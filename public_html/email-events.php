<?php
require __DIR__ . '/_bootstrap.php';

$validTypes = ['received','sent','bounced','rejected'];
$type = in_array($_GET['type'] ?? '', $validTypes) ? $_GET['type'] : 'received';

$cfgMap = [
    'received' => ['label'=>'Recebidos',  'color'=>'#38bdf8', 'sub'=>'e-mails entregues'],
    'sent'     => ['label'=>'Enviados',   'color'=>'#34d399', 'sub'=>'e-mails enviados'],
    'bounced'  => ['label'=>'Bounced',    'color'=>'#f59e0b', 'sub'=>'falhas de entrega'],
    'rejected' => ['label'=>'Rejeitados', 'color'=>'#ef4444', 'sub'=>'bloqueados/rejeitados'],
];
$cfg = $cfgMap[$type];

try {
    $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $hasData         = (int)$pdo->query("SELECT COUNT(*) FROM email_stats_daily")->fetchColumn() > 0;
    $hasAuth         = $type==='sent'
                       && (int)$pdo->query("SELECT COUNT(*) FROM email_auth_events")->fetchColumn() > 0;
    $hasBounceEvents = in_array($type,['bounced','sent'])
                       && (int)$pdo->query("SELECT COUNT(*) FROM email_bounce_events")->fetchColumn() > 0;
    $hasRejectDetail = $type==='rejected'
                       && (int)$pdo->query("SELECT COUNT(*) FROM email_reject_reasons")->fetchColumn() > 0;
} catch(Exception $e) {
    $hasData=$hasAuth=$hasBounceEvents=$hasRejectDetail=false;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $cfg['label'] ?> — Monitor E-mail</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#0f172a;color:#e2e8f0;min-height:100vh}
header{background:#1e293b;border-bottom:1px solid #334155;padding:14px 24px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:10}
header h1{font-size:1.1rem;font-weight:600;color:#f1f5f9}
header h1 span{color:<?= $cfg['color'] ?>}
.back{font-size:.78rem;color:#64748b;text-decoration:none;padding:5px 10px;border:1px solid #334155;border-radius:6px;transition:all .15s}
.back:hover{color:#e2e8f0;border-color:#64748b}
.container{max-width:1300px;margin:0 auto;padding:24px 20px}
.nav-pills{display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap}
.nav-pill{text-decoration:none;background:#1e293b;border:1px solid #334155;border-radius:8px;padding:8px 16px;font-size:.82rem;font-weight:500;color:#94a3b8;transition:all .15s}
.nav-pill:hover,.nav-pill.active{background:#334155;color:#e2e8f0;border-color:#475569}
.section-title{font-size:.68rem;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:#475569;margin-bottom:12px;margin-top:28px}
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
.metrics-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px}
.card{background:#1e293b;border:1px solid #334155;border-radius:10px;padding:16px 18px}
.card-label{font-size:.65rem;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:.07em;margin-bottom:6px}
.card-value{font-size:1.9rem;font-weight:700;line-height:1;margin-bottom:4px}
.card-sub{font-size:.72rem;color:#94a3b8}
.chart-card{background:#1e293b;border:1px solid #334155;border-radius:10px;padding:18px 20px}
.chart-title{font-size:.82rem;font-weight:600;color:#cbd5e1;margin-bottom:14px}
.chart-wrap{position:relative;height:200px}
.chart-wrap-sm{position:relative;height:220px}
.charts-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(380px,1fr));gap:14px}
.table-card{background:#1e293b;border:1px solid #334155;border-radius:10px;overflow:hidden}
.table-header{padding:14px 16px;border-bottom:1px solid #334155}
.table-header span{font-size:.82rem;font-weight:600;color:#cbd5e1}
table{width:100%;border-collapse:collapse;font-size:.82rem}
thead{background:#0f172a}
th{padding:10px 14px;text-align:left;font-size:.65rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#475569}
td{padding:9px 14px;border-top:1px solid #334155;vertical-align:top}
tr:hover td{background:#ffffff06}
.num{text-align:right}
.domain-link{color:#e2e8f0;text-decoration:none;border-bottom:1px dashed #475569}
.domain-link:hover{color:#38bdf8;border-bottom-color:#38bdf8}
.warn{color:#f59e0b}
.danger{color:#ef4444}
.ok{color:#22c55e}
.muted{color:#475569}
code{background:#334155;padding:2px 6px;border-radius:4px;font-size:.78rem;word-break:break-all}
.no-data{background:#1e293b;border:1px solid #334155;border-radius:10px;padding:20px;color:#475569;font-size:.85rem}
.reason-blacklist{color:#f87171}
.reason-sender_verify{color:#fbbf24}
.reason-unroutable{color:#fb923c}
.reason-relay{color:#a78bfa}
.reason-dns_fail{color:#60a5fa}
.reason-other{color:#94a3b8}
.type-nav{display:flex;gap:6px;margin-bottom:20px;flex-wrap:wrap}
.type-btn{text-decoration:none;padding:6px 14px;border-radius:6px;border:1px solid #334155;font-size:.78rem;color:#64748b;transition:all .15s}
.type-btn:hover{background:#334155;color:#e2e8f0}
.type-btn.active{color:#0f172a;border-color:transparent;font-weight:600}
</style>
</head>
<body>

<header>
  <h1>E-mail — <span><?= $cfg['label'] ?></span></h1>
  <a href="email.php" class="back">← E-mail</a>
</header>

<div class="container">

  <div class="nav-pills">
    <a href="index.php"       class="nav-pill">Sistema</a>
    <a href="cpu-detail.php"  class="nav-pill">CPU por Conta</a>
    <a href="email.php"       class="nav-pill active">E-mail</a>
    <a href="queue.php"       class="nav-pill">Fila</a>
    <a href="uptime.php"      class="nav-pill">Uptime</a>
    <a href="security.php"    class="nav-pill">Segurança</a>
    <a href="alerts-config.php" class="nav-pill">Alertas</a>
  </div>

  <!-- Navegação entre tipos -->
  <div class="type-nav">
    <a href="email-events.php?type=received"
       class="type-btn <?= $type==='received'?'active':'' ?>"
       style="<?= $type==='received'?'background:#38bdf8':''; ?>">↓ Recebidos</a>
    <a href="email-events.php?type=sent"
       class="type-btn <?= $type==='sent'?'active':'' ?>"
       style="<?= $type==='sent'?'background:#34d399':''; ?>">↑ Enviados</a>
    <a href="email-events.php?type=bounced"
       class="type-btn <?= $type==='bounced'?'active':'' ?>"
       style="<?= $type==='bounced'?'background:#f59e0b':''; ?>">↩ Bounced</a>
    <a href="email-events.php?type=rejected"
       class="type-btn <?= $type==='rejected'?'active':'' ?>"
       style="<?= $type==='rejected'?'background:#ef4444':''; ?>">✕ Rejeitados</a>
  </div>

  <!-- Filtro de período -->
  <div class="period-bar">
    <span>Período:</span>
    <button class="pbtn active" id="btn-today"     data-d="1">Hoje</button>
    <button class="pbtn"        id="btn-yesterday" data-d="2">Ontem</button>
    <button class="pbtn"        data-d="7">7 dias</button>
    <button class="pbtn"        data-d="14">14 dias</button>
    <button class="pbtn"        data-d="30">30 dias</button>
    <button class="pbtn"        id="btn-custom">Personalizado</button>
  </div>
  <div class="custom-range" id="custom-range">
    <label>De</label>
    <input type="date" id="date-from">
    <label>até</label>
    <input type="date" id="date-to">
    <button class="apply-btn" onclick="applyCustomRange()">Aplicar</button>
  </div>

  <?php if (!$hasData): ?>
    <div class="no-data">Aguardando dados. O coletor precisa processar os logs do Exim.</div>
  <?php else: ?>

  <!-- Cards resumo -->
  <div class="section-title">Resumo do período</div>
  <div class="metrics-grid" id="summary-cards">
    <div class="card">
      <div class="card-label">Recebidos</div>
      <div class="card-value" style="color:#38bdf8" id="s-received">—</div>
      <div class="card-sub">e-mails entregues</div>
    </div>
    <div class="card">
      <div class="card-label">Enviados</div>
      <div class="card-value" style="color:#34d399" id="s-sent">—</div>
      <div class="card-sub">e-mails enviados</div>
    </div>
    <div class="card">
      <div class="card-label">Falha de Entrega</div>
      <div class="card-value" style="color:#f59e0b" id="s-bounced">—</div>
      <div class="card-sub">bounced / não entregues</div>
    </div>
    <div class="card">
      <div class="card-label">Rejeitados</div>
      <div class="card-value" style="color:#ef4444" id="s-rejected">—</div>
      <div class="card-sub">bloqueados/rejeitados</div>
    </div>
    <div class="card">
      <div class="card-label">Na Fila Agora</div>
      <div class="card-value" style="color:#a78bfa" id="s-queue">—</div>
      <div class="card-sub">mensagens na fila</div>
    </div>
  </div>

  <!-- Gráfico timeline -->
  <div class="section-title">Histórico no período</div>
  <div class="chart-card">
    <div class="chart-wrap"><canvas id="timeline-chart"></canvas></div>
  </div>

  <!-- Top domínios -->
  <div class="section-title">Top domínios</div>
  <div class="table-card">
    <div class="table-header"><span>Domínio → clique para detalhes</span></div>
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Domínio</th>
          <th class="num">Recebidos</th>
          <th class="num">Enviados</th>
          <th class="num">Falha de Entrega</th>
          <th class="num">Rejeitados</th>
        </tr>
      </thead>
      <tbody id="domains-tbody">
        <tr><td colspan="6" style="color:#475569;padding:20px">Carregando...</td></tr>
      </tbody>
    </table>
  </div>

  <?php if ($type==='sent' && $hasAuth): ?>
  <!-- Top contas autenticadas -->
  <div class="section-title">Top contas que enviaram</div>
  <div class="charts-grid">
    <div class="table-card">
      <div class="table-header"><span>Conta HestiaCP</span></div>
      <table>
        <thead><tr><th>#</th><th>Conta</th><th class="num">Envios</th><th class="num">IPs únicos</th></tr></thead>
        <tbody id="auth-accounts-tbody">
          <tr><td colspan="4" style="color:#475569;padding:20px">Carregando...</td></tr>
        </tbody>
      </table>
    </div>
    <div class="table-card">
      <div class="table-header"><span>IPs de saída</span></div>
      <table>
        <thead><tr><th>#</th><th>IP</th><th class="num">Envios</th><th class="num">Contas</th></tr></thead>
        <tbody id="auth-ips-tbody">
          <tr><td colspan="4" style="color:#475569;padding:20px">Carregando...</td></tr>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($type==='bounced' && $hasBounceEvents): ?>
  <!-- Top domínios destino que rejeitaram -->
  <div class="section-title">Top destinos que rejeitaram nossa entrega</div>
  <div class="table-card">
    <table>
      <thead><tr><th>#</th><th>Domínio destino</th><th class="num">Eventos</th><th class="num">E-mails distintos</th></tr></thead>
      <tbody id="bounce-dest-tbody">
        <tr><td colspan="4" style="color:#475569;padding:20px">Carregando...</td></tr>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php if ($hasBounceEvents): ?>
  <!-- Lista de bounce events -->
  <div class="section-title">
    <?php if ($type==='bounced'): ?>Detalhes das falhas de entrega
    <?php else: ?>Bounces vinculados ao período<?php endif; ?>
  </div>
  <div class="table-card">
    <table>
      <thead>
        <tr>
          <th style="width:145px">Data/Hora</th>
          <th>Destinatário</th>
          <th>Motivo</th>
        </tr>
      </thead>
      <tbody id="bounces-tbody">
        <tr><td colspan="3" style="color:#475569;padding:20px">Carregando...</td></tr>
      </tbody>
    </table>
  </div>
  <div style="margin-top:10px;text-align:center">
    <button class="pbtn" id="load-more-btn" style="display:none" onclick="loadMoreBounces()">Carregar mais</button>
  </div>
  <?php endif; ?>

  <?php if ($type==='rejected' && $hasRejectDetail): ?>
  <!-- Motivos de rejeição globais -->
  <div class="section-title">Motivos de rejeição (todos os domínios)</div>
  <div class="charts-grid">
    <div class="chart-card">
      <div class="chart-title">Distribuição por motivo</div>
      <div class="chart-wrap-sm"><canvas id="reasons-chart"></canvas></div>
    </div>
    <div class="table-card" style="margin-top:0">
      <table>
        <thead><tr><th>Motivo</th><th class="num">Total</th><th class="num">%</th></tr></thead>
        <tbody id="reasons-tbody">
          <tr><td colspan="3" style="color:#475569;padding:20px">Carregando...</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Top IPs atacantes globais -->
  <div class="section-title">Top IPs atacantes (todos os domínios)</div>
  <div class="table-card">
    <table>
      <thead><tr><th>#</th><th>IP atacante</th><th class="num">Tentativas</th></tr></thead>
      <tbody id="ips-tbody">
        <tr><td colspan="3" style="color:#475569;padding:20px">Carregando...</td></tr>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php endif; ?>
</div>

<script>
const PAGE_TYPE    = <?= json_encode($type) ?>;
const HAS_AUTH     = <?= $hasAuth     ? 'true' : 'false' ?>;
const HAS_BOUNCES  = <?= $hasBounceEvents ? 'true' : 'false' ?>;
const HAS_REJECTS  = <?= $hasRejectDetail ? 'true' : 'false' ?>;

const reasonLabels = {
  blacklist:'Blacklist (Spamhaus/RBL)', sender_verify:'Falha no Sender Verify',
  unroutable:'Endereço Inválido', relay:'Relay Negado',
  dns_fail:'Falha de DNS', other:'Outros',
};
const reasonColors = {
  blacklist:'#f87171', sender_verify:'#fbbf24', unroutable:'#fb923c',
  relay:'#a78bfa', dns_fail:'#60a5fa', other:'#94a3b8',
};

let currentFilter = {};
let timelineChart, reasonsChart;
let bounceOffset = 0;

function todayStr()     { return new Date().toISOString().slice(0,10); }
function yesterdayStr() { const d=new Date(); d.setDate(d.getDate()-1); return d.toISOString().slice(0,10); }
function filterQS()     { return currentFilter.from ? `from=${currentFilter.from}&to=${currentFilter.to}` : `days=${currentFilter.days}`; }

// ── Summary ──────────────────────────────────────────────────────────────────
async function loadSummary() {
  const qs  = filterQS();
  const row = await fetch(`api.php?action=email-domain-summary&${qs}`).then(r=>r.json());
  if (!row) return;

  const set = (id, val) => {
    const el = document.getElementById(id);
    if (el) el.textContent = Number(val ?? 0).toLocaleString('pt-BR');
  };

  set('s-received', row.received);
  set('s-sent',     row.sent);
  set('s-bounced',  row.bounced);
  set('s-rejected', row.rejected);
  if (row.queue !== undefined) set('s-queue', row.queue);
}

// ── Timeline chart ───────────────────────────────────────────────────────────
async function loadTimeline() {
  const qs = filterQS();
  const d  = await fetch(`api.php?action=email-chart&days=${currentFilter.days||30}`).then(r=>r.json());

  const colorMap = { received:'#38bdf8', sent:'#34d399', bounced:'#f59e0b', rejected:'#ef4444' };

  const dsConfigs = {
    received: [{ key:'received', label:'Recebidos', color:'#38bdf8', fill:true }],
    sent:     [{ key:'sent', label:'Enviados', color:'#34d399', fill:true },
               { key:'bounced', label:'Bounced', color:'#f59e0b', fill:false }],
    bounced:  [{ key:'bounced', label:'Bounced', color:'#f59e0b', fill:true },
               { key:'sent', label:'Enviados', color:'#34d39966', fill:false, dash:true }],
    rejected: [{ key:'rejected', label:'Rejeitados', color:'#ef4444', fill:true }],
  };

  const datasets = dsConfigs[PAGE_TYPE].map(cfg => ({
    label:           cfg.label,
    data:            d[cfg.key],
    borderColor:     cfg.color,
    backgroundColor: cfg.fill ? cfg.color+'22' : 'transparent',
    borderWidth:     2,
    pointRadius:     2,
    fill:            cfg.fill,
    tension:         .3,
    borderDash:      cfg.dash ? [4,3] : [],
  }));

  const opts = {
    responsive:true, maintainAspectRatio:false, animation:{duration:300},
    plugins:{ legend:{ display:true, labels:{color:'#94a3b8',font:{size:10},boxWidth:12} } },
    scales:{
      x:{ ticks:{color:'#64748b',font:{size:10},maxTicksLimit:8}, grid:{color:'#1e293b'} },
      y:{ min:0, ticks:{color:'#64748b',font:{size:10}}, grid:{color:'#334155'} }
    }
  };

  if (timelineChart) {
    timelineChart.data.labels = d.labels;
    timelineChart.data.datasets = datasets;
    timelineChart.update();
  } else {
    timelineChart = new Chart(document.getElementById('timeline-chart'), {
      type:'line', data:{ labels:d.labels, datasets }, options:opts
    });
  }
}

// ── Top domínios ─────────────────────────────────────────────────────────────
async function loadDomains() {
  const qs   = filterQS();
  const rows = await fetch(`api.php?action=email-domains&${qs}&sort=${PAGE_TYPE}`).then(r=>r.json());
  const tbody = document.getElementById('domains-tbody');

  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="6" style="color:#475569;padding:20px">Sem dados no período.</td></tr>';
    return;
  }

  tbody.innerHTML = rows.map((r, i) => {
    const domain = r.domain;
    const rcv = Number(r.received).toLocaleString('pt-BR');
    const snt = Number(r.sent).toLocaleString('pt-BR');
    const bnc = Number(r.bounced).toLocaleString('pt-BR');
    const rej = Number(r.rejected).toLocaleString('pt-BR');
    const bncCl = r.bounced > 0 ? 'warn' : 'muted';
    const rejCl = r.rejected > 0 ? 'danger' : 'muted';
    return `<tr>
      <td style="color:#475569;width:32px">${i+1}</td>
      <td><a class="domain-link" href="email-detail.php?domain=${encodeURIComponent(domain)}">${domain}</a></td>
      <td class="num" style="color:#38bdf8">${rcv}</td>
      <td class="num" style="color:#34d399">${snt}</td>
      <td class="num ${bncCl}">${bnc}</td>
      <td class="num ${rejCl}">${rej}</td>
    </tr>`;
  }).join('');
}

// ── Auth (contas / IPs de saída) ─────────────────────────────────────────────
async function loadAuth() {
  if (!HAS_AUTH) return;
  const qs  = filterQS();
  const d   = await fetch(`api.php?action=email-auth-top&${qs}`).then(r=>r.json());

  const atbody = document.getElementById('auth-accounts-tbody');
  if (atbody) {
    atbody.innerHTML = d.accounts.length
      ? d.accounts.map((r,i) => `<tr>
          <td style="color:#475569;width:32px">${i+1}</td>
          <td><code>${r.account}</code></td>
          <td class="num" style="font-weight:600">${Number(r.total).toLocaleString('pt-BR')}</td>
          <td class="num muted">${r.unique_ips}</td>
        </tr>`).join('')
      : '<tr><td colspan="4" style="color:#475569;padding:16px">Sem dados de autenticação no período.</td></tr>';
  }

  const itbody = document.getElementById('auth-ips-tbody');
  if (itbody) {
    itbody.innerHTML = d.ips.length
      ? d.ips.map((r,i) => `<tr>
          <td style="color:#475569;width:32px">${i+1}</td>
          <td><code>${r.sender_ip}</code></td>
          <td class="num" style="font-weight:600">${Number(r.total).toLocaleString('pt-BR')}</td>
          <td class="num muted">${r.unique_accounts}</td>
        </tr>`).join('')
      : '<tr><td colspan="4" style="color:#475569;padding:16px">Sem dados de IP no período.</td></tr>';
  }
}

// ── Top destinos de bounce ────────────────────────────────────────────────────
async function loadBounceTopDest() {
  const qs   = filterQS();
  const rows = await fetch(`api.php?action=email-bounce-top-dest&${qs}`).then(r=>r.json());
  const tbody = document.getElementById('bounce-dest-tbody');
  if (!tbody) return;
  tbody.innerHTML = rows.length
    ? rows.map((r,i) => `<tr>
        <td style="color:#475569;width:32px">${i+1}</td>
        <td>${r.recipient_domain || '(desconhecido)'}</td>
        <td class="num warn">${Number(r.events).toLocaleString('pt-BR')}</td>
        <td class="num muted">${Number(r.unique_recipients).toLocaleString('pt-BR')}</td>
      </tr>`).join('')
    : '<tr><td colspan="4" style="color:#475569;padding:16px">Sem dados no período.</td></tr>';
}

// ── Lista de bounces ─────────────────────────────────────────────────────────
async function loadBounces(reset) {
  if (!HAS_BOUNCES) return;
  if (reset) bounceOffset = 0;
  const qs   = filterQS();
  const rows = await fetch(`api.php?action=bounce-list&${qs}&offset=${bounceOffset}`).then(r=>r.json());
  const tbody = document.getElementById('bounces-tbody');
  const btn   = document.getElementById('load-more-btn');
  if (!tbody) return;
  if (reset) tbody.innerHTML = '';
  if (!rows.length && bounceOffset===0) {
    tbody.innerHTML = '<tr><td colspan="3" style="color:#475569;padding:16px">Nenhum bounce no período.</td></tr>';
    if (btn) btn.style.display='none';
    return;
  }
  rows.forEach(r => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td style="color:#64748b;white-space:nowrap;font-size:.75rem">${r.logged_at}</td>
      <td><code>${r.recipient}</code></td>
      <td style="color:#94a3b8;font-size:.75rem">${(r.reason||'—').substring(0,160)}</td>`;
    tbody.appendChild(tr);
  });
  bounceOffset += rows.length;
  if (btn) btn.style.display = rows.length===50 ? 'inline-block' : 'none';
}
function loadMoreBounces() { loadBounces(false); }

// ── Motivos rejeição globais ──────────────────────────────────────────────────
async function loadRejectReasons() {
  if (!HAS_REJECTS) return;
  const qs      = filterQS();
  const reasons = await fetch(`api.php?action=email-global-reject-reasons&${qs}`).then(r=>r.json());
  const total   = reasons.reduce((s,r) => s+parseInt(r.total), 0);
  const labels  = reasons.map(r => reasonLabels[r.reason_type]||r.reason_type);
  const data    = reasons.map(r => parseInt(r.total));
  const colors  = reasons.map(r => reasonColors[r.reason_type]||'#94a3b8');

  if (reasonsChart) {
    reasonsChart.data.labels=labels; reasonsChart.data.datasets[0].data=data;
    reasonsChart.data.datasets[0].backgroundColor=colors; reasonsChart.update();
  } else {
    const el = document.getElementById('reasons-chart');
    if (el) reasonsChart = new Chart(el, {
      type:'doughnut',
      data:{ labels, datasets:[{ data, backgroundColor:colors, borderWidth:2, borderColor:'#1e293b' }] },
      options:{ responsive:true, maintainAspectRatio:false, animation:{duration:300},
        plugins:{ legend:{ position:'right', labels:{color:'#94a3b8',font:{size:11},boxWidth:14,padding:10} } } }
    });
  }

  const tbody = document.getElementById('reasons-tbody');
  if (tbody) tbody.innerHTML = reasons.length
    ? reasons.map(r => {
        const pct = total>0 ? Math.round(r.total/total*100) : 0;
        return `<tr>
          <td class="reason-${r.reason_type}">${reasonLabels[r.reason_type]||r.reason_type}</td>
          <td class="num">${Number(r.total).toLocaleString('pt-BR')}</td>
          <td class="num muted">${pct}%</td>
        </tr>`;
      }).join('')
    : '<tr><td colspan="3" style="color:#475569;padding:16px">Sem dados no período.</td></tr>';
}

// ── Top IPs atacantes globais ─────────────────────────────────────────────────
async function loadRejectIPs() {
  if (!HAS_REJECTS) return;
  const qs   = filterQS();
  const rows = await fetch(`api.php?action=email-global-reject-ips&${qs}`).then(r=>r.json());
  const tbody = document.getElementById('ips-tbody');
  if (!tbody) return;
  tbody.innerHTML = rows.length
    ? rows.map((r,i) => `<tr>
        <td style="color:#475569;width:32px">${i+1}</td>
        <td><code>${r.sender_ip}</code></td>
        <td class="num danger">${Number(r.total).toLocaleString('pt-BR')}</td>
      </tr>`).join('')
    : '<tr><td colspan="3" style="color:#475569;padding:16px">Sem dados no período.</td></tr>';
}

// ── Carrega tudo ──────────────────────────────────────────────────────────────
async function loadAll(filter) {
  currentFilter = filter;
  bounceOffset  = 0;
  await Promise.all([
    loadSummary(),
    loadTimeline(),
    loadDomains(),
    HAS_AUTH    ? loadAuth()          : Promise.resolve(),
    HAS_BOUNCES && PAGE_TYPE==='bounced' ? loadBounceTopDest() : Promise.resolve(),
    HAS_BOUNCES ? loadBounces(true)   : Promise.resolve(),
    HAS_REJECTS ? loadRejectReasons() : Promise.resolve(),
    HAS_REJECTS ? loadRejectIPs()     : Promise.resolve(),
  ]);
}

// ── Período: botões ───────────────────────────────────────────────────────────
function setActiveBtn(el) {
  document.querySelectorAll('.pbtn').forEach(b => b.classList.remove('active'));
  if (el) el.classList.add('active');
}

document.querySelectorAll('[data-d]').forEach(btn => {
  btn.addEventListener('click', function() {
    document.getElementById('custom-range').classList.remove('visible');
    setActiveBtn(this);
    const d = parseInt(this.dataset.d);
    if (this.id==='btn-today') {
      const t = todayStr();
      loadAll({ from:t, to:t });
    } else if (this.id==='btn-yesterday') {
      const y = yesterdayStr();
      loadAll({ from:y, to:y });
    } else {
      loadAll({ days:d });
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
  loadAll({ from:f, to:t });
}

<?php if ($hasData): ?>
// Inicializa com "Hoje"
(function() {
  const t = todayStr();
  loadAll({ from:t, to:t });
})();
<?php endif; ?>
</script>
</body>
</html>
