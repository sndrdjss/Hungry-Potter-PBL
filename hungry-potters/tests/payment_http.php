<?php
declare(strict_types=1);

// Run against a disposable database and a PHP server using the same HP_DB_NAME.
$database = getenv('HP_DB_NAME');
$baseUrl = getenv('HP_TEST_BASE_URL');
if ($database !== 'hungry_potters_payment_test' || !$baseUrl) {
    exit("Set HP_DB_NAME=hungry_potters_payment_test and HP_TEST_BASE_URL to the test server.\n");
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/payments.php';

function must(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function client(string $baseUrl): CurlHandle
{
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEFILE => '',
        CURLOPT_URL => $baseUrl,
    ]);
    return $curl;
}

function page(CurlHandle $curl, string $baseUrl, string $path, ?array $fields = null): string
{
    curl_setopt($curl, CURLOPT_URL, rtrim($baseUrl, '/') . '/' . ltrim($path, '/'));
    if ($fields === null) {
        curl_setopt($curl, CURLOPT_HTTPGET, true);
    } else {
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    $body = curl_exec($curl);
    must(is_string($body), 'HTTP request failed: ' . curl_error($curl));
    must(curl_getinfo($curl, CURLINFO_HTTP_CODE) === 200, 'Unexpected HTTP status for ' . $path);
    return $body;
}

function token(string $html): string
{
    must(preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $html, $match) === 1, 'CSRF token missing.');
    return $match[1];
}

function place_order(CurlHandle $curl, string $baseUrl, string $csrf): int
{
    curl_setopt($curl, CURLOPT_URL, rtrim($baseUrl, '/') . '/actions/order-place.php');
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode([
        'csrf_token' => $csrf,
        'order_type' => 'takeout',
        'location' => '',
        'kitchen_note' => '',
        'items' => [['menu_item_id' => 6, 'quantity' => 1]],
    ], JSON_THROW_ON_ERROR));
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_setopt($curl, CURLOPT_HTTPHEADER, []);
    must(is_string($body) && $status === 201, 'Order placement failed.');
    $result = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    must(!empty($result['order_id']), 'Placed order has no ID.');
    return (int) $result['order_id'];
}

function new_order(PDO $pdo, int $cents, string $status = 'placed', string $paymentState = 'tracked'): int
{
    $queue = (int) $pdo->query('SELECT COALESCE(MAX(queue_number), 0) + 1 FROM orders')->fetchColumn();
    $number = 'PT-' . strtoupper(bin2hex(random_bytes(5)));
    $insert = $pdo->prepare(
        'INSERT INTO orders (order_number, queue_number, order_type, status, subtotal, total,
                             payment_history_state, created_by)
         VALUES (:number, :queue, \'takeout\', :status, :subtotal, :total, :payment_state, 2)'
    );
    $insert->execute([
        'number' => $number, 'queue' => $queue, 'status' => $status,
        'subtotal' => payment_sql_amount($cents), 'total' => payment_sql_amount($cents),
        'payment_state' => $paymentState,
    ]);
    return (int) $pdo->lastInsertId();
}

function submit_payment(CurlHandle $curl, string $baseUrl, int $orderId, string $csrf, array $fields): void
{
    page($curl, $baseUrl, 'actions/payment-save.php', array_merge([
        'csrf_token' => $csrf,
        'request_key' => bin2hex(random_bytes(16)),
        'order_id' => $orderId,
    ], $fields));
}

$cashier = client($baseUrl);
$cashierToken = token(page($cashier, $baseUrl, 'login.php'));
page($cashier, $baseUrl, 'login.php', [
    'csrf_token' => $cashierToken, 'username' => 'cashier', 'password' => 'password',
]);
$cashierToken = token(page($cashier, $baseUrl, 'register.php'));
page($cashier, $baseUrl, 'actions/shift-save.php', [
    'csrf_token' => $cashierToken, 'action' => 'open', 'opening_float' => '1000.00',
]);
$shift = payment_shift($pdo);
must($shift !== null, 'Cashier could not open a shift.');
$shiftId = (int) $shift['id'];

$orderId = new_order($pdo, 10000);
$cashierToken = token(page($cashier, $baseUrl, 'payments.php?order_id=' . $orderId));
submit_payment($cashier, $baseUrl, $orderId, $cashierToken, [
    'action' => 'failed', 'method' => 'card', 'amount' => '100.00', 'reason' => 'Terminal declined',
]);
submit_payment($cashier, $baseUrl, $orderId, $cashierToken, [
    'action' => 'payment', 'method' => 'cash', 'amount' => '40.00', 'tendered_amount' => '50.00',
]);
$duplicateKey = bin2hex(random_bytes(16));
$cardFields = [
    'csrf_token' => $cashierToken, 'request_key' => $duplicateKey, 'order_id' => $orderId,
    'action' => 'payment', 'method' => 'card', 'amount' => '60.00', 'reference' => 'TEST-CARD-001',
];
page($cashier, $baseUrl, 'actions/payment-save.php', $cardFields);
page($cashier, $baseUrl, 'actions/payment-save.php', $cardFields);
$rows = payment_transactions_for_order($pdo, $orderId);
must(count($rows) === 3, 'Duplicate submission created a second transaction.');
$order = $pdo->query('SELECT status, total, payment_history_state FROM orders WHERE id = ' . $orderId)->fetch();
$summary = payment_summary($order, $rows);
must($summary['status'] === 'Paid' && $summary['failed'] === 1, 'Payment or failed attempt balance is wrong.');
$cash = array_values(array_filter($rows, static fn (array $row): bool => $row['method'] === 'cash'))[0];
must($cash['change_amount'] === '10.00', 'Cash change was not recorded.');
$card = array_values(array_filter($rows, static fn (array $row): bool => $row['method'] === 'card' && $row['kind'] === 'payment'))[0];

$pdo->prepare("UPDATE orders SET status = 'completed', completed_at = NOW() WHERE id = :id")->execute(['id' => $orderId]);
$admin = client($baseUrl);
$adminToken = token(page($admin, $baseUrl, 'login.php'));
page($admin, $baseUrl, 'login.php', [
    'csrf_token' => $adminToken, 'username' => 'admin', 'password' => 'password',
]);
$adminToken = token(page($admin, $baseUrl, 'payments.php?order_id=' . $orderId));
submit_payment($admin, $baseUrl, $orderId, $adminToken, [
    'action' => 'refund', 'method' => 'card', 'amount' => '20.00',
    'refund_of_id' => $card['id'], 'reference' => 'TEST-REFUND-001', 'reason' => 'Service recovery',
]);
$rows = payment_transactions_for_order($pdo, $orderId);
$order = $pdo->query('SELECT status, total, payment_history_state FROM orders WHERE id = ' . $orderId)->fetch();
$summary = payment_summary($order, $rows);
must($summary['status'] === 'Paid' && $summary['net'] === 8000, 'Goodwill refund created a false balance.');

$cancelledId = new_order($pdo, 5000);
submit_payment($cashier, $baseUrl, $cancelledId, $cashierToken, [
    'action' => 'payment', 'method' => 'cash', 'amount' => '50.00', 'tendered_amount' => '50.00',
]);
$cancelPayment = payment_transactions_for_order($pdo, $cancelledId)[0];
$pdo->prepare("UPDATE orders SET status = 'cancelled', cancelled_at = NOW() WHERE id = :id")->execute(['id' => $cancelledId]);
submit_payment($admin, $baseUrl, $cancelledId, $adminToken, [
    'action' => 'refund', 'method' => 'cash', 'amount' => '50.00',
    'refund_of_id' => $cancelPayment['id'], 'reason' => 'Cancelled order',
]);
$cancelled = $pdo->query('SELECT status, total, payment_history_state FROM orders WHERE id = ' . $cancelledId)->fetch();
must(payment_summary($cancelled, payment_transactions_for_order($pdo, $cancelledId))['status'] === 'Refunded', 'Cancelled order was not refunded.');
$totals = payment_shift_totals($pdo, $shiftId);
must($totals['cash'] === 4000 && $totals['card'] === 4000, 'Shift method totals are wrong.');

$legacyId = new_order($pdo, 7000, 'cancelled', 'legacy_unknown');
$past = date('Y-m-d\TH:i', strtotime('-1 day'));
submit_payment($admin, $baseUrl, $legacyId, $adminToken, [
    'action' => 'historical_payment', 'method' => 'cash', 'amount' => '70.00',
    'occurred_at' => $past, 'reason' => 'Old receipt verified',
]);
$legacyPayment = payment_transactions_for_order($pdo, $legacyId)[0];
submit_payment($admin, $baseUrl, $legacyId, $adminToken, [
    'action' => 'historical_refund', 'method' => 'cash', 'amount' => '70.00',
    'refund_of_id' => $legacyPayment['id'], 'occurred_at' => $past, 'reason' => 'Old refund verified',
]);
submit_payment($admin, $baseUrl, $legacyId, $adminToken, [
    'action' => 'reconcile', 'reason' => 'Historical entries verified against drawer records',
]);
$legacy = $pdo->query('SELECT status, total, payment_history_state FROM orders WHERE id = ' . $legacyId)->fetch();
$legacyRows = payment_transactions_for_order($pdo, $legacyId);
must($legacy['payment_history_state'] === 'reconciled' && payment_summary($legacy, $legacyRows)['status'] === 'Refunded', 'Legacy backfill failed.');
must($legacyRows[0]['shift_id'] === null && $legacyRows[1]['shift_id'] === null, 'Historical entries affected a live shift.');
must(payment_shift_totals($pdo, $shiftId) === $totals, 'Historical entries changed the live shift totals.');

page($admin, $baseUrl, 'actions/shift-save.php', [
    'csrf_token' => $adminToken, 'action' => 'close', 'counted_cash' => '1035.00',
]);
must(payment_shift($pdo) !== null, 'Shift closed without a variance note.');

page($admin, $baseUrl, 'actions/shift-save.php', [
    'csrf_token' => $adminToken, 'action' => 'close',
    'counted_cash' => '1035.00', 'close_note' => 'Test count short by five pesos',
]);
$closed = $pdo->query('SELECT expected_cash, variance FROM register_shifts WHERE id = ' . $shiftId)->fetch();
must($closed['expected_cash'] === '1040.00' && $closed['variance'] === '-5.00', 'Shift reconciliation is wrong.');
$afterClose = new_order($pdo, 1000);
submit_payment($cashier, $baseUrl, $afterClose, $cashierToken, [
    'action' => 'payment', 'method' => 'cash', 'amount' => '10.00', 'tendered_amount' => '10.00',
]);
must(payment_transactions_for_order($pdo, $afterClose) === [], 'Payment posted to a closed shift.');

// Use the real POS, edit, and cancellation endpoints to verify balance changes.
page($cashier, $baseUrl, 'actions/shift-save.php', [
    'csrf_token' => $cashierToken, 'action' => 'open', 'opening_float' => '0.00',
]);
$editShift = payment_shift($pdo);
must($editShift !== null, 'Second test shift did not open.');
$editableId = place_order($cashier, $baseUrl, $cashierToken);
$line = $pdo->query('SELECT id FROM order_items WHERE order_id = ' . $editableId)->fetch();
must($line !== false, 'POS order has no item line.');
page($cashier, $baseUrl, 'actions/order-edit.php', [
    'csrf_token' => $cashierToken, 'order_id' => $editableId,
    'existing_items' => [(string) $line['id'] => 2],
    'expected_items' => [(string) $line['id'] => 1],
]);
$editedTotal = $pdo->query('SELECT total FROM orders WHERE id = ' . $editableId)->fetchColumn();
must($editedTotal === '70.00', 'Order increase did not change the total.');
submit_payment($cashier, $baseUrl, $editableId, $cashierToken, [
    'action' => 'payment', 'method' => 'cash', 'amount' => '70.00', 'tendered_amount' => '70.00',
]);
page($cashier, $baseUrl, 'actions/order-edit.php', [
    'csrf_token' => $cashierToken, 'order_id' => $editableId,
    'existing_items' => [(string) $line['id'] => 1],
    'expected_items' => [(string) $line['id'] => 2],
]);
$editedOrder = $pdo->query('SELECT status, total, payment_history_state FROM orders WHERE id = ' . $editableId)->fetch();
$editedRows = payment_transactions_for_order($pdo, $editableId);
must($editedOrder['total'] === '35.00' && payment_summary($editedOrder, $editedRows)['balance'] === -3500, 'Paid order decrease did not create refund due.');
submit_payment($admin, $baseUrl, $editableId, $adminToken, [
    'action' => 'refund', 'method' => 'cash', 'amount' => '35.00',
    'refund_of_id' => $editedRows[0]['id'], 'reason' => 'Order reduced',
]);
page($cashier, $baseUrl, 'actions/order-cancel.php', [
    'csrf_token' => $cashierToken, 'order_id' => $editableId,
    'cancel_reason_type' => 'Customer cancelled', 'cancel_mode' => 'restore',
]);
$cancelledOrder = $pdo->query('SELECT status, total, payment_history_state, inventory_restored FROM orders WHERE id = ' . $editableId)->fetch();
must($cancelledOrder['status'] === 'cancelled' && (int) $cancelledOrder['inventory_restored'] === 1, 'Real cancellation did not restore stock.');
must(payment_summary($cancelledOrder, payment_transactions_for_order($pdo, $editableId))['balance'] === -3500, 'Cancellation did not create remaining refund due.');
submit_payment($admin, $baseUrl, $editableId, $adminToken, [
    'action' => 'refund', 'method' => 'cash', 'amount' => '35.00',
    'refund_of_id' => $editedRows[0]['id'], 'reason' => 'Cancelled after edit',
]);
must(payment_summary($cancelledOrder, payment_transactions_for_order($pdo, $editableId))['status'] === 'Refunded', 'Second refund did not settle cancellation.');
page($admin, $baseUrl, 'actions/shift-save.php', [
    'csrf_token' => $adminToken, 'action' => 'close', 'counted_cash' => '0.00',
]);
must(payment_shift($pdo) === null, 'Second shift did not close.');
$dashboard = page($admin, $baseUrl, 'dashboard.php');
must(str_contains($dashboard, 'Net Collected Today'), 'Dashboard did not show payment-based collections.');
$orderDetails = page($admin, $baseUrl, 'orders.php?id=' . $editableId);
must(str_contains($orderDetails, 'Payment · Refunded'), 'Order details did not show the refund status.');
$slip = $pdo->query('SELECT slip_number FROM orders WHERE id = ' . $editableId)->fetchColumn();
$printed = page($admin, $baseUrl, 'order-slip.php?slip=' . rawurlencode((string) $slip));
must(str_contains($printed, 'Payment status') && str_contains($printed, 'Refunded'), 'Takeout slip did not show payment history.');

curl_close($cashier);
curl_close($admin);
echo "Payment HTTP integration passed.\n";
