<?php
$config_path = dirname(__DIR__) . '/frx-db-config.php';
if (!file_exists($config_path)) {
    http_response_code(500);
    die('Server configuration missing.');
}
require $config_path;

// Determine if we're viewing a single issue or the listing
$slug = '';
$path = $_SERVER['REQUEST_URI'] ?? '';

// Support both /archive/some-slug and /archive.php?slug=some-slug
if (preg_match('#^/archive/([a-z0-9\-]+)/?$#', strtok($path, '?'), $m)) {
    $slug = $m[1];
} elseif (isset($_GET['slug']) && preg_match('/^[a-z0-9\-]+$/', $_GET['slug'])) {
    $slug = $_GET['slug'];
}

try {
    $pdo = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $pdo->exec("CREATE TABLE IF NOT EXISTS issues (
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        slug         VARCHAR(120) NOT NULL UNIQUE,
        title        VARCHAR(200) NOT NULL,
        preview_text VARCHAR(200) NOT NULL DEFAULT '',
        body_html    MEDIUMTEXT   NOT NULL,
        body_plain   TEXT         NOT NULL DEFAULT '',
        sent_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_sent (sent_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if ($slug !== '') {
        // ── Single issue view ─────────────────────────────────────────────
        $stmt = $pdo->prepare("SELECT title, preview_text, body_html, sent_at FROM issues WHERE slug = :slug LIMIT 1");
        $stmt->execute([':slug' => $slug]);
        $issue = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$issue) {
            http_response_code(404);
            $page_mode = 'not_found';
        } else {
            $page_mode = 'issue';
        }
    } else {
        // ── Listing view ──────────────────────────────────────────────────
        $issues = $pdo->query("SELECT slug, title, preview_text, sent_at FROM issues ORDER BY sent_at DESC")->fetchAll(PDO::FETCH_ASSOC);
        $page_mode = 'listing';
    }

} catch (PDOException $e) {
    http_response_code(500);
    die('Database error.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?php if ($page_mode === 'issue'): ?>
  <title><?= htmlspecialchars($issue['title']) ?> — FRX</title>
  <meta name="description" content="<?= htmlspecialchars($issue['preview_text'] ?: 'Failosaurus Rex newsletter') ?>">
  <?php elseif ($page_mode === 'listing'): ?>
  <title>Archive — Failosaurus Rex</title>
  <meta name="description" content="Every issue of the Failosaurus Rex newsletter, in one place.">
  <?php else: ?>
  <title>Not found — FRX</title>
  <?php endif; ?>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,wght@0,400;0,500;0,700;1,400&display=swap">
  <link rel="icon" href="/favicon.svg" type="image/svg+xml">
  <link rel="alternate" type="application/rss+xml" title="Failosaurus Rex" href="/rss.php">
  <link rel="stylesheet" href="/styles.css">
  <style>
    .archive-wrap { max-width: 680px; }

    /* Listing */
    .issue-list { list-style: none; padding: 0; margin: 2rem 0 0; }
    .issue-item { border-bottom: 1px solid var(--border); padding: 1.4rem 0; }
    .issue-item:first-child { border-top: 1px solid var(--border); }
    .issue-date { font-size: 0.75rem; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; color: var(--fg-muted); margin-bottom: 0.4rem; }
    .issue-title { font-family: 'Bebas Neue', sans-serif; font-size: 1.6rem; letter-spacing: 0.04em; line-height: 1.15; margin-bottom: 0.4rem; }
    .issue-title a { color: var(--fg); text-decoration: none; }
    .issue-title a:hover { color: var(--accent); }
    .issue-preview { font-size: 0.9rem; color: var(--fg-muted); line-height: 1.55; margin: 0; }
    .issue-read-link { display: inline-block; margin-top: 0.6rem; font-size: 0.8rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: var(--accent); text-decoration: none; }
    .issue-read-link:hover { text-decoration: underline; }

    /* Single issue */
    .issue-body { margin-top: 2rem; font-size: 1rem; line-height: 1.75; color: var(--fg); }
    .issue-body p { margin: 0 0 1.2rem; }
    .issue-body h2, .issue-body h3 { font-family: 'Bebas Neue', sans-serif; letter-spacing: 0.04em; color: var(--fg); }
    .issue-body a { color: var(--accent); }
    .issue-body img { max-width: 100%; height: auto; }
    .back-link { font-size: 0.8rem; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; color: var(--fg-muted); text-decoration: none; display: inline-block; margin-bottom: 2rem; }
    .back-link:hover { color: var(--fg); }
    .back-link::before { content: '← '; }
    .empty-state { color: var(--fg-muted); font-size: 0.95rem; margin-top: 2rem; }
  </style>
</head>
<body>

  <header class="site-header">
    <div class="inner">
      <nav class="nav">
        <a href="/" class="nav-logo">FRX</a>
        <ul class="nav-links">
          <li><a href="/#about">About</a></li>
          <li><a href="/#newsletter">Subscribe</a></li>
          <li><a href="/archive.php">Archive</a></li>
        </ul>
      </nav>
    </div>
  </header>

  <main>
    <section class="section" style="border-bottom:none;">
      <div class="inner archive-wrap">

        <?php if ($page_mode === 'listing'): ?>

          <p class="section-label">Newsletter</p>
          <h1 class="section-heading">Archive.</h1>
          <p class="section-body">Every issue, in one place.</p>

          <?php if (empty($issues)): ?>
            <p class="empty-state">No issues yet — check back soon.</p>
          <?php else: ?>
            <ul class="issue-list">
              <?php foreach ($issues as $i): ?>
              <li class="issue-item">
                <p class="issue-date"><?= date('F j, Y', strtotime($i['sent_at'])) ?></p>
                <p class="issue-title">
                  <a href="/archive/<?= htmlspecialchars($i['slug']) ?>"><?= htmlspecialchars($i['title']) ?></a>
                </p>
                <?php if ($i['preview_text'] !== ''): ?>
                  <p class="issue-preview"><?= htmlspecialchars($i['preview_text']) ?></p>
                <?php endif; ?>
                <a class="issue-read-link" href="/archive/<?= htmlspecialchars($i['slug']) ?>">Read issue →</a>
              </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>

        <?php elseif ($page_mode === 'issue'): ?>

          <a class="back-link" href="/archive.php">All issues</a>

          <p class="section-label"><?= date('F j, Y', strtotime($issue['sent_at'])) ?></p>
          <h1 class="section-heading"><?= htmlspecialchars($issue['title']) ?></h1>

          <div class="issue-body">
            <?= $issue['body_html'] ?>
          </div>

          <div style="margin-top:3rem;padding-top:1.5rem;border-top:1px solid var(--border);">
            <p style="font-size:0.85rem;color:var(--fg-muted);">
              Enjoyed this? <a href="/#newsletter" style="color:var(--accent);">Subscribe to get the next issue</a> in your inbox.
            </p>
            <a class="back-link" href="/archive.php" style="margin-top:0.8rem;">All issues</a>
          </div>

        <?php else: ?>

          <p class="section-label">404</p>
          <h1 class="section-heading">Not found.</h1>
          <p class="section-body">That issue doesn't exist. <a href="/archive.php" style="color:var(--accent);">Browse all issues</a>.</p>

        <?php endif; ?>

      </div>
    </section>
  </main>

  <footer class="site-footer">
    <div class="inner">
      <p>&copy; 2026 Failosaurus Rex &middot;
        <a href="mailto:hello@failosaurusrex.com">hello@failosaurusrex.com</a> &middot;
        <a href="/privacy.html">Privacy</a> &middot;
        <a href="/terms.html">Terms</a>
      </p>
    </div>
  </footer>

</body>
</html>
