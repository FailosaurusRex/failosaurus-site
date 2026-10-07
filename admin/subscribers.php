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

    // Ensure double opt-in columns exist (safe on repeated runs)
    $pdo->exec("ALTER TABLE subscribers
        ADD COLUMN IF NOT EXISTS confirm_token CHAR(64) NOT NULL DEFAULT '',
        ADD COLUMN IF NOT EXISTS confirmed TINYINT(1) NOT NULL DEFAULT 0");
    // Treat legacy rows (signed up before double opt-in) as confirmed
    $pdo->exec("UPDATE subscribers SET confirmed = 1 WHERE confirmed = 0 AND confirm_token = ''");

    $total   = (int) $pdo->query("SELECT COUNT(*) FROM subscribers WHERE confirmed = 1")->fetchColumn();
    $pending = (int) $pdo->query("SELECT COUNT(*) FROM subscribers WHERE confirmed = 0")->fetchColumn();

    $today = (int) $pdo->query("SELECT COUNT(*) FROM subscribers WHERE confirmed = 1 AND DATE(created_at) = CURDATE()")->fetchColumn();
    $week  = (int) $pdo->query("SELECT COUNT(*) FROM subscribers WHERE confirmed = 1 AND created_at >= NOW() - INTERVAL 7 DAY")->fetchColumn();
    $month = (int) $pdo->query("SELECT COUNT(*) FROM subscribers WHERE confirmed = 1 AND created_at >= NOW() - INTERVAL 30 DAY")->fetchColumn();

    // Daily confirmed signups for the last 30 days (for the sparkline)
    $daily = $pdo->query("
        SELECT DATE(created_at) AS day, COUNT(*) AS n
        FROM subscribers
        WHERE confirmed = 1 AND created_at >= NOW() - INTERVAL 30 DAY
        GROUP BY day
        ORDER BY day ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Handle delete
    $delete_msg = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['email']) && $_POST['action'] === 'delete') {
        $del = $pdo->prepare("DELETE FROM subscribers WHERE email = :email");
        $del->execute([':email' => $_POST['email']]);
        $delete_msg = $del->rowCount() ? 'Subscriber removed.' : 'Not found.';
        // Refresh counts
        $total   = (int) $pdo->query("SELECT COUNT(*) FROM subscribers WHERE confirmed = 1")->fetchColumn();
        $pending = (int) $pdo->query("SELECT COUNT(*) FROM subscribers WHERE confirmed = 0")->fetchColumn();
    }

    // All confirmed subscribers (for the management table)
    $all_subs = $pdo->query("
        SELECT email, created_at
        FROM subscribers
        WHERE confirmed = 1
        ORDER BY created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die('Database error: ' . htmlspecialchars($e->getMessage()));
}

// Build chart data: fill in zeros for days with no signups
$chart_data = [];
for ($i = 29; $i >= 0; $i--) {
    $chart_data[date('Y-m-d', strtotime("-{$i} days"))] = 0;
}
foreach ($daily as $row) {
    $chart_data[$row['day']] = (int) $row['n'];
}
$chart_json = json_encode(array_values($chart_data));
$chart_labels = json_encode(array_map(fn($d) => date('M j', strtotime($d)), array_keys($chart_data)));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Subscribers — FRX Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,wght@0,400;0,500;0,700;1,400&display=swap">
  <link rel="stylesheet" href="/styles.css">
  <style>
    .dash-wrap { max-width: 780px; }
    .stat-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 1rem; margin: 1.5rem 0 2rem; }
    .stat-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 4px; padding: 1.1rem 1.2rem; }
    .stat-label { font-size: 0.7rem; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; color: var(--fg-muted); margin-bottom: 0.4rem; }
    .stat-value { font-family: 'Bebas Neue', sans-serif; font-size: 2.4rem; color: var(--accent); letter-spacing: 0.04em; line-height: 1; }
    .chart-wrap { background: var(--bg-card); border: 1px solid var(--border); border-radius: 4px; padding: 1.2rem 1.4rem; margin-bottom: 2rem; }
    .chart-title { font-size: 0.7rem; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; color: var(--fg-muted); margin-bottom: 1rem; }
    canvas { display: block; width: 100%; height: 120px; }
    .sub-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
    .sub-table th { text-align: left; font-size: 0.7rem; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; color: var(--fg-muted); padding: 0 0 0.6rem; border-bottom: 1px solid var(--border); }
    .sub-table td { padding: 0.6rem 0; border-bottom: 1px solid var(--border); color: var(--fg-muted); }
    .sub-table td:first-child { color: var(--fg); font-weight: 500; }
    @media (max-width: 600px) { .stat-grid { grid-template-columns: repeat(2, 1fr); } }
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
      <div class="inner dash-wrap">
        <p class="section-label">Admin</p>
        <h1 class="section-heading">Subscribers</h1>

        <div class="stat-grid">
          <div class="stat-card">
            <div class="stat-label">Total</div>
            <div class="stat-value"><?= number_format($total) ?></div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Today</div>
            <div class="stat-value"><?= number_format($today) ?></div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Last 7 days</div>
            <div class="stat-value"><?= number_format($week) ?></div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Last 30 days</div>
            <div class="stat-value"><?= number_format($month) ?></div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Pending confirm</div>
            <div class="stat-value" style="color:var(--fg-muted);"><?= number_format($pending) ?></div>
          </div>
        </div>

        <div class="chart-wrap">
          <div class="chart-title">Daily signups — last 30 days</div>
          <canvas id="chart" height="120"></canvas>
        </div>

        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.8rem;margin-bottom:1rem;">
          <input type="search" id="sub-search" placeholder="Filter by email…"
            style="background:var(--bg-card);border:1px solid var(--border);color:var(--fg);font-family:'DM Sans',sans-serif;font-size:0.9rem;padding:0.5rem 0.8rem;border-radius:4px;outline:none;width:260px;">
          <a href="/admin/export-subscribers.php" class="btn" style="font-size:0.8rem;padding:0.45rem 1rem;">Export CSV</a>
        </div>

        <?php if ($delete_msg): ?>
          <p style="font-size:0.85rem;color:var(--accent);margin-bottom:0.8rem;"><?= htmlspecialchars($delete_msg) ?></p>
        <?php endif; ?>

        <table class="sub-table" id="sub-table">
          <thead>
            <tr>
              <th>Email</th>
              <th>Signed up</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($all_subs as $r): ?>
            <tr>
              <td><?= htmlspecialchars($r['email']) ?></td>
              <td><?= date('M j, Y g:ia', strtotime($r['created_at'])) ?></td>
              <td style="text-align:right;">
                <form method="POST" style="display:inline;" onsubmit="return confirm('Remove <?= htmlspecialchars(addslashes($r['email'])) ?>?');">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="email" value="<?= htmlspecialchars($r['email']) ?>">
                  <button type="submit" style="background:none;border:none;cursor:pointer;font-size:0.75rem;color:#ff5040;font-family:'DM Sans',sans-serif;padding:0;">Remove</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <p style="font-size:0.8rem;color:var(--fg-muted);margin-top:0.8rem;" id="sub-count-label"><?= number_format($total) ?> confirmed subscriber<?= $total !== 1 ? 's' : '' ?></p>

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
      const data   = <?= $chart_json ?>;
      const labels = <?= $chart_labels ?>;
      const canvas = document.getElementById('chart');
      const ctx    = canvas.getContext('2d');
      const W = canvas.offsetWidth;
      const H = 120;
      canvas.width  = W * devicePixelRatio;
      canvas.height = H * devicePixelRatio;
      ctx.scale(devicePixelRatio, devicePixelRatio);

      const max   = Math.max(...data, 1);
      const pad   = { top: 8, right: 8, bottom: 28, left: 28 };
      const gw    = W - pad.left - pad.right;
      const gh    = H - pad.top  - pad.bottom;
      const step  = gw / (data.length - 1);

      // Grid lines
      ctx.strokeStyle = '#2c1f16';
      ctx.lineWidth   = 1;
      [0, 0.5, 1].forEach(t => {
        const y = pad.top + gh * (1 - t);
        ctx.beginPath(); ctx.moveTo(pad.left, y); ctx.lineTo(pad.left + gw, y); ctx.stroke();
        if (t > 0) {
          ctx.fillStyle = '#4a3f37';
          ctx.font      = '10px Arial';
          ctx.textAlign = 'right';
          ctx.fillText(Math.round(max * t), pad.left - 4, y + 4);
        }
      });

      // Area fill
      ctx.beginPath();
      data.forEach((v, i) => {
        const x = pad.left + i * step;
        const y = pad.top + gh * (1 - v / max);
        i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
      });
      const grad = ctx.createLinearGradient(0, pad.top, 0, pad.top + gh);
      grad.addColorStop(0,   'rgba(57,255,20,0.25)');
      grad.addColorStop(1,   'rgba(57,255,20,0)');
      ctx.lineTo(pad.left + (data.length - 1) * step, pad.top + gh);
      ctx.lineTo(pad.left, pad.top + gh);
      ctx.closePath();
      ctx.fillStyle = grad;
      ctx.fill();

      // Line
      ctx.beginPath();
      data.forEach((v, i) => {
        const x = pad.left + i * step;
        const y = pad.top + gh * (1 - v / max);
        i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
      });
      ctx.strokeStyle = '#39ff14';
      ctx.lineWidth   = 2;
      ctx.lineJoin    = 'round';
      ctx.stroke();

      // X labels (every 7 days)
      ctx.fillStyle = '#4a3f37';
      ctx.font      = '10px Arial';
      ctx.textAlign = 'center';
      data.forEach((_, i) => {
        if (i % 7 === 0 || i === data.length - 1) {
          ctx.fillText(labels[i], pad.left + i * step, H - 8);
        }
      });
    })();

    // Live search filter
    const searchInput = document.getElementById('sub-search');
    const table       = document.getElementById('sub-table');
    const label       = document.getElementById('sub-count-label');
    const allRows     = Array.from(table.querySelectorAll('tbody tr'));
    const totalCount  = allRows.length;

    searchInput.addEventListener('input', () => {
      const q = searchInput.value.trim().toLowerCase();
      let visible = 0;
      allRows.forEach(row => {
        const email = row.cells[0].textContent.toLowerCase();
        const show  = q === '' || email.includes(q);
        row.style.display = show ? '' : 'none';
        if (show) visible++;
      });
      label.textContent = q
        ? `${visible} of ${totalCount} subscriber${totalCount !== 1 ? 's' : ''} match`
        : `${totalCount} confirmed subscriber${totalCount !== 1 ? 's' : ''}`;
    });
  </script>

</body>
</html>
