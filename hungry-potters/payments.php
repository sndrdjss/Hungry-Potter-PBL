<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_role(['admin', 'cashier']);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/payments.php';
refresh_account_for_money_actions($pdo);
require_role(['admin', 'cashier']);

$orderId = filter_var($_GET['order_id'] ?? null, FILTER_VALIDATE_INT);
$orderId = $orderId === false || $orderId === null ? 0 : (int) $orderId;
$orderStatement = $pdo->prepare(
    'SELECT id, order_number, slip_number, order_type, status, total, payment_history_state, created_at
     FROM orders WHERE id = :id'
);
$orderStatement->execute(['id' => $orderId]);
$order = $orderStatement->fetch();
if (!$order) {
    http_response_code(404);
    exit('Order not found.');
}
$transactions = payment_transactions_for_order($pdo, $orderId);
$summary = payment_summary($order, $transactions);
$shift = payment_shift($pdo);
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
$isLegacy = $order['payment_history_state'] === 'legacy_unknown';
$flash = (string) ($_SESSION['payment_message'] ?? '');
$flashType = (string) ($_SESSION['payment_message_type'] ?? 'success');
unset($_SESSION['payment_message'], $_SESSION['payment_message_type']);
$refundable = [];
foreach ($transactions as $transaction) {
    if ($transaction['kind'] !== 'payment') {
        continue;
    }
    $remaining = payment_parse_cents((string) $transaction['amount']) ?? 0;
    foreach ($transactions as $candidate) {
        if ($candidate['kind'] === 'refund' && (int) $candidate['refund_of_id'] === (int) $transaction['id']) {
            $remaining -= payment_parse_cents((string) $candidate['amount']) ?? 0;
        }
    }
    if ($remaining > 0) {
        $refundable[] = ['transaction' => $transaction, 'remaining' => $remaining];
    }
}
$canRefund = $isAdmin && $refundable !== [] && ($order['status'] === 'completed' || $order['status'] === 'cancelled' || $summary['balance'] < 0);
$paymentKey = bin2hex(random_bytes(16));
$failedKey = bin2hex(random_bytes(16));
$historicalKey = bin2hex(random_bytes(16));
$reconcileKey = bin2hex(random_bytes(16));
$pageTitle = 'Order Payments';
$currentPage = 'orders';
$pageScript = 'assets/js/payments.js?v=' . (string) filemtime(__DIR__ . '/assets/js/payments.js');
require __DIR__ . '/includes/header.php';
?>
<div class="mx-auto flex max-w-5xl flex-col gap-6 py-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="font-label-sm uppercase tracking-wider text-on-surface-variant">Order <?= h($order['order_number']) ?></p>
            <h2 class="font-headline-lg font-bold text-primary">Payments and refunds</h2>
        </div>
        <div class="flex flex-wrap gap-2">
            <a class="rounded-lg bg-surface-container px-4 py-2 font-body-md-bold text-primary" href="orders.php?id=<?= h($orderId) ?>">Order details</a>
            <?php if ($order['slip_number']): ?>
                <a class="rounded-lg bg-surface-container px-4 py-2 font-body-md-bold text-primary" href="order-slip.php?slip=<?= rawurlencode((string) $order['slip_number']) ?>">Takeout slip</a>
            <?php endif; ?>
            <a class="rounded-lg bg-primary px-4 py-2 font-body-md-bold text-on-primary" href="register.php">POS-01 register</a>
        </div>
    </div>
    <?php if ($flash !== ''): ?>
        <div class="rounded-xl p-4 <?= $flashType === 'error' ? 'bg-error-container text-on-error-container' : 'bg-primary-fixed text-on-primary-fixed-variant' ?>" role="status"><?= h($flash) ?></div>
    <?php endif; ?>
    <section class="rounded-2xl bg-surface-container-lowest p-5 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="font-headline-sm font-bold text-primary"><?= h($summary['status']) ?></h3>
            <span class="rounded-full bg-surface-container px-3 py-1 font-label-sm text-on-surface-variant">Kitchen: <?= h(ucfirst((string) $order['status'])) ?></span>
        </div>
        <div class="mt-4 grid gap-3 sm:grid-cols-4">
            <div><span class="block font-label-sm text-on-surface-variant">Order total</span><strong class="font-headline-sm">₱<?= h(payment_format($summary['total'])) ?></strong></div>
            <div><span class="block font-label-sm text-on-surface-variant">Payments</span><strong class="font-headline-sm">₱<?= h(payment_format($summary['paid'])) ?></strong></div>
            <div><span class="block font-label-sm text-on-surface-variant">Refunds</span><strong class="font-headline-sm">₱<?= h(payment_format($summary['refunded'])) ?></strong></div>
            <div><span class="block font-label-sm text-on-surface-variant"><?= $summary['balance'] < 0 ? 'Refund due' : 'Amount due' ?></span><strong class="font-headline-sm">₱<?= h(payment_format(abs($summary['balance']))) ?></strong></div>
        </div>
        <?php if ($isLegacy): ?>
            <p class="mt-4 rounded-lg bg-secondary-container p-3 font-label-md text-on-secondary-container">This order predates payment tracking. The balance shown above is provisional until an admin enters its history and marks it reconciled.</p>
        <?php endif; ?>
        <?php if ($summary['failed'] > 0): ?>
            <p class="mt-3 font-label-sm text-error"><?= h($summary['failed']) ?> failed digital payment attempt<?= $summary['failed'] === 1 ? '' : 's' ?> recorded.</p>
        <?php endif; ?>
    </section>

    <?php if (!$isLegacy): ?>
        <?php if ($shift === null): ?>
            <p class="rounded-xl bg-secondary-container p-4 text-on-secondary-container">Open a register shift before recording a live payment or refund. <a class="font-bold underline" href="register.php">Go to register</a></p>
        <?php else: ?>
            <p class="rounded-xl bg-primary-fixed p-4 text-on-primary-fixed-variant">POS-01 shift #<?= h($shift['id']) ?> is open. Live payments and refunds will appear in this shift.</p>
        <?php endif; ?>
        <?php if ($shift !== null && $order['status'] !== 'cancelled' && $summary['balance'] > 0): ?>
            <div class="grid gap-4 lg:grid-cols-2">
                <form action="actions/payment-save.php" method="post" class="rounded-2xl bg-surface-container-lowest p-5 shadow-sm" data-payment-entry>
                    <h3 class="font-headline-sm font-bold text-primary">Record payment</h3>
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="request_key" value="<?= h($paymentKey) ?>">
                    <input type="hidden" name="order_id" value="<?= h($orderId) ?>">
                    <input type="hidden" name="action" value="payment">
                    <div class="mt-4 grid gap-3">
                        <label class="font-label-md">Method<select class="mt-1 h-11 w-full rounded-lg bg-surface-container px-3" name="method" required data-payment-method><option value="cash">Cash</option><option value="card">Card</option><option value="ewallet">E-wallet</option></select></label>
                        <label class="font-label-md">Amount applied (₱)<input class="mt-1 h-11 w-full rounded-lg bg-surface-container px-3" name="amount" type="number" min="0.01" max="<?= h(payment_sql_amount($summary['balance'])) ?>" step="0.01" value="<?= h(payment_sql_amount($summary['balance'])) ?>" required data-payment-amount></label>
                        <label class="font-label-md">Cash tendered (₱, cash only)<input class="mt-1 h-11 w-full rounded-lg bg-surface-container px-3" name="tendered_amount" type="number" min="0" step="0.01" value="<?= h(payment_sql_amount($summary['balance'])) ?>" data-payment-tendered></label>
                        <label class="font-label-md">Terminal / e-wallet transaction ID (digital only)<input class="mt-1 h-11 w-full rounded-lg bg-surface-container px-3" name="reference" maxlength="100" autocomplete="off" data-payment-reference></label>
                    </div>
                    <p class="mt-2 font-label-sm text-on-surface-variant">Enter an external transaction ID only. Never enter a card number or verification code.</p>
                    <p class="mt-3 font-label-sm text-on-surface-variant" data-payment-change>Change: ₱0.00</p>
                    <button class="mt-4 min-h-11 rounded-lg bg-primary px-5 font-body-md-bold text-on-primary" type="submit">Record payment</button>
                </form>
                <form action="actions/payment-save.php" method="post" class="rounded-2xl bg-surface-container-lowest p-5 shadow-sm">
                    <h3 class="font-headline-sm font-bold text-primary">Record failed attempt</h3>
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="request_key" value="<?= h($failedKey) ?>">
                    <input type="hidden" name="order_id" value="<?= h($orderId) ?>">
                    <input type="hidden" name="action" value="failed">
                    <div class="mt-4 grid gap-3">
                        <label class="font-label-md">Method<select class="mt-1 h-11 w-full rounded-lg bg-surface-container px-3" name="method"><option value="card">Card</option><option value="ewallet">E-wallet</option></select></label>
                        <label class="font-label-md">Attempted amount (₱)<input class="mt-1 h-11 w-full rounded-lg bg-surface-container px-3" name="amount" type="number" min="0.01" max="<?= h(payment_sql_amount($summary['balance'])) ?>" step="0.01" value="<?= h(payment_sql_amount($summary['balance'])) ?>" required></label>
                        <label class="font-label-md">Failure reason<input class="mt-1 h-11 w-full rounded-lg bg-surface-container px-3" name="reason" maxlength="255" required></label>
                    </div>
                    <button class="mt-4 min-h-11 rounded-lg bg-surface-container px-5 font-body-md-bold text-primary" type="submit">Save failed attempt</button>
                </form>
            </div>
        <?php endif; ?>
    <?php elseif ($isAdmin): ?>
        <div class="grid gap-4 lg:grid-cols-2">
            <form action="actions/payment-save.php" method="post" class="rounded-2xl bg-surface-container-lowest p-5 shadow-sm" data-payment-entry>
                <h3 class="font-headline-sm font-bold text-primary">Backfill historical payment</h3>
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="request_key" value="<?= h($historicalKey) ?>">
                <input type="hidden" name="order_id" value="<?= h($orderId) ?>">
                <input type="hidden" name="action" value="historical_payment">
                <div class="mt-4 grid gap-3">
                    <label class="font-label-md">Method<select class="mt-1 h-11 w-full rounded-lg bg-surface-container px-3" name="method" data-payment-method><option value="cash">Cash</option><option value="card">Card</option><option value="ewallet">E-wallet</option></select></label>
                    <label class="font-label-md">Amount applied (₱)<input class="mt-1 h-11 w-full rounded-lg bg-surface-container px-3" name="amount" type="number" min="0.01" step="0.01" required></label>
                    <label class="font-label-md">Collected at<input class="mt-1 h-11 w-full rounded-lg bg-surface-container px-3" name="occurred_at" type="datetime-local" max="<?= h(date('Y-m-d\TH:i')) ?>" required></label>
                    <label class="font-label-md">Digital transaction ID<input class="mt-1 h-11 w-full rounded-lg bg-surface-container px-3" name="reference" maxlength="100" data-payment-reference></label>
                    <label class="font-label-md">Audit note<input class="mt-1 h-11 w-full rounded-lg bg-surface-container px-3" name="reason" maxlength="255" required></label>
                </div>
                <button class="mt-4 min-h-11 rounded-lg bg-primary px-5 font-body-md-bold text-on-primary" type="submit">Add historical payment</button>
            </form>
            <form action="actions/payment-save.php" method="post" class="rounded-2xl bg-surface-container-lowest p-5 shadow-sm">
                <h3 class="font-headline-sm font-bold text-primary">Finish historical review</h3>
                <p class="mt-2 font-body-md text-on-surface-variant">Confirm that all previously collected payments and refunds have been entered. This enables live payment tracking for this order.</p>
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="request_key" value="<?= h($reconcileKey) ?>">
                <input type="hidden" name="order_id" value="<?= h($orderId) ?>">
                <input type="hidden" name="action" value="reconcile">
                <label class="mt-4 block font-label-md">Audit note<input class="mt-1 h-11 w-full rounded-lg bg-surface-container px-3" name="reason" maxlength="200" required></label>
                <button class="mt-4 min-h-11 rounded-lg bg-surface-container px-5 font-body-md-bold text-primary" type="submit">Mark history reconciled</button>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($canRefund): ?>
        <section class="rounded-2xl bg-surface-container-lowest p-5 shadow-sm">
            <h3 class="font-headline-sm font-bold text-primary"><?= $isLegacy ? 'Backfill historical refund' : 'Record refund' ?></h3>
            <p class="mt-1 font-label-md text-on-surface-variant">Refund each original payment through its original method. Completed orders may receive a partial service credit.</p>
            <?php if ($isLegacy || $shift !== null): ?>
                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                    <?php foreach ($refundable as $entry): ?>
                        <?php $original = $entry['transaction']; ?>
                        <?php $refundLimit = $order['status'] === 'completed' ? $entry['remaining'] : min($entry['remaining'], max(0, -$summary['balance'])); ?>
                        <?php if ($refundLimit <= 0) continue; ?>
                        <form action="actions/payment-save.php" method="post" class="rounded-xl border border-surface-container-high p-4">
                            <p class="font-body-md-bold text-primary"><?= h(ucfirst((string) $original['method'])) ?> payment #<?= h($original['id']) ?> · ₱<?= h(payment_format($refundLimit)) ?> refundable now</p>
                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="request_key" value="<?= h(bin2hex(random_bytes(16))) ?>">
                            <input type="hidden" name="order_id" value="<?= h($orderId) ?>">
                            <input type="hidden" name="action" value="<?= $isLegacy ? 'historical_refund' : 'refund' ?>">
                            <input type="hidden" name="method" value="<?= h($original['method']) ?>">
                            <input type="hidden" name="refund_of_id" value="<?= h($original['id']) ?>">
                            <div class="mt-3 grid gap-3">
                                <label class="font-label-md">Refund amount (₱)<input class="mt-1 h-11 w-full rounded-lg bg-surface-container px-3" name="amount" type="number" min="0.01" max="<?= h(payment_sql_amount($refundLimit)) ?>" step="0.01" required></label>
                                <?php if ($isLegacy): ?><label class="font-label-md">Refunded at<input class="mt-1 h-11 w-full rounded-lg bg-surface-container px-3" name="occurred_at" type="datetime-local" max="<?= h(date('Y-m-d\TH:i')) ?>" required></label><?php endif; ?>
                                <?php if ($original['method'] !== 'cash'): ?><label class="font-label-md">Refund reference<input class="mt-1 h-11 w-full rounded-lg bg-surface-container px-3" name="reference" maxlength="100" required></label><?php endif; ?>
                                <label class="font-label-md">Reason<input class="mt-1 h-11 w-full rounded-lg bg-surface-container px-3" name="reason" maxlength="255" required></label>
                            </div>
                            <button class="mt-4 min-h-11 rounded-lg bg-error-container px-5 font-body-md-bold text-on-error-container" type="submit">Record refund</button>
                        </form>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="rounded-2xl bg-surface-container-lowest p-5 shadow-sm">
        <h3 class="font-headline-sm font-bold text-primary">Payment history</h3>
        <?php if ($transactions === []): ?>
            <p class="mt-3 text-on-surface-variant">No payment activity recorded.</p>
        <?php else: ?>
            <div class="mt-3 divide-y divide-surface-container-high">
                <?php foreach ($transactions as $transaction): ?>
                    <div class="flex flex-wrap items-start justify-between gap-3 py-3">
                        <div>
                            <p class="font-body-md-bold text-on-surface"><?= h(ucfirst((string) $transaction['kind'])) ?> · <?= h(ucfirst((string) $transaction['method'])) ?><?= $transaction['source'] === 'historical' ? ' · Historical' : '' ?></p>
                            <p class="font-label-sm text-on-surface-variant"><?= h(date('M j, Y g:i A', strtotime((string) $transaction['occurred_at']))) ?> · <?= h($transaction['staff_name'] ?: 'Staff') ?></p>
                            <?php if ($transaction['reference']): ?><p class="font-label-sm text-on-surface-variant">Reference: <?= h($transaction['reference']) ?></p><?php endif; ?>
                            <?php if ($transaction['reason']): ?><p class="font-label-sm text-on-surface-variant"><?= h($transaction['reason']) ?></p><?php endif; ?>
                            <?php if ($transaction['change_amount'] !== null): ?><p class="font-label-sm text-on-surface-variant">Tendered ₱<?= h($transaction['tendered_amount']) ?> · Change ₱<?= h($transaction['change_amount']) ?></p><?php endif; ?>
                        </div>
                        <strong class="font-headline-sm <?= $transaction['kind'] === 'refund' ? 'text-error' : 'text-primary' ?>"><?= $transaction['kind'] === 'refund' ? '−' : '' ?>₱<?= h(payment_format(payment_parse_cents((string) $transaction['amount']) ?? 0)) ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
