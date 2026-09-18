<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthenticatedUser;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\TaskRepository;
use App\Validation\TaskValidator;

class TaskController
{
    /** @var TaskRepository */
    private $tasks;

    /** @var TaskValidator */
    private $validator;

    public function __construct(TaskRepository $tasks, TaskValidator $validator)
    {
        $this->tasks = $tasks;
        $this->validator = $validator;
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params, AuthenticatedUser $actor): void
    {
        $options = $this->validator->validateListQuery($request->query());
        $filters = $options['filters'];

        // Scoping happens in the WHERE clause rather than by discarding rows
        // after the fact, so another user's tasks are unreachable by any path.
        if (!$actor->isAdmin()) {
            $filters['user_id'] = $actor->id();
        }

        $total = $this->tasks->countMatching($filters);
        $perPage = $options['per_page'];
        $page = $options['page'];

        $rows = $this->tasks->paginate(
            $filters,
            $perPage,
            ($page - 1) * $perPage,
            $options['sort'],
            $options['order']
        );

        Response::json([
            'data' => array_map([$this, 'present'], $rows),
            'meta' => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params, AuthenticatedUser $actor): void
    {
        $task = $this->findOrFail($params['id']);
        $this->assertCanManage($task, $actor);

        Response::json(['data' => $this->present($task)]);
    }

    /**
     * @param array<string, string> $params
     */
    public function store(Request $request, array $params, AuthenticatedUser $actor): void
    {
        $id = $this->tasks->create($this->validator->validatePayload($request->body(), $actor));

        Response::json(
            ['data' => $this->present($this->findOrFail($id))],
            201,
            ['Location' => '/api/tasks/' . $id]
        );
    }

    /**
     * PUT replaces the whole task, PATCH applies only the fields provided.
     *
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params, AuthenticatedUser $actor): void
    {
        $task = $this->findOrFail($params['id']);
        $this->assertCanManage($task, $actor);

        $body = $request->body();

        if (!$actor->isAdmin() && array_key_exists('user_id', $body)) {
            throw new ForbiddenException('Only an admin can reassign a task to another owner.');
        }

        $id = (int) $task['id'];

        $this->tasks->update($id, $this->validator->validatePayload($body, $actor, $request->method() === 'PATCH'));

        Response::json(['data' => $this->present($this->findOrFail($id))]);
    }

    /**
     * @param array<string, string> $params
     */
    public function destroy(Request $request, array $params, AuthenticatedUser $actor): void
    {
        $task = $this->findOrFail($params['id']);
        $this->assertCanManage($task, $actor);

        $this->tasks->delete((int) $task['id']);

        Response::noContent();
    }

    /**
     * A regular user may only touch their own rows. Answering 403 rather than
     * 404 does confirm the task exists, which is the trade-off the brief asks
     * for; 404 would hide its existence entirely.
     *
     * @param array<string, mixed> $task
     */
    private function assertCanManage(array $task, AuthenticatedUser $actor): void
    {
        if (!$actor->isAdmin() && (int) $task['user_id'] !== $actor->id()) {
            throw new ForbiddenException('You can only manage your own tasks.');
        }
    }

    /**
     * @param int|string $id
     *
     * @return array<string, mixed>
     */
    private function findOrFail($id): array
    {
        $task = ctype_digit((string) $id) ? $this->tasks->find((int) $id) : null;

        if ($task === null) {
            throw new NotFoundException(sprintf('Task %s was not found.', $id));
        }

        return $task;
    }

    /**
     * Shapes a joined database row into the JSON the client expects.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        return [
            'id'          => (int) $row['id'],
            'title'       => $row['title'],
            'description' => $row['description'],
            'status'      => $row['status'],
            'priority'    => $row['priority'],
            'due_date'    => $row['due_date'],
            'user_id'     => (int) $row['user_id'],
            'owner'       => [
                'id'    => (int) $row['user_id'],
                'name'  => $row['owner_name'],
                'email' => $row['owner_email'],
            ],
            'created_at'  => $row['created_at'],
            'updated_at'  => $row['updated_at'],
        ];
    }
}
