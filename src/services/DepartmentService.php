<?php
declare(strict_types=1);

// Catálogo compartido por los empleados; las nóminas conservan su copia histórica.
require_once __DIR__ . '/AuthenticationService.php';
require_once __DIR__ . '/AuditService.php';

function department_data(array $input): array
{
    $name = required_text($input, 'name', 100, 'nombre del departamento');
    $status = array_key_exists('status', $input) ? $input['status'] : 'active';
    if (!is_string($status) || !in_array($status, ['active', 'inactive'], true)) {
        throw new InvalidArgumentException('El estado debe ser active o inactive.');
    }
    return ['name' => $name, 'status' => $status];
}

function serialize_department(array $row): array
{
    foreach (['id', 'employee_count', 'active_employee_count'] as $field) {
        $row[$field] = (int) $row[$field];
    }
    return $row;
}

/** Una sola consulta obtiene también los recuentos con el mismo criterio SQL de nombres. */
function department_rows(?int $id = null): array
{
    $sql = "SELECT d.id, d.name, d.status, d.created_at,
        COUNT(e.id) AS employee_count,
        COALESCE(SUM(e.status = 'active'), 0) AS active_employee_count
        FROM departments d LEFT JOIN employees e ON e.department = d.name";
    if ($id !== null) $sql .= ' WHERE d.id = ?';
    $sql .= ' GROUP BY d.id, d.name, d.status, d.created_at ORDER BY d.name, d.id';
    $query = db()->prepare($sql);
    $query->execute($id === null ? [] : [$id]);
    return array_map('serialize_department', $query->fetchAll());
}

function list_departments(): array
{
    return ['departments' => department_rows()];
}

/** Siempre se toma antes de bloquear empleados, tanto al asignar como al renombrar. */
function lock_departments(): void
{
    if (!db()->inTransaction()) {
        throw new LogicException('El catálogo de departamentos requiere una transacción.');
    }
    // El catálogo es pequeño. Un orden común evita carreras de alta, renombre y desactivación.
    db()->query('SELECT id FROM departments ORDER BY id FOR UPDATE')->fetchAll();
}

/** Compatibilidad con clientes que todavía mandan un nombre libre de departamento. */
function employee_department(string $name, ?string $previousName, array $actor): string
{
    $connection = db();
    if (!$connection->inTransaction()) {
        throw new LogicException('La asignación de departamento requiere una transacción.');
    }
    $query = $connection->prepare('SELECT id, name, status, name = ? AS same_department FROM departments WHERE name = ? FOR UPDATE');
    $query->execute([$previousName, $name]);
    $department = $query->fetch();
    if ($department) {
        // Un departamento inactivo conserva los expedientes existentes y permite editarlos.
        if ($department['status'] !== 'active' && !(bool) $department['same_department']) {
            throw new ApiException(409, 'El departamento está inactivo. Elige uno activo para asignar al empleado.');
        }
        return $department['name'];
    }
    $query = $connection->prepare("INSERT INTO departments (name, status) VALUES (?, 'active')");
    $query->execute([$name]);
    audit_log($actor, 'department.created', 'department', (int) $connection->lastInsertId(), 'Departamento creado al registrar un empleado.');
    return $name;
}

function save_department(array $actor, array $input, ?int $id = null): array
{
    require_admin($actor);
    if (!$input || array_diff(array_keys($input), ['name', 'status'])) {
        throw new InvalidArgumentException('Envía al menos un campo de departamento válido, sin campos desconocidos.');
    }
    $connection = db();
    $connection->beginTransaction();
    try {
        lock_departments();
        $existing = null;
        if ($id !== null) {
            $query = $connection->prepare('SELECT id, name, status FROM departments WHERE id = ? FOR UPDATE');
            $query->execute([$id]);
            $existing = $query->fetch();
            if (!$existing) throw new ApiException(404, 'El departamento no existe.');
        }
        $data = department_data(array_merge($existing ?: [], $input));
        if ($existing) {
            $query = $connection->prepare('UPDATE departments SET name = ?, status = ? WHERE id = ?');
            $query->execute([$data['name'], $data['status'], $id]);
            if ($data['name'] !== $existing['name']) {
                $query = $connection->prepare('UPDATE employees SET department = ? WHERE department = ?');
                $query->execute([$data['name'], $existing['name']]);
            }
            audit_log($actor, 'department.updated', 'department', $id, 'Departamento actualizado. Las nóminas históricas conservan sus datos originales.');
        } else {
            $query = $connection->prepare('INSERT INTO departments (name, status) VALUES (?, ?)');
            $query->execute([$data['name'], $data['status']]);
            $id = (int) $connection->lastInsertId();
            audit_log($actor, 'department.created', 'department', $id, 'Departamento creado.');
        }
        $saved = department_rows($id)[0];
        $connection->commit();
    } catch (PDOException $error) {
        if ($connection->inTransaction()) $connection->rollBack();
        if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
            throw new ApiException(409, 'Ya existe un departamento con ese nombre.');
        }
        throw $error;
    } catch (Throwable $error) {
        if ($connection->inTransaction()) $connection->rollBack();
        throw $error;
    }
    return ['department' => $saved];
}
