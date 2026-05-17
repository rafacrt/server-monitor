<?php
header('Content-Type: application/json');
require __DIR__ . '/_bootstrap.php';

$action = $_GET['action'] ?? 'chart';

try {
    $pdo = new PDO(
        'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    echo json_encode(['error' => true]);
    exit;
}

// ── Gráfico global CPU/RAM (legado) ─────────────────────────────────────────
if ($action === 'chart') {
    $metric = $_GET['metric'] ?? 'cpu';
    $hours  = min(max((int)($_GET['hours'] ?? 24), 1), 168);
    $stmt   = $pdo->prepare("
        SELECT DATE_FORMAT(collected_at,'%d/%m %H:%i') AS label,
               AVG(cpu_percent) AS cpu, AVG(load_1m) AS load1,
               AVG(ram_used_mb) AS ru, MAX(ram_total_mb) AS rt
        FROM system_metrics
        WHERE collected_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
        GROUP BY DATE_FORMAT(collected_at,'%Y-%m-%d %H:%i')
        ORDER BY MIN(collected_at)
    ");
    $stmt->execute([$hours]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $labels = []; $values = [];
    foreach ($rows as $r) {
        $labels[] = $r['label'];
        $values[] = match($metric) {
            'ram'  => $r['rt'] > 0 ? round($r['ru']/$r['rt']*100,1) : 0,
            'load' => round($r['load1'],2),
            default=> round($r['cpu'],1),
        };
    }
    echo json_encode(['labels'=>$labels,'values'=>$values]);
    exit;
}

// ── CPU: top contas por período ──────────────────────────────────────────────
if ($action === 'cpu-accounts') {
    $hours = min(max((int)($_GET['hours'] ?? 24), 1), 720);
    $stmt  = $pdo->prepare("
        SELECT account,
               ROUND(AVG(cpu_pct),1)  AS avg_cpu,
               ROUND(MAX(cpu_pct),1)  AS max_cpu,
               MAX(proc_count)        AS procs
        FROM cpu_by_account
        WHERE collected_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
        GROUP BY account
        ORDER BY avg_cpu DESC
        LIMIT 15
    ");
    $stmt->execute([$hours]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ── CPU: timeline de uma conta ───────────────────────────────────────────────
if ($action === 'cpu-timeline') {
    $hours   = min(max((int)($_GET['hours'] ?? 24), 1), 720);
    $account = $_GET['account'] ?? '';
    if (!preg_match('/^[\w.-]+$/', $account)) { echo json_encode([]); exit; }
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(collected_at,'%d/%m %H:%i') AS label,
               ROUND(AVG(cpu_pct),1) AS cpu
        FROM cpu_by_account
        WHERE collected_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
          AND account = ?
        GROUP BY DATE_FORMAT(collected_at,'%Y-%m-%d %H:%i')
        ORDER BY MIN(collected_at)
    ");
    $stmt->execute([$hours, $account]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode([
        'labels' => array_column($rows,'label'),
        'values' => array_column($rows,'cpu'),
    ]);
    exit;
}

// ── CPU: timeline top 5 contas juntos ────────────────────────────────────────
if ($action === 'cpu-top5') {
    $hours = min(max((int)($_GET['hours'] ?? 24), 1), 720);
    // buscar top 5 contas
    $top = $pdo->prepare("
        SELECT account FROM cpu_by_account
        WHERE collected_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
        GROUP BY account ORDER BY AVG(cpu_pct) DESC LIMIT 5
    ");
    $top->execute([$hours]);
    $accounts = array_column($top->fetchAll(PDO::FETCH_ASSOC), 'account');
    if (!$accounts) { echo json_encode(['labels'=>[],'datasets'=>[]]); exit; }

    $placeholders = implode(',', array_fill(0, count($accounts), '?'));
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(collected_at,'%d/%m %H:%i') AS label,
               account, ROUND(AVG(cpu_pct),1) AS cpu
        FROM cpu_by_account
        WHERE collected_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
          AND account IN ($placeholders)
        GROUP BY DATE_FORMAT(collected_at,'%Y-%m-%d %H:%i'), account
        ORDER BY MIN(collected_at)
    ");
    $stmt->execute(array_merge([$hours], $accounts));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $labels = []; $byAccount = [];
    foreach ($rows as $r) {
        if (!in_array($r['label'], $labels)) $labels[] = $r['label'];
        $byAccount[$r['account']][$r['label']] = (float)$r['cpu'];
    }
    $colors = ['#38bdf8','#818cf8','#34d399','#f59e0b','#f87171'];
    $datasets = [];
    foreach ($accounts as $i => $acc) {
        $data = array_map(fn($l) => $byAccount[$acc][$l] ?? null, $labels);
        $datasets[] = [
            'label'           => $acc,
            'data'            => $data,
            'borderColor'     => $colors[$i % count($colors)],
            'backgroundColor' => ($colors[$i % count($colors)]).'33',
            'borderWidth'     => 2,
            'pointRadius'     => 0,
            'fill'            => false,
            'tension'         => 0.3,
            'spanGaps'        => true,
        ];
    }
    echo json_encode(['labels'=>$labels,'datasets'=>$datasets]);
    exit;
}

// ── Email: resumo do dia ─────────────────────────────────────────────────────
if ($action === 'email-summary') {
    $today = date('Y-m-d');
    $row = $pdo->query("
        SELECT COALESCE(SUM(received),0) AS received,
               COALESCE(SUM(sent),0)     AS sent,
               COALESCE(SUM(bounced),0)  AS bounced,
               COALESCE(SUM(rejected),0) AS rejected
        FROM email_stats_daily WHERE stat_date = '$today'
    ")->fetch(PDO::FETCH_ASSOC);
    $queue = $pdo->query("SELECT queue_count FROM email_queue_snap ORDER BY id DESC LIMIT 1")->fetchColumn();
    $row['queue'] = (int)$queue;
    echo json_encode($row);
    exit;
}

// ── Email: gráfico diário enviados/recebidos ─────────────────────────────────
if ($action === 'email-chart') {
    $days = min(max((int)($_GET['days'] ?? 30), 7), 90);
    $stmt = $pdo->prepare("
        SELECT stat_date AS label,
               SUM(received) AS received,
               SUM(sent)     AS sent,
               SUM(bounced)  AS bounced,
               SUM(rejected) AS rejected
        FROM email_stats_daily
        WHERE stat_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
        GROUP BY stat_date
        ORDER BY stat_date
    ");
    $stmt->execute([$days]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode([
        'labels'   => array_column($rows,'label'),
        'received' => array_column($rows,'received'),
        'sent'     => array_column($rows,'sent'),
        'bounced'  => array_column($rows,'bounced'),
        'rejected' => array_column($rows,'rejected'),
    ]);
    exit;
}

// ── Email: tabela por domínio ─────────────────────────────────────────────────
if ($action === 'email-domains') {
    $sort = in_array($_GET['sort']??'', ['received','sent','bounced','rejected']) ? $_GET['sort'] : 'received';
    $dp = []; $dc = dateRange($_GET, 'stat_date', $dp);
    $stmt = $pdo->prepare("
        SELECT domain,
               SUM(received) AS received,
               SUM(sent)     AS sent,
               SUM(bounced)  AS bounced,
               SUM(rejected) AS rejected,
               SUM(received+sent+bounced+rejected) AS total
        FROM email_stats_daily
        WHERE $dc
        GROUP BY domain
        ORDER BY $sort DESC
        LIMIT 50
    ");
    $stmt->execute($dp);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ── Email: histórico da fila ──────────────────────────────────────────────────
if ($action === 'email-queue') {
    $hours = min(max((int)($_GET['hours'] ?? 24), 1), 168);
    $stmt  = $pdo->prepare("
        SELECT DATE_FORMAT(collected_at,'%d/%m %H:%i') AS label, queue_count AS v
        FROM email_queue_snap
        WHERE collected_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
        ORDER BY collected_at
    ");
    $stmt->execute([$hours]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode([
        'labels' => array_column($rows,'label'),
        'values' => array_column($rows,'v'),
    ]);
    exit;
}

// Helper: cláusula de data com suporte a from/to ou days
function dateRange($get, $col, &$params) {
    $f = $get['from'] ?? ''; $t = $get['to'] ?? '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) && $f <= $t) {
        $params[] = $f; $params[] = $t;
        return "$col BETWEEN ? AND ?";
    }
    $days = min(max((int)($get['days'] ?? 7), 1), 90);
    $params[] = $days;
    return "$col >= DATE_SUB(CURDATE(), INTERVAL ? DAY)";
}

// ── Email: resumo por domínio (ou global se sem domain) ──────────────────────
if ($action === 'email-domain-summary') {
    $domain = preg_replace('/[^a-zA-Z0-9\-\.]/', '', $_GET['domain'] ?? '');
    $dp = []; $dc = dateRange($_GET, 'stat_date', $dp);
    if ($domain) {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(received),0) AS received, COALESCE(SUM(sent),0) AS sent,
                   COALESCE(SUM(bounced),0) AS bounced,  COALESCE(SUM(rejected),0) AS rejected
            FROM email_stats_daily
            WHERE domain = ? AND $dc
        ");
        $stmt->execute(array_merge([$domain], $dp));
    } else {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(received),0) AS received, COALESCE(SUM(sent),0) AS sent,
                   COALESCE(SUM(bounced),0) AS bounced,  COALESCE(SUM(rejected),0) AS rejected
            FROM email_stats_daily
            WHERE $dc
        ");
        $stmt->execute($dp);
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$domain) {
        $row['queue'] = (int)$pdo->query("SELECT queue_count FROM email_queue_snap ORDER BY id DESC LIMIT 1")->fetchColumn();
    }
    echo json_encode($row);
    exit;
}

// ── Email: timeline de um domínio ────────────────────────────────────────────
if ($action === 'email-timeline') {
    $domain = preg_replace('/[^a-zA-Z0-9\-\.]/', '', $_GET['domain'] ?? '');
    if (!$domain) { echo json_encode([]); exit; }
    $dp = []; $dc = dateRange($_GET, 'stat_date', $dp);
    $stmt = $pdo->prepare("
        SELECT stat_date AS label, received, sent, bounced, rejected
        FROM email_stats_daily
        WHERE domain = ? AND $dc
        ORDER BY stat_date
    ");
    $stmt->execute(array_merge([$domain], $dp));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode([
        'labels'   => array_column($rows,'label'),
        'received' => array_column($rows,'received'),
        'sent'     => array_column($rows,'sent'),
        'bounced'  => array_column($rows,'bounced'),
        'rejected' => array_column($rows,'rejected'),
    ]);
    exit;
}

// ── Email: motivos de rejeição por domínio ────────────────────────────────────
if ($action === 'reject-reasons') {
    $domain = preg_replace('/[^a-zA-Z0-9\-\.]/', '', $_GET['domain'] ?? '');
    if (!$domain) { echo json_encode([]); exit; }
    $dp = []; $dc = dateRange($_GET, 'stat_date', $dp);
    $stmt = $pdo->prepare("
        SELECT reason_type, SUM(count) AS total
        FROM email_reject_reasons
        WHERE domain = ? AND $dc
        GROUP BY reason_type ORDER BY total DESC
    ");
    $stmt->execute(array_merge([$domain], $dp));
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ── Email: top IPs atacantes por domínio ─────────────────────────────────────
if ($action === 'reject-top-ips') {
    $domain = preg_replace('/[^a-zA-Z0-9\-\.]/', '', $_GET['domain'] ?? '');
    if (!$domain) { echo json_encode([]); exit; }
    $dp = []; $dc = dateRange($_GET, 'stat_date', $dp);
    $stmt = $pdo->prepare("
        SELECT sender_ip, SUM(count) AS total
        FROM email_reject_ips
        WHERE domain = ? AND $dc
        GROUP BY sender_ip ORDER BY total DESC LIMIT 20
    ");
    $stmt->execute(array_merge([$domain], $dp));
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ── Email: lista de bounces ───────────────────────────────────────────────────
if ($action === 'bounce-list') {
    $domain = preg_replace('/[^a-zA-Z0-9\-\.]/', '', $_GET['domain'] ?? '');
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $dp = []; $dc = dateRange($_GET, 'bounce_date', $dp);
    $params = $dp;
    $where  = '';
    if ($domain) { $where = 'AND recipient_domain = ?'; $params[] = $domain; }
    $params[] = 50;
    $params[] = $offset;
    $stmt = $pdo->prepare("
        SELECT logged_at, recipient, recipient_domain, reason
        FROM email_bounce_events
        WHERE $dc
        $where
        ORDER BY logged_at DESC LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ── Email: motivos de rejeição globais (todos os domínios) ───────────────────
if ($action === 'email-global-reject-reasons') {
    $dp = []; $dc = dateRange($_GET, 'stat_date', $dp);
    $stmt = $pdo->prepare("
        SELECT reason_type, SUM(count) AS total
        FROM email_reject_reasons
        WHERE $dc
        GROUP BY reason_type ORDER BY total DESC
    ");
    $stmt->execute($dp);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ── Email: IPs atacantes globais (todos os domínios) ─────────────────────────
if ($action === 'email-global-reject-ips') {
    $dp = []; $dc = dateRange($_GET, 'stat_date', $dp);
    $stmt = $pdo->prepare("
        SELECT sender_ip, SUM(count) AS total
        FROM email_reject_ips
        WHERE $dc
        GROUP BY sender_ip ORDER BY total DESC LIMIT 20
    ");
    $stmt->execute($dp);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ── Email: top contas e IPs de saída (email_auth_events) ─────────────────────
if ($action === 'email-auth-top') {
    $dp = []; $dc = dateRange($_GET, 'event_date', $dp);
    $stmt = $pdo->prepare("
        SELECT account, SUM(send_count) AS total, COUNT(DISTINCT sender_ip) AS unique_ips
        FROM email_auth_events
        WHERE $dc
        GROUP BY account ORDER BY total DESC LIMIT 15
    ");
    $stmt->execute($dp);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $dp2 = []; $dc2 = dateRange($_GET, 'event_date', $dp2);
    $stmt2 = $pdo->prepare("
        SELECT sender_ip, SUM(send_count) AS total, COUNT(DISTINCT account) AS unique_accounts
        FROM email_auth_events
        WHERE $dc2
        GROUP BY sender_ip ORDER BY total DESC LIMIT 15
    ");
    $stmt2->execute($dp2);
    $ips = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['accounts' => $accounts, 'ips' => $ips]);
    exit;
}

// ── Email: top domínios destino com bounce (recipient_domain) ─────────────────
if ($action === 'email-bounce-top-dest') {
    $dp = []; $dc = dateRange($_GET, 'bounce_date', $dp);
    $stmt = $pdo->prepare("
        SELECT recipient_domain, COUNT(*) AS events, COUNT(DISTINCT recipient) AS unique_recipients
        FROM email_bounce_events
        WHERE $dc
        GROUP BY recipient_domain ORDER BY events DESC LIMIT 15
    ");
    $stmt->execute($dp);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ── CPU: lista de processos de uma conta ─────────────────────────────────────
if ($action === 'cpu-processes') {
    $account = $_GET['account'] ?? '';
    if (!preg_match('/^[\w.-]+$/', $account)) { echo json_encode([]); exit; }
    $pw = @posix_getpwnam($account);
    if (!$pw) { echo json_encode([]); exit; }
    $uid = (int)$pw['uid'];
    $processes = [];
    foreach (new DirectoryIterator('/proc') as $e) {
        if (!$e->isDir() || !ctype_digit($e->getFilename())) continue;
        $pid    = $e->getFilename();
        $status = @file_get_contents("/proc/$pid/status");
        if (!$status) continue;
        preg_match('/Uid:\s+(\d+)/', $status, $m);
        if ((int)($m[1] ?? -1) !== $uid) continue;
        $cmdline = @file_get_contents("/proc/$pid/cmdline");
        $cmd = $cmdline ? str_replace("\0", ' ', trim($cmdline)) : '';
        if ($cmd === '') {
            preg_match('/Name:\s+(.+)/m', $status, $nm);
            $cmd = '[' . trim($nm[1] ?? 'kernel') . ']';
        }
        $stat = @file_get_contents("/proc/$pid/stat");
        $ticks = 0;
        if ($stat) {
            $f = explode(' ', $stat);
            $ticks = (int)($f[13] ?? 0) + (int)($f[14] ?? 0);
        }
        $processes[] = ['pid' => (int)$pid, 'cmd' => mb_substr($cmd, 0, 200), 'ticks' => $ticks];
    }
    usort($processes, fn($a, $b) => $b['ticks'] - $a['ticks']);
    echo json_encode(array_map(fn($p) => ['pid' => $p['pid'], 'cmd' => $p['cmd']], $processes));
    exit;
}

// ── CPU: matar processo de uma conta ─────────────────────────────────────────
if ($action === 'kill-process') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'POST required']); exit; }
    $body    = json_decode(file_get_contents('php://input'), true) ?: [];
    $pid     = (int)($body['pid'] ?? 0);
    $account = preg_replace('/[^\w.-]/', '', $body['account'] ?? '');
    if ($pid <= 0 || !$account) { echo json_encode(['error'=>'invalid']); exit; }
    $pw = @posix_getpwnam($account);
    if (!$pw) { echo json_encode(['error'=>'account not found']); exit; }
    $uid    = (int)$pw['uid'];
    $status = @file_get_contents("/proc/$pid/status");
    if (!$status) { echo json_encode(['error'=>'process not found']); exit; }
    preg_match('/Uid:\s+(\d+)/', $status, $m);
    if ((int)($m[1] ?? -1) !== $uid) { echo json_encode(['error'=>'process not owned by account']); exit; }
    $killFile = dirname(__DIR__) . '/private/kill_requests.json';
    $kills = file_exists($killFile) ? (json_decode(file_get_contents($killFile), true) ?: []) : [];
    $kills[] = ['pid' => $pid, 'account' => $account, 'ts' => date('Y-m-d H:i:s')];
    file_put_contents($killFile, json_encode($kills));
    echo json_encode(['ok' => true, 'queued' => true]);
    exit;
}

// ── Fila: lista completa ──────────────────────────────────────────────────────
if ($action === 'queue-list') {
    $queueFile = dirname(__DIR__) . '/private/queue_details.json';
    if (!file_exists($queueFile)) {
        echo json_encode(['total'=>0,'frozen'=>0,'messages'=>[],'collected_at'=>null]);
        exit;
    }
    $data = json_decode(file_get_contents($queueFile), true);
    echo json_encode($data ?: ['total'=>0,'frozen'=>0,'messages'=>[],'collected_at'=>null]);
    exit;
}

// ── Fila: ação (release/delete) ───────────────────────────────────────────────
if ($action === 'queue-action') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'POST required']); exit; }
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $act  = $body['action'] ?? '';
    $id   = preg_replace('/[^A-Za-z0-9\-]/', '', $body['id'] ?? '');
    if (!in_array($act, ['release','delete','release_all','delete_frozen'])) {
        echo json_encode(['error'=>'invalid action']); exit;
    }
    $actionsFile = dirname(__DIR__) . '/private/queue_actions.json';
    $actions = file_exists($actionsFile) ? (json_decode(file_get_contents($actionsFile), true) ?: []) : [];
    $actions[] = ['action' => $act, 'id' => $id, 'ts' => date('Y-m-d H:i:s')];
    file_put_contents($actionsFile, json_encode($actions));
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['error' => 'unknown action']);
