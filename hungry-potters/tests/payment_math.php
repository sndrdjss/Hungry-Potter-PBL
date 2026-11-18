<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/payments.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function row(string $kind, string $amount, ?string $refundKind = null): array
{
    return ['kind' => $kind, 'amount' => $amount, 'refund_kind' => $refundKind];
}

check(payment_parse_cents('12.34') === 1234, 'Money parser lost cents.');
check(payment_parse_cents('0.001') === null, 'Money parser accepted fractional cents.');
check(payment_parse_signed_cents('-12.34') === -1234, 'Signed money parser failed.');

$order = ['total' => '100.00', 'status' => 'placed', 'payment_history_state' => 'tracked'];
$summary = payment_summary($order, [row('failed', '100.00'), row('payment', '40.00')]);
check($summary['status'] === 'Partially paid' && $summary['balance'] === 6000, 'Failed attempts changed a partial balance.');

$summary = payment_summary($order, [row('payment', '100.00')]);
check($summary['status'] === 'Paid' && $summary['balance'] === 0, 'Full payment did not settle the order.');

$order['total'] = '80.00';
$summary = payment_summary($order, [row('payment', '100.00')]);
check($summary['status'] === 'Refund due' && $summary['balance'] === -2000, 'Reduced order total did not create credit.');

$order['status'] = 'cancelled';
$summary = payment_summary($order, [row('payment', '100.00'), row('refund', '100.00', 'cancellation')]);
check($summary['status'] === 'Refunded' && $summary['balance'] === 0, 'Cancelled order was not settled by refund.');

$order = ['total' => '100.00', 'status' => 'completed', 'payment_history_state' => 'tracked'];
$summary = payment_summary($order, [row('payment', '100.00'), row('refund', '20.00', 'goodwill')]);
check($summary['status'] === 'Paid' && $summary['payable'] === 8000 && $summary['net'] === 8000, 'Goodwill refund created a false amount due.');

$order['payment_history_state'] = 'legacy_unknown';
$summary = payment_summary($order, []);
check($summary['status'] === 'Payment history unknown', 'Legacy order was treated as unpaid.');

echo "Payment calculations passed.\n";
