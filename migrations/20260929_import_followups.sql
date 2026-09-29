-- 上传后待补全：导入时缺订单号 / 日期的行（真实订单），记下来弹窗请上传人直接补填提交导入。每个原始表格一条。
CREATE TABLE IF NOT EXISTS project_import_followups (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  file_id INT NOT NULL,
  user_id INT NOT NULL,
  employee_id INT NOT NULL,
  business_name VARCHAR(50) NOT NULL,
  scope VARCHAR(20) NOT NULL DEFAULT 'personal',
  sheets VARCHAR(500) NOT NULL DEFAULT '',
  rows_json MEDIUMTEXT NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'open',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_followup_file (file_id),
  KEY idx_followup_user (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
