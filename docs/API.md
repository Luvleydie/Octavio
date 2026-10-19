# API HTTP de Nómina Clara

La interfaz web usa ahora **GraphQL** en `POST /api/graphql.php`. Consulta [GRAPHQL.md](GRAPHQL.md) para el esquema, consultas, mutaciones y ejemplos. Las rutas de este documento se mantienen como API REST de compatibilidad y para las exportaciones CSV; comparten servicios y permisos con GraphQL.

Base con el servidor integrado de PHP:

```text
http://127.0.0.1:8088/api/index.php
```

Base con Apache en una subcarpeta de XAMPP:

```text
http://localhost/nomina-clara/public/api/index.php
```

Cada endpoint se construye con `?route=RUTA`. Por ejemplo: `http://127.0.0.1:8088/api/index.php?route=employees`.

Los cuerpos de entrada son objetos JSON con `Content-Type: application/json`; las respuestas son JSON, salvo las exportaciones CSV. Salvo `health` y `auth/login`, todas las rutas requieren:

```http
Authorization: Bearer TOKEN_RECIBIDO_EN_LOGIN
Accept: application/json
```

## Rutas disponibles

| Método | `route` | Acceso | Respuesta correcta |
| --- | --- | --- | --- |
| GET | `health` | Público | `200`, estado de aplicación y base de datos |
| POST | `auth/login` | Público | `200`, token e identidad |
| GET | `auth/me` | Autenticado | `200`, identidad |
| POST | `auth/logout` | Autenticado | `200`, sesión revocada |
| POST | `auth/password` | Autenticado | `200`, contraseña actualizada y todas las sesiones revocadas |
| GET | `auth/sessions` | Autenticado | `200`, sesiones propias |
| POST | `auth/sessions/revoke-others` | Autenticado | `200`, otras sesiones revocadas |
| GET | `users` | Administrador | `200`, `{"users":[...]}` |
| POST | `users` | Administrador | `201`, `{"user":{...}}` |
| PATCH | `users/{id}` | Administrador | `200`, `{"user":{...}}` |
| GET | `audit` | Administrador | `200`, `items`, `total`, `page`, `per_page` |
| GET | `dashboard` | Autenticado | `200`, estadísticas |
| GET | `employees` | Autenticado | `200`, lista de empleados |
| POST | `employees` | Administrador | `201`, empleado creado |
| PATCH | `employees/{id}` | Administrador | `200`, empleado actualizado |
| GET | `attendance` | Autenticado | `200`, asistencias filtradas |
| POST | `attendance` | Administrador | `201`, asistencia creada |
| PATCH | `attendance/{id}` | Administrador | `200`, asistencia actualizada |
| GET | `incidences` | Autenticado | `200`, incidencias filtradas |
| POST | `incidences` | Administrador | `201`, incidencia creada |
| PATCH | `incidences/{id}` | Administrador | `200`, incidencia actualizada |
| GET | `payrolls/preview` | Autenticado | `200`, vista previa a partir de registros |
| GET | `payrolls` | Autenticado | `200`, lista de nóminas |
| POST | `payrolls` | Administrador | `201`, nómina y detalle calculados |
| GET | `payrolls/{id}` | Autenticado | `200`, nómina y detalle |
| POST | `payrolls/{id}/pay` | Administrador | `200`, nómina marcada como pagada |
| POST | `payrolls/{id}/cancel` | Administrador | `200`, borrador cancelado con historial conservado |
| GET | `payments` | Autenticado | `200`, nóminas pagadas |
| GET | `reports/dashboard` | Autenticado | `200`, mismos indicadores que `dashboard` |
| GET | `reports/employees` | Autenticado | `200`, archivo CSV de empleados |
| GET | `reports/payrolls/{id}` | Autenticado | `200`, archivo CSV del detalle histórico |

También funcionan los alias `auth/change-password`, `employees/export` y `payrolls/{id}/export`. La ubicación de cada servicio está en [`SERVICIOS.md`](SERVICIOS.md).

## Estado de la aplicación

`GET ?route=health` comprueba la conexión con MySQL/MariaDB:

```json
{"status":"ok","database":"connected"}
```

## Autenticación

### POST `auth/login`

```json
{"email":"admin@nomina.local","password":"Nomina2026!"}
```

Ambos campos deben ser cadenas. El correo debe tener un formato válido y admite hasta 190 bytes; se convierte a minúsculas y se recortan los espacios de sus extremos antes de validarlo y buscar la cuenta. La contraseña admite hasta 72 bytes UTF-8, sin caracteres NUL; para crear cuentas propias, el instalador aplica entre 12 y 72 bytes. Cada carácter ASCII ocupa un byte. Las credenciales del ejemplo existen solo si se instaló `--demo`.

Respuesta **200**, con token y fecha ilustrativos:

```json
{
  "authenticated": true,
  "token": "TOKEN_OPACO_DE_64_CARACTERES_HEXADECIMALES",
  "expires_at": "2026-09-28T20:00:00Z",
  "user": {
    "id": 1,
    "name": "Administración demo",
    "email": "admin@nomina.local",
    "role": "admin"
  }
}
```

El token caduca a las ocho horas por defecto; `expires_at` usa UTC. Cada login correcto crea una sesión distinta. No se devuelve el hash de la contraseña.

Credenciales incorrectas: **401**.

```json
{"message":"Correo o contraseña incorrectos.","authenticated":false}
```

Campos faltantes, con tipos incorrectos, demasiado largos o correo de formato inválido: **422**. Existe un límite de intentos en una ventana de 15 minutos por correo y por dirección IP. Una vez superado se devuelve **429**, `authenticated: false` y `Retry-After: 900`.

### GET `auth/me`

Con un Bearer vigente devuelve **200**:

```json
{
  "authenticated": true,
  "user": {"id":1,"name":"Administración demo","email":"admin@nomina.local","role":"admin"}
}
```

Token ausente, inválido, vencido o revocado: **401** con `authenticated: false` y `message`.

### POST `auth/logout`

Envía el Bearer de la sesión que deseas cerrar. Puede acompañarse de un cuerpo `{}`. Respuesta **200**:

```json
{"authenticated":false,"message":"Sesión cerrada."}
```

El mismo token ya no sirve para `auth/me` ni para ninguna ruta protegida. La sesión de otros dispositivos o clientes, si usa un token distinto, continúa vigente.

Los clientes ejecutables y la explicación del protocolo están en [`AUTENTICACION.md`](AUTENTICACION.md).

### Contraseña y sesiones

`POST auth/password` recibe `{"current_password":"CONTRASEÑA_ACTUAL","new_password":"CONTRASEÑA_NUEVA"}`. La contraseña nueva debe tener entre 12 y 72 bytes UTF-8, no contener NUL y ser diferente de la actual. Al cambiarla se revocan todas las sesiones, incluida la actual; vuelve a iniciar sesión con la contraseña nueva. Responde con `authenticated: false`. `auth/change-password` es un alias.

`GET auth/sessions` permite consultar las sesiones propias. `POST auth/sessions/revoke-others` cierra las demás sesiones del usuario y conserva la que hace la petición.

## Resumen

### GET `dashboard`

```json
{
  "stats": {
    "active_employees": 6,
    "monthly_payroll": 128000,
    "last_run_total": 0,
    "total_runs": 0
  },
  "departments": [
    {"department":"Tecnología","total":2},
    {"department":"Administración","total":1}
  ],
  "recent_runs": []
}
```

Ejemplo abreviado. `monthly_payroll` suma los sueldos mensuales de empleados activos; `last_run_total` es el neto de la última nómina creada que no esté cancelada, o cero si no hay ninguna vigente. `total_runs` cuenta todas las nóminas, incluidas las canceladas. Los departamentos cuentan empleados activos; `recent_runs` incluye hasta cinco nóminas, desde la más reciente, también con las canceladas. `stats` contiene además `pending_runs` (solo borradores) y `paid_total` (neto total pagado). `monthly_payments` contiene seis objetos `{"month":"YYYY-MM","total":0}` con los pagos por mes UTC, incluido el mes actual.

## Empleados

### GET `employees`

Devuelve `{"employees":[...]}` con empleados activos e inactivos, ordenados por nombre e identificador. Cada empleado contiene los campos del ejemplo de creación, más `id` y `created_at`.

### POST `employees`

```json
{
  "employee_code": "NC-007",
  "full_name": "Ana López",
  "email": "ana@example.test",
  "department": "Administración",
  "position": "Auxiliar",
  "monthly_salary": "18000.00",
  "hire_date": "2026-01-01",
  "status": "active"
}
```

| Campo | Reglas |
| --- | --- |
| `employee_code` | Obligatorio, 1–24 caracteres: letras, números, guion y guion bajo. Se guarda en mayúsculas. Único. |
| `full_name` | Obligatorio, 1–160 caracteres. |
| `email` | Opcional, válido y único cuando tiene valor. `null` o cadena vacía significa sin correo. Máximo 190 bytes. |
| `department` | Obligatorio, 1–100 caracteres. |
| `position` | Obligatorio, 1–100 caracteres. |
| `monthly_salary` | Obligatorio, mayor que cero y hasta 10,000,000 MXN, con hasta dos decimales. |
| `hire_date` | Obligatorio, fecha válida `YYYY-MM-DD`. |
| `status` | `active` o `inactive`; por defecto `active`. |

Se admiten importes numéricos o cadenas decimales como `"18000.00"`; no uses símbolos de moneda ni separadores de miles. La respuesta **201** es `{"employee":{...}}`. Si la clave o el correo ya existen, devuelve **409**. No se admiten campos desconocidos.

### PATCH `employees/{id}`

Envía únicamente los campos que deseas modificar:

```json
{"monthly_salary":"19500.00","status":"inactive"}
```

La respuesta **200** es `{"employee":{...}}`. Un identificador inexistente devuelve **404**. No se elimina al empleado: puede desactivarse para conservar su historial. Actualizar un sueldo o nombre no cambia los datos ya guardados en nóminas anteriores. Mover su fecha de ingreso después de una asistencia, incidencia o del fin de un periodo ya generado devuelve **409**.

## Asistencias

### GET `attendance`

Devuelve `{"attendance":[...]}`. Admite los parámetros adicionales `employee_id`, `period_start` y `period_end`, por ejemplo:

```text
?route=attendance&employee_id=7&period_start=2026-10-01&period_end=2026-10-15
```

Cada fila contiene `id`, `employee_id`, `employee_code`, `full_name`, `work_date`, `status`, `notes`, `created_by` y `created_at`. Se ordenan por fecha descendente y nombre.

### POST `attendance`

```json
{"employee_id":7,"work_date":"2026-10-01","status":"present","notes":"Jornada completa"}
```

`status` admite `present` (asistencia), `absent` (falta) y `paid_leave` (permiso pagado). `notes` es opcional, máximo 255 caracteres sin controles. La fecha debe ser válida, igual o posterior al ingreso. El empleado debe existir y estar activo. Responde **201** con `{"attendance":{...}}`.

Solo se permite una fila por empleado y fecha; un duplicado devuelve **409**. Una fecha cubierta por una nómina vigente (`draft` o `paid`) del empleado también devuelve **409**. Una nómina cancelada no cierra ese periodo.

### PATCH `attendance/{id}`

Admite los mismos campos de forma parcial, por ejemplo `{"status":"paid_leave","notes":"Permiso autorizado"}`. Responde **200** con `{"attendance":{...}}`. No permite cambiar de empleado ni editar/mover un registro cuya fecha original o nueva esté cubierta por una nómina vigente: devuelve **409**. Al cancelar el borrador que cerraba el periodo, los registros pueden corregirse. No existe una ruta de eliminación.

## Incidencias

### GET `incidences`

Devuelve `{"incidences":[...]}`. Admite `employee_id`, `period_start` y `period_end`, con la misma sintaxis de Asistencias. Cada fila incluye `id`, `employee_id`, `employee_code`, `full_name`, `occurrence_date`, `type`, `concept`, `amount`, `created_by` y `created_at`.

### POST `incidences`

```json
{"employee_id":7,"occurrence_date":"2026-10-01","type":"bonus","concept":"Bono por productividad","amount":"500.00"}
```

`type` admite `bonus` o `deduction`. `concept` es obligatorio y admite hasta 120 caracteres; `amount` es positivo, con hasta dos decimales y un máximo de 10,000,000 MXN. La fecha debe ser válida e igual o posterior al ingreso; el empleado debe existir y estar activo. Responde **201** con `{"incidence":{...}}`.

Una incidencia capturada cuenta directamente para la nómina desde registros. El tipo define si suma o resta; el importe se envía siempre positivo. Pueden existir varios conceptos del mismo empleado en una fecha.

### PATCH `incidences/{id}`

Admite los campos de creación de forma parcial y responde **200** con `{"incidence":{...}}`. No permite cambiar de empleado, modificar una incidencia aplicada a una nómina vigente ni editar/mover registros dentro de un periodo cubierto por una nómina vigente: devuelve **409**. Si las nóminas relacionadas están canceladas y ninguna vigente cierra el periodo, permite corregir el registro. No existe eliminación.

Asistencias e Incidencias rechazan campos desconocidos (**422**), fechas invertidas en los filtros (**422**) y registros inexistentes (**404**).

## Nóminas

### GET `payrolls/preview`

```text
?route=payrolls/preview&period_start=2026-10-01&period_end=2026-10-15
```

Obtiene una vista previa para empleados activos que hayan ingresado antes o durante el periodo. Admite `employee_id` como filtro opcional. No crea una nómina. Devuelve:

```json
{
  "period_start":"2026-10-01",
  "period_end":"2026-10-15",
  "items":[{
    "employee_id":7,"employee_code":"NC-007","full_name":"Ana López",
    "monthly_salary":18000,"days_worked":2,"attendance_count":3,
    "present_days":1,"paid_leave_days":1,"absent_days":1,
    "bonus":500,"deduction":250,"base_pay":1200,"gross_pay":1700,"net_pay":1450,
    "incidence_ids":[1,2],"can_create":true,"errors":[]
  }],
  "totals":{"gross":1700,"deductions":250,"net":1450},
  "can_create":true
}
```

Ejemplo de una sola persona. `present` y `paid_leave` cuentan un día pagado; `absent` y días sin registro valen cero. Se pagan como máximo 30 días. Solo suma incidencias del periodo que no estén vinculadas a otra nómina vigente. Las aplicadas únicamente a nóminas canceladas pueden utilizarse de nuevo.

Cada fila informa `can_create` y `errors`. Si existe un solapamiento o las deducciones exceden el bruto, la fila contiene el error y no suma a `totals`. El indicador global es falso si no hay filas o existe alguna inválida. Se pueden seleccionar únicamente empleados válidos al crear. El servidor vuelve a comprobar todos los datos al guardar.

### GET `payrolls`

Devuelve `{"payrolls":[...]}` ordenado desde la última nómina creada, incluidas las canceladas. `status` admite `draft`, `paid` y `cancelled`. Cada cabecera incluye:

```json
{
  "id": 1,
  "period_start": "2026-09-01",
  "period_end": "2026-09-15",
  "status": "draft",
  "employee_count": 1,
  "total_gross": 9500,
  "total_deductions": 250,
  "total_net": 9250,
  "created_by": 1,
  "paid_at": null,
  "cancelled_at": null,
  "cancelled_by": null,
  "cancellation_reason": null,
  "created_at": "2026-09-28 12:00:00"
}
```

### POST `payrolls`

```json
{
  "period_start": "2026-09-01",
  "period_end": "2026-09-15",
  "items": [
    {"employee_id":7,"days_worked":15,"bonus":"500.00","deduction":"250.00"}
  ]
}
```

Usa el `id` real del empleado que deseas incluir. El servidor obtiene su sueldo; no se envían totales calculados por el cliente.

Para generar con los registros de Asistencias e Incidencias envía `use_records: true` y únicamente los empleados seleccionados:

```json
{
  "period_start":"2026-10-01",
  "period_end":"2026-10-15",
  "use_records":true,
  "items":[{"employee_id":7}]
}
```

En este modo el servidor obtiene los días, bonos y deducciones desde la base y omite cualquier valor manual de esos campos. `use_records` debe ser booleano; su valor por defecto es `false`. El modo manual no aplica ni vincula incidencias registradas: usa únicamente los valores capturados en cada detalle.

Reglas de negocio:

- Fechas válidas `YYYY-MM-DD`, con un periodo inclusivo de 1 a 31 días.
- Entre 1 y 1000 empleados, sin repetir identificadores; todos deben existir y estar activos.
- `days_worked` debe ser un entero entre cero y el mínimo de 30 y los días disponibles del periodo. También se respeta la fecha de ingreso del empleado.
- `bonus` y `deduction` son opcionales, con valor inicial cero. Admiten hasta dos decimales, valores no negativos y hasta 10,000,000 MXN.
- La deducción no puede superar el bruto; el neto no puede ser negativo.
- Un empleado no puede tener dos nóminas vigentes con periodos que se solapen, incluso si una sigue en borrador. El conflicto devuelve **409**. Las canceladas conservan su historial pero no impiden regenerar el periodo.
- La creación usa una transacción: se guarda la nómina completa con sus detalles o no se guarda nada.

Cálculo en el servidor:

```text
base_pay  = monthly_salary × days_worked / 30
gross_pay = base_pay + bonus
net_pay   = gross_pay − deduction
```

El sueldo base se redondea a centavos después del prorrateo, con mitades hacia arriba. Para sueldo de 18,000, 15 días, bono de 500 y deducción de 250, el neto es **9,250 MXN**.

Respuesta **201**: `{"payroll":{...},"items":[...],"incidence_ids":[...]}`. `incidence_ids` lista las incidencias aplicadas al generar desde registros; en modo manual está vacío. La cabecera sigue el ejemplo anterior y cada detalle contiene:

```json
{
  "id": 1,
  "payroll_id": 1,
  "employee_id": 7,
  "employee_code": "NC-007",
  "full_name": "Ana López",
  "department": "Administración",
  "position": "Auxiliar",
  "monthly_salary": 18000,
  "days_worked": 15,
  "base_pay": 9000,
  "bonus": 500,
  "deduction": 250,
  "gross_pay": 9500,
  "net_pay": 9250
}
```

Los detalles conservan los datos y sueldo de cada empleado al momento de crear la nómina. No se editan ni eliminan nóminas: para corregir un borrador, cancélalo y genera otro. Esta versión no realiza cálculos fiscales.

Crear una nómina bloquea la captura y edición de asistencias e incidencias de los empleados incluidos dentro de ese periodo, aunque su estado siga como `draft`. Cancelar ese borrador libera el periodo. Las incidencias aplicadas se vinculan en `payroll_incidences` dentro de la misma transacción que cabecera y detalles; esos vínculos se conservan al cancelar y no impiden aplicar la incidencia a una nueva nómina vigente.

### GET `payrolls/{id}`

Devuelve **200** con `{"payroll":{...},"items":[...],"incidence_ids":[...]}`, también para nóminas canceladas. Sus detalles e identificadores de incidencias se conservan aunque después se corrijan los registros y se genere otra nómina. Un identificador inexistente devuelve **404**.

### POST `payrolls/{id}/pay`

Envía el Bearer de administrador y, opcionalmente, `{}`. Cambia `status` de `draft` a `paid` y registra `paid_at` en UTC. La respuesta **200** devuelve la nómina y sus detalles. Repetir la petición conserva la fecha del primer pago; un identificador inexistente devuelve **404**. Intentar pagar una nómina `cancelled` devuelve **409**.

Marcar como pagada registra una acción administrativa dentro del sistema: no ejecuta un pago bancario ni genera un comprobante fiscal.

### POST `payrolls/{id}/cancel`

Requiere un Bearer de administrador y este cuerpo JSON:

```json
{"reason":"Corregir las asistencias del periodo"}
```

`reason` es obligatorio, de tipo cadena y de 3 a 500 caracteres después de recortar espacios en los extremos. No admite caracteres de control ni campos adicionales. Una entrada inválida devuelve **422**.

Solo un borrador (`draft`) cambia a `cancelled`. La operación guarda `cancelled_at` en UTC, `cancelled_by` con el identificador del administrador y `cancellation_reason` con el motivo. Devuelve **200** con `{"payroll":{...},"items":[...],"incidence_ids":[...]}`. Cabecera, importes, detalles e identificadores de incidencias permanecen disponibles en el historial.

El periodo queda libre para corregir asistencias e incidencias y generar una nueva nómina. Los vínculos de las incidencias con la cancelada permanecen; esas incidencias pueden aplicarse a otra nómina mientras no exista otra aplicación vigente.

Repetir la petición con un motivo válido es idempotente: devuelve **200**, conserva el primer motivo, autor y fecha. No modifica esos datos aunque se envíe otro motivo. Una nómina pagada devuelve **409**; un identificador inexistente, **404**. La cancelación no se revierte: crea una nueva nómina cuando termines las correcciones.

## Pagos y exportaciones

`GET payments` devuelve `{"payments":[...]}` con las cabeceras de nóminas cuyo estado es `paid`, ordenadas por fecha de pago descendente. Incluye `paid_at`, periodo, totales y los demás campos de la cabecera. La fecha de pago se conserva al repetir `POST payrolls/{id}/pay`.

`GET reports/employees` descarga el catálogo de empleados en CSV. `GET reports/payrolls/{id}` descarga el detalle histórico de la nómina; cambiar después el nombre o sueldo del empleado no altera sus importes ni su expediente histórico. El CSV incluye el estado de la nómina y, cuando está cancelada, el motivo y la fecha UTC de cancelación. También permite exportar canceladas. Funcionan los alias `employees/export` y `payrolls/{id}/export`.

Los CSV usan `Content-Type: text/csv; charset=utf-8` y `Content-Disposition: attachment`. Los campos textuales que podrían interpretarse como fórmulas en una hoja de cálculo se protegen con un apóstrofo. Las rutas de exportación requieren una sesión válida, incluido el rol de consulta `viewer`.

## Errores y formatos

| HTTP | Significado |
| --- | --- |
| `400` | JSON mal formado o el cuerpo no es un objeto JSON. |
| `401` | Credenciales incorrectas o sesión ausente, inválida, vencida o revocada. |
| `403` | El usuario autenticado no tiene permisos para la operación. |
| `404` | Ruta o registro inexistente. |
| `405` | Método HTTP incorrecto para la ruta. |
| `409` | Clave/correo duplicado, asistencia duplicada, periodo solapado/cerrado, incidencia aplicada a una nómina vigente, cancelación de una nómina pagada, pago de una cancelada o conflicto concurrente. |
| `413` | El cuerpo supera 1 MiB. |
| `415` | El endpoint exige un cuerpo JSON y falta `application/json`. |
| `422` | Falló una regla de validación. |
| `429` | Se superó el límite de intentos de acceso. |
| `500` | Error inesperado de la aplicación. |
| `503` | Configuración o base de datos no disponible. |

Los errores incluyen `message`; los rechazos de autenticación incluyen además `authenticated: false`. Ejemplo:

```json
{"message":"Los días trabajados deben estar entre 0 y los días del periodo, con máximo de 30."}
```

Fechas de periodos e ingreso: `YYYY-MM-DD`. `expires_at`: ISO 8601 UTC con `Z`. Las fechas de base de datos, como `created_at`, `paid_at` y `cancelled_at`, también están en UTC y se devuelven como `YYYY-MM-DD HH:MM:SS`. Los importes de respuesta son números en pesos mexicanos.


## Usuarios y auditoría

`POST users` recibe `name`, `email`, `password` y, opcionalmente, `role` (`admin` o `viewer`, predeterminado `viewer`) y `status` (`active` o `inactive`, predeterminado `active`). `PATCH users/{id}` recibe uno o más de esos campos; omitir `password` conserva la contraseña. La contraseña debe tener de 12 a 72 bytes UTF-8, sin NUL. Las respuestas de usuarios nunca contienen hashes ni contraseñas.

Los cambios de correo, rol, estado o contraseña revocan las sesiones de esa cuenta. Desactivarla también bloquea nuevos accesos. La API rechaza el correo duplicado y la desactivación o degradación del propio administrador con **409**; valida que permanezca un administrador activo. No se eliminan cuentas mediante la API.

`GET ?route=audit` admite `actor_id`, `action`, `entity`, `date_from`, `date_to`, `page` y `per_page` como parámetros de URL. Por ejemplo: `?route=audit&entity=user&page=1&per_page=20`. Las fechas `YYYY-MM-DD` cubren días UTC inclusivos; se admite de 1 a 100 registros por página (20 por defecto). Devuelve los eventos más recientes primero, con autor, acción, recurso, resumen y fecha UTC. La auditoría es de solo lectura: no tiene altas, ediciones ni eliminaciones públicas.

Las dos rutas exigen `admin`; un `viewer` obtiene **403**, aunque puede consultar los reportes y exportar CSV. Cada acción auditada se registra en la misma transacción que su cambio exitoso; un error revierte ambos. Hay ejemplos completos en [USUARIOS_Y_AUDITORIA.md](USUARIOS_Y_AUDITORIA.md).
