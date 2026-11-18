<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';

$roleCanEdit = ($_SESSION['role'] ?? '') === 'admin';
$selectedId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$selectedId = $selectedId === false ? null : $selectedId;
$filter = isset($_GET['filter']) && is_string($_GET['filter']) ? $_GET['filter'] : 'all';
$search = isset($_GET['search']) && is_string($_GET['search']) ? trim($_GET['search']) : '';
$validFilters = ['all', 'action', 'in-stock', 'archived'];
if (!in_array($filter, $validFilters, true)) {
    $filter = 'all';
}
if ($filter === 'archived' && !$roleCanEdit) {
    $filter = 'all';
}

$flashMessage = $_SESSION['inventory_message'] ?? '';
unset($_SESSION['inventory_message']);
$flashType = $_SESSION['inventory_message_type'] ?? 'success';
unset($_SESSION['inventory_message_type']);

$categoryStatement = $pdo->query(
    "SELECT id, name FROM categories WHERE category_type = 'inventory' ORDER BY name"
);
$inventoryCategories = $categoryStatement->fetchAll();
$unitOptions = ['pcs', 'g', 'kg', 'mg', 'mL', 'L', 'serving', 'servings', 'glasses', 'bottle', 'pack', 'box', 'can', 'bag'];
$unitStatement = $pdo->query('SELECT DISTINCT unit FROM inventory_items ORDER BY unit');
foreach ($unitStatement->fetchAll(PDO::FETCH_COLUMN) as $existingUnit) {
    if (!in_array($existingUnit, $unitOptions, true)) {
        $unitOptions[] = (string) $existingUnit;
    }
}

$where = ['inventory.is_active = :is_active'];
$parameters = [];
if ($filter === 'archived') {
    $parameters['is_active'] = 0;
} else {
    $parameters['is_active'] = 1;
}
if ($filter === 'action') {
    $where[] = 'inventory.current_stock <= inventory.low_stock_level';
} elseif ($filter === 'in-stock') {
    $where[] = 'inventory.current_stock > inventory.low_stock_level';
}
if ($search !== '') {
    $where[] = '(inventory.name LIKE :search_name OR inventory.description LIKE :search_description OR inventory.unit LIKE :search_unit)';
    $searchTerm = '%' . $search . '%';
    $parameters['search_name'] = $searchTerm;
    $parameters['search_description'] = $searchTerm;
    $parameters['search_unit'] = $searchTerm;
}

$inventorySql = 'SELECT inventory.id, inventory.name, inventory.description,
                        inventory.unit, inventory.current_stock,
                        inventory.low_stock_level, inventory.category_id,
                        categories.name AS category_name
                 FROM inventory_items inventory
                 INNER JOIN categories ON categories.id = inventory.category_id
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY
                    CASE
                        WHEN inventory.current_stock <= 0 THEN 0
                        WHEN inventory.current_stock <= inventory.low_stock_level THEN 1
                        ELSE 2
                    END,
                    inventory.name';
$inventoryStatement = $pdo->prepare($inventorySql);
$inventoryStatement->execute($parameters);
$inventoryItems = $inventoryStatement->fetchAll();

$inventoryCountsStatement = $pdo->query(
    'SELECT
        COUNT(*) AS tracked_count,
        SUM(CASE WHEN current_stock > low_stock_level THEN 1 ELSE 0 END) AS in_stock_count,
        SUM(CASE WHEN current_stock > 0 AND current_stock <= low_stock_level THEN 1 ELSE 0 END) AS low_count,
        SUM(CASE WHEN current_stock <= 0 THEN 1 ELSE 0 END) AS out_count
     FROM inventory_items
     WHERE is_active = 1'
);
$inventoryCounts = $inventoryCountsStatement->fetch() ?: [
    'tracked_count' => 0,
    'in_stock_count' => 0,
    'low_count' => 0,
    'out_count' => 0,
];

$selectedItem = null;
if ($selectedId !== null && $selectedId > 0) {
    $selectedStatement = $pdo->prepare(
        'SELECT id, name, description, unit, current_stock, low_stock_level, category_id
         FROM inventory_items
         WHERE id = :id AND is_active = 1'
    );
    $selectedStatement->execute(['id' => $selectedId]);
    $selectedItem = $selectedStatement->fetch() ?: null;
}
$showSidePanel = $selectedItem !== null || ($roleCanEdit && isset($_GET['new']));

$movementStatement = $pdo->prepare(
    'SELECT movements.id, movements.movement_type, movements.quantity_change,
            movements.quantity_before, movements.quantity_after,
            movements.note, movements.created_at,
            inventory.name AS item_name, orders.order_number
     FROM inventory_movements movements
     INNER JOIN inventory_items inventory ON inventory.id = movements.inventory_item_id
     LEFT JOIN orders ON orders.id = movements.order_id
     ORDER BY movements.created_at DESC, movements.id DESC
     LIMIT 10'
);
$movementStatement->execute();
$recentMovements = $movementStatement->fetchAll();

$movementHistory = [];
if ($roleCanEdit) {
    $movementHistoryStatement = $pdo->query(
        'SELECT movements.created_at, movements.movement_type,
                movements.quantity_change, movements.quantity_before,
                movements.quantity_after, movements.note,
                inventory.name AS item_name, inventory.unit,
                orders.order_number, users.full_name AS user_name
         FROM inventory_movements movements
         INNER JOIN inventory_items inventory ON inventory.id = movements.inventory_item_id
         LEFT JOIN orders ON orders.id = movements.order_id
         LEFT JOIN users ON users.id = movements.changed_by
         ORDER BY movements.created_at DESC, movements.id DESC
         LIMIT 100'
    );
    $movementHistory = $movementHistoryStatement->fetchAll();
}

$pageTitle = 'Inventory';
$currentPage = 'inventory';
$pageScript = 'assets/js/inventory.js';
require __DIR__ . '/includes/header.php';
?>
<div class="flex w-full flex-col gap-6">
    <section class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
        <div>
            <div class="mb-2 flex flex-wrap items-center gap-2 font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">
                <span>Back-of-House Station</span>
                <span class="text-outline-variant">/</span>
                <span>Storage &amp; Prep</span>
                <span class="text-outline-variant">/</span>
                <span class="font-bold text-secondary">Live Stock</span>
            </div>
            <h2 class="font-headline-xl text-headline-xl tracking-tight text-primary">Inventory &amp; Stock Management</h2>
            <p class="mt-1 max-w-2xl font-body-md text-body-md text-on-surface-variant">
                Real-time stock on record and automatic deduction log across line stations
            </p>
        </div>
        <?php if ($roleCanEdit): ?>
            <a class="flex min-h-12 items-center justify-center gap-2 rounded-xl bg-primary px-5 font-label-lg text-label-lg text-on-primary shadow-md transition-colors hover:bg-primary-container" href="inventory.php?new=1">
                <span class="material-symbols-outlined text-[20px]">add</span>
                <span>Add Stock Item</span>
            </a>
        <?php endif; ?>
    </section>

    <?php if ($flashMessage !== ''): ?>
        <div class="rounded-xl border p-4 font-body-md text-body-md <?= $flashType === 'error' ? 'border-error/30 bg-error-container text-on-error-container' : 'border-primary-fixed bg-primary-fixed text-on-primary-fixed-variant' ?>" role="status">
            <?= h($flashMessage) ?>
        </div>
    <?php endif; ?>

    <section aria-label="Inventory stock overview" class="grid grid-cols-2 gap-4 md:grid-cols-4">
        <article class="flex flex-col justify-between rounded-xl bg-surface-container-lowest p-4 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">Tracked Items</span>
                <span class="material-symbols-outlined text-[20px] text-on-surface-variant">inventory_2</span>
            </div>
            <div class="mt-2">
                <span class="block text-[32px] font-bold leading-none text-primary"><?= h((int) $inventoryCounts['tracked_count']) ?></span>
                <p class="mt-2 font-label-sm text-label-sm text-on-surface-variant">Active inventory items</p>
            </div>
        </article>
        <article class="flex flex-col justify-between rounded-xl bg-surface-container-lowest p-4 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">In Stock</span>
                <span class="material-symbols-outlined text-[20px] text-tertiary-container">check_circle</span>
            </div>
            <div class="mt-2">
                <span class="block text-[32px] font-bold leading-none text-primary"><?= h((int) $inventoryCounts['in_stock_count']) ?></span>
                <p class="mt-2 font-label-sm text-label-sm text-tertiary-container">Above low-stock level</p>
            </div>
        </article>
        <article class="flex flex-col justify-between rounded-xl border-l-4 border-secondary-container bg-surface-container-lowest p-4 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="font-label-sm text-label-sm uppercase tracking-wider text-secondary">Low Stock</span>
                <span class="material-symbols-outlined text-[20px] text-secondary">warning</span>
            </div>
            <div class="mt-2">
                <span class="block text-[32px] font-bold leading-none text-secondary"><?= h((int) $inventoryCounts['low_count']) ?></span>
                <p class="mt-2 font-label-sm text-label-sm text-secondary">Replenishment required</p>
            </div>
        </article>
        <article class="flex flex-col justify-between rounded-xl border-l-4 border-error bg-surface-container-lowest p-4 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="font-label-sm text-label-sm uppercase tracking-wider text-error">Depleted</span>
                <span class="material-symbols-outlined text-[20px] text-error">block</span>
            </div>
            <div class="mt-2">
                <span class="block text-[32px] font-bold leading-none text-error"><?= h((int) $inventoryCounts['out_count']) ?></span>
                <p class="mt-2 font-label-sm text-label-sm text-error">Unavailable in POS recipes</p>
            </div>
        </article>
    </section>

    <div class="grid grid-cols-1 items-start gap-6 xl:grid-cols-12">
        <section class="flex min-w-0 flex-col overflow-hidden rounded-xl bg-surface-container-lowest shadow-sm <?= $showSidePanel ? 'xl:col-span-8' : 'xl:col-span-12' ?>">
            <div class="flex flex-col justify-between gap-4 bg-surface-container-low p-4 md:flex-row md:items-center">
                <nav aria-label="Inventory filters" class="flex items-center gap-2 overflow-x-auto">
                    <?php
                    $filters = [
                        'all' => ['label' => 'All Items', 'count' => (int) $inventoryCounts['tracked_count']],
                        'action' => ['label' => 'Action Needed', 'count' => (int) $inventoryCounts['low_count'] + (int) $inventoryCounts['out_count']],
                        'in-stock' => ['label' => 'In Stock', 'count' => (int) $inventoryCounts['in_stock_count']],
                    ];
                    foreach ($filters as $key => $item):
                        $active = $filter === $key;
                        $query = http_build_query(array_filter(
                            ['filter' => $key === 'all' ? null : $key, 'search' => $search],
                            static fn ($value): bool => $value !== null && $value !== ''
                        ));
                    ?>
                        <a class="flex min-h-10 shrink-0 items-center gap-2 rounded-lg px-4 font-label-md text-label-md transition-colors <?= $active ? 'bg-primary-container text-on-primary shadow-sm' : 'bg-surface-container text-on-surface-variant hover:bg-surface-container-high' ?>" href="inventory.php<?= $query !== '' ? '?' . h($query) : '' ?>">
                            <span><?= h($item['label']) ?></span>
                            <span class="rounded px-1.5 py-0.5 text-[11px] <?= $active ? 'bg-primary text-primary-fixed' : 'bg-surface-container-highest text-on-surface-variant' ?>"><?= h($item['count']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>
                <?php if ($roleCanEdit): ?>
                    <?php $archivedCount = (int) $pdo->query('SELECT COUNT(*) FROM inventory_items WHERE is_active = 0')->fetchColumn(); ?>
                    <a class="flex min-h-10 shrink-0 items-center gap-2 rounded-lg px-4 font-label-md transition-colors <?= $filter === 'archived' ? 'bg-primary-container text-on-primary shadow-sm' : 'bg-surface-container text-on-surface-variant hover:bg-surface-container-high' ?>" href="inventory.php?<?= h(http_build_query(array_filter(['filter' => 'archived', 'search' => $search], static fn ($value): bool => $value !== null && $value !== ''))) ?>">
                        <span>Archived</span><span class="rounded px-1.5 py-0.5 text-[11px]"><?= h($archivedCount) ?></span>
                    </a>
                <?php endif; ?>
                <form action="inventory.php" method="get" class="relative w-full md:w-72">
                    <?php if ($filter !== 'all'): ?><input type="hidden" name="filter" value="<?= h($filter) ?>"><?php endif; ?>
                    <label class="sr-only" for="inventory-search">Search inventory</label>
                    <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[18px] text-on-surface-variant">search</span>
                    <input class="h-10 w-full rounded-lg bg-surface pl-9 pr-4 font-body-md text-body-md text-on-surface outline-none transition focus:bg-surface-container-lowest focus:ring-2 focus:ring-primary-container" id="inventory-search" name="search" placeholder="Search stock item name..." type="search" value="<?= h($search) ?>">
                </form>
            </div>
            <div class="w-full overflow-x-auto">
                <table class="w-full border-collapse text-left">
                    <thead>
                        <tr class="h-12 bg-surface-container-high font-label-md text-label-md uppercase tracking-wider text-on-surface-variant">
                            <th class="px-4 py-3">Stock Item</th>
                            <th class="px-4 py-3">Unit</th>
                            <th class="px-4 py-3 text-right">Stock on Record</th>
                            <th class="px-4 py-3 text-right">Low-Stock Level</th>
                            <th class="px-4 py-3 text-center">Status</th>
                            <?php if ($roleCanEdit): ?><th class="px-4 py-3 text-center">Manage</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody class="font-body-md text-body-md text-on-surface">
                        <?php if ($inventoryItems === []): ?>
                            <tr><td class="px-4 py-8 text-center text-on-surface-variant" colspan="<?= $roleCanEdit ? '6' : '5' ?>">No inventory items match this filter.</td></tr>
                        <?php else: ?>
                            <?php foreach ($inventoryItems as $index => $item): ?>
                                <?php
                                $stock = (float) $item['current_stock'];
                                $threshold = (float) $item['low_stock_level'];
                                $isOut = $stock <= 0;
                                $isLow = !$isOut && $stock <= $threshold;
                                $rowClass = $filter === 'archived'
                                    ? 'bg-surface-container-low'
                                    : ($isOut
                                    ? 'bg-error-container/25 hover:bg-error-container/40'
                                    : ($isLow ? 'bg-secondary-fixed/30 hover:bg-secondary-fixed/50' : ($index % 2 ? 'bg-surface-container-low hover:bg-surface-container' : 'bg-surface-container-lowest hover:bg-surface-container')));
                                $selectedClass = $selectedItem && (int) $selectedItem['id'] === (int) $item['id'] ? 'ring-2 ring-inset ring-primary-container' : '';
                                ?>
                                <tr class="h-16 min-h-[60px] transition-colors <?= $rowClass ?> <?= $selectedClass ?>">
                                    <td class="px-4 py-3">
                                        <div class="block">
                                            <span class="block font-body-md-bold text-body-md-bold <?= $isOut ? 'text-error' : ($isLow ? 'text-secondary' : 'text-on-surface') ?>"><?= h($item['name']) ?></span>
                                            <span class="block font-label-sm text-label-sm text-on-surface-variant"><?= h($item['description'] ?: $item['category_name']) ?></span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 font-label-md text-label-md text-on-surface-variant"><?= h($item['unit']) ?></td>
                                    <td class="px-4 py-3 text-right font-headline-sm text-headline-sm font-bold <?= $isOut ? 'text-error' : ($isLow ? 'text-secondary' : 'text-on-surface') ?>"><?= h(rtrim(rtrim(number_format($stock, 2, '.', ''), '0'), '.')) ?></td>
                                    <td class="px-4 py-3 text-right font-label-lg text-label-lg text-on-surface-variant"><?= h(rtrim(rtrim(number_format($threshold, 2, '.', ''), '0'), '.')) ?></td>
                                    <td class="px-4 py-3 text-center">
                                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-1 font-label-sm text-label-sm font-bold uppercase <?= $filter === 'archived' ? 'bg-surface-container text-on-surface-variant' : ($isOut ? 'bg-error text-on-error' : ($isLow ? 'bg-secondary-container text-on-secondary' : 'bg-primary-fixed text-primary')) ?>">
                                            <span class="material-symbols-outlined text-[14px]"><?= $filter === 'archived' ? 'archive' : ($isOut ? 'cancel' : ($isLow ? 'warning' : 'check_circle')) ?></span>
                                            <span><?= $filter === 'archived' ? 'Archived' : ($isOut ? 'Out of stock' : ($isLow ? 'Low stock' : 'In stock')) ?></span>
                                        </span>
                                    </td>
                                    <?php if ($roleCanEdit): ?>
                                        <td class="px-4 py-3 text-center">
                                            <div class="flex flex-wrap items-center justify-center gap-2">
                                                <?php if ($filter !== 'archived'): ?>
                                                    <a class="inline-flex min-h-10 items-center gap-1 rounded-lg bg-primary-fixed px-3 py-2 font-label-md font-semibold text-primary hover:bg-primary-container" href="inventory.php?<?= h(http_build_query(array_filter(['id' => (int) $item['id'], 'filter' => $filter === 'all' ? null : $filter, 'search' => $search], static fn ($value): bool => $value !== null && $value !== ''))) ?>">
                                                        <span class="material-symbols-outlined text-[17px]">edit</span><span>Edit</span>
                                                    </a>
                                                <?php endif; ?>
                                                <form action="actions/inventory-save.php" method="post" onsubmit="return confirm('<?= $filter === 'archived' ? 'Restore' : 'Remove' ?> this ingredient?<?= $filter === 'archived' ? '' : ' If it is used by a dish or has stock history, it will be archived; otherwise it will be deleted.' ?>');">
                                                    <input name="csrf_token" type="hidden" value="<?= h(csrf_token()) ?>">
                                                    <input name="action" type="hidden" value="<?= $filter === 'archived' ? 'restore' : 'remove' ?>">
                                                    <input name="item_id" type="hidden" value="<?= h($item['id']) ?>">
                                                    <button class="rounded-lg px-3 py-2 font-label-md <?= $filter === 'archived' ? 'bg-surface-container text-primary' : 'bg-error-container text-on-error-container' ?>" type="submit"><?= $filter === 'archived' ? 'Restore' : 'Remove' ?></button>
                                                </form>
                                            </div>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="flex flex-col items-center justify-between gap-2 bg-surface-container-low p-4 font-label-md text-label-md text-on-surface-variant sm:flex-row">
                <span>Showing <?= h(count($inventoryItems)) ?> matching items</span>
                <a class="font-semibold text-primary hover:underline" href="inventory.php">Clear filters</a>
            </div>
        </section>

        <?php if ($showSidePanel): ?>
        <aside class="flex w-full flex-col gap-4 xl:sticky xl:top-24 xl:col-span-4">
            <?php if ($roleCanEdit && (isset($_GET['new']) || isset($_GET['edit']) && $selectedItem !== null)): ?>
                <?php $editing = isset($_GET['edit']) && $selectedItem !== null; ?>
                <section class="overflow-hidden rounded-xl bg-surface-container-lowest shadow-xl">
                    <div class="flex items-start justify-between gap-3 bg-primary p-4 text-on-primary">
                        <div>
                            <h3 class="font-headline-md text-headline-md font-bold"><?= $editing ? 'Edit Stock Item' : 'Add Stock Item' ?></h3>
                            <?php if ($editing): ?><p class="mt-1 font-label-sm text-label-sm text-on-primary-container"><?= h($selectedItem['name']) ?></p><?php endif; ?>
                        </div>
                        <a aria-label="Close item editor" class="flex h-9 w-9 items-center justify-center rounded-full bg-primary-container transition-colors hover:bg-primary-fixed hover:text-on-primary-fixed" href="inventory.php">
                            <span class="material-symbols-outlined text-[20px]">close</span>
                        </a>
                    </div>
                    <form action="actions/inventory-save.php" method="post" class="flex flex-col gap-3 p-4">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="item_id" value="<?= $editing ? h($selectedItem['id']) : '' ?>">
                        <label class="font-label-sm text-label-sm uppercase text-on-surface-variant" for="item-name">Item name</label>
                        <input class="h-12 rounded-lg border border-outline-variant bg-surface px-3 text-on-surface outline-none focus:ring-2 focus:ring-primary-container" id="item-name" maxlength="100" name="name" required value="<?= $editing ? h($selectedItem['name']) : '' ?>">
                        <label class="font-label-sm text-label-sm uppercase text-on-surface-variant" for="item-description">Description</label>
                        <input class="h-12 rounded-lg border border-outline-variant bg-surface px-3 text-on-surface outline-none focus:ring-2 focus:ring-primary-container" id="item-description" maxlength="255" name="description" value="<?= $editing ? h($selectedItem['description']) : '' ?>">
                        <div class="grid grid-cols-2 gap-3">
                            <div class="flex flex-col gap-1">
                                <label class="font-label-sm text-label-sm uppercase text-on-surface-variant" for="item-unit">Unit</label>
                                <select class="h-12 min-w-0 rounded-lg border border-outline-variant bg-surface px-2 text-on-surface outline-none focus:ring-2 focus:ring-primary-container" id="item-unit" name="unit_choice" data-inventory-unit required>
                                    <option value="">Choose</option>
                                    <?php foreach ($unitOptions as $unitOption): ?>
                                        <option value="<?= h($unitOption) ?>" <?= $editing && $selectedItem['unit'] === $unitOption ? 'selected' : '' ?>><?= h($unitOption) ?></option>
                                    <?php endforeach; ?>
                                    <option value="__custom__">Other (custom)</option>
                                </select>
                                <div class="hidden flex-col gap-1" data-custom-unit-field>
                                    <label class="font-label-sm text-label-sm uppercase text-on-surface-variant" for="item-unit-custom">Custom unit</label>
                                    <input class="h-12 min-w-0 rounded-lg border border-outline-variant bg-surface px-3 text-on-surface outline-none focus:ring-2 focus:ring-primary-container" id="item-unit-custom" maxlength="30" name="unit_custom" data-custom-unit placeholder="Enter unit">
                                </div>
                            </div>
                            <div class="flex flex-col gap-1">
                                <label class="font-label-sm text-label-sm uppercase text-on-surface-variant" for="item-category">Category</label>
                                <select class="h-12 min-w-0 rounded-lg border border-outline-variant bg-surface px-2 text-on-surface outline-none focus:ring-2 focus:ring-primary-container" id="item-category" name="category_id" required>
                                    <option value="">Choose</option>
                                    <?php foreach ($inventoryCategories as $category): ?>
                                        <option value="<?= h($category['id']) ?>" <?= $editing && (int) $selectedItem['category_id'] === (int) $category['id'] ? 'selected' : '' ?>><?= h($category['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div class="flex flex-col gap-1">
                                <label class="font-label-sm text-label-sm uppercase text-on-surface-variant" for="item-stock">Stock on record</label>
                                <input class="h-12 min-w-0 rounded-lg border border-outline-variant bg-surface px-3 text-on-surface outline-none focus:ring-2 focus:ring-primary-container" id="item-stock" min="0" name="current_stock" required step="0.01" type="number" value="<?= $editing ? h($selectedItem['current_stock']) : '0' ?>">
                            </div>
                            <div class="flex flex-col gap-1">
                                <label class="font-label-sm text-label-sm uppercase text-on-surface-variant" for="item-threshold">Low-stock level</label>
                                <input class="h-12 min-w-0 rounded-lg border border-outline-variant bg-surface px-3 text-on-surface outline-none focus:ring-2 focus:ring-primary-container" id="item-threshold" min="0" name="low_stock_level" required step="0.01" type="number" value="<?= $editing ? h($selectedItem['low_stock_level']) : '0' ?>">
                            </div>
                        </div>
                        <button class="mt-1 flex min-h-12 items-center justify-center gap-2 rounded-lg bg-primary px-4 font-body-md-bold text-body-md-bold text-on-primary transition-colors hover:bg-primary-container" type="submit">
                            <span class="material-symbols-outlined text-[18px]">save</span>
                            <span><?= $editing ? 'Save Changes' : 'Add Item' ?></span>
                        </button>
                    </form>
                </section>
            <?php elseif ($selectedItem !== null): ?>
                <?php
                $selectedStock = (float) $selectedItem['current_stock'];
                $selectedThreshold = (float) $selectedItem['low_stock_level'];
                $selectedOut = $selectedStock <= 0;
                $selectedLow = !$selectedOut && $selectedStock <= $selectedThreshold;
                ?>
                <section class="overflow-hidden rounded-xl bg-surface-container-lowest shadow-xl">
                    <div class="flex items-start justify-between gap-3 bg-primary p-4 text-on-primary">
                        <div class="flex flex-col">
                            <h3 class="font-headline-md text-headline-md font-bold"><?= h($selectedItem['name']) ?></h3>
                            <span class="mt-2 inline-flex w-fit items-center gap-1 rounded-full px-2 py-1 font-label-sm text-label-sm font-bold <?= $selectedOut ? 'bg-error-container text-error' : ($selectedLow ? 'bg-secondary-container text-on-secondary' : 'bg-primary-fixed text-primary') ?>">
                                <span class="material-symbols-outlined text-[14px]"><?= $selectedOut ? 'cancel' : ($selectedLow ? 'warning' : 'check_circle') ?></span>
                                <?= $selectedOut ? 'Out of Stock' : ($selectedLow ? 'Low Stock' : 'In Stock') ?>
                            </span>
                        </div>
                        <a aria-label="Close item details" class="flex h-9 w-9 items-center justify-center rounded-full bg-primary-container transition-colors hover:bg-primary-fixed hover:text-on-primary-fixed" href="inventory.php">
                            <span class="material-symbols-outlined text-[20px]">close</span>
                        </a>
                    </div>
                    <div class="flex flex-col gap-4 p-4">
                        <div class="grid grid-cols-2 gap-2">
                            <div class="flex flex-col justify-between rounded-xl bg-surface-container p-4">
                                <span class="font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">Stock on Record</span>
                                <span class="my-1 text-[32px] font-bold leading-none text-primary"><?= h(rtrim(rtrim(number_format($selectedStock, 2, '.', ''), '0'), '.')) ?></span>
                                <span class="font-label-sm text-label-sm text-on-surface-variant"><?= h($selectedItem['unit']) ?></span>
                            </div>
                            <div class="flex flex-col justify-between rounded-xl bg-secondary-fixed/40 p-4">
                                <span class="font-label-sm text-label-sm font-bold uppercase tracking-wider text-secondary">Low-stock level</span>
                                <span class="my-1 text-[32px] font-bold leading-none text-secondary"><?= h(rtrim(rtrim(number_format($selectedThreshold, 2, '.', ''), '0'), '.')) ?></span>
                                <span class="font-label-sm text-label-sm text-secondary"><?= h($selectedItem['unit']) ?></span>
                            </div>
                        </div>
                        <div class="flex flex-col gap-1 rounded-lg bg-surface-container-low p-3 font-label-sm text-label-sm text-on-surface-variant">
                            <span><strong class="text-primary">Category:</strong> <?= h($selectedItem['category_name'] ?? '') ?></span>
                            <?php if ($selectedItem['description'] !== ''): ?>
                                <span><strong class="text-primary">Description:</strong> <?= h($selectedItem['description']) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($roleCanEdit): ?>
                            <form action="actions/inventory-save.php" method="post" class="flex flex-col gap-3 rounded-xl bg-surface-container-low p-4">
                                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                <input type="hidden" name="action" value="restock">
                                <input type="hidden" name="item_id" value="<?= h($selectedItem['id']) ?>">
                                <h4 class="font-body-md-bold text-body-md-bold text-primary">Restock Intake</h4>
                                <label class="font-label-sm text-label-sm uppercase text-on-surface-variant" for="restock-quantity">Quantity to add (<?= h($selectedItem['unit']) ?>)</label>
                                <input class="h-12 rounded-lg border border-outline-variant bg-surface px-3 text-on-surface outline-none focus:ring-2 focus:ring-primary-container" id="restock-quantity" min="0.01" name="quantity" required step="0.01" type="number">
                                <label class="font-label-sm text-label-sm uppercase text-on-surface-variant" for="restock-note">Note (optional)</label>
                                <input class="h-12 rounded-lg border border-outline-variant bg-surface px-3 text-on-surface outline-none focus:ring-2 focus:ring-primary-container" id="restock-note" maxlength="255" name="note" placeholder="Delivery or prep note">
                                <button class="flex min-h-12 items-center justify-center gap-2 rounded-lg bg-primary px-4 font-body-md-bold text-body-md-bold text-on-primary transition-colors hover:bg-primary-container" type="submit">
                                    <span class="material-symbols-outlined text-[18px]">add</span>
                                    <span>Save Restock</span>
                                </button>
                            </form>
                            <a class="flex min-h-12 items-center justify-center gap-2 rounded-lg bg-surface-container-high px-4 font-body-md-bold text-body-md-bold text-primary transition-colors hover:bg-surface-container-highest" href="inventory.php?edit=1&amp;id=<?= h($selectedItem['id']) ?>">
                                <span class="material-symbols-outlined text-[18px]">edit</span>
                                <span>Edit Item &amp; Threshold</span>
                            </a>
                        <?php else: ?>
                            <p class="rounded-lg bg-surface-container p-3 font-label-md text-label-md text-on-surface-variant">Only an admin can add items or change stock.</p>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section class="flex flex-col gap-3 rounded-xl bg-surface-container-lowest p-4 shadow-sm">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-[20px] text-primary">history</span>
                        <h3 class="font-headline-sm text-headline-sm font-bold text-primary">Recent Stock Activity</h3>
                    </div>
                </div>
                <?php if ($recentMovements === []): ?>
                    <p class="rounded-lg bg-surface-container-low p-3 font-label-md text-label-md text-on-surface-variant">No inventory movements have been recorded yet.</p>
                <?php else: ?>
                    <div class="flex flex-col gap-2">
                        <?php foreach ($recentMovements as $movement): ?>
                            <?php
                            $movementTypeLabels = [
                                'order_deduction' => 'Order deduction',
                                'restock' => 'Restock',
                                'manual_adjustment' => 'Stock adjustment',
                                'cancellation_restore' => 'Cancellation restore',
                                'waste' => 'Waste',
                            ];
                            $change = (float) $movement['quantity_change'];
                            ?>
                            <article class="flex items-start justify-between gap-3 rounded-lg bg-surface-container-low p-3">
                                <div class="min-w-0">
                                    <span class="block truncate font-body-md-bold text-body-md-bold text-primary"><?= h($movement['item_name']) ?></span>
                                    <span class="block font-label-sm text-label-sm text-on-surface-variant"><?= h($movementTypeLabels[$movement['movement_type']] ?? $movement['movement_type']) ?><?= $movement['order_number'] ? ' • ' . h($movement['order_number']) : '' ?></span>
                                    <?php if ($movement['note']): ?><span class="block break-words font-label-sm text-label-sm text-on-surface-variant"><?= h($movement['note']) ?></span><?php endif; ?>
                                </div>
                                <div class="shrink-0 text-right">
                                    <span class="block font-body-md-bold text-body-md-bold <?= $change < 0 ? 'text-error' : 'text-tertiary-container' ?>"><?= $change > 0 ? '+' : '' ?><?= h(rtrim(rtrim(number_format($change, 2, '.', ''), '0'), '.')) ?></span>
                                    <time class="font-label-sm text-label-sm text-on-surface-variant" datetime="<?= h(date(DATE_ATOM, strtotime((string) $movement['created_at']))) ?>"><?= h(date('g:i A', strtotime((string) $movement['created_at']))) ?></time>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
            <?php if ($roleCanEdit): ?>
                <section class="flex flex-col gap-3 rounded-xl bg-surface-container-lowest p-4 shadow-sm">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-[20px] text-primary">receipt_long</span>
                        <h3 class="font-headline-sm text-headline-sm font-bold text-primary">Movements</h3>
                    </div>
                    <span class="font-label-sm text-label-sm text-on-surface-variant">Latest 100</span>
                </div>
                <?php if ($movementHistory === []): ?>
                    <p class="rounded-lg bg-surface-container-low p-3 font-label-md text-label-md text-on-surface-variant">No inventory movements have been recorded yet.</p>
                <?php else: ?>
                    <div class="max-h-[32rem] overflow-auto rounded-lg border border-surface-container">
                        <table class="w-full min-w-[680px] border-collapse text-left">
                            <thead class="sticky top-0 bg-surface-container-low font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">
                                <tr>
                                    <th class="px-3 py-2">Date</th>
                                    <th class="px-3 py-2">Ingredient</th>
                                    <th class="px-3 py-2 text-right">Change</th>
                                    <th class="px-3 py-2">Type</th>
                                    <th class="px-3 py-2">Order</th>
                                    <th class="px-3 py-2">User</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-surface-container font-label-md text-label-md text-on-surface">
                                <?php foreach ($movementHistory as $movement): ?>
                                    <?php
                                    $movementTypeLabel = [
                                        'order_deduction' => 'Order deduction',
                                        'restock' => 'Restock',
                                        'manual_adjustment' => 'Stock adjustment',
                                        'cancellation_restore' => 'Cancellation restore',
                                        'waste' => 'Waste',
                                    ][$movement['movement_type']] ?? $movement['movement_type'];
                                    $change = (float) $movement['quantity_change'];
                                    ?>
                                    <tr>
                                        <td class="whitespace-nowrap px-3 py-2"><?= h(date('M j, Y g:i A', strtotime((string) $movement['created_at']))) ?></td>
                                        <td class="px-3 py-2">
                                            <span class="block font-body-md-bold text-body-md-bold"><?= h($movement['item_name']) ?></span>
                                            <span class="font-label-sm text-label-sm text-on-surface-variant"><?= h($movement['unit']) ?></span>
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-2 text-right font-body-md-bold text-body-md-bold <?= $change < 0 ? 'text-error' : ($change > 0 ? 'text-tertiary-container' : 'text-on-surface-variant') ?>">
                                            <?= $change > 0 ? '+' : '' ?><?= h(rtrim(rtrim(number_format($change, 2, '.', ''), '0'), '.')) ?>
                                        </td>
                                        <td class="px-3 py-2">
                                            <span class="block"><?= h($movementTypeLabel) ?></span>
                                            <?php if ($movement['note']): ?>
                                                <span class="block max-w-56 break-words font-label-sm text-label-sm text-on-surface-variant"><?= h($movement['note']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-2"><?= h($movement['order_number'] ?: '—') ?></td>
                                        <td class="whitespace-nowrap px-3 py-2"><?= h($movement['user_name'] ?: 'System / unknown') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                </section>
            <?php endif; ?>
        </aside>
        <?php endif; ?>
    </div>

    <footer class="flex flex-col items-center justify-between gap-3 rounded-xl border border-outline-variant/30 bg-surface-container-low px-6 py-4 font-label-md text-label-md text-on-surface-variant sm:flex-row">
        <div class="flex items-center gap-2">
            <span class="material-symbols-outlined text-[18px] text-primary">sync</span>
            <span>Stock changes are recorded in the inventory activity log.</span>
        </div>
        <span>Stock auto-deductions are recorded when orders are completed.</span>
    </footer>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
