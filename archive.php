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

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function plain_text($html) {
    $html = preg_replace('#<(script|style)\b.*?</\1>#is', ' ', $html);
    $html = preg_replace('#<(br|/p|/h[1-6]|/li|/div|/blockquote|/tr)\b[^>]*>#i', ' ', $html);
    $t = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $t));
}

function reading_minutes($plain) {
    return max(1, (int)ceil(str_word_count($plain) / 220));
}

function excerpt($plain, $len = 160) {
    if (mb_strlen($plain) <= $len) return $plain;
    $cut = mb_substr($plain, 0, $len);
    $sp = mb_strrpos($cut, ' ');
    if ($sp !== false && $sp > $len * 0.6) $cut = mb_substr($cut, 0, $sp);
    return rtrim($cut, " ,.;:-") . '…';
}

function strip_duplicate_heading($html, $title) {
    $norm = function ($h) { return mb_strtolower(plain_text($h)); };
    $pat = '#^\s*<(h[1-3])\b[^>]*>(.*?)</\1>\s*#is';
    for ($n = 0; $n < 2 && preg_match($pat, $html, $m); $n++) {
        $txt = $norm($m[2]);
        if ($txt === mb_strtolower(trim($title)) || preg_match('/^issue\s*#?\s*\d+\b/', $txt)) {
            $html = substr($html, strlen($m[0]));
        } else {
            break;
        }
    }
    return $html;
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
        $stmt = $pdo->prepare("SELECT id, title, preview_text, body_html, sent_at FROM issues WHERE slug = :slug LIMIT 1");
        $stmt->execute([':slug' => $slug]);
        $issue = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$issue) {
            http_response_code(404);
            $page_mode = 'not_found';
        } else {
            $page_mode = 'issue';
            $q = $pdo->prepare("SELECT COUNT(*) FROM issues WHERE sent_at < :s1 OR (sent_at = :s2 AND id <= :i)");
            $q->execute([':s1' => $issue['sent_at'], ':s2' => $issue['sent_at'], ':i' => $issue['id']]);
            $issue_no = (int)$q->fetchColumn();

            $q = $pdo->prepare("SELECT slug, title FROM issues WHERE sent_at < :s1 OR (sent_at = :s2 AND id < :i) ORDER BY sent_at DESC, id DESC LIMIT 1");
            $q->execute([':s1' => $issue['sent_at'], ':s2' => $issue['sent_at'], ':i' => $issue['id']]);
            $prev_issue = $q->fetch(PDO::FETCH_ASSOC) ?: null;

            $q = $pdo->prepare("SELECT slug, title FROM issues WHERE sent_at > :s1 OR (sent_at = :s2 AND id > :i) ORDER BY sent_at ASC, id ASC LIMIT 1");
            $q->execute([':s1' => $issue['sent_at'], ':s2' => $issue['sent_at'], ':i' => $issue['id']]);
            $next_issue = $q->fetch(PDO::FETCH_ASSOC) ?: null;

            $body = $issue['body_html'];
            $plain = plain_text($body);
            $read_min = reading_minutes($plain);
            $body = strip_duplicate_heading($body, $issue['title']);
            $desc = $issue['preview_text'] !== '' ? $issue['preview_text'] : excerpt($plain, 160);
            if ($desc === '') $desc = 'Failosaurus Rex newsletter';
            $ts = strtotime($issue['sent_at']);
        }
    } else {
        // ── Listing view ──────────────────────────────────────────────────
        $issues = $pdo->query("SELECT slug, title, preview_text, body_html, sent_at FROM issues ORDER BY sent_at DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
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
  <?php $url = 'https://failosaurusrex.com/archive/' . $slug; ?>
  <title><?= e($issue['title']) ?> — FRX</title>
  <meta name="description" content="<?= e($desc) ?>">
  <link rel="canonical" href="<?= e($url) ?>">
  <meta property="og:title"       content="<?= e($issue['title']) ?> — FRX">
  <meta property="og:description" content="<?= e($desc) ?>">
  <meta property="og:url"         content="<?= e($url) ?>">
  <meta property="og:type"        content="article">
  <meta property="article:published_time" content="<?= e(date('c', $ts)) ?>">
  <script type="application/ld+json"><?= json_encode([
      '@context' => 'https://schema.org',
      '@type' => 'Article',
      'headline' => $issue['title'],
      'description' => $desc,
      'datePublished' => date('c', $ts),
      'mainEntityOfPage' => $url,
      'image' => 'https://failosaurusrex.com/og-image.png',
      'author' => ['@type' => 'Person', 'name' => 'Failosaurus Rex'],
      'publisher' => ['@type' => 'Organization', 'name' => 'Failosaurus Rex', 'url' => 'https://failosaurusrex.com/'],
  ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
  <?php elseif ($page_mode === 'listing'): ?>
  <title>Archive — Failosaurus Rex</title>
  <meta name="description" content="Every issue of the Failosaurus Rex newsletter, in one place.">
  <link rel="canonical" href="https://failosaurusrex.com/archive.php">
  <meta property="og:title"       content="Archive — Failosaurus Rex">
  <meta property="og:description" content="Every issue of the Failosaurus Rex newsletter, in one place.">
  <meta property="og:url"         content="https://failosaurusrex.com/archive.php">
  <meta property="og:type"        content="website">
  <script type="application/ld+json"><?= json_encode([
      '@context' => 'https://schema.org',
      '@type' => 'Blog',
      'name' => 'Failosaurus Rex',
      'url' => 'https://failosaurusrex.com/archive.php',
      'description' => 'Every issue of the Failosaurus Rex newsletter, in one place.',
  ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
  <?php else: ?>
  <title>Not found — FRX</title>
  <meta name="robots" content="noindex">
  <?php endif; ?>
  <?php if ($page_mode !== 'issue'): ?>
  <meta property="og:type" content="website">
  <?php endif; ?>
  <meta property="og:image" content="https://failosaurusrex.com/og-image.png">
  <meta name="twitter:card"  content="summary_large_image">
  <meta name="twitter:image" content="https://failosaurusrex.com/og-image.png">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,wght@0,400;0,500;0,700;1,400&display=swap">
  <link rel="icon" href="/favicon.svg" type="image/svg+xml">
  <link rel="alternate" type="application/rss+xml" title="Failosaurus Rex" href="/rss.php">
  <link rel="stylesheet" href="/styles.css">
  <link rel="stylesheet" href="/archive.css">
</head>
<body>

  <?php if ($page_mode === 'issue'): ?>
  <div class="read-progress" id="read-progress" aria-hidden="true"></div>
  <?php endif; ?>

  <header class="site-header">
    <div class="inner">
      <nav class="nav">
        <a href="/" class="nav-logo">FRX</a>
        <ul class="nav-links">
          <li><a href="/#about">About</a></li>
          <li><a href="/#newsletter">Subscribe</a></li>
          <li><a href="/archive.php" aria-current="page">Archive</a></li>
        </ul>
      </nav>
    </div>
  </header>

  <main>
    <section class="section archive-section">
      <div class="inner archive-wrap">

        <?php if ($page_mode === 'listing'): ?>

          <p class="section-label">Newsletter</p>
          <h1 class="section-heading">Archive.</h1>
          <p class="section-body">Every issue, in one place.
            <?php if (!empty($issues)): ?><span class="archive-count"><?= count($issues) ?> <?= count($issues) === 1 ? 'issue' : 'issues' ?></span><?php endif; ?>
          </p>

          <?php if (empty($issues)): ?>
            <div class="empty-card">
              <p class="empty-title">Nothing here yet.</p>
              <p class="empty-copy">The dinosaur is still working on issue one. It is, naturally, not going perfectly. That is rather the point.</p>
              <a class="btn" href="/#newsletter">Get the first one in your inbox</a>
            </div>
          <?php else: $total = count($issues); ?>
            <ol class="issue-list">
              <?php foreach ($issues as $idx => $i):
                  $ip = plain_text($i['body_html']);
                  $ex = $i['preview_text'] !== '' ? $i['preview_text'] : excerpt($ip, 160);
                  $href = '/archive/' . e($i['slug']);
                  $its = strtotime($i['sent_at']);
              ?>
              <li class="issue-item">
                <a class="issue-card" href="<?= $href ?>">
                  <p class="issue-meta">
                    <span class="issue-num">No. <?= $total - $idx ?></span>
                    <time datetime="<?= e(date('Y-m-d', $its)) ?>"><?= e(date('M j, Y', $its)) ?></time>
                    <span><?= reading_minutes($ip) ?> min read</span>
                  </p>
                  <h2 class="issue-title"><?= e($i['title']) ?></h2>
                  <?php if ($ex !== ''): ?><p class="issue-preview"><?= e($ex) ?></p><?php endif; ?>
                  <span class="issue-read-link">Read issue <span aria-hidden="true">→</span></span>
                </a>
              </li>
              <?php endforeach; ?>
            </ol>
          <?php endif; ?>

        <?php elseif ($page_mode === 'issue'): ?>

          <a class="back-link" href="/archive.php">All issues</a>

          <article class="issue">
            <header class="issue-head">
              <p class="issue-meta">
                <span class="issue-num">No. <?= $issue_no ?></span>
                <time datetime="<?= e(date('Y-m-d', $ts)) ?>"><?= e(date('F j, Y', $ts)) ?></time>
                <span><?= $read_min ?> min read</span>
              </p>
              <h1 class="issue-h1"><?= e($issue['title']) ?></h1>
              <?php if ($issue['preview_text'] !== ''): ?><p class="issue-dek"><?= e($issue['preview_text']) ?></p><?php endif; ?>
            </header>

            <div class="issue-body">
              <?= $body ?>
            </div>

            <div class="share-row" id="share-row" hidden>
              <span class="share-label">Pass it on</span>
              <button type="button" class="share-btn" id="share-btn" data-title="<?= e($issue['title']) ?>" data-url="<?= e($url) ?>">Share</button>
              <button type="button" class="share-btn" id="copy-btn" data-url="<?= e($url) ?>">Copy link</button>
              <span class="share-status" id="share-status" role="status" aria-live="polite"></span>
            </div>
          </article>

          <aside class="cta-card" aria-labelledby="cta-title">
            <p class="cta-kicker">Weekly. Free.</p>
            <h2 class="cta-title" id="cta-title">Enjoyed this? Get the next one.</h2>
            <p class="cta-copy">For everyone who was never immediately great at anything. One email a week, no spam.</p>
            <a class="btn" href="/#newsletter">Subscribe</a>
          </aside>

          <nav class="issue-nav" aria-label="More issues">
            <?php if ($prev_issue): ?>
            <a class="issue-nav-link prev" href="/archive/<?= e($prev_issue['slug']) ?>" rel="prev">
              <span class="issue-nav-dir"><span aria-hidden="true">←</span> Previous</span>
              <span class="issue-nav-title"><?= e($prev_issue['title']) ?></span>
            </a>
            <?php endif; ?>
            <?php if ($next_issue): ?>
            <a class="issue-nav-link next" href="/archive/<?= e($next_issue['slug']) ?>" rel="next">
              <span class="issue-nav-dir">Next <span aria-hidden="true">→</span></span>
              <span class="issue-nav-title"><?= e($next_issue['title']) ?></span>
            </a>
            <?php endif; ?>
          </nav>

          <a class="back-link back-bottom" href="/archive.php">All issues</a>

          <script>
          (function () {
            var bar = document.getElementById('read-progress');
            if (bar) {
              var tick = false;
              var update = function () {
                tick = false;
                var h = document.documentElement.scrollHeight - window.innerHeight;
                bar.style.transform = 'scaleX(' + (h > 0 ? Math.min(1, window.scrollY / h) : 0) + ')';
              };
              window.addEventListener('scroll', function () { if (!tick) { tick = true; requestAnimationFrame(update); } }, { passive: true });
              window.addEventListener('resize', update);
              bar.classList.add('on');
              update();
            }
            var status = document.getElementById('share-status');
            var say = function (m) { if (status) { status.textContent = m; setTimeout(function () { status.textContent = ''; }, 2500); } };
            var row = document.getElementById('share-row');
            if (row) row.hidden = false;
            var share = document.getElementById('share-btn');
            var copy = document.getElementById('copy-btn');
            if (share && !navigator.share) share.hidden = true;
            if (share && navigator.share) share.addEventListener('click', function () {
              navigator.share({ title: share.dataset.title, url: share.dataset.url }).catch(function () {});
            });
            if (copy) copy.addEventListener('click', function () {
              var u = copy.dataset.url;
              var fallback = function () {
                var t = document.createElement('textarea');
                t.value = u; t.setAttribute('readonly', ''); t.style.position = 'fixed'; t.style.opacity = '0';
                document.body.appendChild(t); t.select();
                try { document.execCommand('copy'); say('Link copied'); } catch (e) { say('Copy failed'); }
                document.body.removeChild(t);
              };
              if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(u).then(function () { say('Link copied'); }, fallback);
              } else { fallback(); }
            });
          })();
          </script>

        <?php else: ?>

          <p class="section-label">404</p>
          <h1 class="section-heading">Not found.</h1>
          <p class="section-body">That issue doesn't exist. <a class="text-link" href="/archive.php">Browse all issues</a>.</p>

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
