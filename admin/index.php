<?php
ob_start();

$config_path = dirname(__DIR__, 2) . '/frx-db-config.php';

if (!file_exists($config_path)) {
    http_response_code(500);
    die('Server configuration missing.');
}
require $config_path;

session_name('frx_admin');
session_start();

$error = '';

// ── Handle login / logout ──────────────────────────────────────────────────

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: /admin/');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (password_verify($_POST['password'], $admin_pass)) {
        session_regenerate_id(true);
        $_SESSION['authed'] = true;
        header('Location: /admin/');
        exit;
    }
    $error = 'Incorrect password.';
}

$authed = !empty($_SESSION['authed']);

// ── Get subscriber count (for authed view) ────────────────────────────────

$sub_count = null;
if ($authed) {
    try {
        $pdo = new PDO(
            "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
            $db_user,
            $db_pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $sub_count = (int) $pdo->query("SELECT COUNT(*) FROM subscribers")->fetchColumn();
    } catch (PDOException $e) {
        $sub_count = -1;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>FRX Admin — Broadcast</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,wght@0,400;0,500;0,700;1,400&display=swap">
  <link rel="stylesheet" href="/styles.css">
  <style>
    .admin-wrap { max-width: 680px; }
    .admin-label { font-size: 0.75rem; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; color: var(--fg-muted); margin-bottom: 0.4rem; display: block; }
    .admin-input, .admin-textarea {
      width: 100%; box-sizing: border-box;
      background: var(--bg-card); border: 1px solid var(--border);
      color: var(--fg); font-family: 'DM Sans', sans-serif; font-size: 1rem;
      padding: 0.75rem 1rem; border-radius: 4px; outline: none;
      transition: border-color 0.15s;
    }
    .admin-input:focus, .admin-textarea:focus { border-color: var(--accent); }
    .admin-textarea { min-height: 340px; resize: vertical; line-height: 1.6; }
    .admin-field { margin-bottom: 1.4rem; }
    .error-msg { color: #ff5040; font-size: 0.9rem; margin-bottom: 1rem; }
    .stat-chip { display: inline-block; background: var(--bg-card); border: 1px solid var(--border); border-radius: 4px; padding: 0.3rem 0.8rem; font-size: 0.85rem; color: var(--fg-muted); margin-bottom: 2rem; }
    .stat-chip strong { color: var(--accent); }
    .logout-link { font-size: 0.8rem; color: var(--fg-muted); text-decoration: none; margin-left: 1rem; }
    .logout-link:hover { color: var(--fg); }
    .preview-toggle { font-size: 0.8rem; color: var(--accent); cursor: pointer; margin-left: 0.5rem; text-decoration: underline; }
    #preview-frame { width: 100%; min-height: 400px; border: 1px solid var(--border); border-radius: 4px; margin-top: 1rem; display: none; }
    .hint { font-size: 0.8rem; color: var(--fg-muted); margin-top: 0.3rem; }
  </style>
</head>
<body>

  <header class="site-header">
    <div class="inner">
      <nav class="nav">
        <a href="/" class="nav-logo">FRX</a>
        <?php if ($authed): ?>
        <ul class="nav-links">
          <li><a href="/admin/subscribers.php">Subscribers</a></li>
          <li><a href="/admin/logs.php">Logs</a></li>
          <li><a href="/admin/change-password.php">Password</a></li>
          <li><a href="/admin/?logout=1" class="logout-link">Log out</a></li>
        </ul>
        <?php endif; ?>
      </nav>
    </div>
  </header>

  <main>
    <section class="section" style="border-bottom:none;">
      <div class="inner admin-wrap">

        <?php if (!$authed): ?>

          <p class="section-label">Admin</p>
          <h1 class="section-heading">Broadcast</h1>
          <?php if ($error): ?>
            <p class="error-msg"><?= htmlspecialchars($error) ?></p>
          <?php endif; ?>
          <form method="POST" action="/admin/">
            <div class="admin-field">
              <label class="admin-label" for="password">Password</label>
              <input class="admin-input" type="password" id="password" name="password" autofocus required>
            </div>
            <button type="submit" class="btn">Enter</button>
          </form>

        <?php else: ?>

          <p class="section-label">Admin</p>
          <h1 class="section-heading">Send newsletter</h1>

          <div class="stat-chip">
            <?php if ($sub_count < 0): ?>
              Could not load subscriber count
            <?php elseif ($sub_count === 0): ?>
              No subscribers yet
            <?php else: ?>
              Sending to <strong><?= number_format($sub_count) ?></strong> subscriber<?= $sub_count !== 1 ? 's' : '' ?>
            <?php endif; ?>
          </div>

          <form id="broadcast-form" action="/admin/send.php" method="POST">

            <div class="admin-field">
              <label class="admin-label" for="subject">Subject line</label>
              <input class="admin-input" type="text" id="subject" name="subject" maxlength="200" required placeholder="Issue #1 — What I learned failing at X">
            </div>

            <div class="admin-field">
              <label class="admin-label" for="preview_text">Preview text <span style="font-weight:400;text-transform:none;">(inbox snippet, optional)</span></label>
              <input class="admin-input" type="text" id="preview_text" name="preview_text" maxlength="200" placeholder="The short line people see before opening…">
            </div>

            <div class="admin-field">
              <label class="admin-label" for="body_html">
                Body (HTML)
                <span class="preview-toggle" onclick="togglePreview()">preview</span>
              </label>
              <textarea class="admin-textarea" id="body_html" name="body_html" required
                oninput="updatePreview()"
                placeholder="<p>Write your newsletter content here.</p>&#10;<p>Use standard HTML. Inline styles work best for email.</p>&#10;&#10;<!-- The unsubscribe link is appended automatically. -->"></textarea>
              <p class="hint">The unsubscribe link is added automatically at the bottom of every email.</p>
              <iframe id="preview-frame" title="Email preview" sandbox="allow-same-origin"></iframe>
            </div>

            <div class="admin-field">
              <label class="admin-label" for="body_plain">Plain-text version</label>
              <textarea class="admin-textarea" id="body_plain" name="body_plain" style="min-height:160px;"
                placeholder="Plain text fallback (for email clients that don't render HTML). Optional but recommended."></textarea>
            </div>

            <?php if ($sub_count > 0): ?>
              <button type="submit" class="btn" id="send-btn">
                Send to <?= number_format($sub_count) ?> subscriber<?= $sub_count !== 1 ? 's' : '' ?> →
              </button>
            <?php else: ?>
              <button type="button" class="btn" disabled style="opacity:0.4;cursor:not-allowed;">No subscribers</button>
            <?php endif; ?>

          </form>

        <?php endif; ?>

      </div>
    </section>
  </main>

  <footer class="site-footer">
    <div class="inner">
      <p>&copy; 2026 Failosaurus Rex &middot;
        <a href="mailto:hello@failosaurusrex.com">hello@failosaurusrex.com</a>
      </p>
    </div>
  </footer>

  <script>
    const previewFrame = document.getElementById('preview-frame');
    const bodyField    = document.getElementById('body_html');
    let previewOpen    = false;

    function togglePreview() {
      previewOpen = !previewOpen;
      previewFrame.style.display = previewOpen ? 'block' : 'none';
      if (previewOpen) updatePreview();
    }

    function updatePreview() {
      if (!previewOpen) return;
      const doc = previewFrame.contentDocument || previewFrame.contentWindow.document;
      doc.open();
      doc.write(`<!DOCTYPE html><html><body style="margin:0;padding:16px;background:#110c08;font-family:Arial,sans-serif;color:#ede5d8;">${bodyField.value}</body></html>`);
      doc.close();
    }

    document.getElementById('broadcast-form')?.addEventListener('submit', function(e) {
      const btn = document.getElementById('send-btn');
      if (!confirm('Send this newsletter to all subscribers now?')) {
        e.preventDefault();
        return;
      }
      btn.disabled = true;
      btn.textContent = 'Sending…';
    });
  </script>

</body>
</html>
