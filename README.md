# Nómina Clara: PHP y GraphQL

Aplicación de nómina en PHP con **API GraphQL** y MySQL/MariaDB de XAMPP en **127.0.0.1:3306**. La base se llama **`nomina_octavio_servicios`**. La interfaz web usa GraphQL para iniciar sesión, consultar y modificar los datos; las descargas CSV usan las rutas HTTP de exportación.

El endpoint es **`POST http://127.0.0.1:8088/api/graphql.php`**. Las consultas y los tipos están definidos en [`src/graphql/schema.graphql`](src/graphql/schema.graphql) y las mutaciones en [`src/graphql/mutations.graphql`](src/graphql/mutations.graphql), con ejemplos y permisos en [`docs/GRAPHQL.md`](docs/GRAPHQL.md). El motor es `webonyx/graphql-php`, instalado con Composer y fijado en `composer.lock`. GraphQL permite reutilizar los servicios y sus transacciones sin migrar la base de datos.

Los ocho servicios principales son **Empleados, Asistencias, Incidencias, Nóminas, Reportes, Autenticación, Administración de usuarios y Auditoría**. Cálculo y Pagos son dos módulos auxiliares del flujo de nómina. Son módulos PHP de una misma aplicación: comparten la API, el servidor y la base de datos, y no son microservicios ni procesos independientes.

## Versión simplificada

El código usa funciones, decisiones `if`/`switch` y pasos explícitos para seguir las peticiones. Se conservan las validaciones, los permisos, las consultas preparadas y las transacciones.

**Administración de usuarios** permite crear y editar cuentas, roles y estado, además de restablecer contraseñas. **Auditoría** registra acciones exitosas y permite consultar su historial con filtros y paginación. Ambas pantallas y sus operaciones de API requieren un administrador. Las instalaciones anteriores se actualizan sin borrar datos.

Para estudiar el proyecto: [guía para explicarlo y responder preguntas](docs/GUIA_PARA_EXPLICAR.md) y [guía de Usuarios y Auditoría](docs/USUARIOS_Y_AUDITORIA.md).

## Plantilla del frontend

El frontend usa **Solid State de HTML5 UP**, incluida en la carpeta [`plantilla`](plantilla), como base visual. Se adaptaron el inicio de sesión, la navegación, las tarjetas, las tablas y los formularios al sistema de nómina.

- [`public/assets/solid-state`](public/assets/solid-state) contiene el CSS base, el fondo, los iconos y las fuentes locales. La copia de `main.css` ajusta la ruta del fondo y reemplaza la importación remota de Google Fonts por `fonts.css`.
- [`public/assets/app.css`](public/assets/app.css) adapta la plantilla a las pantallas y los recibos, incluidos móvil e impresión.
- [`public/index.php`](public/index.php) conserva los formularios e identificadores usados por [`app.js`](public/assets/app.js), que mantiene la navegación y la conexión GraphQL.

La carpeta `plantilla` se conserva como referencia. La aplicación carga los recursos desde `public`, por lo que funciona con `iniciar.ps1` y con Apache. No necesita cargar jQuery ni las animaciones del sitio de demostración. El crédito a HTML5 UP y la licencia CC BY 3.0 están incluidos en los recursos y visibles en la interfaz.

## Dónde están los servicios y qué hace cada uno

| Servicio | Archivo | Responsabilidad |
| --- | --- | --- |
| Empleados | [`src/services/EmployeeService.php`](src/services/EmployeeService.php) | Registrar y actualizar empleados, sueldo, puesto, departamento y estado. |
| Asistencias | [`src/services/AttendanceService.php`](src/services/AttendanceService.php) | Registrar por fecha asistencia, falta o permiso pagado. |
| Incidencias | [`src/services/IncidenceService.php`](src/services/IncidenceService.php) | Registrar bonos y deducciones con fecha, concepto e importe. |
| Nóminas | [`src/services/PayrollService.php`](src/services/PayrollService.php) | Previsualizar, crear, consultar y cancelar borradores; conservar el detalle histórico y evitar periodos superpuestos entre nóminas vigentes de un empleado. Se apoya en Cálculo y Pagos. |
| Reportes | [`src/services/ReportService.php`](src/services/ReportService.php) | Mostrar indicadores y generar archivos CSV de empleados y nóminas. |
| Autenticación | [`src/services/AuthenticationService.php`](src/services/AuthenticationService.php) | Iniciar/cerrar sesión y comprobar identidad y permisos, cambiar contraseña y administrar sesiones. |
| Administración de usuarios | [`src/services/UserService.php`](src/services/UserService.php) | Crear y editar cuentas, roles, estado y contraseñas; cerrar sesiones cuando cambia el acceso. |
| Auditoría | [`src/services/AuditService.php`](src/services/AuditService.php) | Registrar acciones dentro de su transacción y consultar el historial con filtros y paginación. |

La implementación contiene **diez archivos** en `src/services/`: los ocho principales anteriores y estos dos módulos de apoyo:

| Módulo auxiliar | Archivo | Responsabilidad |
| --- | --- | --- |
| Cálculo de nóminas | [`src/services/CalculationService.php`](src/services/CalculationService.php) | Validar periodos y calcular sueldo base, bruto, deducciones y neto. |
| Pagos de nóminas | [`src/services/PaymentService.php`](src/services/PaymentService.php) | Marcar una nómina como pagada y conservar la fecha del primer registro. |

La entrada GraphQL está en [`public/api/graphql.php`](public/api/graphql.php); sus resolutores están en [`src/graphql/server.php`](src/graphql/server.php). Comparte reglas y permisos con las rutas REST de compatibilidad mediante [`src/api.php`](src/api.php). Los servicios reciben datos explícitos y devuelven resultados para la respuesta HTTP; las exportaciones de Reportes envían directamente las cabeceras y el archivo CSV. La explicación del flujo está en [`docs/SERVICIOS.md`](docs/SERVICIOS.md).

## Cómo funciona la autenticación

`AuthenticationService.php` verifica correo y contraseña con `password_verify`. Cuando son correctos, genera un token aleatorio que el cliente envía en `Authorization: Bearer <token>` para acceder a la API. La base guarda únicamente su hash SHA-256 y su vencimiento; cada petición comprueba que la sesión esté vigente y no se haya revocado. Las contraseñas se guardan con `password_hash`.

El rol `admin` permite modificar datos y acceder a Usuarios y Auditoría. `viewer` permite consultar empleados, asistencias, incidencias, nóminas, indicadores y reportes CSV, además de gestionar su propia cuenta; no permite consultar usuarios ni auditoría. Cerrar sesión revoca el token actual, y cambiar la contraseña revoca todas las sesiones de la cuenta. Las cuentas inactivas no pueden iniciar sesión ni usar tokens existentes. La pantalla **Mi cuenta** permite ver sesiones y cerrar las demás. Hay límites de intentos de acceso. Las rutas y ejemplos están en [`docs/AUTENTICACION.md`](docs/AUTENTICACION.md).

## Iniciar en Windows con XAMPP

1. Inicia **MySQL** desde el panel de XAMPP. Debe escuchar en el puerto **3306**.
2. Abre PowerShell en la carpeta de este proyecto y ejecuta:

```powershell
composer install
& 'C:\xampp\php\php.exe' scripts/install.php --demo
& 'C:\xampp\php\php.exe' -S 127.0.0.1:8088 -t public
```

3. Deja la terminal abierta y entra a **http://127.0.0.1:8088**.

Se requiere PHP 8.2 o posterior, Composer y las extensiones `pdo_mysql` y `mbstring`. Si Composer informa que faltan ZipArchive y unzip/7z, activa `extension=zip` en el PHP usado por Composer. Las dependencias instaladas se conservan en `vendor/` y no se versionan.

| Acceso de demostración | Valor |
| --- | --- |
| Correo | `admin@nomina.local` |
| Contraseña | `Nomina2026!` |

`--demo` agrega el administrador y seis empleados ficticios. El instalador crea la base nueva y las tablas desde [`database/schema.sql`](database/schema.sql); repetirlo conserva los registros y la contraseña existente. La base anterior `nomina_octavio`, si existe, se conserva.

Si ya tenías instalada una versión anterior, actualiza su esquema antes de arrancar:

```powershell
& 'C:\xampp\php\php.exe' scripts/install.php --migrate-only
```

Actualiza el esquema sin borrar datos ni agregar cuentas o empleados: incorpora cancelaciones, el estado de las cuentas y la tabla/columnas de auditoría que falten. Conserva contraseñas, registros e historial anterior. Repetirlo reconoce los cambios ya realizados. `scripts/install.php --demo` también aplica estas migraciones y agrega los datos demo que falten.

También puedes instalar y arrancar con:

```powershell
powershell -ExecutionPolicy Bypass -File .\iniciar.ps1 -InstalarDemo
```

Después basta ejecutar `iniciar.ps1` sin `-InstalarDemo`. Para otro puerto web agrega `-Puerto 8089`; el puerto de MySQL continúa siendo 3306.

El arranque comprueba primero que el puerto esté libre y después verifica PHP, extensiones, dependencias GraphQL, configuración, conexión y tablas. Si encuentra un problema, muestra cómo corregirlo. Puedes ejecutar el diagnóstico por separado, sin modificar datos:

```powershell
& 'C:\xampp\php\php.exe' scripts/diagnostico.php
```

`--solo-dependencias` comprueba PHP y GraphQL sin conectarse a la base. El puerto se valida antes de instalar datos de demostración.

## Cómo usarlo

1. Inicia sesión y registra un empleado con sueldo mensual y fecha de ingreso.
2. Captura sus asistencias del periodo. Cada registro `present` o `paid_leave` cuenta como un día pagado; `absent` y los días sin registro cuentan como cero. Se consideran como máximo 30 días.
3. Captura las incidencias: `bonus` suma un bono; `deduction` resta una deducción.
4. En **Crear nómina**, marca **Usar asistencias y conceptos registrados** y revisa la vista previa. Se seleccionan inicialmente las personas con registros; puedes ajustar la selección. Los días sin registrar cuentan como cero. Al generar desde registros, el servidor obtiene los días e importes de los servicios anteriores. La pantalla selecciona por defecto a los empleados con registros válidos; puedes ajustar esa selección. También se conserva la captura manual de días, bonos y deducciones.
5. Crea la nómina. Se guarda una copia del nombre, puesto y sueldo de cada empleado, junto con sus importes. El periodo queda cerrado para modificar asistencias o incidencias de los empleados incluidos mientras la nómina esté en borrador o pagada.
6. Consulta el detalle. Si necesitas corregir un borrador, cancélalo con un motivo de 3 a 500 caracteres: podrás ajustar los registros y generar otra nómina, conservando la cancelada en el historial. Cuando los importes sean correctos, registra el pago y descarga el reporte CSV. Una nómina pagada no se puede cancelar y una cancelada no se puede pagar.
7. Usa **Mi cuenta** para cambiar tu contraseña y cerrar otras sesiones.
8. Como administrador, abre **Usuarios** para registrar cuentas o actualizar su rol y estado. Abre **Auditoría** para consultar quién realizó cada acción, con filtros por usuario, acción, recurso y fechas UTC. Consulta [las reglas y ejemplos](docs/USUARIOS_Y_AUDITORIA.md).

Los listados de empleados y nóminas se muestran en páginas de 10 registros. Los filtros vuelven a la primera página y las búsquedas admiten nombres con o sin acentos. El historial distingue entre no tener nóminas y no encontrar coincidencias con el filtro; un rango de fechas invertido muestra un mensaje para corregirlo.

Los botones de primera quincena, segunda quincena y mes completo usan el mes de la fecha de inicio. Si está vacía, toman el mes actual. Respetan la duración de cada mes y los años bisiestos, y vuelven a calcular la vista previa cuando se usan asistencias registradas.

El cálculo educativo es:

```text
Sueldo base = sueldo mensual × días pagados / 30
Bruto       = sueldo base + bonos
Neto        = bruto − deducciones
```

Por ejemplo: $18,000 mensuales, 15 días, $500 de bono y $250 de deducción producen **$9,250 MXN**. PHP calcula con centavos enteros y redondea el prorrateo al centavo. No se acepta un neto negativo.

El proyecto no calcula ISR, IMSS, prestaciones legales ni CFDI. El registro de pago es administrativo y no realiza transferencias bancarias.

## Base de datos

| Parámetro | Valor por defecto | Variable de entorno |
| --- | --- | --- |
| Servidor | `127.0.0.1` | `NOMINA_DB_HOST` |
| Puerto | `3306` | `NOMINA_DB_PORT` |
| Base | `nomina_octavio_servicios` | `NOMINA_DB_NAME` |
| Usuario | `root` | `NOMINA_DB_USER` |
| Contraseña | Vacía, configuración local de XAMPP | `NOMINA_DB_PASSWORD` |

La conexión se configura en [`config.php`](config.php). Si no existe, el instalador lo copia de [`config.example.php`](config.example.php). Las variables de entorno tienen prioridad.

Para consultar las tablas, inicia también Apache y abre **http://localhost/phpmyadmin**. Selecciona `nomina_octavio_servicios`. El esquema y las relaciones se explican en [`docs/BASE_DATOS.md`](docs/BASE_DATOS.md).

Para usar un administrador propio ejecuta el instalador sin `--demo`. Solicita nombre, correo y contraseña de 12 a 72 bytes UTF-8; también admite la contraseña mediante `NOMINA_ADMIN_PASSWORD`. El usuario de MySQL debe tener permisos para crear la base y sus tablas.

## Organización del proyecto

```text
public/                 Interfaz web y entrada HTTP de la API
src/graphql/            Esquema y ejecución de consultas/mutaciones GraphQL
src/api.php             Despacho y permisos compartidos por GraphQL y REST
src/services/           Ocho servicios principales y dos módulos auxiliares
src/bootstrap.php       Conexión PDO, JSON y validaciones comunes
database/schema.sql     Estructura de la base desde cero
scripts/install.php     Instalación y datos de demostración
scripts/diagnostico.php Diagnóstico de arranque sin modificar datos
docs/SERVICIOS.md       Recorrido de los servicios y sus dependencias
docs/API.md             Rutas HTTP, ejemplos JSON y reglas
docs/GRAPHQL.md         Operaciones GraphQL, permisos y ejemplos
docs/BASE_DATOS.md      Tablas, relaciones y consultas
examples/python/        Cliente de autenticación Python
examples/php/           Cliente de autenticación PHP
examples/graphql/       Solicitudes GraphQL para un cliente HTTP
tests/                  Pruebas de cálculo e integración HTTP
iniciar.ps1             Arranque local
comprobar.ps1           Comprobaciones del proyecto
```

Se requiere PHP 8.2 o superior con PDO MySQL y mbstring, y Composer para instalar GraphQL. XAMPP proporciona PHP y MariaDB. Los ejemplos PHP usan cURL; las pruebas HTTP usan Python 3.10 o superior sin paquetes adicionales. Node es opcional para comprobar la sintaxis JavaScript y las consultas que construye la interfaz.

## Comprobaciones

```powershell
& 'C:\xampp\php\php.exe' tests/payroll_test.php
python tests/test_api.py
python tests/test_graphql.py
```

Las 19 comprobaciones de cálculo funcionan sin MySQL. Las pruebas HTTP y una prueba de migración requieren MySQL encendido y usan bases temporales `nomina_test_...`, que se eliminan al finalizar. Las pruebas HTTP inician su propio servidor y verifican autenticación, permisos, asistencias, incidencias, cálculo, historial, periodos cerrados, cancelación, regeneración, pagos, reportes, usuarios y auditoría. La prueba de migración comprueba la actualización de una base anterior y la conservación de sus datos.

Las pruebas de integración GraphQL comprueban el flujo completo de nómina, variables, alias, fragmentos, selección de campos, permisos, revocación dentro de una misma petición, límites de consultas y validación antes de modificar datos. También ejecutan los documentos de consulta utilizados por la interfaz cuando Node está disponible, y comprueban que un estado nulo no pueda reactivar empleados inactivos.

Las siete pruebas de `tests/ui_test.cjs` verifican búsquedas con acentos, paginación, filtros por fecha y periodos rápidos, incluidos años bisiestos. Se ejecutan con `node --test tests/ui_test.cjs` y forman parte de `comprobar.ps1` cuando Node está instalado.

Para ejecutar todas las comprobaciones disponibles:

```powershell
powershell -ExecutionPolicy Bypass -File .\comprobar.ps1
```

Los ejemplos también permiten comprobar el inicio y cierre de sesión desde otro lenguaje:

```powershell
python examples/python/cliente_auth.py --email admin@nomina.local
& 'C:\xampp\php\php.exe' examples/php/cliente_auth.php --email admin@nomina.local
```

Consulta [`docs/AUTENTICACION.md`](docs/AUTENTICACION.md) para más detalles.

## Usar Apache de XAMPP

Puedes copiar el proyecto completo a `C:\xampp\htdocs\nomina-clara`, ejecutar allí el instalador e iniciar Apache y MySQL. Abre **http://localhost/nomina-clara/public/**. Los clientes de ejemplo admiten `--url http://localhost/nomina-clara/public/api/index.php`.

## Si algo no inicia

| Problema | Qué revisar |
| --- | --- |
| `php` no se reconoce | Usa la ruta completa `C:\xampp\php\php.exe`. |
| No conecta a MySQL | Inicia MySQL en XAMPP; revisa puerto 3306 y credenciales de `config.php`. |
| Faltan tablas o columnas | Ejecuta `scripts/install.php --migrate-only`. |
| Puerto web 8088 ocupado | Usa `iniciar.ps1 -Puerto 8089` y abre esa dirección. |
| Error 409 al guardar asistencia/incidencia | Revisa si ya existe el registro o una nómina vigente del empleado que cubra esa fecha. Para corregir un borrador, cancélalo primero. |
| Error 429 al iniciar sesión | Espera a que finalice la ventana de límite de intentos. |

El servidor integrado de PHP y las credenciales demo están pensados para desarrollo local con datos ficticios.
