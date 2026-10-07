<?php
ob_start(); // buffer output so header() redirects work even if config file has trailing whitespace

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

$subject         = strip_tags(trim($_POST['subject']         ?? ''));
$preview_text    = trim($_POST['preview_text']    ?? '');
$body_html       = trim($_POST['body_html']       ?? '');
$body_plain      = trim($_POST['body_plain']      ?? '');
$slug            = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($_POST['slug'] ?? '')));
$publish_archive = !empty($_POST['publish_archive']) && $slug !== '';

if ($subject === '' || $body_html === '') {
    die('Subject and body are required.');
}

require dirname(__DIR__) . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

// ── Load subscribers + save to archive ────────────────────────────────────

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

    // Save issue to archive if requested
    $archive_url = '';
    if ($publish_archive) {
        $ins = $pdo->prepare("INSERT IGNORE INTO issues (slug, title, preview_text, body_html, body_plain) VALUES (:slug, :title, :preview, :html, :plain)");
        $ins->execute([
            ':slug'    => $slug,
            ':title'   => $subject,
            ':preview' => $preview_text,
            ':html'    => $body_html,
            ':plain'   => $body_plain,
        ]);
        $archive_url = 'https://failosaurusrex.com/archive/' . $slug;
    }

    $rows = $pdo->query("SELECT email, token FROM subscribers WHERE confirmed = 1 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('Database error: ' . htmlspecialchars($e->getMessage()));
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

    $full_html = build_email_html($preheader_html, $body_html, $unsub_url, $archive_url);
    $full_text = build_email_text($body_plain ?: strip_tags($body_html), $unsub_url, $archive_url);

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
        <a href="/" class="nav-logo"><img class="nav-mark" src="/brand/logo-mark.svg" alt="" width="30" height="30"><span>FRX</span></a>
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

        <?php if ($archive_url): ?>
        <p style="margin-top:1.5rem;font-size:0.9rem;color:var(--fg-muted);">
          Archived at: <a href="<?= htmlspecialchars($archive_url) ?>" style="color:var(--accent);"><?= htmlspecialchars($archive_url) ?></a>
        </p>
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

function build_email_html(string $preheader, string $body, string $unsub_url, string $archive_url = ''): string {
    $unsub_esc   = htmlspecialchars($unsub_url,   ENT_QUOTES, 'UTF-8');
    $archive_esc = htmlspecialchars($archive_url, ENT_QUOTES, 'UTF-8');

    $view_in_browser = '';
    if ($archive_url !== '') {
        $view_in_browser = <<<VIB

        <!-- View in browser bar -->
        <tr>
          <td bgcolor="#110c08" style="background-color:#110c08;padding:12px 40px 0 40px;text-align:center;">
            <p style="margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;font-size:11px;line-height:1.5;color:#4d423a;mso-line-height-rule:exactly;">
              <a href="{$archive_esc}" style="color:#4d423a;text-decoration:underline;font-family:Arial,Helvetica,sans-serif;">View this message in your browser</a>
            </p>
          </td>
        </tr>
VIB;
    }

    return <<<HTML
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <meta name="color-scheme" content="dark light" />
  <meta name="supported-color-schemes" content="dark light" />
  <title>Failosaurus Rex</title>
</head>
<body style="margin:0;padding:0;background-color:#110c08;font-family:Arial,Helvetica,sans-serif;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">
{$preheader}
<!-- ═══════════════════════════════════════════════════════════════
     OUTER WRAPPER — full-bleed near-black background
     ═══════════════════════════════════════════════════════════════ -->
<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" bgcolor="#110c08" style="background-color:#110c08;margin:0;padding:0;">
  <tr>
    <td align="center" valign="top" style="padding:36px 16px 56px 16px;">

      <!-- ─── CONTAINER: max 600 px ─────────────────────────────── -->
      <table role="presentation" width="600" border="0" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">
{$view_in_browser}
        <!-- ╔══════════════════════════════════════════════════════╗
             ║  TOP ACCENT BAR — 4 px neon green                   ║
             ╚══════════════════════════════════════════════════════╝ -->
        <tr>
          <td bgcolor="#39ff14" height="4" style="background-color:#39ff14;font-size:0;line-height:4px;mso-line-height-rule:exactly;" aria-hidden="true">&nbsp;</td>
        </tr>

        <!-- ╔══════════════════════════════════════════════════════╗
             ║  HEADER                                              ║
             ╚══════════════════════════════════════════════════════╝ -->
        <tr>
          <td bgcolor="#1b1410" style="background-color:#1b1410;padding:36px 40px 32px 40px;">
            <!-- Wordmark — visually isolated above the sub-brand group -->
            <p style="margin:0 0 14px 0;padding:0;font-family:Arial,Helvetica,sans-serif;font-size:36px;font-weight:700;letter-spacing:0.24em;color:#39ff14;line-height:1;mso-line-height-rule:exactly;">FRX</p>
            <!-- Full brand name — tight pair with tagline below -->
            <p style="margin:0 0 4px 0;padding:0;font-family:Arial,Helvetica,sans-serif;font-size:10px;font-weight:400;letter-spacing:0.2em;color:#7a6e64;line-height:1;text-transform:uppercase;mso-line-height-rule:exactly;">Failosaurus Rex</p>
            <!-- Tagline — tertiary, intentionally receded but legible -->
            <p style="margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;font-size:11px;font-weight:400;font-style:italic;letter-spacing:0.01em;color:#6b5f57;line-height:1.4;mso-line-height-rule:exactly;">For everyone who was never immediately great at anything.</p>
          </td>
        </tr>

        <!-- 1 px full-width rule: header → content -->
        <tr>
          <td bgcolor="#2c1f16" height="1" style="background-color:#2c1f16;font-size:0;line-height:0;mso-line-height-rule:exactly;">&nbsp;</td>
        </tr>

        <!-- ╔══════════════════════════════════════════════════════╗
             ║  CONTENT AREA                                        ║
             ║  Links within {$body} should carry inline style:     ║
             ║  color:#39ff14;text-decoration:underline;            ║
             ║  Note: Outlook does not inherit font/color into      ║
             ║  nested table cells — body tables need own styles.   ║
             ╚══════════════════════════════════════════════════════╝ -->
        <tr>
          <td bgcolor="#1b1410" style="background-color:#1b1410;padding:40px 40px 48px 40px;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.75;color:#ede5d8;mso-line-height-rule:exactly;">
            {$body}
          </td>
        </tr>

        <!-- 1 px inset rule: content → footer
             Deliberately inset (40 px per side) to signal a softer break
             than the full-width header separator above. -->
        <tr>
          <td bgcolor="#1b1410" style="background-color:#1b1410;padding:0 40px;">
            <table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0">
              <tr>
                <td bgcolor="#2c1f16" height="1" style="background-color:#2c1f16;font-size:0;line-height:0;mso-line-height-rule:exactly;">&nbsp;</td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- ╔══════════════════════════════════════════════════════╗
             ║  FOOTER                                              ║
             ╚══════════════════════════════════════════════════════╝ -->
        <tr>
          <td bgcolor="#110c08" style="background-color:#110c08;padding:24px 40px 32px 40px;">
            <!-- Primary footer: attribution + unsubscribe -->
            <p style="margin:0 0 8px 0;padding:0;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.6;color:#7a6e64;mso-line-height-rule:exactly;">
              You&rsquo;re receiving this because you signed up at
              <a href="https://failosaurusrex.com" style="color:#7a6e64;text-decoration:underline;font-family:Arial,Helvetica,sans-serif;">failosaurusrex.com</a>.
              &nbsp;&middot;&nbsp;
              <a href="{$unsub_esc}" style="color:#7a6e64;text-decoration:underline;font-family:Arial,Helvetica,sans-serif;">Unsubscribe</a>
            </p>
            <!-- Secondary footer: copyright — CAN-SPAM §7(1)(A) also requires
                 a physical postal address; add it here once confirmed. -->
            <p style="margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;font-size:11px;line-height:1.5;color:#4d423a;mso-line-height-rule:exactly;">
              &copy; 2026 Failosaurus Rex
            </p>
          </td>
        </tr>

      </table>
      <!-- /container -->

    </td>
  </tr>
</table>
<!-- /outer wrapper -->
</body>
</html>
HTML;
}

function build_email_text(string $body, string $unsub_url, string $archive_url = ''): string {
    $divider    = str_repeat('-', 60);
    $view_line  = $archive_url !== '' ? "View in browser: {$archive_url}\n\n" : '';
    return $view_line . rtrim($body) . "\n\n{$divider}\nYou're receiving this because you signed up at https://failosaurusrex.com\nUnsubscribe: {$unsub_url}\n";
}
