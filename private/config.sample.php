<?php
// Copie este arquivo para config.php e preencha com seus dados reais.
// NUNCA envie config.php para o git — ele está no .gitignore.

define('SITE_NAME',   'Meu Servidor Monitor');  // Nome exibido no painel
define('DB_HOST',     'localhost');
define('DB_NAME',     'monitor_db');            // Nome do banco de dados
define('DB_USER',     'monitor_user');          // Usuário MySQL
define('DB_PASS',     'sua_senha_aqui');
define('RESEND_KEY',  're_xxxxxxxxxxxxxxxx');   // API Key do Resend (para alertas por e-mail)
define('ALERT_FROM',  'Monitor <monitor@seudominio.com>');
define('ALERT_TO',    'admin@seudominio.com');  // E-mail padrão para alertas
define('MONITOR_URL', 'https://monitor.seudominio.com');
