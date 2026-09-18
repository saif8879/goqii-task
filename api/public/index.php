<?php

declare(strict_types=1);

use App\Auth\Authenticator;
use App\Auth\Jwt;
use App\Auth\TokenService;
use App\Controllers\AuthController;
use App\Controllers\TaskController;
use App\Controllers\UserController;
use App\Exceptions\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Repositories\RefreshTokenRepository;
use App\Repositories\TaskRepository;
use App\Repositories\UserRepository;
use App\Support\Config;
use App\Support\Env;
use App\Validation\AuthValidator;
use App\Validation\TaskValidator;

require __DIR__ . '/../src/autoload.php';

// Errors are reported through the JSON handler below, never printed into the
// body: a stack trace in an API response is an information leak.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Does nothing when the platform already supplies the environment, which is the
// case under Docker. Present so direct and CLI runs are not a special case.
Env::load(dirname(__DIR__, 2) . '/.env');

$request = Request::capture();
$router = new Router();

// Configuration is read inside the try so a missing secret produces a logged
// 500 with a JSON body rather than a blank page from a fatal error.
try {
    // Credentialed CORS forbids a wildcard origin, so the request's origin is
    // matched against an allowlist and echoed only when it is present. Echoing
    // whatever arrived would let any site make credentialed calls.
    $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';

    if ($requestOrigin !== '' && in_array($requestOrigin, Config::corsOrigins(), true)) {
        header('Access-Control-Allow-Origin: ' . $requestOrigin);
        header('Access-Control-Allow-Credentials: true');
    }

    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization');

    if ($request->method() === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    $jwt = new Jwt(Config::jwtSecret());

    $users = new UserRepository();
    $refreshTokens = new RefreshTokenRepository();
    $tokenService = new TokenService($jwt, $refreshTokens, $users);
    $authenticator = new Authenticator($jwt);

    $auth = new AuthController($users, $tokenService, $refreshTokens, new AuthValidator($users));
    $tasks = new TaskController(new TaskRepository(), new TaskValidator($users));
    $userController = new UserController($users);

    $router->add('GET', '/api/health', static function (): void {
        Response::json(['status' => 'ok']);
    });

    // Public: these establish a session, so they cannot require one.
    $router->add('POST', '/api/auth/register', [$auth, 'register']);
    $router->add('POST', '/api/auth/login', [$auth, 'login']);
    $router->add('POST', '/api/auth/refresh', [$auth, 'refresh']);
    $router->add('POST', '/api/auth/logout', [$auth, 'logout']);

    $router->add('GET', '/api/auth/me', [$auth, 'me'], ['auth' => true]);
    $router->add('POST', '/api/auth/logout-all', [$auth, 'logoutEverywhere'], ['auth' => true]);

    $router->add('GET', '/api/users', [$userController, 'index'], ['auth' => true, 'role' => 'admin']);

    $router->add('GET', '/api/tasks', [$tasks, 'index'], ['auth' => true]);
    $router->add('POST', '/api/tasks', [$tasks, 'store'], ['auth' => true]);
    $router->add('GET', '/api/tasks/{id}', [$tasks, 'show'], ['auth' => true]);
    $router->add('PUT', '/api/tasks/{id}', [$tasks, 'update'], ['auth' => true]);
    $router->add('PATCH', '/api/tasks/{id}', [$tasks, 'update'], ['auth' => true]);
    $router->add('DELETE', '/api/tasks/{id}', [$tasks, 'destroy'], ['auth' => true]);

    $router->dispatch($request, static function () use ($authenticator, $request) {
        return $authenticator->requireUser($request);
    });
} catch (HttpException $e) {
    Response::json($e->toArray(), $e->status(), $e->headers());
} catch (Throwable $e) {
    // Log the detail, return something generic so internals are not leaked.
    error_log(sprintf('[%s] %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));

    Response::json(['message' => 'An unexpected server error occurred.'], 500);
}
