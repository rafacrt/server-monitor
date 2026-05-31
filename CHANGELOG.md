# Changelog — Server Monitor

Todas as mudanças relevantes do projeto são registradas aqui.

---

## [Não lançado] — 2026-05-30

### Adicionado
- **Análise de Load expandida** (`index.php`): novo bloco "Análise de Load" com 4 cards
  (Load 1m, Load 5m, Load 15m, Saturação % em relação às 12 CPUs), badge de tendência
  (Crescendo / Estável / Caindo) e texto interpretativo automático.
- **Gráfico Load 1m/5m/15m** (`index.php`): gráfico histórico de 3 linhas simultâneas
  com linha de referência tracejada indicando saturação (12 CPUs). Períodos: 6h/24h/7d.
- **API `load-history`** (`api.php`): endpoint novo retornando load_1m, load_5m, load_15m
  e pico (peak1) agrupados por minuto, com suporte a janelas de 1–168 horas.
- **Responsividade mobile** (todas as páginas): media queries em `@media(max-width:680px)`
  adicionadas a `index.php`, `cpu-detail.php`, `email.php`, `queue.php`, `security.php`,
  `uptime.php`, `server-info.php`, `alerts-config.php`. Nav vira scroll horizontal,
  grids colapsam para 1 coluna, tabelas ganham overflow-x.
- `.chart-wrap-tall` (`index.php`): container de 240px para o gráfico de load (mais alto
  que o padrão de 185px para melhor leitura das 3 linhas).
- `CHANGELOG.md`: este arquivo.

### Alterado
- Nav de `cpu-detail.php` convertida de divs com estilos inline para classes `.nav-pills`
  / `.nav-pill`, alinhando com o padrão das demais páginas e permitindo o scroll mobile.
- `.charts-grid` em `index.php`: `minmax(420px,1fr)` → `minmax(min(420px,100%),1fr)`
  para evitar overflow em viewports estreitas.
- `.chart-header` agora usa `flex-wrap:wrap` e `gap:8px` para tabs de período não
  transbordarem em telas pequenas.

---

## [v0.3] — 2026-05-22

### Adicionado
- **Alertas via Telegram** (`collector/alerts.php`): mensagens enviadas ao bot
  `@clauderafacrt_bot` além do canal Slack existente.
- **Página Servidor** (`server-info.php`): exibe versões PHP-FPM, serviços, RAM, disco,
  uptime e informações do SO coletadas em `private/server_info.json`.
- Documentação no `README.md` atualizada com novos canais de alerta e nova página.

### Alterado
- Alertas migrados de Resend (e-mail) para Telegram + Slack (2026-05-19).

---

## [v0.2] — 2026-05-17 (commit 33fafa3)

### Adicionado
- **Initial release** — dashboard completo com:
  - Página Sistema (`index.php`): CPU %, RAM %, Disco, Uptime, serviços ao vivo.
  - Página CPU por Conta (`cpu-detail.php`): top contas, tabela, modal de processos,
    botão "Matar processo".
  - Página E-mail (`email.php` + `email-detail.php` + `email-events.php`): estatísticas
    diárias por domínio, gráficos, IPs atacantes, bounces.
  - Página Fila (`queue.php`): mensagens em fila, ações release/delete.
  - Página Uptime (`uptime.php`): status por domínio, incidentes, histórico 7 dias.
  - Página Segurança (`security.php`): eventos fail2ban, IPs banidos, gráfico de bans.
  - Página Alertas (`alerts-config.php`): limiares configuráveis, silenciamentos.
  - API interna (`api.php`): endpoints para gráficos e ações.
  - Coletor (`collector/collect.php`): métricas de sistema, CPU por conta, e-mail,
    fila, uptime e segurança — roda a cada 5 min via cron.
  - Schema MySQL (`sql/schema.sql`): 12 tabelas.
