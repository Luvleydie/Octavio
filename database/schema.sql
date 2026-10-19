-- MariaDB / MySQL en XAMPP, puerto 3306.
-- scripts/install.php crea y selecciona nomina_octavio_servicios por defecto.
-- Para importar manualmente, crea y selecciona primero esa base en phpMyAdmin.
-- Los importes guardados son pesos; los cálculos PHP usan centavos enteros.
-- users conserva las cuentas de acceso y sus roles; employees guarda el expediente laboral.
-- La cuenta inicial se crea con scripts/install.php.
CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(32) NOT NULL DEFAULT 'admin',
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Los campos anteriores de auditoría se conservan para instalaciones existentes.
-- details no se expone en la API; los nuevos eventos guardan un resumen sin secretos.
CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    actor_name VARCHAR(120) NOT NULL DEFAULT 'Usuario histórico',
    action VARCHAR(60) NOT NULL,
    entity_type VARCHAR(40) NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    summary VARCHAR(500) NOT NULL DEFAULT 'Registro histórico',
    details LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_created (created_at, id),
    KEY idx_audit_actor (user_id, id),
    KEY idx_audit_entity (entity_type, id),
    KEY idx_audit_action (action, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS access_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_access_token_hash (token_hash),
    KEY idx_tokens_user (user_id),
    KEY idx_tokens_expiration (expires_at),
    CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ip_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_login_email_time (email_hash, attempted_at),
    KEY idx_login_ip_time (ip_hash, attempted_at),
    KEY idx_login_time (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catálogo de áreas: no elimina ni modifica las copias de departamentos en nóminas.
CREATE TABLE IF NOT EXISTS departments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_departments_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employees (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    employee_code VARCHAR(24) NOT NULL,
    full_name VARCHAR(160) NOT NULL,
    email VARCHAR(190) NULL,
    department VARCHAR(100) NOT NULL,
    position VARCHAR(100) NOT NULL,
    monthly_salary DECIMAL(14,2) NOT NULL,
    hire_date DATE NOT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_employee_code (employee_code),
    UNIQUE KEY uq_employee_email (email),
    KEY idx_employee_status (status),
    KEY idx_employee_department (department),
    CONSTRAINT chk_employee_salary CHECK (monthly_salary >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payrolls (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    status ENUM('draft', 'paid', 'cancelled') NOT NULL DEFAULT 'draft',
    employee_count INT UNSIGNED NOT NULL,
    total_gross DECIMAL(16,2) NOT NULL,
    total_deductions DECIMAL(16,2) NOT NULL,
    total_net DECIMAL(16,2) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    paid_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    cancelled_by BIGINT UNSIGNED NULL,
    cancellation_reason VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_payroll_period (period_start, period_end),
    CONSTRAINT fk_payroll_creator FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_payroll_canceller FOREIGN KEY (cancelled_by) REFERENCES users(id),
    CONSTRAINT chk_payroll_period CHECK (period_end >= period_start),
    CONSTRAINT chk_payroll_totals CHECK (total_gross >= 0 AND total_deductions >= 0 AND total_net >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    payroll_id BIGINT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NOT NULL,
    employee_code VARCHAR(24) NOT NULL,
    full_name VARCHAR(160) NOT NULL,
    department VARCHAR(100) NOT NULL,
    position VARCHAR(100) NOT NULL,
    monthly_salary DECIMAL(14,2) NOT NULL,
    days_worked TINYINT UNSIGNED NOT NULL,
    base_pay DECIMAL(14,2) NOT NULL,
    bonus DECIMAL(14,2) NOT NULL DEFAULT 0,
    deduction DECIMAL(14,2) NOT NULL DEFAULT 0,
    gross_pay DECIMAL(14,2) NOT NULL,
    net_pay DECIMAL(14,2) NOT NULL,
    UNIQUE KEY uq_payroll_employee (payroll_id, employee_id),
    KEY idx_payroll_item_employee (employee_id, payroll_id),
    CONSTRAINT fk_payroll_item_run FOREIGN KEY (payroll_id) REFERENCES payrolls(id),
    CONSTRAINT fk_payroll_item_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT chk_payroll_item_days CHECK (days_worked <= 30),
    CONSTRAINT chk_payroll_item_money CHECK (monthly_salary >= 0 AND base_pay >= 0 AND bonus >= 0 AND deduction >= 0 AND gross_pay >= 0 AND net_pay >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Servicio de asistencias: un registro por empleado y fecha.
CREATE TABLE IF NOT EXISTS attendance (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    employee_id BIGINT UNSIGNED NOT NULL,
    work_date DATE NOT NULL,
    status ENUM('present', 'absent', 'paid_leave') NOT NULL,
    notes VARCHAR(255) NOT NULL DEFAULT '',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_attendance_employee_date (employee_id, work_date),
    KEY idx_attendance_date (work_date),
    CONSTRAINT fk_attendance_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT fk_attendance_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Servicio de incidencias: percepciones y deducciones capturadas por concepto.
CREATE TABLE IF NOT EXISTS incidences (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    employee_id BIGINT UNSIGNED NOT NULL,
    occurrence_date DATE NOT NULL,
    type ENUM('bonus', 'deduction') NOT NULL,
    concept VARCHAR(120) NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_incidence_employee_date (employee_id, occurrence_date),
    KEY idx_incidence_date (occurrence_date),
    CONSTRAINT fk_incidence_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT fk_incidence_creator FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT chk_incidence_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Trazabilidad: conserva también las aplicaciones en nóminas canceladas.
-- La exclusividad entre nóminas vigentes se valida bajo bloqueo del empleado.
CREATE TABLE IF NOT EXISTS payroll_incidences (
    payroll_id BIGINT UNSIGNED NOT NULL,
    incidence_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (payroll_id, incidence_id),
    KEY idx_applied_incidence (incidence_id),
    CONSTRAINT fk_applied_payroll FOREIGN KEY (payroll_id) REFERENCES payrolls(id),
    CONSTRAINT fk_applied_incidence FOREIGN KEY (incidence_id) REFERENCES incidences(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

