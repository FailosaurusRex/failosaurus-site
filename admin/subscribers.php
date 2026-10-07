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

    $total = (int) $pdo->query("SELECT COUNT(*) FROM subscribers")->fetchColumn();

    $today = (int) $pdo->query("SELECT COUNT(*) FROM subscribers WHERE DATE(created_at) = CURDATE()")->fetchColumn();
    $week  = (int) $pdo->query("SELECT COUNT(*) FROM subscribers WHERE created_at >= NOW() - INTERVAL 7 DAY")->fetchColumn();
    $month = (int) $pdo->query("SELECT COUNT(*) FROM subscribers WHERE created_at >= NOW() - INTERVAL 30 DAY")->fetchColumn();

    // Daily signups for the last 30 days (for the sparkline)
    $daily = $pdo->query("
        SELECT DATE(created_at) AS day, COUNT(*) AS n
        FROM subscribers
        WHERE created_at >= NOW() - INTERVAL 30 DAY
        GROUP BY day
        ORDER BY day ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Recent signups
    $recent = $pdo->query("
        SELECT email, created_at
        FROM subscribers
        ORDER BY created_at DESC
        LIMIT 25
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
    .stat-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin: 1.5rem 0 2rem; }
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
        <a href="/" class="nav-logo">FRX</a>
        <ul class="nav-links">
          <li><a href="/admin/">Compose</a></li>
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
        </div>

        <div class="chart-wrap">
          <div class="chart-title">Daily signups — last 30 days</div>
          <canvas id="chart" height="120"></canvas>
        </div>

        <table class="sub-table">
          <thead>
            <tr>
              <th>Email</th>
              <th>Signed up</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recent as $r): ?>
            <tr>
              <td><?= htmlspecialchars($r['email']) ?></td>
              <td><?= date('M j, Y g:ia', strtotime($r['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php if ($total > 25): ?>
          <p style="font-size:0.8rem;color:var(--fg-muted);margin-top:0.8rem;">Showing 25 most recent of <?= number_format($total) ?> total.</p>
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
  </script>

</body>
</html>
