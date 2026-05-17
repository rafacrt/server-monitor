-- Server Monitor — Schema
-- Crie o banco e o usuário antes de importar:
--   CREATE DATABASE monitor_db CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci;
--   CREATE USER 'monitor_user'@'localhost' IDENTIFIED BY 'sua_senha';
--   GRANT ALL PRIVILEGES ON monitor_db.* TO 'monitor_user'@'localhost';
--   FLUSH PRIVILEGES;

CREATE TABLE IF NOT EXISTS `system_metrics` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `collected_at` datetime NOT NULL DEFAULT current_timestamp(),
  `cpu_percent` float NOT NULL DEFAULT 0,
  `ram_used_mb` int(10) unsigned NOT NULL DEFAULT 0,
  `ram_total_mb` int(10) unsigned NOT NULL DEFAULT 0,
  `disk_used_gb` float NOT NULL DEFAULT 0,
  `disk_total_gb` float NOT NULL DEFAULT 0,
  `load_1m` float NOT NULL DEFAULT 0,
  `load_5m` float NOT NULL DEFAULT 0,
  `load_15m` float NOT NULL DEFAULT 0,
  `services_json` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_collected_at` (`collected_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS `cpu_by_account` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `collected_at` datetime NOT NULL DEFAULT current_timestamp(),
  `account` varchar(64) NOT NULL,
  `cpu_pct` float NOT NULL DEFAULT 0,
  `proc_count` smallint(6) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_cat` (`collected_at`),
  KEY `idx_acc` (`account`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS `disk_by_account` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `collected_at` datetime DEFAULT current_timestamp(),
  `account` varchar(100) NOT NULL,
  `disk_mb` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_acc_time` (`account`,`collected_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS `email_stats_daily` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `stat_date` date NOT NULL,
  `domain` varchar(255) NOT NULL,
  `received` int(10) unsigned NOT NULL DEFAULT 0,
  `sent` int(10) unsigned NOT NULL DEFAULT 0,
  `bounced` int(10) unsigned NOT NULL DEFAULT 0,
  `rejected` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dd` (`stat_date`,`domain`),
  KEY `idx_sd` (`stat_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS `email_queue_snap` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `collected_at` datetime NOT NULL DEFAULT current_timestamp(),
  `queue_count` smallint(6) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_cat` (`collected_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS `email_reject_reasons` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `stat_date` date NOT NULL,
  `domain` varchar(255) NOT NULL,
  `reason_type` varchar(30) NOT NULL,
  `count` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_reason` (`stat_date`,`domain`,`reason_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS `email_reject_ips` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `stat_date` date NOT NULL,
  `domain` varchar(255) NOT NULL,
  `sender_ip` varchar(45) NOT NULL,
  `count` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ip` (`stat_date`,`domain`,`sender_ip`),
  KEY `idx_domain_date` (`stat_date`,`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS `email_bounce_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `logged_at` datetime NOT NULL,
  `bounce_date` date NOT NULL,
  `recipient_domain` varchar(255) DEFAULT NULL,
  `recipient` varchar(255) DEFAULT NULL,
  `reason` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_date_domain` (`bounce_date`,`recipient_domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS `email_auth_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_date` date NOT NULL,
  `account` varchar(255) NOT NULL,
  `sender_ip` varchar(45) NOT NULL,
  `send_count` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_auth` (`event_date`,`account`,`sender_ip`),
  KEY `idx_date_account` (`event_date`,`account`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS `security_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `logged_at` datetime NOT NULL,
  `event_type` varchar(20) DEFAULT NULL,
  `jail` varchar(50) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_logged` (`logged_at`),
  KEY `idx_ip` (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS `site_checks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `checked_at` datetime DEFAULT current_timestamp(),
  `domain` varchar(255) NOT NULL,
  `status_code` int(11) DEFAULT NULL,
  `response_ms` int(11) DEFAULT NULL,
  `is_up` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_domain_time` (`domain`,`checked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS `site_incidents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `domain` varchar(255) NOT NULL,
  `started_at` datetime NOT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `last_status_code` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
