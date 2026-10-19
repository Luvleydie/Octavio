<?php
declare(strict_types=1);

// Servicio de autenticación: acceso, sesiones, roles y contraseñas.

require_once __DIR__ . '/AuditService.php';

function public_user(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'name' => $row['name'],
        'email' => $row['email'],
        'role' => $row['role'],
        'status' => $row['status'],
        'created_at' => $row['created_at'] ?? null,
    ];
}

function bearer_token(): string
{
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer ([a-f0-9]{64})$/iD', $authorization, $matches)) {
        throw new ApiException(401, 'Inicia sesión para continuar.', ['authenticated' => false]);
    }
    return $matches[1];
}

function authenticated_user(): array
{
    $statement = db()->prepare("SELECT u.id, u.name, u.email, u.role, u.status, u.created_at, t.id AS token_id FROM access_tokens t JOIN users u ON u.id = t.user_id WHERE t.token_hash = ? AND u.status = 'active' AND t.revoked_at IS NULL AND t.expires_at > UTC_TIMESTAMP() LIMIT 1");
    $statement->execute([hash('sha256', bearer_token())]);
    $user = $statement->fetch();
    if (!$user) {
        throw new ApiException(401, 'La sesión no es válida o ha vencido.', ['authenticated' => false]);
    }
    return $user;
}

/** Reserva un intento antes de verificar la contraseña; los aciertos se liberan. */
function begin_auth_attempt(string $email): int
{
    $emailHash = hash('sha256', strtolower(trim($email)));
    // REMOTE_ADDR evita confiar en un X-Forwarded-For enviado por el cliente.
    $ipHash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'local');
    $connection = db();
    $connection->exec('DELETE FROM login_attempts WHERE attempted_at < UTC_TIMESTAMP() - INTERVAL 1 DAY');
    // Se registra antes de contar para que solicitudes concurrentes también consuman cupo.
    $insert = $connection->prepare('INSERT INTO login_attempts (email_hash, ip_hash, attempted_at) VALUES (?, ?, UTC_TIMESTAMP())');
    $insert->execute([$emailHash, $ipHash]);
    $attemptId = (int) $connection->lastInsertId();
    $count = $connection->prepare('SELECT COUNT(*) FROM login_attempts WHERE email_hash = ? AND attempted_at >= UTC_TIMESTAMP() - INTERVAL 15 MINUTE');
    $count->execute([$emailHash]);
    $emailAttempts = (int) $count->fetchColumn();
    $count = $connection->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip_hash = ? AND attempted_at >= UTC_TIMESTAMP() - INTERVAL 15 MINUTE');
    $count->execute([$ipHash]);
    $ipAttempts = (int) $count->fetchColumn();
    if ($emailAttempts > 5 || $ipAttempts > 20) {
        header('Retry-After: 900');
        throw new ApiException(429, 'Demasiados intentos. Espera 15 minutos antes de volver a intentarlo.', ['authenticated' => false]);
    }
    return $attemptId;
}

function finish_auth_attempt(int $id): void
{
    $delete = db()->prepare('DELETE FROM login_attempts WHERE id = ?');
    $delete->execute([$id]);
}

function valid_password_input(mixed $value, int $minimum = 1): bool
{
    return is_string($value) && strlen($value) >= $minimum && strlen($value) <= 72 && !str_contains($value, "\0");
}

function login(array $data): array
{
    // bcrypt solo considera 72 bytes; rechazar exceso evita aceptar sufijos distintos.
    if (!isset($data['email'], $data['password']) || !is_string($data['email']) || strlen($data['email']) > 190 || !valid_password_input($data['password'])) {
        throw new ApiException(422, 'Proporciona correo y contraseña válidos.', ['authenticated' => false]);
    }
    $email = strtolower(trim($data['email']));
    // Las cuentas se crean con correos ASCII válidos. La colación SQL ignora
    // acentos; admitir variantes Unicode permitiría consultar la misma cuenta
    // con hashes de correo distintos y eludir su límite de intentos.
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new ApiException(422, 'Proporciona correo y contraseña válidos.', ['authenticated' => false]);
    }
    $attemptId = begin_auth_attempt($email);
    $connection = db();
    $token = bin2hex(random_bytes(32));
    $hours = max(1, min(168, (int) (app_config()['app']['token_hours'] ?? 8)));
    $expiresAt = (new DateTimeImmutable())->modify("+{$hours} hours");
    $connection->beginTransaction();
    try {
        // El mismo bloqueo se usa al cambiar contraseña: no se emiten sesiones
        // con una contraseña antigua mientras otra solicitud la está cambiando.
        $query = $connection->prepare('SELECT * FROM users WHERE email = ? LIMIT 1 FOR UPDATE');
        $query->execute([$email]);
        $user = $query->fetch();
        $dummyHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $valid = password_verify($data['password'], $user ? $user['password_hash'] : $dummyHash);
        if (!$user || !$valid || $user['status'] !== 'active') {
            throw new ApiException(401, 'Correo o contraseña incorrectos.', ['authenticated' => false]);
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $update = $connection->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $update->execute([password_hash($data['password'], PASSWORD_DEFAULT), $user['id']]);
        }
        $insert = $connection->prepare('INSERT INTO access_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)');
        $insert->execute([$user['id'], hash('sha256', $token), $expiresAt->format('Y-m-d H:i:s')]);
        finish_auth_attempt($attemptId);
        audit_log($user, 'auth.login', 'user', (int) $user['id'], 'Inicio de sesión.');
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
    return ['authenticated' => true, 'token' => $token, 'expires_at' => $expiresAt->format('Y-m-d\TH:i:s\Z'), 'user' => public_user($user)];
}

function logout(array $user): array
{
    $connection = db();
    $connection->beginTransaction();
    try {
        $query = $connection->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $query->execute([$user['id']]);
        $query->fetchColumn();
        lock_current_session($user);
        $query = $connection->prepare('UPDATE access_tokens SET revoked_at = UTC_TIMESTAMP() WHERE id = ?');
        $query->execute([$user['token_id']]);
        audit_log($user, 'auth.logout', 'user', (int) $user['id'], 'Cierre de sesión.');
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
    return ['authenticated' => false, 'message' => 'Sesión cerrada.'];
}

function require_admin(array $user): void
{
    if ($user['role'] !== 'admin') {
        throw new ApiException(403, 'Esta acción requiere permisos de administrador.');
    }
}

/** Revalidación dentro de la transacción, después de bloquear la fila del usuario. */
function lock_current_session(array $user): void
{
    $query = db()->prepare("SELECT t.id FROM access_tokens t JOIN users u ON u.id = t.user_id WHERE t.id = ? AND t.user_id = ? AND u.status = 'active' AND t.revoked_at IS NULL AND t.expires_at > UTC_TIMESTAMP() FOR UPDATE");
    $query->execute([$user['token_id'], $user['id']]);
    if (!$query->fetchColumn()) {
        throw new ApiException(401, 'La sesión no es válida o ha vencido.', ['authenticated' => false]);
    }
}

function change_password(array $user, array $data): array
{
    if (!valid_password_input($data['current_password'] ?? null) || !valid_password_input($data['new_password'] ?? null, 12)) {
        throw new ApiException(422, 'La nueva contraseña debe tener entre 12 y 72 bytes UTF-8, sin caracteres NUL. Proporciona también tu contraseña actual.');
    }
    if (hash_equals($data['current_password'], $data['new_password'])) {
        throw new ApiException(422, 'Elige una contraseña diferente a la actual.');
    }
    $attemptId = begin_auth_attempt($user['email']);
    $connection = db();
    $connection->beginTransaction();
    try {
        $query = $connection->prepare('SELECT password_hash FROM users WHERE id = ? FOR UPDATE');
        $query->execute([$user['id']]);
        $passwordHash = $query->fetchColumn();
        lock_current_session($user);
        if (!$passwordHash || !password_verify($data['current_password'], $passwordHash)) {
            throw new ApiException(422, 'La contraseña actual no es correcta.');
        }
        $update = $connection->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $update->execute([password_hash($data['new_password'], PASSWORD_DEFAULT), $user['id']]);
        $revoke = $connection->prepare('UPDATE access_tokens SET revoked_at = UTC_TIMESTAMP() WHERE user_id = ? AND revoked_at IS NULL');
        $revoke->execute([$user['id']]);
        finish_auth_attempt($attemptId);
        audit_log($user, 'auth.password_changed', 'user', (int) $user['id'], 'Cambio de contraseña y cierre de todas las sesiones.');
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
    return ['authenticated' => false, 'message' => 'Contraseña actualizada. Se cerraron todas tus sesiones. Inicia sesión con la nueva contraseña.'];
}

function list_sessions(array $user): array
{
    $query = db()->prepare('SELECT id, created_at, expires_at FROM access_tokens WHERE user_id = ? AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP() ORDER BY created_at DESC, id DESC');
    $query->execute([$user['id']]);
    $sessions = [];
    foreach ($query->fetchAll() as $session) {
        $sessions[] = [
            'id' => (int) $session['id'],
            'created_at' => (new DateTimeImmutable($session['created_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => (new DateTimeImmutable($session['expires_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
            'current' => (int) $session['id'] === (int) $user['token_id'],
        ];
    }
    return ['sessions' => $sessions];
}

function revoke_other_sessions(array $user): array
{
    $connection = db();
    $connection->beginTransaction();
    try {
        $query = $connection->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $query->execute([$user['id']]);
        $query->fetchColumn();
        lock_current_session($user);
        $revoke = $connection->prepare('UPDATE access_tokens SET revoked_at = UTC_TIMESTAMP() WHERE user_id = ? AND id <> ? AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()');
        $revoke->execute([$user['id'], $user['token_id']]);
        $count = $revoke->rowCount();
        audit_log($user, 'auth.sessions_revoked', 'user', (int) $user['id'], "Cierre de {$count} sesiones adicionales.");
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
    return ['revoked_count' => $count, 'message' => 'Las otras sesiones se cerraron. Esta sesión sigue activa.'];
}
