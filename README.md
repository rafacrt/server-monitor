# Server Monitor

Dashboard de monitoramento de servidor Linux em tempo real. Desenvolvido para servidores com **HestiaCP** e **Exim4**, mas adaptável a qualquer stack Linux com PHP e MySQL/MariaDB.

## Funcionalidades

- **Sistema ao vivo** — CPU, RAM, Disco, Uptime e Load
- **Serviços** — status em tempo real de Nginx, Apache, MariaDB, Exim, Dovecot, ClamAV, SpamAssassin, HestiaCP
- **Histórico em gráficos** — CPU e RAM das últimas 6h / 24h / 7d
- **CPU por conta** — top contas por consumo de CPU, timeline e lista de processos
- **E-mail** — estatísticas diárias por domínio (recebidos, enviados, bounces, rejeitados), fila Exim, motivos de rejeição, IPs atacantes
- **Uptime de sites** — monitoramento HTTP de todos os domínios cadastrados, incidentes e tempo de resposta
- **Segurança** — bans/unbans do Fail2ban e falhas SSH do auth.log
- **Alertas via Telegram e Slack** — notificações para CPU, RAM, disco, load, serviços, sites fora e atividade suspeita de e-mail
- **Fila Exim** — listagem de mensagens em fila com ações de release/delete
- **Info do servidor** — hardware, SO, kernel, versões de software (Nginx, Apache, MariaDB, Exim, Dovecot, HestiaCP) e PHP-FPM por versão

## Requisitos

| Componente | Versão mínima |
|---|---|
| PHP | 8.1+ |
| MySQL / MariaDB | 10.5+ |
| Extensões PHP | `pdo_mysql`, `posix`, `curl` |
| Servidor web | Nginx ou Apache |
| MTA | Exim4 (para coleta de e-mail) |
| Fail2ban | qualquer (para coleta de segurança) |
| Telegram Bot | para alertas (obtenha via [@BotFather](https://t.me/BotFather)) |
| Slack Webhook | para alertas (Incoming Webhook do seu workspace) |

O PHP do **coletor** precisa rodar como **root** (via cron) para ler `/proc`, `/var/log` e o spool do Exim.

## Estrutura

```
server-monitor/
├── public_html/          # Raiz do site (DocumentRoot)
│   ├── setup.php         # Configuração inicial (acesse primeiro)
│   ├── _bootstrap.php    # Carrega config e redireciona para setup se necessário
│   ├── index.php         # Dashboard principal
│   ├── api.php           # Endpoint JSON para os gráficos
│   ├── email.php         # Painel de e-mail
│   ├── email-events.php  # Drill-down por tipo de evento
│   ├── email-detail.php  # Detalhes por domínio
│   ├── cpu-detail.php    # CPU por conta
│   ├── queue.php         # Fila Exim
│   ├── uptime.php        # Uptime dos sites
│   ├── security.php      # Segurança / Fail2ban
│   ├── alerts-config.php # Configuração de alertas e silenciamentos
│   └── server-info.php   # Hardware, SO, kernel e versões de software
├── private/              # Fora do DocumentRoot — protegido por .htaccess
│   ├── config.sample.php # Modelo de configuração
│   └── .htaccess         # Bloqueia acesso direto
├── collector/            # Scripts executados como root via cron
│   ├── collect.php       # Coleta todas as métricas
│   ├── alerts.php        # Disparo de alertas via Telegram e Slack
│   └── config.sample.php # Modelo de configuração do coletor
└── sql/
    └── schema.sql        # Schema completo do banco de dados
```

## Instalação

### 1. Clone o repositório

```bash
git clone https://github.com/rafacrt/server-monitor.git /home/usuario/web/monitor.seudominio.com
```

### 2. Configure o servidor web

Aponte o `DocumentRoot` para `public_html/`. O diretório `private/` deve ficar **fora** do DocumentRoot ou estar protegido pelo `.htaccess` incluído.

Exemplo de vhost Nginx:
```nginx
server {
    listen 80;
    server_name monitor.seudominio.com;
    root /home/usuario/web/monitor.seudominio.com/public_html;
    index index.php;

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

### 3. Crie o banco de dados

```sql
CREATE DATABASE monitor_db CHARACTER SET utf8mb4;
CREATE USER 'monitor_user'@'localhost' IDENTIFIED BY 'senha_forte';
GRANT ALL PRIVILEGES ON monitor_db.* TO 'monitor_user'@'localhost';
FLUSH PRIVILEGES;
```

Importe o schema:
```bash
mysql -u monitor_user -p monitor_db < sql/schema.sql
```

### 4. Configure o painel (setup web)

Acesse `https://monitor.seudominio.com/setup.php` e preencha os dados de conexão. O arquivo `private/config.php` será criado automaticamente.

Ou copie e edite manualmente:
```bash
cp private/config.sample.php private/config.php
nano private/config.php
```

### 5. Configure o coletor

```bash
cp collector/config.sample.php collector/config.php
nano collector/config.php
```

Preencha as constantes:

| Constante | Descrição |
|---|---|
| `DB_HOST/DB_NAME/DB_USER/DB_PASS` | Conexão com o banco de dados |
| `SITE_NAME` | Nome exibido no cabeçalho e nos alertas |
| `MONITOR_URL` | URL pública do dashboard (usado nos botões dos alertas) |
| `TELEGRAM_TOKEN` | Token do bot obtido via [@BotFather](https://t.me/BotFather) |
| `TELEGRAM_CHAT_ID` | ID do chat ou grupo que receberá as notificações |
| `SLACK_WEBHOOK` | URL do Incoming Webhook do seu workspace Slack |
| `PRIVATE_DIR` | Caminho absoluto do diretório `private/` |
| `WEB_USER` | Usuário do sistema que owna os arquivos web (ex: `usuario` no HestiaCP) |

### 6. Configure os crons (como root)

```bash
crontab -e
```

Adicione:
```cron
# Coleta de métricas a cada 5 minutos
*/5 * * * * php -d disable_functions= /home/usuario/web/monitor.seudominio.com/collector/collect.php

# Verificação de alertas a cada 5 minutos
*/5 * * * * php /home/usuario/web/monitor.seudominio.com/collector/alerts.php
```

### 7. Permissões do diretório private/

```bash
chmod 750 private/
chmod 640 private/config.php
```

## Configuração de alertas

Acesse `https://monitor.seudominio.com/alerts-config.php` para ajustar:

- **Limites** de CPU, RAM, Disco, Load e fila
- **Cooldowns** (tempo mínimo entre alertas repetidos do mesmo tipo)
- **Silenciamentos** por alerta específico, com duração configurável ou permanente

Os alertas são enviados simultaneamente para **Telegram** e **Slack**. Configure as credenciais no `collector/config.php`.

## Configurando o bot do Telegram

1. Crie um bot via [@BotFather](https://t.me/BotFather) e copie o token
2. Adicione o bot ao grupo ou chat desejado
3. Obtenha o `chat_id`: acesse `https://api.telegram.org/bot<TOKEN>/getUpdates` após enviar uma mensagem ao bot
4. Preencha `TELEGRAM_TOKEN` e `TELEGRAM_CHAT_ID` no `config.php`

## Segurança

- O diretório `private/` contém credenciais e nunca deve ser acessível publicamente
- O `private/.htaccess` bloqueia acesso direto; se usar Nginx, configure o bloco correspondente
- Após a primeira configuração, o `setup.php` só é exibido se o banco de dados estiver inacessível
- Recomenda-se adicionar autenticação HTTP básica (htpasswd) ao painel

## Compatibilidade

Testado em Ubuntu 22.04 / 24.04 com HestiaCP, PHP 8.1–8.3 e MariaDB 10.11.
