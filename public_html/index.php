<?php
require __DIR__ . '/_bootstrap.php';

// Ler snapshot atual escrito pelo coletor (root)
$snapFile = dirname(__DIR__) . '/private/current.json';
$snap     = file_exists($snapFile) ? json_decode(file_get_contents($snapFile), true) : null;

// Fallback se ainda não tem snapshot
$cpuPct     = $snap ? (int)$snap['cpu_percent']  : 0;
$load       = $snap ? $snap['load']               : [0, 0, 0];
$ramUsedMb  = $snap ? $snap['ram_used_mb']        : 0;
$ramTotalMb = $snap ? $snap['ram_total_mb']       : 1;
$ramPct     = $snap ? $snap['ram_pct']            : 0;
$ramUsedGb  = round($ramUsedMb / 1024, 1);
$ramTotalGb = round($ramTotalMb / 1024, 1);
$diskUsed   = $snap ? $snap['disk_used_gb']       : 0;
$diskTotal  = $snap ? $snap['disk_total_gb']      : 1;
$diskFree   = $snap ? $snap['disk_free_gb']       : 0;
$diskPct    = $snap ? $snap['disk_pct']           : 0;
$uptimeSec  = $snap ? (int)$snap['uptime_sec']    : 0;
$collectedAt = $snap ? $snap['collected_at']      : null;

$uptimeDays  = intdiv($uptimeSec, 86400);
$uptimeHours = intdiv($uptimeSec % 86400, 3600);
$uptime = "{$uptimeDays}d {$uptimeHours}h";

$serviceLabels = [
    'nginx'         => 'Nginx',
    'apache2'       => 'Apache',
    'mariadb'       => 'MariaDB',
    'exim4'         => 'Exim',
    'dovecot'       => 'Dovecot',
    'clamav-daemon' => 'ClamAV',
    'spamassassin'  => 'SpamAssassin',
    'hestia'        => 'HestiaCP',
];
$services = [];
if ($snap && isset($snap['services'])) {
    foreach ($serviceLabels as $key => $label) {
        $services[$label] = (bool)($snap['services'][$key] ?? false);
    }
}

// Dados históricos do banco
$hasHistory = false;
$topDisk    = [];
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $hasHistory = (int)$pdo->query("SELECT COUNT(*) FROM system_metrics")->fetchColumn() > 0;
    $topDisk = $pdo->query("
        SELECT d.account, d.disk_mb
        FROM disk_by_account d
        INNER JOIN (SELECT account, MAX(id) AS mid FROM disk_by_account GROUP BY account) m
          ON d.id = m.mid
        ORDER BY d.disk_mb DESC LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

function pctColor(int $pct): string {
    if ($pct >= 85) return '#ef4444';
    if ($pct >= 65) return '#f59e0b';
    return '#22c55e';
}

$loadColor = $load[0] > 10 ? '#ef4444' : ($load[0] > 6 ? '#f59e0b' : '#22c55e');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="60">
<title>Monitor — <?= htmlspecialchars(SITE_NAME) ?></title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    background: #0f172a; color: #e2e8f0; min-height: 100vh;
  }
  .card-link { text-decoration: none; color: inherit; display: block; }
  .card-link .card { transition: border-color .15s, background .15s; }
  .card-link:hover .card { border-color: #38bdf8; background: #1e3a4a; }
  .card-link .card-label::after { content: ' ↗'; font-size:.6rem; color:#38bdf855; }
  .nav-pills { display:flex; gap:10px; margin-bottom:20px; flex-wrap:wrap; }
  .nav-pill { text-decoration:none; background:#1e293b; border:1px solid #334155; border-radius:8px; padding:8px 16px; font-size:.82rem; font-weight:500; color:#94a3b8; transition:all .15s; }
  .nav-pill:hover, .nav-pill.active { background:#334155; color:#e2e8f0; border-color:#475569; }
  header {
    background: #1e293b; border-bottom: 1px solid #334155;
    padding: 14px 24px; display: flex; align-items: center;
    justify-content: space-between; position: sticky; top: 0; z-index: 10;
  }
  header h1 { font-size: 1.1rem; font-weight: 600; color: #f1f5f9; }
  header h1 span { color: #38bdf8; }
  .header-meta { font-size: .72rem; color: #64748b; text-align: right; line-height: 1.6; }
  .container { max-width: 1300px; margin: 0 auto; padding: 24px 20px; }
  .section-title {
    font-size: .68rem; font-weight: 700; letter-spacing: .09em;
    text-transform: uppercase; color: #475569;
    margin-bottom: 12px; margin-top: 28px;
  }
  .section-title:first-child { margin-top: 0; }
  .metrics-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 14px;
  }
  .card {
    background: #1e293b; border: 1px solid #334155;
    border-radius: 10px; padding: 18px 20px;
  }
  .card-label { font-size: .68rem; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; margin-bottom: 8px; }
  .card-value { font-size: 2rem; font-weight: 700; line-height: 1; margin-bottom: 5px; }
  .card-sub   { font-size: .76rem; color: #94a3b8; }
  .bar-track  { height: 4px; background: #334155; border-radius: 3px; margin-top: 10px; }
  .bar-fill   { height: 4px; border-radius: 3px; }
  .services-grid { display: flex; flex-wrap: wrap; gap: 10px; }
  .svc-badge {
    display: flex; align-items: center; gap: 7px;
    background: #1e293b; border: 1px solid #334155;
    border-radius: 7px; padding: 8px 14px; font-size: .82rem; font-weight: 500;
  }
  .svc-dot  { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
  .svc-up   { background: #22c55e; box-shadow: 0 0 5px #22c55e99; }
  .svc-down { background: #ef4444; box-shadow: 0 0 5px #ef444499; }
  .charts-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(420px, 1fr));
    gap: 14px;
  }
  .chart-card {
    background: #1e293b; border: 1px solid #334155;
    border-radius: 10px; padding: 18px 20px;
  }
  .chart-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; }
  .chart-title  { font-size: .82rem; font-weight: 600; color: #cbd5e1; }
  .period-tabs  { display: flex; gap: 4px; }
  .period-btn {
    font-size: .68rem; padding: 3px 9px; border-radius: 4px;
    border: 1px solid #334155; background: transparent;
    color: #64748b; cursor: pointer; transition: all .15s;
  }
  .period-btn.active, .period-btn:hover { background: #334155; color: #e2e8f0; }
  .chart-wrap { position: relative; height: 185px; }
  .alert-bar {
    background: #450a0a; border: 1px solid #b91c1c;
    border-radius: 8px; padding: 10px 16px; font-size: .82rem;
    color: #fca5a5; margin-bottom: 12px;
  }
  .no-snap {
    background: #1e293b; border: 1px solid #334155; border-radius: 10px;
    padding: 20px; color: #475569; font-size: .85rem;
  }
  code { background: #334155; padding: 2px 7px; border-radius: 4px; font-size: .82rem; }
  button.nav-pill { border: 1px solid #334155; cursor: pointer; }
  .disk-panel { display: none; margin-bottom: 8px; }
</style>
</head>
<body>

<header>
  <h1><?= htmlspecialchars(SITE_NAME) ?> <span>Monitor</span></h1>
  <div class="header-meta">
    <?= date('d/m/Y H:i') ?> · auto-refresh 60s
    <?php if ($collectedAt): ?>
      <br>Dados coletados às <?= date('H:i', strtotime($collectedAt)) ?>
    <?php endif; ?>
  </div>
</header>

<div class="container">

  <!-- Navegação -->
  <div class="nav-pills">
    <a href="index.php"      class="nav-pill active">Sistema</a>
    <a href="cpu-detail.php" class="nav-pill">CPU por Conta</a>
    <a href="email.php"      class="nav-pill">E-mail</a>
    <a href="queue.php"      class="nav-pill">Fila</a>
    <a href="uptime.php"     class="nav-pill">Uptime</a>
    <a href="security.php"   class="nav-pill">Segurança</a>
    <a href="alerts-config.php" class="nav-pill">Alertas</a>
    <?php if ($topDisk): ?><button class="nav-pill" onclick="toggleDisk(this)">Disco por Conta</button><?php endif; ?>
  </div>

  <?php if ($topDisk): ?>
  <div class="disk-panel" id="disk-panel">
    <div class="section-title">Disco por conta — top 10</div>
    <div class="metrics-grid" style="grid-template-columns:repeat(auto-fill,minmax(180px,1fr))">
      <?php
      $maxDisk = $topDisk[0]['disk_mb'] ?? 1;
      foreach ($topDisk as $d):
          $gb  = round($d['disk_mb'] / 1024, 1);
          $pct = min(100, round($d['disk_mb'] / $maxDisk * 100));
          $col = $gb > 10 ? '#f59e0b' : '#38bdf8';
      ?>
      <div class="card">
        <div class="card-label"><?= htmlspecialchars($d['account']) ?></div>
        <div class="card-value" style="font-size:1.4rem;color:<?= $col ?>"><?= $gb ?>GB</div>
        <div class="bar-track"><div class="bar-fill" style="width:<?= $pct ?>%;background:<?= $col ?>"></div></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

<?php if (!$snap): ?>
  <div class="no-snap">
    Aguardando primeiro snapshot do coletor.<br>
    Execute manualmente: <code>php -d disable_functions= /root/monitor/collect.php</code>
  </div>
<?php else: ?>

  <?php
  $alerts = [];
  if ($ramPct  >= 85) $alerts[] = "RAM em {$ramPct}% — verifique processos em excesso.";
  if ($diskPct >= 85) $alerts[] = "Disco em {$diskPct}% — limpe logs ou backups antigos.";
  if ($load[0]  > 10) $alerts[] = "Load alto: " . round($load[0], 2) . " (12 CPUs disponíveis).";
  foreach ($services as $name => $up) {
    if (!$up) $alerts[] = "Serviço <strong>" . htmlspecialchars($name) . "</strong> está FORA.";
  }
  foreach ($alerts as $a): ?>
    <div class="alert-bar">⚠ <?= $a ?></div>
  <?php endforeach; ?>

  <div class="section-title">Sistema — ao vivo</div>
  <div class="metrics-grid">

    <a href="cpu-detail.php" class="card-link">
    <div class="card">
      <div class="card-label">CPU</div>
      <div class="card-value" style="color:<?= pctColor($cpuPct) ?>"><?= $cpuPct ?>%</div>
      <div class="card-sub">Load: <?= round($load[0],2) ?> · <?= round($load[1],2) ?> · <?= round($load[2],2) ?></div>
      <div class="bar-track"><div class="bar-fill" style="width:<?= $cpuPct ?>%;background:<?= pctColor($cpuPct) ?>"></div></div>
    </div>
    </a>

    <div class="card">
      <div class="card-label">RAM</div>
      <div class="card-value" style="color:<?= pctColor($ramPct) ?>"><?= $ramUsedGb ?>GB</div>
      <div class="card-sub"><?= $ramPct ?>% de <?= $ramTotalGb ?>GB usados</div>
      <div class="bar-track"><div class="bar-fill" style="width:<?= $ramPct ?>%;background:<?= pctColor($ramPct) ?>"></div></div>
    </div>

    <div class="card">
      <div class="card-label">Disco</div>
      <div class="card-value" style="color:<?= pctColor($diskPct) ?>"><?= $diskUsed ?>GB</div>
      <div class="card-sub"><?= $diskPct ?>% de <?= $diskTotal ?>GB · <?= $diskFree ?>GB livre</div>
      <div class="bar-track"><div class="bar-fill" style="width:<?= $diskPct ?>%;background:<?= pctColor($diskPct) ?>"></div></div>
    </div>

    <div class="card">
      <div class="card-label">Uptime</div>
      <div class="card-value" style="color:#38bdf8"><?= $uptime ?></div>
      <div class="card-sub">12 CPUs · <?= $ramTotalGb ?>GB RAM</div>
    </div>

  </div>

  <div class="section-title">Serviços</div>
  <div class="services-grid">
    <?php foreach ($services as $name => $up): ?>
    <div class="svc-badge">
      <span class="svc-dot <?= $up ? 'svc-up' : 'svc-down' ?>"></span>
      <?= htmlspecialchars($name) ?>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="section-title">Histórico</div>

  <?php if (!$hasHistory): ?>
    <div class="no-snap">Aguardando dados históricos (coletados a cada 5 min via cron).</div>
  <?php else: ?>
  <div class="charts-grid">

    <div class="chart-card">
      <div class="chart-header">
        <span class="chart-title">CPU %</span>
        <div class="period-tabs">
          <button class="period-btn active" onclick="loadChart('cpu-chart','cpu',6,this)">6h</button>
          <button class="period-btn" onclick="loadChart('cpu-chart','cpu',24,this)">24h</button>
          <button class="period-btn" onclick="loadChart('cpu-chart','cpu',168,this)">7d</button>
        </div>
      </div>
      <div class="chart-wrap"><canvas id="cpu-chart"></canvas></div>
    </div>

    <div class="chart-card">
      <div class="chart-header">
        <span class="chart-title">RAM %</span>
        <div class="period-tabs">
          <button class="period-btn active" onclick="loadChart('ram-chart','ram',6,this)">6h</button>
          <button class="period-btn" onclick="loadChart('ram-chart','ram',24,this)">24h</button>
          <button class="period-btn" onclick="loadChart('ram-chart','ram',168,this)">7d</button>
        </div>
      </div>
      <div class="chart-wrap"><canvas id="ram-chart"></canvas></div>
    </div>

  </div>
  <?php endif; ?>

<?php endif; ?>
</div>

<script>
const charts = {};

async function loadChart(canvasId, metric, hours, btn) {
  if (btn) {
    btn.closest('.period-tabs').querySelectorAll('.period-btn')
       .forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
  }
  const res  = await fetch(`api.php?metric=${metric}&hours=${hours}`);
  const data = await res.json();
  const color = metric === 'ram' ? '#818cf8' : '#38bdf8';

  if (charts[canvasId]) {
    charts[canvasId].data.labels = data.labels;
    charts[canvasId].data.datasets[0].data = data.values;
    charts[canvasId].update();
    return;
  }
  const ctx = document.getElementById(canvasId).getContext('2d');
  charts[canvasId] = new Chart(ctx, {
    type: 'line',
    data: {
      labels: data.labels,
      datasets: [{
        data: data.values,
        borderColor: color,
        backgroundColor: color + '22',
        borderWidth: 2,
        pointRadius: 0,
        fill: true,
        tension: 0.3,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      animation: { duration: 300 },
      plugins: { legend: { display: false } },
      scales: {
        x: {
          ticks: { color: '#64748b', font: { size: 10 }, maxTicksLimit: 8 },
          grid:  { color: '#1e293b' },
        },
        y: {
          min: 0, max: 100,
          ticks: { color: '#64748b', font: { size: 10 }, callback: v => v + '%' },
          grid:  { color: '#334155' },
        }
      }
    }
  });
}

<?php if ($hasHistory): ?>
loadChart('cpu-chart', 'cpu', 6, null);
loadChart('ram-chart', 'ram', 6, null);
<?php endif; ?>

function toggleDisk(btn) {
  const panel = document.getElementById('disk-panel');
  if (!panel) return;
  const isOpen = panel.style.display === 'block';
  panel.style.display = isOpen ? 'none' : 'block';
  btn.classList.toggle('active', !isOpen);
}
</script>
</body>
</html>
