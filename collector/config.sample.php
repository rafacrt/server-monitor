<?php
// Copie para config.php e preencha com seus dados reais.
// Este arquivo é executado como root via cron.

define('DB_HOST',     'localhost');
define('DB_NAME',     'monitor_db');
define('DB_USER',     'monitor_user');
define('DB_PASS',     'sua_senha_aqui');
define('RESEND_KEY',  're_xxxxxxxxxxxxxxxx');
define('SITE_NAME',   'Meu Servidor Monitor');
define('ALERT_FROM',  'Monitor <monitor@seudominio.com>');
define('ALERT_TO',    'admin@seudominio.com');
define('MONITOR_URL', 'https://monitor.seudominio.com');

// Caminho absoluto para o diretório private/ da aplicação web
define('PRIVATE_DIR', '/home/usuario/web/monitor.seudominio.com/private');

// Usuário do sistema que owna os arquivos web (usuário HestiaCP/cPanel)
define('WEB_USER',    'usuario');
