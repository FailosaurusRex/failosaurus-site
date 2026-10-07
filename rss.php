<?php
$config_path = dirname(__DIR__) . '/frx-db-config.php';
if (!file_exists($config_path)) {
    http_response_code(500);
    exit;
}
require $config_path;

try {
    $pdo = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $issues = $pdo->query("SELECT slug, title, preview_text, body_html, sent_at FROM issues ORDER BY sent_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    exit;
}

$base        = 'https://failosaurusrex.com';
$feed_url    = $base . '/rss.php';
$last_build  = !empty($issues) ? date(DATE_RSS, strtotime($issues[0]['sent_at'])) : date(DATE_RSS);

header('Content-Type: application/rss+xml; charset=utf-8');
header('X-Content-Type-Options: nosniff');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0"
  xmlns:atom="http://www.w3.org/2005/Atom"
  xmlns:content="http://purl.org/rss/1.0/modules/content/">

  <channel>
    <title>Failosaurus Rex</title>
    <link><?= $base ?></link>
    <description>For everyone who was never immediately great at anything. Tech, culture, and whatever I'm currently failing at. Weekly. Free.</description>
    <language>en</language>
    <lastBuildDate><?= htmlspecialchars($last_build) ?></lastBuildDate>
    <atom:link href="<?= $feed_url ?>" rel="self" type="application/rss+xml"/>

<?php foreach ($issues as $issue):
    $item_url  = $base . '/archive/' . htmlspecialchars($issue['slug'], ENT_XML1, 'UTF-8');
    $pub_date  = date(DATE_RSS, strtotime($issue['sent_at']));
    $title_esc = htmlspecialchars($issue['title'],        ENT_XML1, 'UTF-8');
    $desc_esc  = htmlspecialchars($issue['preview_text'] ?: strip_tags($issue['body_html']), ENT_XML1, 'UTF-8');
?>
    <item>
      <title><?= $title_esc ?></title>
      <link><?= $item_url ?></link>
      <guid isPermaLink="true"><?= $item_url ?></guid>
      <pubDate><?= htmlspecialchars($pub_date) ?></pubDate>
      <description><?= $desc_esc ?></description>
      <content:encoded><![CDATA[<?= $issue['body_html'] ?>]]></content:encoded>
    </item>
<?php endforeach; ?>

  </channel>
</rss>
