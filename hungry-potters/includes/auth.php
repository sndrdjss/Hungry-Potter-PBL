<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    $isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    session_set_cookie_params([
        'httponly' => true,
        'secure' => $isSecure,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function require_login(): void
{
    if (!isset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'])) {
        header('Location: login.php');
        exit;
    }
}

function require_role(array $allowedRoles): void
{
    require_login();

    if (!in_array($_SESSION['role'], $allowedRoles, true)) {
        http_response_code(403);
        require __DIR__ . '/access-denied.php';
        exit;
    }
}

function refresh_account_for_money_actions(PDO $pdo): void
{
    require_login();
    $statement = $pdo->prepare('SELECT role, full_name, is_active FROM users WHERE id = :id');
    $statement->execute(['id' => $_SESSION['user_id']]);
    $user = $statement->fetch();
    if (!$user || (int) $user['is_active'] !== 1) {
        http_response_code(403);
        exit('This staff account is inactive. Sign out and contact an admin.');
    }
    $_SESSION['role'] = $user['role'];
    $_SESSION['full_name'] = $user['full_name'];
}

function h(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf_token(?string $submittedToken): bool
{
    return isset($_SESSION['csrf_token'])
        && is_string($submittedToken)
        && hash_equals($_SESSION['csrf_token'], $submittedToken);
}
