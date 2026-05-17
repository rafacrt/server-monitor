<?php
require __DIR__ . '/_bootstrap.php';

try {
    $pdo = new PDO(
        'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
        DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $hasData = (int)$pdo->query("SELECT COUNT(*) FROM site_checks")->fetchColumn() > 0;

    // Latest status per domain
    $statusRows = $pdo->query("
        SELECT s.domain, s.status_code, s.response_ms, s.is_up, s.checked_at
        FROM site_checks s
        INNER JOIN (SELECT domain, MAX(id) AS mid FROM site_checks GROUP BY domain) m
          ON s.id = m.mid
        ORDER BY s.is_up ASC, s.domain ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Open incidents
    $incidents = $pdo->query("
        SELECT domain, started_at, last_status_code,
               TIMESTAMPDIFF(MINUTE, started_at, NOW()) AS down_minutes
        FROM site_incidents WHERE resolved_at IS NULL
        ORDER BY started_at
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Recent resolved incidents (last 7 days)
    $resolved = $pdo->query("
        SELECT domain, started_at, resolved_at,
               TIMESTAMPDIFF(MINUTE, started_at, resolved_at) AS duration_min,
               last_status_code
        FROM site_incidents WHERE resolved_at IS NOT NULL
          AND resolved_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY resolved_at DESC LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Uptime % last 7 days per domain
    $uptimePct = [];
    $rows = $pdo->query("
        SELECT domain,
               SUM(is_up) AS up_cnt,
               COUNT(*) AS total_cnt
        FROM site_checks
        WHERE checked_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY domain
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $uptimePct[$r['domain']] = $r['total_cnt'] > 0
            ? round($r['up_cnt'] / $r['total_cnt'] * 100, 1) : 100;
    }

} catch (Exception $e) {
    $hasData   = false;
    $statusRows = $incidents = $resolved = $uptimePct = [];
}

$totalDomains = count($statusRows);
$downCount    = count(array_filter($statusRows, fn($r) => !$r['is_up']));
$upCount      = $totalDomains - $downCount;
$avgResponse  = $totalDomains > 0
    ? round(array_sum(array_column($statusRows, 'response_ms')) / $totalDomains)
    : 0;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="refresh" content="120">
<title>Uptime — Monitor</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#0f172a;color:#e2e8f0;min-height:100vh}
header{background:#1e293b;border-bottom:1px solid #334155;padding:14px 24px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:10}
header h1{font-size:1.1rem;font-weight:600;color:#f1f5f9}header h1 span{color:#38bdf8}
.header-meta{font-size:.72rem;color:#64748b}
.container{max-width:1300px;margin:0 auto;padding:24px 20px}
.nav-pills{display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap}
.nav-pill{text-decoration:none;background:#1e293b;border:1px solid #334155;border-radius:8px;padding:8px 16px;font-size:.82rem;font-weight:500;color:#94a3b8;transition:all .15s}
.nav-pill:hover,.nav-pill.active{background:#334155;color:#e2e8f0;border-color:#475569}
.section-title{font-size:.68rem;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:#475569;margin-bottom:12px;margin-top:28px}
.section-title:first-child{margin-top:0}
.metrics-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px}
.card{background:#1e293b;border:1px solid #334155;border-radius:10px;padding:16px 18px}
.card-label{font-size:.65rem;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:.07em;margin-bottom:6px}
.card-value{font-size:2rem;font-weight:700;line-height:1;margin-bottom:3px}
.card-sub{font-size:.72rem;color:#94a3b8}
.sites-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:10px}
.site-card{background:#1e293b;border:1px solid #334155;border-radius:8px;padding:12px 14px;display:flex;gap:10px;align-items:flex-start}
.site-card.down{border-color:#b91c1c55;background:#450a0a33}
.site-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;margin-top:3px}
.dot-up{background:#22c55e;box-shadow:0 0 6px #22c55e88}
.dot-down{background:#ef4444;box-shadow:0 0 6px #ef444488;animation:pulse 1.5s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
.site-domain{font-size:.82rem;font-weight:500;color:#e2e8f0;word-break:break-all}
.site-meta{font-size:.7rem;color:#64748b;margin-top:2px}
.site-pct{font-size:.68rem;margin-top:3px}
.table-card{background:#1e293b;border:1px solid #334155;border-radius:10px;overflow:hidden}
table{width:100%;border-collapse:collapse;font-size:.82rem}
thead{background:#0f172a}
th{padding:10px 14px;text-align:left;font-size:.65rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#475569}
td{padding:9px 14px;border-top:1px solid #334155}
tr:hover td{background:#ffffff06}
.badge-down{background:#7f1d1d55;color:#fca5a5;padding:2px 8px;border-radius:4px;font-size:.7rem;font-weight:600}
.badge-up{background:#14532d55;color:#4ade80;padding:2px 8px;border-radius:4px;font-size:.7rem;font-weight:600}
.no-data{background:#1e293b;border:1px solid #334155;border-radius:10px;padding:24px;color:#475569;font-size:.85rem}
code{background:#334155;padding:2px 6px;border-radius:4px;font-size:.78rem}
</style>
</head>
<body>
<header>
  <h1>Uptime dos <span>Sites</span></h1>
  <div class="header-meta"><?= date('d/m/Y H:i') ?> · auto-refresh 2min</div>
</header>
<div class="container">

  <div class="nav-pills">
    <a href="index.php"      class="nav-pill">Sistema</a>
    <a href="cpu-detail.php" class="nav-pill">CPU por Conta</a>
    <a href="email.php"      class="nav-pill">E-mail</a>
    <a href="queue.php"      class="nav-pill">Fila</a>
    <a href="uptime.php"     class="nav-pill active">Uptime</a>
    <a href="security.php"   class="nav-pill">Segurança</a>
    <a href="alerts-config.php" class="nav-pill">Alertas</a>
  </div>

<?php if (!$hasData): ?>
  <div class="no-data">Aguardando primeira coleta de uptime. Execute: <code>php -d disable_functions= /root/monitor/collect.php</code></div>
<?php else: ?>

  <div class="section-title">Visão geral</div>
  <div class="metrics-grid">
    <div class="card">
      <div class="card-label">Online</div>
      <div class="card-value" style="color:#22c55e"><?= $upCount ?></div>
      <div class="card-sub">de <?= $totalDomains ?> domínios</div>
    </div>
    <?php if ($downCount > 0): ?>
    <div class="card" style="border-color:#b91c1c55">
      <div class="card-label">FORA</div>
      <div class="card-value" style="color:#ef4444"><?= $downCount ?></div>
      <div class="card-sub">requer atenção</div>
    </div>
    <?php endif; ?>
    <div class="card">
      <div class="card-label">Resp. média</div>
      <div class="card-value" style="color:<?= $avgResponse > 2000 ? '#f59e0b' : '#38bdf8' ?>"><?= $avgResponse ?>ms</div>
      <div class="card-sub">última verificação</div>
    </div>
    <div class="card">
      <div class="card-label">Incidentes abertos</div>
      <div class="card-value" style="color:<?= count($incidents) > 0 ? '#ef4444' : '#475569' ?>"><?= count($incidents) ?></div>
      <div class="card-sub">sem resolução</div>
    </div>
  </div>

  <?php if ($incidents): ?>
  <div class="section-title">Incidentes ativos</div>
  <div class="table-card">
    <table>
      <thead><tr><th>Domínio</th><th>Desde</th><th>Fora há</th><th>Código HTTP</th></tr></thead>
      <tbody>
      <?php foreach ($incidents as $inc):
        $h = intdiv($inc['down_minutes'], 60);
        $m = $inc['down_minutes'] % 60;
        $ago = $h > 0 ? "{$h}h {$m}m" : "{$inc['down_minutes']}m";
      ?>
      <tr>
        <td style="font-weight:600;color:#fca5a5"><?= htmlspecialchars($inc['domain']) ?></td>
        <td style="color:#64748b"><?= $inc['started_at'] ?></td>
        <td style="color:#f87171"><?= $ago ?></td>
        <td><?= $inc['last_status_code'] ?: '<span style="color:#64748b">timeout</span>' ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <div class="section-title">Status de todos os sites</div>
  <div class="sites-grid">
    <?php foreach ($statusRows as $s):
      $isUp   = (bool)$s['is_up'];
      $pct    = $uptimePct[$s['domain']] ?? 100;
      $pctCol = $pct < 95 ? '#ef4444' : ($pct < 99 ? '#f59e0b' : '#22c55e');
      $ms     = $s['response_ms'];
      $msCol  = $ms > 3000 ? '#f59e0b' : '#64748b';
    ?>
    <div class="site-card <?= $isUp ? '' : 'down' ?>">
      <span class="site-dot <?= $isUp ? 'dot-up' : 'dot-down' ?>"></span>
      <div>
        <div class="site-domain"><?= htmlspecialchars($s['domain']) ?></div>
        <div class="site-meta">
          <?= $isUp ? "HTTP {$s['status_code']}" : '<strong style="color:#ef4444">FORA</strong>' ?>
          · <span style="color:<?= $msCol ?>"><?= $ms ?>ms</span>
        </div>
        <div class="site-pct" style="color:<?= $pctCol ?>"><?= $pct ?>% uptime 7d</div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if ($resolved): ?>
  <div class="section-title">Incidentes resolvidos — últimos 7 dias</div>
  <div class="table-card">
    <table>
      <thead><tr><th>Domínio</th><th>Início</th><th>Resolução</th><th>Duração</th><th>Código</th></tr></thead>
      <tbody>
      <?php foreach ($resolved as $r):
        $dur = $r['duration_min'] >= 60
            ? intdiv($r['duration_min'],60).'h '.($r['duration_min']%60).'m'
            : $r['duration_min'].'m';
      ?>
      <tr>
        <td><?= htmlspecialchars($r['domain']) ?></td>
        <td style="color:#64748b;font-size:.78rem"><?= $r['started_at'] ?></td>
        <td style="color:#64748b;font-size:.78rem"><?= $r['resolved_at'] ?></td>
        <td style="color:#f59e0b"><?= $dur ?></td>
        <td style="color:#64748b"><?= $r['last_status_code'] ?: 'timeout' ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

<?php endif; ?>
</div>
</body>
</html>
