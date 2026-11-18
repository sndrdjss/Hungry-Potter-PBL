<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role(['admin', 'cashier']);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/payments.php';
refresh_account_for_money_actions($pdo);
require_role(['admin', 'cashier']);

function payment_redirect(int $orderId, string $message, string $type): never
{
    $_SESSION['payment_message'] = $message;
    $_SESSION['payment_message_type'] = $type;
    header('Location: ../payments.php?order_id=' . $orderId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

$orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
$orderId = $orderId === false || $orderId === null ? 0 : (int) $orderId;
if (!verify_csrf_token(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    payment_redirect($orderId, 'Invalid request token. Refresh and try again.', 'error');
}
$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
$isHistorical = in_array($action, ['historical_payment', 'historical_refund'], true);
$isRefund = in_array($action, ['refund', 'historical_refund'], true);
if ($orderId < 1 || !in_array($action, ['payment', 'failed', 'refund', 'historical_payment', 'historical_refund', 'reconcile'], true)) {
    payment_redirect($orderId, 'Choose a valid payment action.', 'error');
}
if (($isHistorical || $isRefund || $action === 'reconcile') && !$isAdmin) {
    payment_redirect($orderId, 'Only an admin can perform this action.', 'error');
}

$requestKey = $_POST['request_key'] ?? null;
if (!is_string($requestKey) || !preg_match('/^[a-f0-9]{32}$/D', $requestKey)) {
    payment_redirect($orderId, 'Refresh the page and try again.', 'error');
}
$method = is_string($_POST['method'] ?? null) ? $_POST['method'] : '';
$amount = payment_parse_cents($_POST['amount'] ?? null);
$reference = is_string($_POST['reference'] ?? null) ? trim($_POST['reference']) : '';
$reason = is_string($_POST['reason'] ?? null) ? trim($_POST['reason']) : '';
$tendered = payment_parse_cents($_POST['tendered_amount'] ?? null);
if ($action !== 'reconcile' && (
    !in_array($method, ['cash', 'card', 'ewallet'], true)
    || $amount === null || $amount <= 0
    || strlen($reference) > 100 || strlen($reason) > 255
)) {
    payment_redirect($orderId, 'Enter a valid method and amount.', 'error');
}
if ($action !== 'reconcile' && $method !== 'cash' && $action !== 'failed' && $reference === '') {
    payment_redirect($orderId, 'Enter the terminal or e-wallet reference.', 'error');
}
if (($isRefund || $action === 'failed' || $isHistorical || $action === 'reconcile') && $reason === '') {
    payment_redirect($orderId, 'Enter a reason or audit note.', 'error');
}
if ($action === 'reconcile' && strlen($reason) > 225) {
    payment_redirect($orderId, 'Keep the reconciliation note under 225 characters.', 'error');
}
if ($action === 'failed' && $method === 'cash') {
    payment_redirect($orderId, 'Failed attempts are for card or e-wallet payments.', 'error');
}
if ($action === 'payment' && $method === 'cash' && ($tendered === null || $tendered < $amount)) {
    payment_redirect($orderId, 'Cash tendered must cover the payment amount.', 'error');
}

$occurredAt = null;
if ($isHistorical) {
    $input = $_POST['occurred_at'] ?? null;
    $date = is_string($input) ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $input) : false;
    if (!$date || $date->format('Y-m-d\TH:i') !== $input || $date > new DateTimeImmutable()) {
        payment_redirect($orderId, 'Enter a valid past payment date and time.', 'error');
    }
    $occurredAt = $date->format('Y-m-d H:i:s');
}

try {
    $pdo->beginTransaction();
    $shiftId = null;
    if (!$isHistorical && $action !== 'reconcile') {
        $register = $pdo->query('SELECT current_shift_id FROM registers WHERE id = 1 FOR UPDATE')->fetch();
        $shiftId = $register && $register['current_shift_id'] !== null ? (int) $register['current_shift_id'] : null;
        if ($shiftId === null) {
            throw new DomainException('Open the POS-01 register shift before recording a payment.');
        }
        $shiftStatement = $pdo->prepare('SELECT id FROM register_shifts WHERE id = :id AND closed_at IS NULL FOR UPDATE');
        $shiftStatement->execute(['id' => $shiftId]);
        if (!$shiftStatement->fetch()) {
            throw new DomainException('The register shift is closed. Refresh and try again.');
        }
    }

    $orderStatement = $pdo->prepare('SELECT id, status, total, payment_history_state FROM orders WHERE id = :id FOR UPDATE');
    $orderStatement->execute(['id' => $orderId]);
    $order = $orderStatement->fetch();
    if (!$order) {
        throw new DomainException('Order not found.');
    }
    $duplicate = $pdo->prepare('SELECT id FROM payment_transactions WHERE request_key = :request_key');
    $duplicate->execute(['request_key' => $requestKey]);
    if ($duplicate->fetch()) {
        $pdo->rollBack();
        payment_redirect($orderId, 'This payment action was already recorded.', 'success');
    }
    if ($action === 'reconcile') {
        if ($order['payment_history_state'] !== 'legacy_unknown') {
            throw new DomainException('This order already has a reconciled payment history.');
        }
        $update = $pdo->prepare("UPDATE orders SET payment_history_state = 'reconciled' WHERE id = :id");
        $update->execute(['id' => $orderId]);
        $note = $pdo->prepare(
            "INSERT INTO order_status_history (order_id, changed_by, old_status, new_status, note)
             VALUES (:order_id, :user_id, :status, :status_again, :note)"
        );
        $note->execute([
            'order_id' => $orderId, 'user_id' => $_SESSION['user_id'],
            'status' => $order['status'], 'status_again' => $order['status'],
            'note' => 'Payment history reconciled: ' . $reason,
        ]);
        $pdo->commit();
        payment_redirect($orderId, 'Historical payment history marked reconciled.', 'success');
    }

    if ($isHistorical && $order['payment_history_state'] !== 'legacy_unknown') {
        throw new DomainException('Historical backfill is only available before an order is reconciled.');
    }
    if (!$isHistorical && $order['payment_history_state'] === 'legacy_unknown') {
        throw new DomainException('An admin must reconcile this older order before recording a live payment.');
    }
    $transactions = payment_transactions_for_order($pdo, $orderId);
    $summary = payment_summary($order, $transactions);
    $kind = $isRefund ? 'refund' : ($action === 'failed' ? 'failed' : 'payment');
    $refundOfId = null;
    $refundKind = null;
    $change = null;

    if ($kind === 'payment') {
        if ($order['status'] === 'cancelled' && !$isHistorical) {
            throw new DomainException('Cancelled orders cannot receive payments.');
        }
        $available = $isHistorical && $order['status'] === 'cancelled'
            ? $summary['total'] - $summary['paid']
            : $summary['balance'];
        if ($amount > $available) {
            throw new DomainException('The payment exceeds the remaining balance. Refresh and try again.');
        }
        if (!$isHistorical && $method === 'cash') {
            $change = $tendered - $amount;
        }
    } elseif ($kind === 'failed') {
        if ($order['status'] === 'cancelled' || $summary['balance'] <= 0 || $amount > $summary['balance']) {
            throw new DomainException('The failed attempt exceeds the current amount due.');
        }
    } else {
        $refundOfId = filter_var($_POST['refund_of_id'] ?? null, FILTER_VALIDATE_INT);
        if ($refundOfId === false || $refundOfId === null || $refundOfId < 1) {
            throw new DomainException('Choose the original payment to refund.');
        }
        $parentStatement = $pdo->prepare(
            "SELECT id, method, amount, occurred_at FROM payment_transactions
             WHERE id = :id AND order_id = :order_id AND kind = 'payment' FOR UPDATE"
        );
        $parentStatement->execute(['id' => $refundOfId, 'order_id' => $orderId]);
        $parent = $parentStatement->fetch();
        if (!$parent || $parent['method'] !== $method) {
            throw new DomainException('Refunds must use the method of the original payment.');
        }
        if ($isHistorical && $occurredAt < $parent['occurred_at']) {
            throw new DomainException('A historical refund cannot predate its original payment.');
        }
        $alreadyRefunded = 0;
        foreach ($transactions as $transaction) {
            if ($transaction['kind'] === 'refund' && (int) $transaction['refund_of_id'] === (int) $refundOfId) {
                $alreadyRefunded += payment_parse_cents((string) $transaction['amount']) ?? 0;
            }
        }
        $remainingOnPayment = (payment_parse_cents((string) $parent['amount']) ?? 0) - $alreadyRefunded;
        if ($amount > $remainingOnPayment) {
            throw new DomainException('The refund exceeds the remaining amount on that payment.');
        }
        if ($order['status'] === 'cancelled') {
            $refundKind = 'cancellation';
            if ($amount > -$summary['balance']) {
                throw new DomainException('The refund exceeds the amount due back to the customer.');
            }
        } elseif ($order['status'] === 'completed') {
            $refundKind = 'goodwill';
        } elseif ($summary['balance'] < 0) {
            $refundKind = 'order_reduction';
            if ($amount > -$summary['balance']) {
                throw new DomainException('The refund exceeds the order credit.');
            }
        } else {
            throw new DomainException('This active order has no refundable credit.');
        }
    }

    $insert = $pdo->prepare(
        'INSERT INTO payment_transactions
            (order_id, shift_id, kind, method, amount, tendered_amount, change_amount,
             reference, reason, refund_of_id, refund_kind, source, occurred_at, recorded_by, request_key)
         VALUES
            (:order_id, :shift_id, :kind, :method, :amount, :tendered, :change,
             :reference, :reason, :refund_of, :refund_kind, :source, :occurred_at, :user_id, :request_key)'
    );
    $insert->execute([
        'order_id' => $orderId,
        'shift_id' => $shiftId,
        'kind' => $kind,
        'method' => $method,
        'amount' => payment_sql_amount($amount),
        'tendered' => $change !== null ? payment_sql_amount($tendered) : null,
        'change' => $change !== null ? payment_sql_amount($change) : null,
        'reference' => $reference !== '' ? $reference : null,
        'reason' => $reason !== '' ? $reason : null,
        'refund_of' => $refundOfId,
        'refund_kind' => $refundKind,
        'source' => $isHistorical ? 'historical' : 'live',
        'occurred_at' => $occurredAt ?? date('Y-m-d H:i:s'),
        'user_id' => $_SESSION['user_id'],
        'request_key' => $requestKey,
    ]);
    $pdo->commit();
    $message = match ($kind) {
        'refund' => 'Refund recorded.',
        'failed' => 'Failed payment attempt recorded.',
        default => 'Payment recorded.',
    };
    payment_redirect($orderId, $message, 'success');
} catch (DomainException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    payment_redirect($orderId, $exception->getMessage(), 'error');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Payment action failed: ' . $exception->getMessage());
    payment_redirect($orderId, 'The payment action could not be saved. Please try again.', 'error');
}
