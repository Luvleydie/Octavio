<?php
declare(strict_types=1);

// Servicio de cálculo: reglas numéricas sin conexión a la base de datos.

/** Convierte pesos a centavos, sin aritmética de punto flotante. */
function money_to_cents(mixed $value, string $field = 'importe', int $maximum = 1000000000): int
{
    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        throw new InvalidArgumentException("El campo {$field} debe ser un importe válido.");
    }
    $text = (string) $value;
    if (!preg_match('/^([0-9]{1,10})(?:\.([0-9]{1,2}))?$/D', $text, $matches)) {
        throw new InvalidArgumentException("El campo {$field} debe ser positivo o cero y tener hasta dos decimales.");
    }
    $cents = ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    if ($cents > $maximum) {
        throw new InvalidArgumentException("El campo {$field} supera el límite permitido.");
    }
    return $cents;
}

function cents_to_decimal(int $cents): string
{
    if ($cents < 0) {
        throw new InvalidArgumentException('El importe no puede ser negativo.');
    }
    return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
}

function valid_date(mixed $value, string $field): DateTimeImmutable
{
    if (!is_string($value) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value)) {
        throw new InvalidArgumentException("El campo {$field} debe ser una fecha YYYY-MM-DD.");
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
    if (!$date || $date->format('Y-m-d') !== $value || $value < '1900-01-01' || $value > '9999-12-31') {
        throw new InvalidArgumentException("El campo {$field} contiene una fecha inválida.");
    }
    return $date;
}

/** Intervalo inclusivo; un mes de 31 días se paga con un máximo de 30 días. */
function payroll_period_days(mixed $start, mixed $end): int
{
    $first = valid_date($start, 'period_start');
    $last = valid_date($end, 'period_end');
    $days = (int) $first->diff($last)->format('%r%a') + 1;
    if ($days < 1 || $days > 31) {
        throw new InvalidArgumentException('El periodo debe contener entre 1 y 31 días.');
    }
    return $days;
}

/** Devuelve exclusivamente centavos enteros. Función pura, independiente de PDO. */
function calculate_payroll_item(int $monthlySalaryCents, int $daysWorked, int $bonusCents, int $deductionCents, int $periodDays): array
{
    if ($periodDays < 1 || $periodDays > 31 || $daysWorked < 0 || $daysWorked > min($periodDays, 30)) {
        throw new InvalidArgumentException('Los días trabajados deben estar entre 0 y los días del periodo, con máximo de 30.');
    }
    if ($monthlySalaryCents < 0 || $monthlySalaryCents > 1000000000 || $bonusCents < 0 || $bonusCents > 1000000000 || $deductionCents < 0 || $deductionCents > 1000000000) {
        throw new InvalidArgumentException('Los importes están fuera de rango.');
    }
    // Redondeo half-up después de prorratear: evita redondear antes el salario diario.
    $base = intdiv($monthlySalaryCents * $daysWorked + 15, 30);
    $gross = $base + $bonusCents;
    if ($deductionCents > $gross) {
        throw new InvalidArgumentException('Las deducciones no pueden superar el importe bruto.');
    }
    return ['base_pay' => $base, 'bonus' => $bonusCents, 'deduction' => $deductionCents, 'gross_pay' => $gross, 'net_pay' => $gross - $deductionCents];
}
