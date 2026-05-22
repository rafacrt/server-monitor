<?php
require __DIR__ . '/config.php';

const STATE_FILE    = PRIVATE_DIR . '/alert_state.json';
const CONFIG_FILE   = PRIVATE_DIR . '/alert_config.json';
const SILENCES_FILE = PRIVATE_DIR . '/alert_silences.json';

function cfg(): array {
    static $c = null;
    if ($c) return $c;
    $defaults = [
        'thresholds' => ['cpu'=>85,'ram'=>90,'disk'=>88,'queue'=>100,'load'=>14,'susp_sends'=>30],
        'cooldowns'  => ['cpu'=>60,'ram'=>60,'disk'=>120,'service'=>30,'site'=>30,'queue'=>60,'suspicious'=>240,'load'=>60],
    ];
    if (!file_exists(CONFIG_FILE)) return $c = $defaults;
    $file = json_decode(file_get_contents(CONFIG_FILE), true) ?: [];
    $c = [
        'thresholds' => array_merge($defaults['thresholds'], $file['thresholds'] ?? []),
        'cooldowns'  => array_merge($defaults['cooldowns'],  $file['cooldowns']  ?? []),
    ];
    return $c;
}

function thr(string $key): int|float { return cfg()['thresholds'][$key] ?? 0; }
function cool(string $key): int      { return cfg()['cooldowns'][$key]  ?? 60; }

function loadState(): array {
    if (!file_exists(STATE_FILE)) return [];
    return json_decode(file_get_contents(STATE_FILE), true) ?: [];
}

function cooldownOk(string $key, ?int $minutes = null): bool {
    $state = loadState();
    $entry = $state[$key] ?? null;
    $ts    = is_array($entry) ? (int)($entry['ts'] ?? 0) : (int)$entry;
    $min   = $minutes ?? 60;
    return (time() - $ts) > ($min * 60);
}

function markSent(string $key, string $label): void {
    $state        = loadState();
    $state[$key]  = ['ts' => time(), 'label' => $label, 'key' => $key];
    file_put_contents(STATE_FILE, json_encode($state, JSON_PRETTY_PRINT));
}

function isSilenced(string $key): bool {
    if (!file_exists(SILENCES_FILE)) return false;
    $silences = json_decode(file_get_contents(SILENCES_FILE), true) ?: [];
    foreach ($silences as $s) {
        if (($s['key'] ?? '') !== $key) continue;
        if (($s['until'] ?? '') === 'forever') return true;
        if (!empty($s['until']) && strtotime($s['until']) > time()) return true;
    }
    return false;
}

function curlPost(string $url, string $payload, array $headers = [], int $timeout = 15): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => $headers ?: ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => $timeout, CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $res];
}

function sendTelegram(array $alerts): bool {
    $count = count($alerts);
    $ts    = date('d/m H:i');
    $lines = ["⚠ <b>{$count} alerta(s) — " . SITE_NAME . "</b> · {$ts}"];
    foreach ($alerts as $a) {
        $icon   = ($a['sev'] ?? '') === 'critical' ? '🔴' : '🟡';
        $title  = htmlspecialchars($a['title'],  ENT_XML1);
        $detail = htmlspecialchars($a['detail'], ENT_XML1);
        $lines[] = "\n{$icon} <b>{$title}</b>\n{$detail}";
    }
    $lines[] = "\n<a href='".MONITOR_URL."'>Ver Dashboard →</a>";
    $payload = json_encode([
        'chat_id'    => TELEGRAM_CHAT_ID,
        'text'       => implode("\n", $lines),
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ]);
    [$code, $res] = curlPost(
        'https://api.telegram.org/bot'.TELEGRAM_TOKEN.'/sendMessage',
        $payload, ['Content-Type: application/json']
    );
    if ($code !== 200)
        file_put_contents(__DIR__.'/error.log', date('Y-m-d H:i:s')." [telegram] $code: $res\n", FILE_APPEND);
    return $code === 200;
}

function sendSlack(array $alerts): bool {
    $count  = count($alerts);
    $ts     = date('d/m H:i');
    $blocks = [
        ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => "⚠ {$count} alerta(s) — " . SITE_NAME . " · {$ts}"]],
    ];
    foreach ($alerts as $a) {
        $icon = ($a['sev'] ?? '') === 'critical' ? '🔴' : '🟡';
        $blocks[] = [
            'type' => 'section',
            'text' => ['type' => 'mrkdwn', 'text' => "{$icon} *{$a['title']}*\n{$a['detail']}"],
        ];
    }
    $blocks[] = [
        'type'     => 'actions',
        'elements' => [['type' => 'button', 'text' => ['type' => 'plain_text', 'text' => 'Ver Dashboard'], 'url' => MONITOR_URL]],
    ];
    $payload = json_encode(['text' => "⚠ {$count} alerta(s) — " . SITE_NAME, 'blocks' => $blocks]);
    [$code, $res] = curlPost(SLACK_WEBHOOK, $payload);
    if ($code !== 200)
        file_put_contents(__DIR__.'/error.log', date('Y-m-d H:i:s')." [slack] $code: $res\n", FILE_APPEND);
    return $code === 200;
}

function sendAlert(array $alerts): bool {
    $t = sendTelegram($alerts);
    $s = sendSlack($alerts);
    return $t && $s;
}

// ── Verificações ──────────────────────────────────────────────────────────────

$snap = null;
$snapFile = PRIVATE_DIR . '/current.json';
if (file_exists($snapFile)) $snap = json_decode(file_get_contents($snapFile), true);

$alerts = [];

try {
    $pdo = new PDO(
        'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
        DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // CPU
    if ($snap && !isSilenced('cpu_high') && cooldownOk('cpu_high', cool('cpu'))) {
        $avgCpu = (float)$pdo->query("SELECT AVG(cpu_percent) FROM (SELECT cpu_percent FROM system_metrics ORDER BY id DESC LIMIT 3) t")->fetchColumn();
        if ($avgCpu >= thr('cpu')) {
            $label = "CPU Alta: {$avgCpu}%";
            $alerts[] = ['sev'=>'critical','title'=>$label,'detail'=>"Média 3 coletas acima de ".thr('cpu')."%. Load: ".round($snap['load'][0],2)];
            markSent('cpu_high', $label);
        }
    }

    // RAM
    if ($snap && !isSilenced('ram_high') && cooldownOk('ram_high', cool('ram'))) {
        $ramPct = (int)($snap['ram_pct'] ?? 0);
        if ($ramPct >= thr('ram')) {
            $label = "RAM Alta: {$ramPct}%";
            $ramGb = round($snap['ram_used_mb']/1024,1);
            $totGb = round($snap['ram_total_mb']/1024,1);
            $alerts[] = ['sev'=>'critical','title'=>$label,'detail'=>"Usando {$ramGb}GB de {$totGb}GB (limite: ".thr('ram')."%)"];
            markSent('ram_high', $label);
        }
    }

    // Disco
    if ($snap && !isSilenced('disk_high') && cooldownOk('disk_high', cool('disk'))) {
        $diskPct = (int)($snap['disk_pct'] ?? 0);
        if ($diskPct >= thr('disk')) {
            $label = "Disco em {$diskPct}%";
            $alerts[] = ['sev'=>'critical','title'=>$label,'detail'=>"Usado: {$snap['disk_used_gb']}GB de {$snap['disk_total_gb']}GB · Livre: {$snap['disk_free_gb']}GB"];
            markSent('disk_high', $label);
        }
    }

    // Load
    if ($snap && !isSilenced('load_high') && cooldownOk('load_high', cool('load'))) {
        $load = (float)($snap['load'][0] ?? 0);
        if ($load >= thr('load')) {
            $label = "Load alto: {$load}";
            $alerts[] = ['sev'=>'warning','title'=>$label,'detail'=>"Load 1min em {$load} (limite: ".thr('load').")"];
            markSent('load_high', $label);
        }
    }

    // Serviços
    if ($snap && isset($snap['services'])) {
        $svcNames = ['nginx'=>'Nginx','apache2'=>'Apache','mariadb'=>'MariaDB','exim4'=>'Exim',
                     'dovecot'=>'Dovecot','clamav-daemon'=>'ClamAV','hestia'=>'HestiaCP'];
        foreach ($svcNames as $key => $label) {
            if (!($snap['services'][$key] ?? true)) {
                $ck = "svc_{$key}";
                if (!isSilenced($ck) && cooldownOk($ck, cool('service'))) {
                    $lbl = "Serviço FORA: {$label}";
                    $alerts[] = ['sev'=>'critical','title'=>$lbl,'detail'=>"O serviço {$label} não foi detectado. Verifique imediatamente."];
                    markSent($ck, $lbl);
                }
            }
        }
    }

    // Fila
    if (!isSilenced('queue_high') && cooldownOk('queue_high', cool('queue'))) {
        $qf = PRIVATE_DIR . '/queue_details.json';
        if (file_exists($qf)) {
            $q      = json_decode(file_get_contents($qf), true);
            $total  = (int)($q['total'] ?? 0);
            $frozen = (int)($q['frozen'] ?? 0);
            if ($total >= thr('queue')) {
                $label = "Fila de e-mail: {$total} mensagens";
                $alerts[] = ['sev'=>'warning','title'=>$label,'detail'=>"{$frozen} congeladas, ".($total-$frozen)." ativas. Limite: ".thr('queue')."."];
                markSent('queue_high', $label);
            }
        }
    }

    // Sites fora
    $downSites = $pdo->query("
        SELECT domain, last_status_code, started_at
        FROM site_incidents WHERE resolved_at IS NULL ORDER BY started_at
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($downSites as $site) {
        $ck = 'site_'.md5($site['domain']);
        if (!isSilenced($ck) && cooldownOk($ck, cool('site'))) {
            $ago   = round((time() - strtotime($site['started_at'])) / 60);
            $code  = $site['last_status_code'] ?: 'timeout';
            $label = "Site FORA: {$site['domain']}";
            $alerts[] = ['sev'=>'critical','title'=>$label,'detail'=>"Fora há {$ago} min · HTTP {$code} · desde {$site['started_at']}"];
            markSent($ck, $label);
        }
    }

    // E-mail suspeito (IP novo)
    $suspStmt = $pdo->query("
        SELECT a.account, a.sender_ip, a.send_count, a.event_date
        FROM email_auth_events a
        WHERE a.event_date >= DATE_SUB(CURDATE(), INTERVAL 2 DAY)
          AND a.send_count >= ".thr('susp_sends')."
          AND NOT EXISTS (SELECT 1 FROM email_auth_events b
            WHERE b.account=a.account AND b.sender_ip=a.sender_ip
              AND b.event_date < DATE_SUB(CURDATE(), INTERVAL 2 DAY))
          AND EXISTS (SELECT 1 FROM email_auth_events c
            WHERE c.account=a.account AND c.event_date < DATE_SUB(CURDATE(), INTERVAL 2 DAY))
        ORDER BY a.send_count DESC LIMIT 10
    ");
    foreach ($suspStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $ck = 'susp_'.md5($row['account'].$row['sender_ip']);
        if (!isSilenced($ck) && cooldownOk($ck, cool('suspicious'))) {
            $label = "E-mail suspeito: {$row['account']}";
            $alerts[] = ['sev'=>'warning','title'=>$label,
                'detail'=>"IP novo ({$row['sender_ip']}) enviou {$row['send_count']} e-mails em {$row['event_date']}. Possível conta comprometida."];
            markSent($ck, $label);
        }
    }

} catch (Exception $e) {
    file_put_contents(__DIR__.'/error.log', date('Y-m-d H:i:s')." [alerts] ".$e->getMessage()."\n", FILE_APPEND);
}

if ($alerts) sendAlert($alerts);
