# Servicios de Nómina Clara

Guía resumida de funcionamiento y consumo · 14 de octubre de 2026

Nómina Clara es una aplicación de nómina en PHP y MySQL. Usuarios controla quién puede entrar y qué puede hacer; Auditoría conserva el historial de acciones; Departamentos organiza las áreas a las que pertenecen los empleados. Los servicios son módulos de la misma aplicación y comparten servidor, API y base de datos.

## Acceso y consumo común

Con MySQL de XAMPP iniciado, ejecuta desde la carpeta del proyecto:

```powershell
& 'C:\xampp\php\php.exe' scripts/install.php --migrate-only
powershell -ExecutionPolicy Bypass -File .\iniciar.ps1
```

Abre http://127.0.0.1:8088 e inicia sesión. El administrador ve las pantallas Usuarios, Auditoría y Departamentos. El rol `viewer` puede consultar Departamentos, pero no administrar cuentas ni leer auditoría.

La interfaz usa `POST http://127.0.0.1:8088/api/graphql.php` con `Content-Type: application/json`. Una `query` lee datos y una `mutation` los modifica. El cuerpo JSON contiene `query` y, cuando se necesitan, `variables`. En Postman puedes usar Body → GraphQL para introducir ambos por separado.

Primero inicia sesión sin Bearer:

```graphql
mutation Login($email: String!, $password: String!) {
  login(email: $email, password: $password) {
    token expires_at user { id name role }
  }
}
```

Variables, sustituyendo los valores por una cuenta existente:

```json
{"email":"TU_CORREO", "password":"TU_CONTRASEÑA"}
```

La respuesta devuelve el token en `data.login.token`. Para las operaciones de las páginas siguientes agrega `Authorization: Bearer TOKEN`. Por ejemplo, una consulta HTTP puede enviar este cuerpo JSON:

```json
{"query":"query { users { id name email role status } }"}
```

El servidor valida el token y los permisos, llama al servicio PHP y consulta MySQL. Revisa siempre `errors` en GraphQL, aunque HTTP sea 200. `errors[].extensions.status` distingue sesión inválida (401), permiso insuficiente (403), conflicto (409) y datos inválidos (422). REST usa los códigos HTTP directamente.

## Usuarios

### Qué es y cómo funciona

Administra cuentas de acceso, que son distintas de los empleados que reciben nómina. Solo un administrador puede listar, crear y editar cuentas. En la pantalla Usuarios se capturan nombre, correo, rol y estado; al crear una cuenta también se necesita contraseña.

El servicio `UserService.php` valida los datos y guarda el cambio junto con su evento de auditoría en una transacción. El correo es único y se normaliza a minúsculas. Las contraseñas se guardan como hashes y nunca se devuelven por la API.

- Roles: `admin` y `viewer`. Estados: `active` e `inactive`. El alta usa `viewer` y `active` si se omiten.
- Una contraseña nueva debe medir de 12 a 72 bytes UTF-8. En una edición, omitir `password` conserva la contraseña actual; no se envía una cadena vacía.
- Cambiar correo, rol, estado o contraseña revoca las sesiones de esa cuenta. Cambiar solo el nombre las conserva.
- No se borran cuentas. Desactivarlas conserva sus referencias y su historial. Un administrador no puede desactivarse ni quitarse su propio rol; se conserva al menos un administrador activo.

### Cómo consumirlo

Consulta de cuentas:

```graphql
query {
  users { id name email role status created_at }
}
```

Alta de una cuenta:

```graphql
mutation CrearUsuario($input: UserCreateInput!) {
  createUser(input: $input) { id name email role status }
}
```

Variables de ejemplo, con un correo todavía no registrado:

```json
{"input": {
  "name":"Consulta de nómina", "email":"consulta@example.test",
  "password":"Temporal2026!Cambiar", "role":"viewer",
  "status":"active"
}}
```

Para editar usa `updateUser(id: ID!, input: UserUpdateInput!)`; `input` admite cambios parciales en `name`, `email`, `role`, `status` y `password`. La contraseña del ejemplo debe reemplazarse por una propia.

REST equivalente sobre `/api/index.php`: `GET ?route=users` lista; `POST ?route=users` crea; `PATCH ?route=users/ID` edita. Las escrituras reciben directamente el objeto `input`. Un alta devuelve 201 con `user`; una edición devuelve 200 con `user`; el listado devuelve 200 con `users`.

## Auditoría

### Qué es y cómo funciona

Conserva quién realizó una acción, sobre qué registro y cuándo. Solo un administrador puede consultar la pantalla Auditoría. Puede filtrar por autor, acción, recurso y fechas, y recorrer el historial en páginas de 20 registros.

`AuditService.php` registra los cambios exitosos dentro de la misma transacción que la operación. Si esta falla, se revierten el cambio y su evento. El historial se consulta en orden del evento más reciente al más antiguo y es de solo lectura: no existen operaciones públicas para editarlo ni borrarlo.

| Recurso | Ejemplos de acciones |
| --- | --- |
| Cuentas y acceso | `user.created`, `user.updated`, `auth.login`, `auth.logout` |
| Empleados y registros | `employee.created`, `attendance.updated`, `incidence.created` |
| Nóminas | `payroll.created`, `payroll.paid`, `payroll.cancelled` |
| Departamentos | `department.created`, `department.updated` |

Los eventos nuevos no guardan contraseñas, tokens ni cuerpos completos de solicitudes. Las lecturas y los intentos fallidos no generan eventos de éxito.

### Cómo consumirlo

```graphql
query Historial($filter: AuditFilter) {
  auditLogs(filter: $filter) {
    items {
      id actor_name action entity entity_id summary created_at
    }
    total page per_page
  }
}
```

Variables para consultar cambios de cuentas durante octubre:

```json
{"filter": {
  "entity":"user", "date_from":"2026-10-01",
  "date_to":"2026-10-31", "page":1, "per_page":20
}}
```

También se aceptan `actor_id` y `action`. Las acciones y los recursos usan coincidencia exacta. `per_page` admite de 1 a 100; la respuesta incluye el total de registros para calcular las páginas. Tanto `created_at` como los días completos del filtro se interpretan en UTC.

REST equivalente con el mismo Bearer de administrador:

```http
GET /api/index.php?route=audit&entity=user&page=1&per_page=20
```

La respuesta 200 contiene `items`, `total`, `page` y `per_page`. El nombre de la consulta GraphQL es `auditLogs`; el de la ruta REST es `audit`.

## Departamentos

### Qué es y cómo funciona

Centraliza las áreas de la organización para que los empleados usen un catálogo común. Los usuarios autenticados pueden consultarlo; solo un administrador puede crear o editar departamentos. En la pantalla Departamentos se muestran nombre, estado, empleados asociados y empleados activos. La pantalla Empleados ofrece el catálogo para elegir un área.

`DepartmentService.php` valida y guarda las áreas y registra sus cambios en Auditoría. Al renombrar un departamento también actualiza el nombre en los empleados vinculados; los datos históricos ya guardados en nóminas conservan su valor original.

El estado `inactive` impide nuevas asignaciones, sin desactivar a los empleados que ya pertenecían al área. La migración incorpora los nombres que ya existían en empleados. Por compatibilidad, registrar un empleado con un nombre de departamento nuevo crea esa área en el catálogo.

### Cómo consumirlo

Consulta del catálogo con sus conteos:

```graphql
query {
  departments {
    id name status employee_count active_employee_count created_at
  }
}
```

Alta de un departamento:

```graphql
mutation CrearDepartamento($input: DepartmentCreateInput!) {
  createDepartment(input: $input) { id name status }
}
```

Variables de ejemplo:

```json
{"input":{"name":"Operaciones", "status":"active"}}
```

`name` es obligatorio en el alta; `status` usa `active` si se omite. Para editar o desactivar, envía únicamente los campos que cambian:

```graphql
mutation EditarDepartamento(
  $id: ID!, $input: DepartmentUpdateInput!
) {
  updateDepartment(id: $id, input: $input) { id name status }
}
```

Variables para desactivar el departamento elegido, sustituyendo el ID:

```json
{"id":"ID_DEL_DEPARTAMENTO", "input":{"status":"inactive"}}
```

REST equivalente sobre `/api/index.php`: `GET ?route=departments`, `POST ?route=departments` y `PATCH ?route=departments/ID`. Las escrituras reciben el objeto `input` directamente y requieren Bearer de administrador.

El proyecto reúne nueve servicios principales y dos módulos auxiliares de cálculo y pagos. El catálogo completo está en `README.md`; los contratos de la API están en `src/graphql/schema.graphql` y `src/graphql/mutations.graphql`.
