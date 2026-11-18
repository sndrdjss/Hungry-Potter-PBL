<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

$dbHost = getenv('HP_DB_HOST') ?: 'localhost';
$dbName = getenv('HP_DB_NAME') ?: 'hungry_potters';
$dbUser = getenv('HP_DB_USER') ?: 'root';
$dbPass = getenv('HP_DB_PASS') ?: '';

$dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
    $pdo->exec("SET time_zone = '+08:00'");
} catch (PDOException $exception) {
    error_log('Database connection failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Database connection failed. Check the XAMPP MySQL service and config/db.php settings.');
}
