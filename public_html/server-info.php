<?php
require __DIR__ . '/_bootstrap.php';

$snapFile = dirname(__DIR__) . '/private/current.json';
$snap = file_exists($snapFile) ? json_decode(file_get_contents($snapFile), true) : null;

$siFile = dirname(__DIR__) . '/private/server_info.json';
$si = file_exists($siFile) ? json_decode(file_get_contents($siFile), true) : null;

$ramUsedMb  = $snap ? $snap['ram_used_mb']  : 0;
$ramTotalMb = $snap ? $snap['ram_total_mb'] : 1;
$ramFreeMb  = max(0, $ramTotalMb - $ramUsedMb);
$ramPct     = $snap ? $snap['ram_pct']      : 0;
$ramUsedGb  = round($ramUsedMb  / 1024, 2);
$ramTotalGb = round($ramTotalMb / 1024, 2);
$ramFreeGb  = round($ramFreeMb  / 1024, 2);

$diskUsed  = $snap ? $snap['disk_used_gb']  : 0;
$diskTotal = $snap ? $snap['disk_total_gb'] : 1;
$diskFree  = $snap ? $snap['disk_free_gb']  : 0;
$diskPct   = $snap ? $snap['disk_pct']      : 0;

$cpuPct = $snap ? (int)$snap['cpu_percent'] : 0;
$load   = $snap ? $snap['load'] : [0, 0, 0];

$uptimeSec  = $snap ? (int)$snap['uptime_sec'] : 0;
$uptimeDays = intdiv($uptimeSec, 86400);
$uptimeHrs  = intdiv($uptimeSec % 86400, 3600);
$uptimeMins = intdiv($uptimeSec % 3600, 60);
$uptimeStr  = "{$uptimeDays}d {$uptimeHrs}h {$uptimeMins}m";

$collectedAt = $snap ? $snap['collected_at'] : null;

function pctColor(int $pct): string {
    if ($pct >= 85) return '#ef4444';
    if ($pct >= 65) return '#f59e0b';
    return '#22c55e';
}

$phpVersions = $si['php_versions'] ?? [];
usort($phpVersions, fn($a, $b) => version_compare($a['version'], $b['version']));
$runningFpmCount = count(array_filter($phpVersions, fn($v) => $v['fpm_running']));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="120">
<title>Servidor — <?= htmlspecialchars(SITE_NAME) ?> Monitor</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #0f172a; color: #e2e8f0; min-height: 100vh; }
  .nav-pills { display:flex; gap:10px; margin-bottom:20px; flex-wrap:wrap; }
  .nav-pill { text-decoration:none; background:#1e293b; border:1px solid #334155; border-radius:8px; padding:8px 16px; font-size:.82rem; font-weight:500; color:#94a3b8; transition:all .15s; }
  .nav-pill:hover, .nav-pill.active { background:#334155; color:#e2e8f0; border-color:#475569; }
  header { background:#1e293b; border-bottom:1px solid #334155; padding:14px 24px; display:flex; align-items:center; justify-content:space-between; position:sticky; top:0; z-index:10; }
  header h1 { font-size:1.1rem; font-weight:600; color:#f1f5f9; }
  header h1 span { color:#38bdf8; }
  .header-meta { font-size:.72rem; color:#64748b; text-align:right; line-height:1.6; }
  .container { max-width:1300px; margin:0 auto; padding:24px 20px; }
  .section-title { font-size:.68rem; font-weight:700; letter-spacing:.09em; text-transform:uppercase; color:#475569; margin-bottom:12px; margin-top:28px; }
  .section-title:first-child { margin-top:0; }
  .grid { display:grid; gap:14px; }
  .grid-4 { grid-template-columns:repeat(auto-fit, minmax(200px,1fr)); }
  .grid-3 { grid-template-columns:repeat(auto-fit, minmax(250px,1fr)); }
  .grid-2 { grid-template-columns:repeat(auto-fit, minmax(380px,1fr)); }
  .card { background:#1e293b; border:1px solid #334155; border-radius:10px; padding:18px 20px; }
  .card-label { font-size:.68rem; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:.07em; margin-bottom:8px; }
  .card-value { font-size:1.9rem; font-weight:700; line-height:1; margin-bottom:5px; }
  .card-sub { font-size:.76rem; color:#94a3b8; }
  .bar-track { height:4px; background:#334155; border-radius:3px; margin-top:10px; }
  .bar-fill  { height:4px; border-radius:3px; }
  .info-table { width:100%; border-collapse:collapse; font-size:.82rem; }
  .info-table th { text-align:left; padding:10px 14px; color:#475569; font-weight:700; font-size:.68rem; text-transform:uppercase; letter-spacing:.07em; border-bottom:1px solid #334155; }
  .info-table td { padding:11px 14px; border-bottom:1px solid #1e293b; color:#cbd5e1; vertical-align:middle; }
  .info-table tr:last-child td { border-bottom:none; }
  .info-table tr:hover td { background:#1a2740; }
  .badge { display:inline-flex; align-items:center; gap:5px; font-size:.72rem; font-weight:600; padding:3px 10px; border-radius:20px; }
  .badge-green { background:#14532d44; color:#4ade80; border:1px solid #15803d55; }
  .badge-gray  { background:#1e293b; color:#475569; border:1px solid #334155; }
  .badge-blue  { background:#0c4a6e44; color:#38bdf8; border:1px solid #0369a155; }
  .dot { width:7px; height:7px; border-radius:50%; display:inline-block; }
  .dot-green { background:#22c55e; box-shadow:0 0 4px #22c55e99; }
  .dot-gray  { background:#475569; }
  .php-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(145px,1fr)); gap:10px; }
  .php-card { background:#1e293b; border:1px solid #334155; border-radius:8px; padding:12px 14px; }
  .php-card.running { border-color:#15803d55; background:#0a1f0f; }
  .php-ver  { font-size:1.1rem; font-weight:700; color:#38bdf8; margin-bottom:4px; }
  .php-full { font-size:.72rem; color:#64748b; margin-bottom:6px; }
  .info-row { display:flex; justify-content:space-between; align-items:center; padding:9px 0; border-bottom:1px solid #1e293b; font-size:.82rem; }
  .info-row:last-child { border-bottom:none; }
  .info-key { color:#64748b; font-weight:500; }
  .info-val { color:#cbd5e1; text-align:right; font-family:monospace; font-size:.8rem; }
  .no-snap { background:#1e293b; border:1px solid #334155; border-radius:10px; padding:20px; color:#475569; font-size:.85rem; }
  code { background:#334155; padding:2px 7px; border-radius:4px; font-size:.82rem; }
</style>
</head>
<body>

<header>
  <h1><?= htmlspecialchars(SITE_NAME) ?> <span>Monitor</span></h1>
  <div class="header-meta">
    <?= date('d/m/Y H:i') ?> · auto-refresh 2min
    <?php if ($collectedAt): ?>
      <br>Dados coletados às <?= date('H:i', strtotime($collectedAt)) ?>
    <?php endif; ?>
  </div>
</header>

<div class="container">

  <div class="nav-pills">
    <a href="index.php"       class="nav-pill">Sistema</a>
    <a href="cpu-detail.php"  class="nav-pill">CPU por Conta</a>
    <a href="email.php"       class="nav-pill">E-mail</a>
    <a href="queue.php"       class="nav-pill">Fila</a>
    <a href="uptime.php"      class="nav-pill">Uptime</a>
    <a href="security.php"    class="nav-pill">Segurança</a>
    <a href="alerts-config.php" class="nav-pill">Alertas</a>
    <a href="server-info.php" class="nav-pill active">Servidor</a>
  </div>

<?php if (!$snap && !$si): ?>
  <div class="no-snap">Aguardando primeiro snapshot. Execute: <code>php -d disable_functions= /root/monitor/collect.php</code></div>
<?php else: ?>

  <!-- Recursos ao vivo -->
  <div class="section-title">Recursos — ao vivo</div>
  <div class="grid grid-4">

    <div class="card">
      <div class="card-label">CPU</div>
      <div class="card-value" style="color:<?= pctColor($cpuPct) ?>"><?= $cpuPct ?>%</div>
      <div class="card-sub">Load: <?= round($load[0],2) ?> · <?= round($load[1],2) ?> · <?= round($load[2],2) ?></div>
      <div class="bar-track"><div class="bar-fill" style="width:<?= $cpuPct ?>%;background:<?= pctColor($cpuPct) ?>"></div></div>
    </div>

    <div class="card">
      <div class="card-label">RAM Usada</div>
      <div class="card-value" style="color:<?= pctColor($ramPct) ?>"><?= $ramUsedGb ?>GB</div>
      <div class="card-sub"><?= $ramPct ?>% de <?= $ramTotalGb ?>GB · <?= $ramFreeGb ?>GB livre</div>
      <div class="bar-track"><div class="bar-fill" style="width:<?= $ramPct ?>%;background:<?= pctColor($ramPct) ?>"></div></div>
    </div>

    <div class="card">
      <div class="card-label">Disco Usado</div>
      <div class="card-value" style="color:<?= pctColor($diskPct) ?>"><?= $diskUsed ?>GB</div>
      <div class="card-sub"><?= $diskPct ?>% de <?= $diskTotal ?>GB · <?= $diskFree ?>GB livre</div>
      <div class="bar-track"><div class="bar-fill" style="width:<?= $diskPct ?>%;background:<?= pctColor($diskPct) ?>"></div></div>
    </div>

    <div class="card">
      <div class="card-label">Uptime</div>
      <div class="card-value" style="font-size:1.4rem;color:#38bdf8"><?= $uptimeStr ?></div>
      <div class="card-sub">desde o último boot</div>
    </div>

  </div>

<?php if ($si): ?>

  <!-- Hardware + Sistema -->
  <div class="section-title">Hardware &amp; Sistema</div>
  <div class="grid grid-2">

    <div class="card">
      <div class="card-label">Hardware</div>
      <div class="info-row">
        <span class="info-key">CPU</span>
        <span class="info-val"><?= htmlspecialchars($si['cpu_model']) ?></span>
      </div>
      <div class="info-row">
        <span class="info-key">Cores físicos</span>
        <span class="info-val"><?= $si['cpu_cores'] ?> cores</span>
      </div>
      <div class="info-row">
        <span class="info-key">Threads (vCPU)</span>
        <span class="info-val"><?= $si['cpu_threads'] ?> threads</span>
      </div>
      <div class="info-row">
        <span class="info-key">RAM total</span>
        <span class="info-val"><?= $ramTotalGb ?>GB</span>
      </div>
      <div class="info-row">
        <span class="info-key">RAM livre</span>
        <span class="info-val"><?= $ramFreeGb ?>GB (<?= 100 - $ramPct ?>%)</span>
      </div>
      <div class="info-row">
        <span class="info-key">Disco total</span>
        <span class="info-val"><?= $diskTotal ?>GB</span>
      </div>
      <div class="info-row">
        <span class="info-key">Disco livre</span>
        <span class="info-val"><?= $diskFree ?>GB (<?= 100 - $diskPct ?>%)</span>
      </div>
    </div>

    <div class="card">
      <div class="card-label">Sistema</div>
      <div class="info-row">
        <span class="info-key">Hostname</span>
        <span class="info-val"><?= htmlspecialchars($si['hostname']) ?></span>
      </div>
      <div class="info-row">
        <span class="info-key">IP principal</span>
        <span class="info-val"><?= htmlspecialchars($si['main_ip']) ?></span>
      </div>
      <div class="info-row">
        <span class="info-key">Sistema operacional</span>
        <span class="info-val"><?= htmlspecialchars($si['os']) ?></span>
      </div>
      <div class="info-row">
        <span class="info-key">Kernel</span>
        <span class="info-val"><?= htmlspecialchars($si['kernel']) ?></span>
      </div>
      <div class="info-row">
        <span class="info-key">Uptime</span>
        <span class="info-val"><?= $uptimeStr ?></span>
      </div>
      <div class="info-row">
        <span class="info-key">HestiaCP</span>
        <span class="info-val"><?= htmlspecialchars($si['hestia_version'] ?: '—') ?></span>
      </div>
    </div>

  </div>

  <!-- Softwares -->
  <div class="section-title">Versões de Software</div>
  <div class="card">
    <table class="info-table">
      <thead>
        <tr>
          <th>Software</th>
          <th>Versão</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $software = [
            ['Nginx',          $si['nginx_version'],   'nginx'],
            ['Apache',         $si['apache_version'],  'apache2'],
            ['MariaDB',        $si['mariadb_version'], 'mariadb'],
            ['Exim (SMTP)',    $si['exim_version'],    'exim4'],
            ['Dovecot (IMAP)', $si['dovecot_version'], 'dovecot'],
            ['HestiaCP',       $si['hestia_version'],  'hestia'],
        ];
        $snapServices = $snap['services'] ?? [];
        foreach ($software as [$name, $ver, $svcKey]):
            $isUp = !empty($snapServices[$svcKey]);
        ?>
        <tr>
          <td style="color:#e2e8f0;font-weight:500"><?= htmlspecialchars($name) ?></td>
          <td><code style="font-size:.78rem"><?= htmlspecialchars($ver ?: '—') ?></code></td>
          <td>
            <?php if ($snap): ?>
              <span class="badge <?= $isUp ? 'badge-green' : 'badge-gray' ?>">
                <span class="dot <?= $isUp ? 'dot-green' : 'dot-gray' ?>"></span>
                <?= $isUp ? 'Rodando' : 'Parado' ?>
              </span>
            <?php else: ?>
              <span class="badge badge-gray">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- PHP Versions -->
  <div class="section-title">
    PHP — <?= count($phpVersions) ?> versões instaladas · <?= $runningFpmCount ?> com PHP-FPM ativo
  </div>
  <?php if ($phpVersions): ?>
  <div class="php-grid">
    <?php foreach ($phpVersions as $v):
        $isRun = $v['fpm_running'];
    ?>
    <div class="php-card <?= $isRun ? 'running' : '' ?>">
      <div class="php-ver">PHP <?= htmlspecialchars($v['version']) ?></div>
      <div class="php-full"><?= htmlspecialchars($v['full_version']) ?></div>
      <span class="badge <?= $isRun ? 'badge-green' : 'badge-gray' ?>">
        <span class="dot <?= $isRun ? 'dot-green' : 'dot-gray' ?>"></span>
        <?= $isRun ? 'FPM ativo' : 'FPM parado' ?>
      </span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="no-snap">Aguardando coleta de informações PHP. Execute: <code>php -d disable_functions= /root/monitor/collect.php</code></div>
  <?php endif; ?>

  <div style="margin-top:14px;font-size:.72rem;color:#334155;text-align:right">
    Configuração coletada em <?= htmlspecialchars($si['collected_at']) ?>
  </div>

<?php endif; ?>
<?php endif; ?>

</div>
</body>
</html>
