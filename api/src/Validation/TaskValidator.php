<?php

declare(strict_types=1);

namespace App\Validation;

use App\Auth\AuthenticatedUser;
use App\Exceptions\ValidationException;
use App\Repositories\TaskRepository;
use App\Repositories\UserRepository;
use DateTimeImmutable;

class TaskValidator
{
    public const STATUSES = ['todo', 'in-progress', 'done'];
    public const PRIORITIES = ['low', 'medium', 'high'];

    private const TITLE_MIN = 3;
    private const TITLE_MAX = 160;
    private const DESCRIPTION_MAX = 2000;
    private const PER_PAGE_DEFAULT = 10;
    private const PER_PAGE_MAX = 50;

    /** @var UserRepository */
    private $users;

    public function __construct(UserRepository $users)
    {
        $this->users = $users;
    }

    /**
     * Validates a create (POST/PUT) or partial update (PATCH) payload and
     * returns only the columns that are safe to persist.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function validatePayload(array $input, AuthenticatedUser $actor, bool $partial = false): array
    {
        $errors = [];
        $clean = [];

        $this->checkTitle($input, $partial, $clean, $errors);
        $this->checkDescription($input, $partial, $clean, $errors);
        $this->checkEnum($input, $partial, 'status', self::STATUSES, 'todo', $clean, $errors);
        $this->checkEnum($input, $partial, 'priority', self::PRIORITIES, 'medium', $clean, $errors);
        $this->checkDueDate($input, $partial, $clean, $errors);
        $this->checkOwner($input, $partial, $actor, $clean, $errors);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        if ($partial && $clean === []) {
            throw new ValidationException([], 'Provide at least one field to update.');
        }

        return $clean;
    }

    /**
     * Validates the list endpoint's query string.
     *
     * @param array<string, mixed> $query
     *
     * @return array{filters: array<string, mixed>, page: int, per_page: int, sort: string, order: string}
     */
    public function validateListQuery(array $query): array
    {
        $errors = [];
        $filters = [];

        $status = $this->stringOrNull($query, 'status');
        if ($status !== null) {
            if (!in_array($status, self::STATUSES, true)) {
                $errors['status'] = $this->oneOfMessage('Status', self::STATUSES);
            } else {
                $filters['status'] = $status;
            }
        }

        $priority = $this->stringOrNull($query, 'priority');
        if ($priority !== null) {
            if (!in_array($priority, self::PRIORITIES, true)) {
                $errors['priority'] = $this->oneOfMessage('Priority', self::PRIORITIES);
            } else {
                $filters['priority'] = $priority;
            }
        }

        $userId = $this->stringOrNull($query, 'user_id');
        if ($userId !== null) {
            if (!$this->isPositiveInt($userId)) {
                $errors['user_id'] = 'user_id must be a positive integer.';
            } else {
                $filters['user_id'] = (int) $userId;
            }
        }

        $search = $this->stringOrNull($query, 'search');
        if ($search !== null) {
            $filters['search'] = $search;
        }

        $page = 1;
        $rawPage = $this->stringOrNull($query, 'page');
        if ($rawPage !== null) {
            if (!$this->isPositiveInt($rawPage)) {
                $errors['page'] = 'page must be a positive integer.';
            } else {
                $page = (int) $rawPage;
            }
        }

        $perPage = self::PER_PAGE_DEFAULT;
        $rawPerPage = $this->stringOrNull($query, 'per_page');
        if ($rawPerPage !== null) {
            if (!$this->isPositiveInt($rawPerPage)) {
                $errors['per_page'] = 'per_page must be a positive integer.';
            } else {
                $perPage = min((int) $rawPerPage, self::PER_PAGE_MAX);
            }
        }

        $sort = $this->stringOrNull($query, 'sort') ?? 'created_at';
        if (!array_key_exists($sort, TaskRepository::SORTABLE)) {
            $errors['sort'] = $this->oneOfMessage('sort', array_keys(TaskRepository::SORTABLE));
        }

        $order = strtolower($this->stringOrNull($query, 'order') ?? 'desc');
        if (!in_array($order, ['asc', 'desc'], true)) {
            $errors['order'] = $this->oneOfMessage('order', ['asc', 'desc']);
        }

        if ($errors !== []) {
            throw new ValidationException($errors, 'The query parameters are invalid.');
        }

        return [
            'filters'  => $filters,
            'page'     => $page,
            'per_page' => $perPage,
            'sort'     => $sort,
            'order'    => $order,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $clean
     * @param array<string, string> $errors
     */
    private function checkTitle(array $input, bool $partial, array &$clean, array &$errors): void
    {
        if ($partial && !array_key_exists('title', $input)) {
            return;
        }

        $title = is_string($input['title'] ?? null) ? trim($input['title']) : '';

        if ($title === '') {
            $errors['title'] = 'Title is required.';
        } elseif (mb_strlen($title) < self::TITLE_MIN) {
            $errors['title'] = sprintf('Title must be at least %d characters.', self::TITLE_MIN);
        } elseif (mb_strlen($title) > self::TITLE_MAX) {
            $errors['title'] = sprintf('Title cannot be longer than %d characters.', self::TITLE_MAX);
        } else {
            $clean['title'] = $title;
        }
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $clean
     * @param array<string, string> $errors
     */
    private function checkDescription(array $input, bool $partial, array &$clean, array &$errors): void
    {
        if (!array_key_exists('description', $input)) {
            if (!$partial) {
                $clean['description'] = null;
            }

            return;
        }

        $description = $input['description'];

        if ($description === null) {
            $clean['description'] = null;

            return;
        }

        if (!is_string($description)) {
            $errors['description'] = 'Description must be text.';

            return;
        }

        $description = trim($description);

        if ($description === '') {
            $clean['description'] = null;
        } elseif (mb_strlen($description) > self::DESCRIPTION_MAX) {
            $errors['description'] = sprintf('Description cannot be longer than %d characters.', self::DESCRIPTION_MAX);
        } else {
            $clean['description'] = $description;
        }
    }

    /**
     * @param array<string, mixed>  $input
     * @param array<int, string>    $allowed
     * @param array<string, mixed>  $clean
     * @param array<string, string> $errors
     */
    private function checkEnum(
        array $input,
        bool $partial,
        string $field,
        array $allowed,
        string $default,
        array &$clean,
        array &$errors
    ): void {
        if (!array_key_exists($field, $input) || $input[$field] === null || $input[$field] === '') {
            if (!$partial) {
                $clean[$field] = $default;
            }

            return;
        }

        if (!in_array($input[$field], $allowed, true)) {
            $errors[$field] = $this->oneOfMessage(ucfirst(str_replace('_', ' ', $field)), $allowed);

            return;
        }

        $clean[$field] = $input[$field];
    }

    /**
     * @param array<string, mixed>  $input
     * @param array<string, mixed>  $clean
     * @param array<string, string> $errors
     */
    private function checkDueDate(array $input, bool $partial, array &$clean, array &$errors): void
    {
        if (!array_key_exists('due_date', $input)) {
            if (!$partial) {
                $clean['due_date'] = null;
            }

            return;
        }

        $dueDate = $input['due_date'];

        if ($dueDate === null || $dueDate === '') {
            $clean['due_date'] = null;

            return;
        }

        if (!is_string($dueDate) || !$this->isCalendarDate($dueDate)) {
            $errors['due_date'] = 'Due date must be a real date in YYYY-MM-DD format.';

            return;
        }

        $clean['due_date'] = $dueDate;
    }

    /**
     * Only an admin may nominate an owner. For everyone else the owner is the
     * caller, and any user_id in the payload is discarded rather than trusted.
     *
     * @param array<string, mixed>  $input
     * @param array<string, mixed>  $clean
     * @param array<string, string> $errors
     */
    private function checkOwner(
        array $input,
        bool $partial,
        AuthenticatedUser $actor,
        array &$clean,
        array &$errors
    ): void {
        if (!$actor->isAdmin()) {
            if (!$partial) {
                $clean['user_id'] = $actor->id();
            }

            return;
        }

        if ($partial && !array_key_exists('user_id', $input)) {
            return;
        }

        // An admin who omits the owner is creating the task for themselves.
        $userId = $input['user_id'] ?? $actor->id();

        if ($userId === null || $userId === '') {
            $errors['user_id'] = 'An owner is required.';
        } elseif (!$this->isPositiveInt($userId)) {
            $errors['user_id'] = 'Owner must be a positive integer id.';
        } elseif (!$this->users->exists((int) $userId)) {
            $errors['user_id'] = 'The selected owner does not exist.';
        } else {
            $clean['user_id'] = (int) $userId;
        }
    }

    /**
     * @param array<string, mixed> $source
     */
    private function stringOrNull(array $source, string $key): ?string
    {
        if (!isset($source[$key]) || !is_scalar($source[$key])) {
            return null;
        }

        $value = trim((string) $source[$key]);

        return $value === '' ? null : $value;
    }

    /**
     * @param mixed $value
     */
    private function isPositiveInt($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
    }

    private function isCalendarDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);

        // Guards against inputs like 2026-02-31, which PHP happily rolls over.
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /**
     * @param array<int, string> $allowed
     */
    private function oneOfMessage(string $label, array $allowed): string
    {
        return sprintf('%s must be one of: %s.', $label, implode(', ', $allowed));
    }
}
