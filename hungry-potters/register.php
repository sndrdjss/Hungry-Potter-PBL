<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_role(['admin', 'cashier']);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/payments.php';
refresh_account_for_money_actions($pdo);
require_role(['admin', 'cashier']);

$shift = payment_shift($pdo);
$totals = $shift ? payment_shift_totals($pdo, (int) $shift['id']) : ['cash' => 0, 'card' => 0, 'ewallet' => 0];
$expected = $shift ? (payment_parse_cents((string) $shift['opening_float']) ?? 0) + $totals['cash'] : 0;
$history = $pdo->query(
    'SELECT shifts.*, opener.full_name AS opener_name, closer.full_name AS closer_name
     FROM register_shifts shifts
     JOIN users opener ON opener.id = shifts.opened_by
     LEFT JOIN users closer ON closer.id = shifts.closed_by
     WHERE shifts.register_id = 1
     ORDER BY shifts.id DESC LIMIT 20'
)->fetchAll();
$exceptions = payment_open_balances($pdo);
$flash = (string) ($_SESSION['shift_message'] ?? '');
$flashType = (string) ($_SESSION['shift_message_type'] ?? 'success');
unset($_SESSION['shift_message'], $_SESSION['shift_message_type']);
$pageTitle = 'Register';
$currentPage = 'register';
require __DIR__ . '/includes/header.php';
?>
<div class="mx-auto flex max-w-5xl flex-col gap-6 py-4">
    <div>
        <p class="font-label-sm uppercase tracking-wider text-on-surface-variant">Shared cash drawer</p>
        <h2 class="font-headline-lg font-bold text-primary">POS-01 register</h2>
    </div>
    <?php if ($flash !== ''): ?>
        <div class="rounded-xl p-4 <?= $flashType === 'error' ? 'bg-error-container text-on-error-container' : 'bg-primary-fixed text-on-primary-fixed-variant' ?>" role="status"><?= h($flash) ?></div>
    <?php endif; ?>
    <?php if ($shift): ?>
        <section class="rounded-2xl bg-surface-container-lowest p-5 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h3 class="font-headline-sm font-bold text-primary">Shift #<?= h($shift['id']) ?> · Open</h3>
                <span class="font-label-md text-on-surface-variant">Opened <?= h(date('M j, Y g:i A', strtotime((string) $shift['opened_at']))) ?> by <?= h($shift['opener_name']) ?></span>
            </div>
            <div class="mt-5 grid gap-4 sm:grid-cols-5">
                <div><span class="block font-label-sm text-on-surface-variant">Opening float</span><strong class="font-headline-sm">₱<?= h($shift['opening_float']) ?></strong></div>
                <div><span class="block font-label-sm text-on-surface-variant">Net cash</span><strong class="font-headline-sm">₱<?= h(payment_format($totals['cash'])) ?></strong></div>
                <div><span class="block font-label-sm text-on-surface-variant">Expected drawer</span><strong class="font-headline-sm">₱<?= h(payment_format($expected)) ?></strong></div>
                <div><span class="block font-label-sm text-on-surface-variant">Net card</span><strong class="font-headline-sm">₱<?= h(payment_format($totals['card'])) ?></strong></div>
                <div><span class="block font-label-sm text-on-surface-variant">Net e-wallet</span><strong class="font-headline-sm">₱<?= h(payment_format($totals['ewallet'])) ?></strong></div>
            </div>
        </section>
    <?php else: ?>
        <section class="rounded-2xl bg-secondary-container p-5 text-on-secondary-container">
            <h3 class="font-headline-sm font-bold">No open shift</h3>
            <p class="mt-1">A cashier must open POS-01 before live payments or refunds can be recorded.</p>
        </section>
    <?php endif; ?>
    <section class="rounded-2xl bg-surface-container-lowest p-5 shadow-sm">
        <h3 class="font-headline-sm font-bold text-primary">Open balances across tracked orders</h3>
        <div class="mt-3 grid gap-4 sm:grid-cols-2">
            <p><span class="block font-label-sm text-on-surface-variant">Customer amount due · <?= h($exceptions['unpaid_orders']) ?> orders</span><strong class="font-headline-sm">₱<?= h(payment_format($exceptions['amount_due'])) ?></strong></p>
            <p><span class="block font-label-sm text-on-surface-variant">Refunds due · <?= h($exceptions['refund_orders']) ?> orders</span><strong class="font-headline-sm text-error">₱<?= h(payment_format($exceptions['refund_due'])) ?></strong></p>
        </div>
        <p class="mt-3 font-label-sm text-on-surface-variant">These balances can carry into another shift. The drawer is reconciled from transactions recorded during this shift.</p>
    </section>
    <?php if (!$shift && ($_SESSION['role'] ?? '') === 'cashier'): ?>
        <form action="actions/shift-save.php" method="post" class="rounded-2xl bg-surface-container-lowest p-5 shadow-sm">
            <h3 class="font-headline-sm font-bold text-primary">Open shift</h3>
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="open">
            <label class="mt-4 block max-w-xs font-label-md">Opening cash float (₱)<input class="mt-1 h-11 w-full rounded-lg bg-surface-container px-3" name="opening_float" type="number" min="0" step="0.01" value="0.00" required></label>
            <button class="mt-4 min-h-11 rounded-lg bg-primary px-5 font-body-md-bold text-on-primary" type="submit">Open POS-01</button>
        </form>
    <?php endif; ?>
    <?php if ($shift && ($_SESSION['role'] ?? '') === 'admin'): ?>
        <form action="actions/shift-save.php" method="post" class="rounded-2xl bg-surface-container-lowest p-5 shadow-sm">
            <h3 class="font-headline-sm font-bold text-primary">Close and reconcile shift</h3>
            <p class="mt-1 font-label-md text-on-surface-variant">Count the actual drawer cash. A reason is required if it differs from ₱<?= h(payment_format($expected)) ?>.</p>
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="close">
            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <label class="font-label-md">Counted cash (₱)<input class="mt-1 h-11 w-full rounded-lg bg-surface-container px-3" name="counted_cash" type="number" min="0" step="0.01" value="<?= h(payment_sql_amount($expected)) ?>" required></label>
                <label class="font-label-md">Variance note<input class="mt-1 h-11 w-full rounded-lg bg-surface-container px-3" name="close_note" maxlength="255" placeholder="Required if cash differs"></label>
            </div>
            <button class="mt-4 min-h-11 rounded-lg bg-primary px-5 font-body-md-bold text-on-primary" type="submit">Close shift</button>
        </form>
    <?php endif; ?>
    <section class="rounded-2xl bg-surface-container-lowest p-5 shadow-sm">
        <h3 class="font-headline-sm font-bold text-primary">Recent shifts</h3>
        <?php if ($history === []): ?><p class="mt-3 text-on-surface-variant">No shifts recorded yet.</p><?php endif; ?>
        <div class="mt-3 divide-y divide-surface-container-high">
            <?php foreach ($history as $entry): ?>
                <?php $entryTotals = payment_shift_totals($pdo, (int) $entry['id']); ?>
                <div class="flex flex-wrap items-start justify-between gap-3 py-3">
                    <div>
                        <p class="font-body-md-bold text-primary">Shift #<?= h($entry['id']) ?> · <?= $entry['closed_at'] ? 'Closed' : 'Open' ?></p>
                        <p class="font-label-sm text-on-surface-variant"><?= h($entry['opened_at']) ?> · <?= h($entry['opener_name']) ?><?= $entry['closed_at'] ? ' → ' . h($entry['closed_at']) . ' · ' . h($entry['closer_name']) : '' ?></p>
                        <p class="font-label-sm text-on-surface-variant">Cash ₱<?= h(payment_format($entryTotals['cash'])) ?> · Card ₱<?= h(payment_format($entryTotals['card'])) ?> · E-wallet ₱<?= h(payment_format($entryTotals['ewallet'])) ?></p>
                        <?php if ($entry['close_note']): ?><p class="font-label-sm text-on-surface-variant">Note: <?= h($entry['close_note']) ?></p><?php endif; ?>
                    </div>
                    <?php if ($entry['closed_at']): ?><strong class="font-body-md-bold text-primary">Variance ₱<?= h($entry['variance']) ?></strong><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
