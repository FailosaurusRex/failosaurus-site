<?php
ob_start(); // buffer output so header() redirects work even if config file has trailing whitespace

$config_path = dirname(__DIR__, 2) . '/frx-db-config.php';
if (!file_exists($config_path)) { http_response_code(500); die('Server configuration missing.'); }
require $config_path;

session_name('frx_admin');
session_start();

// Auth gate: must run before any output or DB access.
if (empty($_SESSION['authed'])) {
    header('Location: /admin/');
    exit;
}

// Per-session CSRF token (the other admin pages have none; this page does).
if (empty($_SESSION['csrf_library'])) {
    $_SESSION['csrf_library'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_library'];

// ── Helpers ───────────────────────────────────────────────────────────────

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

const L_TYPES = ['guide' => 'Guide', 'tool' => 'Tool', 'attempt' => 'Attempt'];
const L_STATUS = ['worked' => 'Worked', 'sort_of' => 'Sort of', 'failed' => 'Failed', 'ongoing' => 'Ongoing'];
const L_DIFF = ['beginner' => 'Beginner', 'intermediate' => 'Intermediate', 'advanced' => 'Advanced'];
const L_MAX = ['title' => 200, 'slug' => 120, 'summary' => 400, 'url' => 500, 'tags' => 255, 'body' => 400000];

function slugify($s) {
    $s = strtolower(trim((string)$s));
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($t !== false) $s = strtolower($t);
    }
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim(substr(trim($s, '-'), 0, L_MAX['slug']), '-');
}

function normalize_tags($raw) {
    $out = [];
    foreach (explode(',', mb_strtolower((string)$raw)) as $t) {
        $t = trim(preg_replace('/[^a-z0-9]+/', '-', trim($t)), '-');
        $t = substr($t, 0, 30);
        if ($t !== '') $out[$t] = $t;
    }
    $out = array_slice(array_values($out), 0, 8);
    $s = implode(',', $out);
    while (strlen($s) > L_MAX['tags']) { array_pop($out); $s = implode(',', $out); }
    return $s;
}

function clean_url($u) {
    $u = trim((string)$u);
    if ($u === '') return '';
    $p = parse_url($u);
    if (!$p || empty($p['scheme']) || empty($p['host']) || !in_array(strtolower($p['scheme']), ['http', 'https'], true)) return false;
    if (strlen($u) > L_MAX['url'] || preg_match('/[\x00-\x20\x7f]/', $u)) return false;
    return $u;
}

function flash($kind, $msg) { $_SESSION['flash'] = [$kind, $msg]; }

function go($qs = '') {
    header('Location: /admin/library.php' . $qs);
    exit;
}

function starter_entries() {
    $attempt_body = <<<'HTML'
<p>Substack exists. It is lovely. It works. I looked at it, said "how hard can it be," and built my own newsletter platform on shared hosting instead. Reader, it was a little hard.</p>

<h2>What I tried</h2>
<p>A newsletter that lives entirely on my own domain: plain PHP and MySQL, running on Hostinger shared hosting. A signup form, a confirmation email, an admin page for writing and sending issues, a public archive, and an unsubscribe link that actually works. No framework, no third-party email service, no build step.</p>

<h2>What went wrong</h2>
<ul>
  <li><strong>An .htaccess rule 403'd my own admin panel.</strong> I locked the door and then stood outside it, politely knocking.</li>
  <li><strong>A missing database column.</strong> The code expected it. The database had never heard of it. Every signup failed with great enthusiasm.</li>
  <li><strong>Invisible email text.</strong> Dark text on a dark background. The first test email looked blank. It was not blank. It was just rude.</li>
  <li><strong>A typo in the test.</strong> I debugged the wrong thing for longer than I will admit. The typo was in the test, not the code.</li>
</ul>

<h2>What I added along the way</h2>
<ul>
  <li><strong>Double opt-in</strong>, so nobody gets signed up by someone else's typo or mischief.</li>
  <li><strong>Self-hosted SMTP through Hostinger</strong>, so email goes out from my own domain.</li>
</ul>

<h2>What I learned</h2>
<ul>
  <li>Test the unglamorous paths first: the admin login, the empty database, the email in dark mode.</li>
  <li>When something "doesn't work," check whether the thing you are testing with is the broken part.</li>
  <li>Owning the whole stack is slower to start and much easier to understand later.</li>
</ul>

<h2>Outcome</h2>
<p>It works. It runs on my own domain and sends real emails to real people. Would I recommend it to someone who just wants to write? Honestly, no, use a platform and write. I did it this way because I wanted to know how it works, and now I do.</p>
<!-- TODO(owner): check every detail above against what actually happened and add anything I invented wrong or left out. -->
HTML;

    $tool_body = <<<'HTML'
<h2>The honest take</h2>
<p>Playwright drives a real browser from a script. I used it for two things on this site: generating the Open Graph image (render a page, screenshot it, done) and taking screenshots of the site at phone and desktop widths to see what I had actually built, as opposed to what I thought I had built.</p>
<h2>Good for</h2>
<ul>
  <li>Screenshots at exact sizes, on demand, without opening a browser and squinting.</li>
  <li>Checking that a page still works after you "just changed one thing."</li>
</ul>
<h2>Less good for</h2>
<ul>
  <li>Anything where you only need one screenshot, once. The setup is real. It downloads browsers.</li>
  <li>Skipping the step where you look at the result yourself.</li>
</ul>
<!-- TODO(owner): add the exact command or snippet you used, if you want one here. -->
HTML;

    $guide_body = <<<'HTML'
<p><strong>TODO(owner): this is a stub. Finish it before publishing.</strong> The outline is below; fill in each section in your own voice, with real code from the site.</p>

<h2>What double opt-in is, and why bother</h2>
<p>TODO: two or three sentences. Someone signs up, you email them a link, they click it, only then are they on the list.</p>

<h2>The moving parts</h2>
<ul>
  <li>A subscribers table with a confirmation token and a confirmed flag.</li>
  <li>A signup handler that stores the address as unconfirmed and sends the email.</li>
  <li>A confirmation page that checks the token and flips the flag.</li>
  <li>Sending only to confirmed subscribers.</li>
</ul>

<h2>Step 1: the table</h2>
<p>TODO: the CREATE TABLE and a note on why the token is long and random.</p>

<h2>Step 2: the signup handler</h2>
<p>TODO: validate, insert, send. Mention what happens when the same address signs up twice.</p>

<h2>Step 3: the confirmation link</h2>
<p>TODO: the token check, the success page, and the expired or wrong token page.</p>

<h2>Step 4: send only to people who said yes</h2>
<p>TODO: the one WHERE clause that matters.</p>

<h2>What went wrong when I did it</h2>
<p>TODO: the honest part. A missing column, probably.</p>
HTML;

    return [
        ['attempt', 'i-built-my-own-newsletter-platform-instead-of-using-substack',
         'I built my own newsletter platform instead of using Substack',
         'Custom PHP and MySQL on shared hosting, a locked-out admin panel, invisible email text, and a typo that wasted an afternoon. It works now.',
         $attempt_body, null, 'newsletter,php,mysql,self-hosting', 'worked', null, 1],
        ['tool', 'playwright', 'Playwright',
         'Scripts a real browser. I used it to generate the OG image and to screenshot this site at phone and desktop widths.',
         $tool_body, 'https://playwright.dev', 'testing,screenshots,automation', null, null, 0],
        ['guide', 'how-to-build-a-double-opt-in-newsletter-signup-in-plain-php',
         'How to build a double opt-in newsletter signup in plain PHP',
         'Confirmation emails, tokens, and not signing up strangers, with no framework and no third-party service. (Draft outline.)',
         $guide_body, null, 'php,email,newsletter', null, 'intermediate', 0],
    ];
}

// ── Database ──────────────────────────────────────────────────────────────

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

    $form_errors = [];
    $form = null; // sticky values when validation fails

    // ── POST actions ──────────────────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $sent = $_POST['csrf'] ?? '';
        if (!is_string($sent) || !hash_equals($csrf, $sent)) {
            http_response_code(403);
            die('Invalid or expired form token. Go back, reload the page and try again.');
        }
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        $pid = (int)($_POST['id'] ?? 0);

        if ($action === 'delete' && $pid > 0) {
            $st = $pdo->prepare("DELETE FROM library_items WHERE id = :id");
            $st->execute([':id' => $pid]);
            flash($st->rowCount() ? 'ok' : 'err', $st->rowCount() ? 'Entry deleted.' : 'That entry no longer exists.');
            go();
        }

        if ($action === 'toggle' && $pid > 0) {
            $st = $pdo->prepare("UPDATE library_items SET published = 1 - published, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
            $st->execute([':id' => $pid]);
            flash('ok', $st->rowCount() ? 'Visibility updated.' : 'That entry no longer exists.');
            go();
        }

        if ($action === 'seed') {
            $n = (int)$pdo->query("SELECT COUNT(*) FROM library_items")->fetchColumn();
            if ($n === 0) {
                $pdo->beginTransaction();
                $ins = $pdo->prepare("INSERT INTO library_items (type, slug, title, summary, body, url, tags, status, difficulty, featured, published)
                                      VALUES (:type, :slug, :title, :summary, :body, :url, :tags, :status, :difficulty, :featured, 0)");
                foreach (starter_entries() as $r) {
                    $ins->execute([':type' => $r[0], ':slug' => $r[1], ':title' => $r[2], ':summary' => $r[3], ':body' => $r[4],
                                   ':url' => $r[5], ':tags' => $r[6], ':status' => $r[7], ':difficulty' => $r[8], ':featured' => $r[9]]);
                }
                $pdo->commit();
                flash('ok', 'Three starter drafts loaded. Nothing is public until you publish it.');
            } else {
                flash('err', 'The library is not empty, so nothing was loaded.');
            }
            go();
        }

        if ($action === 'save') {
            $type = is_string($_POST['type'] ?? null) && isset(L_TYPES[$_POST['type']]) ? $_POST['type'] : '';
            $title = trim(preg_replace('/\s+/u', ' ', strip_tags((string)($_POST['title'] ?? ''))));
            $slug_in = trim((string)($_POST['slug'] ?? ''));
            $summary = trim(preg_replace('/\s+/u', ' ', strip_tags((string)($_POST['summary'] ?? ''))));
            $body = trim((string)($_POST['body'] ?? ''));
            $url_raw = (string)($_POST['url'] ?? '');
            $tags = normalize_tags($_POST['tags'] ?? '');
            $status = is_string($_POST['status'] ?? null) && isset(L_STATUS[$_POST['status']]) ? $_POST['status'] : '';
            $diff = is_string($_POST['difficulty'] ?? null) && isset(L_DIFF[$_POST['difficulty']]) ? $_POST['difficulty'] : '';
            $featured = !empty($_POST['featured']) ? 1 : 0;
            $published = !empty($_POST['published']) ? 1 : 0;

            if ($type === '') $form_errors[] = 'Choose a type.';
            if ($title === '') $form_errors[] = 'A title is required.';
            if (mb_strlen($title) > L_MAX['title']) $form_errors[] = 'Title is over ' . L_MAX['title'] . ' characters.';
            if (mb_strlen($summary) > L_MAX['summary']) $form_errors[] = 'Summary is over ' . L_MAX['summary'] . ' characters.';
            if (strlen($body) > L_MAX['body']) $form_errors[] = 'Body is too long.';
            if (($type === 'guide' || $type === 'attempt') && $body === '') $form_errors[] = 'Guides and attempts need a body.';

            $url = null;
            if ($type === 'tool') {
                $u = clean_url($url_raw);
                if ($u === false) $form_errors[] = 'URL must be a valid http(s) link under ' . L_MAX['url'] . ' characters.';
                elseif ($u === '') $form_errors[] = 'Tools need a URL.';
                else $url = $u;
            }
            if ($type === 'attempt' && $status === '') $form_errors[] = 'Pick an outcome for the attempt.';
            if ($type !== 'attempt') $status = '';
            if ($type !== 'guide') $diff = '';

            // Slug: sanitise, auto-generate when blank, unique check
            $slug = slugify($slug_in !== '' ? $slug_in : $title);
            if ($slug === '' && $title !== '') $slug = 'entry-' . substr(bin2hex(random_bytes(3)), 0, 6);
            if ($slug !== '') {
                $chk = $pdo->prepare("SELECT id FROM library_items WHERE slug = :s AND id <> :id LIMIT 1");
                $chk->execute([':s' => $slug, ':id' => $pid]);
                if ($chk->fetchColumn()) {
                    if ($slug_in === '') {
                        $base = substr($slug, 0, L_MAX['slug'] - 6);
                        for ($i = 2; $i < 100; $i++) {
                            $chk->execute([':s' => $base . '-' . $i, ':id' => $pid]);
                            if (!$chk->fetchColumn()) { $slug = $base . '-' . $i; break; }
                        }
                    } else {
                        $form_errors[] = 'That slug is already used by another entry.';
                    }
                }
            }

            if (!$form_errors) {
                $params = [':type' => $type, ':slug' => $slug, ':title' => $title, ':summary' => $summary, ':body' => $body,
                           ':url' => $url, ':tags' => $tags, ':status' => $status !== '' ? $status : null,
                           ':difficulty' => $diff !== '' ? $diff : null, ':featured' => $featured, ':published' => $published];
                try {
                    if ($pid > 0) {
                        $params[':id'] = $pid;
                        $st = $pdo->prepare("UPDATE library_items SET type=:type, slug=:slug, title=:title, summary=:summary, body=:body, url=:url,
                            tags=:tags, status=:status, difficulty=:difficulty, featured=:featured, published=:published, updated_at=CURRENT_TIMESTAMP WHERE id=:id");
                        $st->execute($params);
                        $new_id = $pid;
                    } else {
                        $st = $pdo->prepare("INSERT INTO library_items (type, slug, title, summary, body, url, tags, status, difficulty, featured, published)
                            VALUES (:type, :slug, :title, :summary, :body, :url, :tags, :status, :difficulty, :featured, :published)");
                        $st->execute($params);
                        $new_id = (int)$pdo->lastInsertId();
                    }
                    flash('ok', ($pid > 0 ? 'Saved.' : 'Entry created.') . ($published ? ' It is live.' : ' It is a draft.'));
                    go('?edit=' . $new_id);
                } catch (PDOException $ex) {
                    if ($ex->getCode() === '23000') { $form_errors[] = 'That slug is already used by another entry.'; }
                    else { throw $ex; }
                }
            }
            $form = ['id' => $pid, 'type' => $type ?: 'guide', 'slug' => $slug_in, 'title' => $title, 'summary' => $summary, 'body' => $body,
                     'url' => trim($url_raw), 'tags' => $tags, 'status' => $status, 'difficulty' => $diff, 'featured' => $featured, 'published' => $published];
        }
    }

    // ── Which view? ───────────────────────────────────────────────────────
    $view = 'list';
    if ($form !== null) {
        $view = 'form';
    } elseif (isset($_GET['edit']) && ctype_digit((string)$_GET['edit'])) {
        $st = $pdo->prepare("SELECT * FROM library_items WHERE id = :id LIMIT 1");
        $st->execute([':id' => (int)$_GET['edit']]);
        $form = $st->fetch(PDO::FETCH_ASSOC);
        if ($form) { $view = 'form'; } else { flash('err', 'That entry does not exist.'); go(); }
    } elseif (isset($_GET['new'])) {
        $nt = (is_string($_GET['type'] ?? null) && isset(L_TYPES[$_GET['type']])) ? $_GET['type'] : 'guide';
        $form = ['id' => 0, 'type' => $nt, 'slug' => '', 'title' => '', 'summary' => '', 'body' => '', 'url' => '', 'tags' => '',
                 'status' => $nt === 'attempt' ? 'ongoing' : '', 'difficulty' => '', 'featured' => 0, 'published' => 0];
        $view = 'form';
    }

    $f_type = (is_string($_GET['type'] ?? null) && isset(L_TYPES[$_GET['type']])) ? $_GET['type'] : '';
    $f_state = (is_string($_GET['state'] ?? null) && in_array($_GET['state'], ['live', 'draft'], true)) ? $_GET['state'] : '';
    $items = [];
    $n_all = 0; $n_live = 0;
    if ($view === 'list') {
        $where = []; $params = [];
        if ($f_type !== '') { $where[] = 'type = :t'; $params[':t'] = $f_type; }
        if ($f_state !== '') { $where[] = 'published = :p'; $params[':p'] = $f_state === 'live' ? 1 : 0; }
        $st = $pdo->prepare("SELECT id, type, slug, title, status, difficulty, featured, published, created_at, updated_at FROM library_items"
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY created_at DESC, id DESC");
        $st->execute($params);
        $items = $st->fetchAll(PDO::FETCH_ASSOC);
        $n_all = (int)$pdo->query("SELECT COUNT(*) FROM library_items")->fetchColumn();
        $n_live = (int)$pdo->query("SELECT COUNT(*) FROM library_items WHERE published = 1")->fetchColumn();
    }
} catch (PDOException $ex) {
    error_log('admin/library.php: ' . $ex->getMessage());
    if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
    http_response_code(500);
    die('Database error. Check the server error log.');
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function list_url($over = []) {
    global $f_type, $f_state;
    $p = array_filter(array_merge(['type' => $f_type, 'state' => $f_state], $over), function ($v) { return $v !== ''; });
    return '/admin/library.php' . ($p ? '?' . http_build_query($p) : '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>Library — FRX Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,wght@0,400;0,500;0,700;1,400&display=swap">
  <link rel="stylesheet" href="/styles.css">
  <style>
    .dash-wrap { max-width: 780px; }
    .al-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.8rem; margin: 0.4rem 0 1.2rem; }
    .al-filters { display: flex; flex-wrap: wrap; gap: 0.4rem; margin-bottom: 1.2rem; }
    .al-filters a { display: inline-flex; align-items: center; min-height: 40px; padding: 0 0.9rem; border: 1px solid var(--border); border-radius: 99px; font-size: 0.78rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: var(--fg-muted); }
    .al-filters a:hover { color: var(--accent); border-color: var(--accent); }
    .al-filters a[aria-current="true"] { background: var(--accent); color: var(--acc-ink); border-color: var(--accent); }
    .al-flash { padding: 0.7rem 1rem; border-radius: 4px; font-size: 0.9rem; margin-bottom: 1.2rem; border: 1px solid; }
    .al-flash--ok { color: var(--accent); border-color: rgba(57,255,20,0.4); background: rgba(57,255,20,0.06); }
    .al-flash--err { color: var(--danger); border-color: rgba(255,107,91,0.5); background: rgba(255,107,91,0.07); }
    .al-errors { margin: 0 0 1.2rem; padding: 0.8rem 1rem 0.8rem 2rem; border: 1px solid rgba(255,107,91,0.5); border-radius: 4px; color: var(--danger); font-size: 0.9rem; background: rgba(255,107,91,0.07); }
    .al-list { list-style: none; border-top: 1px solid var(--border); }
    .al-row { display: grid; grid-template-columns: 1fr auto; gap: 0.5rem 1rem; align-items: center; padding: 0.9rem 0; border-bottom: 1px solid var(--border); }
    .al-title { font-weight: 700; font-size: 1rem; overflow-wrap: anywhere; }
    .al-title a:hover { color: var(--accent); }
    .al-meta { display: flex; flex-wrap: wrap; gap: 0.3rem 0.7rem; align-items: center; margin-top: 0.3rem; font-size: 0.75rem; color: var(--fg-muted); }
    .al-tag { font-size: 0.65rem; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; padding: 2px 8px; border-radius: 99px; border: 1px solid var(--border); }
    .al-tag--live { color: var(--accent); border-color: rgba(57,255,20,0.4); }
    .al-tag--draft { color: #ffd24a; border-color: rgba(255,210,74,0.5); }
    .al-tag--feat { color: var(--fg); }
    .al-actions { display: flex; flex-wrap: wrap; gap: 0.2rem 0.9rem; justify-content: flex-end; align-items: center; }
    .al-actions form { display: inline; }
    .al-link { display: inline-flex; align-items: center; min-height: 44px; background: none; border: 0; padding: 0; font: 700 0.75rem 'DM Sans', sans-serif; letter-spacing: 0.08em; text-transform: uppercase; color: var(--fg-muted); cursor: pointer; }
    .al-link:hover { color: var(--accent); }
    .al-link--danger { color: var(--danger); }
    .al-link--danger:hover { color: var(--danger); text-decoration: underline; }
    .al-empty { padding: 2rem 1.4rem; border: 1px dashed var(--border); border-radius: 4px; background: var(--bg-card); }
    .al-empty p { color: var(--fg-muted); margin-bottom: 1rem; max-width: 52ch; }
    .al-form { display: grid; gap: 1.2rem; }
    .al-field label, .al-legend { display: block; font-size: 0.7rem; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; color: var(--fg-muted); margin-bottom: 0.4rem; }
    .al-hint { font-size: 0.78rem; color: var(--fg-muted); margin-top: 0.35rem; }
    .al-in { width: 100%; min-height: 44px; background: var(--bg-card); border: 1px solid var(--border); color: var(--fg); font: 400 0.95rem 'DM Sans', sans-serif; padding: 0.6rem 0.8rem; border-radius: 4px; }
    .al-in:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(57,255,20,0.18); }
    textarea.al-in { font-family: ui-monospace, 'SF Mono', Menlo, Consolas, monospace; font-size: 0.85rem; line-height: 1.6; min-height: 340px; resize: vertical; }
    textarea.al-sum { font-family: 'DM Sans', sans-serif; font-size: 0.95rem; min-height: 84px; }
    .al-radios { display: flex; flex-wrap: wrap; gap: 0.5rem; }
    .al-radios label { display: inline-flex; align-items: center; gap: 0.5rem; min-height: 44px; padding: 0 1rem; margin: 0; border: 1px solid var(--border); border-radius: 4px; background: var(--bg-card); color: var(--fg); font-size: 0.85rem; letter-spacing: 0.04em; text-transform: none; font-weight: 500; cursor: pointer; }
    .al-radios input { accent-color: #39ff14; width: 18px; height: 18px; }
    .al-radios label:has(input:checked) { border-color: var(--accent); }
    .al-checks { display: flex; flex-wrap: wrap; gap: 0.5rem 1.4rem; }
    .al-checks label { display: inline-flex; align-items: center; gap: 0.6rem; min-height: 44px; margin: 0; text-transform: none; letter-spacing: 0; font-size: 0.92rem; font-weight: 500; color: var(--fg); cursor: pointer; }
    .al-checks input { accent-color: #39ff14; width: 20px; height: 20px; }
    .al-slugrow { display: flex; align-items: center; gap: 0.4rem; }
    .al-slugpre { color: var(--fg-muted); font-size: 0.9rem; white-space: nowrap; }
    .al-bar { display: flex; flex-wrap: wrap; gap: 0.8rem; align-items: center; padding-top: 0.4rem; }
    .al-seed { margin-top: 1.6rem; padding: 1.2rem; border: 1px dashed var(--border); border-radius: 4px; background: var(--bg-card); }
    .al-seed h2 { font-family: 'Bebas Neue', sans-serif; font-weight: 400; letter-spacing: 0.04em; font-size: 1.5rem; margin-bottom: 0.3rem; }
    .al-seed p { color: var(--fg-muted); font-size: 0.9rem; margin-bottom: 0.9rem; max-width: 56ch; }
    [hidden] { display: none !important; }
    a:focus-visible, button:focus-visible, input:focus-visible, textarea:focus-visible, select:focus-visible { outline: 2px solid var(--accent); outline-offset: 3px; }
    @media (max-width: 480px) { .nav-links { flex-wrap: wrap; gap: 0 12px; } .nav-links a { font-size: 0.64rem; } }
    @media (max-width: 600px) {
      .al-row { grid-template-columns: 1fr; }
      .al-actions { justify-content: flex-start; }
      .al-slugrow { flex-direction: column; align-items: stretch; }
    }
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
          <li><a href="/admin/library.php" aria-current="page">Library</a></li>
          <li><a href="/admin/logs.php">Logs</a></li>
          <li><a href="/admin/change-password.php">Password</a></li>
          <li><a href="/admin/?logout=1">Log out</a></li>
        </ul>
      </nav>
    </div>
  </header>

  <main>
    <section class="section" style="border-bottom:none;">
      <div class="inner dash-wrap">
        <p class="section-label">Admin</p>

        <?php if ($flash): ?>
          <p class="al-flash al-flash--<?= $flash[0] === 'ok' ? 'ok' : 'err' ?>" role="status"><?= e($flash[1]) ?></p>
        <?php endif; ?>

<?php if ($view === 'list'): ?>

        <h1 class="section-heading">Library</h1>
        <div class="al-head">
          <p class="text-muted" style="font-size:0.9rem;"><?= $n_all ?> <?= $n_all === 1 ? 'entry' : 'entries' ?>, <?= $n_live ?> live</p>
          <a class="btn btn--small" href="/admin/library.php?new=1">New entry</a>
        </div>

        <nav class="al-filters" aria-label="Filter entries">
          <a href="<?= e(list_url(['type' => ''])) ?>"<?= $f_type === '' ? ' aria-current="true"' : '' ?>>All types</a>
          <?php foreach (L_TYPES as $k => $lbl): ?>
          <a href="<?= e(list_url(['type' => $k])) ?>"<?= $f_type === $k ? ' aria-current="true"' : '' ?>><?= e($lbl) ?>s</a>
          <?php endforeach; ?>
          <a href="<?= e(list_url(['state' => $f_state === 'draft' ? '' : 'draft'])) ?>"<?= $f_state === 'draft' ? ' aria-current="true"' : '' ?>>Drafts</a>
          <a href="<?= e(list_url(['state' => $f_state === 'live' ? '' : 'live'])) ?>"<?= $f_state === 'live' ? ' aria-current="true"' : '' ?>>Live</a>
        </nav>

        <?php if (!$items): ?>
          <div class="al-empty">
            <p><?= $n_all === 0 ? 'The shelves are bare.' : 'No entries match those filters.' ?></p>
            <?php if ($n_all === 0): ?>
              <a class="btn btn--small" href="/admin/library.php?new=1">Write the first one</a>
            <?php else: ?>
              <a class="btn btn--small btn--ghost" href="/admin/library.php">Clear filters</a>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <ul class="al-list">
            <?php foreach ($items as $r): $ts = strtotime($r['created_at']); ?>
            <li class="al-row">
              <div>
                <p class="al-title"><a href="/admin/library.php?edit=<?= (int)$r['id'] ?>"><?= e($r['title']) ?></a></p>
                <p class="al-meta">
                  <span class="al-tag"><?= e(L_TYPES[$r['type']] ?? $r['type']) ?></span>
                  <span class="al-tag <?= $r['published'] ? 'al-tag--live' : 'al-tag--draft' ?>"><?= $r['published'] ? 'Live' : 'Draft' ?></span>
                  <?php if ($r['featured']): ?><span class="al-tag al-tag--feat">Start here</span><?php endif; ?>
                  <?php if ($r['status']): ?><span><?= e(L_STATUS[$r['status']] ?? '') ?></span><?php endif; ?>
                  <?php if ($r['difficulty']): ?><span><?= e(L_DIFF[$r['difficulty']] ?? '') ?></span><?php endif; ?>
                  <span><?= e(date('M j, Y', $ts)) ?></span>
                </p>
              </div>
              <div class="al-actions">
                <a class="al-link" href="/admin/library.php?edit=<?= (int)$r['id'] ?>">Edit</a>
                <a class="al-link" href="/library.php?slug=<?= e(urlencode($r['slug'])) ?>&amp;preview=1" target="_blank" rel="noopener"><?= $r['published'] ? 'View' : 'Preview' ?></a>
                <form method="POST">
                  <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button type="submit" class="al-link"><?= $r['published'] ? 'Unpublish' : 'Publish' ?></button>
                </form>
                <form method="POST" data-confirm="Delete “<?= e($r['title']) ?>”? This cannot be undone.">
                  <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button type="submit" class="al-link al-link--danger">Delete</button>
                </form>
              </div>
            </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <?php if ($n_all === 0): ?>
        <div class="al-seed">
          <h2>Load starter entries</h2>
          <p>Adds three drafts to get you going: an attempt log (the newsletter platform story), a tool (Playwright) and a guide outline with TODO markers. They are saved unpublished, so nothing goes live until you read it and publish it.</p>
          <form method="POST">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="seed">
            <button type="submit" class="btn btn--small btn--ghost">Load starter entries</button>
          </form>
        </div>
        <?php endif; ?>

<?php else: $is_new = (int)$form['id'] === 0; ?>

        <a class="al-link" href="/admin/library.php">← All entries</a>
        <h1 class="section-heading"><?= $is_new ? 'New entry' : 'Edit entry' ?></h1>

        <?php if ($form_errors): ?>
          <ul class="al-errors" role="alert">
            <?php foreach ($form_errors as $m): ?><li><?= e($m) ?></li><?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <form class="al-form" method="POST" action="/admin/library.php" id="entry-form" novalidate>
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">

          <fieldset style="border:0;min-width:0;">
            <legend class="al-legend">Type</legend>
            <div class="al-radios">
              <?php foreach (L_TYPES as $k => $lbl): ?>
              <label><input type="radio" name="type" value="<?= e($k) ?>"<?= $form['type'] === $k ? ' checked' : '' ?>> <?= e($lbl) ?></label>
              <?php endforeach; ?>
            </div>
          </fieldset>

          <div class="al-field">
            <label for="f-title">Title</label>
            <input class="al-in" type="text" id="f-title" name="title" maxlength="200" required value="<?= e($form['title']) ?>">
          </div>

          <div class="al-field">
            <label for="f-slug">Slug</label>
            <div class="al-slugrow">
              <span class="al-slugpre">/library/</span>
              <input class="al-in" type="text" id="f-slug" name="slug" maxlength="120" pattern="[a-z0-9\-]*" value="<?= e($form['slug']) ?>" autocomplete="off">
            </div>
            <p class="al-hint">Lowercase letters, numbers and dashes. Leave blank to generate it from the title.</p>
          </div>

          <div class="al-field">
            <label for="f-summary">Summary</label>
            <textarea class="al-in al-sum" id="f-summary" name="summary" maxlength="400"><?= e($form['summary']) ?></textarea>
            <p class="al-hint">One or two sentences. Shown on cards, in search results and link previews. Plain text.</p>
          </div>

          <div class="al-field" data-for="tool">
            <label for="f-url">URL</label>
            <input class="al-in" type="url" id="f-url" name="url" maxlength="500" placeholder="https://" value="<?= e($form['url']) ?>">
            <p class="al-hint">The external link for this tool. http or https only.</p>
          </div>

          <div class="al-field" data-for="attempt">
            <span class="al-legend" id="lbl-status">Outcome</span>
            <div class="al-radios" role="radiogroup" aria-labelledby="lbl-status">
              <?php foreach (L_STATUS as $k => $lbl): ?>
              <label><input type="radio" name="status" value="<?= e($k) ?>"<?= ($form['status'] ?? '') === $k ? ' checked' : '' ?>> <?= e($lbl) ?></label>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="al-field" data-for="guide">
            <label for="f-diff">Difficulty</label>
            <select class="al-in" id="f-diff" name="difficulty">
              <option value="">Not set</option>
              <?php foreach (L_DIFF as $k => $lbl): ?>
              <option value="<?= e($k) ?>"<?= ($form['difficulty'] ?? '') === $k ? ' selected' : '' ?>><?= e($lbl) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="al-field">
            <label for="f-body">Body (HTML)</label>
            <textarea class="al-in" id="f-body" name="body" spellcheck="true"><?= e($form['body']) ?></textarea>
            <p class="al-hint">Trusted HTML, rendered as-is. Use h2/h3, p, ul, pre/code. Skip the h1; the title is added for you. Optional for tools.</p>
          </div>

          <div class="al-field">
            <label for="f-tags">Tags</label>
            <input class="al-in" type="text" id="f-tags" name="tags" maxlength="255" placeholder="php, email, newsletter" value="<?= e($form['tags']) ?>" autocomplete="off">
            <p class="al-hint">Comma separated. Lowercased and cleaned on save. Up to 8.</p>
          </div>

          <div class="al-checks">
            <label><input type="checkbox" name="featured" value="1"<?= !empty($form['featured']) ? ' checked' : '' ?>> Show in “Start here”</label>
            <label><input type="checkbox" name="published" value="1"<?= !empty($form['published']) ? ' checked' : '' ?>> Published</label>
          </div>

          <div class="al-bar">
            <button type="submit" class="btn">Save</button>
            <?php if (!$is_new && !empty($form['slug'])): ?>
              <a class="al-link" href="/library.php?slug=<?= e(urlencode($form['slug'])) ?>&amp;preview=1" target="_blank" rel="noopener">Preview</a>
            <?php endif; ?>
            <a class="al-link" href="/admin/library.php">Cancel</a>
          </div>
        </form>

        <?php if (!$is_new): ?>
        <form method="POST" data-confirm="Delete this entry? This cannot be undone." style="margin-top:2rem;padding-top:1rem;border-top:1px solid var(--border);">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
          <button type="submit" class="al-link al-link--danger">Delete this entry</button>
        </form>
        <?php endif; ?>

<?php endif; ?>

      </div>
    </section>
  </main>

  <footer class="site-footer">
    <div class="inner">
      <p>&copy; 2026 Failosaurus Rex</p>
    </div>
  </footer>

  <script>
    (function () {
      // Confirm before any destructive form
      document.addEventListener('submit', function (ev) {
        var msg = ev.target.getAttribute && ev.target.getAttribute('data-confirm');
        if (msg && !window.confirm(msg)) ev.preventDefault();
      });

      var form = document.getElementById('entry-form');
      if (!form) return;

      // Show only the fields relevant to the chosen type
      var groups = form.querySelectorAll('[data-for]');
      var radios = form.querySelectorAll('input[name="type"]');
      function sync() {
        var t = (form.querySelector('input[name="type"]:checked') || {}).value;
        groups.forEach(function (g) { g.hidden = g.getAttribute('data-for') !== t; });
      }
      radios.forEach(function (r) { r.addEventListener('change', sync); });
      sync();

      // Slug follows the title until it is edited by hand
      var title = document.getElementById('f-title');
      var slug = document.getElementById('f-slug');
      function slugify(s) {
        return s.toLowerCase().normalize('NFKD').replace(/[̀-ͯ]/g, '')
          .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 120).replace(/-+$/, '');
      }
      var manual = slug.value !== '';
      slug.addEventListener('input', function () { manual = slug.value !== ''; });
      title.addEventListener('input', function () { if (!manual) slug.value = slugify(title.value); });
      slug.addEventListener('blur', function () { if (slug.value) slug.value = slugify(slug.value); });

      // Tidy tags
      var tags = document.getElementById('f-tags');
      tags.addEventListener('blur', function () {
        var seen = {}, out = [];
        tags.value.toLowerCase().split(',').forEach(function (t) {
          t = t.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 30);
          if (t && !seen[t]) { seen[t] = 1; out.push(t); }
        });
        tags.value = out.slice(0, 8).join(', ');
      });
    })();
  </script>

</body>
</html>
