<?php
declare(strict_types=1);
// Solo opera sobre la base efímera creada por test_api.py.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$config = require dirname(__DIR__) . '/config.php';
$db = $config['db'];
if (!preg_match('/\Anomina_test_[a-f0-9]{12}\z/', (string) $db['name'])) {
    fwrite(STDERR, "La fixture solo permite bases de pruebas con identificador aleatorio.\n");
    exit(1);
}
$pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']), $db['user'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$action = $argv[1] ?? '';
if ($action === 'cleanup') {
    $pdo->exec('DROP DATABASE `' . $db['name'] . '`');
} elseif ($action === 'expire') {
    $tokenHash = trim((string) fgets(STDIN));
    $stmt = $pdo->prepare('UPDATE access_tokens SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR) WHERE token_hash = ?');
    $stmt->execute([$tokenHash]);
} elseif ($action === 'stored-credentials') {
    $user = $pdo->query("SELECT password_hash FROM users WHERE email = 'admin@nomina.local'")->fetchColumn();
    $hashes = $pdo->query('SELECT token_hash FROM access_tokens')->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode(['password_hashed' => $user !== 'Nomina2026!' && password_verify('Nomina2026!', $user), 'token_hashes' => $hashes]);
} elseif ($action === 'viewer') {
    $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)');
    $stmt->execute(['Lector de pruebas', 'viewer@example.test', password_hash('Viewer2026!', PASSWORD_DEFAULT), 'viewer']);
} elseif ($action === 'account') {
    $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)');
    $stmt->execute(['Cuenta de pruebas', 'account@example.test', password_hash('Account2026!Original', PASSWORD_DEFAULT), 'viewer']);
} elseif ($action === 'fail-audit-writes') {
    // Fallo controlado para verificar la atomicidad; nunca se crea fuera de nomina_test_*.
    $pdo->exec("CREATE TRIGGER audit_test_failure BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fallo de auditoria simulado'");
} elseif ($action === 'restore-audit-writes') {
    $pdo->exec('DROP TRIGGER IF EXISTS audit_test_failure');
} elseif ($action === 'legacy-cancellation-schema') {
    // Solo una base recién instalada, además del filtro nomina_test_* inicial.
    if ((int) $pdo->query('SELECT COUNT(*) FROM payrolls')->fetchColumn() !== 0
        || (int) $pdo->query('SELECT COUNT(*) FROM incidences')->fetchColumn() !== 0) {
        throw new RuntimeException('La fixture legacy requiere una base temporal sin nóminas ni incidencias.');
    }
    $pdo->exec("ALTER TABLE payrolls DROP FOREIGN KEY fk_payroll_canceller,
        DROP COLUMN cancelled_at, DROP COLUMN cancelled_by, DROP COLUMN cancellation_reason,
        MODIFY COLUMN status ENUM('draft', 'paid') NOT NULL DEFAULT 'draft'");
    $pdo->exec('ALTER TABLE payroll_incidences ADD UNIQUE KEY uq_applied_incidence (incidence_id), DROP INDEX idx_applied_incidence');
    $userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
    $employeeId = (int) $pdo->query('SELECT id FROM employees ORDER BY id LIMIT 1')->fetchColumn();
    // Simula una instalación anterior dentro de esta base recién creada y efímera.
    // La migración debe añadir estado y auditoría sin cambiar valores históricos.
    $pdo->exec('ALTER TABLE users DROP COLUMN status');
    $pdo->exec('DROP TABLE audit_logs');
    $pdo->exec("CREATE TABLE audit_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NULL,
        action VARCHAR(60) NOT NULL,
        entity_type VARCHAR(40) NOT NULL,
        entity_id BIGINT UNSIGNED NULL,
        details LONGTEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("INSERT INTO incidences (employee_id, occurrence_date, type, concept, amount, created_by) VALUES (?, '2026-07-01', 'bonus', 'Bono histórico', '500.00', ?)");
    $stmt->execute([$employeeId, $userId]);
    $incidenceId = (int) $pdo->lastInsertId();
    $stmt = $pdo->prepare("INSERT INTO payrolls (period_start, period_end, employee_count, total_gross, total_deductions, total_net, created_by) VALUES ('2026-07-01', '2026-07-15', 1, '1100.00', '0.00', '1100.00', ?)");
    $stmt->execute([$userId]);
    $payrollId = (int) $pdo->lastInsertId();
    $stmt = $pdo->prepare("INSERT INTO payroll_items (payroll_id, employee_id, employee_code, full_name, department, position, monthly_salary, days_worked, base_pay, bonus, deduction, gross_pay, net_pay) VALUES (?, ?, 'LEGACY-001', 'Nombre histórico', 'Departamento histórico', 'Puesto histórico', '18000.00', 1, '600.00', '500.00', '0.00', '1100.00', '1100.00')");
    $stmt->execute([$payrollId, $employeeId]);
    $stmt = $pdo->prepare('INSERT INTO payroll_incidences (payroll_id, incidence_id) VALUES (?, ?)');
    $stmt->execute([$payrollId, $incidenceId]);
    $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details) VALUES (?, 'payroll.created', 'payroll', ?, ?)");
    $stmt->execute([$userId, $payrollId, '{"use_records":true}']);
    $pdo->commit();
} elseif ($action === 'migration-snapshot') {
    $snapshot = [];
    foreach (['users', 'employees', 'incidences', 'payroll_items', 'audit_logs'] as $table) {
        $snapshot[$table] = $pdo->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }
    $snapshot['payrolls'] = $pdo->query('SELECT id, period_start, period_end, employee_count, total_gross, total_deductions, total_net, status, created_by, created_at, paid_at FROM payrolls ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $snapshot['payroll_incidences'] = $pdo->query('SELECT * FROM payroll_incidences ORDER BY payroll_id, incidence_id')->fetchAll(PDO::FETCH_ASSOC);
    $snapshot['schema'] = [
        'columns' => $pdo->query("SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payrolls' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_KEY_PAIR),
        'indexes' => $pdo->query("SELECT DISTINCT INDEX_NAME, NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll_incidences' ORDER BY INDEX_NAME")->fetchAll(PDO::FETCH_KEY_PAIR),
        'foreign_keys' => $pdo->query("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payrolls' AND CONSTRAINT_TYPE = 'FOREIGN KEY' ORDER BY CONSTRAINT_NAME")->fetchAll(PDO::FETCH_COLUMN),
        'user_columns' => $pdo->query("SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_KEY_PAIR),
        'audit_columns' => $pdo->query("SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_KEY_PAIR),
    ];
    echo json_encode($snapshot, JSON_THROW_ON_ERROR);
} else {
    fwrite(STDERR, "Acción desconocida.\n");
    exit(1);
}
