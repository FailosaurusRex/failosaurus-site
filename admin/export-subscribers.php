<?php
ob_start();

$config_path = dirname(__DIR__, 2) . '/frx-db-config.php';
if (!file_exists($config_path)) { http_response_code(500); die('Server configuration missing.'); }
require $config_path;

session_name('frx_admin');
session_start();

if (empty($_SESSION['authed'])) {
    header('Location: /admin/');
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $rows = $pdo->query("SELECT email, created_at FROM subscribers WHERE confirmed = 1 ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    die('Database error.');
}

ob_end_clean();
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="frx-subscribers-' . date('Y-m-d') . '.csv"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fputcsv($out, ['email', 'confirmed_at']);
foreach ($rows as $row) {
    fputcsv($out, [$row['email'], $row['created_at']]);
}
fclose($out);
