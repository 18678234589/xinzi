-- 合作人员月度 AI 经营复盘缓存；只存匿名汇总生成的建议，不存客户信息。
CREATE TABLE IF NOT EXISTS project_partner_insights (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  period CHAR(7) NOT NULL,
  input_hash CHAR(64) NOT NULL,
  insight_json TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_partner_insight_input (employee_id, period, input_hash),
  KEY idx_partner_insight_period (period, employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
