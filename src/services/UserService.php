<?php
declare(strict_types=1);

// Servicio de usuarios: cuentas de acceso, permisos y revocación de sesiones.

require_once __DIR__ . '/AuthenticationService.php';
require_once __DIR__ . '/AuditService.php';

function user_data(array $input): array
{
    $name = required_text($input, 'name', 120, 'nombre');
    $email = strtolower(required_text($input, 'email', 190, 'correo'));
    if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('El correo no es válido.');
    }
    $role = array_key_exists('role', $input) ? $input['role'] : 'viewer';
    if (!is_string($role) || !in_array($role, ['admin', 'viewer'], true)) {
        throw new InvalidArgumentException('El rol debe ser admin o viewer.');
    }
    $status = array_key_exists('status', $input) ? $input['status'] : 'active';
    if (!is_string($status) || !in_array($status, ['active', 'inactive'], true)) {
        throw new InvalidArgumentException('El estado debe ser active o inactive.');
    }
    return ['name' => $name, 'email' => $email, 'role' => $role, 'status' => $status];
}

function list_users(): array
{
    $rows = db()->query('SELECT id, name, email, role, status, created_at FROM users ORDER BY name, id')->fetchAll();
    return ['users' => array_map('public_user', $rows)];
}

function save_user(array $actor, array $input, ?int $id = null): array
{
    require_admin($actor);
    $allowed = ['name', 'email', 'role', 'status', 'password'];
    if (!$input || array_diff(array_keys($input), $allowed)) {
        throw new InvalidArgumentException('Envía al menos un campo de usuario válido, sin campos desconocidos.');
    }
    $changesPassword = array_key_exists('password', $input);
    if (($id === null || $changesPassword) && !valid_password_input($input['password'] ?? null, 12)) {
        throw new InvalidArgumentException('La contraseña debe tener entre 12 y 72 bytes UTF-8, sin caracteres NUL.');
    }
    // El cálculo del hash no necesita mantener bloqueadas las cuentas.
    $passwordHash = $changesPassword ? password_hash($input['password'], PASSWORD_DEFAULT) : null;
    $connection = db();
    $connection->beginTransaction();
    try {
        // Un orden común serializa la administración de cuentas. Evita que dos
        // administradores se desactiven mutuamente y dejen al sistema sin acceso.
        $rows = $connection->query('SELECT id, name, email, role, status, created_at FROM users ORDER BY id FOR UPDATE')->fetchAll();
        $existing = null;
        $currentActor = null;
        $activeAdmins = 0;
        foreach ($rows as $row) {
            if ((int) $row['id'] === (int) $actor['id']) $currentActor = $row;
            if ($id !== null && (int) $row['id'] === $id) $existing = $row;
            if ($row['role'] === 'admin' && $row['status'] === 'active') $activeAdmins++;
        }
        if (!$currentActor || $currentActor['status'] !== 'active') {
            throw new ApiException(401, 'La sesión no es válida o ha vencido.', ['authenticated' => false]);
        }
        require_admin($currentActor);
        lock_current_session($actor);
        if ($id !== null && !$existing) {
            throw new ApiException(404, 'El usuario no existe.');
        }
        $data = user_data(array_merge($existing ?? [], $input));
        if ($id === (int) $actor['id'] && ($data['role'] !== 'admin' || $data['status'] !== 'active')) {
            throw new ApiException(409, 'No puedes desactivar tu propia cuenta ni quitarte el rol de administrador.');
        }
        if ($existing && $existing['role'] === 'admin' && $existing['status'] === 'active'
            && ($data['role'] !== 'admin' || $data['status'] !== 'active') && $activeAdmins <= 1) {
            throw new ApiException(409, 'Debe permanecer al menos un administrador activo.');
        }
        if ($existing) {
            $query = $connection->prepare('UPDATE users SET name = ?, email = ?, role = ?, status = ? WHERE id = ?');
            $query->execute([...array_values($data), $id]);
            if ($changesPassword) {
                $query = $connection->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                $query->execute([$passwordHash, $id]);
            }
            $securityChange = $changesPassword || $data['email'] !== $existing['email']
                || $data['role'] !== $existing['role'] || $data['status'] !== $existing['status'];
            if ($securityChange) {
                $query = $connection->prepare('UPDATE access_tokens SET revoked_at = UTC_TIMESTAMP() WHERE user_id = ? AND revoked_at IS NULL');
                $query->execute([$id]);
            }
            $summary = 'Cuenta de usuario actualizada.' . ($securityChange ? ' Se cerraron sus sesiones por cambios de acceso.' : '');
            audit_log($currentActor, 'user.updated', 'user', $id, $summary);
        } else {
            $query = $connection->prepare('INSERT INTO users (name, email, role, status, password_hash) VALUES (?, ?, ?, ?, ?)');
            $query->execute([...array_values($data), $passwordHash]);
            $id = (int) $connection->lastInsertId();
            audit_log($currentActor, 'user.created', 'user', $id, 'Cuenta de usuario creada.');
        }
        $query = $connection->prepare('SELECT id, name, email, role, status, created_at FROM users WHERE id = ?');
        $query->execute([$id]);
        $saved = $query->fetch();
        $connection->commit();
    } catch (PDOException $error) {
        if ($connection->inTransaction()) $connection->rollBack();
        if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
            throw new ApiException(409, 'Ya existe un usuario con ese correo.');
        }
        throw $error;
    } catch (Throwable $error) {
        if ($connection->inTransaction()) $connection->rollBack();
        throw $error;
    }
    return ['user' => public_user($saved)];
}
