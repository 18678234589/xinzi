-- 平台 / 服务器信息（仅白名单可见）与项目账号密码（跟随订单权限）。密码、备注为 AES-256-GCM 密文，密钥在 config/vault_key.php。
CREATE TABLE IF NOT EXISTS project_vault_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category VARCHAR(30) NOT NULL DEFAULT '其他',
  name VARCHAR(120) NOT NULL,
  url VARCHAR(500) NOT NULL DEFAULT '',
  host VARCHAR(200) NOT NULL DEFAULT '',
  account VARCHAR(200) NOT NULL DEFAULT '',
  password_enc TEXT NOT NULL,
  notes_enc TEXT NOT NULL,
  created_by_type VARCHAR(20) NOT NULL,
  created_by_id INT NOT NULL,
  updated_by_type VARCHAR(20) NOT NULL,
  updated_by_id INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  KEY idx_vault_category (category, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_order_credentials (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  kind VARCHAR(30) NOT NULL DEFAULT '其他',
  label VARCHAR(120) NOT NULL DEFAULT '',
  url VARCHAR(500) NOT NULL DEFAULT '',
  account VARCHAR(200) NOT NULL DEFAULT '',
  password_enc TEXT NOT NULL,
  notes_enc TEXT NOT NULL,
  created_by_type VARCHAR(20) NOT NULL,
  created_by_id INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  KEY idx_order_credentials (order_id, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 平台信息专用账号（管理层查看平台信息，不参与订单）
ALTER TABLE project_users MODIFY role ENUM('technical','customer_service','governance','vault') NOT NULL;
