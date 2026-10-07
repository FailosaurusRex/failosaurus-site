<?php
$config_path = dirname(__DIR__) . '/frx-db-config.php';
$done  = false;
$error = null;

if (!file_exists($config_path)) {
    $error = 'Configuration error. Please email hello@failosaurusrex.com.';
} else {
    require $config_path;

    $token = isset($_GET['token']) ? trim($_GET['token']) : '';

    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
        $error = 'Invalid confirmation link.';
    } else {
        try {
            $pdo = new PDO(
                "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
                $db_user,
                $db_pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            // Fetch the subscriber
            $stmt = $pdo->prepare("SELECT email, token, confirmed FROM subscribers WHERE confirm_token = :ct LIMIT 1");
            $stmt->execute([':ct' => $token]);
            $sub = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$sub) {
                $error = 'This confirmation link has already been used or is invalid.';
            } elseif ($sub['confirmed']) {
                $done = true; // already confirmed — idempotent
            } else {
                $pdo->prepare("UPDATE subscribers SET confirmed = 1, confirm_token = '' WHERE confirm_token = :ct")
                    ->execute([':ct' => $token]);
                $done = true;

                // Send the welcome email
                require __DIR__ . '/vendor/autoload.php';
                $unsub_url = 'https://failosaurusrex.com/unsubscribe.php?token=' . $sub['token'];
                send_welcome($sub['email'], $smtp_pass, $unsub_url);
            }

            // Fetch latest issue to show on confirmation page
            $latest_issue = null;
            try {
                $li = $pdo->query("SELECT slug, title FROM issues ORDER BY sent_at DESC LIMIT 1");
                $latest_issue = $li ? $li->fetch(PDO::FETCH_ASSOC) : null;
            } catch (PDOException $e) { /* table may not exist yet */ }
        } catch (PDOException $e) {
            $error = 'Something went wrong. Please email hello@failosaurusrex.com.';
        }
    }
}

function send_welcome(string $to, string $smtp_pass, string $unsub_url): void {
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = 'smtp.hostinger.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'hello@failosaurusrex.com';
    $mail->Password   = $smtp_pass;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    $mail->setFrom('hello@failosaurusrex.com', 'Failosaurus Rex');
    $mail->addAddress($to);
    $mail->addCustomHeader('List-Unsubscribe', "<{$unsub_url}>");
    $mail->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
    $mail->Subject = "You're in — welcome to FRX";
    $mail->isHTML(true);

    $unsub_esc = htmlspecialchars($unsub_url, ENT_QUOTES, 'UTF-8');

    $mail->Body = <<<HTML
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="color-scheme" content="dark light" />
</head>
<body style="margin:0;padding:0;background-color:#110c08;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" bgcolor="#110c08" style="background-color:#110c08;">
  <tr><td align="center" style="padding:48px 16px;">
    <table role="presentation" width="560" border="0" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;">
      <tr><td bgcolor="#39ff14" height="4" style="background-color:#39ff14;font-size:0;line-height:4px;mso-line-height-rule:exactly;" aria-hidden="true">&nbsp;</td></tr>
      <tr>
        <td bgcolor="#1b1410" style="background-color:#1b1410;padding:36px 40px 32px 40px;">
          <p style="margin:0 0 14px 0;font-family:Arial,Helvetica,sans-serif;font-size:36px;font-weight:700;letter-spacing:0.24em;color:#39ff14;line-height:1;mso-line-height-rule:exactly;">FRX</p>
          <p style="margin:0 0 4px 0;font-family:Arial,Helvetica,sans-serif;font-size:10px;font-weight:400;letter-spacing:0.18em;color:#7a6e64;line-height:1;text-transform:uppercase;mso-line-height-rule:exactly;">Failosaurus Rex</p>
          <p style="margin:4px 0 0 0;font-family:Arial,Helvetica,sans-serif;font-size:11px;font-style:italic;color:#6b5f57;line-height:1.4;mso-line-height-rule:exactly;">For everyone who was never immediately great at anything.</p>
        </td>
      </tr>
      <tr><td bgcolor="#2c1f16" height="1" style="background-color:#2c1f16;font-size:0;line-height:0;mso-line-height-rule:exactly;">&nbsp;</td></tr>
      <tr>
        <td bgcolor="#1b1410" style="background-color:#1b1410;padding:40px 40px 48px 40px;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.75;color:#ede5d8;mso-line-height-rule:exactly;">
          <p style="margin:0 0 20px 0;font-size:22px;font-weight:700;color:#ede5d8;line-height:1.2;mso-line-height-rule:exactly;">You're in.</p>
          <p style="margin:0 0 16px 0;color:#7a6e64;">Every week: tech, culture, and whatever I'm currently failing at.</p>
          <p style="margin:0;color:#7a6e64;">No spam. No fluff. Just the honest version of figuring it out.</p>
        </td>
      </tr>
      <tr>
        <td bgcolor="#110c08" style="background-color:#110c08;padding:24px 40px 32px 40px;">
          <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.65;color:#7a6e64;">
            You signed up at <a href="https://failosaurusrex.com" style="color:#7a6e64;text-decoration:underline;">failosaurusrex.com</a>.&nbsp;&middot;&nbsp;<a href="{$unsub_esc}" style="color:#7a6e64;text-decoration:underline;">Unsubscribe</a>
          </p>
          <p style="margin:8px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:11px;color:#4d423a;">&copy; 2026 Failosaurus Rex</p>
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body>
</html>
HTML;

    $mail->AltBody = "You're in.\n\nEvery week: tech, culture, and whatever I'm currently failing at. No spam, no fluff.\n\nFailosaurus Rex\nhttps://failosaurusrex.com\n\n---\nUnsubscribe: {$unsub_url}";
    $mail->send();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $done ? "You're confirmed" : 'Something went wrong' ?> — Failosaurus Rex</title>
  <link rel="icon" href="/favicon.svg" type="image/svg+xml">
  <link rel="icon" href="/favicon-32.png" sizes="32x32" type="image/png">
  <link rel="apple-touch-icon" href="/apple-touch-icon.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,wght@0,400;0,500;0,700;1,400&display=swap">
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
          <svg class="status-icon" viewBox="0 0 56 56" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="28" cy="28" r="25"/><path d="M17 29l8 8 14-17"/></svg>
          <p class="section-label">Confirmed</p>
          <h1 class="hero-name" style="font-size:clamp(3rem,8vw,6rem);">You're in.</h1>
          <p class="hero-tagline">Welcome to FRX. Every week: tech, culture, and whatever I'm currently failing at. A welcome note is on its way to your inbox.</p>
          <div class="actions">
            <?php if (!empty($latest_issue)): ?>
              <a href="/archive/<?= htmlspecialchars($latest_issue['slug']) ?>" class="btn">Read the latest issue &rarr;</a>
              <a href="/" class="btn btn--ghost">Back to site</a>
            <?php else: ?>
              <a href="/" class="btn">Back to the site</a>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <svg class="status-icon status-icon--error" viewBox="0 0 56 56" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M28 5L52 49H4z"/><path d="M28 21v13M28 41v.5"/></svg>
          <p class="section-label">Oops</p>
          <h1 class="hero-name" style="font-size:clamp(3rem,8vw,6rem);">Something's off.</h1>
          <p class="hero-tagline" role="alert"><?= htmlspecialchars($error ?? 'Unknown error.') ?></p>
          <div class="actions">
            <a href="/#newsletter" class="btn">Try signing up again</a>
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
