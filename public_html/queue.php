<?php
require __DIR__ . '/_bootstrap.php';

$queueFile = dirname(__DIR__) . '/private/queue_details.json';
$queue = file_exists($queueFile) ? json_decode(file_get_contents($queueFile), true) : null;

$actionsFile = dirname(__DIR__) . '/private/queue_actions.json';
$pending = file_exists($actionsFile) ? (json_decode(file_get_contents($actionsFile), true) ?: []) : [];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="refresh" content="120">
<title>Fila de E-mail — Monitor</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#0f172a;color:#e2e8f0;min-height:100vh}
header{background:#1e293b;border-bottom:1px solid #334155;padding:14px 24px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:10}
header h1{font-size:1.1rem;font-weight:600;color:#f1f5f9}header h1 span{color:#38bdf8}
.header-meta{font-size:.72rem;color:#64748b;text-align:right;line-height:1.6}
.container{max-width:1300px;margin:0 auto;padding:24px 20px}
.nav-pills{display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap}
.nav-pill{text-decoration:none;background:#1e293b;border:1px solid #334155;border-radius:8px;padding:8px 16px;font-size:.82rem;font-weight:500;color:#94a3b8;transition:all .15s}
.nav-pill:hover,.nav-pill.active{background:#334155;color:#e2e8f0;border-color:#475569}
.section-title{font-size:.68rem;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:#475569;margin-bottom:12px;margin-top:28px}
.section-title:first-child{margin-top:0}
.metrics-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px}
.card{background:#1e293b;border:1px solid #334155;border-radius:10px;padding:16px 18px}
.card-label{font-size:.65rem;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:.07em;margin-bottom:6px}
.card-value{font-size:2rem;font-weight:700;line-height:1;margin-bottom:3px}
.card-sub{font-size:.72rem;color:#94a3b8}
.actions-bar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px;align-items:center}
.btn{padding:7px 16px;border-radius:7px;border:1px solid #334155;font-size:.8rem;font-weight:500;cursor:pointer;transition:all .15s}
.btn-primary{background:#0ea5e9;border-color:#0ea5e9;color:#fff}.btn-primary:hover{background:#0284c7}
.btn-danger{background:#dc2626;border-color:#dc2626;color:#fff}.btn-danger:hover{background:#b91c1c}
.btn-ghost{background:transparent;color:#94a3b8}.btn-ghost:hover{background:#334155;color:#e2e8f0}
.table-card{background:#1e293b;border:1px solid #334155;border-radius:10px;overflow:hidden}
table{width:100%;border-collapse:collapse;font-size:.8rem}
thead{background:#0f172a}
th{padding:10px 14px;text-align:left;font-size:.65rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#475569;white-space:nowrap}
td{padding:9px 14px;border-top:1px solid #334155;vertical-align:top}
tr:hover td{background:#ffffff06}
.badge-frozen{display:inline-block;background:#7f1d1d55;color:#fca5a5;padding:2px 7px;border-radius:4px;font-size:.7rem;font-weight:600}
.badge-active{display:inline-block;background:#14532d55;color:#4ade80;padding:2px 7px;border-radius:4px;font-size:.7rem;font-weight:600}
.age-old{color:#ef4444}
.age-med{color:#f59e0b}
.age-ok{color:#94a3b8}
.msg-id{font-family:monospace;font-size:.75rem;color:#64748b}
code{background:#334155;padding:2px 6px;border-radius:4px;font-size:.75rem;word-break:break-all}
.act-btns{display:flex;gap:5px;white-space:nowrap}
.act-btn{padding:3px 9px;border-radius:5px;border:1px solid #334155;font-size:.7rem;cursor:pointer;transition:all .15s}
.act-btn-release{color:#34d399;border-color:#34d39955}.act-btn-release:hover{background:#14532d55}
.act-btn-delete{color:#f87171;border-color:#f8717155}.act-btn-delete:hover{background:#7f1d1d55}
.pending-notice{background:#1e3a4a;border:1px solid #0ea5e955;border-radius:8px;padding:10px 14px;font-size:.8rem;color:#7dd3fc;margin-bottom:14px}
.no-data{background:#1e293b;border:1px solid #334155;border-radius:10px;padding:24px;color:#475569;font-size:.85rem}
.filter-row{display:flex;gap:10px;align-items:center;margin-bottom:12px;flex-wrap:wrap}
.filter-row label{font-size:.75rem;color:#94a3b8}
input[type=text]{background:#1e293b;border:1px solid #334155;color:#e2e8f0;padding:5px 10px;border-radius:6px;font-size:.78rem;width:260px}
input[type=text]:focus{outline:none;border-color:#38bdf8}
#toast{position:fixed;bottom:24px;right:24px;background:#1e293b;border:1px solid #334155;border-radius:8px;padding:10px 16px;font-size:.82rem;color:#e2e8f0;display:none;z-index:100;box-shadow:0 4px 20px #00000066}
</style>
</head>
<body>

<header>
  <h1>Fila de <span>E-mail</span></h1>
  <div class="header-meta"><?= date('d/m/Y H:i') ?> · auto-refresh 2min</div>
</header>

<div class="container">

  <div class="nav-pills">
    <a href="index.php"      class="nav-pill">Sistema</a>
    <a href="cpu-detail.php" class="nav-pill">CPU por Conta</a>
    <a href="email.php"      class="nav-pill">E-mail</a>
    <a href="queue.php"      class="nav-pill active">Fila</a>
    <a href="uptime.php"     class="nav-pill">Uptime</a>
    <a href="security.php"   class="nav-pill">Segurança</a>
    <a href="alerts-config.php" class="nav-pill">Alertas</a>
    <a href="server-info.php"  class="nav-pill">Servidor</a>
  </div>

<?php if ($pending): ?>
  <div class="pending-notice">
    ⏳ <?= count($pending) ?> ação(ões) aguardando processamento pelo cron (próximos 5 min):
    <?php foreach ($pending as $p): ?>
      <strong><?= htmlspecialchars($p['action']) ?></strong><?= $p['id'] ? ' #'.htmlspecialchars($p['id']) : '' ?>;
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (!$queue): ?>
  <div class="no-data">Aguardando primeira coleta de fila. Execute: <code>php -d disable_functions= /root/monitor/collect.php</code></div>
<?php else:
  $total  = $queue['total'];
  $frozen = $queue['frozen'];
  $active = $total - $frozen;
  $colAt  = $queue['collected_at'];
  $msgs   = $queue['messages'] ?? [];
  $totalColor = $total > 100 ? '#ef4444' : ($total > 30 ? '#f59e0b' : '#22c55e');
  $frozenColor = $frozen > 0 ? '#f59e0b' : '#475569';
?>

  <div class="section-title">Status — dados de <?= $colAt ?></div>
  <div class="metrics-grid">
    <div class="card">
      <div class="card-label">Total</div>
      <div class="card-value" style="color:<?= $totalColor ?>"><?= $total ?></div>
      <div class="card-sub">mensagens na fila</div>
    </div>
    <div class="card">
      <div class="card-label">Congelados</div>
      <div class="card-value" style="color:<?= $frozenColor ?>"><?= $frozen ?></div>
      <div class="card-sub">requerem atenção manual</div>
    </div>
    <div class="card">
      <div class="card-label">Ativos</div>
      <div class="card-value" style="color:#22c55e"><?= $active ?></div>
      <div class="card-sub">tentando entrega</div>
    </div>
  </div>

  <?php if ($total > 0): ?>
  <div class="section-title">Ações em massa</div>
  <div class="actions-bar">
    <button class="btn btn-primary" onclick="bulkAction('release_all')">▶ Forçar re-envio de todos</button>
    <?php if ($frozen > 0): ?>
    <button class="btn btn-danger" onclick="bulkAction('delete_frozen')">✕ Deletar congelados (<?= $frozen ?>)</button>
    <?php endif; ?>
    <span style="font-size:.75rem;color:#475569">Ações são processadas em até 5 min pelo cron</span>
  </div>

  <div class="section-title">Mensagens na fila</div>

  <div class="filter-row">
    <label>Filtrar:</label>
    <input type="text" id="filter-input" placeholder="remetente, destinatário ou ID..." oninput="filterTable()">
  </div>

  <div class="table-card">
    <table>
      <thead>
        <tr>
          <th>ID / Hora</th>
          <th>Remetente</th>
          <th>Destinatário(s)</th>
          <th>Assunto</th>
          <th>Idade</th>
          <th>Status</th>
          <th>Ações</th>
        </tr>
      </thead>
      <tbody id="queue-tbody">
        <?php foreach ($msgs as $m):
          $ageH   = intdiv($m['age_minutes'], 60);
          $ageM   = $m['age_minutes'] % 60;
          $ageStr = $ageH > 0 ? "{$ageH}h {$ageM}m" : "{$m['age_minutes']}m";
          $ageCls = $m['age_minutes'] > 1440 ? 'age-old' : ($m['age_minutes'] > 120 ? 'age-med' : 'age-ok');
          $rcpts  = implode('<br>', array_map('htmlspecialchars', $m['recipients']));
          $frozen = $m['frozen'];
        ?>
        <tr data-search="<?= htmlspecialchars(strtolower($m['sender'].' '.implode(' ',$m['recipients']).' '.$m['id'])) ?>">
          <td>
            <div class="msg-id"><?= htmlspecialchars($m['id']) ?></div>
            <div style="font-size:.7rem;color:#475569"><?= htmlspecialchars($m['added_at']) ?></div>
          </td>
          <td><code><?= htmlspecialchars($m['sender']) ?></code></td>
          <td style="font-size:.75rem"><?= $rcpts ?></td>
          <td style="font-size:.75rem;color:#94a3b8;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($m['subject']) ?></td>
          <td class="<?= $ageCls ?>" style="white-space:nowrap"><?= $ageStr ?></td>
          <td><?= $frozen ? '<span class="badge-frozen">congelado</span>' : '<span class="badge-active">ativo</span>' ?></td>
          <td>
            <div class="act-btns">
              <button class="act-btn act-btn-release" onclick="msgAction('release','<?= htmlspecialchars($m['id']) ?>')">▶</button>
              <button class="act-btn act-btn-delete"  onclick="msgAction('delete','<?= htmlspecialchars($m['id']) ?>')">✕</button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
  <div class="no-data" style="margin-top:20px">Fila vazia — nenhuma mensagem aguardando.</div>
  <?php endif; ?>

<?php endif; ?>
</div>

<div id="toast"></div>

<script>
function showToast(msg, ok = true) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.style.display = 'block';
  t.style.borderColor = ok ? '#334155' : '#b91c1c';
  clearTimeout(t._to);
  t._to = setTimeout(() => t.style.display = 'none', 3500);
}

async function msgAction(action, id) {
  const label = action === 'release' ? 'Re-enviar' : 'Deletar';
  if (action === 'delete' && !confirm(`Deletar mensagem ${id}?`)) return;
  try {
    const res = await fetch('api.php?action=queue-action', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action, id }),
    });
    const data = await res.json();
    if (data.ok) showToast(`${label} agendado — processado em até 5 min.`);
    else showToast('Erro ao agendar ação.', false);
  } catch(e) { showToast('Erro de comunicação.', false); }
}

async function bulkAction(action) {
  const labels = { release_all: 'Forçar re-envio de todos', delete_frozen: 'Deletar mensagens congeladas' };
  if (!confirm(`${labels[action]}?`)) return;
  try {
    const res = await fetch('api.php?action=queue-action', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action, id: '' }),
    });
    const data = await res.json();
    if (data.ok) showToast(`${labels[action]} agendado — processado em até 5 min.`);
    else showToast('Erro ao agendar ação.', false);
  } catch(e) { showToast('Erro de comunicação.', false); }
}

function filterTable() {
  const q = document.getElementById('filter-input').value.toLowerCase();
  document.querySelectorAll('#queue-tbody tr').forEach(tr => {
    tr.style.display = !q || tr.dataset.search.includes(q) ? '' : 'none';
  });
}
</script>
</body>
</html>
