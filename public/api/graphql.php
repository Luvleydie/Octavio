<?php
declare(strict_types=1);

ini_set('display_errors', '0');
require_once dirname(__DIR__, 2) . '/src/api.php';

try {
    require_method('POST');
    $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (!is_file($autoload)) throw new ApiException(503, 'Faltan las dependencias GraphQL. Ejecuta composer install.');
    require_once $autoload;
    require_once dirname(__DIR__, 2) . '/src/graphql/server.php';
    respond(graphql_execute(request_json()));
} catch (Throwable $error) {
    [$status, $body] = api_error($error);
    respond(['errors' => [['message' => $body['message'], 'extensions' => ['status' => $status]]]], $status);
}
