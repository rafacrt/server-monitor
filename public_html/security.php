<?php
require __DIR__ . '/_bootstrap.php';

try {
    $pdo = new PDO(
        'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
        DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $hasData = (int)$pdo->query("SELECT COUNT(*) FROM security_events")->fetchColumn() > 0;

    // Stats today
    $today     = date('Y-m-d');
    $bansToday = (int)$pdo->query("SELECT COUNT(*) FROM security_events WHERE event_type='ban' AND DATE(logged_at)='$today'")->fetchColumn();
    $sshFails  = (int)$pdo->query("SELECT COUNT(*) FROM security_events WHERE event_type='ssh_fail' AND DATE(logged_at)='$today'")->fetchColumn();
    $distIps   = (int)$pdo->query("SELECT COUNT(DISTINCT ip) FROM security_events WHERE DATE(logged_at)='$today'")->fetchColumn();

    // Currently banned IPs (banned but not yet unbanned in last 24h)
    $banned = $pdo->query("
        SELECT ip, jail, MAX(logged_at) AS last_ban
        FROM security_events
        WHERE event_type='ban' AND logged_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
          AND ip NOT IN (
            SELECT ip FROM security_events
            WHERE event_type='unban' AND logged_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
          )
        GROUP BY ip, jail
        ORDER BY last_ban DESC
        LIMIT 50
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Top attacking IPs (last 7 days)
    $topIps = $pdo->query("
        SELECT ip,
               SUM(CASE WHEN event_type='ban'      THEN 1 ELSE 0 END) AS bans,
               SUM(CASE WHEN event_type='ssh_fail' THEN 1 ELSE 0 END) AS ssh_fails,
               MAX(logged_at) AS last_seen
        FROM security_events
        WHERE logged_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY ip
        ORDER BY bans DESC, ssh_fails DESC
        LIMIT 25
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Recent events (last 100)
    $recentEvents = $pdo->query("
        SELECT logged_at, event_type, jail, ip
        FROM security_events
        ORDER BY logged_at DESC
        LIMIT 100
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Bans per day (last 14d) for chart
    $bansPerDay = $pdo->query("
        SELECT DATE(logged_at) AS day, COUNT(*) AS cnt
        FROM security_events
        WHERE event_type='ban' AND logged_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
        GROUP BY DATE(logged_at) ORDER BY day
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Auth events: accounts sending from multiple IPs
    $authMultiIp = $pdo->query("
        SELECT account,
               COUNT(DISTINCT sender_ip) AS ip_count,
               SUM(send_count) AS total_sends,
               GROUP_CONCAT(DISTINCT sender_ip ORDER BY send_count DESC SEPARATOR ', ') AS ips
        FROM email_auth_events
        WHERE event_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY account
        HAVING ip_count > 1
        ORDER BY ip_count DESC, total_sends DESC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Auth events: new IPs (IP first appeared in last 2 days, account has older history)
    $newIpAlerts = $pdo->query("
        SELECT a.account, a.sender_ip, a.send_count, a.event_date
        FROM email_auth_events a
        WHERE a.event_date >= DATE_SUB(CURDATE(), INTERVAL 2 DAY)
          AND NOT EXISTS (
            SELECT 1 FROM email_auth_events b
            WHERE b.account = a.account AND b.sender_ip = a.sender_ip
              AND b.event_date < DATE_SUB(CURDATE(), INTERVAL 2 DAY)
          )
          AND EXISTS (
            SELECT 1 FROM email_auth_events c
            WHERE c.account = a.account
              AND c.event_date < DATE_SUB(CURDATE(), INTERVAL 2 DAY)
          )
        ORDER BY a.send_count DESC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $hasData = false;
    $bansToday = $sshFails = $distIps = 0;
    $banned = $topIps = $recentEvents = $bansPerDay = $authMultiIp = $newIpAlerts = [];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="refresh" content="120">
<title>Segurança — Monitor</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
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
.two-col{display:grid;grid-template-columns:repeat(auto-fit,minmax(400px,1fr));gap:14px}
.table-card{background:#1e293b;border:1px solid #334155;border-radius:10px;overflow:hidden}
.chart-card{background:#1e293b;border:1px solid #334155;border-radius:10px;padding:18px 20px}
.chart-title{font-size:.82rem;font-weight:600;color:#cbd5e1;margin-bottom:14px}
.chart-wrap{position:relative;height:180px}
table{width:100%;border-collapse:collapse;font-size:.8rem}
thead{background:#0f172a}
th{padding:10px 14px;text-align:left;font-size:.65rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#475569}
td{padding:8px 14px;border-top:1px solid #334155;vertical-align:middle}
tr:hover td{background:#ffffff06}
.event-ban{color:#f87171}
.event-unban{color:#94a3b8}
.event-ssh{color:#fbbf24}
code{background:#334155;padding:2px 6px;border-radius:4px;font-size:.75rem}
.alert-row{background:#450a0a33;border-left:3px solid #ef4444}
.warn-row{background:#451a0333;border-left:3px solid #f59e0b}
.no-data{background:#1e293b;border:1px solid #334155;border-radius:10px;padding:24px;color:#475569;font-size:.85rem}
.num{text-align:right}
</style>
</head>
<body>
<header>
  <h1>Segurança do <span>Servidor</span></h1>
  <div class="header-meta"><?= date('d/m/Y H:i') ?> · auto-refresh 2min</div>
</header>
<div class="container">

  <div class="nav-pills">
    <a href="index.php"      class="nav-pill">Sistema</a>
    <a href="cpu-detail.php" class="nav-pill">CPU por Conta</a>
    <a href="email.php"      class="nav-pill">E-mail</a>
    <a href="queue.php"      class="nav-pill">Fila</a>
    <a href="uptime.php"     class="nav-pill">Uptime</a>
    <a href="security.php"   class="nav-pill active">Segurança</a>
    <a href="alerts-config.php" class="nav-pill">Alertas</a>
  </div>

<?php if (!$hasData): ?>
  <div class="no-data">Aguardando dados de segurança. Execute o coletor para populá-los.</div>
<?php else: ?>

  <div class="section-title">Hoje — <?= date('d/m/Y') ?></div>
  <div class="metrics-grid">
    <div class="card">
      <div class="card-label">Bans hoje</div>
      <div class="card-value" style="color:<?= $bansToday > 50 ? '#ef4444' : '#f59e0b' ?>"><?= $bansToday ?></div>
      <div class="card-sub">bloqueios via fail2ban</div>
    </div>
    <div class="card">
      <div class="card-label">Falhas SSH</div>
      <div class="card-value" style="color:<?= $sshFails > 100 ? '#ef4444' : '#94a3b8' ?>"><?= $sshFails ?></div>
      <div class="card-sub">tentativas rejeitadas</div>
    </div>
    <div class="card">
      <div class="card-label">IPs distintos</div>
      <div class="card-value" style="color:#64748b"><?= $distIps ?></div>
      <div class="card-sub">atacantes hoje</div>
    </div>
    <div class="card">
      <div class="card-label">IPs banidos agora</div>
      <div class="card-value" style="color:<?= count($banned) > 0 ? '#f59e0b' : '#22c55e' ?>"><?= count($banned) ?></div>
      <div class="card-sub">últimas 24h</div>
    </div>
  </div>

  <?php if ($newIpAlerts): ?>
  <div class="section-title">⚠ E-mail — IPs novos detectados</div>
  <div class="table-card">
    <table>
      <thead><tr><th>Conta</th><th>IP novo</th><th>E-mails enviados</th><th>Data</th></tr></thead>
      <tbody>
      <?php foreach ($newIpAlerts as $r): ?>
      <tr class="warn-row">
        <td><strong><?= htmlspecialchars($r['account']) ?></strong></td>
        <td><code><?= htmlspecialchars($r['sender_ip']) ?></code></td>
        <td class="num"><?= number_format($r['send_count']) ?></td>
        <td style="color:#64748b"><?= $r['event_date'] ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <div class="section-title">E-mail — contas enviando de múltiplos IPs (7 dias)</div>
  <?php if (!$authMultiIp): ?>
    <div class="no-data" style="border-radius:8px;padding:14px">Nenhuma conta enviando de múltiplos IPs — comportamento normal.</div>
  <?php else: ?>
  <div class="table-card">
    <table>
      <thead><tr><th>Conta</th><th class="num">IPs distintos</th><th class="num">Total enviados</th><th>IPs usados</th></tr></thead>
      <tbody>
      <?php foreach ($authMultiIp as $r):
        $cls = $r['ip_count'] >= 3 ? 'alert-row' : '';
      ?>
      <tr class="<?= $cls ?>">
        <td><strong><?= htmlspecialchars($r['account']) ?></strong></td>
        <td class="num" style="color:<?= $r['ip_count'] >= 3 ? '#f87171' : '#f59e0b' ?>"><?= $r['ip_count'] ?></td>
        <td class="num"><?= number_format($r['total_sends']) ?></td>
        <td style="font-size:.75rem;color:#94a3b8"><?= htmlspecialchars($r['ips']) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <div class="section-title">Bans ativos (últimas 24h)</div>
  <?php if (!$banned): ?>
    <div class="no-data" style="border-radius:8px;padding:14px">Nenhum IP atualmente banido.</div>
  <?php else: ?>
  <div class="table-card">
    <table>
      <thead><tr><th>IP</th><th>Jail</th><th>Banido em</th></tr></thead>
      <tbody>
      <?php foreach ($banned as $b): ?>
      <tr>
        <td><code><?= htmlspecialchars($b['ip']) ?></code></td>
        <td style="color:#64748b"><?= htmlspecialchars($b['jail']) ?></td>
        <td style="color:#64748b;font-size:.78rem"><?= $b['last_ban'] ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <div class="section-title">Top IPs atacantes — 7 dias</div>
  <div class="two-col">
    <div class="table-card">
      <table>
        <thead><tr><th>IP</th><th class="num">Bans</th><th class="num">Falhas SSH</th><th>Última vez</th></tr></thead>
        <tbody>
        <?php foreach ($topIps as $r): ?>
        <tr>
          <td><code><?= htmlspecialchars($r['ip']) ?></code></td>
          <td class="num" style="color:<?= $r['bans'] > 5 ? '#f87171' : '#f59e0b' ?>"><?= $r['bans'] ?></td>
          <td class="num" style="color:#94a3b8"><?= $r['ssh_fails'] ?></td>
          <td style="color:#64748b;font-size:.75rem"><?= $r['last_seen'] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="chart-card">
      <div class="chart-title">Bans por dia — 14 dias</div>
      <div class="chart-wrap"><canvas id="bans-chart"></canvas></div>
    </div>
  </div>

  <div class="section-title">Eventos recentes</div>
  <div class="table-card">
    <table>
      <thead><tr><th>Data/Hora</th><th>Tipo</th><th>Jail/Serviço</th><th>IP</th></tr></thead>
      <tbody>
      <?php foreach ($recentEvents as $e):
        $cls = match($e['event_type']) { 'ban' => 'event-ban', 'ssh_fail' => 'event-ssh', default => 'event-unban' };
        $lbl = match($e['event_type']) { 'ban' => '🔴 Ban', 'unban' => '⚪ Unban', 'ssh_fail' => '🟡 SSH fail', default => $e['event_type'] };
      ?>
      <tr>
        <td style="color:#64748b;font-size:.75rem;white-space:nowrap"><?= $e['logged_at'] ?></td>
        <td class="<?= $cls ?>"><?= $lbl ?></td>
        <td style="color:#94a3b8"><?= htmlspecialchars($e['jail'] ?? '') ?></td>
        <td><code><?= htmlspecialchars($e['ip'] ?? '') ?></code></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php endif; ?>
</div>

<script>
<?php if ($bansPerDay): ?>
const bansData = <?= json_encode([
    'labels' => array_column($bansPerDay, 'day'),
    'values' => array_column($bansPerDay, 'cnt'),
]) ?>;
new Chart(document.getElementById('bans-chart'), {
  type: 'bar',
  data: {
    labels: bansData.labels,
    datasets: [{ data: bansData.values, backgroundColor: '#f8717166', borderColor: '#f87171', borderWidth: 1, borderRadius: 3 }]
  },
  options: {
    responsive: true, maintainAspectRatio: false, animation: { duration: 300 },
    plugins: { legend: { display: false } },
    scales: {
      x: { ticks: { color: '#64748b', font: { size: 10 }, maxTicksLimit: 7 }, grid: { color: '#1e293b' } },
      y: { min: 0, ticks: { color: '#64748b', font: { size: 10 } }, grid: { color: '#334155' } }
    }
  }
});
<?php endif; ?>
</script>
</body>
</html>
