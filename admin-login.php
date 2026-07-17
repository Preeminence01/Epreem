<?php

declare(strict_types=1);

require __DIR__ . '/db.php';

session_start();

if (in_array($_SESSION['epreem_role'] ?? null, ['admin', 'super_admin'], true)) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Enter your admin email and password.';
    } else {
        try {
            $pdo = db();
            $superAdminEmail = 'eternalpreeminence01@gmail.com';
            $superAdminPassword = 'Eternal@123';
            $stmt = $pdo->prepare('SELECT id, name, email, password, role FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (strcasecmp($email, $superAdminEmail) === 0 && $password === $superAdminPassword) {
                $upsert = $pdo->prepare(
                    "INSERT INTO users (name, email, password, role, reason_for_joining, verification_status, created_at, updated_at)
                     VALUES (?, ?, ?, 'super_admin', 'buyer', 'verified', NOW(), NOW())
                     ON DUPLICATE KEY UPDATE name = VALUES(name), password = VALUES(password), role = 'super_admin',
                         reason_for_joining = 'buyer', verification_status = 'verified', updated_at = NOW()"
                );
                $upsert->execute([
                    'EPREEM Super Admin',
                    $superAdminEmail,
                    password_hash($superAdminPassword, PASSWORD_DEFAULT),
                ]);

                $stmt->execute([$superAdminEmail]);
                $user = $stmt->fetch();
            }

            if ($user && in_array($user['role'], ['admin', 'super_admin'], true) && password_verify($password, $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['epreem_user_id'] = (int) $user['id'];
                $_SESSION['epreem_name'] = $user['name'];
                $_SESSION['epreem_email'] = $user['email'];
                $_SESSION['epreem_role'] = $user['role'];

                header('Location: dashboard.php');
                exit;
            }

            $error = 'Invalid admin credentials.';
        } catch (Throwable $exception) {
            $error = 'Admin login is not ready. Import the database schema and make sure MySQL is running.';
        }
    }
}

function epreem_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<link rel="manifest" href="manifest.json" />
<meta name="theme-color" content="#0E0D0B" />
<link rel="apple-touch-icon" href="icons/icon-180.png" />
<meta name="apple-mobile-web-app-capable" content="yes" />
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
<title>Admin Sign In - EPREEM</title>
<link rel="stylesheet" href="css/style.css" />
</head>
<body>

<div id="site-header"></div>

<main>
  <div class="auth-shell">
    <div class="auth-card">
      <span class="eyebrow">Admin access</span>
      <h1>Sign in to the console</h1>
      <p class="sub">Manage users, listings, seller verification, disputes and commissions.</p>

      <?php if ($error !== ''): ?>
        <div class="form-alert" role="alert"><?= epreem_escape($error) ?></div>
      <?php endif; ?>

      <form method="post" action="admin-login.php" novalidate>
        <div class="field">
          <label for="email">Admin email</label>
          <input type="email" id="email" name="email" required value="<?= epreem_escape($email) ?>" placeholder="admin@epreem.com" />
        </div>
        <div class="field">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" required placeholder="Password" />
        </div>
        <div style="display:flex; justify-content:flex-end; margin:-6px 0 20px;">
          <a href="admin-forgot-password.php" style="font-size:13px; color:var(--gold-bright);">Forgot password?</a>
        </div>
        <button type="submit" class="btn btn-gold btn-block">Open Dashboard</button>
      </form>

      <p class="auth-foot">Marketplace account? <a href="login.html">Use regular sign in</a></p>
    </div>
  </div>
</main>

<div id="site-footer"></div>

<script src="js/config.js"></script>
<script src="js/api.js"></script>
<script src="js/password-visibility.js?v=1"></script>
<script src="js/app.js"></script>
</body>
</html>
