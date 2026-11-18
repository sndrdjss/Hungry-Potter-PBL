<?php
declare(strict_types=1);

function payment_parse_cents(mixed $value): ?int
{
    if (!is_string($value) && !is_int($value)) {
        return null;
    }
    $text = (string) $value;
    if (!preg_match('/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,2}))?$/D', $text, $matches)) {
        return null;
    }
    return (int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '', 2, '0');
}

function payment_format(int $cents): string
{
    return number_format($cents / 100, 2, '.', ',');
}

function payment_parse_signed_cents(string $value): ?int
{
    $negative = str_starts_with($value, '-');
    $text = $negative ? substr($value, 1) : $value;
    if (!preg_match('/^(0|[1-9][0-9]{0,12})(?:\.([0-9]{1,2}))?$/D', $text, $matches)) {
        return null;
    }
    $amount = (int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '', 2, '0');
    return $negative ? -$amount : $amount;
}

function payment_sql_amount(int $cents): string
{
    return number_format($cents / 100, 2, '.', '');
}

function payment_transactions_for_order(PDO $pdo, int $orderId): array
{
    $statement = $pdo->prepare(
        'SELECT transactions.*, users.full_name AS staff_name
         FROM payment_transactions transactions
         LEFT JOIN users ON users.id = transactions.recorded_by
         WHERE transactions.order_id = :order_id
         ORDER BY transactions.occurred_at, transactions.id'
    );
    $statement->execute(['order_id' => $orderId]);
    return $statement->fetchAll();
}

function payment_summary(array $order, array $transactions): array
{
    $total = payment_parse_cents((string) $order['total']) ?? 0;
    $paid = 0;
    $refunded = 0;
    $goodwill = 0;
    $failed = 0;
    foreach ($transactions as $transaction) {
        $amount = payment_parse_cents((string) $transaction['amount']) ?? 0;
        if ($transaction['kind'] === 'payment') {
            $paid += $amount;
        } elseif ($transaction['kind'] === 'refund') {
            $refunded += $amount;
            if ($transaction['refund_kind'] === 'goodwill') {
                $goodwill += $amount;
            }
        } else {
            $failed++;
        }
    }
    $net = $paid - $refunded;
    $payable = $order['status'] === 'cancelled' ? 0 : max(0, $total - $goodwill);
    $balance = $payable - $net;
    if ($order['payment_history_state'] === 'legacy_unknown') {
        $status = 'Payment history unknown';
    } elseif ($balance < 0) {
        $status = 'Refund due';
    } elseif ($balance > 0) {
        $status = $net > 0 ? 'Partially paid' : 'Unpaid';
    } elseif ($order['status'] === 'cancelled') {
        $status = $paid > 0 ? 'Refunded' : 'No payment collected';
    } else {
        $status = 'Paid';
    }
    return compact('total', 'paid', 'refunded', 'goodwill', 'failed', 'net', 'payable', 'balance', 'status');
}

function payment_shift(PDO $pdo): ?array
{
    $statement = $pdo->query(
        'SELECT shifts.*, registers.code, opener.full_name AS opener_name
         FROM registers
         JOIN register_shifts shifts ON shifts.id = registers.current_shift_id
         JOIN users opener ON opener.id = shifts.opened_by
         WHERE registers.id = 1 AND shifts.closed_at IS NULL'
    );
    return $statement->fetch() ?: null;
}

function payment_shift_totals(PDO $pdo, int $shiftId): array
{
    $statement = $pdo->prepare(
        "SELECT method, kind, COALESCE(SUM(amount), 0) AS amount, COUNT(*) AS transactions
         FROM payment_transactions
         WHERE shift_id = :shift_id AND kind IN ('payment', 'refund')
         GROUP BY method, kind"
    );
    $statement->execute(['shift_id' => $shiftId]);
    $totals = ['cash' => 0, 'card' => 0, 'ewallet' => 0];
    foreach ($statement->fetchAll() as $row) {
        $amount = payment_parse_signed_cents((string) $row['amount']) ?? 0;
        $totals[$row['method']] += $row['kind'] === 'refund' ? -$amount : $amount;
    }
    return $totals;
}

function payment_open_balances(PDO $pdo): array
{
    $statement = $pdo->query(
        "SELECT orders.id, orders.status, orders.total,
            COALESCE(SUM(CASE WHEN transactions.kind = 'payment' THEN transactions.amount ELSE 0 END), 0) AS paid,
            COALESCE(SUM(CASE WHEN transactions.kind = 'refund' THEN transactions.amount ELSE 0 END), 0) AS refunded,
            COALESCE(SUM(CASE WHEN transactions.kind = 'refund' AND transactions.refund_kind = 'goodwill' THEN transactions.amount ELSE 0 END), 0) AS goodwill
         FROM orders
         LEFT JOIN payment_transactions transactions ON transactions.order_id = orders.id
         WHERE orders.payment_history_state <> 'legacy_unknown'
         GROUP BY orders.id, orders.status, orders.total"
    );
    $balances = ['amount_due' => 0, 'refund_due' => 0, 'unpaid_orders' => 0, 'refund_orders' => 0];
    foreach ($statement->fetchAll() as $row) {
        $payable = $row['status'] === 'cancelled' ? 0 : (payment_parse_cents((string) $row['total']) ?? 0) - (payment_parse_cents((string) $row['goodwill']) ?? 0);
        $net = (payment_parse_signed_cents((string) $row['paid']) ?? 0) - (payment_parse_signed_cents((string) $row['refunded']) ?? 0);
        $balance = $payable - $net;
        if ($balance > 0) {
            $balances['amount_due'] += $balance;
            $balances['unpaid_orders']++;
        } elseif ($balance < 0) {
            $balances['refund_due'] += -$balance;
            $balances['refund_orders']++;
        }
    }
    return $balances;
}
