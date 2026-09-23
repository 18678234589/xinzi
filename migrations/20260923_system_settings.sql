-- 系统设置（管理员）：客户联系方式可见权限、AI 接入（OpenAI 兼容）等键值配置。
CREATE TABLE IF NOT EXISTS project_settings (
  setting_key VARCHAR(80) NOT NULL PRIMARY KEY,
  setting_value TEXT NOT NULL,
  updated_by_admin INT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 成本可按售价百分比计价（如华梦定制外包 = 售价 × 80%）；percent 模式下 price 存百分数。
ALTER TABLE project_cost_templates ADD COLUMN price_mode ENUM('fixed','percent') NOT NULL DEFAULT 'fixed' AFTER unit;

-- 已审核订单的售后退款 / 成本冲减：按差额生成正负调整，计入指定（默认下一个未锁定）月份，原快照不变。
CREATE TABLE IF NOT EXISTS project_commission_adjustments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  employee_id INT NOT NULL,
  commission_group ENUM('technical','customer_service') NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  payroll_month CHAR(7) NOT NULL,
  reason VARCHAR(500) NOT NULL,
  calc_note VARCHAR(500) NOT NULL DEFAULT '',
  cash_movement_id BIGINT UNSIGNED NULL,
  created_by_admin INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_project_adjust_month (employee_id, payroll_month),
  KEY idx_project_adjust_order (order_id),
  CONSTRAINT fk_project_adjust_order FOREIGN KEY (order_id) REFERENCES project_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
