<?php
declare(strict_types=1);

require_once __DIR__ . '/CalculationService.php';
require_once __DIR__ . '/AuditService.php';

/** Un bono suma al pago; una deducción resta. Los importes se validan en centavos. */
function incidence_data(array $input): array
{
    $type = $input['type'] ?? null;
    if (!is_string($type) || !in_array($type, ['bonus', 'deduction'], true)) {
        throw new InvalidArgumentException('El tipo de incidencia debe ser bonus o deduction.');
    }
    $amount = money_to_cents($input['amount'] ?? null, 'importe');
    if ($amount <= 0) {
        throw new InvalidArgumentException('El importe de la incidencia debe ser mayor que cero.');
    }
    return [
        'employee_id' => positive_id($input['employee_id'] ?? null, 'employee_id'),
        'occurrence_date' => valid_date($input['occurrence_date'] ?? null, 'occurrence_date')->format('Y-m-d'),
        'type' => $type,
        'concept' => required_text($input, 'concept', 120, 'concepto'),
        'amount' => cents_to_decimal($amount),
    ];
}

function serialize_incidence(array $row): array
{
    foreach (['id', 'employee_id', 'created_by'] as $field) {
        $row[$field] = (int) $row[$field];
    }
    $row['amount'] = (float) $row['amount'];
    return $row;
}

function list_incidences(array $filters = []): array
{
    $conditions = [];
    $parameters = [];
    if (isset($filters['employee_id'])) {
        $conditions[] = 'i.employee_id = ?';
        $parameters[] = positive_id($filters['employee_id'], 'employee_id');
    }
    $start = isset($filters['period_start']) ? valid_date($filters['period_start'], 'period_start')->format('Y-m-d') : null;
    $end = isset($filters['period_end']) ? valid_date($filters['period_end'], 'period_end')->format('Y-m-d') : null;
    if ($start !== null && $end !== null && $start > $end) {
        throw new InvalidArgumentException('El inicio del periodo no puede ser posterior al final.');
    }
    if ($start !== null) {
        $conditions[] = 'i.occurrence_date >= ?';
        $parameters[] = $start;
    }
    if ($end !== null) {
        $conditions[] = 'i.occurrence_date <= ?';
        $parameters[] = $end;
    }
    $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
    $query = db()->prepare('SELECT i.*, e.employee_code, e.full_name FROM incidences i JOIN employees e ON e.id = i.employee_id' . $where . ' ORDER BY i.occurrence_date DESC, e.full_name, i.id DESC');
    $query->execute($parameters);
    return ['incidences' => array_map('serialize_incidence', $query->fetchAll())];
}

function save_incidence(array $user, array $input, ?int $id = null): array
{
    $allowed = ['employee_id', 'occurrence_date', 'type', 'concept', 'amount'];
    if (!$input || array_diff(array_keys($input), $allowed)) {
        throw new InvalidArgumentException('Envía al menos un campo de incidencia válido, sin campos desconocidos.');
    }
    $isNew = $id === null;
    $connection = db();
    $connection->beginTransaction();
    try {
        $existing = null;
        if (!$isNew) {
            $query = $connection->prepare('SELECT employee_id FROM incidences WHERE id = ?');
            $query->execute([$id]);
            $existing = $query->fetch();
            if (!$existing) {
                throw new ApiException(404, 'La incidencia no existe.');
            }
            $employeeId = (int) $existing['employee_id'];
        } else {
            $employeeId = positive_id($input['employee_id'] ?? null, 'employee_id');
        }
        // Compartimos el bloqueo de empleado con Nómina para impedir cambios simultáneos.
        $query = $connection->prepare('SELECT * FROM employees WHERE id = ? FOR UPDATE');
        $query->execute([$employeeId]);
        $employee = $query->fetch();
        if (!$employee) {
            throw new ApiException(404, 'El empleado no existe.');
        }
        if ($employee['status'] !== 'active') {
            throw new ApiException(422, 'Solo se pueden registrar incidencias de empleados activos.');
        }
        if (!$isNew) {
            $query = $connection->prepare('SELECT * FROM incidences WHERE id = ? FOR UPDATE');
            $query->execute([$id]);
            $existing = $query->fetch();
            if (!$existing) {
                throw new ApiException(404, 'La incidencia no existe.');
            }
            $query = $connection->prepare("SELECT pi.payroll_id FROM payroll_incidences pi JOIN payrolls p ON p.id = pi.payroll_id WHERE pi.incidence_id = ? AND p.status <> 'cancelled' FOR UPDATE");
            $query->execute([$id]);
            if ($query->fetch()) {
                throw new ApiException(409, 'La incidencia ya fue aplicada en una nómina y no se puede modificar.');
            }
        }
        $data = incidence_data(array_merge($existing ?? [], $input));
        if ($data['employee_id'] !== $employeeId) {
            throw new ApiException(409, 'No se puede cambiar el empleado de una incidencia.');
        }
        if ($data['occurrence_date'] < $employee['hire_date']) {
            throw new InvalidArgumentException('La incidencia no puede ser anterior a la fecha de ingreso.');
        }
        $dates = array_unique([$existing['occurrence_date'] ?? $data['occurrence_date'], $data['occurrence_date']]);
        $closed = $connection->prepare("SELECT p.id FROM payroll_items pi JOIN payrolls p ON p.id = pi.payroll_id WHERE pi.employee_id = ? AND p.status <> 'cancelled' AND p.period_start <= ? AND p.period_end >= ? LIMIT 1 FOR UPDATE");
        foreach ($dates as $date) {
            $closed->execute([$employeeId, $date, $date]);
            if ($closed->fetch()) {
                throw new ApiException(409, 'La fecha pertenece a una nómina generada; sus incidencias ya no se pueden modificar.');
            }
        }
        if ($isNew) {
            $query = $connection->prepare('INSERT INTO incidences (employee_id, occurrence_date, type, concept, amount, created_by) VALUES (?, ?, ?, ?, ?, ?)');
            $query->execute([$employeeId, $data['occurrence_date'], $data['type'], $data['concept'], $data['amount'], $user['id']]);
            $id = (int) $connection->lastInsertId();
        } else {
            $query = $connection->prepare('UPDATE incidences SET occurrence_date = ?, type = ?, concept = ?, amount = ? WHERE id = ?');
            $query->execute([$data['occurrence_date'], $data['type'], $data['concept'], $data['amount'], $id]);
        }
        $query = $connection->prepare('SELECT i.*, e.employee_code, e.full_name FROM incidences i JOIN employees e ON e.id = i.employee_id WHERE i.id = ?');
        $query->execute([$id]);
        $incidence = $query->fetch();
        audit_log($user, $isNew ? 'incidence.created' : 'incidence.updated', 'incidence', $id, $isNew ? 'Incidencia registrada.' : 'Incidencia actualizada.');
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
    return ['incidence' => serialize_incidence($incidence)];
}

/** Devuelve centavos enteros e IDs para vincular las incidencias a la nómina generada. */
function incidences_for_payroll(PDO $connection, int $employeeId, string $start, string $end): array
{
    $query = $connection->prepare("SELECT i.id, i.type, i.amount FROM incidences i WHERE i.employee_id = ? AND i.occurrence_date BETWEEN ? AND ? AND NOT EXISTS (SELECT 1 FROM payroll_incidences applied JOIN payrolls p ON p.id = applied.payroll_id WHERE applied.incidence_id = i.id AND p.status <> 'cancelled') ORDER BY i.id");
    $query->execute([$employeeId, $start, $end]);
    $summary = ['bonus_cents' => 0, 'deduction_cents' => 0, 'incidence_ids' => []];
    foreach ($query->fetchAll() as $row) {
        $summary[$row['type'] . '_cents'] += money_to_cents($row['amount'], 'importe de incidencia');
        $summary['incidence_ids'][] = (int) $row['id'];
    }
    return $summary;
}
