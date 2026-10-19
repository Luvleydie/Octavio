<?php
declare(strict_types=1);
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
?>
<!doctype html>
<html lang="es-MX">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#2e3141">
  <title>Nómina Clara · Tu equipo, en orden</title>
  <!-- Base visual: Solid State de HTML5 UP, adaptada para Nómina Clara. -->
  <link rel="stylesheet" href="./assets/solid-state/css/fonts.css">
  <link rel="stylesheet" href="./assets/solid-state/css/main.css">
  <link rel="stylesheet" href="./assets/app.css">
  <script src="./assets/graphql.js" defer></script>
  <script src="./assets/ui.js" defer></script>
  <script src="./assets/app.js" defer></script>
</head>
<body>
  <noscript><div class="noscript-message">Activa JavaScript en tu navegador para usar Nómina Clara. La aplicación necesita JavaScript para iniciar sesión y cargar los datos.</div></noscript>
  <div id="loading-screen" class="loading-screen" role="status"><span class="brand-mark"><span class="icon fa-gem" aria-hidden="true"></span></span><p>Preparando tu espacio…</p></div>

  <main id="login-view" class="login-layout solid-login" hidden>
    <section class="login-story solid-banner" aria-label="Bienvenido a Nómina Clara">
      <a class="brand" href="./"><span class="brand-mark"><span class="icon fa-gem" aria-hidden="true"></span></span><span>Nómina Clara<span class="brand-caption">PERSONAS PRIMERO</span></span></a>
      <div class="login-story-body"><span class="solid-section-number">01 / ADMINISTRACIÓN DE NÓMINA</span><div class="eyebrow light"><span class="status-dot"></span> PERSONAS. PERIODOS. PAGOS.</div><h1 class="major">Tu equipo,<br>en orden.</h1><p>Empleados, asistencias y nóminas.<br>Todo lo que necesitas, en un mismo lugar.</p>
        <div class="story-card"><div class="story-icon"><span class="icon fa-gem" aria-hidden="true"></span></div><div><strong>Cada detalle cuenta</strong><span>Prepara cada periodo con información clara.</span></div></div>
      </div>
      <div class="login-footnote"><span>Tu equipo. Tu espacio de trabajo.</span><span>NÓMINA CLARA</span></div>
    </section>
    <section class="login-panel">
      <div class="login-form-wrap solid-login-card"><span class="tag">ACCESO AL SISTEMA</span><h2 class="major">Inicia sesión</h2><p class="muted">Ingresa a tu espacio para continuar.</p>
        <form id="login-form" class="stack" novalidate>
          <div id="login-error" class="alert error" role="alert" hidden></div>
          <label>Correo electrónico<input id="login-email" name="email" type="email" autocomplete="username" placeholder="nombre@empresa.com" required></label>
          <label>Contraseña<span class="password-field"><input id="login-password" name="password" type="password" autocomplete="current-password" placeholder="Tu contraseña" required><button id="toggle-password" type="button" class="password-toggle" aria-label="Mostrar contraseña" aria-pressed="false">Ver</button></span></label>
          <button id="login-submit" class="button primary login-submit" type="submit">Entrar a mi espacio <span aria-hidden="true">→</span></button>
        </form>
        <div class="demo-note"><span class="demo-icon" aria-hidden="true">i</span><div><strong>¿Estás probando el proyecto?</strong><p>Después de instalar los datos de ejemplo:</p><button id="use-demo" type="button" class="text-button">admin@nomina.local</button><p>Contraseña: <code>Nomina2026!</code></p></div></div>
        <p class="login-footer">Diseño adaptado de Solid State por <a href="https://html5up.net" target="_blank" rel="noopener noreferrer">HTML5 UP</a> <span>·</span> <a href="./assets/solid-state/LICENSE.txt">CC BY 3.0</a></p>
      </div>
    </section>
  </main>

  <div id="app-view" class="app-layout solid-app" hidden>
    <button id="menu-overlay" class="menu-overlay" type="button" tabindex="-1" aria-hidden="true" hidden></button>
    <aside id="sidebar" class="sidebar">
      <button id="close-menu" class="icon-button sidebar-close" type="button" aria-label="Cerrar menú"><span aria-hidden="true">×</span></button>
      <a class="brand" href="./"><span class="brand-mark"><span class="icon fa-gem" aria-hidden="true"></span></span><span>Nómina Clara<span class="brand-caption">PERSONAS PRIMERO</span></span></a>
      <div class="workspace-label"><span class="workspace-symbol">NC</span><span>Mi organización<small>Espacio de trabajo</small></span><span class="workspace-caret" aria-hidden="true">⌄</span></div>
      <p class="nav-section-label">ADMINISTRACIÓN</p>
      <nav aria-label="Navegación principal">
        <button class="nav-item active" data-page="dashboard" type="button"><span class="nav-icon icon solid fa-chart-pie" aria-hidden="true"></span>Resumen<span class="nav-active-dot"></span></button>
        <button class="nav-item" data-page="employees" type="button"><span class="nav-icon icon solid fa-users" aria-hidden="true"></span>Mi equipo<span id="nav-team-count" class="nav-count">—</span></button>
        <button class="nav-item" data-page="departments" type="button"><span class="nav-icon icon solid fa-building" aria-hidden="true"></span>Departamentos</button>
        <button class="nav-item" data-page="attendance" type="button"><span class="nav-icon icon solid fa-calendar-check" aria-hidden="true"></span>Asistencias</button>
        <button class="nav-item" data-page="incidences" type="button"><span class="nav-icon icon solid fa-sliders-h" aria-hidden="true"></span>Bonos y deducciones</button>
        <button class="nav-item" data-page="payrolls" type="button"><span class="nav-icon icon solid fa-file-invoice-dollar" aria-hidden="true"></span>Nóminas</button>
        <button class="nav-item" data-page="users" data-write-only type="button" hidden><span class="nav-icon icon solid fa-user-shield" aria-hidden="true"></span>Usuarios</button>
        <button class="nav-item" data-page="audit" data-write-only type="button" hidden><span class="nav-icon icon solid fa-history" aria-hidden="true"></span>Auditoría</button>
        <button class="nav-item" data-page="account" type="button"><span class="nav-icon icon solid fa-user-circle" aria-hidden="true"></span>Mi cuenta</button>
      </nav>
      <div class="sidebar-bottom"><div class="sidebar-tip"><span class="tip-star" aria-hidden="true">✧</span><strong>Las cuentas claras.</strong><p>Tu información se guarda en la base de datos de este proyecto.</p><span class="local-indicator"><span class="status-dot"></span> ENTORNO LOCAL</span></div><button id="logout-button" class="logout-button" type="button"><span aria-hidden="true">↪</span> Cerrar sesión</button></div>
    </aside>
    <div class="main-shell">
      <header class="topbar"><div class="breadcrumb"><button id="mobile-menu" aria-controls="sidebar" type="button" class="icon-button mobile-only" aria-label="Abrir menú" aria-expanded="false">☰</button><span>Mi organización</span><span class="breadcrumb-separator">/</span><strong id="breadcrumb-current">Resumen</strong></div><div class="topbar-user"><span class="local-badge"><span class="status-dot"></span>Espacio local</span><span class="topbar-divider"></span><div class="user-copy"><strong id="user-name">Administrador</strong><span id="user-role">Administración</span></div><span id="user-avatar" class="avatar">AD</span></div></header>
      <main id="workspace-main" class="workspace-main">
        <div id="page-error" class="alert error page-alert" role="alert" hidden></div>
        <section id="page-dashboard" class="page" aria-labelledby="dashboard-heading">
          <div class="page-heading"><div><p class="eyebrow">UN VISTAZO A LO IMPORTANTE</p><h1 id="dashboard-heading">Todo en su lugar<span class="heading-dot">.</span></h1><p>Así va tu equipo. Así va tu nómina.</p></div><button type="button" class="button primary" data-page="new-payroll"><span aria-hidden="true">＋</span> Crear nómina</button></div>
          <div class="overview-label"><h2>Resumen general</h2><span id="today-label" class="muted"></span></div>
          <div id="dashboard-stats" class="stats-grid" aria-live="polite">
            <article class="stat-card"><div class="stat-top"><span>Empleados activos</span><span class="stat-symbol mint" aria-hidden="true">♧</span></div><strong id="stat-employees">—</strong><p>Personas que hacen la diferencia</p></article>
            <article class="stat-card"><div class="stat-top"><span>Nómina mensual estimada</span><span class="stat-symbol blue" aria-hidden="true">＄</span></div><strong id="stat-monthly">—</strong><p>Suma de sueldos mensuales activos</p></article>
            <article class="stat-card"><div class="stat-top"><span>Última nómina</span><span class="stat-symbol sand" aria-hidden="true">▤</span></div><strong id="stat-last">—</strong><p>Importe neto del último periodo</p></article>
            <article class="stat-card"><div class="stat-top"><span>Periodos registrados</span><span class="stat-symbol lavender" aria-hidden="true">▦</span></div><strong id="stat-runs">—</strong><p>El historial de tu trabajo</p></article>
          </div>
          <div class="summary-strip" aria-label="Estado de los pagos"><p><span>Pendientes</span><strong id="stat-pending">—</strong></p><p><span>Neto pagado acumulado</span><strong id="stat-paid">—</strong></p></div>
          <div class="dashboard-columns"><section class="card recent-card"><div class="card-heading"><div><h2>Nóminas recientes</h2><p>Tus últimos periodos, a un clic.</p></div><button class="text-button" type="button" data-page="payrolls">Ver todas <span aria-hidden="true">↗</span></button></div><div id="recent-payrolls"></div></section><section class="card departments-card"><div class="card-heading"><div><h2>Un equipo, muchas manos</h2><p>Personas por departamento</p></div></div><div id="departments-list" class="departments-list"></div><div class="card-bottom"><button type="button" class="text-button" data-page="employees">Conocer al equipo <span aria-hidden="true">→</span></button></div></section></div>
          <section class="card monthly-card"><div class="card-heading"><div><h2>Pagos de los últimos seis meses</h2><p>Importe neto de las nóminas pagadas, por mes de pago.</p></div><span class="quiet-badge">MXN</span></div><div id="monthly-history" class="monthly-history" aria-label="Historial mensual de pagos"></div></section>
          <section class="calm-banner"><span class="banner-symbol" aria-hidden="true">✳</span><div><h2>El siguiente periodo empieza aquí.</h2><p>Revisa tu equipo, ajusta los conceptos y prepara tu próxima nómina.</p></div><button type="button" class="button secondary" data-page="new-payroll">Preparar nómina <span aria-hidden="true">→</span></button></section>
          <p class="calculation-note">Cálculo demostrativo: sueldo mensual ÷ 30 × días trabajados, más bonos y menos deducciones manuales.</p>
        </section>

        <section id="page-employees" class="page" aria-labelledby="employees-heading" hidden>
          <div class="page-heading"><div><p class="eyebrow">EL CORAZÓN DE TU ORGANIZACIÓN</p><h1 id="employees-heading">Tu equipo<span class="heading-dot">.</span></h1><p>Personas, datos y sueldos en un mismo lugar.</p></div><button id="add-employee" type="button" class="button primary"><span aria-hidden="true">＋</span> Agregar empleado</button></div>
          <section class="card"><div class="table-toolbar employee-filters"><div class="search-field"><span aria-hidden="true">⌕</span><input id="employee-search" type="search" placeholder="Buscar nombre, código o correo" aria-label="Buscar empleados"></div><label class="filter-label"><span class="sr-only">Filtrar departamento</span><select id="employee-department"><option value="">Todos los departamentos</option></select></label><label class="filter-label"><span class="sr-only">Filtrar estado</span><select id="employee-status"><option value="all">Todos los estados</option><option value="active">Activos</option><option value="inactive">Inactivos</option></select></label><button type="button" class="button secondary small" data-action="export-employees">Exportar equipo CSV</button></div><div id="employee-list"></div><div id="employee-count" class="table-footer pagination" aria-live="polite"></div></section>
        </section>

        <section id="page-attendance" class="page" aria-labelledby="attendance-heading" hidden>
          <div class="page-heading"><div><p class="eyebrow">DÍA A DÍA</p><h1 id="attendance-heading">Asistencias<span class="heading-dot">.</span></h1><p>Registra presencia, faltas o permisos con goce de sueldo.</p></div></div>
          <section class="card record-card" data-write-only><form id="attendance-form" class="record-form">
            <input name="id" type="hidden"><div class="form-grid">
              <label>Empleado<select name="employee_id" required></select></label>
              <label>Fecha<input name="work_date" type="date" required></label>
              <label>Asistencia<select name="status"><option value="present">Presente</option><option value="absent">Falta</option><option value="paid_leave">Permiso con goce</option></select></label>
              <label>Nota (opcional)<input name="notes" maxlength="255" placeholder="Ej. Permiso autorizado"></label>
            </div><div class="record-actions"><button class="button primary" type="submit">Guardar asistencia</button><button class="button secondary" type="button" data-action="reset-record" data-kind="attendance">Limpiar</button></div>
            <p class="form-help">Presente y permiso con goce cuentan como un día pagado. Una falta o un día sin registro cuentan como cero en el cálculo por registros.</p>
          </form></section>
          <section class="card"><div class="card-heading"><h2>Registro de asistencias</h2><button class="text-button" type="button" data-page="attendance">Actualizar</button></div><div id="attendance-list"></div></section>
        </section>

        <section id="page-incidences" class="page" aria-labelledby="incidences-heading" hidden>
          <div class="page-heading"><div><p class="eyebrow">CONCEPTOS DEL PERIODO</p><h1 id="incidences-heading">Bonos y deducciones<span class="heading-dot">.</span></h1><p>Registra cada ajuste con empleado, fecha e importe.</p></div></div>
          <section class="card record-card" data-write-only><form id="incidences-form" class="record-form">
            <input name="id" type="hidden"><div class="form-grid">
              <label>Empleado<select name="employee_id" required></select></label>
              <label>Fecha<input name="occurrence_date" type="date" required></label>
              <label>Tipo<select name="type"><option value="bonus">Bono / percepción</option><option value="deduction">Deducción</option></select></label>
              <label>Concepto<input name="concept" maxlength="120" placeholder="Ej. Bono de puntualidad" required></label>
              <label>Importe (MXN)<input name="amount" type="number" min="0.01" max="10000000" step="0.01" required></label>
            </div><div class="record-actions"><button class="button primary" type="submit">Guardar concepto</button><button class="button secondary" type="button" data-action="reset-record" data-kind="incidences">Limpiar</button></div>
            <p class="form-help">Los conceptos se suman al elegir “Usar asistencias y conceptos” al crear una nómina. Una nómina guardada bloquea los registros de su periodo.</p>
          </form></section>
          <section class="card"><div class="card-heading"><h2>Conceptos registrados</h2><button class="text-button" type="button" data-page="incidences">Actualizar</button></div><div id="incidences-list"></div></section>
        </section>

        <section id="page-payrolls" class="page" aria-labelledby="payrolls-heading" hidden>
          <div class="page-heading"><div><p class="eyebrow">CADA PERIODO CUENTA</p><h1 id="payrolls-heading">Tus nóminas<span class="heading-dot">.</span></h1><p>Consulta tus periodos y mantén los pagos al día.</p></div><button type="button" class="button primary" data-page="new-payroll"><span aria-hidden="true">＋</span> Crear nómina</button></div>
          <section class="card"><div class="card-heading"><div><h2>Historial de nóminas</h2><p>Abre un periodo para revisar sus recibos.</p></div><span id="payroll-count" class="quiet-badge"></span></div><div class="table-toolbar payroll-filters"><div class="search-field"><span aria-hidden="true">⌕</span><input id="payroll-search" type="search" placeholder="Buscar periodo o número" aria-label="Buscar nóminas por periodo o número"></div><label><span>Estado</span><select id="payroll-status"><option value="all">Todos</option><option value="draft">Pendientes</option><option value="paid">Pagadas</option><option value="cancelled">Canceladas</option></select></label><label><span>Desde</span><input id="payroll-from" type="date"></label><label><span>Hasta</span><input id="payroll-to" type="date"></label><button type="button" class="text-button" data-action="reset-payroll-filters">Limpiar filtros</button></div><p class="filter-help">El rango muestra periodos que coincidan, total o parcialmente, con esas fechas.</p><div id="payroll-filter-error" class="alert error filter-error" role="alert" hidden></div><div id="payroll-list"></div><div id="payroll-pagination" class="table-footer pagination" aria-live="polite"></div></section>
        </section>

        <section id="page-new-payroll" class="page" aria-labelledby="new-payroll-heading" hidden>
          <button class="back-link" type="button" data-page="payrolls">← Volver a nóminas</button>
          <div class="page-heading"><div><p class="eyebrow">PASO A PASO, TODO CLARO</p><h1 id="new-payroll-heading">Prepara tu nómina<span class="heading-dot">.</span></h1><p>Elige el periodo y revisa los conceptos de cada persona.</p></div></div>
          <form id="payroll-form">
            <div id="payroll-form-error" class="alert error page-alert" role="alert" hidden></div>
            <div id="payroll-edit-notice" class="alert info page-alert" hidden>Al guardar, todos los conceptos se recalcularán con los sueldos y datos actuales de los empleados seleccionados. La nómina conservará su número e historial.</div>
            <section class="card period-card"><div><span class="step-number">01</span><h2>Define el periodo</h2><p>Un periodo puede abarcar hasta 31 días.</p></div><div class="period-fields"><label>Fecha de inicio<input id="period-start" name="period_start" type="date" required></label><label>Fecha de cierre<input id="period-end" name="period_end" type="date" required></label></div></section>
            <section class="card record-card"><div class="record-form"><label class="record-mode"><input id="use-records" type="checkbox"> Usar asistencias y conceptos registrados</label><p class="form-help">Se cuentan solo los días presentes o con permiso con goce, hasta 30. Los días sin registro valen cero. Revisa la selección antes de guardar.</p><button id="refresh-records" class="button secondary small" type="button">Actualizar cálculo del periodo</button></div></section>
            <div class="period-presets" role="group" aria-label="Periodos rápidos del mes de la fecha de inicio"><span>En el mes seleccionado:</span><button type="button" class="button secondary small" data-action="period-preset" data-preset="first">Primera quincena</button><button type="button" class="button secondary small" data-action="period-preset" data-preset="second">Segunda quincena</button><button type="button" class="button secondary small" data-action="period-preset" data-preset="month">Mes completo</button></div>
            <section class="card"><div class="card-heading"><div><span class="step-number">02</span><h2>Revisa a tu equipo</h2><p>Selecciona empleados y revisa días, bonos y deducciones.</p></div><span id="editor-selection-count" class="quiet-badge" aria-live="polite"></span></div><div id="payroll-editor"></div></section>
            <div class="payroll-summary"><p><strong>Antes de guardar</strong><span>Revisa días, bonos y deducciones. Los importes se calculan nuevamente en el servidor.</span></p><div><span>Neto a pagar</span><strong id="preview-total">$0.00</strong></div><button id="create-payroll-submit" class="button primary" type="submit">Guardar nómina <span aria-hidden="true">→</span></button></div>
          </form>
          <p class="calculation-note">Sueldo base = sueldo mensual ÷ 30 × días trabajados. No incluye cálculo fiscal automático.</p>
        </section>

        <section id="page-payroll-detail" class="page" aria-labelledby="payroll-detail-heading" hidden>
          <button class="back-link" type="button" data-page="payrolls">← Volver a nóminas</button>
          <div id="payroll-detail"></div>
        </section>
        <section id="page-departments" class="page" aria-labelledby="departments-heading" hidden>
          <div class="page-heading"><div><p class="eyebrow">ÁREAS DE LA ORGANIZACIÓN</p><h1 id="departments-heading">Departamentos<span class="heading-dot">.</span></h1><p>Organiza al equipo y consulta cuántas personas pertenecen a cada área.</p></div><button type="button" class="button primary" data-action="add-department" data-write-only hidden>＋ Agregar departamento</button></div>
          <section class="card"><div class="table-toolbar"><div class="search-field"><span aria-hidden="true">⌕</span><input id="department-search" type="search" placeholder="Buscar departamento" aria-label="Buscar departamentos"></div><label class="filter-label"><span class="sr-only">Filtrar estado de departamento</span><select id="department-status"><option value="all">Todos los estados</option><option value="active">Activos</option><option value="inactive">Inactivos</option></select></label><button type="button" class="text-button" data-page="departments">Actualizar</button></div><div id="department-list"></div><div id="department-pagination" class="table-footer pagination" aria-live="polite"></div></section>
          <p class="calculation-note">Renombrar un departamento actualiza a sus empleados y conserva los datos de las nóminas anteriores. Desactivarlo impide nuevas asignaciones; los empleados actuales conservan su estado.</p>
        </section>
        <section id="page-users" class="page" aria-labelledby="users-heading" hidden>
          <div class="page-heading"><div><p class="eyebrow">ACCESOS A TU ORGANIZACIÓN</p><h1 id="users-heading">Usuarios<span class="heading-dot">.</span></h1><p>Administra quién puede consultar o modificar la información.</p></div><button type="button" class="button primary" data-action="add-user">＋ Agregar usuario</button></div>
          <section class="card"><div class="table-toolbar"><div class="search-field"><span aria-hidden="true">⌕</span><input id="user-search" type="search" placeholder="Buscar nombre o correo" aria-label="Buscar usuarios"></div><label class="filter-label"><span class="sr-only">Filtrar estado de usuario</span><select id="user-status"><option value="all">Todos los estados</option><option value="active">Activos</option><option value="inactive">Inactivos</option></select></label><button type="button" class="text-button" data-page="users">Actualizar</button></div><div id="user-list"></div><div id="user-pagination" class="table-footer pagination" aria-live="polite"></div></section>
          <p class="calculation-note">Administración puede modificar datos y gestionar accesos. Solo lectura puede consultar la información operativa. Desactivar una cuenta impide el acceso y cierra sus sesiones.</p>
        </section>
        <section id="page-audit" class="page" aria-labelledby="audit-heading" hidden>
          <div class="page-heading"><div><p class="eyebrow">HISTORIAL DE ACTIVIDAD</p><h1 id="audit-heading">Auditoría<span class="heading-dot">.</span></h1><p>Consulta quién realizó cada operación y cuándo ocurrió.</p></div><button type="button" class="button secondary" data-page="audit">Actualizar</button></div>
          <section class="card"><form id="audit-filter-form" class="table-toolbar audit-filters">
            <label>Usuario<select name="actor_id" id="audit-actor"><option value="">Todos los usuarios</option></select></label>
            <label>Acción<select name="action" id="audit-action"><option value="">Todas las acciones</option></select></label>
            <label>Recurso<select name="entity"><option value="">Todos los recursos</option><option value="user">Usuarios</option><option value="department">Departamentos</option><option value="employee">Empleados</option><option value="attendance">Asistencias</option><option value="incidence">Bonos y deducciones</option><option value="payroll">Nóminas</option></select></label>
            <label>Desde (UTC)<input name="date_from" type="date"></label><label>Hasta (UTC)<input name="date_to" type="date"></label>
            <div class="audit-filter-actions"><button type="submit" class="button primary small">Aplicar filtros</button><button type="button" class="text-button" data-action="reset-audit-filters">Limpiar filtros</button></div>
          </form><div id="audit-filter-error" class="alert error filter-error" role="alert" hidden></div><div id="audit-list"></div><div id="audit-pagination" class="table-footer pagination" aria-live="polite"></div></section>
          <p class="calculation-note">El historial es de solo lectura. Las fechas y horas se muestran en UTC. Los registros anteriores a esta actualización pueden tener un resumen limitado.</p>
        </section>
        <section id="page-account" class="page" aria-labelledby="account-heading" hidden>
          <div class="page-heading"><div><p class="eyebrow">TU ACCESO, BAJO CONTROL</p><h1 id="account-heading">Mi cuenta<span class="heading-dot">.</span></h1><p id="account-email"></p></div></div>
          <div class="account-grid"><section class="card"><div class="card-heading"><div><h2>Cambiar contraseña</h2><p>Al cambiarla se cerrarán todas tus sesiones, incluida esta.</p></div></div><form id="password-form" class="stack account-form"><div id="password-error" class="alert error" role="alert" hidden></div><label>Contraseña actual<input id="current-password" type="password" autocomplete="current-password" required></label><label>Nueva contraseña<input id="new-password" type="password" autocomplete="new-password" aria-describedby="password-help" required></label><p id="password-help" class="form-help">Usa entre 12 y 72 bytes de texto, con una contraseña diferente a la actual. Los acentos y símbolos pueden ocupar más de un byte.</p><label>Confirma la nueva contraseña<input id="confirm-password" type="password" autocomplete="new-password" required></label><button id="change-password" class="button primary" type="submit">Cambiar contraseña y cerrar sesiones</button></form></section><section class="card"><div class="card-heading"><div><h2>Sesiones activas</h2><p>Consulta la vigencia de tus accesos.</p></div><button type="button" class="text-button" data-action="refresh-sessions">Actualizar</button></div><div id="sessions-error" class="alert error account-error" role="alert" hidden></div><div id="session-list"></div><div class="card-bottom"><button id="revoke-sessions" type="button" class="button secondary small">Cerrar las demás sesiones</button></div></section></div>
        </section>
        <footer class="app-footer"><span>Nómina Clara <span class="footer-dot">·</span> Tu equipo, en orden.</span><span>Solid State adaptado de <a href="https://html5up.net" target="_blank" rel="noopener noreferrer">HTML5 UP</a> <span class="footer-dot">·</span> <a href="./assets/solid-state/LICENSE.txt">CC BY 3.0</a></span></footer>
      </main>
    </div>
  </div>

  <dialog id="employee-dialog" class="modal"><form id="employee-form"><div class="modal-heading"><div><p class="eyebrow">MI EQUIPO</p><h2 id="employee-dialog-title">Agregar empleado</h2></div><button type="button" class="icon-button" data-close-dialog="employee-dialog" aria-label="Cerrar formulario">×</button></div><div id="employee-form-error" class="alert error" role="alert" hidden></div><input id="employee-id" type="hidden"><div class="form-grid"><label>Código de empleado<input name="employee_code" maxlength="24" pattern="[A-Za-z0-9_-]+" placeholder="EMP-001" required></label><label>Nombre completo<input name="full_name" maxlength="160" autocomplete="name" required></label><label>Correo electrónico (opcional)<input name="email" type="email" maxlength="190" autocomplete="email"></label><label>Departamento<input name="department" maxlength="100" placeholder="Ej. Operaciones" required></label><label>Puesto<input name="position" maxlength="100" placeholder="Ej. Analista" required></label><label>Sueldo mensual (MXN)<input name="monthly_salary" type="number" min="0.01" max="9999999.99" step="0.01" placeholder="0.00" required></label><label>Fecha de ingreso<input name="hire_date" type="date" required></label><label>Estado<select name="status"><option value="active">Activo</option><option value="inactive">Inactivo</option></select></label></div><p class="form-help">Los empleados inactivos conservan su historial y quedan fuera de las nuevas nóminas.</p><div class="modal-actions"><button type="button" class="button secondary" data-close-dialog="employee-dialog">Cancelar</button><button id="save-employee" type="submit" class="button primary">Guardar empleado</button></div></form></dialog>
  <dialog id="user-dialog" class="modal" aria-labelledby="user-dialog-title"><form id="user-form"><div class="modal-heading"><div><p class="eyebrow">ADMINISTRACIÓN DE ACCESOS</p><h2 id="user-dialog-title">Agregar usuario</h2></div><button type="button" class="icon-button" data-close-dialog="user-dialog" aria-label="Cerrar formulario de usuario">×</button></div><div id="user-form-error" class="alert error" role="alert" hidden></div><input id="user-id" type="hidden"><div class="form-grid"><label>Nombre completo<input name="name" maxlength="120" autocomplete="off" required></label><label>Correo electrónico<input name="email" type="email" maxlength="190" autocomplete="off" required></label><label>Rol<select name="role"><option value="viewer">Solo lectura</option><option value="admin">Administración</option></select></label><label>Estado<select name="status"><option value="active">Activo</option><option value="inactive">Inactivo</option></select></label><label id="user-password-field"><span id="user-password-label">Contraseña</span><input name="password" type="password" autocomplete="new-password" aria-describedby="user-password-help"></label><label id="user-confirm-field">Confirmar contraseña<input id="user-password-confirm" type="password" autocomplete="new-password"></label></div><p id="user-password-help" class="form-help">Usa entre 12 y 72 bytes de texto; los acentos y símbolos pueden ocupar más de un byte. Al editar, deja la contraseña vacía para conservarla. Cambiar correo, contraseña, rol o estado cierra las sesiones de esa cuenta.</p><p id="user-self-help" class="form-help" hidden>Tu rol y estado se conservan para evitar perder el acceso. Puedes cambiar tu contraseña desde Mi cuenta. Si cambias tu correo, tendrás que iniciar sesión nuevamente.</p><div class="modal-actions"><button type="button" class="button secondary" data-close-dialog="user-dialog">Cancelar</button><button id="save-user" type="submit" class="button primary">Guardar usuario</button></div></form></dialog>
  <dialog id="receipt-dialog" class="modal receipt-modal"><div class="receipt-controls"><button id="print-receipt" class="button secondary" type="button">Imprimir recibo</button><button type="button" class="icon-button" data-close-dialog="receipt-dialog" aria-label="Cerrar recibo">×</button></div><div id="receipt-content"></div></dialog>
  <dialog id="pay-dialog" class="modal confirm-modal"><div class="modal-heading"><div><p class="eyebrow">CONFIRMAR REGISTRO</p><h2>Marcar como pagada</h2></div><button type="button" class="icon-button" data-close-dialog="pay-dialog" aria-label="Cerrar confirmación">×</button></div><div id="pay-form-error" class="alert error" role="alert" hidden></div><p>Se registrará esta nómina como pagada. Esta acción guarda el estado del periodo; no realiza transferencias bancarias.</p><div id="pay-confirm-total" class="confirm-total"></div><div class="modal-actions"><button class="button secondary" type="button" data-close-dialog="pay-dialog">Volver</button><button id="confirm-payment" class="button primary" type="button">Confirmar pago registrado</button></div></dialog>
  <dialog id="cancel-dialog" class="modal confirm-modal"><form id="cancel-payroll-form"><div class="modal-heading"><div><p class="eyebrow">CONSERVAR EL HISTORIAL</p><h2>Cancelar nómina pendiente</h2></div><button type="button" class="icon-button" data-close-dialog="cancel-dialog" aria-label="Cerrar cancelación">×</button></div><div id="cancel-form-error" class="alert error" role="alert" hidden></div><p class="form-help">La nómina y sus recibos quedarán marcados como cancelados. Podrás crear otra nómina para el mismo periodo.</p><label class="reason-field">Motivo de cancelación<textarea id="cancel-reason" rows="4" minlength="3" maxlength="500" required placeholder="Explica por qué se cancela esta nómina"></textarea></label><div class="modal-actions"><button class="button secondary" type="button" data-close-dialog="cancel-dialog">Volver</button><button id="confirm-cancellation" class="button danger" type="submit">Confirmar cancelación</button></div></form></dialog>
  <dialog id="employee-history-dialog" class="modal history-modal" aria-labelledby="employee-history-title"><div class="modal-heading"><div><p class="eyebrow">TRAYECTORIA EN TU EQUIPO</p><h2 id="employee-history-title">Historial del empleado</h2></div><button type="button" class="icon-button" data-close-dialog="employee-history-dialog" aria-label="Cerrar historial">×</button></div><div id="employee-history-content"></div></dialog>
  <dialog id="department-dialog" class="modal" aria-labelledby="department-dialog-title">
    <form id="department-form">
      <div class="modal-heading"><div><p class="eyebrow">ÁREAS DE LA ORGANIZACIÓN</p><h2 id="department-dialog-title">Agregar departamento</h2></div><button type="button" class="icon-button" data-close-dialog="department-dialog" aria-label="Cerrar formulario de departamento">×</button></div>
      <div id="department-form-error" class="alert error" role="alert" hidden></div>
      <input id="department-id" type="hidden">
      <div class="form-grid">
        <label>Nombre del departamento<input name="name" maxlength="100" required></label>
        <label>Estado<select name="status"><option value="active">Activo</option><option value="inactive">Inactivo</option></select></label>
      </div>
      <p class="form-help">Los departamentos inactivos conservan sus empleados y no permiten nuevas asignaciones.</p>
      <div class="modal-actions"><button type="button" class="button secondary" data-close-dialog="department-dialog">Cancelar</button><button id="save-department" class="button primary" type="submit">Guardar departamento</button></div>
    </form>
  </dialog>
  <div id="toast" class="toast" role="status" aria-live="polite" hidden></div>
</body>
</html>
