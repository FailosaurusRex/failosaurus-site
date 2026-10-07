<?php
$config_path = dirname(__DIR__) . '/frx-db-config.php';
$issues = [];
$library = [];

if (file_exists($config_path)) {
    require $config_path;
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
        $issues = $pdo->query("SELECT slug, sent_at FROM issues ORDER BY sent_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // silently serve static pages only
    }
    if (isset($pdo)) {
        try {
            $library = $pdo->query("SELECT slug, updated_at FROM library_items WHERE published = 1 ORDER BY updated_at DESC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // library table not created yet
        }
    }
}

header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">

  <url>
    <loc>https://failosaurusrex.com/</loc>
    <changefreq>weekly</changefreq>
    <priority>1.0</priority>
  </url>

  <url>
    <loc>https://failosaurusrex.com/archive.php</loc>
    <changefreq>weekly</changefreq>
    <priority>0.8</priority>
  </url>

  <url>
    <loc>https://failosaurusrex.com/library.php</loc>
    <changefreq>weekly</changefreq>
    <priority>0.8</priority>
  </url>

  <url>
    <loc>https://failosaurusrex.com/privacy.html</loc>
    <changefreq>yearly</changefreq>
    <priority>0.3</priority>
  </url>

  <url>
    <loc>https://failosaurusrex.com/terms.html</loc>
    <changefreq>yearly</changefreq>
    <priority>0.3</priority>
  </url>

<?php foreach ($issues as $issue): ?>
  <url>
    <loc>https://failosaurusrex.com/archive/<?= htmlspecialchars($issue['slug'], ENT_XML1, 'UTF-8') ?></loc>
    <lastmod><?= date('Y-m-d', strtotime($issue['sent_at'])) ?></lastmod>
    <changefreq>never</changefreq>
    <priority>0.7</priority>
  </url>
<?php endforeach; ?>
<?php foreach ($library as $item): ?>
  <url>
    <loc>https://failosaurusrex.com/library/<?= htmlspecialchars($item['slug'], ENT_XML1, 'UTF-8') ?></loc>
    <lastmod><?= date('Y-m-d', strtotime($item['updated_at'])) ?></lastmod>
    <changefreq>monthly</changefreq>
    <priority>0.6</priority>
  </url>
<?php endforeach; ?>

</urlset>
