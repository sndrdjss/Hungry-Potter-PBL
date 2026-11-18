<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role(['admin', 'cashier']);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/payments.php';
refresh_account_for_money_actions($pdo);
require_role(['admin', 'cashier']);

function shift_redirect(string $message, string $type): never
{
    $_SESSION['shift_message'] = $message;
    $_SESSION['shift_message_type'] = $type;
    header('Location: ../register.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}
if (!verify_csrf_token(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    shift_redirect('Invalid request token. Refresh and try again.', 'error');
}
$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
$amount = payment_parse_cents($_POST[$action === 'open' ? 'opening_float' : 'counted_cash'] ?? null);
$note = is_string($_POST['close_note'] ?? null) ? trim($_POST['close_note']) : '';
if (!in_array($action, ['open', 'close'], true) || $amount === null || strlen($note) > 255) {
    shift_redirect('Enter a valid register amount.', 'error');
}
if ($action === 'open' && ($_SESSION['role'] ?? '') !== 'cashier') {
    shift_redirect('A cashier must open the register shift.', 'error');
}
if ($action === 'close' && ($_SESSION['role'] ?? '') !== 'admin') {
    shift_redirect('An admin must close the register shift.', 'error');
}

try {
    $pdo->beginTransaction();
    $register = $pdo->query('SELECT id, current_shift_id FROM registers WHERE id = 1 FOR UPDATE')->fetch();
    if (!$register) {
        throw new DomainException('POS-01 is not configured.');
    }
    if ($action === 'open') {
        if ($register['current_shift_id'] !== null) {
            throw new DomainException('POS-01 already has an open shift.');
        }
        $insert = $pdo->prepare(
            'INSERT INTO register_shifts (register_id, opened_by, opening_float)
             VALUES (1, :user_id, :opening_float)'
        );
        $insert->execute(['user_id' => $_SESSION['user_id'], 'opening_float' => payment_sql_amount($amount)]);
        $shiftId = (int) $pdo->lastInsertId();
        $update = $pdo->prepare('UPDATE registers SET current_shift_id = :shift_id WHERE id = 1');
        $update->execute(['shift_id' => $shiftId]);
        $pdo->commit();
        shift_redirect('POS-01 shift opened.', 'success');
    }
    if ($register['current_shift_id'] === null) {
        throw new DomainException('There is no open shift to close.');
    }
    $shiftId = (int) $register['current_shift_id'];
    $shiftStatement = $pdo->prepare('SELECT * FROM register_shifts WHERE id = :id AND closed_at IS NULL FOR UPDATE');
    $shiftStatement->execute(['id' => $shiftId]);
    $shift = $shiftStatement->fetch();
    if (!$shift) {
        throw new DomainException('The current shift could not be found.');
    }
    $totals = payment_shift_totals($pdo, $shiftId);
    $expected = (payment_parse_cents((string) $shift['opening_float']) ?? 0) + $totals['cash'];
    $variance = $amount - $expected;
    if ($variance !== 0 && $note === '') {
        throw new DomainException('Explain the cash variance before closing the shift.');
    }
    $close = $pdo->prepare(
        'UPDATE register_shifts
         SET closed_by = :user_id, closed_at = NOW(), expected_cash = :expected,
             counted_cash = :counted, variance = :variance, close_note = :note
         WHERE id = :id AND closed_at IS NULL'
    );
    $close->execute([
        'user_id' => $_SESSION['user_id'],
        'expected' => payment_sql_amount($expected),
        'counted' => payment_sql_amount($amount),
        'variance' => payment_sql_amount($variance),
        'note' => $note !== '' ? $note : null,
        'id' => $shiftId,
    ]);
    $pdo->query('UPDATE registers SET current_shift_id = NULL WHERE id = 1');
    $pdo->commit();
    shift_redirect('Shift closed and cash variance recorded.', 'success');
} catch (DomainException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    shift_redirect($exception->getMessage(), 'error');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Register shift action failed: ' . $exception->getMessage());
    shift_redirect('The register shift could not be saved.', 'error');
}
