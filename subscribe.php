<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://failosaurusrex.com');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$raw   = file_get_contents('php://input');
$body  = json_decode($raw, true);
$email = isset($body['email']) ? trim($body['email']) : '';

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid email address']);
    exit;
}

// Credentials live one level above public_html — never in the repo
$config_path = dirname(__DIR__) . '/frx-db-config.php';
if (!file_exists($config_path)) {
    http_response_code(500);
    echo json_encode(['error' => 'Server configuration missing']);
    exit;
}
require $config_path; // defines $db_host, $db_name, $db_user, $db_pass, $smtp_pass

require __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

try {
    $pdo = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $pdo->exec("CREATE TABLE IF NOT EXISTS subscribers (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        email         VARCHAR(255) NOT NULL UNIQUE,
        token         CHAR(64)     NOT NULL,
        confirm_token CHAR(64)     NOT NULL DEFAULT '',
        confirmed     TINYINT(1)   NOT NULL DEFAULT 0,
        created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Migrate existing rows: add columns if they don't exist yet
    $pdo->exec("ALTER TABLE subscribers
        ADD COLUMN IF NOT EXISTS confirm_token CHAR(64) NOT NULL DEFAULT '',
        ADD COLUMN IF NOT EXISTS confirmed TINYINT(1) NOT NULL DEFAULT 0");

    // Treat anyone who signed up before double opt-in as already confirmed
    $pdo->exec("UPDATE subscribers SET confirmed = 1 WHERE confirmed = 0 AND confirm_token = ''");

    $pdo->exec("CREATE TABLE IF NOT EXISTS signup_attempts (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ip         VARCHAR(45)  NOT NULL,
        attempted_at DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ip_time (ip, attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Rate limit: max 5 attempts per IP per hour
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP']   // Cloudflare
       ?? $_SERVER['HTTP_X_FORWARDED_FOR']
       ?? $_SERVER['REMOTE_ADDR']
       ?? '0.0.0.0';
    $ip = trim(explode(',', $ip)[0]); // take first IP if comma-separated

    // Purge attempts older than 1 hour to keep table small
    $pdo->prepare("DELETE FROM signup_attempts WHERE attempted_at < NOW() - INTERVAL 1 HOUR")->execute();

    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM signup_attempts WHERE ip = :ip AND attempted_at > NOW() - INTERVAL 1 HOUR");
    $count_stmt->execute([':ip' => $ip]);
    if ((int) $count_stmt->fetchColumn() >= 5) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many attempts. Please try again later.']);
        exit;
    }

    $pdo->prepare("INSERT INTO signup_attempts (ip) VALUES (:ip)")->execute([':ip' => $ip]);

    $token         = bin2hex(random_bytes(32));
    $confirm_token = bin2hex(random_bytes(32));

    // Try inserting new subscriber (unconfirmed)
    $stmt = $pdo->prepare(
        "INSERT INTO subscribers (email, token, confirm_token, confirmed)
         VALUES (:email, :token, :confirm_token, 0)
         ON DUPLICATE KEY UPDATE
           confirm_token = IF(confirmed = 0, VALUES(confirm_token), confirm_token)"
    );
    $stmt->execute([':email' => $email, ':token' => $token, ':confirm_token' => $confirm_token]);

    // Fetch the actual confirm_token for this email (may have been updated above)
    $row = $pdo->prepare("SELECT confirm_token, confirmed FROM subscribers WHERE email = :email");
    $row->execute([':email' => $email]);
    $sub = $row->fetch(PDO::FETCH_ASSOC);

    if ($sub && !$sub['confirmed'] && $sub['confirm_token'] !== '') {
        $confirm_url = 'https://failosaurusrex.com/confirm.php?token=' . $sub['confirm_token'];
        send_confirm_request($email, $smtp_pass, $confirm_url);
    }

    // Always return ok — don't reveal whether email exists
    echo json_encode(['ok' => true, 'message' => 'Check your inbox to confirm your subscription.']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}

function send_confirm_request(string $to, string $smtp_pass, string $confirm_url): void {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = 'smtp.hostinger.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'hello@failosaurusrex.com';
    $mail->Password   = $smtp_pass;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    $mail->setFrom('hello@failosaurusrex.com', 'Failosaurus Rex');
    $mail->addAddress($to);
    $mail->Subject = 'Confirm your FRX subscription';
    $mail->isHTML(true);

    $url_esc = htmlspecialchars($confirm_url, ENT_QUOTES, 'UTF-8');

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
        </td>
      </tr>
      <tr><td bgcolor="#2c1f16" height="1" style="background-color:#2c1f16;font-size:0;line-height:0;mso-line-height-rule:exactly;">&nbsp;</td></tr>
      <tr>
        <td bgcolor="#1b1410" style="background-color:#1b1410;padding:40px 40px 48px 40px;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.75;color:#ede5d8;mso-line-height-rule:exactly;">
          <p style="margin:0 0 20px 0;color:#ede5d8;">One click and you're in.</p>
          <p style="margin:0 0 32px 0;color:#7a6e64;font-size:15px;">Confirm your email address to start getting the FRX newsletter — tech, culture, and whatever I'm currently failing at. Weekly. Free.</p>
          <table role="presentation" border="0" cellpadding="0" cellspacing="0">
            <tr>
              <td bgcolor="#39ff14" style="background-color:#39ff14;border-radius:3px;">
                <a href="{$url_esc}" style="display:inline-block;padding:14px 28px;font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#060d02;text-decoration:none;">Confirm subscription →</a>
              </td>
            </tr>
          </table>
          <p style="margin:28px 0 0;font-size:12px;color:#4a3f37;">Or paste this link in your browser:<br><a href="{$url_esc}" style="color:#4a3f37;word-break:break-all;">{$url_esc}</a></p>
          <p style="margin:20px 0 0;font-size:12px;color:#4a3f37;">If you didn't sign up for this, ignore this email — you won't hear from us again.</p>
        </td>
      </tr>
      <tr>
        <td bgcolor="#110c08" style="background-color:#110c08;padding:24px 40px 32px 40px;">
          <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.65;color:#7a6e64;">This confirmation was requested at <a href="https://failosaurusrex.com" style="color:#7a6e64;text-decoration:underline;">failosaurusrex.com</a>.</p>
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body>
</html>
HTML;

    $mail->AltBody = "Confirm your FRX subscription\n\nClick the link below to confirm:\n{$confirm_url}\n\nIf you didn't sign up, ignore this email.";
    $mail->send();
}
