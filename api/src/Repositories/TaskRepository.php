<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

class TaskRepository
{
    /**
     * Whitelist of sortable fields. Because ORDER BY cannot be parameterised,
     * only the column names in this map ever reach the SQL string.
     */
    public const SORTABLE = [
        'created_at' => 't.created_at',
        'updated_at' => 't.updated_at',
        'due_date'   => 't.due_date',
        'title'      => 't.title',
        'priority'   => 't.priority',
        'status'     => 't.status',
    ];

    /**
     * Sortable columns that allow NULL. MySQL orders NULLs first when sorting
     * ascending, which would float undated tasks above genuinely urgent ones,
     * so these get an explicit "nulls last" clause in both directions.
     *
     * @var array<int, string>
     */
    private const NULLABLE_SORTS = ['due_date'];

    private const COLUMNS = 't.id, t.title, t.description, t.status, t.priority, t.due_date, t.user_id,
                             t.created_at, t.updated_at, u.name AS owner_name, u.email AS owner_email';

    /** @var array<int, string> */
    private const WRITABLE = ['title', 'description', 'status', 'priority', 'due_date', 'user_id'];

    /** @var PDO */
    private $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?: Database::connection();
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<int, array<string, mixed>>
     */
    public function paginate(array $filters, int $limit, int $offset, string $sort, string $order): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $column = self::SORTABLE[$sort] ?? self::SORTABLE['created_at'];
        $direction = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

        // Safe to interpolate: $column came out of the whitelist above.
        $nullsLast = in_array($sort, self::NULLABLE_SORTS, true) ? $column . ' IS NULL, ' : '';

        $sql = 'SELECT ' . self::COLUMNS . '
                  FROM tasks t
                  JOIN users u ON u.id = t.user_id'
            . $where
            // id is the tie-breaker so paging stays stable when sort values repeat.
            . ' ORDER BY ' . $nullsLast . $column . ' ' . $direction . ', t.id DESC'
            . ' LIMIT :limit OFFSET :offset';

        $stmt = $this->db->prepare($sql);

        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value);
        }

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function countMatching(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM tasks t' . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . ' FROM tasks t JOIN users u ON u.id = t.user_id WHERE t.id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $fields = array_values(array_intersect(self::WRITABLE, array_keys($data)));

        $sql = sprintf(
            'INSERT INTO tasks (%s) VALUES (%s)',
            implode(', ', $fields),
            implode(', ', array_map(static function (string $field): string {
                return ':' . $field;
            }, $fields))
        );

        $stmt = $this->db->prepare($sql);
        $stmt->execute($this->bindings($data, $fields));

        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $fields = array_values(array_intersect(self::WRITABLE, array_keys($data)));

        if ($fields === []) {
            return;
        }

        $assignments = array_map(static function (string $field): string {
            return $field . ' = :' . $field;
        }, $fields);

        $sql = 'UPDATE tasks SET ' . implode(', ', $assignments) . ' WHERE id = :id';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($this->bindings($data, $fields) + [':id' => $id]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM tasks WHERE id = :id');
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $conditions = [];
        $params = [];

        foreach (['status', 'priority', 'user_id'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $conditions[] = 't.' . $field . ' = :' . $field;
                $params[':' . $field] = $filters[$field];
            }
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
            // Native prepared statements bind one value per marker, so a named
            // placeholder cannot be reused across both LIKE clauses.
            $conditions[] = '(t.title LIKE :search_title OR t.description LIKE :search_body)';

            // Escape LIKE wildcards so a literal % or _ in the term is not treated as a pattern.
            $term = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], (string) $filters['search']) . '%';

            $params[':search_title'] = $term;
            $params[':search_body'] = $term;
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $params];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string>   $fields
     *
     * @return array<string, mixed>
     */
    private function bindings(array $data, array $fields): array
    {
        $bindings = [];

        foreach ($fields as $field) {
            $bindings[':' . $field] = $data[$field];
        }

        return $bindings;
    }
}
