<?php
declare(strict_types=1);

require_once __DIR__ . '/CalculationService.php';
require_once __DIR__ . '/AuditService.php';

/** Valida un registro diario: una asistencia, falta o permiso con goce de sueldo. */
function attendance_data(array $input): array
{
    $status = $input['status'] ?? null;
    if (!is_string($status) || !in_array($status, ['present', 'absent', 'paid_leave'], true)) {
        throw new InvalidArgumentException('La asistencia debe ser present, absent o paid_leave.');
    }
    $notes = $input['notes'] ?? '';
    if (!is_string($notes)) {
        throw new InvalidArgumentException('Las notas deben ser texto.');
    }
    if (preg_match('/[\x00-\x1F\x7F]/u', $notes)) {
        throw new InvalidArgumentException('Las notas deben tener hasta 255 caracteres, sin controles.');
    }
    $notes = trim($notes);
    $length = function_exists('mb_strlen') ? mb_strlen($notes, 'UTF-8') : strlen($notes);
    if ($length > 255) {
        throw new InvalidArgumentException('Las notas deben tener hasta 255 caracteres, sin controles.');
    }
    return [
        'employee_id' => positive_id($input['employee_id'] ?? null, 'employee_id'),
        'work_date' => valid_date($input['work_date'] ?? null, 'work_date')->format('Y-m-d'),
        'status' => $status,
        'notes' => $notes,
    ];
}

function serialize_attendance(array $row): array
{
    foreach (['id', 'employee_id', 'created_by'] as $field) {
        $row[$field] = (int) $row[$field];
    }
    return $row;
}

function list_attendance(array $filters = []): array
{
    $conditions = [];
    $parameters = [];
    if (isset($filters['employee_id'])) {
        $conditions[] = 'a.employee_id = ?';
        $parameters[] = positive_id($filters['employee_id'], 'employee_id');
    }
    $start = isset($filters['period_start']) ? valid_date($filters['period_start'], 'period_start')->format('Y-m-d') : null;
    $end = isset($filters['period_end']) ? valid_date($filters['period_end'], 'period_end')->format('Y-m-d') : null;
    if ($start !== null && $end !== null && $start > $end) {
        throw new InvalidArgumentException('El inicio del periodo no puede ser posterior al final.');
    }
    if ($start !== null) {
        $conditions[] = 'a.work_date >= ?';
        $parameters[] = $start;
    }
    if ($end !== null) {
        $conditions[] = 'a.work_date <= ?';
        $parameters[] = $end;
    }
    $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
    $query = db()->prepare('SELECT a.*, e.employee_code, e.full_name FROM attendance a JOIN employees e ON e.id = a.employee_id' . $where . ' ORDER BY a.work_date DESC, e.full_name, a.id DESC');
    $query->execute($parameters);
    return ['attendance' => array_map('serialize_attendance', $query->fetchAll())];
}

function save_attendance(array $user, array $input, ?int $id = null): array
{
    $allowed = ['employee_id', 'work_date', 'status', 'notes'];
    if (!$input || array_diff(array_keys($input), $allowed)) {
        throw new InvalidArgumentException('Envía al menos un campo de asistencia válido, sin campos desconocidos.');
    }
    $isNew = $id === null;
    $connection = db();
    $connection->beginTransaction();
    try {
        $existing = null;
        if (!$isNew) {
            $query = $connection->prepare('SELECT employee_id FROM attendance WHERE id = ?');
            $query->execute([$id]);
            $existing = $query->fetch();
            if (!$existing) {
                throw new ApiException(404, 'El registro de asistencia no existe.');
            }
            $employeeId = (int) $existing['employee_id'];
        } else {
            $employeeId = positive_id($input['employee_id'] ?? null, 'employee_id');
        }
        // Nómina y asistencias toman primero el mismo bloqueo por empleado.
        $query = $connection->prepare('SELECT * FROM employees WHERE id = ? FOR UPDATE');
        $query->execute([$employeeId]);
        $employee = $query->fetch();
        if (!$employee) {
            throw new ApiException(404, 'El empleado no existe.');
        }
        if ($employee['status'] !== 'active') {
            throw new ApiException(422, 'Solo se pueden registrar asistencias de empleados activos.');
        }
        if (!$isNew) {
            $query = $connection->prepare('SELECT * FROM attendance WHERE id = ? FOR UPDATE');
            $query->execute([$id]);
            $existing = $query->fetch();
            if (!$existing) {
                throw new ApiException(404, 'El registro de asistencia no existe.');
            }
        }
        $data = attendance_data(array_merge($existing ?? [], $input));
        if ($data['employee_id'] !== $employeeId) {
            throw new ApiException(409, 'No se puede cambiar el empleado de una asistencia.');
        }
        if ($data['work_date'] < $employee['hire_date']) {
            throw new InvalidArgumentException('La asistencia no puede ser anterior a la fecha de ingreso.');
        }
        $dates = array_unique([$existing['work_date'] ?? $data['work_date'], $data['work_date']]);
        $closed = $connection->prepare("SELECT p.id FROM payroll_items pi JOIN payrolls p ON p.id = pi.payroll_id WHERE pi.employee_id = ? AND p.status <> 'cancelled' AND p.period_start <= ? AND p.period_end >= ? LIMIT 1 FOR UPDATE");
        foreach ($dates as $date) {
            $closed->execute([$employeeId, $date, $date]);
            if ($closed->fetch()) {
                throw new ApiException(409, 'La fecha pertenece a una nómina generada; su asistencia ya no se puede modificar.');
            }
        }
        if ($isNew) {
            $query = $connection->prepare('INSERT INTO attendance (employee_id, work_date, status, notes, created_by) VALUES (?, ?, ?, ?, ?)');
            $query->execute([$employeeId, $data['work_date'], $data['status'], $data['notes'], $user['id']]);
            $id = (int) $connection->lastInsertId();
        } else {
            $query = $connection->prepare('UPDATE attendance SET work_date = ?, status = ?, notes = ? WHERE id = ?');
            $query->execute([$data['work_date'], $data['status'], $data['notes'], $id]);
        }
        $query = $connection->prepare('SELECT a.*, e.employee_code, e.full_name FROM attendance a JOIN employees e ON e.id = a.employee_id WHERE a.id = ?');
        $query->execute([$id]);
        $attendance = $query->fetch();
        audit_log($user, $isNew ? 'attendance.created' : 'attendance.updated', 'attendance', $id, $isNew ? 'Asistencia registrada.' : 'Asistencia actualizada.');
        $connection->commit();
    } catch (PDOException $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
            throw new ApiException(409, 'Ya existe una asistencia para ese empleado y fecha.');
        }
        throw $error;
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
    return ['attendance' => serialize_attendance($attendance)];
}

/** Sin registro no hay día pagable. Nómina llama esta función con el empleado bloqueado. */
function attendance_for_payroll(PDO $connection, int $employeeId, string $start, string $end): array
{
    $query = $connection->prepare('SELECT status FROM attendance WHERE employee_id = ? AND work_date BETWEEN ? AND ? ORDER BY work_date');
    $query->execute([$employeeId, $start, $end]);
    $summary = ['days_worked' => 0, 'attendance_count' => 0, 'present_days' => 0, 'paid_leave_days' => 0, 'absent_days' => 0];
    foreach ($query->fetchAll() as $row) {
        $summary['attendance_count']++;
        $summary[$row['status'] . '_days']++;
    }
    $summary['days_worked'] = min(30, $summary['present_days'] + $summary['paid_leave_days']);
    return $summary;
}
