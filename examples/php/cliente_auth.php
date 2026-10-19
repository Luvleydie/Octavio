<?php
/**
 * Cliente CLI PHP: login, identidad, cierre de sesión y comprobación de revocación.
 * Requiere PHP 8.1+ y extensión cURL (incluida en XAMPP).
 * Salida: 0 = autenticación comprobada; 1 = credenciales rechazadas; 2 = otro error.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function outputJson(array $value, bool $error = false): void
{
    fwrite($error ? STDERR : STDOUT, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL);
}

function endpoint(string $baseUrl, string $route): string
{
    $parts = parse_url($baseUrl);
    if ($parts === false || !isset($parts['host']) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
        throw new RuntimeException('La URL debe comenzar con http:// o https://.');
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        throw new RuntimeException('No incluyas credenciales dentro de la URL.');
    }
    $query = [];
    parse_str($parts['query'] ?? '', $query);
    $query['route'] = $route;
    $origin = $parts['scheme'] . '://' . $parts['host'];
    if (isset($parts['port'])) {
        $origin .= ':' . $parts['port'];
    }
    return $origin . ($parts['path'] ?? '') . '?' . http_build_query($query);
}

function requestApi(string $baseUrl, string $route, string $method = 'GET', ?array $body = null, ?string $token = null): array
{
    $headers = ['Accept: application/json'];
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
    ];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_POSTFIELDS] = json_encode((object) $body, JSON_THROW_ON_ERROR);
    }
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $options[CURLOPT_HTTPHEADER] = $headers;
    $handle = curl_init(endpoint($baseUrl, $route));
    curl_setopt_array($handle, $options);
    $payload = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $failed = $payload === false;
    curl_close($handle);
    if ($failed) {
        throw new RuntimeException('No se pudo conectar con la API. Revisa Apache/PHP, MySQL y --url.');
    }
    try {
        $decoded = json_decode((string) $payload, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new RuntimeException('La API no devolvió JSON válido. Revisa la URL y la configuración del servidor.');
    }
    if (!is_array($decoded)) {
        throw new RuntimeException('La respuesta de la API debe ser un objeto JSON.');
    }
    return [$status, $decoded];
}

function promptLine(string $prompt): string
{
    fwrite(STDERR, $prompt);
    $line = fgets(STDIN);
    if ($line === false) {
        throw new RuntimeException('No se recibió la entrada solicitada.');
    }
    return rtrim($line, "\r\n");
}

function main(): int
{
    if (!extension_loaded('curl')) {
        throw new RuntimeException('Activa extension=curl en el php.ini de XAMPP.');
    }
    $options = getopt('', ['url:', 'email:', 'help']);
    if (isset($options['help'])) {
        fwrite(STDOUT, "Uso: php examples/php/cliente_auth.php [--url URL] [--email CORREO]\nLa contraseña se lee de NOMINA_PASSWORD o de la entrada estándar; nunca de argumentos.\nEl cliente cierra y comprueba la revocación de la sesión al terminar.\n");
        return 0;
    }
    $url = $options['url'] ?? 'http://127.0.0.1:8088/api/index.php';
    $email = trim($options['email'] ?? promptLine('Correo: '));
    $password = getenv('NOMINA_PASSWORD');
    if ($password === false) {
        // PHP CLI no incluye una lectura oculta multiplataforma. Usa el cliente
        // Python (getpass) para entrada oculta o proporciona NOMINA_PASSWORD.
        $password = promptLine('Contraseña (visible en esta terminal PHP): ');
    }
    if ($email === '' || $password === '') {
        throw new RuntimeException('El correo y la contraseña son obligatorios.');
    }
    [$status, $login] = requestApi($url, 'auth/login', 'POST', ['email' => $email, 'password' => $password]);
    if ($status === 401 && ($login['authenticated'] ?? null) === false) {
        outputJson(['authenticated' => false, 'message' => 'Correo o contraseña incorrectos.']);
        return 1;
    }
    if ($status === 422) {
        throw new RuntimeException('Datos de acceso inválidos. Comprueba el correo y la contraseña.');
    }
    if ($status === 429) {
        throw new RuntimeException('Demasiados intentos. Espera antes de volver a intentar.');
    }
    $token = $login['token'] ?? null;
    if ($status !== 200 || ($login['authenticated'] ?? null) !== true || !is_string($token) || $token === '') {
        throw new RuntimeException('El inicio de sesión no devolvió el contrato esperado (HTTP ' . $status . ').');
    }
    $revoked = false;
    try {
        [$status, $identity] = requestApi($url, 'auth/me', 'GET', null, $token);
        if ($status !== 200 || ($identity['authenticated'] ?? null) !== true || !is_array($identity['user'] ?? null)) {
            throw new RuntimeException('La API no pudo validar la identidad de la sesión.');
        }
        [$status, $logout] = requestApi($url, 'auth/logout', 'POST', [], $token);
        if ($status !== 200 || ($logout['authenticated'] ?? null) !== false) {
            throw new RuntimeException('No se pudo confirmar el cierre de sesión.');
        }
        $revoked = true;
        [$status, $afterLogout] = requestApi($url, 'auth/me', 'GET', null, $token);
        if ($status !== 401 || ($afterLogout['authenticated'] ?? null) !== false) {
            throw new RuntimeException('La API aceptó una sesión que ya había sido cerrada.');
        }
        outputJson([
            'authenticated' => true,
            'user' => $identity['user'],
            'expires_at' => $login['expires_at'] ?? null,
            'session_revoked' => true,
            'revoked_token_authenticated' => false,
        ]);
        return 0;
    } finally {
        if (!$revoked) {
            try {
                requestApi($url, 'auth/logout', 'POST', [], $token);
            } catch (Throwable $ignored) {
                // Conservamos el error original y evitamos imprimir credenciales.
            }
        }
    }
}

try {
    exit(main());
} catch (Throwable $error) {
    outputJson(['error' => $error instanceof RuntimeException ? $error->getMessage() : 'No se pudo completar la comunicación con la API.'], true);
    exit(2);
}
