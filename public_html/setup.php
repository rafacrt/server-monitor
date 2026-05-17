<?php
$configFile = dirname(__DIR__) . '/private/config.php';
$installed  = false;
$error      = '';
$success    = false;

// Se já instalado e BD conecta, redireciona
if (file_exists($configFile)) {
    require $configFile;
    try {
        $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        header('Location: index.php'); exit;
    } catch (Exception $e) {
        $error = 'Configuração existente, mas a conexão com o banco falhou: ' . $e->getMessage();
        $installed = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $siteName   = trim($_POST['site_name']   ?? '');
    $dbHost     = trim($_POST['db_host']     ?? 'localhost');
    $dbName     = trim($_POST['db_name']     ?? '');
    $dbUser     = trim($_POST['db_user']     ?? '');
    $dbPass     = $_POST['db_pass']          ?? '';
    $resendKey  = trim($_POST['resend_key']  ?? '');
    $alertFrom  = trim($_POST['alert_from']  ?? '');
    $alertTo    = trim($_POST['alert_to']    ?? '');
    $monitorUrl = rtrim(trim($_POST['monitor_url'] ?? ''), '/');

    if (!$siteName || !$dbName || !$dbUser || !$alertTo || !$monitorUrl) {
        $error = 'Preencha todos os campos obrigatórios.';
    } elseif (!filter_var($alertTo, FILTER_VALIDATE_EMAIL)) {
        $error = 'E-mail de alertas inválido.';
    } else {
        try {
            $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            $escapedAlertFrom = !$alertFrom ? "Monitor <monitor@{$_SERVER['HTTP_HOST']}>" : $alertFrom;

            $cfg = "<?php\n"
                . "define('SITE_NAME',   " . var_export($siteName, true) . ");\n"
                . "define('DB_HOST',     " . var_export($dbHost, true) . ");\n"
                . "define('DB_NAME',     " . var_export($dbName, true) . ");\n"
                . "define('DB_USER',     " . var_export($dbUser, true) . ");\n"
                . "define('DB_PASS',     " . var_export($dbPass, true) . ");\n"
                . "define('RESEND_KEY',  " . var_export($resendKey, true) . ");\n"
                . "define('ALERT_FROM',  " . var_export($escapedAlertFrom, true) . ");\n"
                . "define('ALERT_TO',    " . var_export($alertTo, true) . ");\n"
                . "define('MONITOR_URL', " . var_export($monitorUrl, true) . ");\n";

            if (file_put_contents($configFile, $cfg) === false) {
                $error = 'Não foi possível gravar o arquivo de configuração. Verifique as permissões do diretório private/.';
            } else {
                chmod($configFile, 0640);
                $success = true;
            }
        } catch (Exception $e) {
            $error = 'Não foi possível conectar ao banco de dados: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Configuração — Server Monitor</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#0f172a;color:#e2e8f0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
.box{background:#1e293b;border:1px solid #334155;border-radius:12px;padding:36px 40px;width:100%;max-width:520px}
h1{font-size:1.2rem;font-weight:700;color:#f1f5f9;margin-bottom:6px}
.sub{font-size:.82rem;color:#64748b;margin-bottom:28px}
.field{margin-bottom:18px}
label{display:block;font-size:.75rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px}
label .req{color:#ef4444}
input{width:100%;background:#0f172a;border:1px solid #334155;border-radius:7px;padding:9px 12px;color:#e2e8f0;font-size:.9rem;outline:none;transition:border-color .15s}
input:focus{border-color:#38bdf8}
input::placeholder{color:#475569}
.hint{font-size:.72rem;color:#475569;margin-top:4px}
.sep{border:none;border-top:1px solid #334155;margin:24px 0}
.error{background:#450a0a;border:1px solid #b91c1c;border-radius:8px;padding:12px 16px;font-size:.82rem;color:#fca5a5;margin-bottom:20px}
.success{background:#052e16;border:1px solid #166534;border-radius:8px;padding:16px;font-size:.88rem;color:#86efac;margin-bottom:20px;line-height:1.6}
.success a{color:#38bdf8;text-decoration:none;font-weight:600}
.success a:hover{text-decoration:underline}
.btn{width:100%;background:#0284c7;border:none;border-radius:8px;padding:11px;color:#fff;font-size:.95rem;font-weight:600;cursor:pointer;transition:background .15s;margin-top:4px}
.btn:hover{background:#0369a1}
.row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
</style>
</head>
<body>
<div class="box">
  <h1>Server Monitor</h1>
  <p class="sub">Configure o painel para o seu servidor. Isso cria o arquivo <code>private/config.php</code>.</p>

  <?php if ($error): ?>
    <div class="error">⚠ <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if ($success): ?>
    <div class="success">
      ✓ Configuração salva com sucesso!<br><br>
      Próximos passos:<br>
      1. Importe o schema: <code>mysql -u <?= htmlspecialchars($_POST['db_user'] ?? '') ?> -p <?= htmlspecialchars($_POST['db_name'] ?? '') ?> &lt; sql/schema.sql</code><br>
      2. Configure o coletor em <code>collector/config.php</code><br>
      3. Adicione o cron (como root): <code>*/5 * * * * php /caminho/collector/collect.php</code><br><br>
      <a href="index.php">→ Acessar o painel</a>
    </div>
  <?php else: ?>

  <form method="POST">
    <div class="field">
      <label>Nome do Painel <span class="req">*</span></label>
      <input type="text" name="site_name" value="<?= htmlspecialchars($_POST['site_name'] ?? '') ?>" placeholder="Meu Servidor" required>
      <div class="hint">Aparece no cabeçalho do dashboard</div>
    </div>

    <hr class="sep">
    <div style="font-size:.72rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.07em;margin-bottom:14px">Banco de Dados</div>

    <div class="row">
      <div class="field">
        <label>Host <span class="req">*</span></label>
        <input type="text" name="db_host" value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>" required>
      </div>
      <div class="field">
        <label>Nome do Banco <span class="req">*</span></label>
        <input type="text" name="db_name" value="<?= htmlspecialchars($_POST['db_name'] ?? '') ?>" placeholder="monitor_db" required>
      </div>
    </div>

    <div class="row">
      <div class="field">
        <label>Usuário <span class="req">*</span></label>
        <input type="text" name="db_user" value="<?= htmlspecialchars($_POST['db_user'] ?? '') ?>" required>
      </div>
      <div class="field">
        <label>Senha</label>
        <input type="password" name="db_pass" value="">
      </div>
    </div>

    <hr class="sep">
    <div style="font-size:.72rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.07em;margin-bottom:14px">Alertas por E-mail</div>

    <div class="field">
      <label>API Key do Resend</label>
      <input type="text" name="resend_key" value="<?= htmlspecialchars($_POST['resend_key'] ?? '') ?>" placeholder="re_xxxxxxxxxxxxxxxx">
      <div class="hint">Necessário para envio de alertas. Obtenha em resend.com</div>
    </div>

    <div class="field">
      <label>Remetente</label>
      <input type="text" name="alert_from" value="<?= htmlspecialchars($_POST['alert_from'] ?? '') ?>" placeholder="Monitor <monitor@seudominio.com>">
    </div>

    <div class="field">
      <label>E-mail de alertas <span class="req">*</span></label>
      <input type="email" name="alert_to" value="<?= htmlspecialchars($_POST['alert_to'] ?? '') ?>" placeholder="admin@seudominio.com" required>
    </div>

    <div class="field">
      <label>URL do Painel <span class="req">*</span></label>
      <input type="url" name="monitor_url" value="<?= htmlspecialchars($_POST['monitor_url'] ?? '') ?>" placeholder="https://monitor.seudominio.com" required>
    </div>

    <button type="submit" class="btn">Salvar configuração</button>
  </form>

  <?php endif; ?>
</div>
</body>
</html>
