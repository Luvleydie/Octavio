<?php
declare(strict_types=1);

// Servicio de empleados: expediente, sueldo y estado de cada persona.

require_once __DIR__ . '/CalculationService.php';
require_once __DIR__ . '/AuditService.php';
require_once __DIR__ . '/DepartmentService.php';

function serialize_employee(array $row): array
{
    $row['id'] = (int) $row['id'];
    $row['monthly_salary'] = (float) $row['monthly_salary'];
    return $row;
}

function employee_data(array $data): array
{
    $code = required_text($data, 'employee_code', 24, 'clave');
    if (!preg_match('/^[A-Za-z0-9_-]+$/D', $code)) {
        throw new InvalidArgumentException('La clave solo puede contener letras, números, guiones y guion bajo.');
    }
    $email = $data['email'] ?? null;
    if ($email !== null) {
        if (!is_string($email)) {
            throw new InvalidArgumentException('El correo debe ser texto.');
        }
        $email = strtolower(trim($email));
        if ($email === '') {
            $email = null;
        } elseif (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('El correo no es válido.');
        }
    }
    $salary = money_to_cents($data['monthly_salary'] ?? null, 'salario mensual');
    if ($salary <= 0) {
        throw new InvalidArgumentException('El salario mensual debe ser mayor que cero.');
    }
    $hireDate = valid_date($data['hire_date'] ?? null, 'fecha de ingreso');
    $status = array_key_exists('status', $data) ? $data['status'] : 'active';
    if (!is_string($status) || !in_array($status, ['active', 'inactive'], true)) {
        throw new InvalidArgumentException('El estado debe ser active o inactive.');
    }
    return [
        'employee_code' => strtoupper($code),
        'full_name' => required_text($data, 'full_name', 160, 'nombre completo'),
        'email' => $email,
        'department' => required_text($data, 'department', 100, 'departamento'),
        'position' => required_text($data, 'position', 100, 'puesto'),
        'monthly_salary' => cents_to_decimal($salary),
        'hire_date' => $hireDate->format('Y-m-d'),
        'status' => $status,
    ];
}

function list_employees(): array
{
    $rows = db()->query('SELECT * FROM employees ORDER BY full_name, id')->fetchAll();
    return ['employees' => array_map('serialize_employee', $rows)];
}

function save_employee(array $input, ?int $id = null, ?array $actor = null): array
{
    $allowed = ['employee_code', 'full_name', 'email', 'department', 'position', 'monthly_salary', 'hire_date', 'status'];
    if (!$input || array_diff(array_keys($input), $allowed)) {
        throw new InvalidArgumentException('Envía al menos un campo de empleado válido, sin campos desconocidos.');
    }
    $isNew = $id === null;
    $actor ??= authenticated_user();
    $connection = db();
    $connection->beginTransaction();
    try {
        // El mismo orden que al renombrar un departamento: catálogo antes de empleado.
        lock_departments();
        if ($id !== null) {
            $query = $connection->prepare('SELECT * FROM employees WHERE id = ? FOR UPDATE');
            $query->execute([$id]);
            $existing = $query->fetch();
            if (!$existing) {
                throw new ApiException(404, 'El empleado no existe.');
            }
            $data = employee_data(array_merge($existing, $input));
            $data['department'] = employee_department($data['department'], $existing['department'], $actor);
            if ($data['hire_date'] !== $existing['hire_date']) {
                // El bloqueo del empleado también protege sus asistencias, incidencias y nóminas.
                // No permitimos que una fecha nueva deje registros anteriores fuera del cálculo.
                $history = $connection->prepare('SELECT MIN(record_date) FROM (
                    SELECT work_date AS record_date FROM attendance WHERE employee_id = ?
                    UNION ALL
                    SELECT occurrence_date AS record_date FROM incidences WHERE employee_id = ?
                    UNION ALL
                    SELECT p.period_end AS record_date FROM payroll_items pi JOIN payrolls p ON p.id = pi.payroll_id WHERE pi.employee_id = ?
                ) AS employee_history');
                $history->execute([$id, $id, $id]);
                $firstRecordDate = $history->fetchColumn();
                if ($firstRecordDate !== null && $data['hire_date'] > $firstRecordDate) {
                    throw new ApiException(409, 'La fecha de ingreso no puede ser posterior a las asistencias, incidencias o periodos de nómina registrados para el empleado.');
                }
            }
            $query = $connection->prepare('UPDATE employees SET employee_code = ?, full_name = ?, email = ?, department = ?, position = ?, monthly_salary = ?, hire_date = ?, status = ? WHERE id = ?');
            $query->execute([...array_values($data), $id]);
        } else {
            $data = employee_data($input);
            $data['department'] = employee_department($data['department'], null, $actor);
            $query = $connection->prepare('INSERT INTO employees (employee_code, full_name, email, department, position, monthly_salary, hire_date, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $query->execute(array_values($data));
            $id = (int) $connection->lastInsertId();
        }
        $query = $connection->prepare('SELECT * FROM employees WHERE id = ?');
        $query->execute([$id]);
        $employee = $query->fetch();
        audit_log($actor, $isNew ? 'employee.created' : 'employee.updated', 'employee', $id, $isNew ? 'Empleado registrado.' : 'Datos del empleado actualizados.');
        $connection->commit();
    } catch (PDOException $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
            throw new ApiException(409, 'Ya existe un empleado con esa clave o correo.');
        }
        throw $error;
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
    return ['employee' => serialize_employee($employee)];
}
