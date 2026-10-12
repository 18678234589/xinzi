-- 登录防暴力破解：失败记录（只存哈希，自动清理超过 1 天的记录）。可重复执行。
CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  key_hash CHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_key_time (key_hash, created_at),
  KEY idx_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
