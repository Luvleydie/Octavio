<?php
declare(strict_types=1);

require_once __DIR__ . '/CalculationService.php';

/** Se llama dentro de la transacción del cambio: ambos se guardan o se revierten.
 * Solo recibe un resumen explícito, nunca contraseñas, tokens ni cuerpos HTTP. */
function audit_log(array $actor, string $action, string $entity, ?int $entityId, string $summary): void
{
    $connection = db();
    if (!$connection->inTransaction()) {
        throw new LogicException('El registro de auditoría requiere una transacción.');
    }
    $query = $connection->prepare('INSERT INTO audit_logs (user_id, actor_name, action, entity_type, entity_id, summary, details) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $query->execute([$actor['id'], $actor['name'], $action, $entity, $entityId, $summary, '{}']);
}

/** Historial de solo lectura con filtros y paginación en el servidor. */
function list_audit_logs(array $filters = []): array
{
    $allowed = ['actor_id', 'action', 'entity', 'date_from', 'date_to', 'page', 'per_page'];
    if (array_diff(array_keys($filters), $allowed)) {
        throw new InvalidArgumentException('El filtro de auditoría contiene campos desconocidos.');
    }
    $conditions = [];
    $values = [];
    if (isset($filters['actor_id']) && $filters['actor_id'] !== '') {
        $conditions[] = 'user_id = ?';
        $values[] = positive_id($filters['actor_id'], 'actor_id');
    }
    foreach (['action' => 'action', 'entity' => 'entity_type'] as $field => $column) {
        if (isset($filters[$field]) && $filters[$field] !== '') {
            $conditions[] = $column . ' = ?';
            $values[] = required_text($filters, $field, $field === 'action' ? 60 : 40);
        }
    }
    $dates = [];
    foreach (['date_from', 'date_to'] as $field) {
        if (isset($filters[$field]) && $filters[$field] !== '') {
            $dates[$field] = valid_date($filters[$field], $field)->format('Y-m-d');
        }
    }
    if (isset($dates['date_from'], $dates['date_to']) && $dates['date_from'] > $dates['date_to']) {
        throw new InvalidArgumentException('La fecha inicial no puede ser posterior a la final.');
    }
    if (isset($dates['date_from'])) {
        $conditions[] = 'created_at >= ?';
        $values[] = $dates['date_from'] . ' 00:00:00';
    }
    if (isset($dates['date_to'])) {
        $conditions[] = 'created_at <= ?';
        $values[] = $dates['date_to'] . ' 23:59:59';
    }
    $page = positive_id($filters['page'] ?? 1, 'page');
    $perPage = positive_id($filters['per_page'] ?? 20, 'per_page');
    if ($perPage > 100 || $page > 1000000) {
        throw new InvalidArgumentException('Usa per_page de 1 a 100 y page de 1 a 1000000.');
    }
    $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
    $connection = db();
    $connection->beginTransaction();
    try {
        $count = $connection->prepare('SELECT COUNT(*) FROM audit_logs' . $where);
        $count->execute($values);
        $total = (int) $count->fetchColumn();
        $offset = ($page - 1) * $perPage;
        // LIMIT/OFFSET son enteros validados; los filtros se enlazan como parámetros.
        $query = $connection->prepare('SELECT id, user_id AS actor_id, actor_name, action, entity_type AS entity, entity_id, summary, created_at FROM audit_logs' . $where . " ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}");
        $query->execute($values);
        $items = $query->fetchAll();
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) $connection->rollBack();
        throw $error;
    }
    foreach ($items as &$item) {
        foreach (['id', 'actor_id', 'entity_id'] as $key) {
            if ($item[$key] !== null) $item[$key] = (int) $item[$key];
        }
    }
    return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
}
