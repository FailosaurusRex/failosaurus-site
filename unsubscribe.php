<?php
$config_path = dirname(__DIR__) . '/frx-db-config.php';
$error = null;
$done  = false;

if (!file_exists($config_path)) {
    $error = 'Configuration error. Please email hello@failosaurusrex.com to unsubscribe.';
} else {
    require $config_path;

    $token = isset($_GET['token']) ? trim($_GET['token']) : '';

    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
        $error = 'Invalid unsubscribe link.';
    } else {
        try {
            $pdo = new PDO(
                "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
                $db_user,
                $db_pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            $stmt = $pdo->prepare("DELETE FROM subscribers WHERE token = :token");
            $stmt->execute([':token' => $token]);
            $done = true; // whether or not a row was deleted — idempotent
        } catch (PDOException $e) {
            $error = 'Something went wrong. Please email hello@failosaurusrex.com to unsubscribe.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Unsubscribe — Failosaurus Rex</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,wght@0,400;0,700;1,400&display=swap">
  <link rel="stylesheet" href="styles.css">
</head>
<body>

  <header class="site-header">
    <div class="inner">
      <nav class="nav">
        <a href="/" class="nav-logo">FRX</a>
        <ul class="nav-links">
          <li><a href="/#about">About</a></li>
          <li><a href="/#channels">Channels</a></li>
          <li><a href="/#newsletter">Newsletter</a></li>
        </ul>
      </nav>
    </div>
  </header>

  <main>
    <section class="hero" style="min-height:60vh;display:flex;align-items:center;">
      <div class="inner">
        <?php if ($done): ?>
          <p class="section-label">Done</p>
          <h1 class="hero-name" style="font-size:clamp(3rem,8vw,6rem);">You're out.</h1>
          <p class="hero-tagline">No hard feelings. You won't hear from us again.</p>
          <a href="/" class="btn" style="margin-top:8px;">Back to the site</a>
        <?php else: ?>
          <p class="section-label">Unsubscribe</p>
          <h1 class="hero-name" style="font-size:clamp(3rem,8vw,6rem);">Something's off.</h1>
          <p class="hero-tagline"><?= htmlspecialchars($error ?? 'Unknown error.') ?></p>
          <a href="/" class="btn" style="margin-top:8px;">Back to the site</a>
        <?php endif; ?>
      </div>
    </section>
  </main>

  <footer class="site-footer">
    <div class="inner">
      <p>&copy; 2026 Failosaurus Rex &middot; <a href="mailto:hello@failosaurusrex.com">hello@failosaurusrex.com</a></p>
    </div>
  </footer>

</body>
</html>
