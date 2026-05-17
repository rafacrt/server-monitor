<?php
require __DIR__ . '/_bootstrap.php';

try {
    $pdo = new PDO(
        'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $today   = date('Y-m-d');
    $summary = $pdo->query("
        SELECT COALESCE(SUM(received),0) AS received,
               COALESCE(SUM(sent),0)     AS sent,
               COALESCE(SUM(bounced),0)  AS bounced,
               COALESCE(SUM(rejected),0) AS rejected
        FROM email_stats_daily WHERE stat_date='$today'
    ")->fetch(PDO::FETCH_ASSOC);
    $queue   = (int)$pdo->query("SELECT queue_count FROM email_queue_snap ORDER BY id DESC LIMIT 1")->fetchColumn();
    $hasData = (int)$pdo->query("SELECT COUNT(*) FROM email_stats_daily")->fetchColumn() > 0;
} catch (Exception $e) {
    $summary = ['received'=>0,'sent'=>0,'bounced'=>0,'rejected'=>0];
    $queue   = 0;
    $hasData = false;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="refresh" content="120">
<title>E-mail — Monitor</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#0f172a;color:#e2e8f0;min-height:100vh}
header{background:#1e293b;border-bottom:1px solid #334155;padding:14px 24px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:10}
header h1{font-size:1.1rem;font-weight:600;color:#f1f5f9}header h1 span{color:#38bdf8}
.header-meta{font-size:.72rem;color:#64748b;text-align:right;line-height:1.6}
.back{font-size:.78rem;color:#64748b;text-decoration:none;padding:5px 10px;border:1px solid #334155;border-radius:6px;transition:all .15s}.back:hover{color:#e2e8f0;border-color:#64748b}
.header-right{display:flex;align-items:center;gap:16px}
.container{max-width:1300px;margin:0 auto;padding:24px 20px}
.section-title{font-size:.68rem;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:#475569;margin-bottom:12px;margin-top:28px}
.section-title:first-child{margin-top:0}
.metrics-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px}
.card{background:#1e293b;border:1px solid #334155;border-radius:10px;padding:18px 20px}
.card-label{font-size:.68rem;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:.07em;margin-bottom:8px}
.card-value{font-size:2rem;font-weight:700;line-height:1;margin-bottom:4px}
.card-sub{font-size:.74rem;color:#94a3b8}
.card-link{text-decoration:none;color:inherit;display:block}
.card-clickable{cursor:pointer;transition:border-color .2s}
.card-clickable:hover{border-color:#64748b}
.charts-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));gap:14px}
.chart-card{background:#1e293b;border:1px solid #334155;border-radius:10px;padding:18px 20px}
.chart-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px}
.chart-title{font-size:.82rem;font-weight:600;color:#cbd5e1}
.period-tabs{display:flex;gap:4px}
.period-btn{font-size:.68rem;padding:3px 9px;border-radius:4px;border:1px solid #334155;background:transparent;color:#64748b;cursor:pointer;transition:all .15s}
.period-btn.active,.period-btn:hover{background:#334155;color:#e2e8f0}
.chart-wrap{position:relative;height:200px}
.table-card{background:#1e293b;border:1px solid #334155;border-radius:10px;overflow:hidden}
.table-header{padding:14px 16px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #334155}
.table-header span{font-size:.82rem;font-weight:600;color:#cbd5e1}
.sort-tabs{display:flex;gap:4px}
.sort-btn{font-size:.68rem;padding:3px 9px;border-radius:4px;border:1px solid #334155;background:transparent;color:#64748b;cursor:pointer;transition:all .15s}
.sort-btn.active,.sort-btn:hover{background:#334155;color:#e2e8f0}
table{width:100%;border-collapse:collapse;font-size:.82rem}
thead{background:#0f172a}
th{padding:10px 14px;text-align:left;font-size:.68rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#475569}
td{padding:9px 14px;border-top:1px solid #334155}
tr:hover td{background:#ffffff06}
.num{text-align:right}
.domain-cell{font-weight:500}
.zero{color:#334155}
.warn{color:#f59e0b}
.danger{color:#ef4444}
.period-bar{display:flex;gap:6px;margin-bottom:16px;align-items:center}
.period-bar span{font-size:.75rem;color:#64748b;margin-right:4px}
.pbtn{font-size:.75rem;padding:5px 12px;border-radius:6px;border:1px solid #334155;background:transparent;color:#64748b;cursor:pointer;transition:all .15s}
.pbtn.active,.pbtn:hover{background:#334155;color:#e2e8f0}
.queue-badge{display:inline-flex;align-items:center;gap:6px;background:#1e293b;border:1px solid #334155;border-radius:8px;padding:4px 12px;font-size:.8rem}
.queue-dot{width:7px;height:7px;border-radius:50%}
.nav-pills{display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap}
.nav-pill{text-decoration:none;background:#1e293b;border:1px solid #334155;border-radius:8px;padding:8px 16px;font-size:.82rem;font-weight:500;color:#94a3b8;transition:all .15s}
.nav-pill:hover,.nav-pill.active{background:#334155;color:#e2e8f0;border-color:#475569}
</style>
</head>
<body>

<header>
  <h1>Monitor de <span>E-mail</span></h1>
  <div class="header-right">
    <div class="header-meta"><?= date('d/m/Y H:i') ?> · auto-refresh 2min</div>
    <a href="index.php" class="back">← Painel</a>
  </div>
</header>

<div class="container">

  <div class="nav-pills">
    <a href="index.php"      class="nav-pill">Sistema</a>
    <a href="cpu-detail.php" class="nav-pill">CPU por Conta</a>
    <a href="email.php"      class="nav-pill active">E-mail</a>
    <a href="queue.php"      class="nav-pill">Fila</a>
    <a href="uptime.php"     class="nav-pill">Uptime</a>
    <a href="security.php"   class="nav-pill">Segurança</a>
    <a href="alerts-config.php" class="nav-pill">Alertas</a>
  </div>

  <?php
  $qColor = $queue > 100 ? '#ef4444' : ($queue > 30 ? '#f59e0b' : '#22c55e');
  ?>

  <!-- Cards de resumo (hoje) -->
  <div class="section-title">Hoje — <?= date('d/m/Y') ?></div>
  <div class="metrics-grid">

    <a href="email-events.php?type=received" class="card-link">
    <div class="card card-clickable">
      <div class="card-label">Recebidos ↗</div>
      <div class="card-value" style="color:#38bdf8"><?= number_format($summary['received']) ?></div>
      <div class="card-sub">e-mails entregues hoje</div>
    </div>
    </a>

    <a href="email-events.php?type=sent" class="card-link">
    <div class="card card-clickable">
      <div class="card-label">Enviados ↗</div>
      <div class="card-value" style="color:#34d399"><?= number_format($summary['sent']) ?></div>
      <div class="card-sub">saíram do servidor hoje</div>
    </div>
    </a>

    <a href="email-events.php?type=bounced" class="card-link">
    <div class="card card-clickable">
      <div class="card-label">Bounced ↗</div>
      <div class="card-value" style="color:<?= $summary['bounced']>0?'#f59e0b':'#475569' ?>"><?= number_format($summary['bounced']) ?></div>
      <div class="card-sub">falhas de entrega hoje</div>
    </div>
    </a>

    <a href="email-events.php?type=rejected" class="card-link">
    <div class="card card-clickable">
      <div class="card-label">Rejeitados ↗</div>
      <div class="card-value" style="color:<?= $summary['rejected']>50?'#ef4444':'#94a3b8' ?>"><?= number_format($summary['rejected']) ?></div>
      <div class="card-sub">bloqueados (spam/blacklist)</div>
    </div>
    </a>

    <a href="queue.php" style="text-decoration:none;color:inherit">
    <div class="card" style="cursor:pointer;transition:border-color .15s" onmouseover="this.style.borderColor='#38bdf8'" onmouseout="this.style.borderColor='#334155'">
      <div class="card-label">Fila agora ↗</div>
      <div class="card-value" style="color:<?= $qColor ?>"><?= $queue ?></div>
      <div class="card-sub">mensagens aguardando envio</div>
    </div>
    </a>

  </div>

  <?php if (!$hasData): ?>
    <div style="background:#1e293b;border:1px solid #334155;border-radius:10px;padding:24px;color:#475569;font-size:.85rem;margin-top:20px">
      Aguardando dados de e-mail. O coletor precisa processar os logs do Exim.
    </div>
  <?php else: ?>

  <!-- Gráficos -->
  <div class="section-title">Histórico</div>
  <div class="period-bar">
    <span>Período:</span>
    <button class="pbtn active" data-d="7">7 dias</button>
    <button class="pbtn" data-d="14">14 dias</button>
    <button class="pbtn" data-d="30">30 dias</button>
  </div>

  <div class="charts-grid">

    <div class="chart-card">
      <div class="chart-header">
        <span class="chart-title">Recebidos vs Enviados por dia</span>
      </div>
      <div class="chart-wrap"><canvas id="email-chart"></canvas></div>
    </div>

    <div class="chart-card">
      <div class="chart-header">
        <span class="chart-title">Rejeições + Bounces por dia</span>
      </div>
      <div class="chart-wrap"><canvas id="reject-chart"></canvas></div>
    </div>

    <div class="chart-card">
      <div class="chart-header">
        <span class="chart-title">Fila de envio — últimas 24h</span>
      </div>
      <div class="chart-wrap"><canvas id="queue-chart"></canvas></div>
    </div>

  </div>

  <!-- Tabela por domínio -->
  <div class="section-title">Por domínio</div>
  <div class="period-bar">
    <span>Período:</span>
    <button class="pbtn active" data-td="7">7 dias</button>
    <button class="pbtn" data-td="30">30 dias</button>
  </div>

  <div class="table-card">
    <div class="table-header">
      <span>Domínios</span>
      <div class="sort-tabs">
        <button class="sort-btn active" data-sort="received">Recebidos</button>
        <button class="sort-btn" data-sort="sent">Enviados</button>
        <button class="sort-btn" data-sort="bounced">Bounces</button>
        <button class="sort-btn" data-sort="rejected">Rejeitados</button>
      </div>
    </div>
    <table>
      <thead>
        <tr>
          <th>Domínio</th>
          <th class="num">Recebidos</th>
          <th class="num">Enviados</th>
          <th class="num">Bounces</th>
          <th class="num">Rejeitados</th>
          <th class="num">Total</th>
        </tr>
      </thead>
      <tbody id="domains-tbody">
        <tr><td colspan="6" style="color:#475569;padding:20px">Carregando...</td></tr>
      </tbody>
    </table>
  </div>

  <?php endif; ?>
</div>

<script>
let emailChart, rejectChart, queueChart;
let currentDays = 7;
let currentTableDays = 7;
let currentSort = 'received';

const chartOpts = (yLabel) => ({
  responsive: true, maintainAspectRatio: false,
  animation: { duration: 300 },
  plugins: { legend: { display: true, labels: { color:'#94a3b8', font:{size:10}, boxWidth:12 } } },
  scales: {
    x: { ticks:{ color:'#64748b', font:{size:10}, maxTicksLimit:8 }, grid:{ color:'#1e293b' } },
    y: { min:0, ticks:{ color:'#64748b', font:{size:10} }, grid:{ color:'#334155' } }
  }
});

async function loadCharts(days) {
  currentDays = days;
  const d = await fetch(`api.php?action=email-chart&days=${days}`).then(r=>r.json());

  // Recebidos vs Enviados
  const datasets1 = [
    { label:'Recebidos', data:d.received, borderColor:'#38bdf8', backgroundColor:'#38bdf822', borderWidth:2, pointRadius:2, fill:true, tension:.3 },
    { label:'Enviados',  data:d.sent,     borderColor:'#34d399', backgroundColor:'#34d39922', borderWidth:2, pointRadius:2, fill:true, tension:.3 },
  ];
  if (emailChart) {
    emailChart.data.labels = d.labels;
    emailChart.data.datasets = datasets1;
    emailChart.update();
  } else {
    emailChart = new Chart(document.getElementById('email-chart'), {
      type:'line', data:{ labels:d.labels, datasets:datasets1 }, options: chartOpts()
    });
  }

  // Rejeições + Bounces
  const datasets2 = [
    { label:'Rejeitados', data:d.rejected, borderColor:'#f87171', backgroundColor:'#f8717122', borderWidth:2, pointRadius:2, fill:true, tension:.3 },
    { label:'Bounces',    data:d.bounced,  borderColor:'#fbbf24', backgroundColor:'#fbbf2422', borderWidth:2, pointRadius:2, fill:true, tension:.3 },
  ];
  if (rejectChart) {
    rejectChart.data.labels = d.labels;
    rejectChart.data.datasets = datasets2;
    rejectChart.update();
  } else {
    rejectChart = new Chart(document.getElementById('reject-chart'), {
      type:'line', data:{ labels:d.labels, datasets:datasets2 }, options: chartOpts()
    });
  }
}

async function loadQueue() {
  const q = await fetch(`api.php?action=email-queue&hours=24`).then(r=>r.json());
  if (queueChart) {
    queueChart.data.labels = q.labels;
    queueChart.data.datasets[0].data = q.values;
    queueChart.update();
  } else {
    queueChart = new Chart(document.getElementById('queue-chart'), {
      type: 'line',
      data: {
        labels: q.labels,
        datasets: [{
          label:'Fila', data:q.values,
          borderColor:'#818cf8', backgroundColor:'#818cf822',
          borderWidth:2, pointRadius:0, fill:true, tension:.3
        }]
      },
      options: { ...chartOpts(), plugins: { legend:{ display:false } } }
    });
  }
}

async function loadDomains(days, sort) {
  currentTableDays = days;
  currentSort = sort;
  const rows = await fetch(`api.php?action=email-domains&days=${days}&sort=${sort}`).then(r=>r.json());
  const tbody = document.getElementById('domains-tbody');
  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="6" style="color:#475569;padding:20px">Sem dados.</td></tr>';
    return;
  }
  tbody.innerHTML = rows.map(r => {
    const bClass = parseInt(r.bounced)>5 ? 'warn' : 'zero';
    const rClass = parseInt(r.rejected)>50 ? 'danger' : parseInt(r.rejected)>10 ? 'warn' : '';
    return `<tr>
      <td class="domain-cell"><a href="email-detail.php?domain=${encodeURIComponent(r.domain)}" style="color:#e2e8f0;text-decoration:none;border-bottom:1px dashed #475569">${r.domain}</a></td>
      <td class="num">${Number(r.received).toLocaleString('pt-BR')}</td>
      <td class="num">${Number(r.sent).toLocaleString('pt-BR')}</td>
      <td class="num ${bClass}">${Number(r.bounced).toLocaleString('pt-BR')}</td>
      <td class="num ${rClass}">${Number(r.rejected).toLocaleString('pt-BR')}</td>
      <td class="num" style="color:#64748b">${Number(r.total).toLocaleString('pt-BR')}</td>
    </tr>`;
  }).join('');
}

// Period buttons (charts)
document.querySelectorAll('[data-d]').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelectorAll('[data-d]').forEach(b=>b.classList.remove('active'));
    this.classList.add('active');
    loadCharts(parseInt(this.dataset.d));
  });
});

// Period buttons (table)
document.querySelectorAll('[data-td]').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelectorAll('[data-td]').forEach(b=>b.classList.remove('active'));
    this.classList.add('active');
    loadDomains(parseInt(this.dataset.td), currentSort);
  });
});

// Sort buttons
document.querySelectorAll('[data-sort]').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelectorAll('[data-sort]').forEach(b=>b.classList.remove('active'));
    this.classList.add('active');
    loadDomains(currentTableDays, this.dataset.sort);
  });
});

<?php if ($hasData): ?>
loadCharts(7);
loadQueue();
loadDomains(7, 'received');
<?php endif; ?>
</script>
</body>
</html>
