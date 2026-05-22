<?php
// Copie para config.php e preencha com seus dados reais.
// Este arquivo é executado como root via cron.

define('DB_HOST',     'localhost');
define('DB_NAME',     'monitor_db');
define('DB_USER',     'monitor_user');
define('DB_PASS',     'sua_senha_aqui');

define('SITE_NAME',   'Meu Servidor');
define('MONITOR_URL', 'https://monitor.seudominio.com');

// Notificações via Telegram
define('TELEGRAM_TOKEN',   'BOT_TOKEN_AQUI');
define('TELEGRAM_CHAT_ID', 'CHAT_ID_AQUI');

// Notificações via Slack (Incoming Webhook)
define('SLACK_WEBHOOK', 'https://hooks.slack.com/services/XXXXX/YYYYY/ZZZZZ');

// Caminho absoluto para o diretório private/ da aplicação web
define('PRIVATE_DIR', '/home/usuario/web/monitor.seudominio.com/private');

// Usuário do sistema que owna os arquivos web (usuário HestiaCP/cPanel)
define('WEB_USER',    'usuario');
