-- 全员福利池：季度规则、不可覆盖的资金流水、部门代录与奖励快照。
CREATE TABLE IF NOT EXISTS project_welfare_policy (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  effective_quarter CHAR(7) NOT NULL DEFAULT '2026-Q4',
  chair_quarter_target DECIMAL(12,2) NOT NULL DEFAULT 10000.00,
  chair_duty_portion DECIMAL(12,2) NOT NULL DEFAULT 7000.00,
  committee_person_target DECIMAL(12,2) NOT NULL DEFAULT 1000.00,
  quarterly_award_rate DECIMAL(5,4) NOT NULL DEFAULT 0.3000,
  quarterly_person_cap_rate DECIMAL(5,4) NOT NULL DEFAULT 0.3000,
  updated_by_admin INT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO project_welfare_policy (id) VALUES (1);

-- 既有管理层规则中的监委会条款改成明确的“每人 1000、三人 3000”；已人工确认的版本不覆盖。
UPDATE project_governance_rules SET reward_amount=3000.00,
  rule_text='每位监委会成员季度目标 1000 元，三人合计 3000 元。已核验有效监督按每条 50 元计入个人额度，另有已核验奖惩按记录计入，个人最多获得 1000 元；季度剩余额度结转全员福利池。具体资金口径见全员福利池规则。',
  version=version+1
WHERE rule_code='committee_pool' AND rule_state='draft' AND rule_text NOT LIKE '%每位监委会成员季度目标 1000 元%';

CREATE TABLE IF NOT EXISTS project_welfare_ledger (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_key VARCHAR(160) NOT NULL,
  event_type VARCHAR(50) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  quarter_key CHAR(7) NULL,
  employee_id INT NULL,
  note VARCHAR(500) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_welfare_source (source_key),
  KEY idx_welfare_quarter (quarter_key,id),
  CONSTRAINT fk_welfare_ledger_employee FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_welfare_managers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  department VARCHAR(100) NOT NULL,
  employee_id INT NOT NULL,
  assigned_by_admin INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_welfare_manager_department (department,employee_id),
  CONSTRAINT fk_welfare_manager_employee FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_welfare_contributions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  created_by_employee_id INT NULL,
  created_by_admin_id INT NULL,
  department VARCHAR(100) NOT NULL,
  kind ENUM('suggestion','bug') NOT NULL,
  title VARCHAR(160) NOT NULL,
  description TEXT NOT NULL,
  evidence_text TEXT NULL,
  occurred_on DATE NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  impact_tier ENUM('normal','implemented','major') NOT NULL DEFAULT 'normal',
  points SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  review_note VARCHAR(500) NOT NULL DEFAULT '',
  reviewed_by_admin_id INT NULL,
  reviewed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_welfare_contribution_period (occurred_on,status),
  KEY idx_welfare_contribution_person (employee_id,occurred_on),
  CONSTRAINT fk_welfare_contribution_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
  CONSTRAINT fk_welfare_contribution_creator FOREIGN KEY (created_by_employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_welfare_closures (
  quarter_key CHAR(7) NOT NULL PRIMARY KEY,
  chair_funding DECIMAL(12,2) NOT NULL,
  committee_funding DECIMAL(12,2) NOT NULL,
  chair_earned DECIMAL(12,2) NOT NULL,
  committee_earned DECIMAL(12,2) NOT NULL,
  penalty_transfers DECIMAL(12,2) NOT NULL,
  remainder_transfer DECIMAL(12,2) NOT NULL,
  details_json LONGTEXT NOT NULL,
  closed_at DATETIME NOT NULL,
  KEY idx_welfare_closed_at (closed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_welfare_awards (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  award_type ENUM('quarterly','annual') NOT NULL,
  period_key VARCHAR(7) NOT NULL,
  employee_id INT NOT NULL,
  points INT UNSIGNED NOT NULL DEFAULT 0,
  amount DECIMAL(12,2) NOT NULL,
  status ENUM('calculated','paid') NOT NULL DEFAULT 'calculated',
  paid_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_welfare_award_person (award_type,period_key,employee_id),
  CONSTRAINT fk_welfare_award_employee FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_welfare_year_roster (
  year_key SMALLINT UNSIGNED NOT NULL,
  employee_id INT NOT NULL,
  eligible_months TINYINT UNSIGNED NOT NULL DEFAULT 12,
  confirmed_by_admin INT NULL,
  confirmed_at DATETIME NULL,
  PRIMARY KEY (year_key,employee_id),
  CONSTRAINT fk_welfare_year_employee FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
