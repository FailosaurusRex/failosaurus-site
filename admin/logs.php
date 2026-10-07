<?php
$config_path = dirname(__DIR__, 2) . '/frx-db-config.php';
if (!file_exists($config_path)) { http_response_code(500); die('Server configuration missing.'); }
require $config_path;

session_name('frx_admin');
session_start();

if (empty($_SESSION['authed'])) {
    header('Location: /admin/');
    exit;
}

$log_file = dirname(__DIR__, 2) . '/frx-broadcast-logs/broadcast.log';
$entries  = [];
if (file_exists($log_file)) {
    $lines = array_filter(array_map('trim', file($log_file)));
    foreach (array_reverse(array_values($lines)) as $line) {
        $d = json_decode($line, true);
        if ($d) $entries[] = $d;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Broadcast Logs — FRX Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,wght@0,400;0,500;0,700;1,400&display=swap">
  <link rel="stylesheet" href="/styles.css">
  <style>
    .log-wrap { max-width: 720px; }
    .log-entry { border: 1px solid var(--border); border-radius: 4px; padding: 1rem 1.2rem; margin-bottom: 1rem; }
    .log-subject { font-size: 1rem; font-weight: 700; color: var(--fg); margin-bottom: 0.4rem; }
    .log-meta { font-size: 0.8rem; color: var(--fg-muted); line-height: 1.8; }
    .log-meta .green { color: var(--accent); }
    .log-meta .red { color: #ff5040; }
    .log-ts { font-size: 0.75rem; color: var(--fg-muted); margin-bottom: 0.5rem; }
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
      <div class="inner log-wrap">
        <p class="section-label">Admin</p>
        <h1 class="section-heading">Broadcast logs</h1>

        <?php if (empty($entries)): ?>
          <p style="color:var(--fg-muted);">No broadcasts sent yet.</p>
        <?php else: ?>
          <?php foreach ($entries as $e): ?>
            <div class="log-entry">
              <div class="log-ts"><?= htmlspecialchars($e['ts'] ?? '') ?></div>
              <div class="log-subject"><?= htmlspecialchars($e['subject'] ?? '') ?></div>
              <div class="log-meta">
                Subscribers at send time: <?= (int)($e['total'] ?? 0) ?> &nbsp;·&nbsp;
                <span class="green">Delivered: <?= (int)($e['sent'] ?? 0) ?></span>
                <?php if (!empty($e['failed']) && $e['failed'] > 0): ?>
                  &nbsp;·&nbsp; <span class="red">Failed: <?= (int)$e['failed'] ?></span>
                <?php endif; ?>
              </div>
              <?php if (!empty($e['errors'])): ?>
                <div class="log-meta" style="margin-top:0.5rem;">
                  <?php foreach ($e['errors'] as $addr => $reason): ?>
                    <div style="color:#ff5040;"><?= htmlspecialchars($addr) ?>: <?= htmlspecialchars($reason) ?></div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
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
