<?php
require __DIR__ . '/_bootstrap.php';

$mapFile     = dirname(__DIR__) . '/private/accounts_map.json';
$accountsMap = file_exists($mapFile) ? (json_decode(file_get_contents($mapFile), true) ?: []) : [];

try {
    $pdo = new PDO(
        'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $hasData = (int)$pdo->query("SELECT COUNT(*) FROM cpu_by_account")->fetchColumn() > 0;
} catch (Exception $e) {
    $hasData = false;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>CPU por Conta — Monitor</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#0f172a;color:#e2e8f0;min-height:100vh}
header{background:#1e293b;border-bottom:1px solid #334155;padding:14px 24px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:10}
header h1{font-size:1.1rem;font-weight:600;color:#f1f5f9}header h1 span{color:#38bdf8}
.nav-pills{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap}
.nav-pill{text-decoration:none;background:#1e293b;border:1px solid #334155;border-radius:8px;padding:8px 14px;font-size:.82rem;font-weight:500;color:#94a3b8;transition:all .15s;white-space:nowrap}
.nav-pill:hover,.nav-pill.active{background:#334155;color:#e2e8f0;border-color:#475569}
.back{font-size:.78rem;color:#64748b;text-decoration:none;padding:5px 10px;border:1px solid #334155;border-radius:6px;transition:all .15s}.back:hover{color:#e2e8f0;border-color:#64748b}
.container{max-width:1300px;margin:0 auto;padding:24px 20px}
.section-title{font-size:.68rem;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:#475569;margin-bottom:12px;margin-top:28px}
.section-title:first-child{margin-top:0}
.period-bar{display:flex;gap:6px;margin-bottom:20px;flex-wrap:wrap;align-items:center}
.period-bar span{font-size:.75rem;color:#64748b;margin-right:4px}
.pbtn{font-size:.75rem;padding:5px 12px;border-radius:6px;border:1px solid #334155;background:transparent;color:#64748b;cursor:pointer;transition:all .15s}
.pbtn.active,.pbtn:hover{background:#334155;color:#e2e8f0}
.filter-row{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:16px}
.filter-row label{font-size:.75rem;color:#94a3b8}
select{background:#1e293b;border:1px solid #334155;color:#e2e8f0;padding:5px 10px;border-radius:6px;font-size:.78rem}
.charts-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));gap:14px}
.chart-card{background:#1e293b;border:1px solid #334155;border-radius:10px;padding:18px 20px}
.chart-title{font-size:.82rem;font-weight:600;color:#cbd5e1;margin-bottom:14px}
.chart-wrap{position:relative;height:220px}
.table-card{background:#1e293b;border:1px solid #334155;border-radius:10px;overflow:hidden}
table{width:100%;border-collapse:collapse;font-size:.82rem}
thead{background:#0f172a}
th{padding:10px 14px;text-align:left;font-size:.68rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#475569}
td{padding:10px 14px;border-top:1px solid #1e293b2a}
tr:hover td{background:#ffffff06}
.badge{display:inline-block;padding:2px 7px;border-radius:4px;font-size:.7rem;font-weight:600}
.badge-green{background:#14532d55;color:#4ade80}
.badge-yellow{background:#78350f55;color:#fbbf24}
.badge-red{background:#7f1d1d55;color:#f87171}
.sites-list{font-size:.7rem;color:#64748b;margin-top:2px}
.no-data{background:#1e293b;border:1px solid #334155;border-radius:10px;padding:24px;color:#475569;font-size:.85rem}
code{background:#334155;padding:2px 7px;border-radius:4px}
.bar-mini{height:6px;background:#334155;border-radius:3px;margin-top:5px;min-width:60px}
.bar-mini-fill{height:6px;border-radius:3px;background:#38bdf8}
.procs-btn{background:none;border:1px solid #334155;color:#64748b;padding:2px 8px;border-radius:4px;font-size:.72rem;cursor:pointer;transition:all .15s}
.procs-btn:hover{background:#334155;color:#e2e8f0}
.modal-overlay{display:none;position:fixed;inset:0;background:#0008;z-index:100;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:#1e293b;border:1px solid #334155;border-radius:12px;width:90%;max-width:900px;max-height:85vh;display:flex;flex-direction:column}
.modal-header{padding:16px 20px;border-bottom:1px solid #334155;display:flex;align-items:center;justify-content:space-between}
.modal-header h2{font-size:.95rem;font-weight:600;color:#f1f5f9}
.modal-close{background:none;border:none;color:#64748b;font-size:1.4rem;cursor:pointer;line-height:1;padding:0 4px}
.modal-close:hover{color:#e2e8f0}
.modal-body{overflow-y:auto;padding:16px 20px}
.proc-table{width:100%;border-collapse:collapse;font-size:.78rem}
.proc-table th{padding:8px 12px;text-align:left;font-size:.65rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#475569;background:#0f172a}
.proc-table td{padding:8px 12px;border-top:1px solid #1e293b2a;color:#cbd5e1;font-family:monospace;word-break:break-all}
.proc-table tr:hover td{background:#ffffff06}
.kill-btn{background:#7f1d1d44;border:1px solid #7f1d1d88;color:#f87171;padding:3px 10px;border-radius:4px;font-size:.72rem;cursor:pointer;transition:all .15s;white-space:nowrap}
.kill-btn:hover{background:#7f1d1d99}
.kill-btn:disabled{opacity:.4;cursor:not-allowed}
@media(max-width:680px){
  header{padding:10px 12px}
  .container{padding:14px 10px}
  [style*="display:flex"][style*="gap:10px"]{flex-wrap:nowrap;overflow-x:auto;padding-bottom:6px;scrollbar-width:none}
  .charts-grid{grid-template-columns:1fr!important}
  .chart-wrap{height:180px}
  .table-card{overflow-x:auto}
  .filter-row{flex-direction:column;align-items:flex-start}
  select{width:100%}
  .modal{width:95%;max-height:90vh}
}
</style>
</head>
<body>
<header>
  <h1>CPU por <span>Conta</span></h1>
  <div class="header-meta" style="font-size:.72rem;color:#64748b"><?= date('d/m/Y H:i') ?></div>
</header>
<div class="container">

<div class="nav-pills">
  <a href="index.php"         class="nav-pill">Sistema</a>
  <a href="cpu-detail.php"    class="nav-pill active">CPU por Conta</a>
  <a href="email.php"         class="nav-pill">E-mail</a>
  <a href="queue.php"         class="nav-pill">Fila</a>
  <a href="uptime.php"        class="nav-pill">Uptime</a>
  <a href="security.php"      class="nav-pill">Segurança</a>
  <a href="alerts-config.php" class="nav-pill">Alertas</a>
  <a href="server-info.php"   class="nav-pill">Servidor</a>
</div>

<?php if (!$hasData): ?>
<div class="no-data">
  Ainda sem dados de CPU por conta. O coletor precisa rodar ao menos 2 vezes (intervalo de 5 min) para calcular a diferença.<br><br>
  Para popular agora: <code>php -d disable_functions= /root/monitor/collect.php</code>
</div>
<?php else: ?>

<div class="period-bar">
  <span>Período:</span>
  <button class="pbtn active" data-h="1">1h</button>
  <button class="pbtn" data-h="6">6h</button>
  <button class="pbtn" data-h="24">24h</button>
  <button class="pbtn" data-h="168">7 dias</button>
  <button class="pbtn" data-h="720">30 dias</button>
</div>

<div class="charts-grid">
  <div class="chart-card" style="grid-column:span 1">
    <div class="chart-title">Top contas — média de CPU%</div>
    <div class="chart-wrap"><canvas id="bar-chart"></canvas></div>
  </div>
  <div class="chart-card">
    <div class="chart-title">Top 5 contas ao longo do tempo</div>
    <div class="chart-wrap"><canvas id="line-chart"></canvas></div>
  </div>
</div>

<div class="section-title">Detalhamento por conta</div>

<div class="filter-row">
  <label>Filtrar conta:</label>
  <select id="acc-filter">
    <option value="">— todas —</option>
    <?php foreach (array_keys($accountsMap) as $acc): ?>
    <option value="<?= htmlspecialchars($acc) ?>"><?= htmlspecialchars($acc) ?></option>
    <?php endforeach; ?>
  </select>
</div>

<div class="table-card">
<table id="accounts-table">
  <thead>
    <tr>
      <th>Conta</th>
      <th>Sites</th>
      <th>Média CPU%</th>
      <th>Máx CPU%</th>
      <th>Processos</th>
    </tr>
  </thead>
  <tbody id="accounts-tbody">
    <tr><td colspan="5" style="color:#475569;padding:20px">Carregando...</td></tr>
  </tbody>
</table>
</div>

<?php endif; ?>
</div><!-- /container -->

<!-- Modal processos -->
<div class="modal-overlay" id="proc-modal">
  <div class="modal">
    <div class="modal-header">
      <h2>Processos — <span id="modal-account"></span></h2>
      <button class="modal-close" id="modal-close-btn">&times;</button>
    </div>
    <div class="modal-body">
      <div id="proc-loading" style="color:#475569;padding:20px 0">Carregando...</div>
      <table class="proc-table" id="proc-table" style="display:none">
        <thead><tr><th>PID</th><th>Comando</th><th></th></tr></thead>
        <tbody id="proc-tbody"></tbody>
      </table>
    </div>
  </div>
</div>

<?php if ($hasData): ?>
<script>
const accountsMap = <?= json_encode($accountsMap) ?>;
let currentHours  = 1;
let barChart, lineChart;

const chartBase = {
  options: {
    responsive: true,
    maintainAspectRatio: false,
    animation: { duration: 300 },
    plugins: { legend: { display: false } },
    scales: {
      x: { ticks: { color:'#64748b', font:{size:10}, maxTicksLimit:8 }, grid:{ color:'#1e293b' } },
      y: { min:0, ticks:{ color:'#64748b', font:{size:10}, callback:v=>v+'%' }, grid:{ color:'#334155' } }
    }
  }
};

async function loadAll(hours) {
  currentHours = hours;

  // Bar chart: top contas
  const accData = await fetch(`api.php?action=cpu-accounts&hours=${hours}`).then(r=>r.json());
  const labels  = accData.map(r=>r.account);
  const avgs    = accData.map(r=>parseFloat(r.avg_cpu));
  const colors  = avgs.map(v => v>=20?'#f87171': v>=10?'#fbbf24':'#38bdf8');

  if (barChart) {
    barChart.data.labels = labels;
    barChart.data.datasets[0].data = avgs;
    barChart.data.datasets[0].backgroundColor = colors;
    barChart.update();
  } else {
    barChart = new Chart(document.getElementById('bar-chart').getContext('2d'), {
      type: 'bar',
      data: {
        labels,
        datasets: [{ data:avgs, backgroundColor:colors, borderRadius:4, borderSkipped:false }]
      },
      options: {
        ...chartBase.options,
        plugins: {
          legend: { display:false },
          tooltip: { callbacks: { label: c => ` ${c.raw}% média` } }
        }
      }
    });
  }

  // Line chart: top 5 ao longo do tempo
  const top5 = await fetch(`api.php?action=cpu-top5&hours=${hours}`).then(r=>r.json());
  if (lineChart) {
    lineChart.data.labels   = top5.labels;
    lineChart.data.datasets = top5.datasets;
    lineChart.update();
  } else {
    lineChart = new Chart(document.getElementById('line-chart').getContext('2d'), {
      type: 'line',
      data: { labels: top5.labels, datasets: top5.datasets },
      options: {
        ...chartBase.options,
        plugins: {
          legend: {
            display: true,
            labels: { color:'#94a3b8', font:{size:10}, boxWidth:12 }
          }
        }
      }
    });
  }

  // Tabela
  renderTable(accData);
}

function renderTable(data) {
  const filter = document.getElementById('acc-filter').value;
  const tbody  = document.getElementById('accounts-tbody');
  const filtered = filter ? data.filter(r=>r.account===filter) : data;

  if (!filtered.length) {
    tbody.innerHTML = '<tr><td colspan="5" style="color:#475569;padding:20px">Sem dados para o período.</td></tr>';
    return;
  }

  tbody.innerHTML = filtered.map(r => {
    const sites  = (accountsMap[r.account] || []).join(', ') || '—';
    const avg    = parseFloat(r.avg_cpu);
    const cls    = avg>=20 ? 'badge-red' : avg>=10 ? 'badge-yellow' : 'badge-green';
    const barPct = Math.min(100, avg * 2);
    const acc    = r.account.replace(/"/g,'&quot;');
    return `<tr>
      <td><strong>${r.account}</strong></td>
      <td><div class="sites-list">${sites}</div></td>
      <td>
        <span class="badge ${cls}">${r.avg_cpu}%</span>
        <div class="bar-mini"><div class="bar-mini-fill" style="width:${barPct}%"></div></div>
      </td>
      <td style="color:#94a3b8">${r.max_cpu}%</td>
      <td><button class="procs-btn" onclick="openProcs('${acc}')">${r.procs}</button></td>
    </tr>`;
  }).join('');
}

// Period buttons
document.querySelectorAll('.pbtn').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelectorAll('.pbtn').forEach(b=>b.classList.remove('active'));
    this.classList.add('active');
    loadAll(parseInt(this.dataset.h));
  });
});

// Account filter
document.getElementById('acc-filter').addEventListener('change', async () => {
  const acc = document.getElementById('acc-filter').value;
  if (acc) {
    const data = await fetch(`api.php?action=cpu-accounts&hours=${currentHours}`).then(r=>r.json());
    renderTable(data);
  } else {
    const data = await fetch(`api.php?action=cpu-accounts&hours=${currentHours}`).then(r=>r.json());
    renderTable(data);
  }
});

loadAll(1);

// ── Modal de processos ────────────────────────────────────────────────────────
const modal        = document.getElementById('proc-modal');
const modalAccount = document.getElementById('modal-account');
const procLoading  = document.getElementById('proc-loading');
const procTable    = document.getElementById('proc-table');
const procTbody    = document.getElementById('proc-tbody');

document.getElementById('modal-close-btn').addEventListener('click', () => modal.classList.remove('open'));
modal.addEventListener('click', e => { if (e.target === modal) modal.classList.remove('open'); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') modal.classList.remove('open'); });

async function openProcs(account) {
  modalAccount.textContent = account;
  procLoading.style.display = 'block';
  procTable.style.display   = 'none';
  procTbody.innerHTML = '';
  modal.classList.add('open');

  const procs = await fetch(`api.php?action=cpu-processes&account=${encodeURIComponent(account)}`).then(r=>r.json()).catch(()=>[]);

  procLoading.style.display = 'none';
  if (!procs.length) {
    procLoading.textContent = 'Nenhum processo encontrado para esta conta.';
    procLoading.style.display = 'block';
    return;
  }

  procTbody.innerHTML = procs.map(p => `<tr id="proc-row-${p.pid}">
    <td style="color:#64748b;min-width:60px">${p.pid}</td>
    <td>${p.cmd}</td>
    <td style="min-width:80px"><button class="kill-btn" onclick="killProc('${account}',${p.pid},this)">Matar</button></td>
  </tr>`).join('');
  procTable.style.display = 'table';
}

async function killProc(account, pid, btn) {
  if (!confirm(`Matar processo PID ${pid} da conta ${account}?`)) return;
  btn.disabled = true;
  btn.textContent = 'Aguarde...';
  const res = await fetch('api.php?action=kill-process', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({account, pid})
  }).then(r=>r.json()).catch(()=>({error:'falha na requisição'}));

  if (res.ok) {
    const row = document.getElementById(`proc-row-${pid}`);
    if (row) { row.style.opacity = '.35'; row.style.textDecoration = 'line-through'; }
    btn.textContent = 'Enviado';
  } else {
    btn.disabled = false;
    btn.textContent = 'Matar';
    alert('Erro: ' + (res.error || 'desconhecido'));
  }
}
</script>
<?php endif; ?>
</body>
</html>
