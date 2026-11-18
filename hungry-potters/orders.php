<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/payments.php';

$validStatuses = ['all', 'active', 'placed', 'preparing', 'completed', 'cancelled'];
$statusFilter = isset($_GET['status']) && is_string($_GET['status']) ? $_GET['status'] : 'all';
if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = 'all';
}
$search = isset($_GET['search']) && is_string($_GET['search']) ? trim($_GET['search']) : '';
$orderId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$orderId = $orderId === false ? null : $orderId;
$sort = isset($_GET['sort']) && is_string($_GET['sort']) ? $_GET['sort'] : 'oldest';
$validSorts = ['oldest', 'newest', 'prep', 'value'];
if (!in_array($sort, $validSorts, true)) {
    $sort = 'oldest';
}
$canUpdateOrders = in_array($_SESSION['role'] ?? '', ['admin', 'kitchen'], true);
$canEditOrders = in_array($_SESSION['role'] ?? '', ['admin', 'cashier'], true);
$flashMessage = $_SESSION['orders_message'] ?? '';
unset($_SESSION['orders_message']);
$flashType = $_SESSION['orders_message_type'] ?? 'success';
unset($_SESSION['orders_message_type']);

$today = date('Y-m-d');
$countsStatement = $pdo->prepare(
    'SELECT status, COUNT(*) AS order_count
     FROM orders
     WHERE DATE(created_at) = :today
     GROUP BY status'
);
$countsStatement->execute(['today' => $today]);
$statusCounts = [
    'placed' => 0,
    'preparing' => 0,
    'completed' => 0,
    'cancelled' => 0,
];
foreach ($countsStatement->fetchAll() as $countRow) {
    $statusCounts[$countRow['status']] = (int) $countRow['order_count'];
}
$activeCount = $statusCounts['placed'] + $statusCounts['preparing'];
$totalToday = array_sum($statusCounts);
$nonCancelledToday = $statusCounts['placed'] + $statusCounts['preparing'] + $statusCounts['completed'];

$averagePrepStatement = $pdo->prepare(
    'SELECT COALESCE(AVG(TIMESTAMPDIFF(MINUTE, started_at, completed_at)), 0)
     FROM orders
     WHERE status = \'completed\'
       AND started_at IS NOT NULL
       AND completed_at IS NOT NULL
       AND DATE(completed_at) = :today'
);
$averagePrepStatement->execute(['today' => $today]);
$averagePrep = (float) $averagePrepStatement->fetchColumn();

$where = [];
$parameters = [];
if ($statusFilter === 'active') {
    $where[] = "orders.status IN ('placed', 'preparing')";
} elseif ($statusFilter !== 'all') {
    $where[] = 'orders.status = :status';
    $parameters['status'] = $statusFilter;
}
if ($search !== '') {
    $where[] = '(orders.order_number LIKE :order_number OR CAST(orders.queue_number AS CHAR) LIKE :queue_number OR COALESCE(orders.location, \'\') LIKE :location)';
    $term = '%' . $search . '%';
    $hasQueuePrefix = preg_match('/^q[\s-]*(\d+)$/i', $search, $queueMatch) === 1;
    $queueSearch = $hasQueuePrefix
        ? (ltrim($queueMatch[1], '0') ?: '0')
        : $term;
    $parameters['order_number'] = $term;
    $parameters['queue_number'] = $queueSearch;
    $parameters['location'] = $term;
}

$orderBy = match ($sort) {
    'newest' => 'orders.created_at DESC, orders.id DESC',
    'prep' => 'CASE WHEN orders.status = \'preparing\' THEN TIMESTAMPDIFF(MINUTE, orders.started_at, NOW()) ELSE TIMESTAMPDIFF(MINUTE, orders.created_at, NOW()) END DESC, orders.created_at ASC',
    'value' => 'orders.total DESC, orders.created_at DESC',
    default => 'orders.created_at ASC, orders.id ASC',
};
$ordersSql = 'SELECT
        orders.id,
        orders.order_number,
        orders.slip_number,
        orders.queue_number,
        orders.order_type,
        orders.location,
        orders.status,
        orders.subtotal,
        orders.total,
        orders.kitchen_note,
        orders.created_at,
        orders.started_at,
        orders.completed_at,
        orders.cancelled_at,
        orders.cancel_reason,
        GROUP_CONCAT(
            CONCAT(order_items.quantity, \'× \', order_items.item_name)
            ORDER BY order_items.id
            SEPARATOR \', \'
        ) AS items_summary,
        COUNT(order_items.id) AS line_count,
        COALESCE(SUM(order_items.quantity), 0) AS unit_count
     FROM orders
     LEFT JOIN order_items ON order_items.order_id = orders.id'
     . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' GROUP BY orders.id ORDER BY ' . $orderBy . ' LIMIT 100';
$ordersStatement = $pdo->prepare($ordersSql);
$ordersStatement->execute($parameters);
$orders = $ordersStatement->fetchAll();

$selectedOrder = null;
$selectedItems = [];
$selectedIngredients = [];
$selectedHistory = [];
$selectedPaymentSummary = null;
$canEditSelectedOrder = false;
$hasUnmappedSelectedItem = false;
$editableMenuItems = [];
if ($orderId !== null && $orderId > 0) {
    $selectedStatement = $pdo->prepare(
        'SELECT orders.*, users.full_name AS staff_name,
                cancelled_user.full_name AS cancelled_by_name
         FROM orders
         LEFT JOIN users ON users.id = orders.created_by
         LEFT JOIN users cancelled_user ON cancelled_user.id = orders.cancelled_by
         WHERE orders.id = :id'
    );
    $selectedStatement->execute(['id' => $orderId]);
    $selectedOrder = $selectedStatement->fetch() ?: null;

    if ($selectedOrder !== null) {
        $selectedPaymentSummary = payment_summary($selectedOrder, payment_transactions_for_order($pdo, $orderId));
        $itemsStatement = $pdo->prepare(
            'SELECT id, menu_item_id, item_name, quantity, unit_price, line_total, item_note, status
             FROM order_items
             WHERE order_id = :id
             ORDER BY id'
        );
        $itemsStatement->execute(['id' => $orderId]);
        $selectedItems = $itemsStatement->fetchAll();
        foreach ($selectedItems as $selectedItem) {
            if ($selectedItem['menu_item_id'] === null) {
                $hasUnmappedSelectedItem = true;
                break;
            }
        }
        $canEditSelectedOrder = $canEditOrders
            && $selectedOrder['status'] === 'placed'
            && !$hasUnmappedSelectedItem;
        if ($canEditSelectedOrder) {
            $editableMenuStatement = $pdo->query(
                'SELECT id, name, price
                 FROM menu_items
                 WHERE is_active = 1 AND is_available = 1
                 ORDER BY name'
            );
            $editableMenuItems = $editableMenuStatement->fetchAll();
        }

        $ingredientsStatement = $pdo->prepare(
            'SELECT inventory.name, inventory.unit,
                    SUM(recipe.quantity_required * order_items.quantity) AS required_quantity
             FROM order_items
             INNER JOIN menu_recipes recipe ON recipe.menu_item_id = order_items.menu_item_id
             INNER JOIN inventory_items inventory ON inventory.id = recipe.inventory_item_id
             WHERE order_items.order_id = :id
             GROUP BY inventory.id, inventory.name, inventory.unit
             ORDER BY inventory.name'
        );
        $ingredientsStatement->execute(['id' => $orderId]);
        $selectedIngredients = $ingredientsStatement->fetchAll();

        $historyStatement = $pdo->prepare(
            'SELECT history.old_status, history.new_status, history.changed_at,
                    history.note, users.full_name AS staff_name
             FROM order_status_history history
             LEFT JOIN users ON users.id = history.changed_by
             WHERE history.order_id = :id
             ORDER BY history.changed_at ASC, history.id ASC'
        );
        $historyStatement->execute(['id' => $orderId]);
        $selectedHistory = $historyStatement->fetchAll();
    }
}

$statusLabels = [
    'placed' => 'Placed',
    'preparing' => 'Preparing',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
];
$orderTypeLabels = [
    'dine_in' => 'Dine-In',
    'takeout' => 'Takeout',
    'delivery' => 'Delivery',
];
$pageTitle = 'Orders';
$currentPage = 'orders';
$pageScript = 'assets/js/orders.js?v=' . (string) filemtime(__DIR__ . '/assets/js/orders.js');
require __DIR__ . '/includes/header.php';
?>
<div class="relative flex w-full flex-col">
    <?php if ($flashMessage !== ''): ?>
        <div class="mb-5 rounded-xl border p-4 font-body-md text-body-md <?= $flashType === 'error' ? 'border-error/30 bg-error-container text-on-error-container' : 'border-primary-fixed bg-primary-fixed text-on-primary-fixed-variant' ?>" role="status">
            <?= h($flashMessage) ?>
        </div>
    <?php endif; ?>

    <section aria-label="Today's order summary" class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-3">
        <article class="flex items-center justify-between rounded-xl border border-surface-container bg-surface-container-lowest p-4 shadow-sm">
            <div>
                <span class="font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">Active Queue</span>
                <div class="mt-1 font-mono-metric text-mono-metric text-primary"><?= h($activeCount) ?> <span class="font-label-md text-label-md font-normal text-on-surface-variant">tickets</span></div>
            </div>
            <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-surface-container text-primary">
                <span class="material-symbols-outlined text-[24px]">soup_kitchen</span>
            </div>
        </article>
        <article class="flex items-center justify-between rounded-xl border border-surface-container bg-surface-container-lowest p-4 shadow-sm">
            <div>
                <span class="font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">Average Prep</span>
                <div class="mt-1 font-mono-metric text-mono-metric text-primary"><?= h(number_format($averagePrep, 1)) ?> <span class="font-label-md text-label-md font-normal text-on-surface-variant">min</span></div>
            </div>
            <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-surface-container text-secondary">
                <span class="material-symbols-outlined text-[24px]">timer</span>
            </div>
        </article>
        <article class="flex items-center justify-between rounded-xl border border-surface-container bg-surface-container-lowest p-4 shadow-sm">
            <div>
                <span class="font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">Completed Today</span>
                <div class="mt-1 font-mono-metric text-mono-metric text-primary"><?= h($statusCounts['completed']) ?> <span class="font-label-md text-label-md font-normal text-on-surface-variant">of <?= h($nonCancelledToday) ?> non-cancelled orders</span></div>
            </div>
            <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-surface-container text-primary-container">
                <span class="material-symbols-outlined text-[24px]">check_circle</span>
            </div>
        </article>
    </section>

    <section class="mb-6 flex flex-col justify-between gap-4 md:flex-row md:items-center">
        <div>
            <h2 class="font-headline-xl text-headline-xl tracking-tight text-primary">Orders Management</h2>
            <p class="mt-0.5 font-body-md text-body-md text-on-surface-variant">Sequential daily queue and kitchen sync</p>
        </div>
        <div class="flex items-center gap-3">
            <a class="flex min-h-12 items-center justify-center gap-2 rounded-lg bg-surface-container-high px-4 font-body-md-bold text-body-md-bold text-on-surface shadow-sm transition-colors hover:bg-surface-container-highest" href="orders.php">
                <span class="material-symbols-outlined text-[20px]">refresh</span>
                <span>Refresh Orders</span>
            </a>
            <?php if (in_array($_SESSION['role'], ['admin', 'cashier'], true)): ?>
                <a class="flex min-h-12 items-center justify-center gap-2 rounded-lg bg-primary-container px-5 font-body-md-bold text-body-md-bold text-on-primary shadow-md transition-colors hover:bg-primary" href="new-order.php">
                    <span class="material-symbols-outlined text-[20px]">add</span>
                    <span>+ New Order</span>
                </a>
            <?php endif; ?>
        </div>
    </section>

    <section class="mb-6 flex flex-col gap-4 rounded-xl bg-surface-container-lowest p-4 shadow-sm">
        <form action="orders.php" method="get" class="flex flex-col items-center justify-between gap-4 lg:flex-row">
            <input type="hidden" name="status" value="<?= h($statusFilter) ?>">
            <div class="relative w-full lg:w-96">
                <label class="sr-only" for="order-search">Search orders</label>
                <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-[20px] text-outline">search</span>
                <input class="h-12 w-full rounded-lg bg-surface-container-low pl-11 pr-4 font-body-md text-body-md text-on-surface outline-none transition focus:bg-surface-container-lowest focus:ring-2 focus:ring-primary-container" id="order-search" name="search" placeholder="Search queue number, order ID or location..." type="search" value="<?= h($search) ?>">
            </div>
            <div class="flex w-full items-center justify-end gap-2 lg:w-auto">
                <label class="whitespace-nowrap font-label-md text-label-md text-on-surface-variant" for="sort-orders">Sort By:</label>
                <select class="h-12 min-w-[220px] rounded-lg bg-surface-container-low px-4 font-body-md text-body-md text-on-surface outline-none focus:ring-2 focus:ring-primary-container" id="sort-orders" name="sort" onchange="this.form.submit()">
                    <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Queue Order (Oldest first)</option>
                    <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Queue Order (Newest first)</option>
                    <option value="prep" <?= $sort === 'prep' ? 'selected' : '' ?>>Highest Prep Time</option>
                    <option value="value" <?= $sort === 'value' ? 'selected' : '' ?>>Order Value</option>
                </select>
                <button class="flex h-12 items-center justify-center rounded-lg bg-primary px-4 font-body-md-bold text-body-md-bold text-on-primary" type="submit">Search</button>
            </div>
        </form>

        <nav aria-label="Filter orders by status" class="flex items-center gap-2 overflow-x-auto pb-1">
            <?php
            $filterTabs = [
                'all' => ['label' => 'All Orders', 'count' => $totalToday],
                'active' => ['label' => 'Active', 'count' => $activeCount],
                'placed' => ['label' => 'Placed', 'count' => $statusCounts['placed']],
                'preparing' => ['label' => 'Preparing', 'count' => $statusCounts['preparing']],
                'completed' => ['label' => 'Completed', 'count' => $statusCounts['completed']],
                'cancelled' => ['label' => 'Cancelled', 'count' => $statusCounts['cancelled']],
            ];
            foreach ($filterTabs as $key => $tab):
                $tabQuery = http_build_query(array_filter([
                    'status' => $key === 'all' ? null : $key,
                    'search' => $search,
                    'sort' => $sort === 'oldest' ? null : $sort,
                ], static fn ($value): bool => $value !== null && $value !== ''));
                $tabActive = $statusFilter === $key;
            ?>
                <a class="flex min-h-10 shrink-0 items-center gap-1 rounded-lg px-4 font-label-md text-label-md whitespace-nowrap transition-colors <?= $tabActive ? 'bg-primary-container text-on-primary shadow-sm' : 'bg-surface-container text-on-surface hover:bg-surface-container-high' ?>" href="orders.php<?= $tabQuery !== '' ? '?' . h($tabQuery) : '' ?>">
                    <span><?= h($tab['label']) ?></span>
                    <span class="font-mono-metric text-xs opacity-80">(<?= h($tab['count']) ?>)</span>
                </a>
            <?php endforeach; ?>
        </nav>
    </section>

    <div class="grid grid-cols-12 items-start gap-6">
        <section class="<?= $selectedOrder ? 'col-span-12 xl:col-span-7' : 'col-span-12' ?> flex min-w-0 flex-col overflow-hidden rounded-xl border border-surface-container bg-surface-container-lowest shadow-sm">
            <div class="w-full overflow-x-auto">
                <table class="w-full border-collapse text-left">
                    <thead>
                        <tr class="h-12 border-b border-surface-container bg-surface-container-low font-label-md text-label-md uppercase tracking-wider text-on-surface-variant">
                            <th class="px-4 py-3">Queue No.</th>
                            <th class="px-4 py-3">Order ID</th>
                            <th class="px-4 py-3">Items Summary</th>
                            <th class="px-4 py-3">Time Placed</th>
                            <th class="px-4 py-3 text-right">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-surface-container font-body-md text-body-md">
                        <?php if ($orders === []): ?>
                            <tr><td class="px-4 py-10 text-center text-on-surface-variant" colspan="5">No orders match these filters.</td></tr>
                        <?php else: ?>
                            <?php foreach ($orders as $order): ?>
                                <?php
                                $orderStatus = (string) $order['status'];
                                $orderType = (string) $order['order_type'];
                                $isSelected = $selectedOrder && (int) $selectedOrder['id'] === (int) $order['id'];
                                $queue = 'Q-' . str_pad((string) $order['queue_number'], 3, '0', STR_PAD_LEFT);
                                $detailQuery = http_build_query(array_filter([
                                    'id' => (int) $order['id'],
                                    'status' => $statusFilter === 'all' ? null : $statusFilter,
                                    'search' => $search,
                                    'sort' => $sort === 'oldest' ? null : $sort,
                                ], static fn ($value): bool => $value !== null && $value !== ''));
                                ?>
                                <tr class="min-h-16 transition-colors <?= $isSelected ? 'bg-primary/5 hover:bg-primary/10' : 'hover:bg-surface-container-low' ?> <?= $orderStatus === 'cancelled' ? 'opacity-75' : '' ?>">
                                    <td class="whitespace-nowrap px-4 py-4">
                                        <a class="inline-flex items-center justify-center rounded-lg bg-primary-fixed px-2 py-1 font-headline-sm text-headline-sm font-bold tracking-tight text-primary" href="orders.php?<?= h($detailQuery) ?>"><?= h($queue) ?></a>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-4">
                                        <a class="font-body-md-bold text-body-md-bold <?= $orderStatus === 'cancelled' ? 'text-outline' : 'text-primary' ?>" href="orders.php?<?= h($detailQuery) ?>"><?= h($order['order_number']) ?></a>
                                        <span class="block font-label-sm text-label-sm text-on-surface-variant"><?= h($orderTypeLabels[$orderType] ?? $orderType) ?><?= $order['location'] ? ' • ' . h($order['location']) : '' ?></span>
                                        <?php if ($orderType === 'takeout' && !empty($order['slip_number'])): ?>
                                            <a class="mt-1 inline-flex min-h-8 items-center gap-1 rounded-lg bg-surface-container px-2.5 font-label-sm text-primary transition-colors hover:bg-surface-container-high" href="order-slip.php?slip=<?= rawurlencode((string) $order['slip_number']) ?>">
                                                <span class="material-symbols-outlined text-[15px]">receipt_long</span>
                                                <span>View slip</span>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                    <td class="max-w-[260px] px-4 py-4">
                                        <a class="block break-words text-on-surface hover:text-primary" href="orders.php?<?= h($detailQuery) ?>">
                                            <span class="font-body-md-bold"><?= h($order['unit_count']) ?> item<?= (int) $order['unit_count'] === 1 ? '' : 's' ?>:</span>
                                            <?= h($order['items_summary'] ?: 'No items') ?>
                                        </a>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-4">
                                        <time class="text-on-surface" datetime="<?= h(date(DATE_ATOM, strtotime((string) $order['created_at']))) ?>"><?= h(date('g:i A', strtotime((string) $order['created_at']))) ?></time>
                                        <?php if ($orderStatus === 'completed' && $order['completed_at']): ?>
                                            <span class="block font-label-sm text-label-sm text-tertiary-container">Completed <?= h(date('g:i A', strtotime((string) $order['completed_at']))) ?></span>
                                        <?php elseif ($orderStatus === 'cancelled' && $order['cancelled_at']): ?>
                                            <span class="block font-label-sm text-label-sm text-outline">Cancelled <?= h(date('g:i A', strtotime((string) $order['cancelled_at']))) ?></span>
                                        <?php else: ?>
                                            <?php $minutesAgo = max(0, (int) floor((time() - strtotime((string) $order['created_at'])) / 60)); ?>
                                            <span class="flex items-center gap-1 font-label-sm text-label-sm <?= $minutesAgo >= 10 && $orderStatus !== 'completed' ? 'text-error' : 'text-on-surface-variant' ?>">
                                                <span class="material-symbols-outlined text-[14px]">schedule</span><?= h($minutesAgo) ?>m ago
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-4 text-right">
                                        <?php
                                        $statusClasses = match ($orderStatus) {
                                            'placed' => 'bg-surface-container-highest text-primary-container',
                                            'preparing' => 'bg-secondary-fixed text-on-secondary-fixed',
                                            'completed' => 'bg-tertiary-fixed text-on-tertiary-fixed-variant',
                                            'cancelled' => 'bg-error-container text-on-error-container',
                                            default => 'bg-surface-container-highest text-outline',
                                        };
                                        $statusIcon = match ($orderStatus) {
                                            'placed' => 'inventory_2',
                                            'preparing' => 'local_fire_department',
                                            'completed' => 'check_circle',
                                            'cancelled' => 'cancel',
                                            default => 'cancel',
                                        };
                                        $currentRole = (string) ($_SESSION['role'] ?? '');
                                        $canCancelOrder = in_array($currentRole, ['admin', 'cashier'], true)
                                            && ($orderStatus === 'placed' || ($orderStatus === 'preparing' && $currentRole === 'admin'));
                                        ?>
                                        <div class="flex flex-col items-end gap-2">
                                            <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-1 font-label-sm text-label-sm font-bold <?= h($statusClasses) ?>">
                                                <span class="material-symbols-outlined text-[15px]"><?= h($statusIcon) ?></span>
                                                <?= h($statusLabels[$orderStatus] ?? $orderStatus) ?>
                                            </span>
                                            <?php if ($canCancelOrder): ?>
                                                <button
                                                    class="inline-flex min-h-9 items-center gap-1 rounded-lg bg-error-container px-3 font-label-md text-label-md font-bold text-on-error-container transition-colors hover:bg-error"
                                                    data-open-order-cancel
                                                    data-order-id="<?= h($order['id']) ?>"
                                                    data-order-number="<?= h($order['order_number']) ?>"
                                                    data-order-status="<?= h($orderStatus) ?>"
                                                    data-return-query="<?= h($detailQuery) ?>"
                                                    type="button"
                                                >
                                                    <span class="material-symbols-outlined text-[16px]">cancel</span>
                                                    <span>Cancel</span>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="flex flex-col items-center justify-between gap-2 border-t border-surface-container bg-surface-container-low p-4 font-label-md text-label-md text-on-surface-variant sm:flex-row">
                <span>Displaying <?= h(count($orders)) ?> orders (maximum 100)</span>
                <span class="font-mono-metric text-xs">Live status is reflected on the kitchen display</span>
            </div>
        </section>

        <?php if ($selectedOrder !== null): ?>
            <?php
            $selectedStatus = (string) $selectedOrder['status'];
            $selectedType = (string) $selectedOrder['order_type'];
            $selectedQueue = 'Q-' . str_pad((string) $selectedOrder['queue_number'], 3, '0', STR_PAD_LEFT);
            ?>
            <aside class="relative col-span-12 flex flex-col overflow-hidden rounded-2xl border border-surface-container bg-surface-container-lowest shadow-xl xl:col-span-5">
                <div class="flex items-start justify-between gap-3 border-b border-surface-container bg-surface-container p-5">
                    <div class="flex min-w-0 flex-col">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="font-headline-lg text-headline-lg font-bold tracking-tight text-primary">Queue No. <?= h($selectedQueue) ?></h3>
                            <span class="rounded-full bg-surface-container-highest px-2.5 py-1 font-label-sm text-label-sm font-bold text-primary-container"><?= h($statusLabels[$selectedStatus] ?? $selectedStatus) ?></span>
                        </div>
                        <div class="mt-1 flex flex-wrap items-center gap-2 font-label-md text-label-md text-on-surface-variant">
                            <span class="font-mono-metric text-sm font-semibold text-primary"><?= h($selectedOrder['order_number']) ?></span>
                            <span>•</span>
                            <span><?= h($orderTypeLabels[$selectedType] ?? $selectedType) ?><?= $selectedOrder['location'] ? ' (' . h($selectedOrder['location']) . ')' : '' ?></span>
                        </div>
                    </div>
                    <?php
                    $closeQuery = http_build_query(array_filter([
                        'status' => $statusFilter === 'all' ? null : $statusFilter,
                        'search' => $search,
                        'sort' => $sort === 'oldest' ? null : $sort,
                    ], static fn ($value): bool => $value !== null && $value !== ''));
                    ?>
                    <a aria-label="Close order details" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-surface-container-high text-on-surface transition-colors hover:bg-surface-container-highest" href="orders.php<?= $closeQuery !== '' ? '?' . h($closeQuery) : '' ?>">
                        <span class="material-symbols-outlined text-[20px]">close</span>
                    </a>
                </div>

                <div class="flex max-h-[calc(100vh-330px)] flex-col gap-5 overflow-y-auto p-5">
                    <?php if ($selectedStatus === 'cancelled'): ?>
                        <section class="rounded-xl border border-error/30 bg-error-container/40 p-4">
                            <h4 class="font-label-sm text-label-sm font-bold uppercase tracking-wider text-on-error-container">Order cancelled</h4>
                            <p class="mt-2 break-words font-body-md text-body-md text-on-error-container"><?= h($selectedOrder['cancel_reason'] ?: 'No cancellation reason was recorded.') ?></p>
                            <p class="mt-2 font-label-sm text-label-sm text-on-error-container">
                                <?= $selectedOrder['cancelled_at'] ? h(date('M j, Y g:i A', strtotime((string) $selectedOrder['cancelled_at']))) : '' ?>
                                <?= $selectedOrder['cancelled_by_name'] ? ' • ' . h($selectedOrder['cancelled_by_name']) : '' ?>
                                • <?= (int) $selectedOrder['inventory_restored'] === 1 ? 'Recorded ingredients restored' : 'Ingredients not restored' ?>
                            </p>
                        </section>
                    <?php endif; ?>

                    <?php if ($selectedStatus !== 'cancelled'): ?>
                    <section class="rounded-xl border border-surface-container bg-surface-container-low p-4">
                        <h4 class="mb-4 font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">Stage Progress</h4>
                        <ol class="grid grid-cols-3 gap-2">
                            <?php
                            $progressStages = ['placed', 'preparing', 'completed'];
                            $currentProgress = array_search($selectedStatus, $progressStages, true);
                            if ($currentProgress === false) {
                                $currentProgress = -1;
                            }
                            foreach ($progressStages as $index => $stage):
                                $isDone = $currentProgress >= $index;
                                $isCurrent = $currentProgress === $index;
                            ?>
                                <li class="flex flex-col items-center text-center">
                                    <span class="flex h-9 w-9 items-center justify-center rounded-full <?= $isDone ? 'bg-primary-container text-on-primary' : 'bg-surface-container text-outline' ?>">
                                        <span class="material-symbols-outlined text-[18px]"><?= $isDone && !$isCurrent ? 'check' : ($stage === 'placed' ? 'receipt' : ($stage === 'preparing' ? 'restaurant' : 'done_all')) ?></span>
                                    </span>
                                    <span class="mt-1.5 font-label-sm text-label-sm font-bold <?= $isCurrent ? 'text-secondary' : ($isDone ? 'text-primary' : 'text-on-surface-variant') ?>"><?= h($statusLabels[$stage]) ?></span>
                                    <span class="font-label-sm text-[10px] text-on-surface-variant">
                                        <?php
                                        $stageTime = match ($stage) {
                                            'placed' => $selectedOrder['created_at'],
                                            'preparing' => $selectedOrder['started_at'],
                                            default => $selectedOrder['completed_at'],
                                        };
                                        ?>
                                        <?= $stageTime ? h(date('g:i A', strtotime((string) $stageTime))) : 'Pending' ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    </section>
                    <?php endif; ?>

                    <section class="rounded-xl border border-surface-container bg-surface-container-low p-4">
                        <div class="mb-3 flex items-center justify-between gap-2">
                            <h4 class="font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">Ticket Line Items</h4>
                            <span class="rounded bg-surface-container px-2 py-0.5 font-label-sm text-label-sm text-on-surface-variant"><?= h(count($selectedItems)) ?> lines • <?= h(array_sum(array_map(static fn (array $item): int => (int) $item['quantity'], $selectedItems))) ?> units</span>
                        </div>
                        <?php if ($canEditSelectedOrder): ?>
                            <form action="actions/order-edit.php" class="flex flex-col gap-3" data-order-edit-form method="post">
                                <input name="csrf_token" type="hidden" value="<?= h(csrf_token()) ?>">
                                <input name="order_id" type="hidden" value="<?= h($selectedOrder['id']) ?>">
                                <input name="return_query" type="hidden" value="<?= h(http_build_query(['id' => (int) $selectedOrder['id']])) ?>">
                                <p class="font-label-sm text-label-sm text-on-surface-variant">Change a quantity, or set it to 0 to remove that line. Additions use current menu prices; existing lines keep their original price.</p>
                                <?php foreach ($selectedItems as $item): ?>
                                    <div class="grid grid-cols-[minmax(0,1fr)_6rem] items-center gap-3 border-b border-surface-container py-3">
                                        <input name="expected_items[<?= h($item['id']) ?>]" type="hidden" value="<?= h($item['quantity']) ?>">
                                        <div class="min-w-0">
                                            <span class="block font-body-md-bold text-body-md-bold text-on-surface"><?= h($item['item_name']) ?></span>
                                            <?php if ($item['item_note']): ?><span class="block break-words font-label-sm text-label-sm text-on-surface-variant"><?= h($item['item_note']) ?></span><?php endif; ?>
                                            <span class="font-label-sm text-label-sm text-on-surface-variant">₱<?= h(number_format((float) $item['unit_price'], 2)) ?> each</span>
                                        </div>
                                        <label class="flex flex-col gap-1 font-label-sm text-on-surface-variant">Qty
                                            <input class="h-10 w-full rounded-lg bg-surface-container-lowest px-2 text-on-surface focus:outline-none focus:ring-2 focus:ring-primary-container" max="100" min="0" name="existing_items[<?= h($item['id']) ?>]" required step="1" type="number" value="<?= h($item['quantity']) ?>">
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                                <div class="rounded-lg bg-surface-container-low p-3">
                                    <div class="mb-2 flex items-center justify-between gap-2">
                                        <h5 class="font-label-sm text-label-sm font-bold uppercase tracking-wider text-on-surface-variant">Add items</h5>
                                        <button class="min-h-9 rounded-lg bg-surface-container px-3 font-label-md text-primary hover:bg-surface-container-high" data-add-order-item type="button">Add item</button>
                                    </div>
                                    <div class="flex flex-col gap-2" data-order-add-items></div>
                                    <template data-order-add-item-template>
                                        <div class="grid grid-cols-[minmax(0,1fr)_5rem_auto] items-end gap-2" data-order-add-item>
                                            <label class="flex min-w-0 flex-col gap-1 font-label-sm text-on-surface-variant">Menu item
                                                <select class="h-10 min-w-0 rounded-lg bg-surface-container-lowest px-2 text-on-surface focus:outline-none focus:ring-2 focus:ring-primary-container" name="new_items[__INDEX__][menu_item_id]" required>
                                                    <option value="">Choose item</option>
                                                    <?php foreach ($editableMenuItems as $menuItem): ?>
                                                        <option value="<?= h($menuItem['id']) ?>"><?= h($menuItem['name'] . ' — ₱' . number_format((float) $menuItem['price'], 2)) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                            <label class="flex flex-col gap-1 font-label-sm text-on-surface-variant">Qty
                                                <input class="h-10 w-full rounded-lg bg-surface-container-lowest px-2 text-on-surface focus:outline-none focus:ring-2 focus:ring-primary-container" max="100" min="1" name="new_items[__INDEX__][quantity]" required step="1" type="number" value="1">
                                            </label>
                                            <button aria-label="Remove added item" class="min-h-10 rounded-lg bg-error-container px-2 font-label-md text-on-error-container hover:opacity-80" data-remove-order-item type="button">Remove</button>
                                        </div>
                                    </template>
                                </div>
                                <button class="flex min-h-11 items-center justify-center gap-2 rounded-lg bg-primary px-4 font-body-md-bold text-body-md-bold text-on-primary hover:bg-primary-container" type="submit">
                                    <span class="material-symbols-outlined text-[18px]">save</span>
                                    <span>Save Order Changes</span>
                                </button>
                            </form>
                        <?php else: ?>
                            <div class="flex flex-col divide-y divide-surface-container">
                                <?php foreach ($selectedItems as $item): ?>
                                    <div class="flex items-center justify-between gap-3 py-3">
                                        <div class="flex min-w-0 items-start gap-2">
                                            <span class="flex h-7 min-w-8 items-center justify-center rounded bg-primary-fixed px-1 font-mono-metric text-xs font-bold text-primary"><?= h($item['quantity']) ?>×</span>
                                            <div class="min-w-0">
                                                <span class="block font-body-md-bold text-body-md-bold text-on-surface"><?= h($item['item_name']) ?></span>
                                                <?php if ($item['item_note']): ?><span class="block break-words font-label-sm text-label-sm text-on-surface-variant"><?= h($item['item_note']) ?></span><?php endif; ?>
                                            </div>
                                        </div>
                                        <span class="shrink-0 font-mono-metric text-sm font-semibold text-primary">₱<?= h(number_format((float) $item['line_total'], 2)) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if ($selectedStatus === 'placed' && $canEditOrders && $hasUnmappedSelectedItem): ?>
                                <p class="mt-3 rounded-lg bg-secondary-container p-3 font-label-sm text-on-secondary-container">This order contains a line without a menu-item mapping, so it cannot be safely edited.</p>
                            <?php endif; ?>
                        <?php endif; ?>
                        <div class="mt-3 flex items-center justify-between rounded-lg bg-surface-container-high px-4 py-3">
                            <span class="font-body-md-bold text-body-md-bold text-on-surface">Total</span>
                            <span class="font-headline-sm text-headline-sm font-bold text-primary">₱<?= h(number_format((float) $selectedOrder['total'], 2)) ?></span>
                        </div>
                    </section>

                    <?php if ($selectedPaymentSummary !== null): ?>
                        <section class="rounded-xl border border-surface-container bg-surface-container-low p-4">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h4 class="font-body-md-bold text-primary">Payment · <?= h($selectedPaymentSummary['status']) ?></h4>
                                <?php if ($canEditOrders): ?><a class="rounded-lg bg-primary px-3 py-2 font-label-md text-on-primary" href="payments.php?order_id=<?= h($selectedOrder['id']) ?>">View and record payments</a><?php endif; ?>
                            </div>
                            <p class="mt-2 font-label-md text-on-surface-variant">Collected ₱<?= h(payment_format($selectedPaymentSummary['paid'])) ?> · Refunded ₱<?= h(payment_format($selectedPaymentSummary['refunded'])) ?></p>
                            <?php if ($selectedPaymentSummary['balance'] !== 0 && $selectedOrder['payment_history_state'] !== 'legacy_unknown'): ?>
                                <p class="mt-1 font-body-md-bold <?= $selectedPaymentSummary['balance'] < 0 ? 'text-error' : 'text-primary' ?>"><?= $selectedPaymentSummary['balance'] < 0 ? 'Refund due' : 'Amount due' ?> ₱<?= h(payment_format(abs($selectedPaymentSummary['balance']))) ?></p>
                            <?php endif; ?>
                        </section>
                    <?php endif; ?>

                    <?php if ($selectedOrder['kitchen_note']): ?>
                        <section class="rounded-xl border-l-4 border-secondary bg-secondary-fixed/40 p-4">
                            <h4 class="font-label-sm text-label-sm font-bold uppercase tracking-wider text-on-secondary-fixed">Kitchen Note</h4>
                            <p class="mt-1 break-words font-body-md text-body-md text-on-secondary-fixed"><?= h($selectedOrder['kitchen_note']) ?></p>
                        </section>
                    <?php endif; ?>

                    <section class="rounded-xl border border-surface-container bg-surface-container p-4">
                        <div class="mb-3 flex items-center justify-between gap-2">
                            <h4 class="flex items-center gap-2 font-body-md-bold text-body-md-bold text-primary">
                                <span class="material-symbols-outlined text-[18px]">inventory_2</span>
                                Ingredients for this order
                            </h4>
                            <span class="rounded bg-tertiary-fixed px-2 py-0.5 font-label-sm text-label-sm font-semibold text-on-tertiary-fixed-variant">From recipe</span>
                        </div>
                        <?php if ($selectedIngredients === []): ?>
                            <p class="rounded-md bg-surface-container-low p-3 font-label-md text-label-md text-on-surface-variant">Ingredient recipes are not configured for this order’s menu items.</p>
                        <?php else: ?>
                            <div class="flex flex-col gap-1">
                                <?php foreach ($selectedIngredients as $ingredient): ?>
                                    <div class="flex items-center justify-between gap-2 rounded-md bg-surface-container-low px-3 py-2 font-body-md text-body-md">
                                        <span class="font-medium text-on-surface"><?= h($ingredient['name']) ?></span>
                                        <span class="font-body-md-bold text-body-md-bold text-primary">−<?= h(rtrim(rtrim(number_format((float) $ingredient['required_quantity'], 2, '.', ''), '0'), '.')) ?> <?= h($ingredient['unit']) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <p class="mt-2 font-label-sm text-label-sm text-on-surface-variant">Stock was deducted when placed and is adjusted by any order edits.</p>
                        <?php endif; ?>
                    </section>

                    <section class="rounded-xl border border-surface-container bg-surface-container-low p-4">
                        <h4 class="mb-3 font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">Status History</h4>
                        <?php if ($selectedHistory === []): ?>
                            <p class="font-label-md text-label-md text-on-surface-variant">No status history has been recorded.</p>
                        <?php else: ?>
                            <ol class="flex flex-col gap-2">
                                <?php foreach ($selectedHistory as $history): ?>
                                    <li class="flex flex-col gap-0.5 border-l-2 border-primary-container pl-3">
                                        <span class="font-body-md-bold text-body-md-bold text-on-surface">
                                            <?= $history['old_status'] ? h($statusLabels[$history['old_status']] ?? $history['old_status']) . ' → ' : '' ?><?= h($statusLabels[$history['new_status']] ?? $history['new_status']) ?>
                                        </span>
                                        <span class="font-label-sm text-label-sm text-on-surface-variant">
                                            <?= h(date('M j, g:i A', strtotime((string) $history['changed_at']))) ?>
                                            <?= $history['staff_name'] ? ' • ' . h($history['staff_name']) : '' ?>
                                            <?= $history['note'] ? ' • ' . h($history['note']) : '' ?>
                                        </span>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                        <?php endif; ?>
                    </section>
                </div>

                <?php if ($canUpdateOrders && in_array($selectedStatus, ['placed', 'preparing'], true)): ?>
                    <div class="flex flex-col gap-2 border-t border-surface-container bg-surface-container-low p-5">
                        <form action="actions/order-status-update.php" method="post" data-order-status-form data-status-endpoint="actions/order-status-update.php">
                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="order_id" value="<?= h($selectedOrder['id']) ?>">
                            <input type="hidden" name="status" value="<?= $selectedStatus === 'placed' ? 'preparing' : 'completed' ?>">
                            <input type="hidden" name="return_query" value="<?= h($detailQuery) ?>">
                            <button class="flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-primary-container font-body-md-bold text-body-md-bold text-on-primary shadow-md transition-colors hover:bg-primary" type="submit">
                                <span class="material-symbols-outlined text-[20px]"><?= $selectedStatus === 'placed' ? 'play_circle' : 'check_circle' ?></span>
                                <span><?= $selectedStatus === 'placed' ? 'Start Preparing' : 'Mark as Completed' ?></span>
                            </button>
                        </form>
                        <p class="text-center font-label-sm text-label-sm text-on-surface-variant">Stock was deducted when the order was placed.</p>
                    </div>
                <?php endif; ?>
            </aside>
        <?php endif; ?>
    </div>
    <?php if (in_array($_SESSION['role'] ?? '', ['admin', 'cashier'], true)): ?>
        <dialog aria-labelledby="order-cancel-title" class="m-auto w-[calc(100%-2rem)] max-w-lg rounded-2xl bg-surface-container-lowest p-0 text-on-surface shadow-2xl backdrop:bg-primary/40" data-order-cancel-dialog>
            <form action="actions/order-cancel.php" class="flex flex-col" id="order-cancel-form" method="post">
                <input name="csrf_token" type="hidden" value="<?= h(csrf_token()) ?>">
                <input data-cancel-order-id name="order_id" type="hidden" value="">
                <input data-cancel-return-query name="return_query" type="hidden" value="">
                <div class="p-6">
                    <div class="flex items-start gap-3">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-error-container text-on-error-container">
                            <span class="material-symbols-outlined">warning</span>
                        </span>
                        <div>
                            <h2 class="font-body-md-bold text-xl text-primary" id="order-cancel-title">Cancel order?</h2>
                            <p class="mt-1 font-body-md text-on-surface-variant" data-cancel-order-summary></p>
                        </div>
                    </div>

                    <div class="mt-4 rounded-lg border border-error/30 bg-error-container/40 p-3 font-label-md text-label-md text-on-error-container">
                        Any collected payment will appear as a refund due after cancellation. An admin can record the refund from the order's Payments page.
                    </div>

                    <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
                        <fieldset class="mt-4 hidden rounded-xl border border-outline-variant p-4" data-cancel-disposition>
                            <legend class="px-1 font-label-sm text-label-sm font-bold uppercase tracking-wider text-on-surface-variant">Preparing-order ingredients</legend>
                            <p class="mb-3 font-label-md text-label-md text-on-surface-variant">Choose what should happen to the ingredients already deducted.</p>
                            <label class="flex cursor-pointer items-start gap-3 rounded-lg bg-surface-container-low p-3">
                                <input class="mt-1" name="cancel_mode" type="radio" value="restore" data-cancel-mode-input>
                                <span>
                                    <span class="block font-body-md-bold text-body-md-bold text-primary">Restore ingredients</span>
                                    <span class="block font-label-sm text-label-sm text-on-surface-variant">Add back the exact quantities recorded for this order.</span>
                                </span>
                            </label>
                            <label class="mt-2 flex cursor-pointer items-start gap-3 rounded-lg bg-surface-container-low p-3">
                                <input class="mt-1" name="cancel_mode" type="radio" value="waste" data-cancel-mode-input>
                                <span>
                                    <span class="block font-body-md-bold text-body-md-bold text-primary">Mark as waste</span>
                                    <span class="block font-label-sm text-label-sm text-on-surface-variant">Do not restore stock; record the ingredients as waste.</span>
                                </span>
                            </label>
                        </fieldset>
                    <?php endif; ?>

                    <p class="mt-4 hidden rounded-lg bg-primary-fixed p-3 font-label-md text-label-md text-on-primary-fixed-variant" data-cancel-auto-restore>
                        This placed order will restore its recorded ingredient deductions automatically.
                    </p>

                    <label class="mt-4 block font-label-sm text-label-sm font-bold uppercase tracking-wider text-on-surface-variant" for="cancel-reason-type">Cancellation reason</label>
                    <select class="mt-1 h-12 w-full rounded-lg border border-outline-variant bg-surface px-3 text-on-surface outline-none focus:ring-2 focus:ring-primary-container" id="cancel-reason-type" name="cancel_reason_type" required>
                        <option value="">Choose a reason</option>
                        <option value="Customer cancelled">Customer cancelled</option>
                        <option value="Wrong order">Wrong order</option>
                        <option value="Out of stock">Out of stock</option>
                        <option value="Other">Other</option>
                    </select>
                    <label class="mt-3 hidden font-label-sm text-label-sm font-bold uppercase tracking-wider text-on-surface-variant" data-cancel-other-label for="cancel-reason-other">Explain the reason</label>
                    <textarea class="mt-1 hidden min-h-24 w-full rounded-lg border border-outline-variant bg-surface p-3 text-on-surface outline-none focus:ring-2 focus:ring-primary-container" id="cancel-reason-other" maxlength="173" name="cancel_reason_other" data-cancel-other-input></textarea>
                </div>
                <div class="flex flex-col-reverse gap-2 border-t border-surface-container-high px-5 py-4 sm:flex-row sm:justify-end sm:px-6">
                    <button class="min-h-11 rounded-lg bg-surface-container px-5 font-body-md-bold text-primary hover:bg-surface-container-high" data-close-order-cancel type="button">Keep Order</button>
                    <button class="min-h-11 rounded-lg bg-error-container px-5 font-body-md-bold text-on-error-container hover:bg-error" type="submit">Confirm Cancellation</button>
                </div>
            </form>
        </dialog>
    <?php endif; ?>
    <div class="fixed bottom-6 right-6 z-50 hidden max-w-md rounded-xl bg-primary px-5 py-3 font-body-md-bold text-body-md-bold text-on-primary shadow-2xl" data-orders-message role="status"></div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
