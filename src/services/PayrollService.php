<?php
declare(strict_types=1);

// Servicio de nóminas: periodos, validaciones y detalles históricos.

require_once __DIR__ . '/CalculationService.php';
require_once __DIR__ . '/AttendanceService.php';
require_once __DIR__ . '/IncidenceService.php';

function serialize_payroll(array $row): array
{
    foreach (['id', 'employee_count', 'created_by', 'cancelled_by'] as $field) {
        if (isset($row[$field])) {
            $row[$field] = (int) $row[$field];
        }
    }
    foreach (['total_gross', 'total_deductions', 'total_net'] as $field) {
        $row[$field] = (float) $row[$field];
    }
    return $row;
}

function payroll_details(int $id): array
{
    $query = db()->prepare('SELECT * FROM payrolls WHERE id = ?');
    $query->execute([$id]);
    $payroll = $query->fetch();
    if (!$payroll) {
        throw new ApiException(404, 'La nómina no existe.');
    }
    $query = db()->prepare('SELECT * FROM payroll_items WHERE payroll_id = ? ORDER BY full_name, id');
    $query->execute([$id]);
    $items = $query->fetchAll();
    foreach ($items as &$item) {
        foreach (['id', 'payroll_id', 'employee_id', 'days_worked'] as $key) {
            $item[$key] = (int) $item[$key];
        }
        foreach (['monthly_salary', 'base_pay', 'bonus', 'deduction', 'gross_pay', 'net_pay'] as $key) {
            $item[$key] = (float) $item[$key];
        }
    }
    unset($item);
    $query = db()->prepare('SELECT incidence_id FROM payroll_incidences WHERE payroll_id = ? ORDER BY incidence_id');
    $query->execute([$id]);
    return [
        'payroll' => serialize_payroll($payroll),
        'items' => $items,
        'incidence_ids' => array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN)),
    ];
}

function list_payrolls(): array
{
    $rows = db()->query('SELECT * FROM payrolls ORDER BY id DESC')->fetchAll();
    return ['payrolls' => array_map('serialize_payroll', $rows)];
}

/** Vista previa informativa: al guardar se consultan y validan otra vez los registros. */
function payroll_preview(array $filters): array
{
    $start = $filters['period_start'] ?? null;
    $end = $filters['period_end'] ?? null;
    $periodDays = payroll_period_days($start, $end);
    $connection = db();
    $parameters = [$end];
    $filter = '';
    if (isset($filters['employee_id'])) {
        $filter = ' AND id = ?';
        $parameters[] = positive_id($filters['employee_id'], 'employee_id');
    }
    $query = $connection->prepare("SELECT * FROM employees WHERE status = 'active' AND hire_date <= ?{$filter} ORDER BY full_name, id");
    $query->execute($parameters);
    $employees = $query->fetchAll();
    $overlap = $connection->prepare("SELECT p.id FROM payroll_items pi JOIN payrolls p ON p.id = pi.payroll_id WHERE pi.employee_id = ? AND p.status <> 'cancelled' AND p.period_start <= ? AND p.period_end >= ? LIMIT 1");
    $items = [];
    $totals = ['gross' => 0, 'deductions' => 0, 'net' => 0];
    $canCreate = count($employees) > 0;
    foreach ($employees as $employee) {
        $employeeId = (int) $employee['id'];
        $eligibleStart = max($start, $employee['hire_date']);
        $attendance = attendance_for_payroll($connection, $employeeId, $eligibleStart, $end);
        $incidences = incidences_for_payroll($connection, $employeeId, $eligibleStart, $end);
        $row = [
            'employee_id' => $employeeId,
            'employee_code' => $employee['employee_code'],
            'full_name' => $employee['full_name'],
            'monthly_salary' => (float) $employee['monthly_salary'],
            ...$attendance,
            'bonus' => (float) cents_to_decimal($incidences['bonus_cents']),
            'deduction' => (float) cents_to_decimal($incidences['deduction_cents']),
            'incidence_ids' => $incidences['incidence_ids'],
            'base_pay' => null,
            'gross_pay' => null,
            'net_pay' => null,
            'can_create' => true,
            'errors' => [],
        ];
        $overlap->execute([$employeeId, $end, $start]);
        if ($overlap->fetchColumn()) {
            $row['errors'][] = 'El empleado ya tiene una nómina que se solapa con este periodo.';
        }
        try {
            $amounts = calculate_payroll_item(
                money_to_cents($employee['monthly_salary']),
                $attendance['days_worked'],
                $incidences['bonus_cents'],
                $incidences['deduction_cents'],
                min($periodDays, payroll_period_days($eligibleStart, $end))
            );
            foreach (['base_pay', 'gross_pay', 'net_pay'] as $field) {
                $row[$field] = (float) cents_to_decimal($amounts[$field]);
            }
            if (!$row['errors']) {
                $totals['gross'] += $amounts['gross_pay'];
                $totals['deductions'] += $amounts['deduction'];
                $totals['net'] += $amounts['net_pay'];
            }
        } catch (InvalidArgumentException $error) {
            $row['errors'][] = $error->getMessage();
        }
        $row['can_create'] = !$row['errors'];
        $canCreate = $canCreate && $row['can_create'];
        $items[] = $row;
    }
    return [
        'period_start' => $start,
        'period_end' => $end,
        'items' => $items,
        'totals' => array_map(static fn(int $value): float => (float) cents_to_decimal($value), $totals),
        'can_create' => $canCreate,
    ];
}

function create_payroll(array $user, array $data): array
{
    $periodDays = payroll_period_days($data['period_start'] ?? null, $data['period_end'] ?? null);
    $useRecords = array_key_exists('use_records', $data) ? $data['use_records'] : false;
    if (!is_bool($useRecords)) {
        throw new InvalidArgumentException('El campo use_records debe ser verdadero o falso.');
    }
    if (!isset($data['items']) || !is_array($data['items']) || !array_is_list($data['items']) || count($data['items']) < 1 || count($data['items']) > 1000) {
        throw new InvalidArgumentException('Selecciona entre 1 y 1000 empleados.');
    }
    $requested = [];
    foreach ($data['items'] as $item) {
        if (!is_array($item)) {
            throw new InvalidArgumentException('Cada detalle de nómina debe ser un objeto.');
        }
        $employeeId = positive_id($item['employee_id'] ?? null, 'employee_id');
        if (isset($requested[$employeeId])) {
            throw new InvalidArgumentException('No se puede incluir al mismo empleado dos veces.');
        }
        // Con registros, el cliente solo selecciona personas y fechas.
        $days = $useRecords ? 0 : ($item['days_worked'] ?? null);
        if (!is_int($days)) {
            throw new InvalidArgumentException('Los días trabajados deben ser un número entero.');
        }
        $requested[$employeeId] = [
            'days_worked' => $days,
            'bonus' => $useRecords ? 0 : money_to_cents($item['bonus'] ?? 0, 'bono'),
            'deduction' => $useRecords ? 0 : money_to_cents($item['deduction'] ?? 0, 'deducción'),
            'incidence_ids' => [],
        ];
    }
    // Todos los generadores toman los bloqueos en el mismo orden.
    ksort($requested, SORT_NUMERIC);
    $ids = array_keys($requested);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $connection = db();
    $connection->beginTransaction();
    try {
        // El bloqueo por empleado serializa la comprobación y la inserción de periodos.
        $query = $connection->prepare("SELECT * FROM employees WHERE id IN ({$placeholders}) ORDER BY id FOR UPDATE");
        $query->execute($ids);
        $employees = $query->fetchAll();
        if (count($employees) !== count($ids)) {
            throw new ApiException(422, 'Uno o más empleados no existen.');
        }
        $overlap = $connection->prepare("SELECT pi.employee_code FROM payroll_items pi JOIN payrolls p ON p.id = pi.payroll_id WHERE pi.employee_id IN ({$placeholders}) AND p.status <> 'cancelled' AND p.period_start <= ? AND p.period_end >= ? LIMIT 1 FOR UPDATE");
        $overlap->execute([...$ids, $data['period_end'], $data['period_start']]);
        if ($overlap->fetch()) {
            throw new ApiException(409, 'Ya hay una nómina que se solapa con este periodo para uno de los empleados seleccionados.');
        }
        $preparedItems = [];
        $totals = ['gross' => 0, 'deductions' => 0, 'net' => 0];
        foreach ($employees as $employee) {
            if ($employee['status'] !== 'active') {
                throw new ApiException(422, 'Solo se pueden incluir empleados activos.');
            }
            if ($employee['hire_date'] > $data['period_end']) {
                throw new ApiException(422, 'La fecha de ingreso de un empleado es posterior al periodo.');
            }
            $item = $requested[(int) $employee['id']];
            $eligibleStart = max($data['period_start'], $employee['hire_date']);
            $eligibleDays = payroll_period_days($eligibleStart, $data['period_end']);
            if ($useRecords) {
                // Asistencias e incidencias comparten el bloqueo de empleado con sus altas y cambios.
                $attendance = attendance_for_payroll($connection, (int) $employee['id'], $eligibleStart, $data['period_end']);
                $incidences = incidences_for_payroll($connection, (int) $employee['id'], $eligibleStart, $data['period_end']);
                $item = [
                    'days_worked' => $attendance['days_worked'],
                    'bonus' => $incidences['bonus_cents'],
                    'deduction' => $incidences['deduction_cents'],
                    'incidence_ids' => $incidences['incidence_ids'],
                ];
            }
            $amounts = calculate_payroll_item(money_to_cents($employee['monthly_salary']), $item['days_worked'], $item['bonus'], $item['deduction'], min($periodDays, $eligibleDays));
            $totals['gross'] += $amounts['gross_pay'];
            $totals['deductions'] += $amounts['deduction'];
            $totals['net'] += $amounts['net_pay'];
            $preparedItems[] = [$employee, $item['days_worked'], $amounts, $item['incidence_ids']];
        }
        $insert = $connection->prepare('INSERT INTO payrolls (period_start, period_end, employee_count, total_gross, total_deductions, total_net, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([$data['period_start'], $data['period_end'], count($preparedItems), cents_to_decimal($totals['gross']), cents_to_decimal($totals['deductions']), cents_to_decimal($totals['net']), $user['id']]);
        $payrollId = (int) $connection->lastInsertId();
        $insertItem = $connection->prepare('INSERT INTO payroll_items (payroll_id, employee_id, employee_code, full_name, department, position, monthly_salary, days_worked, base_pay, bonus, deduction, gross_pay, net_pay) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $linkIncidence = $connection->prepare('INSERT INTO payroll_incidences (payroll_id, incidence_id) VALUES (?, ?)');
        foreach ($preparedItems as [$employee, $days, $amounts, $incidenceIds]) {
            $insertItem->execute([
                $payrollId, $employee['id'], $employee['employee_code'], $employee['full_name'], $employee['department'], $employee['position'], $employee['monthly_salary'], $days,
                cents_to_decimal($amounts['base_pay']), cents_to_decimal($amounts['bonus']), cents_to_decimal($amounts['deduction']), cents_to_decimal($amounts['gross_pay']), cents_to_decimal($amounts['net_pay']),
            ]);
            foreach ($incidenceIds as $incidenceId) {
                $linkIncidence->execute([$payrollId, $incidenceId]);
            }
        }
        audit_log($user, 'payroll.created', 'payroll', $payrollId, 'Nómina generada.');
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
    return payroll_details($payrollId);
}

/** Los detalles son inmutables: bloquear sus empleados antes que la cabecera
 * mantiene el mismo orden que generación, asistencias e incidencias. */
function lock_payroll_for_update(PDO $connection, int $id): array
{
    $query = $connection->prepare('SELECT employee_id FROM payroll_items WHERE payroll_id = ? ORDER BY employee_id');
    $query->execute([$id]);
    $employeeIds = $query->fetchAll(PDO::FETCH_COLUMN);
    $lockEmployee = $connection->prepare('SELECT id FROM employees WHERE id = ? FOR UPDATE');
    foreach ($employeeIds as $employeeId) {
        $lockEmployee->execute([$employeeId]);
        $lockEmployee->fetchColumn();
    }
    $query = $connection->prepare('SELECT * FROM payrolls WHERE id = ? FOR UPDATE');
    $query->execute([$id]);
    $payroll = $query->fetch();
    if (!$payroll) {
        throw new ApiException(404, 'La nómina no existe.');
    }
    return $payroll;
}

/** Cancela un borrador sin borrar sus importes ni las incidencias históricas. */
function cancel_payroll(int $id, array $user, array $input): array
{
    if (array_diff(array_keys($input), ['reason'])) {
        throw new InvalidArgumentException('Envía únicamente el motivo de cancelación en reason.');
    }
    $reason = required_text($input, 'reason', 500, 'motivo de cancelación');
    $length = function_exists('mb_strlen') ? mb_strlen($reason, 'UTF-8') : strlen($reason);
    if ($length < 3 || preg_match('/\p{Cc}/u', $input['reason'])) {
        throw new InvalidArgumentException('El motivo de cancelación debe contener entre 3 y 500 caracteres, sin controles.');
    }
    $connection = db();
    $connection->beginTransaction();
    try {
        $payroll = lock_payroll_for_update($connection, $id);
        if ($payroll['status'] === 'paid') {
            throw new ApiException(409, 'Una nómina pagada no se puede cancelar.');
        }
        if ($payroll['status'] === 'draft') {
            $query = $connection->prepare("UPDATE payrolls SET status = 'cancelled', cancelled_at = UTC_TIMESTAMP(), cancelled_by = ?, cancellation_reason = ? WHERE id = ?");
            $query->execute([$user['id'], $reason, $id]);
            audit_log($user, 'payroll.cancelled', 'payroll', $id, 'Nómina en borrador cancelada.');
        }
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
    // Un reintento mantiene la fecha, el autor y el motivo originales.
    return payroll_details($id);
}

