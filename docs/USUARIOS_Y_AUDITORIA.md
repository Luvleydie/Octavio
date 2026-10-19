# Administración de usuarios y Auditoría

Estos dos servicios completan los **ocho servicios principales**, junto con Empleados, Asistencias, Incidencias, Nóminas, Reportes y Autenticación. Cálculo y Pagos son dos módulos auxiliares. Todos funcionan en el mismo servidor PHP y la misma base de datos.

## Actualizar y abrir

Con MySQL de XAMPP iniciado, ejecuta desde la carpeta del proyecto:

```powershell
& 'C:\xampp\php\php.exe' scripts/install.php --migrate-only
powershell -ExecutionPolicy Bypass -File .\iniciar.ps1
```

La migración agrega el estado de las cuentas y la estructura de auditoría que falte. Conserva datos, contraseñas e historial anterior, no agrega cuentas ni empleados y puede repetirse. Los registros de auditoría anteriores conservan sus datos `details`, que no se exponen mediante esta API; si faltaban autor o resumen se muestran `Usuario histórico` y `Registro histórico`.

Abre **http://127.0.0.1:8088**, inicia sesión con un administrador y usa **Usuarios** o **Auditoría** en la navegación.

## 1. Administración de usuarios

El administrador puede listar cuentas, crear una con nombre, correo y contraseña, editar sus datos, asignar el rol `admin` o `viewer`, activar/desactivar la cuenta y restablecer la contraseña de otra persona. El formulario de edición deja conservar la contraseña al no capturar una nueva; para cambiar la propia, la interfaz ofrece **Mi cuenta**.

- Una cuenta nueva usa `viewer` y `active` por defecto. El correo es único y se normaliza a minúsculas.
- Las contraseñas nuevas deben contener de 12 a 72 bytes UTF-8, sin NUL; los acentos pueden ocupar más de un byte. Se guardan como hashes mediante `password_hash`, nunca como texto ni cifrado reversible.
- Cambiar correo, rol, estado o contraseña cierra todas las sesiones de esa cuenta. Cambiar solamente su nombre conserva las sesiones. Una cuenta inactiva no puede iniciar sesión ni reutilizar tokens anteriores.
- Un administrador no puede desactivar su propia cuenta ni quitarse el rol de administrador. Las operaciones se coordinan con bloqueos para conservar al menos un administrador activo.
- No se eliminan cuentas mediante la API. Desactivarlas conserva sus referencias e historial. Un usuario de acceso es distinto de un empleado que recibe nómina.

Usuarios y Auditoría requieren `admin`, incluso para consultar. `viewer` puede consultar empleados, asistencias, incidencias, nóminas, indicadores y reportes CSV, además de administrar su propia contraseña y sesiones.

La interfaz envía GraphQL a `POST /api/graphql.php`, con `Content-Type: application/json` y `Authorization: Bearer TOKEN`. El resolver llama a `src/api.php`, que valida permisos y ejecuta `src/services/UserService.php`; este usa consultas preparadas y transacciones en MySQL.

```graphql
query Usuarios {
  users { id name email role status created_at }
}
```

Para crear una cuenta, envía la operación y sus variables en el cuerpo JSON:

```graphql
mutation CrearUsuario($input: UserCreateInput!) {
  createUser(input: $input) { id name email role status }
}
```

```json
{
  "query": "mutation CrearUsuario($input: UserCreateInput!) { createUser(input: $input) { id name email role status } }",
  "variables": {
    "input": {
      "name": "Consulta de nómina",
      "email": "consulta@example.test",
      "password": "EjemploTemporal2026!",
      "role": "viewer",
      "status": "active"
    }
  }
}
```

Para actualizar, `updateUser(id: ID!, input: UserUpdateInput!)` admite uno o más de `name`, `email`, `role`, `status` y `password`. Omitir `password` conserva la actual; no envíes una cadena vacía. El siguiente ejemplo desactiva la cuenta indicada:

```graphql
mutation DesactivarUsuario($id: ID!) {
  updateUser(id: $id, input: { status: "inactive" }) { id status }
}
```

El equivalente REST es `GET ?route=users`, `POST ?route=users` o `PATCH ?route=users/ID`, sobre `/api/index.php`. Un alta recibe el mismo objeto que `input` y devuelve **201** con `{"user":{...}}`; una edición devuelve **200** y un listado, `{"users":[...]}`. No se devuelven contraseñas ni hashes.

## 2. Auditoría

La pantalla muestra fecha y hora UTC, autor, acción, recurso y resumen. Permite filtrar por usuario, acción, recurso y rango de fechas, y avanzar entre páginas de 20 registros. El servidor aplica los filtros y la paginación; el orden es del evento más reciente al más antiguo.

El servicio `src/services/AuditService.php` guarda el evento dentro de la misma transacción del cambio. Si falla la operación, se revierten el cambio y el evento; no queda registrado como éxito. No se almacenan contraseñas, tokens ni cuerpos completos de solicitudes en los eventos nuevos.

| Recurso | Acciones registradas |
| --- | --- |
| `user` | `user.created`, `user.updated`, `auth.login`, `auth.logout`, `auth.password_changed`, `auth.sessions_revoked` |
| `employee` | `employee.created`, `employee.updated` |
| `attendance` | `attendance.created`, `attendance.updated` |
| `incidence` | `incidence.created`, `incidence.updated` |
| `payroll` | `payroll.created`, `payroll.paid`, `payroll.cancelled` |

Las lecturas, descargas, intentos fallidos de acceso y solicitudes rechazadas no generan eventos de éxito. Repetir el pago o cancelación de una nómina ya procesada conserva el evento original. El historial es de **solo lectura**: no existen operaciones públicas para crear, editar o borrar eventos manualmente.

```graphql
query Historial($filter: AuditFilter) {
  auditLogs(filter: $filter) {
    items { id actor_id actor_name action entity entity_id summary created_at }
    total
    page
    per_page
  }
}
```

Variables de ejemplo:

```json
{
  "filter": {
    "entity": "user",
    "date_from": "2026-10-01",
    "date_to": "2026-10-31",
    "page": 1,
    "per_page": 20
  }
}
```

También admite `actor_id` y `action`; los filtros de acción y recurso son coincidencias exactas. Las fechas `YYYY-MM-DD` incluyen el día completo **en UTC**, desde `00:00:00` hasta `23:59:59`; no representan días de la zona horaria local. `created_at` se devuelve como `YYYY-MM-DD HH:MM:SS` UTC. `page` admite de 1 a 1,000,000 y `per_page` de 1 a 100, con valores iniciales 1 y 20.

Equivalente REST, con un Bearer de administrador:

```http
GET /api/index.php?route=audit&entity=user&date_from=2026-10-01&date_to=2026-10-31&page=1&per_page=20 HTTP/1.1
Authorization: Bearer TOKEN
Accept: application/json
```

Devuelve **200** con `items`, `total`, `page` y `per_page`. No se llama `audit` en GraphQL: allí la consulta es `auditLogs`.

## Respuestas que conviene revisar

| Estado REST | Significado |
| --- | --- |
| `401` | Sesión inválida, revocada, vencida o cuenta inactiva. |
| `403` | Se necesita administrador. |
| `404` | La cuenta a actualizar no existe. |
| `405` | Método no permitido, como borrar una cuenta o escribir auditoría. |
| `409` | Correo duplicado, intento de desactivación/degradación propia o conflicto concurrente. |
| `422` | Campos, contraseña, filtros o fechas inválidos. |

En GraphQL revisa `errors` aunque HTTP sea 200; los errores de la aplicación incluyen `extensions.status`. Hay solicitudes listas para usar en [examples/graphql/solicitudes.http](../examples/graphql/solicitudes.http) y el catálogo completo en [GRAPHQL.md](GRAPHQL.md).
