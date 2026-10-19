<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Ejecuta el diagnostico desde la terminal.');
}

$options = array_slice($argv, 1);
if (in_array('--help', $options, true)) {
    echo "Diagnostico local de solo lectura: no instala ni modifica datos.\n";
    echo "  php scripts/diagnostico.php                     Comprobacion completa\n";
    echo "  php scripts/diagnostico.php --solo-dependencias  PHP, Composer y GraphQL\n";
    exit(0);
}
if (array_diff($options, ['--solo-dependencias'])) {
    fwrite(STDERR, "Opcion desconocida. Usa php scripts/diagnostico.php --help.\n");
    exit(1);
}

$root = dirname(__DIR__);
// Los errores de config.php o del controlador no deben revelar credenciales.
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

function diagnostic_check(string $label, callable $check, string $help): bool
{
    try {
        $check();
        echo "[OK] {$label}\n";
        return true;
    } catch (Throwable $error) {
        fwrite(STDERR, "[ERROR] {$label}. {$help}\n");
        return false;
    }
}

$runtimeReady = diagnostic_check('PHP 8.2 o superior (serie 8)', static function (): void {
    if (PHP_VERSION_ID < 80200 || PHP_VERSION_ID >= 90000) {
        throw new RuntimeException();
    }
}, 'Usa PHP compatible con composer.json; revisa el ejecutable elegido en PATH.');
foreach (['pdo', 'pdo_mysql', 'mbstring'] as $extension) {
    $available = diagnostic_check('Extension ' . $extension, static function () use ($extension): void {
        if (!extension_loaded($extension)) {
            throw new RuntimeException();
        }
    }, 'Activa esta extension en el php.ini del PHP usado por la terminal (php --ini).');
    $runtimeReady = $available && $runtimeReady;
}
if (!$runtimeReady) {
    exit(1);
}

$dependenciesReady = diagnostic_check('Dependencias Composer instaladas', static function () use ($root): void {
    if (!is_file($root . '/vendor/autoload.php')) {
        throw new RuntimeException();
    }
    require_once $root . '/vendor/autoload.php';
    if (!class_exists(GraphQL\GraphQL::class)) {
        throw new RuntimeException();
    }
}, 'Ejecuta composer install desde la carpeta del proyecto.');
if (!$dependenciesReady) {
    exit(1);
}

$schemaReady = diagnostic_check('Esquema GraphQL y mutaciones', static function () use ($root): void {
    require_once $root . '/src/graphql/server.php';
    $schema = graphql_schema();
    $schema->assertValid();
    if ($schema->getQueryType() === null || $schema->getMutationType() === null) {
        throw new RuntimeException();
    }
}, 'Revisa src/graphql/schema.graphql y src/graphql/mutations.graphql.');
if (!$schemaReady) {
    exit(1);
}
if (in_array('--solo-dependencias', $options, true)) {
    echo "Dependencias listas. No se comprobo la base de datos.\n";
    exit(0);
}

$databaseConfig = [];
$configReady = diagnostic_check('Configuracion local', static function () use (&$databaseConfig): void {
    $config = app_config();
    if (!is_array($config['db'])) {
        throw new RuntimeException();
    }
    $databaseConfig = $config['db'];
    foreach (['host', 'name', 'user', 'password'] as $key) {
        if (!isset($databaseConfig[$key]) || !is_string($databaseConfig[$key])) {
            throw new RuntimeException();
        }
    }
    $port = filter_var($databaseConfig['port'] ?? null, FILTER_VALIDATE_INT);
    if ($databaseConfig['host'] === '' || str_contains($databaseConfig['host'], ';')
        || !preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $databaseConfig['name'])
        || $port === false || $port < 1 || $port > 65535) {
        throw new RuntimeException();
    }
}, 'Revisa config.php y las variables NOMINA_DB_*. Si falta, ejecuta php scripts/install.php (o iniciar.ps1 -InstalarDemo para datos ficticios).');
if (!$configReady) {
    exit(1);
}

$connection = null;
$databaseReady = diagnostic_check('Conexion a MySQL/MariaDB', static function () use ($databaseConfig, &$connection): void {
    $connection = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $databaseConfig['host'], $databaseConfig['port'], $databaseConfig['name']),
        $databaseConfig['user'],
        $databaseConfig['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $connection->query('SELECT 1');
}, 'Inicia MySQL en XAMPP y revisa la conexion de config.php. Si no has instalado la base, ejecuta php scripts/install.php.');
if (!$databaseReady) {
    exit(1);
}

$tablesReady = diagnostic_check('Tablas y migraciones de nomina, usuarios, auditoria y departamentos', static function () use ($connection): void {
    $expectedTables = ['users', 'access_tokens', 'login_attempts', 'employees', 'departments', 'payrolls', 'payroll_items', 'attendance', 'incidences', 'payroll_incidences', 'audit_logs'];
    $tables = $connection->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
    if (array_diff($expectedTables, $tables)) {
        throw new RuntimeException();
    }
    $connection->query('SELECT status FROM users LIMIT 0');
    $connection->query('SELECT id, name, status, created_at FROM departments LIMIT 0');
    $connection->query('SELECT user_id, actor_name, action, entity_type, entity_id, summary, details, created_at FROM audit_logs LIMIT 0');
    $columns = $connection->query("SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payrolls'")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (array_diff(['cancelled_at', 'cancelled_by', 'cancellation_reason'], array_keys($columns))
        || !str_contains($columns['status'] ?? '', "'cancelled'")) {
        throw new RuntimeException();
    }
    $oldIndex = $connection->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll_incidences' AND INDEX_NAME = 'uq_applied_incidence' AND NON_UNIQUE = 0")->fetchColumn();
    if ((int) $oldIndex > 0) {
        throw new RuntimeException();
    }
}, 'Actualiza el esquema con php scripts/install.php --migrate-only; el diagnostico no aplica migraciones.');
if (!$tablesReady) {
    exit(1);
}

echo "Diagnostico completo: listo para iniciar. No se modificaron datos.\n";
exit(0);
