<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: dashboard.php');
    exit;
}

require_once __DIR__ . '/config/db.php';

$errorMessage = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])
        ? $_POST['csrf_token']
        : null;

    if (!verify_csrf_token($submittedToken)) {
        http_response_code(400);
        $errorMessage = 'Your session expired. Refresh the page and try again.';
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = $_POST['password'] ?? null;

        if (
            $username === ''
            || strlen($username) > 50
            || !is_string($password)
            || $password === ''
            || strlen($password) > 255
        ) {
            $errorMessage = 'Enter a valid username and password.';
        } else {
            $statement = $pdo->prepare(
                'SELECT id, username, full_name, password_hash, role
                 FROM users
                 WHERE username = :username AND is_active = 1
                 LIMIT 1'
            );
            $statement->execute(['username' => $username]);
            $user = $statement->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                header('Location: dashboard.php');
                exit;
            }

            $errorMessage = 'The username or password is incorrect.';
        }
    }
}

function login_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#edf6f2">
    <title>Staff Login | Hungry Potters</title>
    <link rel="icon" type="image/png" href="assets/images/logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@100..900&amp;family=Work+Sans:wght@100..900&amp;display=swap" rel="stylesheet">
    <link href="assets/css/app.css?v=<?= filemtime(__DIR__ . '/assets/css/app.css') ?>" rel="stylesheet">
</head>
<body class="login-page">
    <div class="login-scene" aria-hidden="true">
        <div class="login-cluster login-cluster-chicken">
            <img class="login-garnish login-basil-chicken" src="assets/images/login-basil-sprig.png" alt="">
            <img class="login-food login-food-chicken" src="../images/fried%20chicken%20no%20bg.png" alt="">
        </div>
        <div class="login-cluster login-cluster-sisig">
            <img class="login-garnish login-basil-sisig" src="assets/images/login-basil-sprig.png" alt="">
            <img class="login-food login-food-sisig" src="../images/sisig%20no%20bg.png" alt="">
            <img class="login-garnish login-tomatoes login-tomatoes-sisig" src="assets/images/login-cherry-tomato-slices.png" alt="">
        </div>
        <div class="login-cluster login-cluster-egg">
            <img class="login-garnish login-basil-egg" src="assets/images/login-basil-sprig.png" alt="">
            <img class="login-food login-food-egg" src="../images/egg%20no%20bg.png" alt="">
            <img class="login-garnish login-tomatoes login-tomatoes-egg" src="assets/images/login-cherry-tomato-slices.png" alt="">
        </div>
    </div>

    <main class="login-main">
        <section class="login-card" aria-labelledby="login-title">
            <div class="login-brand">
                <span class="login-brand-mark">
                    <img alt="Hungry Potters logo" src="assets/images/logo.png">
                </span>
                <h1>Hungry Potters</h1>
                <p>Operations &amp; Kitchen</p>
            </div>

            <div class="login-content">
                <div class="login-intro">
                    <h2 id="login-title">Staff Login</h2>
                    <p>Sign in to open your operations workspace.</p>
                </div>

                <?php if ($errorMessage !== ''): ?>
                    <div class="login-error" role="alert">
                        <?= login_h($errorMessage) ?>
                    </div>
                <?php endif; ?>

                <form action="login.php" method="post" class="login-form">
                    <input type="hidden" name="csrf_token" value="<?= login_h(csrf_token()) ?>">
                    <div class="login-field">
                        <label for="username">Username</label>
                        <input
                            autocomplete="username"
                            id="username"
                            maxlength="50"
                            name="username"
                            required
                            value="<?= login_h($username) ?>"
                        >
                    </div>
                    <div class="login-field">
                        <label for="password">Password</label>
                        <input
                            autocomplete="current-password"
                            id="password"
                            maxlength="255"
                            name="password"
                            required
                            type="password"
                        >
                    </div>
                    <button class="login-submit" type="submit">
                        <span>Sign In</span>
                        <svg aria-hidden="true" fill="none" viewBox="0 0 24 24">
                            <path d="M5 12h14m-7-7 7 7-7 7" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/>
                        </svg>
                    </button>
                </form>
            </div>
        </section>
    </main>
</body>
</html>
