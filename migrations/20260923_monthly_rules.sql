-- 规则中心 · 月度规则：阶梯比例、超额奖金、排名奖、部门主管提成、固定补助、计件奖励。
-- 未锁定月份实时计算；锁定项目分成月份时把结果冻结到 project_monthly_results。
CREATE TABLE IF NOT EXISTS project_monthly_rules (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  rule_type ENUM('tier_rate','threshold_bonus','ranking','dept_share','fixed','per_unit') NOT NULL,
  scope_business VARCHAR(200) NOT NULL DEFAULT '*',
  scope_group VARCHAR(20) NOT NULL DEFAULT '*',
  scope_role VARCHAR(80) NOT NULL DEFAULT '*',
  employee_id INT NULL,
  metric ENUM('profit','sales','commission') NOT NULL DEFAULT 'profit',
  params_json JSON NOT NULL,
  effective_from CHAR(7) NOT NULL,
  effective_to CHAR(7) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  note VARCHAR(300) NOT NULL DEFAULT '',
  updated_by_admin INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_monthly_rule_active (is_active, effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_monthly_inputs (
  payroll_month CHAR(7) NOT NULL,
  rule_id BIGINT UNSIGNED NOT NULL,
  employee_id INT NOT NULL DEFAULT 0,
  value DECIMAL(14,2) NOT NULL DEFAULT 0,
  note VARCHAR(200) NOT NULL DEFAULT '',
  updated_by_admin INT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (payroll_month, rule_id, employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_monthly_results (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payroll_month CHAR(7) NOT NULL,
  rule_id BIGINT UNSIGNED NOT NULL,
  rule_name VARCHAR(100) NOT NULL,
  rule_type VARCHAR(30) NOT NULL,
  employee_id INT NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  detail VARCHAR(500) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_monthly_result (payroll_month, employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 固定服务费（按考勤折算）、全勤奖、手工调整；排名可按财务填写的名次。
ALTER TABLE project_monthly_rules MODIFY rule_type ENUM('tier_rate','threshold_bonus','ranking','dept_share','fixed','per_unit','base_fee','attendance_bonus','manual') NOT NULL;
ALTER TABLE project_monthly_rules MODIFY metric ENUM('profit','sales','commission','manual') NOT NULL DEFAULT 'profit';
-- 逐单规则：成本下限（按售价比例），如客服核算博山定制单按售价 65% 计成本。
ALTER TABLE project_commission_rules ADD COLUMN min_cost_rate DECIMAL(7,6) NULL AFTER min_contract_amount;
-- 另行支付的补助（如法人补助）单列展示，不计入应结算金额。
ALTER TABLE project_monthly_results ADD COLUMN paid_separately TINYINT(1) NOT NULL DEFAULT 0 AFTER amount;
-- 逐单分成的未四舍五入金额，用于按月合计后统一四舍五入（与核算表“先合计再取两位小数”一致）。
ALTER TABLE project_commission_snapshots ADD COLUMN commission_exact DECIMAL(18,6) NULL AFTER commission_amount;
