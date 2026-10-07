<?php
/**
 * FRX Newsletter Broadcast — Send Script
 *
 * Called by POST from admin/index.php after password auth.
 * Sends to every subscriber in batches, with a small sleep between
 * messages to stay within Hostinger SMTP rate limits.
 *
 * NEVER call this endpoint directly from outside — the session check
 * at the top will reject any unauthenticated request.
 */

$config_path = dirname(__DIR__, 2) . '/frx-db-config.php';
if (!file_exists($config_path)) {
    http_response_code(500);
    die('Server configuration missing.');
}
require $config_path;

session_name('frx_admin');
session_start();

if (empty($_SESSION['authed'])) {
    header('Location: /admin/');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/');
    exit;
}

// ── Validate inputs ────────────────────────────────────────────────────────

$subject      = trim($_POST['subject']      ?? '');
$preview_text = trim($_POST['preview_text'] ?? '');
$body_html    = trim($_POST['body_html']    ?? '');
$body_plain   = trim($_POST['body_plain']   ?? '');

if ($subject === '' || $body_html === '') {
    die('Subject and body are required.');
}

// Strip tags from subject to avoid header injection
$subject = strip_tags($subject);

require dirname(__DIR__) . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

// ── Load subscribers ───────────────────────────────────────────────────────

try {
    $pdo = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $rows = $pdo->query("SELECT email, token FROM subscribers ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('Database error: could not load subscribers.');
}

$total   = count($rows);
$sent    = 0;
$failed  = [];

// ── Send ───────────────────────────────────────────────────────────────────

// Hostinger SMTP allows ~20 messages/minute on shared hosting.
// We sleep 3 s between sends → ~20/min max, comfortably within limits.
define('INTER_MESSAGE_SLEEP_US', 3_000_000); // 3 seconds in microseconds

// Build the hidden preview-text trick (invisible preheader spacer)
$preheader_html = '';
if ($preview_text !== '') {
    $esc = htmlspecialchars($preview_text, ENT_QUOTES, 'UTF-8');
    $preheader_html = <<<PREH
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;color:#110c08;line-height:1px;">
  {$esc}&nbsp;&#847;&nbsp;&#847;&nbsp;&#847;&nbsp;&#847;&nbsp;&#847;&nbsp;&#847;&nbsp;&#847;&nbsp;&#847;&nbsp;&#847;&nbsp;&#847;
</div>
PREH;
}

foreach ($rows as $row) {
    $to_email  = $row['email'];
    $token     = $row['token'];
    $unsub_url = 'https://failosaurusrex.com/unsubscribe.php?token=' . urlencode($token);

    $full_html = build_email_html($preheader_html, $body_html, $unsub_url);
    $full_text = build_email_text($body_plain ?: strip_tags($body_html), $unsub_url);

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = 'smtp.hostinger.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'hello@failosaurusrex.com';
        $mail->Password   = $smtp_pass;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('hello@failosaurusrex.com', 'Failosaurus Rex');
        $mail->addAddress($to_email);

        // List-Unsubscribe headers (improves deliverability; many clients show a one-click unsub)
        $mail->addCustomHeader('List-Unsubscribe', "<{$unsub_url}>");
        $mail->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body    = $full_html;
        $mail->AltBody = $full_text;

        $mail->send();
        $sent++;
    } catch (\Exception $e) {
        $failed[] = ['email' => $to_email, 'reason' => $e->getMessage()];
    }

    if ($sent + count($failed) < $total) {
        usleep(INTER_MESSAGE_SLEEP_US);
    }
}

// Log the broadcast to a simple append-only file (above public_html)
$log_dir  = dirname(__DIR__, 2) . '/frx-broadcast-logs';
if (!is_dir($log_dir)) {
    mkdir($log_dir, 0700, true);
}
$log_line = json_encode([
    'ts'      => date('c'),
    'subject' => $subject,
    'total'   => $total,
    'sent'    => $sent,
    'failed'  => count($failed),
    'errors'  => array_column($failed, 'reason', 'email'),
]) . "\n";
file_put_contents($log_dir . '/broadcast.log', $log_line, FILE_APPEND | LOCK_EX);

// ── Results page ──────────────────────────────────────────────────────────

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Broadcast sent — FRX Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,wght@0,400;0,500;0,700;1,400&display=swap">
  <link rel="stylesheet" href="/styles.css">
  <style>
    .result-wrap { max-width: 600px; }
    .result-stat { display: flex; justify-content: space-between; padding: 0.7rem 0; border-bottom: 1px solid var(--border); font-size: 0.95rem; color: var(--fg-muted); }
    .result-stat strong { color: var(--fg); }
    .result-stat .green { color: var(--accent); }
    .result-stat .red { color: #ff5040; }
    .failed-list { margin-top: 1.5rem; }
    .failed-list h3 { font-family: 'Bebas Neue', sans-serif; font-size: 1.2rem; letter-spacing: 0.06em; color: #ff5040; margin-bottom: 0.6rem; }
    .failed-item { font-size: 0.85rem; color: var(--fg-muted); padding: 0.4rem 0; border-bottom: 1px solid var(--border); }
    .failed-item span { color: var(--fg); font-weight: 500; }
  </style>
</head>
<body>

  <header class="site-header">
    <div class="inner">
      <nav class="nav">
        <a href="/" class="nav-logo">FRX</a>
        <ul class="nav-links">
          <li><a href="/admin/">Compose</a></li>
          <li><a href="/admin/logs.php">Logs</a></li>
        </ul>
      </nav>
    </div>
  </header>

  <main>
    <section class="section" style="border-bottom:none;">
      <div class="inner result-wrap">

        <p class="section-label">Broadcast</p>
        <h1 class="section-heading"><?= $sent === $total && $total > 0 ? 'All sent.' : 'Done.' ?></h1>

        <div style="margin: 2rem 0;">
          <div class="result-stat">
            <span>Subject</span>
            <strong><?= htmlspecialchars($subject) ?></strong>
          </div>
          <div class="result-stat">
            <span>Total subscribers</span>
            <strong><?= $total ?></strong>
          </div>
          <div class="result-stat">
            <span>Delivered</span>
            <strong class="green"><?= $sent ?></strong>
          </div>
          <?php if (count($failed) > 0): ?>
          <div class="result-stat">
            <span>Failed</span>
            <strong class="red"><?= count($failed) ?></strong>
          </div>
          <?php endif; ?>
        </div>

        <?php if (!empty($failed)): ?>
          <div class="failed-list">
            <h3>Failed deliveries</h3>
            <?php foreach ($failed as $f): ?>
              <div class="failed-item">
                <span><?= htmlspecialchars($f['email']) ?></span><br>
                <?= htmlspecialchars($f['reason']) ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <a href="/admin/" class="btn" style="margin-top: 2.5rem; display:inline-block;">Compose another</a>
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
<?php

// ── Helper functions ───────────────────────────────────────────────────────

function build_email_html(string $preheader, string $body, string $unsub_url): string {
    $unsub_esc = htmlspecialchars($unsub_url, ENT_QUOTES, 'UTF-8');
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
</head>
<body style="margin:0;padding:0;background:#110c08;font-family:Arial,sans-serif;color:#ede5d8;">
{$preheader}
<table width="100%" cellpadding="0" cellspacing="0" style="background:#110c08;padding:48px 0;">
  <tr><td align="center">
    <table width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;padding:0 24px;">
      <tr><td>
        <p style="font-size:1rem;font-weight:700;letter-spacing:0.14em;color:#39ff14;margin:0 0 32px;">FRX</p>
        {$body}
        <hr style="border:none;border-top:1px solid #2c1f16;margin:40px 0 24px;">
        <p style="font-size:0.75rem;color:#3a342e;margin:0;line-height:1.6;">
          You're receiving this because you signed up at
          <a href="https://failosaurusrex.com" style="color:#3a342e;">failosaurusrex.com</a>.<br>
          <a href="{$unsub_esc}" style="color:#3a342e;">Unsubscribe</a>
        </p>
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
}

function build_email_text(string $body, string $unsub_url): string {
    $divider = str_repeat('-', 60);
    return rtrim($body) . "\n\n{$divider}\nYou're receiving this because you signed up at https://failosaurusrex.com\nUnsubscribe: {$unsub_url}\n";
}
