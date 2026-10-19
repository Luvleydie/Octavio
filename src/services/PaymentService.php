<?php
declare(strict_types=1);

// Servicio de pagos: registro administrativo de nóminas pagadas.
// Marcar una nómina no ejecuta una transferencia bancaria.

require_once __DIR__ . '/PayrollService.php';

function list_payments(): array
{
    $rows = db()->query("SELECT * FROM payrolls WHERE status = 'paid' ORDER BY paid_at DESC, id DESC")->fetchAll();
    return ['payments' => array_map('serialize_payroll', $rows)];
}

function mark_payroll_paid(int $id, ?array $actor = null): array
{
    $actor ??= authenticated_user();
    $connection = db();
    $connection->beginTransaction();
    try {
        $payroll = lock_payroll_for_update($connection, $id);
        if ($payroll['status'] === 'cancelled') {
            throw new ApiException(409, 'Una nómina cancelada no se puede pagar.');
        }
        if ($payroll['status'] === 'draft') {
            $query = $connection->prepare("UPDATE payrolls SET status = 'paid', paid_at = UTC_TIMESTAMP() WHERE id = ?");
            $query->execute([$id]);
            audit_log($actor, 'payroll.paid', 'payroll', $id, 'Pago administrativo de nómina registrado.');
        }
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
    // Repetir la petición conserva la fecha del primer pago.
    return payroll_details($id);
}

