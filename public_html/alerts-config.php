<?php
require __DIR__ . '/_bootstrap.php';

$priv   = dirname(__DIR__) . '/private';
$cfgF   = $priv . '/alert_config.json';
$silF   = $priv . '/alert_silences.json';
$stateF = $priv . '/alert_state.json';

function acCfg(string $f): array {
    $def = [
        'recipients' => [defined('ALERT_TO') ? ALERT_TO : 'admin@seudominio.com'],
        'thresholds' => ['cpu'=>85,'ram'=>90,'disk'=>88,'queue'=>100,'load'=>14,'susp_sends'=>30],
        'cooldowns'  => ['cpu'=>60,'ram'=>60,'disk'=>120,'service'=>30,'site'=>30,'queue'=>60,'suspicious'=>240,'load'=>60],
    ];
    if (!file_exists($f)) return $def;
    $j = json_decode(file_get_contents($f), true) ?: [];
    return [
        'recipients' => $j['recipients'] ?? $def['recipients'],
        'thresholds' => array_merge($def['thresholds'], $j['thresholds'] ?? []),
        'cooldowns'  => array_merge($def['cooldowns'],  $j['cooldowns']  ?? []),
    ];
}

function acActive(array $s): bool {
    if (($s['until'] ?? '') === 'forever') return true;
    return !empty($s['until']) && strtotime($s['until']) > time();
}

function acLoadSil(string $f): array {
    return file_exists($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
}

$msg = ''; $msgType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    if ($act === 'save_config') {
        $cfg = acCfg($cfgF);
        $raw = str_replace([',', ';'], "\n", $_POST['recipients'] ?? '');
        $arr = array_values(array_filter(
            array_map('trim', explode("\n", $raw)),
            fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)
        ));
        if ($arr) $cfg['recipients'] = $arr;
        foreach (['cpu', 'ram', 'disk', 'queue', 'load', 'susp_sends'] as $k)
            if (isset($_POST["thr_$k"]) && is_numeric($_POST["thr_$k"]))
                $cfg['thresholds'][$k] = (float)$_POST["thr_$k"];
        foreach (['cpu', 'ram', 'disk', 'service', 'site', 'queue', 'suspicious', 'load'] as $k)
            if (isset($_POST["cool_$k"]) && is_numeric($_POST["cool_$k"]))
                $cfg['cooldowns'][$k] = (int)$_POST["cool_$k"];
        file_put_contents($cfgF, json_encode($cfg, JSON_PRETTY_PRINT));
        $msg = 'Configurações salvas.';

    } elseif ($act === 'add_silence') {
        $key    = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_POST['silence_key'] ?? '');
        $dur    = $_POST['duration'] ?? 'forever';
        $reason = trim($_POST['reason'] ?? '');
        if (!$key || !$reason) {
            $msg = 'Preencha a chave e a justificativa.'; $msgType = 'err';
        } else {
            $ss = acLoadSil($silF);
            $ss = array_values(array_filter($ss, fn($s) => ($s['key'] ?? '') !== $key));
            $until = $dur === 'forever' ? 'forever' : date('Y-m-d H:i:s', time() + (int)$dur * 3600);
            $ss[] = ['key' => $key, 'until' => $until, 'reason' => $reason, 'created_at' => date('Y-m-d H:i:s')];
            file_put_contents($silF, json_encode($ss, JSON_PRETTY_PRINT));
            $msg = 'Alerta silenciado.';
        }

    } elseif ($act === 'remove_silence') {
        $key = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_POST['key'] ?? '');
        $ss  = acLoadSil($silF);
        $ss  = array_values(array_filter($ss, fn($s) => ($s['key'] ?? '') !== $key));
        file_put_contents($silF, json_encode($ss, JSON_PRETTY_PRINT));
        $msg = 'Silenciamento removido.';
    }
}

$cfg      = acCfg($cfgF);
$silences = acLoadSil($silF);
$state    = file_exists($stateF) ? (json_decode(file_get_contents($stateF), true) ?: []) : [];
$active   = array_values(array_filter($silences, 'acActive'));
$silKeys  = array_column($active, 'key');

// ── DB lookups to enrich display ──────────────────────────────────────────────
$siteMap = [];  // md5(domain) => incident row
$suspMap = [];  // md5(account.ip) => auth event row
try {
    $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
        DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    foreach ($pdo->query("
        SELECT domain, last_status_code, started_at, resolved_at,
               TIMESTAMPDIFF(MINUTE, started_at, COALESCE(resolved_at, NOW())) AS ago_min
        FROM site_incidents ORDER BY id DESC
    ")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $h = md5($r['domain']);
        if (!isset($siteMap[$h])) $siteMap[$h] = $r;
    }

    foreach ($pdo->query("
        SELECT account, sender_ip, send_count, event_date
        FROM email_auth_events
        WHERE event_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        ORDER BY id DESC
    ")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $h = md5($r['account'].$r['sender_ip']);
        if (!isset($suspMap[$h])) $suspMap[$h] = $r;
    }
} catch (Exception $e) {}

// ── Enrich each state entry with human-readable label + detail ────────────────
$svcNames = ['nginx'=>'Nginx','apache2'=>'Apache','mariadb'=>'MariaDB','exim4'=>'Exim',
             'dovecot'=>'Dovecot','clamav-daemon'=>'ClamAV','hestia'=>'HestiaCP'];

$knownLabels = [
    'cpu_high'   => 'CPU Alta',
    'ram_high'   => 'RAM Alta',
    'disk_high'  => 'Disco Alto',
    'load_high'  => 'Load Alto',
    'queue_high' => 'Fila de E-mail Alta',
];

$enriched = [];
foreach ($state as $key => $entry) {
    // Handle both old format (int timestamp) and new format (array)
    $ts     = is_array($entry) ? (int)($entry['ts']    ?? 0) : (int)$entry;
    $stored = is_array($entry) ? ($entry['label'] ?? '') : '';
    $label  = $stored;
    $detail = '';

    if (str_starts_with($key, 'site_')) {
        $hash = substr($key, 5);
        if (isset($siteMap[$hash])) {
            $d    = $siteMap[$hash];
            $code = $d['last_status_code'] ?: 'timeout';
            $ago  = (int)$d['ago_min'];
            if (!$label) $label = "Site FORA: {$d['domain']}";
            $status = $d['resolved_at'] ? 'resolvido' : 'fora';
            $detail = "{$d['domain']} · {$status} há {$ago} min · HTTP {$code} · desde " . date('d/m H:i', strtotime($d['started_at']));
        } else {
            if (!$label) $label = $key;
        }

    } elseif (str_starts_with($key, 'susp_')) {
        $hash = substr($key, 5);
        if (isset($suspMap[$hash])) {
            $s = $suspMap[$hash];
            if (!$label) $label = "E-mail suspeito: {$s['account']}";
            $detail = "IP {$s['sender_ip']} · {$s['send_count']} envios em {$s['event_date']} · possível conta comprometida";
        } else {
            if (!$label) $label = $key;
        }

    } elseif (str_starts_with($key, 'svc_')) {
        $svc  = substr($key, 4);
        $name = $svcNames[$svc] ?? $svc;
        if (!$label) $label = "Serviço FORA: {$name}";
        $detail = "O serviço {$name} estava inativo no servidor";

    } else {
        if (!$label) $label = $knownLabels[$key] ?? $key;
    }

    $enriched[$key] = ['label' => $label, 'detail' => $detail, 'ts' => $ts];
}

// Exibir apenas alertas disparados nas últimas 24h
$enriched = array_filter($enriched, fn($e) => $e['ts'] >= time() - 86400);

uasort($enriched, fn($a, $b) => strcmp($a['label'], $b['label']));

// ── Config metadata ────────────────────────────────────────────────────────────
$thrMeta = [
    'cpu'        => ['CPU alta',         '%',      'Média das últimas 3 coletas'],
    'ram'        => ['RAM alta',          '%',      'Snapshot atual do sistema'],
    'disk'       => ['Disco alto',        '%',      'Partição raiz /'],
    'queue'      => ['Fila de e-mail',    'msgs',   'Total de mensagens na fila Exim'],
    'load'       => ['Load do servidor',  '',       'Load average 1 min (servidor tem 12 CPUs)'],
    'susp_sends' => ['Envios suspeitos',  'e-mails','IP nunca visto com X envios em 2 dias'],
];

$coolMeta = [
    'cpu'        => 'CPU',
    'ram'        => 'RAM',
    'disk'       => 'Disco',
    'service'    => 'Serviço down',
    'site'       => 'Site down',
    'queue'      => 'Fila de e-mail',
    'suspicious' => 'E-mail suspeito',
    'load'       => 'Load',
];

// Build label map for active silences display (key => label from enriched)
$enrichedLabels = array_map(fn($e) => $e['label'], $enriched);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Configuração de Alertas — Monitor</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#0f172a;color:#e2e8f0;min-height:100vh}
header{background:#1e293b;border-bottom:1px solid #334155;padding:14px 24px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:10}
header h1{font-size:1.1rem;font-weight:600;color:#f1f5f9}
header h1 span{color:#f59e0b}
.container{max-width:1060px;margin:0 auto;padding:24px 20px}
.nav{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:24px}
.nav a{font-size:.82rem;font-weight:500;padding:7px 14px;border-radius:8px;border:1px solid #334155;background:#1e293b;color:#94a3b8;text-decoration:none;transition:all .15s}
.nav a:hover{color:#e2e8f0;border-color:#64748b}
.nav a.active{background:#334155;border-color:#475569;color:#e2e8f0}
.flash{padding:12px 16px;border-radius:8px;margin-bottom:20px;font-size:.85rem;font-weight:500}
.flash.ok{background:#14532d33;border:1px solid #16a34a55;color:#4ade80}
.flash.err{background:#7f1d1d33;border:1px solid #dc262655;color:#f87171}
.sec-label{font-size:.68rem;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:#475569;margin-bottom:10px;margin-top:28px}
.sec-label:first-child{margin-top:0}
.card{background:#1e293b;border:1px solid #334155;border-radius:10px;overflow:hidden;margin-bottom:16px}
.card-head{padding:14px 18px;border-bottom:1px solid #334155;display:flex;align-items:center;justify-content:space-between}
.card-head h3{font-size:.88rem;font-weight:600;color:#cbd5e1}
.card-body{padding:18px}
table{width:100%;border-collapse:collapse;font-size:.82rem}
thead{background:#0f172a}
th{padding:9px 14px;text-align:left;font-size:.68rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#475569}
td{padding:10px 14px;border-top:1px solid #1e293b66;vertical-align:middle}
.silence-tbl td{vertical-align:top;padding-top:12px;padding-bottom:12px}
tr:hover td{background:#ffffff05}
.badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:.7rem;font-weight:600}
.badge-red{background:#7f1d1d55;color:#f87171}
.badge-yellow{background:#78350f55;color:#fbbf24}
.badge-green{background:#14532d55;color:#4ade80}
.badge-orange{background:#7c2d1255;color:#fb923c}
.btn{display:inline-flex;align-items:center;gap:5px;padding:7px 14px;border-radius:6px;border:none;font-size:.78rem;font-weight:500;cursor:pointer;transition:all .15s;text-decoration:none}
.btn-danger{background:#7f1d1d55;color:#f87171;border:1px solid #dc262655}.btn-danger:hover{background:#7f1d1d88}
.btn-primary{background:#0369a155;color:#38bdf8;border:1px solid #0369a166}.btn-primary:hover{background:#0369a188}
.btn-sm{padding:4px 10px;font-size:.72rem}
.empty-state{padding:24px;text-align:center;color:#475569;font-size:.82rem}
.silence-key{font-family:monospace;font-size:.68rem;color:#4b5563;margin-top:3px}
.reason-text{font-size:.8rem;color:#cbd5e1;line-height:1.45}
.alert-label{font-size:.85rem;font-weight:600;color:#e2e8f0}
.alert-detail{font-size:.75rem;color:#64748b;margin-top:3px;line-height:1.4}
.sel-row{cursor:pointer;transition:background .1s}
.sel-row:hover td{background:#0f172a88!important}
.sel-row.selected td{background:#0c2a1a!important}
.sel-row.selected{border-left:3px solid #4ade80}
.sel-row.silenced .alert-label{opacity:.6}
.add-form{background:#0f172a;border-top:1px solid #334155;padding:20px}
.add-form h4{font-size:.82rem;font-weight:600;color:#94a3b8;margin-bottom:14px}
.sel-banner{background:#14532d33;border:1px solid #4ade8044;border-radius:7px;padding:9px 14px;margin-bottom:14px;font-size:.82rem;color:#4ade80;display:none}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}
.form-row.wide{grid-template-columns:1fr}
@media(max-width:640px){.form-row{grid-template-columns:1fr}}
.field-group{display:flex;flex-direction:column;gap:5px}
.field-label{font-size:.72rem;font-weight:600;color:#94a3b8;letter-spacing:.04em}
input[type=text],input[type=number],textarea,select{background:#1e293b;border:1px solid #334155;color:#e2e8f0;padding:8px 12px;border-radius:7px;font-size:.82rem;width:100%;font-family:inherit}
textarea{resize:vertical;min-height:68px}
input:focus,textarea:focus,select:focus{outline:none;border-color:#38bdf8}
.grid-thr{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;margin-bottom:4px}
.thr-item{background:#0f172a;border:1px solid #1e293b;border-radius:8px;padding:12px 14px}
.thr-item label{font-size:.72rem;font-weight:600;color:#94a3b8;display:block;margin-bottom:7px}
.thr-item small{font-size:.67rem;color:#475569;display:block;margin-top:5px;line-height:1.4}
.thr-input-row{display:flex;align-items:center;gap:8px}
.thr-input-row input{max-width:100px;width:100px}
.thr-input-row span{font-size:.75rem;color:#64748b}
.sel-btn{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:5px;font-size:.72rem;font-weight:500;background:#0369a122;color:#38bdf8;border:1px solid #0369a133;cursor:pointer;white-space:nowrap}
@media(max-width:680px){
  header{padding:10px 12px}
  .container{padding:14px 10px}
  .nav-pills{flex-wrap:nowrap;overflow-x:auto;padding-bottom:6px;scrollbar-width:none;-webkit-overflow-scrolling:touch}
  .nav-pills::-webkit-scrollbar{display:none}
  .nav-pill{flex-shrink:0;white-space:nowrap}
  .grid-thr{grid-template-columns:1fr!important}
  .form-group input,.form-group textarea,.form-group select{width:100%}
}
</style>
</head>
<body>
<header>
  <h1>⚙ Configuração de <span>Alertas</span></h1>
  <div style="font-size:.72rem;color:#64748b"><?= date('d/m/Y H:i') ?></div>
</header>

<div class="container">

<nav class="nav">
  <a href="index.php">Sistema</a>
  <a href="cpu-detail.php">CPU por Conta</a>
  <a href="email.php">E-mail</a>
  <a href="queue.php">Fila</a>
  <a href="uptime.php">Uptime</a>
  <a href="security.php">Segurança</a>
  <a href="alerts-config.php" class="active">Alertas</a>
  <a href="server-info.php">Servidor</a>
</nav>

<?php if ($msg): ?>
<div class="flash <?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<!-- ══ SILENCIAMENTOS ATIVOS ═══════════════════════════════════════════════ -->
<div class="sec-label">Silenciamentos ativos</div>

<div class="card">
  <?php if (!$active): ?>
    <div class="empty-state">Nenhum alerta silenciado no momento.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th>Alerta</th>
      <th>Justificativa</th>
      <th>Expira</th>
      <th>Adicionado</th>
      <th></th>
    </tr></thead>
    <tbody class="silence-tbl">
    <?php foreach ($active as $s):
      $silLabel = $enrichedLabels[$s['key']] ?? ($s['key']);
    ?>
    <tr>
      <td>
        <div class="alert-label"><?= htmlspecialchars($silLabel) ?></div>
        <div class="silence-key"><?= htmlspecialchars($s['key']) ?></div>
      </td>
      <td><div class="reason-text"><?= htmlspecialchars($s['reason']) ?></div></td>
      <td>
        <?php if (($s['until'] ?? '') === 'forever'): ?>
          <span class="badge badge-red">Permanente</span>
        <?php else: ?>
          <div style="font-size:.78rem;color:#94a3b8"><?= date('d/m H:i', strtotime($s['until'])) ?></div>
          <div style="font-size:.68rem;color:#475569">
            <?php
              $rem = strtotime($s['until']) - time();
              if ($rem < 3600) echo round($rem/60).' min restantes';
              elseif ($rem < 86400) echo round($rem/3600).'h restantes';
              else echo round($rem/86400).'d restantes';
            ?>
          </div>
        <?php endif; ?>
      </td>
      <td style="font-size:.75rem;color:#475569"><?= date('d/m H:i', strtotime($s['created_at'])) ?></td>
      <td>
        <form method="post" style="display:inline">
          <input type="hidden" name="action" value="remove_silence">
          <input type="hidden" name="key" value="<?= htmlspecialchars($s['key']) ?>">
          <button type="submit" class="btn btn-danger btn-sm">Remover</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<!-- ══ ADICIONAR SILENCIAMENTO ════════════════════════════════════════════ -->
<div class="sec-label">Adicionar silenciamento</div>

<div class="card">
  <div class="card-head">
    <h3>Alertas disparados — clique em uma linha para silenciar</h3>
    <span style="font-size:.72rem;color:#475569"><?= count($enriched) ?> registros</span>
  </div>

  <?php if (!$enriched): ?>
    <div class="empty-state">
      Nenhum alerta disparado ainda. O sistema de alertas precisa ter rodado ao menos uma vez.<br>
      <code style="font-size:.72rem;background:#334155;padding:2px 8px;border-radius:4px;margin-top:8px;display:inline-block">
        php -d disable_functions= /root/monitor/alerts.php
      </code>
    </div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th style="width:45%">Alerta / Detalhe</th>
      <th>Último disparo</th>
      <th>Status</th>
      <th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($enriched as $key => $e):
      $isSil = in_array($key, $silKeys, true);
      $ago   = $e['ts'] ? round((time() - $e['ts']) / 60) : null;
    ?>
    <tr class="sel-row <?= $isSil ? 'silenced' : '' ?>"
        data-key="<?= htmlspecialchars($key) ?>"
        data-label="<?= htmlspecialchars($e['label']) ?>">
      <td>
        <div class="alert-label"><?= htmlspecialchars($e['label']) ?></div>
        <?php if ($e['detail']): ?>
          <div class="alert-detail"><?= htmlspecialchars($e['detail']) ?></div>
        <?php endif; ?>
      </td>
      <td style="font-size:.78rem;color:#94a3b8;white-space:nowrap">
        <?php if ($ago !== null): ?>
          <?= $ago < 60 ? "há {$ago} min" : "há ".round($ago/60)."h" ?>
        <?php else: ?>—<?php endif; ?>
      </td>
      <td>
        <?php if ($isSil): ?>
          <span class="badge badge-yellow">Silenciado</span>
        <?php else: ?>
          <span class="badge badge-orange">Disparado</span>
        <?php endif; ?>
      </td>
      <td><span class="sel-btn">Silenciar →</span></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <div class="add-form" id="add-form">
    <div class="sel-banner" id="sel-banner"></div>
    <h4 id="add-form-title">Silenciar alerta</h4>
    <form method="post">
      <input type="hidden" name="action" value="add_silence">
      <div class="form-row">
        <div class="field-group">
          <label class="field-label">Chave do alerta</label>
          <input type="text" name="silence_key" id="sil-key"
            placeholder="clique em uma linha acima" required>
        </div>
        <div class="field-group">
          <label class="field-label">Duração do silenciamento</label>
          <select name="duration">
            <option value="1">1 hora</option>
            <option value="6">6 horas</option>
            <option value="24">24 horas</option>
            <option value="48">48 horas</option>
            <option value="168">7 dias</option>
            <option value="720">30 dias</option>
            <option value="forever" selected>Permanente</option>
          </select>
        </div>
      </div>
      <div class="form-row wide">
        <div class="field-group">
          <label class="field-label">Justificativa <span style="color:#f87171">*</span></label>
          <textarea name="reason" id="sil-reason"
            placeholder="Ex: lojas99.com.br fora do ar — cliente informado, aguardando renovação. Não é problema do servidor."
            required></textarea>
        </div>
      </div>
      <button type="submit" class="btn btn-primary">Silenciar alerta</button>
    </form>
  </div>
</div>

<!-- ══ CONFIGURAÇÕES ══════════════════════════════════════════════════════ -->
<div class="sec-label">Configurações de alertas</div>

<form method="post">
<input type="hidden" name="action" value="save_config">

<div class="card" style="margin-bottom:14px">
  <div class="card-head"><h3>Destinatários dos alertas</h3></div>
  <div class="card-body">
    <div class="field-group">
      <label class="field-label">E-mails (um por linha ou separados por vírgula)</label>
      <textarea name="recipients" rows="3"><?= htmlspecialchars(implode("\n", $cfg['recipients'])) ?></textarea>
    </div>
  </div>
</div>

<div class="card" style="margin-bottom:14px">
  <div class="card-head"><h3>Limites para disparo (thresholds)</h3></div>
  <div class="card-body">
    <div class="grid-thr">
    <?php foreach ($thrMeta as $k => [$name, $unit, $hint]): ?>
      <div class="thr-item">
        <label><?= htmlspecialchars($name) ?></label>
        <div class="thr-input-row">
          <input type="number" name="thr_<?= $k ?>"
            value="<?= $cfg['thresholds'][$k] ?>"
            step="<?= in_array($k, ['cpu', 'load']) ? '0.5' : '1' ?>"
            min="0">
          <?php if ($unit): ?><span><?= htmlspecialchars($unit) ?></span><?php endif; ?>
        </div>
        <small><?= htmlspecialchars($hint) ?></small>
      </div>
    <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="card" style="margin-bottom:24px">
  <div class="card-head"><h3>Cooldown — intervalo mínimo entre alertas do mesmo tipo</h3></div>
  <div class="card-body">
    <div class="grid-thr">
    <?php foreach ($coolMeta as $k => $name): ?>
      <div class="thr-item">
        <label><?= htmlspecialchars($name) ?></label>
        <div class="thr-input-row">
          <input type="number" name="cool_<?= $k ?>" value="<?= $cfg['cooldowns'][$k] ?>" min="1">
          <span>min</span>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
    <p style="font-size:.72rem;color:#475569;margin-top:10px;line-height:1.5">
      Tempo mínimo (em minutos) entre dois disparos consecutivos do mesmo alerta.
      Se o alerta continuar ativo após o cooldown, um novo e-mail é enviado.
    </p>
  </div>
</div>

<button type="submit" class="btn btn-primary" style="padding:10px 24px;font-size:.85rem">
  Salvar configurações
</button>
</form>

</div><!-- /container -->

<script>
document.querySelectorAll('.sel-row').forEach(function(row) {
  row.addEventListener('click', function() {
    var key   = this.dataset.key;
    var label = this.dataset.label;

    document.querySelectorAll('.sel-row').forEach(function(r) {
      r.classList.remove('selected');
    });
    this.classList.add('selected');

    document.getElementById('sil-key').value = key;

    var banner = document.getElementById('sel-banner');
    banner.textContent = 'Silenciando: ' + label;
    banner.style.display = 'block';

    document.getElementById('add-form').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    setTimeout(function() {
      document.getElementById('sil-reason').focus();
    }, 400);
  });
});
</script>
</body>
</html>
