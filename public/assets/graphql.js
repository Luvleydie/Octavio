'use strict';

// Cada ruta de la interfaz se convierte en una operación GraphQL.
// Los datos del formulario se envían como variables, separados de la consulta.
globalThis.NominaGraphQL = (() => {
  const user = 'id name email role status created_at';
  const department = 'id name status employee_count active_employee_count created_at';
  const audit = 'items { id actor_id actor_name action entity entity_id summary created_at } total page per_page';
  const employee = 'id employee_code full_name email department position monthly_salary hire_date status created_at';
  const attendance = 'id employee_id employee_code full_name work_date status notes created_by created_at';
  const incidence = 'id employee_id employee_code full_name occurrence_date type concept amount created_by created_at';
  const payroll = 'id period_start period_end employee_count total_gross total_deductions total_net status created_by created_at paid_at cancelled_at cancelled_by cancellation_reason';
  const details = `payroll { ${payroll} } items { id payroll_id employee_id employee_code full_name department position monthly_salary days_worked base_pay bonus deduction gross_pay net_pay } incidence_ids`;
  const preview = 'period_start period_end can_create totals { gross deductions net } items { employee_id employee_code full_name monthly_salary days_worked attendance_count present_days absent_days paid_leave_days bonus deduction incidence_ids base_pay gross_pay net_pay can_create errors }';
  const dashboard = `stats { active_employees monthly_payroll last_run_total total_runs pending_runs paid_total } departments { department total } recent_runs { ${payroll} } monthly_payments { month total }`;

  function build(route, options = {}) {
    const method = options.method || 'GET';
    const input = options.body || {};
    const query = options.query || {};
    let operation = 'query';
    let field;
    let selection;
    let declaration = '';
    let args = '';
    let variables = {};
    let wrap = null;

    // Consultas de lectura: nombre del campo, datos pedidos y nombre en la pantalla.
    const reads = {
      health: ['health', 'status database'],
      'auth/me': ['me', `authenticated user { ${user} }`],
      'auth/sessions': ['sessions', 'id created_at expires_at current', 'sessions'],
      users: ['users', user, 'users'],
      departments: ['departments', department, 'departments'],
      employees: ['employees', employee, 'employees'],
      payrolls: ['payrolls', payroll, 'payrolls'],
      payments: ['payments', payroll, 'payments'],
      dashboard: ['dashboard', dashboard]
    };
    if (method === 'GET' && reads[route]) {
      [field, selection, wrap = null] = reads[route];
    } else if (method === 'GET' && route === 'audit') {
      field = 'auditLogs'; selection = audit;
      declaration = '($filter: AuditFilter)'; args = '(filter: $filter)'; variables = { filter: query };
    } else if (method === 'GET' && ['attendance', 'incidences'].includes(route)) {
      field = route;
      selection = route === 'attendance' ? attendance : incidence;
      wrap = route;
      declaration = '($filter: RecordFilter)'; args = '(filter: $filter)'; variables = { filter: query };
    } else if (method === 'GET' && route === 'payrolls/preview') {
      field = 'payrollPreview'; selection = preview;
      declaration = '($period_start: String!, $period_end: String!, $employee_id: ID)';
      args = '(period_start: $period_start, period_end: $period_end, employee_id: $employee_id)'; variables = query;
    } else if (method === 'GET' && /^payrolls\/\d+$/.test(route)) {
      field = 'payroll'; selection = details;
      declaration = '($id: ID!)'; args = '(id: $id)'; variables = { id: route.split('/')[1] };
    } else {
      operation = 'mutation';
      const auth = {
        'auth/login': ['login', '($email: String!, $password: String!)', '(email: $email, password: $password)', `authenticated token expires_at user { ${user} }`],
        'auth/logout': ['logout', '', '', 'authenticated message'],
        'auth/change-password': ['changePassword', '($current_password: String!, $new_password: String!)', '(current_password: $current_password, new_password: $new_password)', 'authenticated message'],
        'auth/sessions/revoke-others': ['revokeOtherSessions', '', '', 'revoked_count message']
      };
      if (method === 'POST' && auth[route]) {
        [field, declaration, args, selection] = auth[route]; variables = input;
      } else if (method === 'POST' && route === 'payrolls') {
        field = 'createPayroll'; selection = details;
        declaration = '($input: PayrollInput!)'; args = '(input: $input)'; variables = { input };
      } else if (method === 'POST' && /^payrolls\/\d+\/(pay|cancel)$/.test(route)) {
        const [, id, action] = route.split('/');
        field = action === 'pay' ? 'payPayroll' : 'cancelPayroll'; selection = details;
        declaration = action === 'pay' ? '($id: ID!)' : '($id: ID!, $reason: String!)';
        args = action === 'pay' ? '(id: $id)' : '(id: $id, reason: $reason)'; variables = { id, ...input };
      } else {
        const match = /^(employees|departments|attendance|incidences|users)(?:\/(\d+))?$/.exec(route);
        if (!match || method !== (match[2] ? 'PATCH' : 'POST')) throw new Error('Operación GraphQL desconocida.');
        const [, resource, id] = match;
        const resources = {
          users: ['User', user, 'user'],
          departments: ['Department', department, 'department'],
          employees: ['Employee', employee, 'employee'],
          attendance: ['Attendance', attendance, 'attendance'],
          incidences: ['Incidence', incidence, 'incidence']
        };
        const [type, fields, wrapper] = resources[resource];
        field = `${id ? 'update' : 'create'}${type}`; selection = fields; wrap = wrapper;
        declaration = `(${id ? '$id: ID!, ' : ''}$input: ${type}${id ? 'Update' : 'Create'}Input!)`;
        args = `(${id ? 'id: $id, ' : ''}input: $input)`;
        variables = id ? { id, input } : { input };
      }
    }
    return { query: `${operation} Web${declaration} { result: ${field}${args} { ${selection} } }`, variables, operationName: 'Web', wrap };
  }

  // GraphQL devuelve los ID como texto. La pantalla los compara como números.
  // Recorremos listas y objetos para convertir también los ID de sus elementos.
  function normalize(value, key = '') {
    if (Array.isArray(value)) {
      const items = [];
      const itemKey = key.endsWith('_ids') ? 'id' : '';
      for (const item of value) {
        items.push(normalize(item, itemKey));
      }
      return items;
    }

    if (value && typeof value === 'object') {
      const result = {};
      for (const name of Object.keys(value)) {
        result[name] = normalize(value[name], name);
      }
      return result;
    }

    const isId = key === 'id' || key.endsWith('_id');
    if (isId && typeof value === 'string' && /^\d+$/.test(value)) {
      return Number(value);
    }
    return value;
  }

  // El alias "result" permite leer todas las respuestas de la misma manera.
  function unwrap(data, request) {
    const result = normalize(data.result);
    if (request.wrap) {
      return { [request.wrap]: result };
    }
    return result;
  }

  return { build, unwrap };
})();
