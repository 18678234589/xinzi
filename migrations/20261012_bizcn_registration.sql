CREATE TABLE IF NOT EXISTS bizcn_registration_settings (
 provider_account VARCHAR(100) PRIMARY KEY,
 daily_limit INT UNSIGNED NOT NULL DEFAULT 12,
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 templates_json TEXT NOT NULL,
 ns_json TEXT NOT NULL,
 updated_by INT NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS bizcn_registration_requests (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 provider_account VARCHAR(100) NOT NULL,
 token_hash CHAR(64) NOT NULL UNIQUE,
 actor_key VARCHAR(60) NOT NULL,
 domainname VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 quote_json TEXT NOT NULL,
 expires_at BIGINT NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'quoted',
 domain_lock CHAR(64) NULL UNIQUE,
 usage_day DATE NULL,
 confirmed_day DATE NULL,
 provider_started_at BIGINT NULL,
 order_id VARCHAR(100) NOT NULL DEFAULT '',
 result_json TEXT NULL,
 resolution_note VARCHAR(500) NOT NULL DEFAULT '',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY idx_bizcn_quota (provider_account,status,usage_day),
 KEY idx_bizcn_actor (provider_account,actor_key,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS bizcn_registration_templates (
 provider_account VARCHAR(100) NOT NULL,
 template_id VARCHAR(100) NOT NULL,
 label VARCHAR(120) NOT NULL,
 owner_label VARCHAR(200) NOT NULL,
 refreshed_at BIGINT NOT NULL,
 PRIMARY KEY (provider_account,template_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
