<?php

declare(strict_types=1);

require __DIR__ . '/db.php';

session_start();

$email = trim((string) ($_GET['email'] ?? $_POST['email'] ?? ''));
$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    if ($email === '' || $token === '') {
        $error = 'This reset link is missing information.';
    } elseif (strlen($password) < 8) {
        $error = 'Use at least 8 characters for the new password.';
    } elseif ($password !== $passwordConfirm) {
        $error = 'The password confirmation does not match.';
    } else {
        try {
            $pdo = db();
            $stmt = $pdo->prepare(
                "SELECT prt.email, prt.token
                 FROM password_reset_tokens prt
                 INNER JOIN users u ON u.email = prt.email
                 WHERE prt.email = ? AND u.role = 'admin' AND prt.created_at >= DATE_SUB(NOW(), INTERVAL 60 MINUTE)
                 LIMIT 1"
            );
            $stmt->execute([$email]);
            $reset = $stmt->fetch();

            if (!$reset || !hash_equals($reset['token'], hash('sha256', $token))) {
                $error = 'This reset link is invalid or expired.';
            } else {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare('UPDATE users SET password = ?, updated_at = NOW() WHERE email = ? AND role = ?')
                    ->execute([$passwordHash, $email, 'admin']);
                $pdo->prepare('DELETE FROM password_reset_tokens WHERE email = ?')->execute([$email]);

                $success = 'Admin password updated. You can now sign in.';
            }
        } catch (Throwable $exception) {
            $error = 'Password reset is not ready. Import the database schema and make sure MySQL is running.';
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
<title>Reset Admin Password - EPREEM</title>
<link rel="stylesheet" href="css/style.css" />
</head>
<body>

<div id="site-header"></div>

<main>
  <div class="auth-shell">
    <div class="auth-card">
      <span class="eyebrow">Admin recovery</span>
      <h1>Set a new password</h1>
      <p class="sub">Choose a new password for the admin console.</p>

      <?php if ($error !== ''): ?>
        <div class="form-alert" role="alert"><?= epreem_escape($error) ?></div>
      <?php endif; ?>

      <?php if ($success !== ''): ?>
        <div class="form-alert success" role="status"><?= epreem_escape($success) ?></div>
        <p class="auth-foot"><a href="admin-login.php">Back to admin login</a></p>
      <?php else: ?>
        <form method="post" action="admin-reset-password.php" novalidate>
          <input type="hidden" name="email" value="<?= epreem_escape($email) ?>" />
          <input type="hidden" name="token" value="<?= epreem_escape($token) ?>" />
          <div class="field">
            <label for="password">New password</label>
            <input type="password" id="password" name="password" required minlength="8" placeholder="Password" />
          </div>
          <div class="field">
            <label for="password_confirm">Confirm password</label>
            <input type="password" id="password_confirm" name="password_confirm" required minlength="8" placeholder="Password" />
          </div>
          <button type="submit" class="btn btn-gold btn-block">Update Password</button>
        </form>

        <p class="auth-foot"><a href="admin-login.php">Back to admin login</a></p>
      <?php endif; ?>
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
