<?php

declare(strict_types=1);

namespace Kaneas\Core;

use Kaneas\Services\AuthGuard;

final class Router
{
    public const PUBLIC = 'public';
    public const USER = 'user';
    public const ADMIN = 'admin';

    /** @var list<array{method:string, regex:string, handler:array{class-string,string}, access:string}> */
    private array $routes = [];

    /**
     * @param string $pattern e.g. "boards/{id}" — placeholders match positive integers.
     * @param array{class-string,string} $handler
     */
    public function add(string $method, string $pattern, array $handler, string $access = self::USER): void
    {
        $regex = preg_replace('/\{(\w+)\}/', '(?P<$1>\d+)', $pattern);
        $this->routes[] = [
            'method' => $method,
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
            'access' => $access,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $matches)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $request->method) {
                continue;
            }

            if ($route['access'] !== self::PUBLIC) {
                $request->user = AuthGuard::authenticate($request);
                if ($route['access'] === self::ADMIN && $request->user['role'] !== 'admin') {
                    throw HttpException::forbidden();
                }
            }

            $params = array_map('intval', array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY));
            [$class, $method] = $route['handler'];
            $result = (new $class())->$method($request, $params);

            return $result instanceof Response ? $result : new Response($result);
        }

        throw $pathMatched ? new HttpException(405, 'method_not_allowed') : HttpException::notFound();
    }
}
