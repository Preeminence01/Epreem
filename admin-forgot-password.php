<?php

declare(strict_types=1);

require __DIR__ . '/db.php';

session_start();

$email = '';
$message = '';
$resetLink = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));

    if ($email === '') {
        $error = 'Enter your admin email address.';
    } else {
        try {
            $pdo = db();
            $stmt = $pdo->prepare("SELECT id, email, role FROM users WHERE email = ? AND role = 'admin' LIMIT 1");
            $stmt->execute([$email]);
            $admin = $stmt->fetch();

            $message = 'If that admin account exists, a reset link has been prepared.';

            if ($admin) {
                $token = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $token);

                $pdo->prepare('DELETE FROM password_reset_tokens WHERE email = ?')->execute([$admin['email']]);
                $insert = $pdo->prepare(
                    'INSERT INTO password_reset_tokens (email, token, created_at) VALUES (?, ?, NOW())'
                );
                $insert->execute([$admin['email'], $tokenHash]);

                $resetLink = 'admin-reset-password.php?email=' . rawurlencode($admin['email']) . '&token=' . rawurlencode($token);
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
<title>Admin Password Reset - EPREEM</title>
<link rel="stylesheet" href="css/style.css" />
</head>
<body>

<div id="site-header"></div>

<main>
  <div class="auth-shell">
    <div class="auth-card">
      <span class="eyebrow">Admin recovery</span>
      <h1>Forgot password?</h1>
      <p class="sub">Enter the admin email address to prepare a reset link.</p>

      <?php if ($error !== ''): ?>
        <div class="form-alert" role="alert"><?= epreem_escape($error) ?></div>
      <?php endif; ?>

      <?php if ($message !== ''): ?>
        <div class="form-alert success" role="status">
          <?= epreem_escape($message) ?>
          <?php if ($resetLink !== ''): ?>
            <div style="margin-top:12px;">
              <a href="<?= epreem_escape($resetLink) ?>" style="color:var(--gold-bright);">Reset admin password</a>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <form method="post" action="admin-forgot-password.php" novalidate>
        <div class="field">
          <label for="email">Admin email</label>
          <input type="email" id="email" name="email" required value="<?= epreem_escape($email) ?>" placeholder="admin@epreem.com" />
        </div>
        <button type="submit" class="btn btn-gold btn-block">Prepare Reset Link</button>
      </form>

      <p class="auth-foot">Remembered it? <a href="admin-login.php">Back to admin login</a></p>
    </div>
  </div>
</main>

<div id="site-footer"></div>

<script src="js/config.js"></script>
<script src="js/api.js"></script>
<script src="js/app.js"></script>
</body>
</html>
