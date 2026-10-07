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
  <meta name="robots" content="noindex">
  <meta name="theme-color" content="#110c08">
  <link rel="stylesheet" href="/styles.css">
</head>
<body>

  <a href="#main" class="skip-link">Skip to content</a>

  <header class="site-header">
    <div class="inner">
      <nav class="nav" aria-label="Primary">
        <a href="/" class="nav-logo"><img class="nav-mark" src="/brand/logo-mark.svg" alt="" width="30" height="30"><span>FRX</span></a>
        <ul class="nav-links">
          <li><a href="/#about">About</a></li>
          <li><a href="/#channels">Channels</a></li>
          <li><a href="/#newsletter">Newsletter</a></li>
          <li><a href="/archive.php">Archive</a></li>
        </ul>
      </nav>
    </div>
  </header>

  <main id="main">
    <section class="hero" style="min-height:60vh;display:flex;align-items:center;">
      <div class="inner">
        <?php if ($done): ?>
          <svg class="status-icon" viewBox="0 0 56 56" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="28" cy="28" r="25"/><path d="M18 28h20M30 20l8 8-8 8"/></svg>
          <p class="section-label">Done</p>
          <h1 class="hero-name" style="font-size:clamp(3rem,8vw,6rem);">You're out.</h1>
          <p class="hero-tagline">No hard feelings. Your email has been removed and you won't hear from us again.</p>
          <div class="actions">
            <a href="/" class="btn">Back to the site</a>
            <a href="/#newsletter" class="btn btn--ghost">Changed your mind? Re-subscribe</a>
          </div>
        <?php else: ?>
          <svg class="status-icon status-icon--error" viewBox="0 0 56 56" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M28 5L52 49H4z"/><path d="M28 21v13M28 41v.5"/></svg>
          <p class="section-label">Unsubscribe</p>
          <h1 class="hero-name" style="font-size:clamp(3rem,8vw,6rem);">Something's off.</h1>
          <p class="hero-tagline" role="alert"><?= htmlspecialchars($error ?? 'Unknown error.') ?></p>
          <div class="actions">
            <a href="mailto:hello@failosaurusrex.com?subject=Unsubscribe" class="btn">Email us to unsubscribe</a>
            <a href="/" class="btn btn--ghost">Back to the site</a>
          </div>
        <?php endif; ?>
      </div>
    </section>
  </main>

  <footer class="site-footer">
    <div class="inner">
      <div class="footer-grid">
        <div>
          <p>&copy; 2026 Failosaurus Rex</p>
          <p class="footer-tag">For everyone who was never immediately great at anything.</p>
        </div>
        <ul class="footer-links">
          <li><a href="/archive.php">Archive</a></li>
          <li><a href="/privacy.html">Privacy</a></li>
          <li><a href="/terms.html">Terms</a></li>
          <li><a href="mailto:hello@failosaurusrex.com">hello@failosaurusrex.com</a></li>
        </ul>
      </div>
    </div>
  </footer>

</body>
</html>
