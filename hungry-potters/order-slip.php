<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_role(['admin', 'cashier']);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/payments.php';

$slipNumber = isset($_GET['slip']) && is_string($_GET['slip'])
    ? trim($_GET['slip'])
    : '';

$order = null;
if ($slipNumber !== '' && strlen($slipNumber) <= 32) {
    $orderStatement = $pdo->prepare(
        "SELECT orders.id, orders.slip_number, orders.order_number, orders.order_type,
            orders.status, orders.cancel_reason, orders.cancelled_at, orders.total,
            orders.payment_history_state,
            DATE_FORMAT(orders.created_at, '%b %e, %Y %l:%i %p') AS slip_created_at,
            orders.cashier_name,
            users.full_name AS staff_name
         FROM orders
         LEFT JOIN users ON users.id = orders.created_by
         WHERE orders.slip_number = :slip_number AND orders.order_type = 'takeout'"
    );
    $orderStatement->execute(['slip_number' => $slipNumber]);
    $order = $orderStatement->fetch() ?: null;
}

$items = [];
$paymentTransactions = [];
$paymentSummary = null;
if ($order !== null) {
    $itemsStatement = $pdo->prepare(
        'SELECT item_name, quantity, unit_price, line_total
         FROM order_items
         WHERE order_id = :order_id
         ORDER BY id'
    );
    $itemsStatement->execute(['order_id' => $order['id']]);
    $items = $itemsStatement->fetchAll();
    $paymentTransactions = payment_transactions_for_order($pdo, (int) $order['id']);
    $paymentSummary = payment_summary($order, $paymentTransactions);
} else {
    http_response_code(404);
}

$pageTitle = 'Takeout Order Slip';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f3fbf6">
    <title><?= h($pageTitle) ?> | Hungry Potters</title>
    <link rel="icon" type="image/png" href="assets/images/logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@100..900&amp;family=Work+Sans:wght@100..900&amp;display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="assets/js/tailwind-config.js"></script>
    <link href="assets/css/app.css" rel="stylesheet">
    <style>
        @media print {
            body { background: white !important; padding: 0 !important; }
            .no-print { display: none !important; }
            .slip { box-shadow: none !important; border: 0 !important; max-width: none !important; }
        }
    </style>
</head>
<body class="min-h-screen bg-surface px-4 py-10 font-body-md text-on-surface antialiased">
    <?php if ($order === null): ?>
        <main class="mx-auto max-w-xl rounded-2xl bg-surface-container-lowest p-8 text-center shadow-lg">
            <div class="mx-auto mb-5 flex h-14 w-14 items-center justify-center rounded-full bg-error-container text-error">
                <span aria-hidden="true" class="material-symbols-outlined text-[30px]">receipt_long</span>
            </div>
            <div class="mb-2 flex items-center gap-2">
                <img alt="Hungry Potters logo" class="h-10 w-10 shrink-0 object-contain" src="assets/images/logo.png">
                <p class="font-label-sm uppercase tracking-wider text-on-surface-variant">Hungry Potters</p>
            </div>
            <h1 class="mt-2 font-headline-lg font-bold text-primary">Slip not found</h1>
            <p class="mt-2 font-body-md text-on-surface-variant">We couldn't find that takeout order slip. Check the slip number and try again.</p>
            <a class="mt-6 inline-flex min-h-12 items-center rounded-lg bg-primary px-5 font-body-md-bold text-on-primary" href="orders.php">Back to Orders</a>
        </main>
    <?php else: ?>
    <main class="slip mx-auto max-w-xl rounded-2xl bg-surface-container-lowest p-8 shadow-lg">
        <div class="mb-6 flex items-start justify-between gap-4 border-b border-outline-variant pb-5">
            <div>
                <div class="mb-2 flex items-center gap-2">
                    <img alt="Hungry Potters logo" class="h-10 w-10 shrink-0 object-contain" src="assets/images/logo.png">
                    <p class="font-label-sm uppercase tracking-wider text-on-surface-variant">Hungry Potters</p>
                </div>
                <h1 class="mt-1 font-headline-lg font-bold text-primary">Takeout Order Slip</h1>
                <p class="mt-2 font-label-md text-on-surface-variant"><?= h($order['slip_created_at']) ?></p>
            </div>
            <div class="rounded-xl bg-secondary-container px-4 py-3 text-center text-on-secondary-container">
                <span class="block font-label-sm uppercase tracking-wider">Slip #</span>
                <strong class="font-headline-md"><?= h($order['slip_number']) ?></strong>
            </div>
        </div>
        <?php if ($order['status'] === 'cancelled'): ?>
            <section aria-label="Cancelled order" class="mb-5 rounded-xl border-2 border-error bg-error-container p-4 text-on-error-container">
                <div class="flex items-center gap-2 font-headline-md font-bold tracking-wider">
                    <span class="material-symbols-outlined">cancel</span>
                    <span>CANCELLED</span>
                </div>
                <p class="mt-2 break-words font-body-md text-body-md"><strong>Reason:</strong> <?= h($order['cancel_reason'] ?: 'No reason recorded.') ?></p>
                <?php if ($order['cancelled_at']): ?>
                    <p class="mt-1 font-label-sm text-label-sm"><?= h(date('M j, Y g:i A', strtotime((string) $order['cancelled_at']))) ?></p>
                <?php endif; ?>
            </section>
        <?php endif; ?>
        <div class="mb-5 flex items-center justify-between gap-4">
            <span class="font-label-md text-on-surface-variant">Cashier</span>
            <strong><?= h($order['cashier_name'] ?: $order['staff_name'] ?: 'Staff') ?></strong>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-left">
                <thead>
                    <tr class="border-y border-outline-variant font-label-sm uppercase tracking-wider text-on-surface-variant">
                        <th class="py-3 pr-2">Item</th>
                        <th class="py-3 px-2 text-center">Qty</th>
                        <th class="py-3 px-2 text-right">Price</th>
                        <th class="py-3 pl-2 text-right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr class="border-b border-surface-container-high">
                            <td class="py-3 pr-2 font-body-md-bold"><?= h($item['item_name']) ?></td>
                            <td class="py-3 px-2 text-center"><?= h($item['quantity']) ?></td>
                            <td class="py-3 px-2 text-right">₱<?= h(number_format((float) $item['unit_price'], 2)) ?></td>
                            <td class="py-3 pl-2 text-right">₱<?= h(number_format((float) $item['line_total'], 2)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="mt-5 flex justify-between border-t border-outline-variant pt-4 font-headline-md font-bold">
            <span>Total</span>
            <span>₱<?= h(number_format((float) $order['total'], 2)) ?></span>
        </div>
        <?php if ($paymentSummary !== null): ?>
            <section class="mt-5 border-t border-outline-variant pt-4" aria-label="Payment summary">
                <div class="flex justify-between gap-3 font-body-md-bold"><span>Payment status</span><strong><?= h($paymentSummary['status']) ?></strong></div>
                <div class="mt-2 flex justify-between gap-3 text-on-surface-variant"><span>Collected</span><span>₱<?= h(payment_format($paymentSummary['paid'])) ?></span></div>
                <div class="mt-1 flex justify-between gap-3 text-on-surface-variant"><span>Refunded</span><span>₱<?= h(payment_format($paymentSummary['refunded'])) ?></span></div>
                <?php if ($order['payment_history_state'] !== 'legacy_unknown' && $paymentSummary['balance'] !== 0): ?>
                    <div class="mt-2 flex justify-between gap-3 font-body-md-bold"><span><?= $paymentSummary['balance'] < 0 ? 'Refund due' : 'Amount due' ?></span><span>₱<?= h(payment_format(abs($paymentSummary['balance']))) ?></span></div>
                <?php endif; ?>
                <?php foreach ($paymentTransactions as $transaction): ?>
                    <?php if ($transaction['kind'] === 'failed') continue; ?>
                    <p class="mt-1 font-label-sm text-on-surface-variant"><?= h(ucfirst((string) $transaction['kind'])) ?> · <?= h(ucfirst((string) $transaction['method'])) ?> · ₱<?= h($transaction['amount']) ?></p>
                <?php endforeach; ?>
                <?php if ($paymentSummary['status'] !== 'Paid' && $paymentSummary['status'] !== 'Refunded'): ?>
                    <p class="mt-3 font-label-sm text-on-surface-variant">This order slip does not confirm full payment.</p>
                <?php endif; ?>
            </section>
        <?php endif; ?>
        <div class="no-print mt-7 flex flex-wrap gap-3">
            <a class="inline-flex min-h-11 items-center rounded-lg bg-primary px-4 font-body-md-bold text-on-primary" href="payments.php?order_id=<?= h($order['id']) ?>">Payments</a>
            <button class="min-h-12 rounded-lg bg-primary px-5 font-body-md-bold text-on-primary" onclick="window.print()" type="button">Print slip</button>
            <a class="flex min-h-12 items-center rounded-lg bg-surface-container px-5 font-body-md-bold text-primary" href="new-order.php">Back to New Order</a>
        </div>
    </main>
    <?php endif; ?>
</body>
</html>
