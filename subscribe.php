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
require $config_path; // defines $db_host, $db_name, $db_user, $db_pass

try {
    $pdo = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $pdo->exec("CREATE TABLE IF NOT EXISTS subscribers (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        email      VARCHAR(255) NOT NULL UNIQUE,
        created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $stmt = $pdo->prepare("INSERT IGNORE INTO subscribers (email) VALUES (:email)");
    $stmt->execute([':email' => $email]);

    $is_new = $stmt->rowCount() > 0;

    if ($is_new) {
        send_confirmation($email);
    }

    echo json_encode(['ok' => true]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}

function send_confirmation(string $to): void {
    $from_name    = 'Failosaurus Rex';
    $from_address = 'noreply@failosaurusrex.com';
    $subject      = "You're in — welcome to FRX";

    $text = <<<TEXT
Hey — you made it.

You're now on the Failosaurus Rex newsletter. Every week: tech, culture,
and whatever I'm currently failing at. No spam, no fluff.

Talk soon,
Failosaurus Rex
failosaurusrex.com
TEXT;

    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#110c08;font-family:'DM Sans',Arial,sans-serif;color:#ede5d8;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#110c08;padding:48px 0;">
    <tr><td align="center">
      <table width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;">
        <tr><td style="padding:0 24px 32px;">
          <p style="font-size:1.1rem;font-weight:700;letter-spacing:0.14em;color:#39ff14;margin:0 0 24px;">FRX</p>
          <h1 style="font-size:2rem;color:#ede5d8;margin:0 0 16px;line-height:1.2;">You're in.</h1>
          <p style="font-size:1rem;color:#7a6e64;line-height:1.7;margin:0 0 24px;">
            Every week: tech, culture, and whatever I'm currently failing at.<br>
            No spam. No fluff. Just the honest version of figuring it out.
          </p>
          <p style="font-size:0.9rem;color:#7a6e64;margin:0;">
            Talk soon,<br>
            <a href="https://failosaurusrex.com" style="color:#39ff14;text-decoration:none;">Failosaurus Rex</a>
          </p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

    $boundary = md5(uniqid());
    $headers  = implode("\r\n", [
        "From: {$from_name} <{$from_address}>",
        "Reply-To: {$from_address}",
        "MIME-Version: 1.0",
        "Content-Type: multipart/alternative; boundary=\"{$boundary}\"",
        "X-Mailer: PHP/" . phpversion(),
    ]);

    $body = "--{$boundary}\r\n"
          . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
          . $text . "\r\n"
          . "--{$boundary}\r\n"
          . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
          . $html . "\r\n"
          . "--{$boundary}--";

    mail($to, $subject, $body, $headers);
}
