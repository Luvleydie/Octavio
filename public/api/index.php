<?php
declare(strict_types=1);

ini_set('display_errors', '0');
require_once dirname(__DIR__, 2) . '/src/api.php';

// Entrada REST para clientes y archivos CSV. La interfaz web usa GraphQL.
try {
    $rawRoute = $_GET['route'] ?? '';
    if (!is_string($rawRoute)) throw new ApiException(400, 'La ruta no es válida.');
    $route = trim($rawRoute, '/');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $needsBody = $method === 'PATCH' || ($method === 'POST' && (
        in_array($route, ['auth/login', 'auth/password', 'auth/change-password', 'employees', 'departments', 'attendance', 'incidences', 'payrolls', 'users'], true)
        || str_ends_with($route, '/cancel')
    ));
    if (!in_array($route, ['health', 'auth/login'], true)) {
        $user = authenticated_user();
        if ($needsBody && !str_starts_with($route, 'auth/')) require_admin($user);
    }
    $filters = $_GET;
    unset($filters['route']);
    $body = api_data($route, $method, $needsBody ? request_json() : [], $filters);
    $created = $method === 'POST' && in_array($route, ['employees', 'departments', 'attendance', 'incidences', 'payrolls', 'users'], true);
    respond($body, $created ? 201 : 200);
} catch (Throwable $error) {
    [$status, $body] = api_error($error);
    respond($body, $status);
}
