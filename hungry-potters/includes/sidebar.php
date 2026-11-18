<?php
declare(strict_types=1);

$currentPage = $currentPage ?? '';
$navigationItems = [
    ['page' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard', 'href' => 'dashboard.php'],
    ['page' => 'orders', 'label' => 'Orders', 'icon' => 'receipt_long', 'href' => 'orders.php'],
    ['page' => 'new-order', 'label' => 'New Order', 'icon' => 'add_circle', 'href' => 'new-order.php'],
    ['page' => 'kitchen-display', 'label' => 'Kitchen Display', 'icon' => 'skillet', 'href' => 'kitchen.php'],
    ['page' => 'inventory', 'label' => 'Inventory', 'icon' => 'inventory_2', 'href' => 'inventory.php'],
];
if (in_array($_SESSION['role'] ?? '', ['admin', 'cashier'], true)) {
    $navigationItems[] = ['page' => 'register', 'label' => 'Register', 'icon' => 'point_of_sale', 'href' => 'register.php'];
}
if (($_SESSION['role'] ?? '') === 'admin') {
    $navigationItems[] = ['page' => 'menu-management', 'label' => 'Menu Management', 'icon' => 'restaurant_menu', 'href' => 'menu-management.php'];
    $navigationItems[] = ['page' => 'user-management', 'label' => 'User Management', 'icon' => 'manage_accounts', 'href' => 'user-management.php'];
}
?>
<aside class="fixed left-0 top-0 z-50 flex h-full w-72 flex-col bg-surface-container-low shadow-[0_1px_8px_rgba(0,0,0,0.04)]">
    <div class="flex h-20 items-center gap-2 bg-surface-container-low px-6">
        <img
            alt="Hungry Potters logo"
            class="h-12 w-12 shrink-0 object-contain"
            src="assets/images/logo.png"
        />
        <div class="flex min-w-0 flex-col">
            <span class="truncate font-headline-sm text-headline-sm tracking-tight text-primary">Hungry Potters</span>
            <span class="truncate font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">Operations &amp; Kitchen</span>
        </div>
    </div>
    <div class="px-4 py-2"><div class="h-px bg-surface-container-highest"></div></div>
    <nav class="flex flex-1 flex-col gap-1 overflow-y-auto px-4 py-2">
        <?php foreach ($navigationItems as $item): ?>
            <?php $isActive = $currentPage === $item['page']; ?>
            <a
                aria-current="<?= $isActive ? 'page' : 'false' ?>"
                class="flex min-h-12 items-center gap-4 rounded-lg px-4 py-2 transition-colors <?= $isActive ? 'bg-primary-container font-body-md-bold text-on-primary shadow-sm' : 'font-body-md text-on-surface-variant hover:bg-surface-container-high hover:text-on-surface' ?>"
                href="<?= h($item['href']) ?>"
            >
                <span class="material-symbols-outlined text-[22px]"><?= h($item['icon']) ?></span>
                <span class="truncate"><?= h($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="bg-surface-container-low p-4">
        <div class="flex items-center justify-between rounded-xl bg-surface-container p-4">
            <div class="flex flex-col">
                <span class="font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">Terminal Status</span>
                <span class="flex items-center gap-1 font-body-md-bold text-body-md-bold text-primary">
                    <span class="inline-block h-2 w-2 rounded-full bg-secondary-container"></span>
                    Online &bull; POS-01
                </span>
            </div>
            <span class="material-symbols-outlined text-[20px] text-on-surface-variant">sensors</span>
        </div>
    </div>
</aside>
