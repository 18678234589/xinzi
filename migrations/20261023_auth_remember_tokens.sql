-- 7天内免登录 Token 表：记录已登录用户的有效记住凭据，包含过期时间和账号版本号。可重复执行。
CREATE TABLE IF NOT EXISTS auth_remember_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  token_hash CHAR(64) NOT NULL UNIQUE,
  account_type ENUM('admin', 'employee') NOT NULL,
  account_id INT NOT NULL,
  auth_version INT NOT NULL DEFAULT 0,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_token_expires (token_hash, expires_at),
  KEY idx_account (account_type, account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
