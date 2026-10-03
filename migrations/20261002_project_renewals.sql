CREATE TABLE IF NOT EXISTS project_renewal_items (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 resource_type VARCHAR(30) NOT NULL,
 resource_name VARCHAR(180) NOT NULL DEFAULT '',
 seed_key VARCHAR(40) NULL,
 expires_on DATE NULL,
 expiry_source VARCHAR(20) NOT NULL DEFAULT 'estimated',
 phone_cipher TEXT NULL,
 phone_hash CHAR(64) NOT NULL DEFAULT '',
 sms_enabled TINYINT NOT NULL DEFAULT 0,
 status VARCHAR(20) NOT NULL DEFAULT 'active',
 revision INT NOT NULL DEFAULT 1,
 note VARCHAR(500) NOT NULL DEFAULT '',
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY renewal_seed(order_id,seed_key),
 KEY renewal_due(status,expires_on), KEY renewal_order(order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_renewal_history (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 item_id BIGINT UNSIGNED NOT NULL,
 action VARCHAR(30) NOT NULL,
 actor_type VARCHAR(20) NOT NULL, actor_id INT NOT NULL,
 old_expiry DATE NULL, new_expiry DATE NULL,
 details_json TEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY renewal_history(item_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_renewal_sms_config (
 id INT NOT NULL PRIMARY KEY,
 enabled TINYINT NOT NULL DEFAULT 0,
 provider VARCHAR(20) NOT NULL DEFAULT 'aliyun',
 access_key_id VARCHAR(120) NOT NULL DEFAULT '',
 secret_cipher TEXT NULL,
 sign_name VARCHAR(80) NOT NULL DEFAULT '',
 template_code VARCHAR(60) NOT NULL DEFAULT '',
 param_map VARCHAR(500) NOT NULL DEFAULT '{"resource":"resource","date":"date","days":"days"}',
 send_hour INT NOT NULL DEFAULT 10,
 daily_limit INT NOT NULL DEFAULT 500,
 revision INT NOT NULL DEFAULT 1,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO project_renewal_sms_config (id) VALUES (1);

CREATE TABLE IF NOT EXISTS project_renewal_sms (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 item_id BIGINT UNSIGNED NOT NULL,
 expires_on DATE NOT NULL,
 remind_days INT NOT NULL,
 phone_hash CHAR(64) NOT NULL,
 state VARCHAR(20) NOT NULL DEFAULT 'pending',
 attempts INT NOT NULL DEFAULT 0,
 next_attempt_at DATETIME NULL,
 attempt_day DATE NULL,
 provider_code VARCHAR(100) NOT NULL DEFAULT '',
 provider_request_id VARCHAR(120) NOT NULL DEFAULT '',
 provider_biz_id VARCHAR(120) NOT NULL DEFAULT '',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY renewal_reminder(item_id,expires_on,remind_days),
 KEY renewal_send(state,next_attempt_at), KEY renewal_phone_day(phone_hash,attempt_day,state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_renewal_job_runs (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 summary_json TEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
