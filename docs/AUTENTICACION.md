# Autenticación: PHP y Python hablan el mismo protocolo

La interfaz web utiliza las operaciones GraphQL `login`, `me`, `logout`, `changePassword`, `sessions` y `revokeOtherSessions` mediante `POST /api/graphql.php`. Los clientes PHP/Python descritos aquí conservan las rutas REST de compatibilidad. Ambos transportes usan los mismos tokens, permisos y reglas. Consulta [GRAPHQL.md](GRAPHQL.md) para ejemplos GraphQL.

El servidor está escrito en PHP y consulta MySQL/MariaDB mediante PDO. La aplicación del navegador, el cliente PHP y el cliente Python envían las mismas solicitudes **HTTP con JSON**. Python no accede directamente a las tablas ni necesita conocer cómo está guardada la contraseña.

## Flujo completo

```mermaid
sequenceDiagram
    participant C as Navegador / Python / PHP
    participant A as API PHP
    participant D as MySQL en XAMPP
    C->>A: POST auth/login: correo y contraseña
    A->>D: Buscar usuario y verificar límite de intentos
    D-->>A: Usuario y hash de contraseña
    A->>A: password_verify(contraseña, hash)
    alt Credenciales correctas
        A->>D: Guardar SHA-256 del token y vencimiento
        A-->>C: 200: authenticated=true, token y usuario
        C->>A: GET auth/me, Authorization: Bearer token
        A->>D: Buscar hash del token vigente y no revocado
        A-->>C: 200: authenticated=true y usuario
        C->>A: POST auth/logout con el token
        A->>D: Marcar token como revocado
        A-->>C: 200: authenticated=false
        C->>A: GET auth/me con el token anterior
        A-->>C: 401: authenticated=false
    else Credenciales incorrectas
        A-->>C: 401: authenticated=false
    end
```

## Qué guarda cada componente

| Componente | Información |
| --- | --- |
| `users.password_hash` | Un hash creado mediante `password_hash`, nunca la contraseña original. |
| `access_tokens.token_hash` | SHA-256 de un token opaco generado con `random_bytes`. |
| `access_tokens.expires_at` | Caducidad; ocho horas por defecto. |
| `access_tokens.revoked_at` | Momento del cierre de sesión, si el token fue revocado. |
| Navegador | Token de sesión en `sessionStorage`; se envía como encabezado Bearer. |
| Clientes de ejemplo | Token solamente en memoria; lo revocan antes de terminar. |

El token es una credencial temporal opaca; no es un JWT y el cliente no puede calcular a partir de él la identidad. Cada solicitud protegida debe pasar por la validación del servidor. Cerrar la sesión en un cliente revoca ese token.

La primera cuenta se crea con el instalador de consola. Después, un administrador puede crear o modificar cuentas desde **Usuarios**. No hay registro público. Las consultas de empleados y nóminas exigen autenticación; crear o modificar registros requiere el rol `admin`. Las cuentas inactivas no pueden iniciar sesión ni utilizar tokens existentes.

La implementación está en `src/services/AuthenticationService.php`: valida credenciales, entrega tokens y verifica sesiones. En **Mi cuenta** puedes consultar tus sesiones, cerrar las demás y cambiar tu contraseña proporcionando la actual. Cambiar la contraseña cierra todas las sesiones, incluida la actual.

Los roles `admin` y `viewer` se comprueban en el servidor. Usuarios y Auditoría requieren `admin`, incluidas sus consultas. `viewer` puede consultar datos de nómina, indicadores y CSV, cambiar su propia contraseña y gestionar sus sesiones. Cambiar el correo, rol, estado o contraseña de una cuenta desde Usuarios revoca todas sus sesiones. Las acciones exitosas de acceso, cierre, cambio de contraseña y cierre de otras sesiones se auditan en su misma transacción, sin guardar contraseñas ni tokens. Consulta [Usuarios y Auditoría](USUARIOS_Y_AUDITORIA.md) y las rutas de [API.md](API.md).

## Solicitudes HTTP

La URL base local es `http://127.0.0.1:8088/api/index.php`. La ruta viaja en el parámetro `route`; no requiere configurar reescritura de URL.

### 1. Solicitar acceso

```http
POST /api/index.php?route=auth/login HTTP/1.1
Content-Type: application/json
Accept: application/json

{"email":"admin@nomina.local","password":"Nomina2026!"}
```

Respuesta válida, con valores ilustrativos:

```json
{
  "authenticated": true,
  "token": "TOKEN_OPACO_GENERADO_POR_EL_SERVIDOR",
  "expires_at": "FECHA_DE_VENCIMIENTO",
  "user": {
    "id": 1,
    "name": "Administración demo",
    "email": "admin@nomina.local",
    "role": "admin",
    "status": "active",
    "created_at": "2026-10-01 12:00:00"
  }
}
```

Credenciales incorrectas producen **HTTP 401** y `authenticated: false`. Campos faltantes, de tipo incorrecto o demasiado largos producen **422**; demasiados intentos producen **429**. El mensaje de rechazo no distingue entre correo inexistente y contraseña incorrecta.

### 2. Comprobar la identidad

```http
GET /api/index.php?route=auth/me HTTP/1.1
Authorization: Bearer TOKEN_OPACO_GENERADO_POR_EL_SERVIDOR
Accept: application/json
```

Con un token vigente de una cuenta activa: **200**, `authenticated: true` y el usuario. Sin token, o si es inválido, vencido, revocado o pertenece a una cuenta inactiva: **401**, `authenticated: false`. Consultar esta ruta evita asumir que un token almacenado sigue siendo válido.

### 3. Cerrar sesión

```http
POST /api/index.php?route=auth/logout HTTP/1.1
Authorization: Bearer TOKEN_OPACO_GENERADO_POR_EL_SERVIDOR
Content-Type: application/json

{}
```

Después del cierre, volver a consultar `auth/me` con el mismo token debe devolver **401**. La revocación se registra en la base de datos y funciona entre lenguajes y procesos.

## Ejemplo Python

```powershell
python examples/python/cliente_auth.py --email admin@nomina.local
```

El programa pide la contraseña con `getpass`, hace login con `urllib.request`, envía el token en `Authorization`, comprueba la identidad y cierra la sesión. También verifica que un token revocado sea rechazado. Abre [`cliente_auth.py`](../examples/python/cliente_auth.py) para ver el código completo.

Para Apache de XAMPP:

```powershell
python examples/python/cliente_auth.py --url http://localhost/nomina-clara/public/api/index.php --email admin@nomina.local
```

Para probar rechazo, vuelve a ejecutar el comando e introduce una contraseña incorrecta. El cliente no repite automáticamente los intentos, para que el comportamiento sea fácil de observar y no active accidentalmente el límite de acceso.

## Ejemplo PHP

```powershell
& 'C:\xampp\php\php.exe' examples/php/cliente_auth.php --email admin@nomina.local
```

[`cliente_auth.php`](../examples/php/cliente_auth.php) usa cURL para la misma secuencia. La captura interactiva de este cliente PHP es visible y el programa lo indica. Para una entrada oculta usa el cliente Python; para automatizar ambos clientes se admite la variable de entorno `NOMINA_PASSWORD`.

Ejemplo automatizado con la contraseña pública de demostración, en PowerShell:

```powershell
$env:NOMINA_PASSWORD = 'Nomina2026!'
try {
    python examples/python/cliente_auth.py --email admin@nomina.local
    $LASTEXITCODE
} finally {
    Remove-Item Env:NOMINA_PASSWORD -ErrorAction SilentlyContinue
}
```

Las contraseñas no se aceptan como argumentos de línea de comandos y los clientes no imprimen tokens. El ejemplo anterior usa exclusivamente la contraseña ficticia conocida del modo demo.

## Interpretar el resultado

Los clientes devuelven un objeto con `authenticated: true` si comprobaron el acceso. Como revocan su sesión antes de terminar, también incluyen `session_revoked: true` y `revoked_token_authenticated: false`. Esto no cierra una sesión distinta abierta en el navegador.

| Código de salida | Significado |
| --- | --- |
| `0` | Login, identidad y revocación comprobados correctamente. |
| `1` | Credenciales rechazadas por la API: HTTP 401 y `authenticated: false`. |
| `2` | Problema de conexión, validación, límite de intentos o respuesta inesperada. |

Si falla un paso después del login, los clientes intentan cerrar esa sesión antes de terminar. Si el servidor ya no responde, no pueden confirmar esa revocación; el token conserva su vencimiento normal.

## Referencias

- [Manual oficial de `password_hash` en PHP](https://www.php.net/password-hash): creación del hash que guarda la aplicación.
- [Guía oficial de solicitudes HTTP con `urllib.request` en Python](https://docs.python.org/3/howto/urllib2.html): comunicación HTTP del segundo cliente.
