<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_login();

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/payments.php';

$today = date('Y-m-d');
$flashMessage = $_SESSION['dashboard_message'] ?? '';
unset($_SESSION['dashboard_message']);
$flashType = $_SESSION['dashboard_message_type'] ?? 'success';
unset($_SESSION['dashboard_message_type']);

$salesStatement = $pdo->prepare(
    "SELECT COALESCE(SUM(CASE WHEN kind = 'payment' THEN amount WHEN kind = 'refund' THEN -amount ELSE 0 END), 0)
     FROM payment_transactions
     WHERE source = 'live' AND DATE(occurred_at) = :today"
);
$salesStatement->execute(['today' => $today]);
$todayCollected = payment_parse_signed_cents((string) $salesStatement->fetchColumn()) ?? 0;
$paymentBalances = payment_open_balances($pdo);

$countStatement = $pdo->prepare(
    'SELECT status, COUNT(*) AS order_count
     FROM orders
     WHERE DATE(created_at) = :today
     GROUP BY status'
);
$countStatement->execute(['today' => $today]);
$orderCounts = [
    'placed' => 0,
    'preparing' => 0,
    'completed' => 0,
    'cancelled' => 0,
];
foreach ($countStatement->fetchAll() as $row) {
    $orderCounts[$row['status']] = (int) $row['order_count'];
}

$activeCount = $orderCounts['placed'] + $orderCounts['preparing'];
$nonCancelledOrderCount = $activeCount + $orderCounts['completed'];
$lowStockStatement = $pdo->query(
    'SELECT id, name, unit, current_stock, low_stock_level
     FROM inventory_items
     WHERE is_active = 1 AND current_stock <= low_stock_level
     ORDER BY current_stock ASC, name ASC'
);
$lowStockItems = $lowStockStatement->fetchAll();

$menuStatement = $pdo->query(
    "SELECT
        COUNT(*) AS active_menu_count,
        SUM(
            CASE
                WHEN menu.is_available = 1 AND EXISTS (
                    SELECT 1
                    FROM menu_recipes recipe
                    WHERE recipe.menu_item_id = menu.id
                )
                AND NOT EXISTS (
                    SELECT 1
                    FROM menu_recipes recipe
                    LEFT JOIN inventory_items stock
                        ON stock.id = recipe.inventory_item_id
                    WHERE recipe.menu_item_id = menu.id
                        AND (
                            stock.id IS NULL
                            OR stock.is_active = 0
                            OR stock.current_stock < recipe.quantity_required
                        )
                ) THEN 1
                ELSE 0
            END
        ) AS available_menu_count
     FROM menu_items menu
     WHERE menu.is_active = 1"
);
$menuCounts = $menuStatement->fetch() ?: [
    'active_menu_count' => 0,
    'available_menu_count' => 0,
];

$activeOrdersStatement = $pdo->prepare(
    "SELECT
        orders.id,
        orders.order_number,
        orders.queue_number,
        orders.order_type,
        orders.location,
        orders.status,
        orders.created_at,
        GROUP_CONCAT(
            CONCAT(order_items.quantity, '× ', order_items.item_name)
            ORDER BY order_items.id
            SEPARATOR ', '
        ) AS items_summary
     FROM orders
     LEFT JOIN order_items ON order_items.order_id = orders.id
     WHERE orders.status IN ('placed', 'preparing')
     GROUP BY orders.id
     ORDER BY orders.created_at ASC
     LIMIT 5"
);
$activeOrdersStatement->execute();
$activeOrders = $activeOrdersStatement->fetchAll();

$recentOrdersStatement = $pdo->prepare(
    "SELECT
        orders.id,
        orders.order_number,
        orders.queue_number,
        orders.order_type,
        orders.location,
        orders.status,
        orders.created_at,
        GROUP_CONCAT(
            CONCAT(order_items.quantity, '× ', order_items.item_name)
            ORDER BY order_items.id
            SEPARATOR ', '
        ) AS items_summary
     FROM orders
     LEFT JOIN order_items ON order_items.order_id = orders.id
     WHERE DATE(orders.created_at) = :today
     GROUP BY orders.id
     ORDER BY orders.created_at DESC
     LIMIT 5"
);
$recentOrdersStatement->execute(['today' => $today]);
$recentOrders = $recentOrdersStatement->fetchAll();

$orderTypeLabels = [
    'dine_in' => 'Dine-In',
    'takeout' => 'Takeout',
    'delivery' => 'Delivery',
];
$statusLabels = [
    'placed' => 'Placed',
    'preparing' => 'Preparing',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
];

$pageTitle = 'Dashboard';
$currentPage = 'dashboard';
require __DIR__ . '/includes/header.php';
?>
<div class="flex w-full flex-col gap-6">
    <?php if ($flashMessage !== ''): ?>
        <div class="rounded-xl border p-4 font-body-md text-body-md <?= $flashType === 'error' ? 'border-error/30 bg-error-container text-on-error-container' : 'border-primary-fixed bg-primary-fixed text-on-primary-fixed-variant' ?>" role="status"><?= h($flashMessage) ?></div>
    <?php endif; ?>
    <section class="flex flex-col justify-between gap-4 pt-1 sm:flex-row sm:items-center">
        <div>
            <div class="mb-1 flex items-center gap-2 font-label-md text-label-md uppercase tracking-wider text-on-surface-variant">
                <span>Operations Console</span>
                <span class="h-1.5 w-1.5 rounded-full bg-secondary-container"></span>
                <span class="font-body-md-bold text-secondary">Live Service</span>
            </div>
            <div class="flex flex-wrap items-baseline gap-3">
                <h2 class="font-headline-xl text-headline-xl font-bold tracking-tight text-primary">Staff Service Desk</h2>
                <span class="rounded-full bg-surface-container-highest px-2 py-1 font-label-md text-label-md text-on-surface-variant">Station: POS-01</span>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a class="flex min-h-12 items-center justify-center gap-1 rounded-lg bg-primary px-5 font-body-md-bold text-body-md-bold text-on-primary shadow-md transition-colors hover:bg-primary-container" href="new-order.php">
                <span class="material-symbols-outlined text-[22px]">add</span>
                <span>+ New Order</span>
            </a>
            <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
                <form action="actions/telegram-test.php" method="post">
                    <input name="csrf_token" type="hidden" value="<?= h(csrf_token()) ?>">
                    <button class="flex min-h-12 items-center justify-center gap-2 rounded-lg border border-outline-variant bg-surface-container-lowest px-4 font-body-md-bold text-body-md-bold text-primary transition-colors hover:bg-surface-container" type="submit">
                        <span class="material-symbols-outlined text-[20px]">send</span>
                        <span>Send test message</span>
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </section>

    <section aria-label="Today's operations" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <article class="flex flex-col justify-between rounded-xl bg-surface-container-lowest p-6 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="font-label-md text-label-md font-semibold uppercase tracking-wider text-on-surface-variant">Active Orders</span>
                <span class="h-2.5 w-2.5 rounded-full bg-secondary-container"></span>
            </div>
            <div class="my-2 font-mono-metric text-[32px] font-bold leading-none text-primary"><?= h($activeCount) ?></div>
            <div class="font-body-md text-body-md text-on-surface-variant">
                <?= h($orderCounts['placed']) ?> awaiting &bull; <?= h($orderCounts['preparing']) ?> in prep
            </div>
        </article>
        <article class="flex flex-col justify-between rounded-xl bg-surface-container-lowest p-6 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="font-label-md text-label-md font-semibold uppercase tracking-wider text-on-surface-variant">Completed Today</span>
                <span class="material-symbols-outlined text-[20px] text-tertiary-container">task_alt</span>
            </div>
            <div class="my-2 font-mono-metric text-[32px] font-bold leading-none text-primary"><?= h($orderCounts['completed']) ?></div>
            <div class="flex items-center gap-1 font-body-md text-body-md text-on-surface-variant">
                <span class="material-symbols-outlined text-[16px] text-tertiary-container">sync</span>
                <span>Inventory tracked per order</span>
            </div>
        </article>
        <article class="flex flex-col justify-between rounded-xl bg-surface-container-lowest p-6 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="font-label-md text-label-md font-semibold uppercase tracking-wider text-on-surface-variant">Menu Available</span>
                <span class="material-symbols-outlined text-[20px] text-on-surface-variant">restaurant_menu</span>
            </div>
            <div class="my-2 font-mono-metric text-[32px] font-bold leading-none text-primary">
                <?= h((int) $menuCounts['available_menu_count']) ?>
                <span class="font-headline-md text-headline-md font-normal text-on-surface-variant">/ <?= h((int) $menuCounts['active_menu_count']) ?></span>
            </div>
            <div class="font-body-md text-body-md text-on-surface-variant">Based on available ingredient stock</div>
        </article>
        <article class="flex flex-col justify-between rounded-xl bg-surface-container-lowest p-6 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="font-label-md text-label-md font-semibold uppercase tracking-wider text-on-surface-variant">Stock Alerts</span>
                <span class="material-symbols-outlined text-[20px] text-error">warning</span>
            </div>
            <div class="my-2 font-mono-metric text-[32px] font-bold leading-none text-error"><?= h(count($lowStockItems)) ?></div>
            <div class="font-body-md text-body-md text-on-surface-variant">Low stock or out of stock</div>
        </article>
    </section>

    <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-12">
        <div class="flex flex-col gap-6 lg:col-span-8">
            <section class="flex flex-col gap-4 rounded-xl bg-surface-container-lowest p-6 shadow-sm">
                <div class="flex flex-col justify-between gap-2 sm:flex-row sm:items-center">
                    <div class="flex items-center gap-2">
                        <span class="h-3 w-3 rounded-full bg-secondary-container"></span>
                        <h3 class="font-headline-md text-headline-md font-bold text-primary">Active Orders (<?= h(count($activeOrders)) ?> in queue)</h3>
                    </div>
                    <a class="font-label-md text-label-md text-primary hover:underline" href="orders.php?status=active">View all orders</a>
                </div>
                <?php if ($activeOrders === []): ?>
                    <p class="rounded-xl bg-surface-container-low p-4 font-body-md text-body-md text-on-surface-variant">There are no active orders right now.</p>
                <?php else: ?>
                    <div class="flex flex-col gap-2">
                        <?php foreach ($activeOrders as $order): ?>
                            <?php
                            $orderStatus = (string) $order['status'];
                            $orderType = (string) $order['order_type'];
                            ?>
                            <article class="flex flex-col justify-between gap-4 rounded-xl <?= $orderStatus === 'preparing' ? 'bg-surface-container' : 'bg-surface-container-low' ?> p-4 transition-colors hover:bg-surface-container md:flex-row md:items-center">
                                <div class="flex min-w-0 items-start gap-4">
                                    <div class="flex h-14 min-w-[72px] flex-col items-center justify-center rounded-lg bg-surface-container-highest text-primary">
                                        <span class="font-label-sm text-label-sm uppercase text-on-surface-variant">Queue</span>
                                        <span class="font-headline-sm text-headline-sm font-bold leading-none">Q-<?= h(str_pad((string) $order['queue_number'], 3, '0', STR_PAD_LEFT)) ?></span>
                                    </div>
                                    <div class="flex min-w-0 flex-col">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="font-body-md-bold text-body-md-bold text-on-surface"><?= h($order['order_number']) ?></span>
                                            <span class="inline-flex items-center gap-1 rounded-full bg-surface-container-highest px-2 py-0.5 font-label-sm text-label-sm font-semibold text-on-surface-variant">
                                                <span class="material-symbols-outlined text-[14px]"><?= $orderStatus === 'preparing' ? 'local_fire_department' : 'inventory' ?></span>
                                                <?= h($statusLabels[$orderStatus] ?? $orderStatus) ?>
                                            </span>
                                        </div>
                                        <div class="mt-1 break-words font-body-lg text-body-lg text-on-surface"><?= h($order['items_summary'] ?: 'No items') ?></div>
                                        <span class="mt-0.5 font-label-sm text-label-sm text-on-surface-variant">
                                            <?= h($orderTypeLabels[$orderType] ?? $orderType) ?><?= $order['location'] ? ' • ' . h($order['location']) : '' ?>
                                        </span>
                                    </div>
                                </div>
                                <a class="flex min-h-12 shrink-0 items-center justify-center gap-1 self-end rounded-lg bg-surface-container-highest px-4 font-body-md-bold text-body-md-bold text-primary transition-colors hover:bg-surface-container md:self-center" href="orders.php?id=<?= h($order['id']) ?>">
                                    <span>View Details</span>
                                    <span class="material-symbols-outlined text-[18px]">chevron_right</span>
                                </a>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="flex flex-col gap-4 rounded-xl bg-surface-container-lowest p-6 shadow-sm">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-[22px] text-tertiary-container">check_circle</span>
                        <h3 class="font-headline-md text-headline-md font-bold text-primary">Recent Orders</h3>
                    </div>
                    <span class="font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">Today</span>
                </div>
                <?php if ($recentOrders === []): ?>
                    <p class="rounded-xl bg-surface-container-low p-4 font-body-md text-body-md text-on-surface-variant">No orders have been placed today.</p>
                <?php else: ?>
                    <div class="flex flex-col gap-2">
                        <?php foreach ($recentOrders as $order): ?>
                            <?php
                            $recentStatus = (string) $order['status'];
                            $recentOrderType = (string) $order['order_type'];
                            ?>
                            <a class="flex flex-col gap-2 rounded-xl bg-surface-container-low p-4 transition-colors hover:bg-surface-container sm:flex-row sm:items-center sm:justify-between" href="orders.php?id=<?= h($order['id']) ?>">
                                <div class="flex min-w-0 flex-col">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="rounded bg-surface-container px-2 py-1 font-body-md-bold text-body-md-bold text-primary">Q-<?= h(str_pad((string) $order['queue_number'], 3, '0', STR_PAD_LEFT)) ?></span>
                                        <span class="font-body-md-bold text-body-md-bold text-on-surface"><?= h($order['order_number']) ?></span>
                                        <span class="rounded-full bg-surface-container-highest px-2 py-1 font-label-sm text-label-sm font-semibold text-on-surface-variant"><?= h($statusLabels[$recentStatus] ?? $recentStatus) ?></span>
                                    </div>
                                    <span class="mt-1 break-words font-body-md text-body-md text-on-surface"><?= h($order['items_summary'] ?: 'No items') ?></span>
                                    <span class="font-label-sm text-label-sm text-on-surface-variant"><?= h($orderTypeLabels[$recentOrderType] ?? $recentOrderType) ?><?= $order['location'] ? ' • ' . h($order['location']) : '' ?></span>
                                </div>
                                <time class="shrink-0 font-label-md text-label-md text-on-surface-variant" datetime="<?= h(date(DATE_ATOM, strtotime((string) $order['created_at']))) ?>"><?= h(date('g:i A', strtotime((string) $order['created_at']))) ?></time>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <div class="flex flex-col gap-6 lg:col-span-4">
            <section class="flex flex-col gap-4 rounded-xl bg-surface-container-lowest p-6 shadow-sm">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-[22px] text-error">notification_important</span>
                        <h3 class="font-headline-md text-headline-md font-bold text-primary">Stock Alerts</h3>
                    </div>
                    <span class="rounded-full bg-error-container px-2 py-1 font-label-sm text-label-sm font-bold text-on-error-container"><?= h(count($lowStockItems)) ?> Alerts</span>
                </div>
                <p class="font-body-md text-body-md text-on-surface-variant">Items at or below their low-stock level.</p>
                <?php if ($lowStockItems === []): ?>
                    <p class="rounded-xl bg-surface-container-low p-4 font-body-md text-body-md text-on-surface-variant">All tracked inventory is above its alert level.</p>
                <?php else: ?>
                    <?php foreach ($lowStockItems as $item): ?>
                        <?php $isOutOfStock = (float) $item['current_stock'] <= 0; ?>
                        <div class="flex flex-col gap-2 rounded-xl p-4 <?= $isOutOfStock ? 'bg-error-container text-on-error-container' : 'bg-secondary-fixed text-on-secondary-fixed' ?>">
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-body-lg-bold text-body-lg-bold"><?= h($item['name']) ?></span>
                                <span class="rounded-full bg-surface-container-lowest px-2 py-1 font-label-sm text-label-sm font-bold <?= $isOutOfStock ? 'text-error' : 'text-secondary' ?>">
                                    <?= $isOutOfStock ? 'Out of Stock' : 'Low Stock' ?>
                                </span>
                            </div>
                            <div class="flex items-baseline justify-between gap-2">
                                <span class="font-headline-lg text-headline-lg font-bold"><?= h($item['current_stock']) ?> <?= h($item['unit']) ?></span>
                                <span class="font-label-md text-label-md">Threshold: <?= h($item['low_stock_level']) ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <a class="font-label-md text-label-md font-semibold text-primary hover:underline" href="inventory.php">Manage inventory</a>
            </section>

            <section class="flex flex-col gap-4 rounded-xl bg-surface-container-lowest p-6 shadow-sm">
                <div class="flex items-center justify-between">
                    <h3 class="font-headline-sm text-headline-sm font-bold text-primary">Today's Summary</h3>
                    <span class="material-symbols-outlined text-[20px] text-on-surface-variant">point_of_sale</span>
                </div>
                <div class="flex items-center justify-between py-1 font-body-md text-body-md">
                    <span class="text-on-surface-variant">Orders (excluding cancelled)</span>
                    <span class="font-body-md-bold text-on-surface"><?= h($nonCancelledOrderCount) ?></span>
                </div>
                <div class="flex items-center justify-between py-1 font-body-md text-body-md">
                    <span class="text-on-surface-variant">Completed orders</span>
                    <span class="font-body-md-bold text-on-surface"><?= h($orderCounts['completed']) ?></span>
                </div>
                <div class="flex items-center justify-between py-1 font-body-md text-body-md">
                    <span class="text-on-surface-variant">Cancelled orders</span>
                    <span class="font-body-md-bold text-on-surface"><?= h($orderCounts['cancelled']) ?></span>
                </div>
                <div class="flex items-center justify-between rounded-lg bg-surface-container-low p-3">
                    <span class="font-label-sm text-label-sm font-bold uppercase tracking-wider text-on-surface-variant">Net Collected Today</span>
                    <span class="font-headline-sm text-headline-sm font-bold text-primary">₱<?= h(payment_format($todayCollected)) ?></span>
                </div>
                <div class="flex items-center justify-between py-1 font-body-md text-body-md">
                    <span class="text-on-surface-variant">Customer balances due</span>
                    <span class="font-body-md-bold text-on-surface">₱<?= h(payment_format($paymentBalances['amount_due'])) ?></span>
                </div>
                <div class="flex items-center justify-between py-1 font-body-md text-body-md">
                    <span class="text-on-surface-variant">Refunds due</span>
                    <span class="font-body-md-bold text-error">₱<?= h(payment_format($paymentBalances['refund_due'])) ?></span>
                </div>
            </section>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
