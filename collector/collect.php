<?php
require __DIR__ . '/config.php';

function getCpuPercent(): float {
    $s1 = array_map('intval', array_slice(preg_split('/\s+/', trim(file('/proc/stat')[0])), 1));
    sleep(1);
    $s2 = array_map('intval', array_slice(preg_split('/\s+/', trim(file('/proc/stat')[0])), 1));
    $idle1 = $s1[3] + $s1[4]; $idle2 = $s2[3] + $s2[4];
    $dt = array_sum($s2) - array_sum($s1);
    return $dt > 0 ? round(($dt - ($idle2 - $idle1)) / $dt * 100, 1) : 0;
}

function getMemInfo(): array {
    $raw = file_get_contents('/proc/meminfo');
    preg_match('/MemTotal:\s+(\d+)/m',     $raw, $t);
    preg_match('/MemAvailable:\s+(\d+)/m', $raw, $a);
    return ['total' => (int)round($t[1]/1024), 'used' => (int)round(($t[1]-$a[1])/1024)];
}

function getDisk(): array {
    $tot = disk_total_space('/'); $free = disk_free_space('/');
    return ['total'=>round($tot/1073741824,1), 'used'=>round(($tot-$free)/1073741824,1)];
}

function procRunning(string $match): bool {
    foreach (new DirectoryIterator('/proc') as $e) {
        if (!$e->isDir() || !ctype_digit($e->getFilename())) continue;
        $cmd = @file_get_contents('/proc/'.$e->getFilename().'/cmdline');
        if ($cmd && str_contains($cmd, $match)) return true;
    }
    return false;
}

function getServices(): array {
    return [
        'nginx'         => procRunning('nginx'),
        'apache2'       => procRunning('apache2'),
        'mariadb'       => procRunning('mariadbd'),
        'exim4'         => procRunning('exim'),
        'dovecot'       => procRunning('dovecot'),
        'clamav-daemon' => procRunning('clamd'),
        'spamassassin'  => procRunning('spamd'),
        'hestia'        => procRunning('hestia'),
    ];
}

function collectCpuByAccount(PDO $pdo): void {
    $stateFile = __DIR__ . '/cpu_state.json';
    $prev = file_exists($stateFile) ? json_decode(file_get_contents($stateFile), true) : null;
    $now  = microtime(true);

    $cpuinfo = @file_get_contents('/proc/cpuinfo') ?: '';
    $numCpus = max(1, preg_match_all('/^processor\s*:/m', $cpuinfo));

    $curr = [];
    foreach (new DirectoryIterator('/proc') as $e) {
        if (!$e->isDir() || !ctype_digit($e->getFilename())) continue;
        $pid    = $e->getFilename();
        $stat   = @file_get_contents("/proc/$pid/stat");
        $status = @file_get_contents("/proc/$pid/status");
        if (!$stat || !$status) continue;
        preg_match('/Uid:\s+(\d+)/', $status, $m);
        $uid = (int)($m[1] ?? 0);
        if ($uid < 1000) continue;
        $pw   = posix_getpwuid($uid);
        $user = $pw ? $pw['name'] : "uid:$uid";
        $f    = explode(' ', $stat);
        $ticks = (int)($f[13] ?? 0) + (int)($f[14] ?? 0);
        $curr[$user]['ticks'] = ($curr[$user]['ticks'] ?? 0) + $ticks;
        $curr[$user]['count'] = ($curr[$user]['count'] ?? 0) + 1;
    }

    file_put_contents($stateFile, json_encode(['ts' => $now, 'data' => $curr]));
    if (!$prev || !isset($prev['ts'])) return;

    $elapsed = max(1, $now - $prev['ts']);
    $stmt = $pdo->prepare("INSERT INTO cpu_by_account (account,cpu_pct,proc_count) VALUES(?,?,?)");
    foreach ($curr as $account => $info) {
        $delta  = max(0, $info['ticks'] - ($prev['data'][$account]['ticks'] ?? 0));
        $cpuPct = round($delta / 100 / $elapsed * 100 / $numCpus, 1);
        if ($cpuPct >= 0.1) {
            $stmt->execute([$account, min($cpuPct, 100), $info['count']]);
        }
    }
}

function classifyRejectReason(string $reason): string {
    if (preg_match('/spamhaus|sorbs|barracuda|uribl|surbl|\.rbl\.|dnsbl|blackhole|spam.*list|listed in/i', $reason)) return 'blacklist';
    if (preg_match('/sender verify|sender callout|could not complete/i', $reason))                           return 'sender_verify';
    if (preg_match('/unrouteable/i', $reason))                                                               return 'unroutable';
    if (preg_match('/relay/i', $reason))                                                                     return 'relay';
    if (preg_match('/host not found|lookup.*failed/i', $reason))                                             return 'dns_fail';
    return 'other';
}

function parseEximLogs(PDO $pdo): void {
    $stateFile = __DIR__ . '/email_parse_state.json';
    $state     = file_exists($stateFile) ? json_decode(file_get_contents($stateFile), true) : [];
    $stats         = [];
    $rejectReasons = [];
    $rejectIps     = [];
    $bounceEvents  = [];

    $logs = ['main' => '/var/log/exim4/mainlog', 'reject' => '/var/log/exim4/rejectlog'];

    foreach ($logs as $type => $path) {
        if (!is_readable($path)) continue;
        $inode   = fileinode($path);
        $prevPos = ($state[$type]['inode'] ?? 0) === $inode ? (int)($state[$type]['pos'] ?? 0) : 0;

        $fh = fopen($path, 'r');
        if (!$fh) continue;
        fseek($fh, $prevPos);

        while (!feof($fh)) {
            $line = fgets($fh);
            if (!$line) continue;
            if (!preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})/', $line, $dm)) continue;
            $date     = $dm[1];
            $loggedAt = $dm[1].' '.$dm[2];
            $domain   = null;
            $action   = null;

            if ($type === 'main') {
                if (strpos($line, ' <= ') !== false) {
                    if (preg_match('/ P=local /', $line) || preg_match('/ A=dovecot_\S+ /', $line)) {
                        if (preg_match('/ <= (\S+@([\w.-]+\.\w+))[ \r\n]/', $line, $m)) {
                            $domain = strtolower($m[2]);
                            $action = 'sent';
                        }
                    }
                } elseif (strpos($line, ' => ') !== false) {
                    if (strpos($line, 'T=dovecot_virtual_delivery') !== false) {
                        if (preg_match('/<\S+@([\w.-]+\.\w+)>/', $line, $m)) {
                            $domain = strtolower($m[1]);
                            $action = 'received';
                        }
                    }
                } elseif (strpos($line, ' ** ') !== false) {
                    if (preg_match('/ \*\* (\S+@([\w.-]+\.\w+))[: (]/', $line, $m)) {
                        $domain    = strtolower($m[2]);
                        $action    = 'bounced';
                        $recipient = $m[1];
                        $reason    = '';
                        if (preg_match('/ \*\* \S+@\S+[: ]+(.+)$/', $line, $rm)) {
                            $reason = substr(trim($rm[1]), 0, 500);
                        }
                        $bounceEvents[] = [$loggedAt, $date, $domain, $recipient, $reason];
                    }
                }
            } elseif ($type === 'reject') {
                if (preg_match('/RCPT <[^>@]*@([\w.-]+\.\w+)>/', $line, $m)) {
                    $domain = strtolower($m[1]);
                    $action = 'rejected';
                    $ip     = '';
                    if (preg_match('/\[(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})\]/', $line, $im)) {
                        $ip = $im[1];
                    }
                    $reason = '';
                    if (preg_match('/rejected RCPT <[^>]+>:\s*(.+)$/i', $line, $rm)) {
                        $reason = trim($rm[1]);
                    }
                    $rType = classifyRejectReason($reason);
                    $rejectReasons[$date][$domain][$rType] = ($rejectReasons[$date][$domain][$rType] ?? 0) + 1;
                    if ($ip) {
                        $rejectIps[$date][$domain][$ip] = ($rejectIps[$date][$domain][$ip] ?? 0) + 1;
                    }
                }
            }

            if ($domain && $action) {
                $stats[$date][$domain][$action] = ($stats[$date][$domain][$action] ?? 0) + 1;
            }
        }

        $state[$type] = ['inode' => $inode, 'pos' => ftell($fh)];
        fclose($fh);
    }

    file_put_contents($stateFile, json_encode($state));

    if ($stats) {
        $stmt = $pdo->prepare("
            INSERT INTO email_stats_daily (stat_date, domain, received, sent, bounced, rejected)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                received = received + VALUES(received),
                sent     = sent     + VALUES(sent),
                bounced  = bounced  + VALUES(bounced),
                rejected = rejected + VALUES(rejected)
        ");
        foreach ($stats as $date => $domains) {
            foreach ($domains as $domain => $counts) {
                $stmt->execute([
                    $date, $domain,
                    $counts['received'] ?? 0, $counts['sent']    ?? 0,
                    $counts['bounced']  ?? 0, $counts['rejected'] ?? 0,
                ]);
            }
        }
    }

    if ($rejectReasons) {
        $stmt = $pdo->prepare("
            INSERT INTO email_reject_reasons (stat_date, domain, reason_type, count)
            VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE count = count + VALUES(count)
        ");
        foreach ($rejectReasons as $date => $domains) {
            foreach ($domains as $domain => $reasons) {
                foreach ($reasons as $type => $cnt) {
                    $stmt->execute([$date, $domain, $type, $cnt]);
                }
            }
        }
        $pdo->exec("DELETE FROM email_reject_reasons WHERE stat_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
    }

    if ($rejectIps) {
        $stmt = $pdo->prepare("
            INSERT INTO email_reject_ips (stat_date, domain, sender_ip, count)
            VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE count = count + VALUES(count)
        ");
        foreach ($rejectIps as $date => $domains) {
            foreach ($domains as $domain => $ips) {
                arsort($ips);
                foreach (array_slice($ips, 0, 20, true) as $ip => $cnt) {
                    $stmt->execute([$date, $domain, $ip, $cnt]);
                }
            }
        }
        $pdo->exec("DELETE FROM email_reject_ips WHERE stat_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
    }

    if ($bounceEvents) {
        $stmt = $pdo->prepare("
            INSERT INTO email_bounce_events (logged_at, bounce_date, recipient_domain, recipient, reason)
            VALUES (?,?,?,?,?)
        ");
        foreach ($bounceEvents as $e) {
            try { $stmt->execute($e); } catch (Exception $ex) {}
        }
        $pdo->exec("DELETE FROM email_bounce_events WHERE logged_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    }
}

function collectQueueDetails(PDO $pdo): void {
    $dir      = '/var/spool/exim4/input/';
    $messages = [];
    $count    = 0;

    if (is_dir($dir)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if (!str_ends_with($f->getFilename(), '-H')) continue;
            $count++;
            $msgId   = substr($f->getFilename(), 0, -2);
            $content = @file_get_contents($f->getPathname());
            if (!$content) continue;

            $frozen     = false;
            $sender     = '<>';
            $recipients = [];
            $subject    = '(sem assunto)';

            $lines = explode("\n", $content);
            $phase = 'flags'; // flags → after_xx → recip_count → recipients → headers
            $recipCount = 0;
            foreach ($lines as $idx => $ln) {
                $ln = rtrim($ln);
                if ($idx === 2) { $sender = ($ln === '' || $ln === '<>') ? '<>' : $ln; }
                if (preg_match('/^-frozen\b/', $ln)) { $frozen = true; }
                if ($phase === 'flags' && $ln === 'XX') { $phase = 'after_xx'; continue; }
                if ($phase === 'after_xx' && preg_match('/^\d+$/', $ln)) {
                    $recipCount = (int)$ln; $phase = 'recipients'; continue;
                }
                if ($phase === 'recipients') {
                    if ($ln === '') { $phase = 'headers'; continue; }
                    if ($ln !== '' && count($recipients) < $recipCount) { $recipients[] = $ln; }
                    continue;
                }
                if ($phase === 'headers' && preg_match('/^\d{3}[A-Z]?\s+[Ss]ubject:\s*(.+)$/', $ln, $m))
                    $subject = trim($m[1]);
            }

            $ageMins    = (int)((time() - $f->getMTime()) / 60);
            $messages[] = [
                'id'          => $msgId,
                'age_minutes' => $ageMins,
                'frozen'      => $frozen,
                'sender'      => $sender,
                'recipients'  => $recipients,
                'subject'     => $subject,
                'added_at'    => date('Y-m-d H:i', $f->getMTime()),
            ];
        }
    }

    usort($messages, fn($a,$b) => $b['age_minutes'] - $a['age_minutes']);

    $jsonPath = PRIVATE_DIR . '/queue_details.json';
    file_put_contents($jsonPath, json_encode([
        'collected_at' => date('Y-m-d H:i:s'),
        'total'        => $count,
        'frozen'       => count(array_filter($messages, fn($m) => $m['frozen'])),
        'messages'     => $messages,
    ]));
    chown($jsonPath, WEB_USER);
    chmod($jsonPath, 0644);

    $pdo->prepare("INSERT INTO email_queue_snap (queue_count) VALUES (?)")->execute([$count]);
    $pdo->exec("DELETE FROM email_queue_snap WHERE collected_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
}

function processQueueActions(): void {
    $actionsFile = PRIVATE_DIR . '/queue_actions.json';
    if (!file_exists($actionsFile)) return;
    $actions = json_decode(file_get_contents($actionsFile), true) ?: [];
    if (empty($actions)) return;

    foreach ($actions as $action) {
        $type = $action['action'] ?? '';
        $id   = preg_replace('/[^A-Za-z0-9\-]/', '', $action['id'] ?? '');
        if ($type === 'release' && $id)
            exec("exim -M " . escapeshellarg($id) . " 2>/dev/null");
        elseif ($type === 'delete' && $id)
            exec("exim -Mrm " . escapeshellarg($id) . " 2>/dev/null");
        elseif ($type === 'release_all')
            exec("exim -qf 2>/dev/null");
        elseif ($type === 'delete_frozen')
            exec("exiqgrep -iz 2>/dev/null | xargs -r exim -Mrm 2>/dev/null");
    }

    file_put_contents($actionsFile, json_encode([]));
}

// ── Disco por conta ───────────────────────────────────────────────────────────

function collectDiskByAccount(PDO $pdo): void {
    $out = [];
    exec("du -sb /home/*/ 2>/dev/null", $out);
    if (!$out) return;
    $stmt = $pdo->prepare("INSERT INTO disk_by_account (account, disk_mb) VALUES (?,?)");
    foreach ($out as $line) {
        if (!preg_match('/^(\d+)\s+\/home\/(\w+)\/$/', $line, $m)) continue;
        $mb = (int)round((int)$m[1] / 1048576);
        if ($mb > 0) $stmt->execute([$m[2], $mb]);
    }
    $pdo->exec("DELETE FROM disk_by_account WHERE collected_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
}

// ── Uptime dos sites ──────────────────────────────────────────────────────────

function curlMultiBatch(array $domains, int $timeout = 8): array {
    $results = [];
    foreach (array_chunk($domains, 20) as $batch) {
        $multi = curl_multi_init();
        $handles = [];
        foreach ($batch as $domain) {
            $ch = curl_init("https://{$domain}");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_NOBODY         => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT      => 'ServerMonitor/1.0',
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[$domain] = $ch;
        }
        $active = null;
        do { curl_multi_exec($multi, $active); curl_multi_select($multi, 0.3); } while ($active > 0);
        foreach ($handles as $domain => $ch) {
            $info = curl_getinfo($ch);
            $results[$domain] = ['code' => (int)$info['http_code'], 'ms' => (int)($info['total_time']*1000)];
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi);
    }
    return $results;
}

function checkSiteUptime(PDO $pdo): void {
    $mapFile = PRIVATE_DIR . '/accounts_map.json';
    $map     = file_exists($mapFile) ? json_decode(file_get_contents($mapFile), true) : [];
    $allDomains = array_merge(...array_values($map) ?: [[]]);
    if (!$allDomains) return;

    // State: last_full_check (ts) + pending_down (domain => first_detected_ts)
    $stateFile = __DIR__ . '/site_check_state.json';
    $state    = file_exists($stateFile) ? json_decode(file_get_contents($stateFile), true) : [];
    $lastFull = (int)($state['last_full_check'] ?? 0);
    $pending  = $state['pending_down'] ?? [];

    $doFullCheck    = (time() - $lastFull) >= 3600; // full sweep every 1 hour
    $domainsToCheck = $doFullCheck ? $allDomains : array_keys($pending);

    if (!$domainsToCheck) {
        file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));
        return;
    }

    $results = curlMultiBatch($domainsToCheck);

    $stmtIns     = $pdo->prepare("INSERT INTO site_checks (domain, status_code, response_ms, is_up) VALUES (?,?,?,?)");
    $stmtOpen    = $pdo->prepare("
        INSERT IGNORE INTO site_incidents (domain, started_at, last_status_code)
        SELECT ?, NOW(), ? FROM DUAL
        WHERE NOT EXISTS (SELECT 1 FROM site_incidents WHERE domain=? AND resolved_at IS NULL)
    ");
    $stmtHasOpen = $pdo->prepare("SELECT id FROM site_incidents WHERE domain=? AND resolved_at IS NULL LIMIT 1");
    $stmtResolve = $pdo->prepare("UPDATE site_incidents SET resolved_at=NOW() WHERE domain=? AND resolved_at IS NULL");
    $alertStateFile = PRIVATE_DIR . '/alert_state.json';

    foreach ($results as $domain => $r) {
        // Down = timeout (0) or 5xx; 4xx = server responds = up
        $isUp = $r['code'] >= 200 && $r['code'] < 500;
        $stmtIns->execute([$domain, $r['code'], $r['ms'], $isUp ? 1 : 0]);

        if (!$isUp) {
            if (!isset($pending[$domain])) {
                // First detection: mark as pending, wait 5 min for confirmation
                $pending[$domain] = time();
            } elseif ((time() - $pending[$domain]) >= 300) {
                // Confirmed down (5+ min): open incident and let alerts.php notify
                $stmtOpen->execute([$domain, $r['code'], $domain]);
                unset($pending[$domain]);
            }
        } else {
            unset($pending[$domain]);
            $stmtHasOpen->execute([$domain]);
            if ($stmtHasOpen->fetch()) {
                $stmtResolve->execute([$domain]);
                // Clear alert state so the next outage triggers a fresh alert immediately
                if (file_exists($alertStateFile)) {
                    $alertState = json_decode(file_get_contents($alertStateFile), true) ?: [];
                    $ck = 'site_' . md5($domain);
                    if (isset($alertState[$ck])) {
                        unset($alertState[$ck]);
                        file_put_contents($alertStateFile, json_encode($alertState, JSON_PRETTY_PRINT));
                    }
                }
            }
        }
    }

    if ($doFullCheck) $state['last_full_check'] = time();
    $state['pending_down'] = $pending;
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));
    $pdo->exec("DELETE FROM site_checks WHERE checked_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
}

// ── Atividade autenticada de e-mail (logins suspeitos) ────────────────────────

function collectAuthEvents(PDO $pdo): void {
    $stateFile = __DIR__ . '/auth_events_state.json';
    $state     = file_exists($stateFile) ? json_decode(file_get_contents($stateFile), true) : [];

    $path = '/var/log/exim4/mainlog';
    if (!is_readable($path)) return;

    $inode   = fileinode($path);
    $prevPos = ($state['inode'] ?? 0) === $inode ? (int)($state['pos'] ?? 0) : 0;

    $fh = fopen($path, 'r');
    if (!$fh) return;
    fseek($fh, $prevPos);

    $events = []; // [date][account][ip] = count

    while (!feof($fh)) {
        $line = fgets($fh);
        if (!$line) continue;
        if (strpos($line, ' <= ') === false || strpos($line, 'A=dovecot_') === false) continue;
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})/', $line, $dm)) continue;
        $date = $dm[1];

        if (!preg_match('/ <= (\S+@[\w.-]+\.\w+) /', $line, $sm)) continue;
        $ip = '';
        if (preg_match('/\[(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})\]/', $line, $im)) $ip = $im[1];
        if (!$ip) continue;

        $account = strtolower($sm[1]);
        if (preg_match('/A=dovecot_\w+:(\S+)/', $line, $am)) $account = strtolower($am[1]);

        $events[$date][$account][$ip] = ($events[$date][$account][$ip] ?? 0) + 1;
    }

    $state = ['inode' => $inode, 'pos' => ftell($fh)];
    fclose($fh);
    file_put_contents($stateFile, json_encode($state));

    if (!$events) return;
    $stmt = $pdo->prepare("
        INSERT INTO email_auth_events (event_date, account, sender_ip, send_count)
        VALUES (?,?,?,?)
        ON DUPLICATE KEY UPDATE send_count = send_count + VALUES(send_count)
    ");
    foreach ($events as $date => $accounts) {
        foreach ($accounts as $account => $ips) {
            foreach ($ips as $ip => $cnt) {
                $stmt->execute([$date, $account, $ip, $cnt]);
            }
        }
    }
    $pdo->exec("DELETE FROM email_auth_events WHERE event_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
}

// ── Segurança: fail2ban + auth.log ────────────────────────────────────────────

function collectSecurityEvents(PDO $pdo): void {
    $stateFile = __DIR__ . '/security_state.json';
    $state     = file_exists($stateFile) ? json_decode(file_get_contents($stateFile), true) : [];
    $events    = [];

    // fail2ban.log
    $f2bPath = '/var/log/fail2ban.log';
    if (is_readable($f2bPath)) {
        $inode   = fileinode($f2bPath);
        $prevPos = ($state['f2b']['inode'] ?? 0) === $inode ? (int)($state['f2b']['pos'] ?? 0) : 0;
        $fh = fopen($f2bPath, 'r');
        if ($fh) {
            fseek($fh, $prevPos);
            while (!feof($fh)) {
                $line = fgets($fh);
                if (!$line) continue;
                // 2026-05-12 10:23:45,123 fail2ban.actions [...]: NOTICE  [jail] Ban 1.2.3.4
                if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $line, $dm)) continue;
                $loggedAt = $dm[1];
                if (preg_match('/NOTICE\s+\[(\S+)\]\s+(Ban|Unban)\s+(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/', $line, $m)) {
                    $events[] = [$loggedAt, strtolower($m[2]), $m[1], $m[3]];
                }
            }
            $state['f2b'] = ['inode' => $inode, 'pos' => ftell($fh)];
            fclose($fh);
        }
    }

    // auth.log (SSH failures)
    $authPath = '/var/log/auth.log';
    if (is_readable($authPath)) {
        $inode   = fileinode($authPath);
        $prevPos = ($state['auth']['inode'] ?? 0) === $inode ? (int)($state['auth']['pos'] ?? 0) : 0;
        $fh = fopen($authPath, 'r');
        if ($fh) {
            fseek($fh, $prevPos);
            $year = date('Y');
            while (!feof($fh)) {
                $line = fgets($fh);
                if (!$line) continue;
                // May 12 10:23:45 host sshd[1234]: Failed password for root from 1.2.3.4 port...
                if (strpos($line, 'sshd') === false) continue;
                if (!preg_match('/^(\w{3}\s+\d+\s+\d{2}:\d{2}:\d{2})/', $line, $dm)) continue;
                $loggedAt = date('Y-m-d H:i:s', strtotime("$year {$dm[1]}"));
                if (preg_match('/Failed password for (?:invalid user )?(\S+) from (\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/', $line, $m)) {
                    $events[] = [$loggedAt, 'ssh_fail', 'sshd', $m[2]];
                }
            }
            $state['auth'] = ['inode' => $inode, 'pos' => ftell($fh)];
            fclose($fh);
        }
    }

    file_put_contents($stateFile, json_encode($state));

    if ($events) {
        $stmt = $pdo->prepare("INSERT INTO security_events (logged_at, event_type, jail, ip) VALUES (?,?,?,?)");
        foreach ($events as $e) {
            try { $stmt->execute($e); } catch (Exception $ex) {}
        }
        $pdo->exec("DELETE FROM security_events WHERE logged_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    }
}

function createTables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_metrics (
        id INT AUTO_INCREMENT PRIMARY KEY, collected_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        cpu_percent FLOAT, ram_used_mb INT, ram_total_mb INT,
        disk_used_gb FLOAT, disk_total_gb FLOAT,
        load_1m FLOAT, load_5m FLOAT, load_15m FLOAT, services_json TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cpu_by_account (
        id INT AUTO_INCREMENT PRIMARY KEY, collected_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        account VARCHAR(100), cpu_pct FLOAT, proc_count INT,
        INDEX idx_acc_time (account, collected_at))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_stats_daily (
        id INT AUTO_INCREMENT PRIMARY KEY, stat_date DATE, domain VARCHAR(255),
        received INT DEFAULT 0, sent INT DEFAULT 0, bounced INT DEFAULT 0, rejected INT DEFAULT 0,
        UNIQUE KEY uniq_domain_date (stat_date, domain))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_queue_snap (
        id INT AUTO_INCREMENT PRIMARY KEY, collected_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        queue_count INT DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_reject_reasons (
        id INT AUTO_INCREMENT PRIMARY KEY, stat_date DATE NOT NULL,
        domain VARCHAR(255) NOT NULL, reason_type VARCHAR(30) NOT NULL, count INT DEFAULT 0,
        UNIQUE KEY uniq_reason (stat_date, domain, reason_type))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_reject_ips (
        id INT AUTO_INCREMENT PRIMARY KEY, stat_date DATE NOT NULL,
        domain VARCHAR(255) NOT NULL, sender_ip VARCHAR(45) NOT NULL, count INT DEFAULT 0,
        UNIQUE KEY uniq_ip (stat_date, domain, sender_ip),
        INDEX idx_domain_date (stat_date, domain))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_bounce_events (
        id INT AUTO_INCREMENT PRIMARY KEY, logged_at DATETIME NOT NULL, bounce_date DATE NOT NULL,
        recipient_domain VARCHAR(255), recipient VARCHAR(255), reason VARCHAR(500),
        INDEX idx_date_domain (bounce_date, recipient_domain))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS disk_by_account (
        id INT AUTO_INCREMENT PRIMARY KEY, collected_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        account VARCHAR(100) NOT NULL, disk_mb INT NOT NULL,
        INDEX idx_acc_time (account, collected_at))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS site_checks (
        id INT AUTO_INCREMENT PRIMARY KEY, checked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        domain VARCHAR(255) NOT NULL, status_code INT, response_ms INT, is_up TINYINT(1) DEFAULT 1,
        INDEX idx_domain_time (domain, checked_at))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS site_incidents (
        id INT AUTO_INCREMENT PRIMARY KEY, domain VARCHAR(255) NOT NULL,
        started_at DATETIME NOT NULL, resolved_at DATETIME DEFAULT NULL,
        last_status_code INT, INDEX idx_domain (domain))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_auth_events (
        id INT AUTO_INCREMENT PRIMARY KEY, event_date DATE NOT NULL,
        account VARCHAR(255) NOT NULL, sender_ip VARCHAR(45) NOT NULL, send_count INT DEFAULT 0,
        UNIQUE KEY uniq_auth (event_date, account, sender_ip),
        INDEX idx_date_account (event_date, account))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS security_events (
        id INT AUTO_INCREMENT PRIMARY KEY, logged_at DATETIME NOT NULL,
        event_type VARCHAR(20), jail VARCHAR(50), ip VARCHAR(45),
        INDEX idx_logged (logged_at), INDEX idx_ip (ip))");
}

// ── Informações estáticas do servidor ────────────────────────────────────────

function collectServerInfo(): array {
    $cpuinfo  = @file_get_contents('/proc/cpuinfo') ?: '';
    preg_match_all('/^processor\s*:/m', $cpuinfo, $procs);
    $threads = count($procs[0]);
    $cpuModel = '';
    if (preg_match('/^model name\s*:\s*(.+)$/m', $cpuinfo, $m)) $cpuModel = trim($m[1]);
    $physCores = 0;
    if (preg_match('/^cpu cores\s*:\s*(\d+)/m', $cpuinfo, $m)) $physCores = (int)$m[1];

    $osName = '';
    $osRelease = @file_get_contents('/etc/os-release') ?: '';
    if (preg_match('/^PRETTY_NAME="(.+)"/m', $osRelease, $m)) $osName = $m[1];

    $kernel   = trim(php_uname('r'));
    $hostname = trim(gethostname());

    // Running PHP-FPM versions — cmdline format: "php-fpm: master process (/etc/php/8.2/fpm/php-fpm.conf)"
    $runningFpm = [];
    foreach (new DirectoryIterator('/proc') as $e) {
        if (!$e->isDir() || !ctype_digit($e->getFilename())) continue;
        $cmd = @file_get_contents('/proc/' . $e->getFilename() . '/cmdline');
        if (!$cmd) continue;
        $cmd = str_replace("\0", ' ', $cmd);
        if (preg_match('/\/etc\/php\/(\d+\.\d+)\/fpm/', $cmd, $m)) {
            $runningFpm[$m[1]] = true;
        }
    }

    $phpVersions = [];
    foreach (glob('/usr/bin/php[0-9]*.[0-9]*') as $bin) {
        if (!preg_match('/php(\d+\.\d+)$/', $bin, $m)) continue;
        $ver = $m[1]; $fullVer = ''; $out = [];
        exec("$bin -r 'echo PHP_VERSION;' 2>/dev/null", $out);
        if ($out) $fullVer = trim($out[0]);
        $phpVersions[] = ['version' => $ver, 'full_version' => $fullVer ?: $ver, 'fpm_running' => isset($runningFpm[$ver])];
    }

    $nginxVer = ''; $out = [];
    exec('nginx -v 2>&1', $out);
    if ($out && preg_match('/nginx\/(\S+)/', $out[0], $m)) $nginxVer = $m[1];

    $apacheVer = ''; $out = [];
    exec('apache2 -v 2>&1', $out);
    if ($out && preg_match('/Apache\/(\S+)/', $out[0], $m)) $apacheVer = $m[1];

    $mariaVer = ''; $out = [];
    exec('mariadb --version 2>/dev/null || mysql --version 2>/dev/null', $out);
    if ($out) {
        $vLine = implode(' ', $out);
        if (preg_match('/(\d+\.\d+\.\d+(?:-MariaDB)?)/i', $vLine, $m)) $mariaVer = $m[1];
    }

    $eximVer = ''; $out = [];
    exec('exim --version 2>/dev/null', $out);
    if ($out && preg_match('/Exim version (\S+)/', $out[0], $m)) $eximVer = $m[1];

    $dovecotVer = ''; $out = [];
    exec('dovecot --version 2>/dev/null', $out);
    if ($out) $dovecotVer = trim($out[0]);

    $hestiaVer = '';
    $hconf = @file_get_contents('/usr/local/hestia/conf/hestia.conf') ?: '';
    if (preg_match("/VERSION='([^']+)'/", $hconf, $m)) $hestiaVer = $m[1];

    $mainIp = ''; $out = [];
    exec("ip -4 addr show | grep -oP '(?<=inet )\\d+\\.\\d+\\.\\d+\\.\\d+' | grep -v '^127\\.' | grep -v '^172\\.' | head -1", $out);
    if ($out) $mainIp = trim($out[0]);

    return [
        'collected_at'    => date('Y-m-d H:i:s'),
        'hostname'        => $hostname,
        'main_ip'         => $mainIp,
        'os'              => $osName,
        'kernel'          => $kernel,
        'cpu_model'       => $cpuModel,
        'cpu_threads'     => $threads,
        'cpu_cores'       => $physCores,
        'php_versions'    => $phpVersions,
        'nginx_version'   => $nginxVer,
        'apache_version'  => $apacheVer,
        'mariadb_version' => $mariaVer,
        'exim_version'    => $eximVer,
        'dovecot_version' => $dovecotVer,
        'hestia_version'  => $hestiaVer,
    ];
}

// ── Main ─────────────────────────────────────────────────────────────────────

processQueueActions();

$load = sys_getloadavg();
$cpu  = getCpuPercent();
$mem  = getMemInfo();
$disk = getDisk();
$svcs = getServices();

$uptime = (int)file_get_contents('/proc/uptime');

$serverInfo = collectServerInfo();
$siPath = PRIVATE_DIR . '/server_info.json';
file_put_contents($siPath, json_encode($serverInfo, JSON_PRETTY_PRINT));
chown($siPath, WEB_USER);
chmod($siPath, 0644);

$jsonPath = PRIVATE_DIR . '/current.json';
file_put_contents($jsonPath, json_encode([
    'collected_at'  => date('Y-m-d H:i:s'),
    'cpu_percent'   => $cpu,
    'load'          => $load,
    'ram_used_mb'   => $mem['used'],
    'ram_total_mb'  => $mem['total'],
    'ram_pct'       => $mem['total'] > 0 ? round($mem['used']/$mem['total']*100) : 0,
    'disk_used_gb'  => $disk['used'],
    'disk_total_gb' => $disk['total'],
    'disk_free_gb'  => round($disk['total']-$disk['used'],1),
    'disk_pct'      => $disk['total'] > 0 ? round($disk['used']/$disk['total']*100) : 0,
    'uptime_sec'    => $uptime,
    'services'      => $svcs,
]));
chown($jsonPath, WEB_USER);
chmod($jsonPath, 0644);

$accountsMap = [];
foreach (glob('/home/*/web', GLOB_ONLYDIR) as $webDir) {
    $account = basename(dirname($webDir));
    $domains = [];
    foreach (glob("$webDir/*/public_html", GLOB_ONLYDIR) as $siteDir) {
        $domains[] = basename(dirname($siteDir));
    }
    if ($domains) $accountsMap[$account] = $domains;
}
ksort($accountsMap);
$mapPath = PRIVATE_DIR . '/accounts_map.json';
file_put_contents($mapPath, json_encode($accountsMap));
chown($mapPath, WEB_USER);
chmod($mapPath, 0644);

// Process kill requests from web dashboard
$killFile = PRIVATE_DIR . '/kill_requests.json';
if (file_exists($killFile)) {
    $kills = json_decode(file_get_contents($killFile), true) ?: [];
    if ($kills) {
        file_put_contents($killFile, json_encode([]));
        foreach ($kills as $req) {
            $pid     = (int)($req['pid'] ?? 0);
            $account = preg_replace('/[^\w.-]/', '', $req['account'] ?? '');
            if ($pid <= 0 || !$account) continue;
            $pw = @posix_getpwnam($account);
            if (!$pw) continue;
            $status = @file_get_contents("/proc/$pid/status");
            if (!$status) continue;
            preg_match('/Uid:\s+(\d+)/', $status, $m);
            if ((int)($m[1] ?? -1) !== (int)$pw['uid']) continue;
            posix_kill($pid, 9);
        }
    }
}

try {
    $pdo = new PDO(
        'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    createTables($pdo);

    $pdo->prepare("
        INSERT INTO system_metrics
            (cpu_percent,ram_used_mb,ram_total_mb,disk_used_gb,disk_total_gb,
             load_1m,load_5m,load_15m,services_json)
        VALUES(?,?,?,?,?,?,?,?,?)
    ")->execute([
        $cpu, $mem['used'], $mem['total'], $disk['used'], $disk['total'],
        $load[0], $load[1], $load[2], json_encode($svcs),
    ]);
    $pdo->exec("DELETE FROM system_metrics WHERE collected_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");

    collectCpuByAccount($pdo);
    $pdo->exec("DELETE FROM cpu_by_account WHERE collected_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");

    parseEximLogs($pdo);
    collectQueueDetails($pdo);
    collectDiskByAccount($pdo);
    checkSiteUptime($pdo);
    collectAuthEvents($pdo);
    collectSecurityEvents($pdo);

} catch (Exception $e) {
    file_put_contents(__DIR__.'/error.log', date('Y-m-d H:i:s').' '.$e->getMessage()."\n", FILE_APPEND);
}
