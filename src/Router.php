<?php

declare(strict_types=1);

namespace App;

class Router
{
    private array $routes = [];
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * @param bool $auth     require a signed-in user
     * @param bool $verified require a signed-in user with a verified email (sensitive marketplace actions)
     */
    public function get(string $path, string $handler, bool $auth = false, bool $verified = false): self
    {
        $this->routes[] = ['GET', $path, $handler, $auth || $verified, $verified];
        return $this;
    }

    public function post(string $path, string $handler, bool $auth = false, bool $verified = false): self
    {
        $this->routes[] = ['POST', $path, $handler, $auth || $verified, $verified];
        return $this;
    }

    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $uri = rtrim($uri, '/') ?: '/';

        foreach ($this->routes as $route) {
            [$routeMethod, $pattern, $handler, $auth, $verified] = $route;
            if ($routeMethod !== $method) {
                continue;
            }
            $params = $this->match($pattern, $uri);
            if ($params !== null) {
                if ($method === 'POST' && !Security::validateCsrf()) {
                    http_response_code(419);
                    echo 'Your session security token expired or was invalid. Please go back, refresh the page and try again.';
                    return;
                }
                $isApi = str_starts_with($uri, '/api/');
                if ($auth && !Auth::check($this->config)) {
                    if ($isApi) {
                        $this->jsonError(401, 'Please log in.');
                        return;
                    }
                    header('Location: /login');
                    exit;
                }
                if ($verified && !Auth::isVerified(Auth::user($this->config))) {
                    if ($isApi) {
                        $this->jsonError(403, 'Please verify your email address first.');
                        return;
                    }
                    $_SESSION['verify_notice'] = 'Please verify your email address before listing or renting items.';
                    header('Location: /verify-email');
                    exit;
                }
                [$class, $action] = explode('::', $handler);
                $controller = new $class($this->config);
                $controller->$action($params);
                return;
            }
        }
        http_response_code(404);
        $config = $this->config;
        $currentUser = \App\Auth::user($config);
        echo $this->render('404', ['currentUser' => $currentUser]);
    }

    private function jsonError(int $code, string $message): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $message]);
    }

    private function match(string $pattern, string $uri): ?array
    {
        $names = [];
        $pattern = preg_replace_callback('#\{(\w+)\}#', static function ($m) use (&$names) {
            $names[] = $m[1];
            return '([^/]+)';
        }, $pattern);
        if (preg_match('#^' . $pattern . '$#', $uri, $m)) {
            array_shift($m);
            return $names ? array_combine($names, $m) : [];
        }
        return null;
    }

    public function render(string $view, array $data = []): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        $path = $this->config['base_path'] . '/templates/' . $view . '.php';
        if (file_exists($path)) {
            include $path;
        }
        return ob_get_clean();
    }
}
