<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

class RefreshTokenRepository
{
    /** @var PDO */
    private $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?: Database::connection();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function store(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO refresh_tokens (user_id, token_hash, family_id, expires_at, user_agent)
             VALUES (:user_id, :token_hash, :family_id, :expires_at, :user_agent)'
        );

        $stmt->execute([
            ':user_id'    => $data['user_id'],
            ':token_hash' => $data['token_hash'],
            ':family_id'  => $data['family_id'],
            ':expires_at' => $data['expires_at'],
            ':user_agent' => $data['user_agent'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Runs $work in a transaction, rolling back if it throws.
     *
     * @return mixed whatever $work returns
     */
    public function transactional(callable $work)
    {
        $this->db->beginTransaction();

        try {
            $result = $work();
            $this->db->commit();

            return $result;
        } catch (\Throwable $error) {
            $this->db->rollBack();

            throw $error;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByHash(string $tokenHash): ?array
    {
        return $this->fetchByHash($tokenHash, false);
    }

    /**
     * Reads a token and holds a write lock on the row until the surrounding
     * transaction ends.
     *
     * This is what serialises concurrent refreshes. A second request for the
     * same token blocks here until the first commits, and a locking read always
     * sees the newest committed row, so it observes a completed rotation rather
     * than a half-finished one. Without the lock the two requests interleave and
     * each has to guess whether the other is a legitimate client or a thief.
     *
     * @return array<string, mixed>|null
     */
    public function findByHashForUpdate(string $tokenHash): ?array
    {
        return $this->fetchByHash($tokenHash, true);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchByHash(string $tokenHash, bool $lock): ?array
    {
        $sql = 'SELECT id, user_id, family_id, expires_at, revoked_at
                  FROM refresh_tokens
                 WHERE token_hash = :token_hash';

        $stmt = $this->db->prepare($lock ? $sql . ' FOR UPDATE' : $sql);
        $stmt->execute([':token_hash' => $tokenHash]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function revoke(int $id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE refresh_tokens SET revoked_at = NOW() WHERE id = :id AND revoked_at IS NULL'
        );

        $stmt->execute([':id' => $id]);
    }

    /**
     * Whether any token in the lineage is still usable.
     *
     * Distinguishes a family that has simply moved on through normal rotation
     * from one that was burned by reuse detection, where every row is revoked.
     * That is what stops a grace period from resurrecting a dead family.
     */
    public function familyHasLiveToken(string $familyId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM refresh_tokens
              WHERE family_id = :family_id AND revoked_at IS NULL AND expires_at > NOW()
              LIMIT 1'
        );
        $stmt->execute([':family_id' => $familyId]);

        return $stmt->fetchColumn() !== false;
    }

    public function recordReplacement(int $id, int $replacedBy): void
    {
        $stmt = $this->db->prepare('UPDATE refresh_tokens SET replaced_by = :replaced_by WHERE id = :id');
        $stmt->execute([':replaced_by' => $replacedBy, ':id' => $id]);
    }

    /**
     * Revokes every token in a rotation lineage. Called when an already-rotated
     * token is replayed, which means the lineage has leaked.
     */
    public function revokeFamily(string $familyId): int
    {
        $stmt = $this->db->prepare(
            'UPDATE refresh_tokens SET revoked_at = NOW() WHERE family_id = :family_id AND revoked_at IS NULL'
        );
        $stmt->execute([':family_id' => $familyId]);

        return $stmt->rowCount();
    }

    public function revokeAllForUser(int $userId): int
    {
        $stmt = $this->db->prepare(
            'UPDATE refresh_tokens SET revoked_at = NOW() WHERE user_id = :user_id AND revoked_at IS NULL'
        );
        $stmt->execute([':user_id' => $userId]);

        return $stmt->rowCount();
    }

    /**
     * Housekeeping: rows that expired long ago carry no value.
     */
    public function pruneExpired(): int
    {
        return (int) $this->db->exec('DELETE FROM refresh_tokens WHERE expires_at < NOW() - INTERVAL 30 DAY');
    }
}
