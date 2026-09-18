<?php

declare(strict_types=1);

namespace App\Http;

use App\Exceptions\ForbiddenException;
use App\Exceptions\HttpException;
use App\Exceptions\NotFoundException;
use RuntimeException;

class Router
{
    /** @var array<int, array{method: string, regex: string, handler: callable, options: array<string, mixed>}> */
    private $routes = [];

    /**
     * Patterns use {name} placeholders, e.g. /api/tasks/{id}.
     *
     * Options:
     *   auth => true      the caller must present a valid access token
     *   role => 'admin'   the caller must additionally hold that role
     *
     * @param array<string, mixed> $options
     */
    public function add(string $method, string $pattern, callable $handler, array $options = []): void
    {
        $this->routes[] = [
            'method'  => strtoupper($method),
            'regex'   => $this->compile($pattern),
            'handler' => $handler,
            'options' => $options,
        ];
    }

    /**
     * $authResolver is invoked lazily and only for protected routes, so an
     * expired token cannot break a public endpoint such as login or refresh.
     */
    public function dispatch(Request $request, ?callable $authResolver = null): void
    {
        $allowed = [];

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $request->path(), $matches) !== 1) {
                continue;
            }

            if ($route['method'] !== $request->method()) {
                $allowed[] = $route['method'];
                continue;
            }

            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

            $route['handler']($request, $params, $this->authorize($route['options'], $authResolver));

            return;
        }

        if ($allowed !== []) {
            throw new HttpException(
                405,
                sprintf('%s is not allowed on this endpoint.', $request->method()),
                ['Allow' => implode(', ', array_unique($allowed))]
            );
        }

        throw new NotFoundException(sprintf('No route matches %s %s.', $request->method(), $request->path()));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function authorize(array $options, ?callable $authResolver)
    {
        if (empty($options['auth'])) {
            return null;
        }

        if ($authResolver === null) {
            throw new RuntimeException('A protected route was registered without an auth resolver.');
        }

        $user = $authResolver();

        $role = $options['role'] ?? null;

        if ($role !== null && $user->role() !== $role) {
            throw new ForbiddenException(sprintf('This action requires the %s role.', $role));
        }

        return $user;
    }

    private function compile(string $pattern): string
    {
        $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $pattern);

        return '#^' . $regex . '$#';
    }
}
