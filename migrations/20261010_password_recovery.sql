-- Run through apply_password_recovery.php: administrator hash widening and auth_version are idempotent there.
CREATE TABLE IF NOT EXISTS account_recovery_config (
 id INT NOT NULL PRIMARY KEY, enabled TINYINT NOT NULL DEFAULT 0,
 access_key_id VARCHAR(120) NOT NULL DEFAULT '', secret_cipher TEXT NOT NULL,
 sign_name VARCHAR(80) NOT NULL DEFAULT '山东硕思网络',
 template_code VARCHAR(80) NOT NULL DEFAULT 'SMS_509735214'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO account_recovery_config (id,secret_cipher) VALUES (1,'');
CREATE TABLE IF NOT EXISTS account_recovery_contacts (
 account_type VARCHAR(12) NOT NULL, account_id INT NOT NULL,
 phone_hash CHAR(64) NOT NULL, phone_cipher TEXT NOT NULL, verified_at BIGINT NOT NULL,
 PRIMARY KEY (account_type,account_id), UNIQUE KEY uk_recovery_phone(phone_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS account_recovery_challenges (
 id CHAR(64) NOT NULL PRIMARY KEY, purpose VARCHAR(12) NOT NULL,
 account_type VARCHAR(12) NOT NULL, account_id INT NOT NULL, auth_version INT NOT NULL,
 phone_hash CHAR(64) NOT NULL, phone_cipher TEXT NOT NULL, code_hash VARCHAR(64) NOT NULL,
 session_hash CHAR(64) NOT NULL, ip_hash CHAR(64) NOT NULL, state VARCHAR(16) NOT NULL,
 attempts INT NOT NULL DEFAULT 0, created_at BIGINT NOT NULL, expires_at BIGINT NOT NULL,
 provider_code VARCHAR(100) NOT NULL DEFAULT '',
 KEY idx_recovery_phone(phone_hash,created_at), KEY idx_recovery_ip(ip_hash,created_at),
 KEY idx_recovery_time(created_at), KEY idx_recovery_actor(account_type,account_id),
 KEY idx_recovery_session(session_hash,purpose)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
