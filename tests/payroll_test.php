<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/payroll.php';

$checks = 0;
function same(mixed $expected, mixed $actual, string $label): void
{
    global $checks;
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': esperado ' . json_encode($expected) . ', recibido ' . json_encode($actual));
    }
    $checks++;
}
function rejected(callable $action, string $label): void
{
    global $checks;
    try { $action(); } catch (InvalidArgumentException) { $checks++; return; }
    throw new RuntimeException('Debió rechazarse: ' . $label);
}

same(925000, calculate_payroll_item(1800000, 15, 50000, 25000, 15)['net_pay'], 'Quincena con bono y deducción');
same(33333, calculate_payroll_item(100000, 10, 0, 0, 15)['base_pay'], 'Redondear al final del prorrateo');
same(1, calculate_payroll_item(1, 15, 0, 0, 15)['base_pay'], 'Medio centavo se redondea hacia arriba');
same(1800000, calculate_payroll_item(1800000, 30, 0, 0, 31)['net_pay'], 'Mes de 31 días, base de 30');
same(0, calculate_payroll_item(1800000, 0, 0, 0, 15)['net_pay'], 'Ausencia todo el periodo');
same(1, money_to_cents('0.01'), 'Conversión exacta centavo');
same('100.05', cents_to_decimal(10005), 'Formato de moneda');
same(29, payroll_period_days('2024-02-01', '2024-02-29'), 'Año bisiesto');
same(1, payroll_period_days('2026-09-15', '2026-09-15'), 'Periodo de un día');
rejected(fn() => money_to_cents('1.001'), 'Más de dos decimales');
rejected(fn() => money_to_cents('-1'), 'Dinero negativo');
rejected(fn() => money_to_cents('1e3'), 'Notación exponencial');
rejected(fn() => money_to_cents([]), 'Dinero con tipo inválido');
rejected(fn() => payroll_period_days('2026-02-29', '2026-03-01'), 'Fecha inexistente');
rejected(fn() => payroll_period_days('2026-09-16', '2026-09-15'), 'Periodo invertido');
rejected(fn() => payroll_period_days('2026-09-01', '2026-10-02'), 'Más de 31 días');
rejected(fn() => calculate_payroll_item(1800000, 16, 0, 0, 15), 'Días exceden periodo');
rejected(fn() => calculate_payroll_item(1800000, 31, 0, 0, 31), 'Máximo 30 días pagados');
rejected(fn() => calculate_payroll_item(1800000, 15, 0, 900001, 15), 'Deducción mayor a bruto');
echo "$checks comprobaciones de cálculo correctas.\n";
