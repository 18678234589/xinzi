-- 管理是账户身份，不是技术/客服分成组，不修改历史分成或主管授权。
ALTER TABLE project_users MODIFY COLUMN role ENUM('technical','customer_service','governance','vault','management') NOT NULL;

CREATE TABLE IF NOT EXISTS project_account_management (
  user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  scope ENUM('assigned','company') NOT NULL DEFAULT 'assigned',
  title VARCHAR(80) NOT NULL DEFAULT '管理',
  fixed_pay_only TINYINT(1) NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_account_management_user FOREIGN KEY (user_id) REFERENCES project_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
