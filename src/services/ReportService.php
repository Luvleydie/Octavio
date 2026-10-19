<?php
declare(strict_types=1);

// Servicio de reportes: resumen del sistema y exportación de datos a CSV.

require_once __DIR__ . '/PayrollService.php';

function dashboard(): array
{
    $connection = db();
    $employeeStats = $connection->query("SELECT COUNT(*) AS active_employees, COALESCE(SUM(monthly_salary), 0) AS monthly_payroll FROM employees WHERE status = 'active'")->fetch();
    $totalRuns = (int) $connection->query('SELECT COUNT(*) FROM payrolls')->fetchColumn();
    $paymentStats = $connection->query("SELECT COALESCE(SUM(status = 'draft'), 0) AS pending_runs, COALESCE(SUM(CASE WHEN status = 'paid' THEN total_net ELSE 0 END), 0) AS paid_total FROM payrolls")->fetch();
    $monthStart = (new DateTimeImmutable('first day of this month', new DateTimeZone('UTC')))->setTime(0, 0)->modify('-5 months');
    $query = $connection->prepare("SELECT DATE_FORMAT(paid_at, '%Y-%m') AS month, SUM(total_net) AS total FROM payrolls WHERE status = 'paid' AND paid_at >= ? GROUP BY DATE_FORMAT(paid_at, '%Y-%m') ORDER BY month");
    $query->execute([$monthStart->format('Y-m-d H:i:s')]);
    $paidByMonth = [];
    foreach ($query->fetchAll() as $month) {
        $paidByMonth[$month['month']] = (float) $month['total'];
    }
    $monthlyPayments = [];
    for ($index = 0; $index < 6; $index++) {
        $month = $monthStart->modify("+{$index} months")->format('Y-m');
        $monthlyPayments[] = ['month' => $month, 'total' => $paidByMonth[$month] ?? 0.0];
    }
    $recent = $connection->query('SELECT * FROM payrolls ORDER BY id DESC LIMIT 5')->fetchAll();
    $lastCurrentTotal = $connection->query("SELECT total_net FROM payrolls WHERE status <> 'cancelled' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $departments = $connection->query("SELECT department, COUNT(*) AS total FROM employees WHERE status = 'active' GROUP BY department ORDER BY total DESC, department")->fetchAll();
    foreach ($departments as &$department) {
        $department['total'] = (int) $department['total'];
    }
    unset($department);
    return [
        'stats' => [
            'active_employees' => (int) $employeeStats['active_employees'],
            'monthly_payroll' => (float) $employeeStats['monthly_payroll'],
            'last_run_total' => $lastCurrentTotal !== false ? (float) $lastCurrentTotal : 0,
            'total_runs' => $totalRuns,
            'pending_runs' => (int) $paymentStats['pending_runs'],
            'paid_total' => (float) $paymentStats['paid_total'],
        ],
        'departments' => $departments,
        'recent_runs' => array_map('serialize_payroll', $recent),
        'monthly_payments' => $monthlyPayments,
    ];
}
/** Convierte una celda sin permitir que un texto se interprete como fórmula. */
function csv_safe_cell(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    if (is_int($value) || (is_float($value) && is_finite($value))) {
        return (string) $value;
    }
    if (!is_string($value) && !is_bool($value)) {
        throw new InvalidArgumentException('La celda CSV debe contener un valor escalar finito.');
    }
    $text = (string) $value;
    // También protege una fórmula precedida por espacios o controles, y celdas
    // que comienzan con tabulador/salto de línea. No modifica el dato almacenado.
    if (preg_match('/^[\p{Z}\x00-\x20]*[=+\-@\t\r\n]/u', $text) === 1) {
        return "'" . $text;
    }
    return $text;
}

/** @param array<array<mixed>> $rows */
function download_csv(string $filename, array $headings, array $rows): never
{
    // Se prepara el archivo antes de enviar encabezados para conservar respuestas
    // JSON de error si fallara la generación. php://temp usa disco al superar 2 MiB.
    $stream = fopen('php://temp', 'w+b');
    if ($stream === false) {
        throw new RuntimeException('No se pudo preparar el archivo CSV.');
    }
    try {
        if (fwrite($stream, "\xEF\xBB\xBF") === false) {
            throw new RuntimeException('No se pudo preparar el archivo CSV.');
        }
        foreach ([$headings, ...$rows] as $row) {
            if (fputcsv($stream, array_map('csv_safe_cell', $row), ',', '"', '', "\r\n") === false) {
                throw new RuntimeException('No se pudo escribir el archivo CSV.');
            }
        }
        rewind($stream);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        fpassthru($stream);
    } finally {
        fclose($stream);
    }
    exit;
}

function export_employees_csv(): never
{
    $employees = db()->query('SELECT * FROM employees ORDER BY full_name, id')->fetchAll();
    $rows = [];
    foreach ($employees as $employee) {
        $rows[] = [
            (int) $employee['id'],
            $employee['employee_code'],
            $employee['full_name'],
            $employee['email'],
            $employee['department'],
            $employee['position'],
            number_format((float) $employee['monthly_salary'], 2, '.', ''),
            $employee['hire_date'],
            $employee['status'] === 'active' ? 'Activo' : 'Inactivo',
            $employee['created_at'],
        ];
    }
    download_csv('nomina-clara-empleados.csv', [
        'ID', 'Código', 'Empleado', 'Correo', 'Departamento', 'Puesto',
        'Sueldo mensual (MXN)', 'Fecha de ingreso', 'Estado', 'Fecha de registro (UTC)',
    ], $rows);
}

function export_payroll_csv(int $id): never
{
    // Se leen las copias históricas de los detalles, nunca el expediente actual.
    $details = payroll_details($id);
    $payroll = $details['payroll'];
    $statuses = ['draft' => 'Borrador', 'paid' => 'Pagada', 'cancelled' => 'Cancelada'];
    $rows = [];
    foreach ($details['items'] as $item) {
        $rows[] = [
            $item['full_name'],
            $item['employee_code'],
            $item['position'],
            $item['department'],
            number_format((float) $item['monthly_salary'], 2, '.', ''),
            (int) $item['days_worked'],
            number_format((float) $item['base_pay'], 2, '.', ''),
            number_format((float) $item['bonus'], 2, '.', ''),
            number_format((float) $item['deduction'], 2, '.', ''),
            number_format((float) $item['net_pay'], 2, '.', ''),
            $payroll['period_start'],
            $payroll['period_end'],
            $statuses[$payroll['status']] ?? $payroll['status'],
            $payroll['cancellation_reason'],
            $payroll['cancelled_at'],
        ];
    }
    download_csv('nomina-clara-nomina-' . $id . '.csv', [
        'Empleado', 'Código', 'Puesto', 'Departamento', 'Sueldo mensual (MXN)',
        'Días trabajados', 'Sueldo base (MXN)', 'Bono (MXN)', 'Deducción (MXN)',
        'Neto (MXN)', 'Inicio del periodo', 'Fin del periodo', 'Estado',
        'Motivo de cancelación', 'Fecha de cancelación (UTC)',
    ], $rows);
}
