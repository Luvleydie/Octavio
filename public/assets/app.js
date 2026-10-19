'use strict';

(() => {
  // Atajos para buscar un elemento o una lista de elementos del HTML.
  function $(selector, parent = document) {
    return parent.querySelector(selector);
  }

  function $$(selector, parent = document) {
    return Array.from(parent.querySelectorAll(selector));
  }
  const money = value => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', minimumFractionDigits: 2 }).format(Number(value) || 0);
  const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
  const number = value => new Intl.NumberFormat('es-MX').format(Number(value) || 0);
  const localDate = value => new Date(`${String(value).slice(0, 10)}T12:00:00`);
  const date = (value, options = {}) => value ? new Intl.DateTimeFormat('es-MX', { day: 'numeric', month: 'short', year: 'numeric', ...options }).format(localDate(value)) : '—';
  const toDateInput = value => `${value.getFullYear()}-${String(value.getMonth() + 1).padStart(2, '0')}-${String(value.getDate()).padStart(2, '0')}`;
  const period = payroll => `${date(payroll.period_start, { year: undefined })} — ${date(payroll.period_end)}`;
  const initials = name => String(name || 'NC').trim().split(/\s+/).slice(0, 2).map(part => Array.from(part)[0]).join('').toUpperCase();
  const roundMoney = value => Math.round((value + Number.EPSILON) * 100) / 100;
  const storage = {
    get() {
      try {
        return sessionStorage.getItem('nomina-clara-token') || '';
      } catch {
        return '';
      }
    },
    set(value) {
      try {
        if (value) {
          sessionStorage.setItem('nomina-clara-token', value);
        } else {
          sessionStorage.removeItem('nomina-clara-token');
        }
      } catch {
        // Si el navegador bloquea el almacenamiento, el token sigue en state.
      }
    }
  };
  // Datos que necesita la pantalla. Los registros permanentes están en MySQL.
  const state = {
    token: storage.get(),
    user: null,
    employees: [],
    attendance: [],
    incidences: [],
    users: [],
    departments: [],
    departmentEditor: 0,
    userEditor: 0,
    auditFilters: {},
    payrolls: [],
    editorEmployees: [],
    currentPayroll: null,
    page: 'dashboard',
    pagination: { employees: 1, payrolls: 1, users: 1, audit: 1, departments: 1 },
    drafts: new Map(),
    recordEligibility: new Map(),
    selected: new Set(),
    recordsReady: false,
    navigation: 0,
    previewRequest: 0,
    toastTimer: null
  };
  const pageLabels = {
    dashboard: 'Resumen',
    employees: 'Mi equipo',
    attendance: 'Asistencias',
    incidences: 'Bonos y deducciones',
    account: 'Mi cuenta',
    users: 'Usuarios',
    departments: 'Departamentos',
    audit: 'Auditoría',
    payrolls: 'Nóminas',
    'new-payroll': 'Crear nómina',
    'payroll-detail': 'Detalle de nómina'
  };
  const auditActions = {
    'department.created': 'Departamento creado', 'department.updated': 'Departamento actualizado',
    'user.created': 'Usuario creado', 'user.updated': 'Usuario actualizado',
    'employee.created': 'Empleado creado', 'employee.updated': 'Empleado actualizado',
    'attendance.created': 'Asistencia creada', 'attendance.updated': 'Asistencia actualizada',
    'incidence.created': 'Concepto creado', 'incidence.updated': 'Concepto actualizado',
    'payroll.created': 'Nómina creada', 'payroll.cancelled': 'Nómina cancelada', 'payroll.paid': 'Pago registrado',
    'auth.login': 'Inicio de sesión', 'auth.logout': 'Cierre de sesión',
    'auth.password_changed': 'Contraseña cambiada', 'auth.sessions_revoked': 'Sesiones cerradas'
  };
  const auditEntities = { user: 'Usuario', department: 'Departamento', employee: 'Empleado', attendance: 'Asistencia', incidence: 'Concepto', payroll: 'Nómina' };

  function canWrite() {
    return state.user !== null && state.user.role === 'admin';
  }

  // El menú lateral se convierte en un panel desplegable en pantallas pequeñas.
  function setMenuOpen(open, restoreFocus = false) {
    const mobile = window.matchMedia('(max-width: 720px)').matches;
    const visible = mobile && open;
    $('#sidebar').classList.toggle('is-open', visible);
    $('#sidebar').inert = mobile && !visible;
    $('#menu-overlay').hidden = !visible;
    $('#mobile-menu').setAttribute('aria-expanded', String(visible));
    document.body.classList.toggle('menu-open', visible);
    if (visible) $('#close-menu').focus();
    else if (restoreFocus) $('#mobile-menu').focus();
  }

  function showError(message, target = '#page-error') {
    const element = $(target);
    element.textContent = message || '';
    element.hidden = !message;
  }

  function notify(message, error = false) {
    clearTimeout(state.toastTimer);
    $('#toast').textContent = message;
    $('#toast').classList.toggle('is-error', error);
    $('#toast').hidden = false;
    state.toastTimer = setTimeout(() => { $('#toast').hidden = true; }, 5000);
  }

  function endSession(message = '') {
    state.token = '';
    state.user = null;
    state.navigation += 1;
    state.employees = [];
    state.payrolls = [];
    state.pagination = { employees: 1, payrolls: 1, users: 1, audit: 1, departments: 1 };
    state.departments = [];
    state.departmentEditor += 1;
    state.users = [];
    state.auditFilters = {};
    state.attendance = [];
    state.incidences = [];
    state.editorEmployees = [];
    state.currentPayroll = null;
    state.drafts.clear();
    state.recordEligibility.clear();
    state.recordsReady = false;
    state.selected.clear();
    state.previewRequest += 1;
    storage.set('');
    setMenuOpen(false);
    $$('dialog[open]').forEach(dialog => dialog.close());
    $('#app-view').hidden = true;
    $('#loading-screen').hidden = true;
    $('#login-view').hidden = false;
    $('#login-password').value = '';
    $('#password-form').reset();
    $('#user-form').reset();
    $('#department-form').reset();
    $('#audit-filter-form').reset();
    showError(message, '#login-error');
    document.title = 'Nómina Clara · Inicia sesión';
  }

  async function api(route, options = {}) {
    // 1. Preparar la operación y enviarla al único endpoint de GraphQL.
    const request = NominaGraphQL.build(route, options);
    let response;
    try {
      response = await fetch('./api/graphql.php', {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(state.token ? { Authorization: `Bearer ${state.token}` } : {}) },
        body: JSON.stringify({ query: request.query, variables: request.variables, operationName: request.operationName }),
        cache: 'no-store',
        credentials: 'omit'
      });
    } catch {
      throw new Error('No pudimos conectar con el servidor. Revisa que Apache o el servidor PHP esté iniciado.');
    }
    // 2. Leer el JSON y comprobar errores HTTP y errores propios de GraphQL.
    let data;
    try { data = await response.json(); }
    catch { throw new Error('El servidor no devolvió una respuesta válida. Revisa la instalación del proyecto en el README.'); }
    if (!response.ok || data.errors?.length) {
      const status = data.errors?.[0]?.extensions?.status || response.status;
      if (status === 401 && route !== 'auth/login') endSession('Tu sesión terminó. Inicia sesión nuevamente para continuar.');
      const error = new Error(status === 503 ? 'No pudimos conectar. Revisa que MySQL esté iniciado y sigue la instalación del README.' : data.errors?.[0]?.message || data.message || 'No se pudo completar la operación. Inténtalo de nuevo.');
      error.status = status;
      throw error;
    }
    // 3. Entregar los datos a la función que dibuja la pantalla.
    return NominaGraphQL.unwrap(data.data, request);
  }

  async function withButton(button, label, task) {
    if (button.disabled) return;
    const previous = button.innerHTML;
    button.disabled = true;
    button.textContent = label;
    try { await task(); }
    finally { button.disabled = false; button.innerHTML = previous; }
  }

  function startSession(user) {
    state.user = user;
    $('#user-name').textContent = user.name;
    $('#user-role').textContent = canWrite() ? 'Administración' : 'Solo lectura';
    $('#user-avatar').textContent = initials(user.name);
    $('#login-view').hidden = true;
    $('#loading-screen').hidden = true;
    $('#app-view').hidden = false;
    $('#today-label').textContent = new Intl.DateTimeFormat('es-MX', { day: 'numeric', month: 'long', year: 'numeric' }).format(new Date());
    $$('[data-page="new-payroll"], #add-employee, [data-write-only]').forEach(button => { button.hidden = !canWrite(); });
    navigate('dashboard');
  }

  function badge(status) {
    const labels = { active: 'Activo', inactive: 'Inactivo', draft: 'Pendiente', paid: 'Pagada', cancelled: 'Cancelada' };
    const safeStatus = Object.hasOwn(labels, status) ? status : 'inactive';
    return `<span class="badge ${safeStatus}"><span class="badge-dot" aria-hidden="true"></span>${escape(labels[safeStatus])}</span>`;
  }

  function empty(title, description, action = '') {
    return `<div class="empty-state"><div class="empty-state-symbol" aria-hidden="true">✧</div><h3>${escape(title)}</h3><p>${escape(description)}</p>${action}</div>`;
  }

  function loading(container) { $(container).innerHTML = '<div class="loading-state" role="status">Cargando información…</div>'; }

  async function navigate(page, id) {
    if (!state.user || !Object.hasOwn(pageLabels, page)) return;
    if (['new-payroll', 'users', 'audit'].includes(page) && !canWrite()) return;
    // Una respuesta anterior no debe dibujarse si ya cambiamos de página.
    const navigation = ++state.navigation;
    state.page = page;
    showError('');
    $$('.page').forEach(section => { section.hidden = section.id !== `page-${page}`; });
    $$('.nav-item').forEach(button => {
      const active = button.dataset.page === page || (['new-payroll', 'payroll-detail'].includes(page) && button.dataset.page === 'payrolls');
      button.classList.toggle('active', active);
      active ? button.setAttribute('aria-current', 'page') : button.removeAttribute('aria-current');
    });
    $('#breadcrumb-current').textContent = pageLabels[page];
    document.title = `${pageLabels[page]} · Nómina Clara`;
    setMenuOpen(false, $('#sidebar').classList.contains('is-open'));
    window.scrollTo({ top: 0, behavior: 'instant' });
    let target;
    try {
      if (page === 'dashboard') {
        target = '#recent-payrolls';
        loading(target);
        loading('#departments-list');
        const data = await api('dashboard');
        if (navigation === state.navigation) renderDashboard(data);
      } else if (page === 'employees') {
        target = '#employee-list';
        loading(target);
        const [data, catalog] = await Promise.all([api('employees'), api('departments')]);
        if (navigation === state.navigation) { state.employees = data.employees; state.departments = catalog.departments; populateDepartments(); renderEmployees(); updateTeamCount(); }
      } else if (page === 'payrolls') {
        target = '#payroll-list';
        loading(target);
        const data = await api('payrolls');
        if (navigation === state.navigation) {
          state.payrolls = data.payrolls; renderPayrollList();
        }
      } else if (page === 'new-payroll') {
        target = '#payroll-editor';
        loading(target);
        $('#create-payroll-submit').disabled = true;
        const data = await api('employees');
        if (navigation === state.navigation) {
          state.employees = data.employees;
          state.editorEmployees = data.employees.filter(employee => employee.status === 'active');
          state.drafts.clear();
          state.recordEligibility.clear();
          state.selected = new Set(state.editorEmployees.map(employee => employee.id));
          state.recordsReady = false;
          $('#use-records').checked = false;
          const today = new Date();
          const firstDay = today.getDate() > 15 ? 16 : 1;
          $('#period-start').value = toDateInput(new Date(today.getFullYear(), today.getMonth(), firstDay));
          $('#period-end').value = toDateInput(new Date(today.getFullYear(), today.getMonth() + (firstDay === 16 ? 1 : 0), firstDay === 16 ? 0 : 15));
          await refreshPayrollPeriod();
          updateTeamCount();
        }
      } else if (page === 'attendance' || page === 'incidences') {
        target = `#${page}-list`;
        loading(target);
        const [people, records] = await Promise.all([api('employees'), api(page)]);
        if (navigation === state.navigation) {
          state.employees = people.employees;
          state[page] = records[page];
          resetRecord(page);
          renderRecords(page);
        }
      } else if (page === 'departments') {
        target = '#department-list';
        loading(target);
        $('#department-pagination').textContent = '';
        const data = await api('departments');
        if (navigation === state.navigation) { state.departments = data.departments; renderDepartmentCatalog(); }
      } else if (page === 'users') {
        target = '#user-list';
        loading(target);
        $('#user-pagination').textContent = '';
        const data = await api('users');
        if (navigation === state.navigation) { state.users = data.users; renderUsers(); }
      } else if (page === 'audit') {
        target = '#audit-list';
        loading(target);
        $('#audit-pagination').textContent = '';
        const [people, data] = await Promise.all([api('users'), api('audit', { query: { ...state.auditFilters, page: state.pagination.audit, per_page: 20 } })]);
        if (navigation === state.navigation) {
          state.users = people.users;
          $('#audit-actor').innerHTML = '<option value="">Todos los usuarios</option>' + people.users.map(user => `<option value="${escape(user.id)}">${escape(user.name)} (${escape(user.email)})</option>`).join('');
          $('#audit-actor').value = state.auditFilters.actor_id || '';
          renderAudit(data);
        }
      } else if (page === 'account') {
        $('#account-email').textContent = state.user.email;
        target = '#session-list';
        loading(target);
        const data = await api('auth/sessions');
        if (navigation === state.navigation) renderSessions(data);
      } else if (page === 'payroll-detail') {
        target = '#payroll-detail';
        loading(target);
        const data = await api(`payrolls/${id}`);
        if (navigation === state.navigation) { state.currentPayroll = data; renderPayrollDetail(); }
      }
    } catch (error) {
      if (navigation !== state.navigation || error.status === 401) return;
      showError(error.message);
      if (target) $(target).innerHTML = empty('La información no está disponible', 'Puedes volver a intentar cargar esta sección.', `<button class="button secondary small" type="button" data-action="retry-page"${id ? ` data-id="${escape(id)}"` : ''}>Reintentar</button>`);
      if (page === 'dashboard') $('#departments-list').textContent = '';
    }
  }

  function renderDashboard(data) {
    $('#stat-employees').textContent = number(data.stats.active_employees);
    $('#stat-monthly').textContent = money(data.stats.monthly_payroll);
    $('#stat-last').textContent = money(data.stats.last_run_total);
    $('#stat-runs').textContent = number(data.stats.total_runs);
    $('#stat-pending').textContent = number(data.stats.pending_runs);
    $('#stat-paid').textContent = money(data.stats.paid_total);
    $('#monthly-history').innerHTML = (data.monthly_payments || []).map(item => `<div class="department-row"><div class="department-label"><span>${escape(item.month)}</span><strong>${money(item.total)}</strong></div></div>`).join('') || '<p class="form-help">Todavía no hay pagos registrados.</p>';
    $('#nav-team-count').textContent = number(data.stats.active_employees);
    $('#recent-payrolls').innerHTML = payrollTable(data.recent_runs, true);
    const total = Number(data.stats.active_employees) || 1;
    $('#departments-list').innerHTML = data.departments.length ? data.departments.map(department => `<div class="department-row"><div class="department-label"><span>${escape(department.department)}</span><strong>${number(department.total)} ${Number(department.total) === 1 ? 'persona' : 'personas'}</strong></div><progress class="department-bar" value="${escape(Number(department.total))}" max="${escape(total)}" aria-label="${escape(department.department)}">${number(department.total)}</progress></div>`).join('') : empty('Tu equipo está por llegar', 'Agrega empleados para ver tus departamentos.');
  }

  function payrollTable(payrolls, compact = false) {
    if (!payrolls.length) return empty('Una nueva historia comienza aquí', 'Crea tu primera nómina para consultar los periodos y sus recibos.', canWrite() ? '<button type="button" class="button secondary small" data-page="new-payroll">Crear primera nómina</button>' : '');
    return `<div class="table-wrap"><table><thead><tr><th scope="col">Periodo</th>${compact ? '' : '<th scope="col">Personas</th><th scope="col" class="number">Percepciones</th><th scope="col" class="number">Deducciones</th>'}<th scope="col" class="number">Neto a pagar</th><th scope="col">Estado</th><th scope="col"><span class="sr-only">Acciones</span></th></tr></thead><tbody>${payrolls.map(payroll => `<tr><td><div class="period-cell"><span class="period-icon" aria-hidden="true">▦</span><div><span class="table-primary">${escape(period(payroll))}</span><span class="table-secondary">Nómina #${String(payroll.id).padStart(3, '0')}</span></div></div></td>${compact ? '' : `<td>${number(payroll.employee_count ?? 0)} personas</td><td class="number">${money(payroll.total_gross)}</td><td class="number">${money(payroll.total_deductions)}</td>`}<td class="number"><span class="table-primary">${money(payroll.total_net)}</span></td><td>${badge(payroll.status)}</td><td class="number"><button class="row-arrow" type="button" data-action="open-payroll" data-id="${escape(payroll.id)}" aria-label="Abrir nómina ${escape(payroll.id)}">↗</button></td></tr>`).join('')}</tbody></table></div>`;
  }

  function updateTeamCount() { $('#nav-team-count').textContent = state.employees.filter(employee => employee.status === 'active').length; }

  function renderPagination(list, page, target, summary) {
    state.pagination[list] = page.page;
    const label = { employees: 'empleados', payrolls: 'nóminas', users: 'usuarios', audit: 'auditoría', departments: 'departamentos' }[list];
    $(target).innerHTML = `<span role="status">${escape(summary)}${page.total ? ` · Mostrando ${page.start}–${page.end}` : ''}</span>${page.pages > 1 ? `<nav class="pagination-controls" aria-label="Páginas de ${label}"><button class="button secondary small" type="button" data-action="paginate" data-list="${list}" data-target-page="${page.page - 1}" aria-label="Página anterior" ${page.page === 1 ? 'disabled' : ''}>Anterior</button><span>Página ${page.page} de ${page.pages}</span><button class="button secondary small" type="button" data-action="paginate" data-list="${list}" data-target-page="${page.page + 1}" aria-label="Página siguiente" ${page.page === page.pages ? 'disabled' : ''}>Siguiente</button></nav>` : ''}`;
  }

  function renderEmployees() {
    const query = NominaUI.searchable($('#employee-search').value);
    const status = $('#employee-status').value;
    const department = $('#employee-department').value;
    const filtered = state.employees.filter(employee => {
      const matchesStatus = status === 'all' || employee.status === status;
      const matchesDepartment = !department || employee.department === department;
      const text = `${employee.employee_code} ${employee.full_name} ${employee.email || ''} ${employee.department} ${employee.position}`;
      const matchesSearch = NominaUI.searchable(text).includes(query);
      return matchesStatus && matchesDepartment && matchesSearch;
    });
    const page = NominaUI.paginate(filtered, state.pagination.employees);
    const visible = page.items;
    renderPagination('employees', page, '#employee-count', `${filtered.length} de ${state.employees.length} ${state.employees.length === 1 ? 'empleado' : 'empleados'}`);
    if (!visible.length) {
      $('#employee-list').innerHTML = state.employees.length ? empty('No encontramos coincidencias', 'Prueba otro nombre, código o estado.') : empty('Las personas van primero', 'Agrega a la primera persona de tu equipo para preparar su nómina.', canWrite() ? '<button type="button" class="button secondary small" data-action="add-employee">Agregar empleado</button>' : '');
      return;
    }
    $('#employee-list').innerHTML = `<div class="table-wrap"><table><thead><tr><th scope="col">Empleado</th><th scope="col">Departamento / puesto</th><th scope="col" class="number">Sueldo mensual</th><th scope="col">Ingreso</th><th scope="col">Estado</th>${canWrite() ? '<th scope="col"><span class="sr-only">Acciones</span></th>' : ''}</tr></thead><tbody>${visible.map((employee, index) => `<tr><td><div class="person-cell"><span class="avatar tone-${index % 4}" aria-hidden="true">${escape(initials(employee.full_name))}</span><div><strong class="table-primary">${escape(employee.full_name)}</strong><span class="table-secondary">${escape(employee.employee_code)}${employee.email ? ` · ${escape(employee.email)}` : ''}</span></div></div></td><td><span class="table-primary">${escape(employee.department)}</span><span class="table-secondary">${escape(employee.position)}</span></td><td class="number">${money(employee.monthly_salary)}</td><td>${escape(date(employee.hire_date))}</td><td>${badge(employee.status)}</td>${canWrite() ? `<td><div class="table-actions"><button type="button" class="text-button" data-action="edit-employee" data-id="${escape(employee.id)}" aria-label="Editar a ${escape(employee.full_name)}">Editar</button><button type="button" class="text-button" data-action="toggle-employee" data-id="${escape(employee.id)}" aria-label="${employee.status === 'active' ? 'Desactivar' : 'Reactivar'} a ${escape(employee.full_name)}">${employee.status === 'active' ? 'Desactivar' : 'Reactivar'}</button></div></td>` : ''}</tr>`).join('')}</tbody></table></div>`;
  }

  function openEmployee(id) {
    if (!canWrite()) return;
    const employee = state.employees.find(item => Number(item.id) === Number(id));
    const form = $('#employee-form');
    form.reset();
    const options = state.departments.filter(department => department.status === 'active' || department.name === employee?.department);
    form.elements.department.innerHTML = '<option value="">Selecciona un departamento</option>' + options.map(department => `<option value="${escape(department.name)}">${escape(department.name)}${department.status === 'inactive' ? ' (inactivo, asignación actual)' : ''}</option>`).join('');
    showError('', '#employee-form-error');
    $('#employee-id').value = employee?.id || '';
    $('#employee-dialog-title').textContent = employee ? 'Editar empleado' : 'Agregar empleado';
    if (employee) {
      for (const key of Object.keys(employee)) {
        const input = form.elements.namedItem(key);
        if (input) input.value = employee[key] ?? '';
      }
    }
    else form.elements.hire_date.value = toDateInput(new Date());
    $('#employee-dialog').showModal();
    form.elements.employee_code.focus();
  }

  function renderDepartmentCatalog() {
    const search = NominaUI.searchable($('#department-search').value);
    const status = $('#department-status').value;
    const filtered = state.departments.filter(department => (status === 'all' || department.status === status) && NominaUI.searchable(department.name).includes(search));
    const page = NominaUI.paginate(filtered, state.pagination.departments);
    renderPagination('departments', page, '#department-pagination', `${filtered.length} de ${state.departments.length} departamentos`);
    $('#department-list').innerHTML = page.items.length ? `<div class="table-wrap"><table><thead><tr><th scope="col">Departamento</th><th scope="col">Estado</th><th scope="col" class="number">Empleados</th><th scope="col" class="number">Empleados activos</th>${canWrite() ? '<th scope="col">Acciones</th>' : ''}</tr></thead><tbody>${page.items.map(department => `<tr><td><strong class="table-primary">${escape(department.name)}</strong></td><td>${badge(department.status)}</td><td class="number">${number(department.employee_count)}</td><td class="number">${number(department.active_employee_count)}</td>${canWrite() ? `<td><button type="button" class="text-button" data-action="edit-department" data-id="${escape(department.id)}" aria-label="Editar departamento ${escape(department.name)}">Editar</button></td>` : ''}</tr>`).join('')}</tbody></table></div>` : empty('No encontramos departamentos', 'Cambia los filtros o agrega un departamento para organizar al equipo.');
  }

  function openDepartment(id) {
    if (!canWrite()) return;
    const department = state.departments.find(item => item.id === Number(id));
    if (id && !department) return;
    state.departmentEditor += 1;
    const form = $('#department-form');
    form.reset();
    showError('', '#department-form-error');
    $('#department-id').value = department?.id || '';
    $('#department-dialog-title').textContent = department ? 'Editar departamento' : 'Agregar departamento';
    if (department) {
      form.elements.namedItem('name').value = department.name;
      form.elements.status.value = department.status;
    }
    $('#department-dialog').showModal();
    form.elements.namedItem('name').focus();
  }

  function renderUsers() {
    const search = NominaUI.searchable($('#user-search').value);
    const status = $('#user-status').value;
    const filtered = state.users.filter(user => (status === 'all' || user.status === status) && NominaUI.searchable(`${user.name} ${user.email}`).includes(search));
    const page = NominaUI.paginate(filtered, state.pagination.users);
    renderPagination('users', page, '#user-pagination', `${filtered.length} de ${state.users.length} usuarios`);
    $('#user-list').innerHTML = page.items.length ? `<div class="table-wrap"><table><thead><tr><th scope="col">Usuario</th><th scope="col">Rol</th><th scope="col">Estado</th><th scope="col">Creación</th><th scope="col"><span class="sr-only">Acciones</span></th></tr></thead><tbody>${page.items.map(user => `<tr><td><strong class="table-primary">${escape(user.name)}${user.id === state.user.id ? ' · Tú' : ''}</strong><span class="table-secondary">${escape(user.email)}</span></td><td>${user.role === 'admin' ? 'Administración' : 'Solo lectura'}</td><td>${badge(user.status)}</td><td>${escape(date(user.created_at))}</td><td><button type="button" class="text-button" data-action="edit-user" data-id="${escape(user.id)}" aria-label="Editar usuario ${escape(user.name)}">Editar</button></td></tr>`).join('')}</tbody></table></div>` : empty('No encontramos usuarios', 'Prueba otro nombre, correo o estado.');
  }

  function openUser(id) {
    if (!canWrite()) return;
    const user = state.users.find(item => item.id === Number(id));
    if (id && !user) return;
    state.userEditor += 1;
    const form = $('#user-form');
    form.reset();
    showError('', '#user-form-error');
    $('#user-id').value = user?.id || '';
    $('#user-dialog-title').textContent = user ? 'Editar usuario' : 'Agregar usuario';
    const self = user?.id === state.user.id;
    for (const name of ['name', 'email', 'role', 'status']) {
      if (user) form.elements.namedItem(name).value = user[name];
    }
    form.elements.role.disabled = self;
    form.elements.status.disabled = self;
    form.elements.password.disabled = self;
    form.elements.password.required = !user;
    $('#user-password-confirm').disabled = self;
    $('#user-password-confirm').required = !user;
    $('#user-password-label').textContent = user ? 'Nueva contraseña (opcional)' : 'Contraseña';
    $('#user-password-field').hidden = self;
    $('#user-confirm-field').hidden = self;
    $('#user-password-help').hidden = self;
    $('#user-self-help').hidden = !self;
    $('#user-dialog').showModal();
    form.elements.namedItem('name').focus();
  }

  function renderAudit(data) {
    const offset = (data.page - 1) * data.per_page;
    const page = { page: data.page, pages: Math.max(1, Math.ceil(data.total / data.per_page)), total: data.total, start: data.total ? offset + 1 : 0, end: offset + data.items.length };
    renderPagination('audit', page, '#audit-pagination', `${number(data.total)} registros`);
    $('#audit-list').innerHTML = data.items.length ? `<div class="table-wrap"><table class="audit-table"><thead><tr><th scope="col">Fecha y hora (UTC)</th><th scope="col">Usuario</th><th scope="col">Acción</th><th scope="col">Recurso</th><th scope="col">Resumen</th></tr></thead><tbody>${data.items.map(entry => `<tr><td><span class="table-primary">${escape(date(entry.created_at))}</span><span class="table-secondary">${escape(String(entry.created_at).slice(11, 19))} UTC</span></td><td>${escape(entry.actor_name || 'Sistema')}${entry.actor_id ? `<span class="table-secondary">Usuario #${escape(entry.actor_id)}</span>` : ''}</td><td>${escape(auditActions[entry.action] || entry.action)}</td><td>${escape(auditEntities[entry.entity] || entry.entity)}${entry.entity_id ? ` #${escape(entry.entity_id)}` : ''}</td><td class="audit-summary">${escape(entry.summary)}</td></tr>`).join('')}</tbody></table></div>` : empty('No hay actividad para mostrar', 'Prueba otras fechas o elimina los filtros para consultar el historial.');
  }

  function dayCount(start, end) {
    return Math.round((Date.parse(`${end}T00:00:00Z`) - Date.parse(`${start}T00:00:00Z`)) / 86400000) + 1;
  }

  function eligibleDays(employee) {
    const start = $('#period-start').value;
    const end = $('#period-end').value;
    if (!start || !end || end < start || employee.hire_date > end) return 0;
    return Math.max(0, Math.min(30, dayCount(start > employee.hire_date ? start : employee.hire_date, end)));
  }

  function renderPayrollEditor(resetDays = false) {
    const start = $('#period-start').value;
    const end = $('#period-end').value;
    const days = dayCount(start, end);
    const validPeriod = Boolean(start && end && days > 0 && days <= 31);
    const periodError = !start || !end ? '' : days <= 0 ? 'La fecha de cierre debe ser igual o posterior al inicio.' : days > 31 ? 'El periodo no puede exceder 31 días.' : '';
    $('#period-end').setCustomValidity(periodError);
    showError(periodError, '#payroll-form-error');
    const eligible = state.editorEmployees.filter(employee => eligibleDays(employee) > 0);
    if (!state.editorEmployees.length) {
      $('#payroll-editor').innerHTML = empty('Primero, reúne a tu equipo', 'Necesitas al menos un empleado activo para crear una nómina.', '<button type="button" class="button secondary small" data-page="employees">Ir a mi equipo</button>');
    } else if (!eligible.length) {
      $('#payroll-editor').innerHTML = empty('No hay empleados para este periodo', 'Revisa las fechas del periodo y las fechas de ingreso del equipo.');
    } else {
      for (const employee of eligible) {
        const previous = state.drafts.get(employee.id);
        state.drafts.set(employee.id, { days_worked: resetDays ? eligibleDays(employee) : (previous?.days_worked ?? ($('#use-records').checked ? 0 : eligibleDays(employee))), bonus: previous?.bonus ?? 0, deduction: previous?.deduction ?? 0 });
      }
      $('#payroll-editor').innerHTML = `<div class="table-wrap"><table class="payroll-editor-table"><thead><tr><th scope="col">Incluir / empleado</th><th scope="col" class="number">Días</th><th scope="col" class="number">Sueldo base</th><th scope="col" class="number">Bonos (MXN)</th><th scope="col" class="number">Deducciones (MXN)</th><th scope="col" class="number">Neto</th></tr></thead><tbody>${eligible.map(employee => {
        const draft = state.drafts.get(employee.id);
        const record = state.recordEligibility.get(employee.id);
        const blocked = $('#use-records').checked && (!state.recordsReady || record?.can_create !== true);
        if (blocked) state.selected.delete(employee.id);
        const issue = $('#use-records').checked && state.recordsReady && blocked ? (record?.errors.join(' ') || 'Actualiza la vista previa para incluir este empleado.') : '';
        return `<tr data-employee-row="${escape(employee.id)}"><td><label class="employee-choice"><input type="checkbox" data-select-employee="${employee.id}" ${state.selected.has(employee.id) ? 'checked' : ''} ${blocked ? 'disabled' : ''}${issue ? ` aria-describedby="record-issue-${employee.id}"` : ''}>${escape(employee.full_name)}</label><span class="table-secondary">${escape(employee.employee_code)} · ${money(employee.monthly_salary)} / mes</span>${issue ? `<span id="record-issue-${employee.id}" class="table-secondary record-issue">${escape(issue)}</span>` : ''}</td><td><input class="payroll-input days-input" type="number" min="0" max="${eligibleDays(employee)}" step="1" value="${escape(draft.days_worked)}" data-payroll-field="days_worked" data-id="${escape(employee.id)}" aria-label="Días trabajados de ${escape(employee.full_name)}" required></td><td class="number" data-base-pay>—</td><td><input class="payroll-input" type="number" min="0" max="9999999.99" step="0.01" value="${escape(draft.bonus)}" data-payroll-field="bonus" data-id="${escape(employee.id)}" aria-label="Bono de ${escape(employee.full_name)}" required></td><td><input class="payroll-input" type="number" min="0" max="9999999.99" step="0.01" value="${escape(draft.deduction)}" data-payroll-field="deduction" data-id="${escape(employee.id)}" aria-label="Deducción de ${escape(employee.full_name)}" required></td><td class="number"><strong class="table-primary" data-net-pay>—</strong></td></tr>`;
      }).join('')}</tbody></table></div>${eligible.length < state.editorEmployees.length ? '<p class="table-footer">Se excluyeron empleados cuya fecha de ingreso es posterior al cierre del periodo.</p>' : ''}`;
    }
    $$('[data-payroll-field]').forEach(input => { input.disabled = $('#use-records').checked || !state.selected.has(Number(input.dataset.id)); });
    $('#create-payroll-submit').disabled = !validPeriod || !eligible.length;
    updatePreview();
  }

  function updatePreview() {
    let total = 0;
    for (const employee of state.editorEmployees) {
      const row = $(`[data-employee-row="${employee.id}"]`);
      if (!row) continue;
      const values = {};
      $$('[data-payroll-field]', row).forEach(input => { values[input.dataset.payrollField] = Number(input.value) || 0; });
      state.drafts.set(employee.id, values);
      const baseCents = Math.round(Math.round(Number(employee.monthly_salary) * 100) * values.days_worked / 30);
      const base = baseCents / 100;
      const net = roundMoney(base + values.bonus - values.deduction);
      const deduction = $('[data-payroll-field="deduction"]', row);
      deduction.setCustomValidity(net < 0 ? 'La deducción no puede superar las percepciones.' : '');
      $('[data-base-pay]', row).textContent = money(base);
      $('[data-net-pay]', row).textContent = money(net);
      if (state.selected.has(employee.id)) total += net;
    }
    $('#preview-total').textContent = money(total);
    const selected = state.editorEmployees.filter(employee => eligibleDays(employee) > 0 && state.selected.has(employee.id));
    $('#editor-selection-count').textContent = `${selected.length} seleccionados`;
    const periodDays = dayCount($('#period-start').value, $('#period-end').value);
    $('#create-payroll-submit').disabled = !selected.length || !(periodDays > 0 && periodDays <= 31) || ($('#use-records').checked && (!state.recordsReady || selected.some(employee => state.recordEligibility.get(employee.id)?.can_create !== true)));
  }

  function cancellationNotice(payroll) {
    if (payroll.status !== 'cancelled') return '';
    return `<div class="alert error cancellation-notice"><strong>Nómina cancelada · ${escape(date(payroll.cancelled_at))}</strong><p>${escape(payroll.cancellation_reason)}</p><p>Los importes se conservan como historial. Esta nómina no debe pagarse.</p></div>`;
  }

  function renderPayrollDetail() {
    const { payroll, items } = state.currentPayroll;
    $('#payroll-detail').innerHTML = `<div class="page-heading"><div><p class="eyebrow">NÓMINA #${String(payroll.id).padStart(3, '0')}</p><h1 id="payroll-detail-heading">${escape(period(payroll))}<span class="heading-dot">.</span></h1><p>${items.length} ${items.length === 1 ? 'persona' : 'personas'} · Creada el ${escape(date(payroll.created_at))}</p></div><div class="detail-actions"><button class="button secondary small" type="button" data-action="export-payroll">Exportar CSV</button>${badge(payroll.status)}${payroll.status === 'draft' && canWrite() ? '<button class="button secondary" type="button" data-action="cancel-payroll">Cancelar nómina</button><button class="button primary" type="button" data-action="pay-payroll">Marcar como pagada</button>' : ''}</div></div>${cancellationNotice(payroll)}${payroll.status === 'paid' ? '<div class="pay-notice">✓ El pago de esta nómina está registrado. Puedes consultar e imprimir los recibos de cada persona.</div>' : ''}<div class="stats-grid detail-stats"><article class="stat-card"><div class="stat-top">Total de percepciones</div><strong>${money(payroll.total_gross)}</strong></article><article class="stat-card"><div class="stat-top">Total de deducciones</div><strong>${money(payroll.total_deductions)}</strong></article><article class="stat-card"><div class="stat-top">Neto a pagar</div><strong>${money(payroll.total_net)}</strong></article></div><section class="card"><div class="card-heading"><div><h2>El detalle, persona por persona</h2><p>Los importes y los datos corresponden al momento de crear esta nómina.</p></div><span class="quiet-badge">MXN</span></div><div class="table-wrap"><table><thead><tr><th scope="col">Empleado</th><th scope="col" class="number">Días</th><th scope="col" class="number">Sueldo base</th><th scope="col" class="number">Bonos</th><th scope="col" class="number">Deducciones</th><th scope="col" class="number">Neto</th><th scope="col"><span class="sr-only">Recibo</span></th></tr></thead><tbody>${items.map((item, index) => `<tr><td><strong class="table-primary">${escape(item.full_name)}</strong><span class="table-secondary">${escape(item.employee_code)} · ${escape(item.department)}</span></td><td class="number">${number(item.days_worked)}</td><td class="number">${money(item.base_pay)}</td><td class="number">${money(item.bonus)}</td><td class="number">${money(item.deduction)}</td><td class="number"><strong class="table-primary">${money(item.net_pay)}</strong></td><td><button type="button" class="text-button" data-action="open-receipt" data-index="${index}" aria-label="Ver recibo de ${escape(item.full_name)}">Ver recibo ↗</button></td></tr>`).join('')}</tbody></table></div></section><p class="calculation-note">Cálculo demostrativo. Los importes incluyen los bonos y deducciones del periodo; este registro no representa un comprobante fiscal.</p>`;
  }

  function openReceipt(index) {
    if (!state.currentPayroll) return;
    const { payroll, items } = state.currentPayroll;
    const item = items[index];
    if (!item) return;
    $('#receipt-content').className = 'receipt-content';
    $('#receipt-content').innerHTML = `<div class="receipt-header"><div><div class="receipt-brand">Nómina Clara<span>.</span></div><p>Recibo de nómina · Ejemplo educativo</p></div><div class="receipt-number"><strong>RECIBO #${String(payroll.id).padStart(3, '0')}-${escape(item.employee_code)}</strong>${badge(payroll.status)}</div></div>${cancellationNotice(payroll)}<div class="receipt-person"><div><small>Empleado</small><strong>${escape(item.full_name)}</strong><p>${escape(item.employee_code)}</p></div><div><small>Periodo</small><strong>${escape(period(payroll))}</strong><p>${number(item.days_worked)} días trabajados</p></div><div><small>Departamento / puesto</small><strong>${escape(item.department)}</strong><p>${escape(item.position)}</p></div><div><small>Sueldo mensual de referencia</small><strong>${money(item.monthly_salary)}</strong><p>Moneda: pesos mexicanos (MXN)</p></div></div><table><thead><tr><th scope="col">Concepto</th><th scope="col" class="number">Percepción</th><th scope="col" class="number">Deducción</th></tr></thead><tbody><tr><td>Sueldo base (${number(item.days_worked)} días)</td><td class="number">${money(item.base_pay)}</td><td class="number">—</td></tr><tr><td>Bonos</td><td class="number">${money(item.bonus)}</td><td class="number">—</td></tr><tr><td>Deducciones</td><td class="number">—</td><td class="number">${money(item.deduction)}</td></tr><tr><td><strong>Totales</strong></td><td class="number"><strong>${money(item.gross_pay)}</strong></td><td class="number"><strong>${money(item.deduction)}</strong></td></tr></tbody></table><div class="receipt-total"><span>Neto a pagar</span><strong>${money(item.net_pay)}</strong></div><p class="receipt-note">Sueldo base = sueldo mensual ÷ 30 × días trabajados. Los datos corresponden al momento de generar la nómina. Este recibo es un ejemplo educativo: no es un CFDI ni acredita una transferencia bancaria.</p>`;
    $('#receipt-dialog').showModal();
  }

  function populateDepartments() {
    const selected = $('#employee-department').value;
    const departments = [...new Set(state.employees.map(employee => employee.department))].sort();
    $('#employee-department').innerHTML = '<option value="">Todos los departamentos</option>' + departments.map(value => `<option value="${escape(value)}">${escape(value)}</option>`).join('');
    if (departments.includes(selected)) $('#employee-department').value = selected;
  }

  function renderPayrollList() {
    const query = $('#payroll-search').value;
    const status = $('#payroll-status').value;
    const from = $('#payroll-from').value;
    const to = $('#payroll-to').value;
    const result = NominaUI.filterPayrolls(state.payrolls.map(row => ({ ...row, search_text: `${row.id} ${row.period_start} ${row.period_end} ${period(row)}` })), { query, status, from, to });
    showError(result.error, '#payroll-filter-error');
    $('#payroll-from').setAttribute('aria-invalid', String(Boolean(result.error)));
    $('#payroll-to').setAttribute('aria-invalid', String(Boolean(result.error)));
    const page = NominaUI.paginate(result.rows, state.pagination.payrolls);
    $('#payroll-count').textContent = `${page.total} ${page.total === 1 ? 'periodo' : 'periodos'}`;
    renderPagination('payrolls', page, '#payroll-pagination', `${page.total} de ${state.payrolls.length} periodos`);
    $('#payroll-pagination').hidden = Boolean(result.error);
    $('#payroll-list').innerHTML = result.error
      ? empty('Revisa el rango de fechas', 'Corrige el filtro para consultar los periodos.')
      : page.total || !state.payrolls.length ? payrollTable(page.items)
      : empty('No encontramos coincidencias', 'Hay nóminas registradas. Prueba otras fechas, un número o un estado diferente.', '<button type="button" class="button secondary small" data-action="reset-payroll-filters">Limpiar filtros</button>');
  }

  function resetRecord(kind) {
    const form = $(`#${kind}-form`);
    form.reset();
    form.elements.id.value = '';
    form.elements.employee_id.disabled = false;
    form.elements.employee_id.innerHTML = '<option value="">Selecciona un empleado</option>' + state.employees.filter(employee => employee.status === 'active').map(employee => `<option value="${employee.id}">${escape(employee.employee_code)} · ${escape(employee.full_name)}</option>`).join('');
    form.elements[kind === 'attendance' ? 'work_date' : 'occurrence_date'].value = toDateInput(new Date());
  }

  function editRecord(kind, id) {
    if (!canWrite()) return;
    const row = state[kind].find(item => item.id === id);
    if (!row) return;
    resetRecord(kind);
    const form = $(`#${kind}-form`);
    if (![...form.elements.employee_id.options].some(option => Number(option.value) === row.employee_id)) {
      form.elements.employee_id.add(new Option(row.full_name, row.employee_id));
    }
    for (const [key, value] of Object.entries(row)) if (form.elements.namedItem(key)) form.elements.namedItem(key).value = value ?? '';
    form.elements.employee_id.disabled = true;
    form.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  function renderRecords(kind) {
    const rows = state[kind];
    const attendance = kind === 'attendance';
    const labels = { present: 'Presente', absent: 'Falta', paid_leave: 'Permiso con goce', bonus: 'Bono', deduction: 'Deducción' };
    $(`#${kind}-list`).innerHTML = rows.length ? `<div class="table-wrap"><table><thead><tr><th>Empleado</th><th>Fecha</th><th>${attendance ? 'Asistencia' : 'Tipo'}</th><th>${attendance ? 'Nota' : 'Concepto'}</th>${attendance ? '' : '<th class="number">Importe</th>'}${canWrite() ? '<th>Acción</th>' : ''}</tr></thead><tbody>${rows.map(row => `<tr><td><strong class="table-primary">${escape(row.full_name)}</strong><span class="table-secondary">${escape(row.employee_code)}</span></td><td>${escape(date(row.work_date || row.occurrence_date))}</td><td>${escape(labels[row.status || row.type])}</td><td>${escape(attendance ? row.notes || '—' : row.concept)}</td>${attendance ? '' : `<td class="number">${money(row.amount)}</td>`}${canWrite() ? `<td><button class="text-button" type="button" data-action="edit-record" data-kind="${kind}" data-id="${row.id}">Editar</button></td>` : ''}</tr>`).join('')}</tbody></table></div>` : empty('Todavía no hay registros', attendance ? 'Captura la asistencia de tu equipo en el formulario.' : 'Agrega bonos o deducciones para el siguiente periodo.');
  }

  async function refreshPayrollPeriod() {
    const request = ++state.previewRequest;
    const navigation = state.navigation;
    state.recordsReady = false;
    state.recordEligibility.clear();
    showError('');
    showError('', '#payroll-form-error');
    for (const button of $$('[data-action="period-preset"]')) {
      let active = false;
      try {
        const preset = NominaUI.periodPreset($('#period-start').value, button.dataset.preset);
        active = preset.start === $('#period-start').value && preset.end === $('#period-end').value;
      } catch { /* An incomplete date has no selected preset. */ }
      button.setAttribute('aria-pressed', String(active));
    }
    if (!$('#use-records').checked) { renderPayrollEditor(true); return; }
    state.drafts.clear();
    state.selected.clear();
    renderPayrollEditor(false);
    $('#create-payroll-submit').disabled = true;
    const start = $('#period-start').value;
    const end = $('#period-end').value;
    if (!start || !end || !$('#period-end').checkValidity()) return;
    try {
      const data = await api('payrolls/preview', { query: { period_start: start, period_end: end } });
      if (request !== state.previewRequest || navigation !== state.navigation || state.page !== 'new-payroll' || !$('#use-records').checked) return;
      const issues = [];
      for (const item of data.items) {
        state.recordEligibility.set(item.employee_id, { can_create: item.can_create, errors: item.errors });
        state.drafts.set(item.employee_id, { days_worked: item.days_worked, bonus: item.bonus, deduction: item.deduction });
        if (item.can_create && (item.attendance_count > 0 || item.bonus > 0 || item.deduction > 0)) state.selected.add(item.employee_id);
        if (!item.can_create) issues.push(`${item.full_name}: ${item.errors.join(' ')}`);
      }
      state.recordsReady = true;
      renderPayrollEditor(false);
      showError(issues.join(' '));
    } catch (error) {
      if (request === state.previewRequest && navigation === state.navigation && state.page === 'new-payroll' && error.status !== 401) showError(error.message);
    }
  }

  async function downloadReport(route, filename) {
    const response = await fetch(`./api/index.php?route=${encodeURIComponent(route)}`, { headers: { Authorization: `Bearer ${state.token}` }, cache: 'no-store', credentials: 'omit' });
    if (!response.ok) {
      if (response.status === 401) endSession('Tu sesión terminó. Inicia sesión nuevamente.');
      throw new Error('No se pudo descargar el reporte.');
    }
    const url = URL.createObjectURL(await response.blob());
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  function renderSessions(data) {
    $('#session-list').innerHTML = `<div class="table-wrap"><table><thead><tr><th>Sesión</th><th>Vence</th></tr></thead><tbody>${data.sessions.map(session => `<tr><td>${session.current ? 'Esta sesión' : `Sesión #${session.id}`}</td><td>${escape(new Date(session.expires_at).toLocaleString('es-MX'))}</td></tr>`).join('')}</tbody></table></div>`;
  }

  $('#audit-action').innerHTML += Object.entries(auditActions).map(([value, label]) => `<option value="${escape(value)}">${escape(label)}</option>`).join('');
  $('#audit-filter-form').addEventListener('submit', async event => {
    event.preventDefault();
    if (!canWrite() || !event.currentTarget.reportValidity()) return;
    const filter = Object.fromEntries(Array.from(new FormData(event.currentTarget)).filter(([, value]) => value !== ''));
    if (filter.date_from && filter.date_to && filter.date_from > filter.date_to) {
      showError('La fecha inicial debe ser igual o anterior a la fecha final.', '#audit-filter-error');
      return;
    }
    showError('', '#audit-filter-error');
    state.auditFilters = filter;
    state.pagination.audit = 1;
    await navigate('audit');
  });
  $('#department-search').addEventListener('input', () => { state.pagination.departments = 1; renderDepartmentCatalog(); });
  $('#department-status').addEventListener('change', () => { state.pagination.departments = 1; renderDepartmentCatalog(); });
  $('#department-form').addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    if (!canWrite() || !form.reportValidity()) return;
    const id = $('#department-id').value;
    const editor = state.departmentEditor;
    const navigation = state.navigation;
    const token = state.token;
    const payload = Object.fromEntries(new FormData(form));
    payload.name = payload.name.trim();
    showError('', '#department-form-error');
    await withButton($('#save-department'), 'Guardando…', async () => {
      try {
        await api(id ? `departments/${id}` : 'departments', { method: id ? 'PATCH' : 'POST', body: payload });
        if (token !== state.token) return;
        if (editor === state.departmentEditor) $('#department-dialog').close();
        notify(id ? 'Departamento actualizado.' : 'Departamento creado.');
        if (navigation === state.navigation && editor === state.departmentEditor) await navigate('departments');
      } catch (error) {
        if (error.status !== 401 && token === state.token) {
          if (editor === state.departmentEditor && $('#department-dialog').open) showError(error.message, '#department-form-error');
          else notify(error.message, true);
        }
      }
    });
  });
  $('#user-search').addEventListener('input', () => { state.pagination.users = 1; renderUsers(); });
  $('#user-status').addEventListener('change', () => { state.pagination.users = 1; renderUsers(); });
  $('#user-dialog').addEventListener('close', () => {
    $('#user-form').elements.password.value = '';
    $('#user-password-confirm').value = '';
  });
  $('#user-form').addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    if (!canWrite() || !form.reportValidity()) return;
    const id = $('#user-id').value;
    const editor = state.userEditor;
    const navigation = state.navigation;
    const token = state.token;
    const payload = Object.fromEntries(new FormData(form));
    payload.name = payload.name.trim();
    payload.email = payload.email.trim();
    showError('', '#user-form-error');
    if (payload.password || $('#user-password-confirm').value) {
      if (payload.password !== $('#user-password-confirm').value) { showError('Las contraseñas no coinciden.', '#user-form-error'); return; }
      const bytes = new TextEncoder().encode(payload.password).length;
      if (bytes < 12 || bytes > 72) { showError('La contraseña debe tener entre 12 y 72 bytes de texto.', '#user-form-error'); return; }
    } else delete payload.password;
    await withButton($('#save-user'), 'Guardando…', async () => {
      try {
        const data = await api(id ? `users/${id}` : 'users', { method: id ? 'PATCH' : 'POST', body: payload });
        if (token !== state.token) return;
        if (data.user.id === state.user?.id) {
          if (data.user.email !== state.user.email) {
            endSession('Correo actualizado. Inicia sesión con tu nuevo correo y tu contraseña.');
            return;
          }
          state.user = data.user;
          $('#user-name').textContent = data.user.name;
          $('#user-avatar').textContent = initials(data.user.name);
        }
        if (editor === state.userEditor) $('#user-dialog').close();
        state.users = id ? state.users.map(user => user.id === data.user.id ? data.user : user) : [...state.users, data.user];
        notify(id ? 'Usuario actualizado.' : 'Usuario creado.');
        if (navigation === state.navigation && editor === state.userEditor) await navigate('users');
      } catch (error) {
        if (error.status !== 401 && token === state.token) {
          if (editor === state.userEditor && $('#user-dialog').open) showError(error.message, '#user-form-error');
          else notify(error.message, true);
        }
      }
    });
  });

  for (const kind of ['attendance', 'incidences']) {
    $(`#${kind}-form`).addEventListener('submit', async event => {
      event.preventDefault();
      const form = event.currentTarget;
      if (!form.reportValidity() || !canWrite()) return;
      const payload = Object.fromEntries(new FormData(form));
      const id = form.elements.id.value;
      delete payload.id;
      if (!id) payload.employee_id = Number(payload.employee_id);
      await withButton($('[type="submit"]', form), 'Guardando…', async () => {
        try {
          await api(id ? `${kind}/${id}` : kind, { method: id ? 'PATCH' : 'POST', body: payload });
          notify('Registro guardado.');
          await navigate(kind);
        } catch (error) { if (error.status !== 401) showError(error.message); }
      });
    });
  }

  $('#password-form').addEventListener('submit', async event => {
    event.preventDefault();
    if (!event.currentTarget.reportValidity()) return;
    if ($('#new-password').value !== $('#confirm-password').value) { showError('Las contraseñas no coinciden.', '#password-error'); return; }
    await withButton($('#change-password'), 'Guardando…', async () => {
      try {
        await api('auth/change-password', { method: 'POST', body: { current_password: $('#current-password').value, new_password: $('#new-password').value } });
        $('#password-form').reset();
        endSession('Contraseña actualizada. Inicia sesión con la nueva contraseña.');
      } catch (error) { if (error.status !== 401) showError(error.message, '#password-error'); }
    });
  });
  $('#revoke-sessions').addEventListener('click', async () => {
    await withButton($('#revoke-sessions'), 'Cerrando…', async () => {
      try {
        await api('auth/sessions/revoke-others', { method: 'POST' });
        renderSessions(await api('auth/sessions'));
        notify('Las demás sesiones se cerraron.');
      } catch (error) { if (error.status !== 401) showError(error.message, '#sessions-error'); }
    });
  });

  document.addEventListener('click', async event => {
    const button = event.target.closest('button');
    if (!button || button.disabled) return;
    if (button.dataset.page) { navigate(button.dataset.page); return; }
    if (button.dataset.closeDialog) { $(`#${button.dataset.closeDialog}`).close(); return; }
    const action = button.dataset.action;
    if (!action) return;
    try {
      if (action === 'retry-page') await navigate(state.page, button.dataset.id);
      else if (action === 'paginate') {
        const list = button.dataset.list;
        if (!Object.hasOwn(state.pagination, list)) return;
        state.pagination[list] = Number(button.dataset.targetPage);
        if (list === 'audit') await navigate('audit');
        else if (list === 'users') renderUsers();
        else if (list === 'departments') renderDepartmentCatalog();
        else if (list === 'employees') renderEmployees();
        else renderPayrollList();
        const footer = $({ employees: '#employee-count', payrolls: '#payroll-pagination', users: '#user-pagination', audit: '#audit-pagination', departments: '#department-pagination' }[list]);
        const replacement = footer.querySelector(`[data-action="paginate"][aria-label="${button.getAttribute('aria-label')}"]:not(:disabled)`) || footer.querySelector('[data-action="paginate"]:not(:disabled)');
        replacement?.focus({ preventScroll: true });
      }
      else if (action === 'edit-record') editRecord(button.dataset.kind, Number(button.dataset.id));
      else if (action === 'reset-record') resetRecord(button.dataset.kind);
      else if (action === 'export-employees') await downloadReport('reports/employees', 'empleados.csv');
      else if (action === 'export-payroll') await downloadReport(`reports/payrolls/${state.currentPayroll.payroll.id}`, `nomina-${state.currentPayroll.payroll.id}.csv`);
      else if (action === 'refresh-sessions') renderSessions(await api('auth/sessions'));
      else if (action === 'add-user') openUser();
      else if (action === 'add-department') openDepartment();
      else if (action === 'edit-department') openDepartment(button.dataset.id);
      else if (action === 'edit-user') openUser(button.dataset.id);
      else if (action === 'reset-audit-filters') {
        $('#audit-filter-form').reset();
        showError('', '#audit-filter-error');
        state.auditFilters = {};
        state.pagination.audit = 1;
        await navigate('audit');
      }
      else if (action === 'reset-payroll-filters') {
        state.pagination.payrolls = 1;
        $('#payroll-search').value = ''; $('#payroll-status').value = 'all';
        $('#payroll-from').value = ''; $('#payroll-to').value = ''; renderPayrollList();
      } else if (action === 'period-preset') {
        const value = $('#period-start').value || toDateInput(new Date());
        const preset = NominaUI.periodPreset(value, button.dataset.preset);
        $('#period-start').value = preset.start;
        $('#period-end').value = preset.end;
        await refreshPayrollPeriod();
      }
      else if (action === 'add-employee') openEmployee();
      else if (action === 'edit-employee') openEmployee(button.dataset.id);
      else if (action === 'open-payroll') await navigate('payroll-detail', button.dataset.id);
      else if (action === 'open-receipt') openReceipt(Number(button.dataset.index));
      else if (action === 'pay-payroll') { $('#pay-confirm-total').textContent = money(state.currentPayroll.payroll.total_net); $('#pay-dialog').showModal(); }
      else if (action === 'cancel-payroll') {
        if (!canWrite() || state.currentPayroll?.payroll.status !== 'draft') return;
        $('#cancel-payroll-form').reset();
        showError('', '#cancel-form-error');
        $('#cancel-dialog').showModal();
      }
      else if (action === 'toggle-employee') {
        const employee = state.employees.find(item => Number(item.id) === Number(button.dataset.id));
        if (!employee) return;
        const status = employee.status === 'active' ? 'inactive' : 'active';
        await withButton(button, 'Guardando…', async () => {
          const data = await api(`employees/${employee.id}`, { method: 'PATCH', body: { status } });
          state.employees = state.employees.map(item => item.id === employee.id ? data.employee : item);
          renderEmployees();
          updateTeamCount();
          notify(status === 'active' ? 'El empleado está activo nuevamente.' : 'Empleado desactivado. Su historial se conserva.');
        });
      }
    } catch (error) { if (error.status !== 401) notify(error.message, true); }
  });

  $('#login-form').addEventListener('submit', async event => {
    event.preventDefault();
    if (!event.currentTarget.reportValidity()) return;
    showError('', '#login-error');
    await withButton($('#login-submit'), 'Iniciando sesión…', async () => {
      try {
        const data = await api('auth/login', { method: 'POST', body: { email: $('#login-email').value.trim(), password: $('#login-password').value } });
        if (!data.authenticated || !data.token) throw new Error('No se pudo autenticar al usuario.');
        state.token = data.token;
        storage.set(data.token);
        $('#login-password').value = '';
        startSession(data.user);
      } catch (error) { showError(error.message, '#login-error'); }
    });
  });

  $('#toggle-password').addEventListener('click', () => {
    const show = $('#login-password').type === 'password';
    $('#login-password').type = show ? 'text' : 'password';
    $('#toggle-password').textContent = show ? 'Ocultar' : 'Ver';
    $('#toggle-password').setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
    $('#toggle-password').setAttribute('aria-pressed', String(show));
  });
  $('#use-demo').addEventListener('click', () => { $('#login-email').value = 'admin@nomina.local'; $('#login-password').value = 'Nomina2026!'; $('#login-submit').focus(); });
  $('#logout-button').addEventListener('click', async () => {
    await withButton($('#logout-button'), 'Cerrando sesión…', async () => {
      try { await api('auth/logout', { method: 'POST' }); endSession(); }
      catch (error) {
        endSession();
        if (error.status !== 401) showError('Cerramos la sesión en este navegador. No se pudo revocar el acceso en el servidor; caducará automáticamente.', '#login-error');
      }
    });
  });
  $('#mobile-menu').addEventListener('click', () => setMenuOpen(!$('#sidebar').classList.contains('is-open')));
  $('#close-menu').addEventListener('click', () => setMenuOpen(false, true));
  $('#menu-overlay').addEventListener('click', () => setMenuOpen(false, true));
  window.matchMedia('(max-width: 720px)').addEventListener('change', () => setMenuOpen(false));
  document.addEventListener('keydown', event => {
    if (!$('#sidebar').classList.contains('is-open')) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      setMenuOpen(false, true);
    }
    // Mantener el recorrido del teclado dentro del menú mientras esté abierto.
    if (event.key === 'Tab') {
      const buttons = $$('a[href], button:not([disabled])', $('#sidebar')).filter(element => element.offsetParent !== null);
      const first = buttons[0];
      const last = buttons[buttons.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }
  });
  $('#employee-search').addEventListener('input', () => { state.pagination.employees = 1; renderEmployees(); });
  $('#employee-status').addEventListener('change', () => { state.pagination.employees = 1; renderEmployees(); });
  $('#employee-department').addEventListener('change', () => { state.pagination.employees = 1; renderEmployees(); });
  for (const id of ['payroll-search', 'payroll-status', 'payroll-from', 'payroll-to']) $(`#${id}`).addEventListener('input', () => { state.pagination.payrolls = 1; renderPayrollList(); });
  $('#use-records').addEventListener('change', refreshPayrollPeriod);
  $('#refresh-records').addEventListener('click', refreshPayrollPeriod);
  $('#payroll-editor').addEventListener('change', event => {
    const input = event.target.closest('[data-select-employee]');
    if (!input) return;
    const id = Number(input.dataset.selectEmployee);
    if ($('#use-records').checked && (!state.recordsReady || state.recordEligibility.get(id)?.can_create !== true)) {
      input.checked = false;
    }
    input.checked ? state.selected.add(id) : state.selected.delete(id);
    $$('[data-payroll-field]', input.closest('tr')).forEach(field => { field.disabled = !input.checked || $('#use-records').checked; });
    updatePreview();
  });
  $('#add-employee').addEventListener('click', () => openEmployee());
  $('#employee-form').addEventListener('submit', async event => {
    event.preventDefault();
    if (!event.currentTarget.reportValidity()) return;
    const form = event.currentTarget;
    const id = $('#employee-id').value;
    const payload = Object.fromEntries(new FormData(form));
    for (const key of Object.keys(payload)) payload[key] = payload[key].trim();
    payload.monthly_salary = Number(payload.monthly_salary);
    showError('', '#employee-form-error');
    await withButton($('#save-employee'), 'Guardando…', async () => {
      try {
        await api(id ? `employees/${id}` : 'employees', { method: id ? 'PATCH' : 'POST', body: payload });
        $('#employee-dialog').close();
        notify(id ? 'Los datos del empleado están actualizados.' : 'Una persona más en tu equipo. Empleado agregado.');
        await navigate('employees');
      } catch (error) { if (error.status !== 401) showError(error.message, '#employee-form-error'); }
    });
  });
  $('#period-start').addEventListener('change', refreshPayrollPeriod);
  $('#period-end').addEventListener('change', refreshPayrollPeriod);
  $('#payroll-editor').addEventListener('input', event => { if (event.target.matches('[data-payroll-field]')) updatePreview(); });
  $('#payroll-form').addEventListener('submit', async event => {
    event.preventDefault();
    updatePreview();
    if ($('#create-payroll-submit').disabled) return;
    if (!event.currentTarget.reportValidity()) return;
    const items = state.editorEmployees.filter(employee => eligibleDays(employee) > 0 && state.selected.has(employee.id)).map(employee => ({ employee_id: Number(employee.id), ...state.drafts.get(employee.id) }));
    if (!items.length || ($('#use-records').checked && !state.recordsReady)) return;
    showError('');
    await withButton($('#create-payroll-submit'), 'Guardando nómina…', async () => {
      try {
        const data = await api('payrolls', { method: 'POST', body: { period_start: $('#period-start').value, period_end: $('#period-end').value, items, use_records: $('#use-records').checked } });
        notify('Nómina creada. Ya puedes revisar los recibos de tu equipo.');
        await navigate('payroll-detail', data.payroll.id);
      } catch (error) { if (error.status !== 401) { showError(error.message); $('#page-error').scrollIntoView({ block: 'center', behavior: 'smooth' }); } }
    });
  });
  $('#confirm-payment').addEventListener('click', async () => {
    if (!state.currentPayroll) return;
    const payrollId = state.currentPayroll.payroll.id;
    const navigation = state.navigation;
    await withButton($('#confirm-payment'), 'Registrando pago…', async () => {
      try {
        const data = await api(`payrolls/${payrollId}/pay`, { method: 'POST' });
        if (navigation !== state.navigation || state.currentPayroll?.payroll.id !== payrollId) return;
        state.currentPayroll = data;
        $('#pay-dialog').close();
        renderPayrollDetail();
        notify('El pago quedó registrado correctamente.');
      } catch (error) { if (error.status !== 401 && navigation === state.navigation) { $('#pay-dialog').close(); notify(error.message, true); } }
    });
  });
  $('#cancel-payroll-form').addEventListener('submit', async event => {
    event.preventDefault();
    if (!event.currentTarget.reportValidity() || !canWrite() || state.currentPayroll?.payroll.status !== 'draft') return;
    const payrollId = state.currentPayroll.payroll.id;
    const navigation = state.navigation;
    showError('', '#cancel-form-error');
    await withButton($('#confirm-cancellation'), 'Cancelando…', async () => {
      try {
        const data = await api(`payrolls/${payrollId}/cancel`, { method: 'POST', body: { reason: $('#cancel-reason').value.trim() } });
        if (navigation !== state.navigation || state.currentPayroll?.payroll.id !== payrollId) return;
        state.currentPayroll = data;
        $('#cancel-dialog').close();
        renderPayrollDetail();
        notify('Nómina cancelada. Ya puedes corregir los registros y generar otra para el periodo.');
      } catch (error) { if (error.status !== 401 && navigation === state.navigation) showError(error.message, '#cancel-form-error'); }
    });
  });
  $('#print-receipt').addEventListener('click', () => window.print());

  async function initialize() {
    if (!state.token) { endSession(); return; }
    try {
      const data = await api('auth/me');
      if (!data.authenticated) throw new Error('Tu sesión terminó. Inicia sesión nuevamente.');
      startSession(data.user);
    } catch (error) { endSession(error.status === 401 ? 'Tu sesión terminó. Inicia sesión nuevamente para continuar.' : error.message); }
  }
  initialize();
})();
