<?php
require __DIR__ . '/_bootstrap.php';

$domain = preg_replace('/[^a-zA-Z0-9\-\.]/', '', $_GET['domain'] ?? '');
if (!$domain) { header('Location: email.php'); exit; }

$hasStats   = false;
$hasRejects = false;
$hasBounces = false;
try {
    $pdo = new PDO(
        'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
        DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $hasStats   = (int)$pdo->prepare("SELECT COUNT(*) FROM email_stats_daily WHERE domain=?")->execute([$domain]) > 0;
    $s = $pdo->prepare("SELECT COUNT(*) FROM email_stats_daily WHERE domain=?"); $s->execute([$domain]);
    $hasStats   = (int)$s->fetchColumn() > 0;
    $s = $pdo->prepare("SELECT COUNT(*) FROM email_reject_reasons WHERE domain=?"); $s->execute([$domain]);
    $hasRejects = (int)$s->fetchColumn() > 0;
    $s = $pdo->prepare("SELECT COUNT(*) FROM email_bounce_events WHERE recipient_domain=?"); $s->execute([$domain]);
    $hasBounces = (int)$s->fetchColumn() > 0;
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($domain) ?> — E-mail</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#0f172a;color:#e2e8f0;min-height:100vh}
header{background:#1e293b;border-bottom:1px solid #334155;padding:14px 24px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:10}
header h1{font-size:1.1rem;font-weight:600;color:#f1f5f9}header h1 span{color:#38bdf8}
.back{font-size:.78rem;color:#64748b;text-decoration:none;padding:5px 10px;border:1px solid #334155;border-radius:6px;transition:all .15s}.back:hover{color:#e2e8f0;border-color:#64748b}
.container{max-width:1300px;margin:0 auto;padding:24px 20px}
.nav-pills{display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap}
.nav-pill{text-decoration:none;background:#1e293b;border:1px solid #334155;border-radius:8px;padding:8px 16px;font-size:.82rem;font-weight:500;color:#94a3b8;transition:all .15s}
.nav-pill:hover,.nav-pill.active{background:#334155;color:#e2e8f0;border-color:#475569}
.section-title{font-size:.68rem;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:#475569;margin-bottom:12px;margin-top:28px}
.section-title:first-child{margin-top:0}
.period-bar{display:flex;gap:6px;margin-bottom:8px;align-items:center;flex-wrap:wrap}
.period-bar span{font-size:.75rem;color:#64748b;margin-right:4px}
.pbtn{font-size:.75rem;padding:5px 12px;border-radius:6px;border:1px solid #334155;background:transparent;color:#64748b;cursor:pointer;transition:all .15s}
.pbtn.active,.pbtn:hover{background:#334155;color:#e2e8f0}
.custom-range{display:none;align-items:center;gap:8px;margin-bottom:12px;flex-wrap:wrap}
.custom-range.visible{display:flex}
.custom-range input[type=date]{background:#1e293b;border:1px solid #334155;border-radius:6px;color:#e2e8f0;font-size:.75rem;padding:5px 9px;outline:none}
.custom-range input[type=date]:focus{border-color:#38bdf8}
.custom-range label{font-size:.72rem;color:#64748b}
.apply-btn{font-size:.75rem;padding:5px 14px;border-radius:6px;border:1px solid #38bdf8;background:transparent;color:#38bdf8;cursor:pointer;transition:all .15s}
.apply-btn:hover{background:#38bdf8;color:#0f172a}
.metrics-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-bottom:4px}
.card{background:#1e293b;border:1px solid #334155;border-radius:10px;padding:16px 18px}
.card-label{font-size:.65rem;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:.07em;margin-bottom:6px}
.card-value{font-size:1.8rem;font-weight:700;line-height:1;margin-bottom:3px}
.card-sub{font-size:.72rem;color:#94a3b8}
.charts-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(380px,1fr));gap:14px}
.chart-card{background:#1e293b;border:1px solid #334155;border-radius:10px;padding:18px 20px}
.chart-title{font-size:.82rem;font-weight:600;color:#cbd5e1;margin-bottom:14px}
.chart-wrap{position:relative;height:200px}
.chart-wrap-sm{position:relative;height:220px}
.table-card{background:#1e293b;border:1px solid #334155;border-radius:10px;overflow:hidden;margin-top:0}
table{width:100%;border-collapse:collapse;font-size:.82rem}
thead{background:#0f172a}
th{padding:10px 14px;text-align:left;font-size:.65rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#475569}
td{padding:9px 14px;border-top:1px solid #334155}
tr:hover td{background:#ffffff06}
.num{text-align:right}
.reason-blacklist{color:#f87171}
.reason-sender_verify{color:#fbbf24}
.reason-unroutable{color:#fb923c}
.reason-relay{color:#a78bfa}
.reason-dns_fail{color:#60a5fa}
.reason-other{color:#94a3b8}
.no-data{background:#1e293b;border:1px solid #334155;border-radius:10px;padding:20px;color:#475569;font-size:.85rem;margin-top:12px}
.badge-frozen{background:#7f1d1d55;color:#fca5a5;padding:2px 7px;border-radius:4px;font-size:.7rem}
code{background:#334155;padding:2px 6px;border-radius:4px;font-size:.78rem;word-break:break-all}
</style>
</head>
<body>

<header>
  <h1>E-mail — <span><?= htmlspecialchars($domain) ?></span></h1>
  <a href="email.php" class="back">← E-mail</a>
</header>

<div class="container">

  <div class="nav-pills">
    <a href="index.php"      class="nav-pill">Sistema</a>
    <a href="cpu-detail.php" class="nav-pill">CPU por Conta</a>
    <a href="email.php"      class="nav-pill active">E-mail</a>
    <a href="queue.php"      class="nav-pill">Fila</a>
  </div>

<?php if (!$hasStats): ?>
  <div class="no-data">Sem dados de e-mail para <strong><?= htmlspecialchars($domain) ?></strong>. Verifique se o domínio está correto.</div>
<?php else: ?>

  <div class="period-bar">
    <span>Período:</span>
    <button class="pbtn active" data-d="1" id="btn-today">Hoje</button>
    <button class="pbtn" data-d="2" id="btn-yesterday">Ontem</button>
    <button class="pbtn" data-d="7">7 dias</button>
    <button class="pbtn" data-d="14">14 dias</button>
    <button class="pbtn" data-d="30">30 dias</button>
    <button class="pbtn" id="btn-custom">Personalizado</button>
  </div>
  <div class="custom-range" id="custom-range">
    <label>De</label>
    <input type="date" id="date-from">
    <label>até</label>
    <input type="date" id="date-to">
    <button class="apply-btn" onclick="applyCustomRange()">Aplicar</button>
  </div>

  <div class="section-title">Resumo</div>
  <div class="metrics-grid">
    <div class="card"><div class="card-label">Recebidos</div><div class="card-value" style="color:#38bdf8" id="s-received">—</div><div class="card-sub">entregues ao destinatário</div></div>
    <div class="card"><div class="card-label">Enviados</div><div class="card-value" style="color:#34d399" id="s-sent">—</div><div class="card-sub">saíram do servidor</div></div>
    <div class="card"><div class="card-label">Env. que Voltaram</div><div class="card-value" style="color:#f59e0b" id="s-bounced">—</div><div class="card-sub">enviados com falha de entrega</div></div>
    <div class="card"><div class="card-label">Recebidos Bloqueados</div><div class="card-value" style="color:#f87171" id="s-rejected">—</div><div class="card-sub">spam/blacklist bloqueados</div></div>
  </div>

  <div class="section-title">Volume diário</div>
  <div class="chart-card">
    <div class="chart-wrap"><canvas id="volume-chart"></canvas></div>
  </div>

  <div class="section-title">Rejeições</div>
  <?php if (!$hasRejects): ?>
    <div class="no-data">Detalhamento de rejeições disponível após a próxima coleta do cron.</div>
  <?php else: ?>
  <div class="charts-grid">
    <div class="chart-card">
      <div class="chart-title">Motivos de rejeição</div>
      <div class="chart-wrap-sm"><canvas id="reasons-chart"></canvas></div>
    </div>
    <div class="table-card">
      <table>
        <thead><tr><th>Motivo</th><th class="num">Contagem</th><th class="num">%</th></tr></thead>
        <tbody id="reasons-tbody"><tr><td colspan="3" style="color:#475569;padding:16px">Carregando...</td></tr></tbody>
      </table>
    </div>
  </div>

  <div class="section-title">Top IPs atacantes</div>
  <div class="table-card">
    <table>
      <thead><tr><th>IP</th><th class="num">Tentativas</th></tr></thead>
      <tbody id="ips-tbody"><tr><td colspan="2" style="color:#475569;padding:16px">Carregando...</td></tr></tbody>
    </table>
  </div>
  <?php endif; ?>

  <div class="section-title">Bounces recentes</div>
  <?php if (!$hasBounces): ?>
    <div class="no-data">Nenhum bounce registrado para este domínio. Os dados de bounce são coletados a partir da próxima execução do cron.</div>
  <?php else: ?>
  <div class="table-card">
    <table>
      <thead><tr><th>Data/Hora</th><th>Destinatário</th><th>Motivo</th></tr></thead>
      <tbody id="bounces-tbody"><tr><td colspan="3" style="color:#475569;padding:16px">Carregando...</td></tr></tbody>
    </table>
  </div>
  <div style="margin-top:10px;text-align:center">
    <button class="pbtn" id="load-more-btn" style="display:none" onclick="loadMoreBounces()">Carregar mais</button>
  </div>
  <?php endif; ?>

<?php endif; ?>
</div>

<script>
const domain     = <?= json_encode($domain) ?>;
const hasRejects = <?= $hasRejects ? 'true' : 'false' ?>;
const hasBounces = <?= $hasBounces ? 'true' : 'false' ?>;
let currentFilter = { days: 7 };
let volumeChart, reasonsChart;
let bounceOffset = 0;

function todayStr() {
  return new Date().toISOString().slice(0,10);
}
function yesterdayStr() {
  const d = new Date(); d.setDate(d.getDate()-1);
  return d.toISOString().slice(0,10);
}
function filterQS() {
  if (currentFilter.from) return `from=${currentFilter.from}&to=${currentFilter.to}`;
  return `days=${currentFilter.days}`;
}

const reasonLabels = {
  blacklist:     'Blacklist (Spamhaus/RBL)',
  sender_verify: 'Falha no Sender Verify',
  unroutable:    'Endereço Inválido',
  relay:         'Relay Negado',
  dns_fail:      'Falha de DNS',
  other:         'Outros',
};
const reasonColors = {
  blacklist:     '#f87171',
  sender_verify: '#fbbf24',
  unroutable:    '#fb923c',
  relay:         '#a78bfa',
  dns_fail:      '#60a5fa',
  other:         '#94a3b8',
};

async function loadAll(filter) {
  currentFilter = filter;
  bounceOffset  = 0;
  const qs = filterQS();

  const sum = await fetch(`api.php?action=email-domain-summary&domain=${encodeURIComponent(domain)}&${qs}`).then(r=>r.json());
  if (sum) {
    document.getElementById('s-received').textContent = Number(sum.received).toLocaleString('pt-BR');
    document.getElementById('s-sent').textContent     = Number(sum.sent).toLocaleString('pt-BR');
    document.getElementById('s-bounced').textContent  = Number(sum.bounced).toLocaleString('pt-BR');
    document.getElementById('s-rejected').textContent = Number(sum.rejected).toLocaleString('pt-BR');
  }

  const tl = await fetch(`api.php?action=email-timeline&domain=${encodeURIComponent(domain)}&${qs}`).then(r=>r.json());
  const ds = [
    { label:'Recebidos',      data:tl.received, borderColor:'#38bdf8', backgroundColor:'#38bdf822', borderWidth:2, pointRadius:2, fill:true,  tension:.3 },
    { label:'Enviados',       data:tl.sent,     borderColor:'#34d399', backgroundColor:'#34d39922', borderWidth:2, pointRadius:2, fill:true,  tension:.3 },
    { label:'Env. Voltaram',  data:tl.bounced,  borderColor:'#f59e0b', backgroundColor:'transparent', borderWidth:1, pointRadius:2, fill:false, tension:.3 },
    { label:'Rec. Bloqueados',data:tl.rejected, borderColor:'#f87171', backgroundColor:'transparent', borderWidth:1, pointRadius:2, fill:false, tension:.3 },
  ];
  const chartOpts = {
    responsive:true, maintainAspectRatio:false, animation:{duration:300},
    plugins:{ legend:{ display:true, labels:{color:'#94a3b8',font:{size:10},boxWidth:12} } },
    scales:{
      x:{ ticks:{color:'#64748b',font:{size:10},maxTicksLimit:8}, grid:{color:'#1e293b'} },
      y:{ min:0, ticks:{color:'#64748b',font:{size:10}}, grid:{color:'#334155'} }
    }
  };
  if (volumeChart) { volumeChart.data.labels=tl.labels; volumeChart.data.datasets=ds; volumeChart.update(); }
  else volumeChart = new Chart(document.getElementById('volume-chart'), { type:'line', data:{labels:tl.labels, datasets:ds}, options:chartOpts });

  if (hasRejects) loadRejects();
  if (hasBounces) loadBounces(true);
}

async function loadRejects() {
  const qs = filterQS();
  const reasons = await fetch(`api.php?action=reject-reasons&domain=${encodeURIComponent(domain)}&${qs}`).then(r=>r.json());
  const ips     = await fetch(`api.php?action=reject-top-ips&domain=${encodeURIComponent(domain)}&${qs}`).then(r=>r.json());

  const total  = reasons.reduce((s,r) => s + parseInt(r.total), 0);
  const labels = reasons.map(r => reasonLabels[r.reason_type] || r.reason_type);
  const data   = reasons.map(r => parseInt(r.total));
  const colors = reasons.map(r => reasonColors[r.reason_type] || '#94a3b8');

  if (reasonsChart) {
    reasonsChart.data.labels = labels;
    reasonsChart.data.datasets[0].data = data;
    reasonsChart.data.datasets[0].backgroundColor = colors;
    reasonsChart.update();
  } else {
    reasonsChart = new Chart(document.getElementById('reasons-chart'), {
      type: 'doughnut',
      data: { labels, datasets:[{ data, backgroundColor:colors, borderWidth:2, borderColor:'#1e293b' }] },
      options: {
        responsive:true, maintainAspectRatio:false, animation:{duration:300},
        plugins:{ legend:{ position:'right', labels:{color:'#94a3b8',font:{size:11},boxWidth:14,padding:10} } }
      }
    });
  }

  const tbody = document.getElementById('reasons-tbody');
  tbody.innerHTML = reasons.length
    ? reasons.map(r => {
        const pct = total > 0 ? Math.round(r.total / total * 100) : 0;
        return `<tr><td class="reason-${r.reason_type}">${reasonLabels[r.reason_type]||r.reason_type}</td><td class="num">${Number(r.total).toLocaleString('pt-BR')}</td><td class="num" style="color:#64748b">${pct}%</td></tr>`;
      }).join('')
    : '<tr><td colspan="3" style="color:#475569;padding:16px">Sem dados de rejeição no período.</td></tr>';

  const ipsTbody = document.getElementById('ips-tbody');
  ipsTbody.innerHTML = ips.length
    ? ips.map(r => `<tr><td><code>${r.sender_ip}</code></td><td class="num">${Number(r.total).toLocaleString('pt-BR')}</td></tr>`).join('')
    : '<tr><td colspan="2" style="color:#475569;padding:16px">Sem dados de IP no período.</td></tr>';
}

async function loadBounces(reset) {
  if (reset) bounceOffset = 0;
  const qs   = filterQS();
  const rows = await fetch(`api.php?action=bounce-list&domain=${encodeURIComponent(domain)}&${qs}&offset=${bounceOffset}`).then(r=>r.json());
  const tbody = document.getElementById('bounces-tbody');
  const btn   = document.getElementById('load-more-btn');

  if (reset) tbody.innerHTML = '';

  if (!rows.length && bounceOffset === 0) {
    tbody.innerHTML = '<tr><td colspan="3" style="color:#475569;padding:16px">Nenhum bounce no período.</td></tr>';
    btn.style.display = 'none';
    return;
  }

  rows.forEach(r => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td style="color:#64748b;white-space:nowrap">${r.logged_at}</td>
      <td><code>${r.recipient}</code></td>
      <td style="color:#94a3b8;font-size:.78rem">${r.reason || '—'}</td>`;
    tbody.appendChild(tr);
  });

  bounceOffset += rows.length;
  btn.style.display = rows.length === 50 ? 'inline-block' : 'none';
}

function loadMoreBounces() { loadBounces(false); }

function setActiveBtn(el) {
  document.querySelectorAll('.pbtn').forEach(b => b.classList.remove('active'));
  if (el) el.classList.add('active');
}

document.querySelectorAll('[data-d]').forEach(btn => {
  btn.addEventListener('click', function() {
    document.getElementById('custom-range').classList.remove('visible');
    setActiveBtn(this);
    const d = parseInt(this.dataset.d);
    if (this.id === 'btn-today') {
      const t = todayStr();
      loadAll({ from: t, to: t });
    } else if (this.id === 'btn-yesterday') {
      const y = yesterdayStr();
      loadAll({ from: y, to: y });
    } else {
      loadAll({ days: d });
    }
  });
});

document.getElementById('btn-custom').addEventListener('click', function() {
  setActiveBtn(this);
  const cr = document.getElementById('custom-range');
  cr.classList.toggle('visible');
  if (!document.getElementById('date-from').value) {
    document.getElementById('date-from').value = yesterdayStr();
    document.getElementById('date-to').value   = todayStr();
  }
});

function applyCustomRange() {
  const f = document.getElementById('date-from').value;
  const t = document.getElementById('date-to').value;
  if (!f || !t || f > t) { alert('Selecione um intervalo válido.'); return; }
  loadAll({ from: f, to: t });
}

<?php if ($hasStats): ?>
// Inicializa com "Hoje"
(function() {
  const t = todayStr();
  loadAll({ from: t, to: t });
})();
<?php endif; ?>
</script>
</body>
</html>
