<?php
$config_path = dirname(__DIR__) . '/frx-db-config.php';
if (!file_exists($config_path)) {
    http_response_code(500);
    die('Server configuration missing.');
}
require $config_path;

// ── Helpers ───────────────────────────────────────────────────────────────

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function plain_text($html) {
    $html = preg_replace('#<(script|style)\b.*?</\1>#is', ' ', $html);
    $html = preg_replace('#<!--.*?-->#s', ' ', $html);
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
    $pat = '#^\s*<(h[1-3])\b[^>]*>(.*?)</\1>\s*#is';
    if (preg_match($pat, $html, $m) && mb_strtolower(plain_text($m[2])) === mb_strtolower(trim($title))) {
        $html = substr($html, strlen($m[0]));
    }
    return $html;
}

const LIB_TYPES = [
    'guide'   => ['Guide',   'Guides',   'The how-to, in full'],
    'tool'    => ['Tool',    'Tools',    'Things I actually opened'],
    'attempt' => ['Attempt', 'Attempts', 'What happened when I tried'],
];
const LIB_STATUS = [
    'worked'  => 'Worked (eventually)',
    'sort_of' => 'Sort of (it limps)',
    'failed'  => 'Failed (instructively)',
    'ongoing' => 'Still going',
];
const LIB_DIFFICULTY = [
    'beginner'     => 'Beginner',
    'intermediate' => 'Intermediate',
    'advanced'     => 'Advanced',
];
const LIB_PER_PAGE = 24;

function tag_list($csv) {
    $out = [];
    foreach (explode(',', (string)$csv) as $t) {
        $t = trim($t);
        if ($t !== '' && preg_match('/^[a-z0-9\-]{1,30}$/', $t)) $out[$t] = $t;
    }
    return array_values($out);
}

function safe_http_url($u) {
    $u = trim((string)$u);
    if ($u === '' || strlen($u) > 500) return '';
    $p = parse_url($u);
    if (!$p || empty($p['scheme']) || empty($p['host']) || !in_array(strtolower($p['scheme']), ['http', 'https'], true)) return '';
    return $u;
}

function entry_href($it) { return '/library/' . rawurlencode($it['slug']); }

function lib_query($over = []) {
    global $f_type, $f_tag, $f_q;
    $p = ['type' => $f_type, 'tag' => $f_tag, 'q' => $f_q, 'page' => 1];
    foreach ($over as $k => $v) $p[$k] = $v;
    if ((int)$p['page'] <= 1) $p['page'] = '';
    $p = array_filter($p, function ($v) { return $v !== '' && $v !== null; });
    return '/library.php' . ($p ? '?' . http_build_query($p) : '');
}

function status_pill($it) {
    if ($it['type'] !== 'attempt' || empty($it['status']) || !isset(LIB_STATUS[$it['status']])) return '';
    return '<span class="lib-pill lib-status lib-status--' . e($it['status']) . '">' . e(LIB_STATUS[$it['status']]) . '</span>';
}

function difficulty_pill($it) {
    if (empty($it['difficulty']) || !isset(LIB_DIFFICULTY[$it['difficulty']])) return '';
    return '<span class="lib-pill">' . e(LIB_DIFFICULTY[$it['difficulty']]) . '</span>';
}

function render_card($it, $featured = false) {
    $plain = plain_text($it['body']);
    $has_body = $plain !== '';
    $ext = $it['type'] === 'tool' ? safe_http_url($it['url']) : '';
    $ex = trim($it['summary']) !== '' ? $it['summary'] : excerpt($plain, 160);
    $tags = tag_list($it['tags']);
    $ts = strtotime($it['created_at']);
    $search = mb_strtolower($it['title'] . ' ' . $it['summary'] . ' ' . implode(' ', $tags) . ' ' . LIB_TYPES[$it['type']][0] . ' ' . mb_substr($plain, 0, 600));
    // Main link: detail page when there is one, otherwise straight out to the tool.
    $to_detail = $has_body || $ext === '';
    $href = $to_detail ? entry_href($it) : $ext;
    $rel = $to_detail ? '' : ' rel="noopener noreferrer" target="_blank"';
    ob_start(); ?>
    <li class="lib-item" data-search="<?= e($search) ?>">
      <article class="lib-card lib-card--<?= e($it['type']) ?><?= $featured ? ' lib-card--featured' : '' ?>">
        <div class="lib-card-top">
          <span class="lib-badge lib-badge--<?= e($it['type']) ?>"><?= e(LIB_TYPES[$it['type']][0]) ?></span>
          <?= status_pill($it) ?>
          <?= difficulty_pill($it) ?>
        </div>
        <h3 class="lib-card-title"><a class="lib-card-link" href="<?= e($href) ?>"<?= $rel ?>><?= e($it['title']) ?><?php if (!$to_detail): ?><span class="visually-hidden"> (opens external site)</span><?php endif; ?></a></h3>
        <?php if ($ex !== ''): ?><p class="lib-card-sum"><?= e($ex) ?></p><?php endif; ?>
        <p class="lib-card-meta">
          <time datetime="<?= e(date('Y-m-d', $ts)) ?>"><?= e(date('M j, Y', $ts)) ?></time>
          <?php if ($has_body): ?><span><?= reading_minutes($plain) ?> min read</span><?php endif; ?>
          <?php if ($ext !== ''): ?><span><?= e(preg_replace('/^www\./', '', (string)parse_url($ext, PHP_URL_HOST))) ?></span><?php endif; ?>
        </p>
        <?php if ($tags): ?>
        <p class="lib-card-tags"><?php foreach (array_slice($tags, 0, 4) as $t): ?><a class="lib-tag" href="<?= e('/library.php?tag=' . urlencode($t)) ?>">#<?= e($t) ?></a><?php endforeach; ?></p>
        <?php endif; ?>
        <?php if ($ext !== '' && $has_body): ?>
        <a class="lib-ext" href="<?= e($ext) ?>" rel="noopener noreferrer" target="_blank">Visit site <span aria-hidden="true">↗</span><span class="visually-hidden"> (opens external site)</span></a>
        <?php endif; ?>
      </article>
    </li>
    <?php
    return ob_get_clean();
}

// ── Routing: entry or listing ─────────────────────────────────────────────

$slug = '';
$slug_requested = false;
$path = $_SERVER['REQUEST_URI'] ?? '';
if (preg_match('#^/library/([a-z0-9\-]+)/?$#', strtok($path, '?'), $m)) {
    $slug = $m[1];
    $slug_requested = true;
} elseif (isset($_GET['slug'])) {
    $slug_requested = true;
    if (is_string($_GET['slug']) && preg_match('/^[a-z0-9\-]{1,120}$/', $_GET['slug'])) $slug = $_GET['slug'];
}

// Draft preview: only if the admin session cookie is present AND authed.
$preview = false;
if ($slug !== '' && isset($_GET['preview']) && isset($_COOKIE['frx_admin'])) {
    session_name('frx_admin');
    session_start(['read_and_close' => true]);
    $preview = !empty($_SESSION['authed']);
}

$f_type = (isset($_GET['type']) && is_string($_GET['type']) && isset(LIB_TYPES[$_GET['type']])) ? $_GET['type'] : '';
$f_tag  = (isset($_GET['tag']) && is_string($_GET['tag']) && preg_match('/^[a-z0-9\-]{1,30}$/', $_GET['tag'])) ? $_GET['tag'] : '';
$f_q    = (isset($_GET['q']) && is_string($_GET['q'])) ? trim(mb_substr(preg_replace('/\s+/u', ' ', $_GET['q']), 0, 80)) : '';
$f_page = (isset($_GET['page']) && is_string($_GET['page'])) ? max(1, min(500, (int)$_GET['page'])) : 1;

$cols = "id, type, slug, title, summary, body, url, tags, status, difficulty, featured, published, created_at, updated_at";

try {
    $pdo = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $pdo->exec("CREATE TABLE IF NOT EXISTS library_items (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        type       ENUM('guide','tool','attempt') NOT NULL,
        slug       VARCHAR(120) NOT NULL UNIQUE,
        title      VARCHAR(200) NOT NULL,
        summary    VARCHAR(400) NOT NULL DEFAULT '',
        body       MEDIUMTEXT   NOT NULL,
        url        VARCHAR(500) NULL,
        tags       VARCHAR(255) NOT NULL DEFAULT '',
        status     ENUM('worked','sort_of','failed','ongoing') NULL,
        difficulty ENUM('beginner','intermediate','advanced') NULL,
        featured   TINYINT(1)   NOT NULL DEFAULT 0,
        published  TINYINT(1)   NOT NULL DEFAULT 0,
        created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_pub (published, type, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if ($slug_requested) {
        // ── Single entry ──────────────────────────────────────────────────
        $entry = null;
        if ($slug !== '') {
            $sql = "SELECT $cols FROM library_items WHERE slug = :slug" . ($preview ? '' : ' AND published = 1') . " LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':slug' => $slug]);
            $entry = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if (!$entry) {
            http_response_code(404);
            $page_mode = 'not_found';
        } else {
            $page_mode = 'entry';
            $plain = plain_text($entry['body']);
            $read_min = reading_minutes($plain);
            $body = strip_duplicate_heading($entry['body'], $entry['title']);
            $desc = trim($entry['summary']) !== '' ? trim($entry['summary']) : excerpt($plain, 160);
            if ($desc === '') $desc = LIB_TYPES[$entry['type']][0] . ' from the Failosaurus Rex library';
            $ts = strtotime($entry['created_at']);
            $ts_mod = strtotime($entry['updated_at']) ?: $ts;
            $tags = tag_list($entry['tags']);
            $ext = $entry['type'] === 'tool' ? safe_http_url($entry['url']) : '';
            $is_draft = !(int)$entry['published'];

            $related = [];
            if ($tags) {
                $conds = [];
                $params = [':id' => (int)$entry['id']];
                foreach (array_slice($tags, 0, 6) as $i => $t) {
                    // tags are validated [a-z0-9-], so no LIKE wildcards can sneak in
                    $conds[] = "(tags = :a$i OR tags LIKE :b$i OR tags LIKE :c$i OR tags LIKE :d$i)";
                    $params[":a$i"] = $t;
                    $params[":b$i"] = $t . ',%';
                    $params[":c$i"] = '%,' . $t . ',%';
                    $params[":d$i"] = '%,' . $t;
                }
                $rq = $pdo->prepare("SELECT $cols FROM library_items WHERE published = 1 AND id <> :id AND (" . implode(' OR ', $conds) . ") ORDER BY featured DESC, created_at DESC, id DESC LIMIT 3");
                $rq->execute($params);
                $related = $rq->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    } else {
        // ── Listing ───────────────────────────────────────────────────────
        $page_mode = 'listing';
        $all = $pdo->query("SELECT $cols FROM library_items WHERE published = 1 ORDER BY created_at DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);

        $counts = ['guide' => 0, 'tool' => 0, 'attempt' => 0];
        $tag_counts = [];
        foreach ($all as $it) {
            if (isset($counts[$it['type']])) $counts[$it['type']]++;
            foreach (tag_list($it['tags']) as $t) $tag_counts[$t] = ($tag_counts[$t] ?? 0) + 1;
        }
        arsort($tag_counts);
        $ordered = [];
        foreach ($tag_counts as $t => $n) $ordered[] = [$t, $n];
        usort($ordered, function ($a, $b) { return $b[1] <=> $a[1] ?: strcmp($a[0], $b[0]); });
        $chips = array_slice($ordered, 0, 14);
        if ($f_tag !== '' && !in_array($f_tag, array_column($chips, 0), true)) $chips[] = [$f_tag, $tag_counts[$f_tag] ?? 0];

        $terms = $f_q === '' ? [] : preg_split('/\s+/u', mb_strtolower($f_q));
        $filtered = array_values(array_filter($all, function ($it) use ($f_type, $f_tag, $terms) {
            if ($f_type !== '' && $it['type'] !== $f_type) return false;
            if ($f_tag !== '' && !in_array($f_tag, tag_list($it['tags']), true)) return false;
            if ($terms) {
                $hay = mb_strtolower($it['title'] . ' ' . $it['summary'] . ' ' . $it['tags'] . ' ' . plain_text($it['body']));
                foreach ($terms as $t) if (mb_strpos($hay, $t) === false) return false;
            }
            return true;
        }));
        $total_all = count($all);
        $total = count($filtered);
        $pages = max(1, (int)ceil($total / LIB_PER_PAGE));
        $f_page = min($f_page, $pages);
        $shown = array_slice($filtered, ($f_page - 1) * LIB_PER_PAGE, LIB_PER_PAGE);
        $has_filter = ($f_type !== '' || $f_tag !== '' || $f_q !== '');
        $featured_items = (!$has_filter && $f_page === 1) ? array_slice(array_values(array_filter($all, function ($it) { return (int)$it['featured'] === 1; })), 0, 3) : [];
    }
} catch (PDOException $e) {
    error_log('library.php: ' . $e->getMessage());
    http_response_code(500);
    die('Database error.');
}

$site = 'https://failosaurusrex.com';
$ld = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?php if ($page_mode === 'entry'): ?>
  <?php $url = $site . '/library/' . $entry['slug']; $ttl = $entry['title'] . ' — FRX Library'; ?>
  <title><?= e($ttl) ?></title>
  <meta name="description" content="<?= e($desc) ?>">
  <link rel="canonical" href="<?= e($url) ?>">
  <?php if ($is_draft): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
  <meta property="og:title"       content="<?= e($ttl) ?>">
  <meta property="og:description" content="<?= e($desc) ?>">
  <meta property="og:url"         content="<?= e($url) ?>">
  <meta property="og:type"        content="<?= $entry['type'] === 'tool' ? 'website' : 'article' ?>">
  <?php if ($entry['type'] !== 'tool'): ?><meta property="article:published_time" content="<?= e(date('c', $ts)) ?>"><?php endif; ?>
  <meta name="twitter:title"       content="<?= e($ttl) ?>">
  <meta name="twitter:description" content="<?= e($desc) ?>">
  <script type="application/ld+json"><?= json_encode($entry['type'] === 'tool' ? [
      '@context' => 'https://schema.org',
      '@type' => 'WebPage',
      'name' => $entry['title'],
      'description' => $desc,
      'url' => $url,
      'datePublished' => date('c', $ts),
      'dateModified' => date('c', $ts_mod),
      'isPartOf' => ['@type' => 'CollectionPage', 'name' => 'Library — Failosaurus Rex', 'url' => $site . '/library.php'],
      'author' => ['@type' => 'Person', 'name' => 'Failosaurus Rex'],
  ] : [
      '@context' => 'https://schema.org',
      '@type' => 'Article',
      'headline' => $entry['title'],
      'description' => $desc,
      'datePublished' => date('c', $ts),
      'dateModified' => date('c', $ts_mod),
      'mainEntityOfPage' => $url,
      'image' => $site . '/og-image.png',
      'keywords' => implode(', ', $tags),
      'author' => ['@type' => 'Person', 'name' => 'Failosaurus Rex'],
      'publisher' => ['@type' => 'Organization', 'name' => 'Failosaurus Rex', 'url' => $site . '/'],
  ], $ld) ?></script>
  <script type="application/ld+json"><?= json_encode([
      '@context' => 'https://schema.org',
      '@type' => 'BreadcrumbList',
      'itemListElement' => [
          ['@type' => 'ListItem', 'position' => 1, 'name' => 'Library', 'item' => $site . '/library.php'],
          ['@type' => 'ListItem', 'position' => 2, 'name' => LIB_TYPES[$entry['type']][1], 'item' => $site . '/library.php?type=' . $entry['type']],
          ['@type' => 'ListItem', 'position' => 3, 'name' => $entry['title']],
      ],
  ], $ld) ?></script>
  <?php elseif ($page_mode === 'listing'): ?>
  <?php
    $ldesc = 'Guides, tools and a running log of attempts — including the ones that went sideways. For everyone who was never immediately great at anything.';
    $lcanon = $site . lib_query(['q' => '']);
    $ltitle = ($f_type !== '' ? LIB_TYPES[$f_type][1] . ' — ' : '') . 'Library — Failosaurus Rex';
  ?>
  <title><?= e($ltitle) ?></title>
  <meta name="description" content="<?= e($ldesc) ?>">
  <link rel="canonical" href="<?= e($lcanon) ?>">
  <?php if ($f_q !== ''): ?><meta name="robots" content="noindex, follow"><?php endif; ?>
  <meta property="og:title"       content="<?= e($ltitle) ?>">
  <meta property="og:description" content="<?= e($ldesc) ?>">
  <meta property="og:url"         content="<?= e($lcanon) ?>">
  <meta property="og:type"        content="website">
  <meta name="twitter:title"       content="<?= e($ltitle) ?>">
  <meta name="twitter:description" content="<?= e($ldesc) ?>">
  <script type="application/ld+json"><?= json_encode([
      '@context' => 'https://schema.org',
      '@type' => 'CollectionPage',
      'name' => 'Library — Failosaurus Rex',
      'url' => $lcanon,
      'description' => $ldesc,
      'isPartOf' => ['@type' => 'WebSite', 'name' => 'Failosaurus Rex', 'url' => $site . '/'],
      'mainEntity' => ['@type' => 'ItemList', 'numberOfItems' => $total, 'itemListElement' => array_map(function ($it, $i) use ($site) {
          return ['@type' => 'ListItem', 'position' => $i + 1, 'url' => $site . '/library/' . $it['slug'], 'name' => $it['title']];
      }, $shown, array_keys($shown))],
  ], $ld) ?></script>
  <?php else: ?>
  <title>Not found — FRX Library</title>
  <meta name="robots" content="noindex">
  <?php endif; ?>
  <meta property="og:image" content="<?= $site ?>/og-image.png">
  <meta name="twitter:card"  content="summary_large_image">
  <meta name="twitter:image" content="<?= $site ?>/og-image.png">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,wght@0,400;0,500;0,700;1,400&display=swap">
  <link rel="icon" href="/favicon.svg" type="image/svg+xml">
  <link rel="icon" href="/favicon-32.png" sizes="32x32" type="image/png">
  <link rel="apple-touch-icon" href="/apple-touch-icon.png">
  <link rel="alternate" type="application/rss+xml" title="Failosaurus Rex" href="/rss.php">
  <link rel="stylesheet" href="/styles.css">
  <link rel="stylesheet" href="/library.css">
</head>
<body>

  <a class="skip-link" href="#main">Skip to content</a>

  <header class="site-header">
    <div class="inner">
      <nav class="nav" aria-label="Primary">
        <a href="/" class="nav-logo"><img class="nav-mark" src="/brand/logo-mark.svg" alt="" width="30" height="30"><span>FRX</span></a>
        <ul class="nav-links">
          <li><a href="/#about">About</a></li>
          <li><a href="/#newsletter">Subscribe</a></li>
          <li><a href="/library.php"<?= $page_mode === 'listing' ? ' aria-current="page"' : '' ?>>Library</a></li>
          <li><a href="/archive.php">Archive</a></li>
        </ul>
      </nav>
    </div>
  </header>

  <main id="main">
    <section class="section lib-section">

    <?php if ($page_mode === 'listing'): ?>
      <div class="inner lib-wrap">

        <header class="lib-hero">
          <p class="eyebrow">The Library</p>
          <h1 class="lib-h1">Things I tried.<br>Things I use.<br><span>Things I got wrong.</span></h1>
          <p class="lib-lede">Guides for the how, a short list of tools I actually opened, and a log of attempts, including the ones that went sideways. Especially those.</p>
          <?php if ($total_all > 0): ?>
          <p class="lib-count" aria-label="Library contents">
            <span><b><?= $counts['guide'] ?></b> <?= $counts['guide'] === 1 ? 'guide' : 'guides' ?></span>
            <span><b><?= $counts['tool'] ?></b> <?= $counts['tool'] === 1 ? 'tool' : 'tools' ?></span>
            <span><b><?= $counts['attempt'] ?></b> <?= $counts['attempt'] === 1 ? 'attempt' : 'attempts' ?></span>
          </p>
          <?php endif; ?>
        </header>

        <?php if ($total_all === 0): ?>
          <div class="lib-empty">
            <p class="lib-empty-title">The shelves are bare.</p>
            <p class="lib-empty-copy">The first entry is being attempted. It is, naturally, not going perfectly. That is rather the point. Subscribe and you will hear about it the moment it lands (or collapses).</p>
            <a class="btn" href="/#newsletter">Get the newsletter</a>
          </div>
        <?php else: ?>

          <nav class="lib-tabs" aria-label="Library sections">
            <a href="<?= e(lib_query(['type' => ''])) ?>"<?= $f_type === '' ? ' aria-current="page"' : '' ?>>All <span class="lib-tab-n"><?= $total_all ?></span></a>
            <?php foreach (LIB_TYPES as $k => $lbl): ?>
            <a href="<?= e(lib_query(['type' => $k])) ?>"<?= $f_type === $k ? ' aria-current="page"' : '' ?>><?= e($lbl[1]) ?> <span class="lib-tab-n"><?= $counts[$k] ?></span></a>
            <?php endforeach; ?>
          </nav>

          <form class="lib-search" method="get" action="/library.php" role="search" id="lib-search-form">
            <label class="visually-hidden" for="lib-q">Search the library</label>
            <input class="lib-input" type="search" id="lib-q" name="q" value="<?= e($f_q) ?>" placeholder="Search guides, tools, disasters…" maxlength="80" autocomplete="off">
            <?php if ($f_type !== ''): ?><input type="hidden" name="type" value="<?= e($f_type) ?>"><?php endif; ?>
            <?php if ($f_tag !== ''): ?><input type="hidden" name="tag" value="<?= e($f_tag) ?>"><?php endif; ?>
            <button class="btn lib-search-btn" type="submit">Search</button>
          </form>

          <?php if ($chips): ?>
          <div class="lib-chips" role="group" aria-label="Filter by tag">
            <?php foreach ($chips as [$t, $n]): $on = ($f_tag === $t); ?>
            <a class="lib-chip<?= $on ? ' is-on' : '' ?>" href="<?= e(lib_query(['tag' => $on ? '' : $t])) ?>"<?= $on ? ' aria-current="true"' : '' ?>>#<?= e($t) ?><?php if ($on): ?> <span class="visually-hidden">(remove filter)</span><span aria-hidden="true">×</span><?php endif; ?></a>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <?php if ($featured_items): ?>
          <section class="lib-start" aria-labelledby="lib-start-h">
            <h2 class="lib-h2" id="lib-start-h">Start here</h2>
            <p class="lib-h2-sub">If you only open three things.</p>
            <ul class="lib-grid lib-grid--start">
              <?php foreach ($featured_items as $it) echo render_card($it, true); ?>
            </ul>
          </section>
          <?php endif; ?>

          <section aria-labelledby="lib-all-h">
            <div class="lib-results-head">
              <h2 class="lib-h2" id="lib-all-h"><?= $has_filter ? 'Results' : 'Everything on the shelves' ?></h2>
              <p class="lib-results-count" id="lib-results-count" role="status" aria-live="polite"><?= $total ?> <?= $total === 1 ? 'entry' : 'entries' ?><?php if ($f_q !== ''): ?> for “<?= e($f_q) ?>”<?php endif; ?></p>
              <?php if ($has_filter): ?><a class="lib-clear" href="/library.php">Clear filters</a><?php endif; ?>
            </div>

            <?php if ($total === 0): ?>
              <div class="lib-empty lib-empty--filter">
                <p class="lib-empty-title">Nothing matches that.</p>
                <p class="lib-empty-copy">Either I haven't failed at it yet, or you've found a word I never use. Try fewer words, or clear the filters and wander.</p>
                <a class="btn btn--ghost" href="/library.php">Clear filters</a>
              </div>
            <?php else: ?>
              <ul class="lib-grid" id="lib-grid">
                <?php foreach ($shown as $it) echo render_card($it); ?>
              </ul>
              <div class="lib-empty lib-empty--filter" id="lib-js-empty" hidden>
                <p class="lib-empty-title">Nothing matches that.</p>
                <p class="lib-empty-copy">No match on this page. Press Enter to search the whole library.</p>
              </div>

              <?php if ($pages > 1): ?>
              <nav class="lib-pager" aria-label="Pagination" id="lib-pager">
                <?php if ($f_page > 1): ?><a href="<?= e(lib_query(['page' => $f_page - 1])) ?>" rel="prev"><span aria-hidden="true">←</span> Newer</a><?php else: ?><span></span><?php endif; ?>
                <span class="lib-pager-pos">Page <?= $f_page ?> of <?= $pages ?></span>
                <?php if ($f_page < $pages): ?><a href="<?= e(lib_query(['page' => $f_page + 1])) ?>" rel="next">Older <span aria-hidden="true">→</span></a><?php else: ?><span></span><?php endif; ?>
              </nav>
              <?php endif; ?>
            <?php endif; ?>
          </section>

        <?php endif; ?>

        <aside class="lib-cta" aria-labelledby="lib-cta-title">
          <p class="lib-cta-kicker">Weekly. Free.</p>
          <h2 class="lib-cta-title" id="lib-cta-title">New entries land in the newsletter first.</h2>
          <p class="lib-cta-copy">For everyone who was never immediately great at anything. One email a week, no spam.</p>
          <a class="btn" href="/#newsletter">Subscribe</a>
        </aside>

      </div>
      <script src="/library.js" defer></script>

    <?php elseif ($page_mode === 'entry'): ?>
      <div class="inner lib-wrap lib-wrap--entry">

        <?php if ($is_draft): ?>
        <p class="lib-draft-banner" role="status"><b>Draft preview.</b> This entry is unpublished and invisible to everyone else. <a href="/admin/library.php?edit=<?= (int)$entry['id'] ?>">Back to editor</a></p>
        <?php endif; ?>

        <nav class="lib-crumbs" aria-label="Breadcrumb">
          <ol>
            <li><a href="/library.php">Library</a></li>
            <li><a href="/library.php?type=<?= e($entry['type']) ?>"><?= e(LIB_TYPES[$entry['type']][1]) ?></a></li>
            <li aria-current="page"><?= e(mb_strlen($entry['title']) > 40 ? mb_substr($entry['title'], 0, 40) . '…' : $entry['title']) ?></li>
          </ol>
        </nav>

        <article class="lib-entry">
          <header class="lib-entry-head">
            <p class="lib-eyebrow">
              <span class="lib-eyebrow-type lib-eyebrow-type--<?= e($entry['type']) ?>"><?= e(LIB_TYPES[$entry['type']][0]) ?></span>
              <time datetime="<?= e(date('Y-m-d', $ts)) ?>"><?= e(date('F j, Y', $ts)) ?></time>
              <?php if ($plain !== ''): ?><span><?= $read_min ?> min read</span><?php endif; ?>
            </p>
            <h1 class="lib-entry-h1"><?= e($entry['title']) ?></h1>
            <?php if (trim($entry['summary']) !== ''): ?><p class="lib-entry-dek"><?= e($entry['summary']) ?></p><?php endif; ?>
            <?php if (status_pill($entry) . difficulty_pill($entry) !== ''): ?>
            <p class="lib-entry-pills"><?php if ($entry['type'] === 'attempt'): ?><span class="lib-pills-label">Outcome</span><?php endif; ?><?= status_pill($entry) ?><?= difficulty_pill($entry) ?></p>
            <?php endif; ?>
            <?php if ($ext !== ''): ?>
            <p class="lib-visit"><a class="btn" href="<?= e($ext) ?>" rel="noopener noreferrer" target="_blank">Visit <?= e($entry['title']) ?> <span aria-hidden="true">↗</span><span class="visually-hidden"> (opens external site)</span></a>
              <span class="lib-visit-host"><?= e(preg_replace('/^www\./', '', (string)parse_url($ext, PHP_URL_HOST))) ?></span></p>
            <?php endif; ?>
          </header>

          <?php if (trim($body) !== ''): ?>
          <div class="lib-body">
            <?= $body ?>
          </div>
          <?php endif; ?>

          <?php if ($tags): ?>
          <div class="lib-entry-tags">
            <span class="lib-pills-label">Filed under</span>
            <?php foreach ($tags as $t): ?><a class="lib-chip" href="<?= e('/library.php?tag=' . urlencode($t)) ?>">#<?= e($t) ?></a><?php endforeach; ?>
          </div>
          <?php endif; ?>
        </article>

        <?php if ($related): ?>
        <section class="lib-related" aria-labelledby="lib-rel-h">
          <h2 class="lib-h2" id="lib-rel-h">Related entries</h2>
          <ul class="lib-grid">
            <?php foreach ($related as $it) echo render_card($it); ?>
          </ul>
        </section>
        <?php endif; ?>

        <aside class="lib-cta" aria-labelledby="lib-cta-title">
          <p class="lib-cta-kicker">Weekly. Free.</p>
          <h2 class="lib-cta-title" id="lib-cta-title">Enjoyed this? Get the next one.</h2>
          <p class="lib-cta-copy">For everyone who was never immediately great at anything. One email a week, no spam.</p>
          <a class="btn" href="/#newsletter">Subscribe</a>
        </aside>

        <a class="lib-back" href="/library.php">Back to the library</a>
      </div>

    <?php else: ?>
      <div class="inner lib-wrap lib-wrap--entry">
        <p class="eyebrow">404</p>
        <h1 class="lib-h1 lib-h1--sm">Not on the shelves.</h1>
        <p class="lib-lede">That entry doesn't exist, was unpublished, or never got past the "I should write that up" stage. <a class="lib-textlink" href="/library.php">Browse the library</a>.</p>
      </div>
    <?php endif; ?>

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
