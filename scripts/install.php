<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Ejecuta el instalador desde la terminal.');
}

$root = dirname(__DIR__);
$options = getopt('', ['demo', 'migrate-only', 'help']);
if (isset($options['help'])) {
    echo "Instalación local, sin borrar registros existentes.\n";
    echo "  php scripts/install.php          Crea tu usuario administrador\n";
    echo "  php scripts/install.php --demo   Agrega usuario y empleados ficticios\n";
    echo "  php scripts/install.php --migrate-only   Actualiza el esquema sin agregar datos\n";
    exit(0);
}

function ask(string $label, string $default = ''): string
{
    echo $label . ($default !== '' ? " [$default]" : '') . ': ';
    $answer = trim((string) fgets(STDIN));
    return $answer !== '' ? $answer : $default;
}

/** CREATE TABLE IF NOT EXISTS conserva tablas antiguas; aplica los cambios
 * necesarios sin eliminar registros, importes ni vínculos históricos. */
function migrate_payroll_cancellation(PDO $pdo): void
{
    $columns = $pdo->query("SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payrolls'")->fetchAll(PDO::FETCH_KEY_PAIR);
    $changes = [];
    if (!str_contains($columns['status'], "'cancelled'")) {
        $changes[] = "MODIFY COLUMN status ENUM('draft', 'paid', 'cancelled') NOT NULL DEFAULT 'draft'";
    }
    foreach ([
        'cancelled_at' => 'DATETIME NULL',
        'cancelled_by' => 'BIGINT UNSIGNED NULL',
        'cancellation_reason' => 'VARCHAR(500) NULL',
    ] as $column => $definition) {
        if (!array_key_exists($column, $columns)) {
            $changes[] = "ADD COLUMN {$column} {$definition}";
        }
    }
    if ($changes) {
        $pdo->exec('ALTER TABLE payrolls ' . implode(', ', $changes));
    }
    $constraints = $pdo->query("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payrolls' AND CONSTRAINT_TYPE = 'FOREIGN KEY'")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('fk_payroll_canceller', $constraints, true)) {
        $pdo->exec('ALTER TABLE payrolls ADD CONSTRAINT fk_payroll_canceller FOREIGN KEY (cancelled_by) REFERENCES users(id)');
    }
    $indexes = $pdo->query("SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll_incidences'")->fetchAll(PDO::FETCH_COLUMN);
    // La FK necesita un índice que empiece por incidence_id; créalo antes de
    // quitar la unicidad antigua para conservar todas las relaciones existentes.
    if (!in_array('idx_applied_incidence', $indexes, true)) {
        $pdo->exec('ALTER TABLE payroll_incidences ADD INDEX idx_applied_incidence (incidence_id)');
    }
    if (in_array('uq_applied_incidence', $indexes, true)) {
        $pdo->exec('ALTER TABLE payroll_incidences DROP INDEX uq_applied_incidence');
    }
}

/** Agrega los servicios nuevos sin borrar cuentas ni auditorías anteriores. */
function migrate_users_and_audit(PDO $pdo): void
{
    $columns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('status', $columns, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN status ENUM('active', 'inactive') NOT NULL DEFAULT 'active' AFTER role");
    }
    $columns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs'")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('actor_name', $columns, true)) {
        $pdo->exec("ALTER TABLE audit_logs ADD COLUMN actor_name VARCHAR(120) NOT NULL DEFAULT 'Usuario histórico'");
    }
    if (!in_array('summary', $columns, true)) {
        $pdo->exec("ALTER TABLE audit_logs ADD COLUMN summary VARCHAR(500) NOT NULL DEFAULT 'Registro histórico'");
    }
    $indexes = $pdo->query("SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs'")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['idx_audit_created' => 'created_at, id', 'idx_audit_actor' => 'user_id, id', 'idx_audit_entity' => 'entity_type, id', 'idx_audit_action' => 'action, id'] as $name => $fields) {
        if (!in_array($name, $indexes, true)) $pdo->exec("ALTER TABLE audit_logs ADD INDEX {$name} ({$fields})");
    }
}

/** Conserva las áreas existentes y el estado de las ya administradas en el catálogo. */
function migrate_departments(PDO $pdo): void
{
    $indexes = $pdo->query("SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees'")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('idx_employee_department', $indexes, true)) {
        $pdo->exec('ALTER TABLE employees ADD INDEX idx_employee_department (department)');
    }
    backfill_departments($pdo);
}

function backfill_departments(PDO $pdo): void
{
    // La actualización vacía evita reactivar áreas y conserva ids/nombres al repetir la migración.
    $pdo->exec("INSERT INTO departments (name)
        SELECT DISTINCT department FROM employees WHERE TRIM(department) <> ''
        ON DUPLICATE KEY UPDATE name = departments.name");
}

try {
    if (!extension_loaded('pdo_mysql')) {
        throw new RuntimeException('Activa pdo_mysql en php.ini. XAMPP ya lo incluye.');
    }
    if (!is_file($root . '/config.php')) {
        if (!copy($root . '/config.example.php', $root . '/config.php')) {
            throw new RuntimeException('No se pudo crear config.php.');
        }
        echo "Configuración local creada en config.php.\n";
    }
    $config = require $root . '/config.php';
    $db = $config['db'];
    if (!preg_match('/\A[a-zA-Z0-9_]{1,64}\z/', $db['name'])) {
        throw new RuntimeException('El nombre de la base solo admite letras, números y guion bajo.');
    }
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], $db['port']),
        $db['user'],
        $db['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $db['name'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `' . $db['name'] . '`');
    $schema = file_get_contents($root . '/database/schema.sql');
    if ($schema === false) {
        throw new RuntimeException('No se encontró database/schema.sql.');
    }
    $pdo->exec($schema);
    migrate_payroll_cancellation($pdo);
    migrate_users_and_audit($pdo);
    migrate_departments($pdo);
    echo "Base {$db['name']} y tablas listas.\n";
    echo "Conexión: {$db['host']}:{$db['port']}\n";

    if (isset($options['migrate-only'])) {
        echo "Esquema actualizado; se conservan los usuarios y registros existentes.\n";
        exit(0);
    }

    $demo = isset($options['demo']);
    if ($demo) {
        $name = 'Administración demo';
        $email = 'admin@nomina.local';
        $password = 'Nomina2026!';
    } else {
        $name = ask('Nombre del administrador');
        $email = strtolower(ask('Correo electrónico'));
        // NOMINA_ADMIN_PASSWORD evita mostrar la contraseña en terminal/historial.
        $password = getenv('NOMINA_ADMIN_PASSWORD');
        if ($password === false || $password === '') {
            echo "La entrada de contraseña será visible en esta terminal local.\n";
            $password = ask('Contraseña (12 a 72 bytes; los acentos pueden ocupar más de uno)');
        }
        if ($name === '' || mb_strlen($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 || strlen($password) < 12 || strlen($password) > 72) {
            throw new RuntimeException('Revisa el nombre, correo y contraseña de 12 a 72 bytes.');
        }
    }

    $pdo->beginTransaction();
    $find = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $find->execute([$email]);
    if ($find->fetchColumn() === false) {
        $insert = $pdo->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)');
        $insert->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), 'admin']);
        echo "Administrador creado: $email\n";
    } else {
        echo "El administrador $email ya existe; se conserva su contraseña.\n";
    }
    if ($demo) {
        $rows = [
            ['NC-001', 'Mariana Torres', 'mariana@example.test', 'Operaciones', 'Coordinadora de operaciones', '24000.00', '2024-02-12'],
            ['NC-002', 'Diego Ramírez', 'diego@example.test', 'Tecnología', 'Desarrollador web', '28000.00', '2024-05-06'],
            ['NC-003', 'Valeria Mendoza', 'valeria@example.test', 'Diseño', 'Diseñadora de producto', '22000.00', '2025-01-20'],
            ['NC-004', 'Andrés Castillo', 'andres@example.test', 'Comercial', 'Ejecutivo de ventas', '18000.00', '2025-03-03'],
            ['NC-005', 'Sofía Herrera', 'sofia@example.test', 'Administración', 'Analista administrativa', '20000.00', '2024-09-16'],
            ['NC-006', 'Luis Navarro', 'luis@example.test', 'Tecnología', 'Soporte técnico', '16000.00', '2025-06-02'],
        ];
        $findEmployee = $pdo->prepare('SELECT id FROM employees WHERE employee_code = ? OR email = ?');
        $employee = $pdo->prepare('INSERT INTO employees (employee_code, full_name, email, department, position, monthly_salary, hire_date, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $added = 0;
        foreach ($rows as $row) {
            $findEmployee->execute([$row[0], $row[2]]);
            if ($findEmployee->fetchColumn() === false) {
                $employee->execute([...$row, 'active']);
                $added++;
            }
        }
        echo "$added empleados ficticios agregados.\n";
    }
    backfill_departments($pdo);
    $pdo->commit();
    echo "\nInstalación terminada. Ejecuta:\n  php -S 127.0.0.1:8088 -t public\n";
    echo "Abre http://127.0.0.1:8088\n";
    if ($demo) {
        echo "Usuario demo: admin@nomina.local\nContraseña demo: Nomina2026!\n";
        echo "Las credenciales demo son públicas; usa únicamente datos ficticios.\n";
    }
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "No se pudo completar la instalación.\n");
    fwrite(STDERR, $error->getMessage() . "\nRevisa MySQL en XAMPP y los datos de config.php.\n");
    exit(1);
}
