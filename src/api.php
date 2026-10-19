<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/services/AuthenticationService.php';
require_once __DIR__ . '/services/UserService.php';
require_once __DIR__ . '/services/AuditService.php';
require_once __DIR__ . '/services/EmployeeService.php';
require_once __DIR__ . '/services/AttendanceService.php';
require_once __DIR__ . '/services/IncidenceService.php';
require_once __DIR__ . '/services/PayrollService.php';
require_once __DIR__ . '/services/PaymentService.php';
require_once __DIR__ . '/services/ReportService.php';

/** REST y GraphQL pasan por esta función y comparten permisos y servicios. */
function api_data(string $route, string $method, array $input = [], array $filters = []): array
{
    $aliases = ['auth/change-password' => 'auth/password', 'reports/dashboard' => 'dashboard', 'employees/export' => 'reports/employees'];
    $route = $aliases[$route] ?? $route;
    if (preg_match('#^payrolls/([1-9][0-9]{0,9})/export$#D', $route, $matches)) {
        $route = 'reports/payrolls/' . $matches[1];
    }
    // Solo el diagnóstico y el inicio de sesión son públicos.
    // Revisar cada operación también protege una petición GraphQL con varias mutaciones.
    $user = null;
    if ($route !== 'health' && $route !== 'auth/login') {
        $user = authenticated_user();
    }

    // Cada caso indica qué método acepta y qué función del servicio ejecuta.
    switch ($route) {
        case 'health':
            if ($method !== 'GET') break;
            db()->query('SELECT 1');
            return ['status' => 'ok', 'database' => 'connected'];

        case 'auth/login':
            if ($method !== 'POST') break;
            return login($input);

        case 'auth/me':
            if ($method !== 'GET') break;
            return ['authenticated' => true, 'user' => public_user($user)];

        case 'auth/logout':
            if ($method !== 'POST') break;
            return logout($user);

        case 'auth/password':
            if ($method !== 'POST') break;
            return change_password($user, $input);

        case 'auth/sessions':
            if ($method !== 'GET') break;
            return list_sessions($user);

        case 'auth/sessions/revoke-others':
            if ($method !== 'POST') break;
            return revoke_other_sessions($user);

        case 'employees':
            if ($method === 'GET') return list_employees();
            if ($method !== 'POST') break;
            require_admin($user);
            return save_employee($input, null, $user);

        case 'departments':
            if ($method === 'GET') return list_departments();
            if ($method !== 'POST') break;
            require_admin($user);
            return save_department($user, $input);

        case 'users':
            require_admin($user);
            if ($method === 'GET') return list_users();
            if ($method === 'POST') return save_user($user, $input);
            break;

        case 'audit':
            require_admin($user);
            if ($method === 'GET') return list_audit_logs($filters);
            break;

        case 'attendance':
            if ($method === 'GET') return list_attendance($filters);
            if ($method !== 'POST') break;
            require_admin($user);
            return save_attendance($user, $input);

        case 'incidences':
            if ($method === 'GET') return list_incidences($filters);
            if ($method !== 'POST') break;
            require_admin($user);
            return save_incidence($user, $input);

        case 'payrolls':
            if ($method === 'GET') return list_payrolls();
            if ($method !== 'POST') break;
            require_admin($user);
            return create_payroll($user, $input);

        case 'payrolls/preview':
            if ($method !== 'GET') break;
            return payroll_preview($filters);

        case 'payments':
            if ($method !== 'GET') break;
            return list_payments();

        case 'dashboard':
            if ($method !== 'GET') break;
            return dashboard();

        case 'reports/employees':
            if ($method !== 'GET') break;
            export_employees_csv();

        default:
            return api_record_route($route, $method, $input, $user);
    }

    // Llegamos aquí si la ruta existe, pero el método no está permitido.
    throw new ApiException(405, 'Método no permitido.');
}

/** Atiende las rutas que contienen un identificador, por ejemplo employees/3. */
function api_record_route(string $route, string $method, array $input, array $user): array
{
    if (preg_match('#^departments/([1-9][0-9]{0,9})$#D', $route, $matches)) {
        require_admin($user);
        if ($method !== 'PATCH') throw new ApiException(405, 'Método no permitido.');
        return save_department($user, $input, (int) $matches[1]);
    }
    if (preg_match('#^users/([1-9][0-9]{0,9})$#D', $route, $matches)) {
        require_admin($user);
        if ($method !== 'PATCH') throw new ApiException(405, 'Método no permitido.');
        return save_user($user, $input, (int) $matches[1]);
    }
    if (preg_match('#^(employees|attendance|incidences)/([1-9][0-9]{0,9})$#D', $route, $matches)) {
        if ($method !== 'PATCH') throw new ApiException(405, 'Método no permitido.');
        require_admin($user);
        $id = (int) $matches[2];
        if ($matches[1] === 'employees') return save_employee($input, $id, $user);
        if ($matches[1] === 'attendance') return save_attendance($user, $input, $id);
        return save_incidence($user, $input, $id);
    }
    if (preg_match('#^payrolls/([1-9][0-9]{0,9})(?:/(pay|cancel))?$#D', $route, $matches)) {
        $action = $matches[2] ?? '';
        if ($method !== ($action === '' ? 'GET' : 'POST')) throw new ApiException(405, 'Método no permitido.');
        $id = (int) $matches[1];
        if ($action === '') return payroll_details($id);
        require_admin($user);
        if ($action === 'pay') return mark_payroll_paid($id, $user);
        return cancel_payroll($id, $user, $input);
    }
    if (preg_match('#^reports/payrolls/([1-9][0-9]{0,9})$#D', $route, $matches)) {
        if ($method !== 'GET') throw new ApiException(405, 'Método no permitido.');
        export_payroll_csv((int) $matches[1]);
    }
    throw new ApiException(404, 'La ruta solicitada no existe.');
}

/** Las respuestas no exponen detalles de SQL; esos errores van al registro de PHP. */
function api_error(Throwable $error): array
{
    if ($error instanceof ApiException) return [$error->status, ['message' => $error->getMessage(), ...$error->extra]];
    if ($error instanceof InvalidArgumentException) return [422, ['message' => $error->getMessage()]];
    if ($error instanceof PDOException) {
        error_log('Database error: ' . $error->getMessage());
        if (in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213], true)) {
            return [409, ['message' => 'Otra operación está modificando estos datos. Vuelve a intentarlo.']];
        }
        return [503, ['message' => 'No se pudo completar la operación con la base de datos. Revisa la configuración y que MySQL esté iniciado.']];
    }
    error_log('Application error: ' . $error->getMessage());
    return [500, ['message' => 'No se pudo completar la solicitud.']];
}
