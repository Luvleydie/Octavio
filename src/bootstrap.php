<?php
declare(strict_types=1);

require_once __DIR__ . '/services/CalculationService.php';
date_default_timezone_set('UTC');

final class ApiException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message, public readonly array $extra = [])
    {
        parent::__construct($message);
    }
}

function app_config(): array
{
    static $config;
    if ($config === null) {
        $path = dirname(__DIR__) . '/config.php';
        if (!is_file($path)) {
            throw new ApiException(503, 'Falta configurar la aplicación. Ejecuta el instalador indicado en README.md.');
        }
        $config = require $path;
        if (!is_array($config) || !isset($config['db'])) {
            throw new ApiException(503, 'La configuración de la aplicación no es válida.');
        }
    }
    return $config;
}

function db(): PDO
{
    static $connection;
    if ($connection === null) {
        $config = app_config()['db'];
        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (int) ($config['port'] ?? 3306);
        $name = (string) ($config['name'] ?? 'nomina_octavio_servicios');
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $name) || str_contains($host, ';')) {
            throw new ApiException(503, 'La configuración de conexión no es válida.');
        }
        $connection = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", (string) ($config['user'] ?? 'root'), (string) ($config['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $connection->exec("SET time_zone = '+00:00'");
    }
    return $connection;
}

function respond(array $body, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function request_json(): array
{
    $type = strtolower(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]);
    if (trim($type) !== 'application/json') {
        throw new ApiException(415, 'Envía el cuerpo como application/json.');
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 1048576) {
        throw new ApiException(413, 'El cuerpo de la solicitud es demasiado grande.');
    }
    $raw = file_get_contents('php://input', false, null, 0, 1048577);
    if ($raw === false || strlen($raw) > 1048576) {
        throw new ApiException(413, 'El cuerpo de la solicitud es demasiado grande.');
    }
    try {
        $value = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new ApiException(400, 'El cuerpo de la solicitud debe contener JSON válido.');
    }
    if (!$value instanceof stdClass) {
        throw new ApiException(400, 'El cuerpo JSON debe ser un objeto.');
    }
    return json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
}

function require_method(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
        header('Allow: ' . $method);
        throw new ApiException(405, 'Método no permitido.');
    }
}

function positive_id(mixed $value, string $field = 'id'): int
{
    if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string) $value)) {
        throw new InvalidArgumentException("El campo {$field} debe ser un identificador válido.");
    }
    return (int) $value;
}

function required_text(array $data, string $key, int $maximum, ?string $label = null): string
{
    if (!isset($data[$key]) || !is_string($data[$key])) {
        throw new InvalidArgumentException('El campo ' . ($label ?? $key) . ' es obligatorio.');
    }
    // Comprobar el texto original evita que trim oculte controles en los extremos.
    if (preg_match('/[\x00-\x1F\x7F]/u', $data[$key])) {
        throw new InvalidArgumentException('El campo ' . ($label ?? $key) . " debe contener entre 1 y {$maximum} caracteres, sin controles.");
    }
    $value = trim($data[$key]);
    $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    if ($value === '' || $length > $maximum) {
        throw new InvalidArgumentException('El campo ' . ($label ?? $key) . " debe contener entre 1 y {$maximum} caracteres, sin controles.");
    }
    return $value;
}
