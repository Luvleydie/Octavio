# API GraphQL de Nómina Clara

La aplicación web utiliza GraphQL mediante `webonyx/graphql-php`. La base de datos sigue siendo MySQL/MariaDB de XAMPP: GraphQL define la API, no el almacenamiento. Los servicios reciben parámetros y devuelven datos, por lo que ambos transportes comparten las validaciones, permisos y transacciones.

## Endpoint HTTP y código

```http
POST http://127.0.0.1:8088/api/graphql.php
Content-Type: application/json
Authorization: Bearer TOKEN
```

GraphQL tiene un único endpoint. Dentro del cuerpo se elige una consulta (`query`, lectura) o una mutación (`mutation`, modificación). No existen URLs diferentes como `/graphql/employees`.

| Archivo | Responsabilidad |
| --- | --- |
| [public/api/graphql.php](../public/api/graphql.php) | Recibir POST JSON y devolver la respuesta GraphQL. |
| [src/graphql/schema.graphql](../src/graphql/schema.graphql) | Definir consultas, tipos de entrada y campos de salida. |
| [src/graphql/mutations.graphql](../src/graphql/mutations.graphql) | Definir las 15 mutaciones y sus argumentos, con comentarios de su función. |
| [src/graphql/server.php](../src/graphql/server.php) | Validar y ejecutar GraphQL; conectar operaciones con servicios. |
| [src/api.php](../src/api.php) | Comprobar autenticación y permisos en cada operación. |
| [public/assets/graphql.js](../public/assets/graphql.js) | Consultas y mutaciones utilizadas por la interfaz. |
| [examples/graphql/solicitudes.http](../examples/graphql/solicitudes.http) | Solicitudes listas para un cliente HTTP. |

## Consultas

Solo `health` y `login` son públicos. La introspección muestra el esquema, sin datos de negocio.

| Query | Argumentos | Función | Acceso |
| --- | --- | --- | --- |
| `health` | Ninguno | Comprobar base de datos. | Público |
| `me` | Ninguno | Consultar identidad. | Autenticado |
| `sessions` | Ninguno | Consultar sesiones propias. | Autenticado |
| `users` | Ninguno | Listar cuentas, roles y estado, sin contraseñas. | Administrador |
| `auditLogs` | `filter: AuditFilter` | Consultar el historial con filtros y paginación. | Administrador |
| `employees` | Ninguno | Listar empleados. | Autenticado |
| `attendance` | `filter: RecordFilter` | Filtrar asistencias. | Autenticado |
| `incidences` | `filter: RecordFilter` | Filtrar bonos y deducciones. | Autenticado |
| `payrolls` | Ninguno | Listar nóminas. | Autenticado |
| `payroll` | `id: ID!` | Obtener detalle histórico. | Autenticado |
| `payrollPreview` | `period_start: String!`, `period_end: String!`, `employee_id: ID` | Calcular desde registros, sin guardar. | Autenticado |
| `payments` | Ninguno | Listar nóminas pagadas. | Autenticado |
| `dashboard` | Ninguno | Consultar indicadores y pagos mensuales. | Autenticado |

`RecordFilter` admite `employee_id`, `period_start` y `period_end`. Las fechas usan `YYYY-MM-DD`. `ID` acepta un identificador numérico o una cadena y se devuelve como cadena.

`AuditFilter` admite `actor_id`, `action`, `entity`, `date_from`, `date_to`, `page` y `per_page`. Las fechas delimitan días UTC inclusivos. La página inicial es 1; `per_page` vale 20 por defecto y admite de 1 a 100. `auditLogs` devuelve `items`, `total`, `page` y `per_page`, ordenados del registro más reciente al más antiguo.

## Mutaciones

| Mutation | Argumentos | Función | Acceso |
| --- | --- | --- | --- |
| `login` | `email: String!`, `password: String!` | Obtener token de sesión. | Público |
| `logout` | Ninguno | Revocar sesión actual. | Autenticado |
| `changePassword` | `current_password: String!`, `new_password: String!` | Cambiar contraseña y revocar sesiones. | Autenticado |
| `revokeOtherSessions` | Ninguno | Cerrar otras sesiones propias. | Autenticado |
| `createUser` | `input: UserCreateInput!` | Crear una cuenta de acceso. | Administrador |
| `updateUser` | `id: ID!`, `input: UserUpdateInput!` | Actualizar cuenta, rol, estado o contraseña. | Administrador |
| `createEmployee` | `input: EmployeeCreateInput!` | Registrar empleado. | Administrador |
| `updateEmployee` | `id: ID!`, `input: EmployeeUpdateInput!` | Actualizar empleado. | Administrador |
| `createAttendance` | `input: AttendanceCreateInput!` | Registrar asistencia. | Administrador |
| `updateAttendance` | `id: ID!`, `input: AttendanceUpdateInput!` | Corregir asistencia. | Administrador |
| `createIncidence` | `input: IncidenceCreateInput!` | Registrar bono o deducción. | Administrador |
| `updateIncidence` | `id: ID!`, `input: IncidenceUpdateInput!` | Corregir incidencia. | Administrador |
| `createPayroll` | `input: PayrollInput!` | Calcular y guardar nómina. | Administrador |
| `payPayroll` | `id: ID!` | Registrar pago administrativo. | Administrador |
| `cancelPayroll` | `id: ID!`, `reason: String!` | Cancelar borrador y liberar periodo. | Administrador |

Los ocho servicios principales se cubren así: Empleados (`employees` y altas/cambios), Asistencias (`attendance` y altas/cambios), Incidencias (`incidences` y altas/cambios), Nóminas (`payrolls`, `payroll`, `createPayroll`, `cancelPayroll`), Reportes (`dashboard` y CSV), Autenticación (operaciones de sesión), Administración de usuarios (`users`, `createUser`, `updateUser`) y Auditoría (`auditLogs`). Los módulos auxiliares son Cálculo (`payrollPreview` y cálculo interno de `createPayroll`) y Pagos (`payments`, `payPayroll`).

El esquema tiene 13 consultas y 15 mutaciones. `User` incluye `id`, `name`, `email`, `role`, `status` y `created_at`; nunca expone hashes de contraseña. Usuarios y Auditoría requieren `admin`, incluso para consultar. `viewer` conserva las consultas de nómina, reportes CSV y operaciones de su propia sesión. No existen mutaciones para editar o borrar auditoría. Las reglas y ejemplos de los dos servicios están en [USUARIOS_Y_AUDITORIA.md](USUARIOS_Y_AUDITORIA.md).

## Ejemplos de código

Inicio de sesión, sin Bearer:

```graphql
mutation Login($email: String!, $password: String!) {
  login(email: $email, password: $password) {
    token
    expires_at
    user { id name role }
  }
}
```

El cuerpo HTTP es JSON, por ejemplo con las credenciales de demostración:

```json
{
  "query": "mutation Login($email: String!, $password: String!) { login(email: $email, password: $password) { token user { id name role } } }",
  "operationName": "Login",
  "variables": { "email": "admin@nomina.local", "password": "Nomina2026!" }
}
```

El token se encuentra en `data.login.token`. En las siguientes solicitudes envíalo en `Authorization: Bearer TOKEN`.

Consultar empleados e indicadores en una sola petición, eligiendo los campos:

```graphql
query Resumen {
  employees { id full_name department monthly_salary }
  dashboard { stats { active_employees pending_runs paid_total } }
}
```

Calcular una vista previa desde asistencias e incidencias:

```graphql
query {
  payrollPreview(period_start: "2026-10-01", period_end: "2026-10-15") {
    items { employee_id full_name days_worked net_pay can_create errors }
    totals { gross deductions net }
  }
}
```

Crear una nómina manual (requiere administrador):

```graphql
mutation {
  createPayroll(input: {
    period_start: "2026-10-01"
    period_end: "2026-10-15"
    items: [{ employee_id: 1, days_worked: 15, bonus: "500.00", deduction: "250.00" }]
  }) {
    payroll { id status total_net }
    items { full_name net_pay }
  }
}
```

Para usar registros, envía `use_records: true` y solo el `employee_id` de cada detalle. El servicio obtiene los días e importes de la base. El escalar `Money` acepta números o cadenas decimales; el cálculo sigue usando centavos enteros.

Registrar como pagada una nómina existente:

```graphql
mutation { payPayroll(id: 1) { payroll { id status paid_at } } }
```

## Respuestas y límites

- Una operación ejecutada responde HTTP 200, con `data` y, si corresponde, `errors`. Revisa siempre `errors`, incluso con HTTP 200. Los errores incluyen `extensions.status`: 401 (sesión), 403 (permisos), 409 (conflicto), 422 (validación), etc.
- Una solicitud HTTP inválida devuelve 400/405/413/415; dependencias ausentes devuelven 503. El endpoint acepta únicamente POST JSON, hasta 1 MiB. No acepta lotes JSON.
- Se admiten variables, alias, fragmentos, introspección y `operationName` mediante el motor GraphQL. Profundidad máxima: 12; complejidad máxima: 500.
- Cada campo raíz protegido vuelve a comprobar el token. Una mutación que cierre la sesión impide que otra posterior use ese token en la misma petición.
- Cada operación de negocio conserva su transacción. Varias mutaciones en una petición se ejecutan en orden, pero no forman una única transacción conjunta.
- Las exportaciones de archivos conservan los endpoints `GET /api/index.php?route=reports/employees` y `GET /api/index.php?route=reports/payrolls/{id}`, con Bearer. El resto de las rutas REST permanece disponible para los clientes existentes.

## Instalar y verificar

```powershell
composer install
powershell -ExecutionPolicy Bypass -File .\comprobar.ps1
```

Las pruebas HTTP usan bases temporales `nomina_test_*` que se eliminan al terminar; no escriben nóminas en la base del usuario. `tests/test_graphql.py` comprueba autenticación, permisos, revocación, selección de campos, documentos de la interfaz y el ciclo completo de nómina.

Referencias: [instalación de graphql-php](https://webonyx.github.io/graphql-php/getting-started/) y [ejecución y validación de consultas](https://webonyx.github.io/graphql-php/executing-queries/).
