<?php
declare(strict_types=1);

// Copia este archivo como config.php. Las variables de entorno tienen prioridad.
// La cuenta root sin contraseña es únicamente la configuración local de XAMPP.
$env = static fn(string $name, string $default): string => getenv($name) !== false ? (string) getenv($name) : $default;

return [
    'db' => [
        'host' => $env('NOMINA_DB_HOST', '127.0.0.1'),
        'port' => (int) $env('NOMINA_DB_PORT', '3306'),
        'name' => $env('NOMINA_DB_NAME', 'nomina_octavio_servicios'),
        'user' => $env('NOMINA_DB_USER', 'root'),
        'password' => $env('NOMINA_DB_PASSWORD', ''),
    ],
    'app' => ['token_hours' => 8, 'debug' => false],
];
