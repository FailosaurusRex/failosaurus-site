<?php
ob_start();

$config_path = dirname(__DIR__, 2) . '/frx-db-config.php';
if (!file_exists($config_path)) { http_response_code(500); die('Server configuration missing.'); }
require $config_path;

session_name('frx_admin');
session_start();

if (empty($_SESSION['authed'])) {
    header('Location: /admin/');
    exit;
}

$error   = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = $_POST['current']  ?? '';
    $new1    = $_POST['new1']     ?? '';
    $new2    = $_POST['new2']     ?? '';

    if (!password_verify($current, $admin_pass)) {
        $error = 'Current password is incorrect.';
    } elseif (strlen($new1) < 12) {
        $error = 'New password must be at least 12 characters.';
    } elseif ($new1 !== $new2) {
        $error = 'New passwords do not match.';
    } else {
        $new_hash = password_hash($new1, PASSWORD_BCRYPT);

        // Replace the $admin_pass line in the config file
        $config_contents = file_get_contents($config_path);
        if ($config_contents === false) {
            $error = 'Could not read config file.';
        } else {
            $updated = preg_replace(
                '/^\$admin_pass\s*=\s*\'[^\']*\';/m',
                "\$admin_pass = '" . addslashes($new_hash) . "';",
                $config_contents,
                1,
                $count
            );

            if ($count === 0) {
                // Line didn't exist yet — append it
                $updated = rtrim($config_contents) . "\n\$admin_pass = '" . addslashes($new_hash) . "';\n";
            }

            if (file_put_contents($config_path, $updated, LOCK_EX) === false) {
                $error = 'Could not write config file. Check file permissions.';
            } else {
                $success = true;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Change Password — FRX Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,wght@0,400;0,500;0,700;1,400&display=swap">
  <link rel="stylesheet" href="/styles.css">
  <style>
    .admin-wrap { max-width: 480px; }
    .admin-label { font-size: 0.75rem; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; color: var(--fg-muted); margin-bottom: 0.4rem; display: block; }
    .admin-input { width: 100%; box-sizing: border-box; background: var(--bg-card); border: 1px solid var(--border); color: var(--fg); font-family: 'DM Sans', sans-serif; font-size: 1rem; padding: 0.75rem 1rem; border-radius: 4px; outline: none; transition: border-color 0.15s; }
    .admin-input:focus { border-color: var(--accent); }
    .admin-field { margin-bottom: 1.4rem; }
    .error-msg { color: #ff5040; font-size: 0.9rem; margin-bottom: 1.2rem; }
    .success-msg { color: var(--accent); font-size: 0.95rem; margin-bottom: 1.2rem; }
  </style>
</head>
<body>

  <header class="site-header">
    <div class="inner">
      <nav class="nav">
        <a href="/" class="nav-logo">FRX</a>
        <ul class="nav-links">
          <li><a href="/admin/">Compose</a></li>
          <li><a href="/admin/subscribers.php">Subscribers</a></li>
          <li><a href="/admin/logs.php">Logs</a></li>
          <li><a href="/admin/change-password.php">Password</a></li>
          <li><a href="/admin/?logout=1">Log out</a></li>
        </ul>
      </nav>
    </div>
  </header>

  <main>
    <section class="section" style="border-bottom:none;">
      <div class="inner admin-wrap">
        <p class="section-label">Admin</p>
        <h1 class="section-heading">Change password</h1>

        <?php if ($success): ?>
          <p class="success-msg">Password updated.</p>
          <a href="/admin/" class="btn">Back to compose</a>
        <?php else: ?>
          <?php if ($error): ?>
            <p class="error-msg"><?= htmlspecialchars($error) ?></p>
          <?php endif; ?>
          <form method="POST" action="/admin/change-password.php">
            <div class="admin-field">
              <label class="admin-label" for="current">Current password</label>
              <input class="admin-input" type="password" id="current" name="current" required autofocus>
            </div>
            <div class="admin-field">
              <label class="admin-label" for="new1">New password <span style="font-weight:400;text-transform:none;">(min 12 chars)</span></label>
              <input class="admin-input" type="password" id="new1" name="new1" minlength="12" required>
            </div>
            <div class="admin-field">
              <label class="admin-label" for="new2">Confirm new password</label>
              <input class="admin-input" type="password" id="new2" name="new2" minlength="12" required>
            </div>
            <button type="submit" class="btn">Update password</button>
          </form>
        <?php endif; ?>
      </div>
    </section>
  </main>

  <footer class="site-footer">
    <div class="inner">
      <p>&copy; 2026 Failosaurus Rex</p>
    </div>
  </footer>

</body>
</html>
