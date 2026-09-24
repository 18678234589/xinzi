-- 管理层激励考核：成员白名单与独立审核台账，不自动写入项目报酬。
CREATE TABLE IF NOT EXISTS project_governance_members (
  employee_id INT NOT NULL PRIMARY KEY,
  governance_role ENUM('chair','committee') NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_governance_member_employee FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_governance_records (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  record_kind ENUM('chair','committee','contribution') NOT NULL,
  owner_employee_id INT NOT NULL,
  record_date DATE NOT NULL,
  category VARCHAR(120) NOT NULL DEFAULT '',
  description TEXT NOT NULL,
  commitment_note VARCHAR(160) NOT NULL DEFAULT '',
  due_date DATE NULL,
  due_note VARCHAR(160) NOT NULL DEFAULT '',
  evidence_text TEXT NULL,
  outcome_status VARCHAR(80) NOT NULL DEFAULT '待评判',
  reviewer_employee_id INT NULL,
  bonus_delta DECIMAL(12,2) NULL,
  flow_note VARCHAR(255) NOT NULL DEFAULT '',
  review_state ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  review_note VARCHAR(500) NOT NULL DEFAULT '',
  parent_record_id BIGINT UNSIGNED NULL,
  created_by_employee_id INT NOT NULL,
  reviewed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_governance_date (record_date, id),
  KEY idx_governance_owner (owner_employee_id, record_date),
  KEY idx_governance_review (review_state, record_date),
  CONSTRAINT fk_governance_record_owner FOREIGN KEY (owner_employee_id) REFERENCES employees(id),
  CONSTRAINT fk_governance_record_creator FOREIGN KEY (created_by_employee_id) REFERENCES employees(id),
  CONSTRAINT fk_governance_record_reviewer FOREIGN KEY (reviewer_employee_id) REFERENCES employees(id),
  CONSTRAINT fk_governance_record_parent FOREIGN KEY (parent_record_id) REFERENCES project_governance_records(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_governance_evidence (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  record_id BIGINT UNSIGNED NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(80) NOT NULL,
  mime_type VARCHAR(60) NOT NULL,
  file_size INT UNSIGNED NOT NULL,
  uploaded_by_employee_id INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_governance_evidence_record (record_id),
  CONSTRAINT fk_governance_evidence_record FOREIGN KEY (record_id) REFERENCES project_governance_records(id),
  CONSTRAINT fk_governance_evidence_uploader FOREIGN KEY (uploaded_by_employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_governance_rules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rule_code VARCHAR(80) NOT NULL,
  title VARCHAR(160) NOT NULL,
  role_scope ENUM('chair','committee','all') NOT NULL,
  cadence_note VARCHAR(120) NOT NULL DEFAULT '',
  reward_amount DECIMAL(12,2) NULL,
  penalty_amount DECIMAL(12,2) NULL,
  rule_text TEXT NOT NULL,
  rule_state ENUM('draft','confirmed') NOT NULL DEFAULT 'draft',
  version INT UNSIGNED NOT NULL DEFAULT 1,
  updated_by_employee_id INT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_governance_rule_code (rule_code),
  CONSTRAINT fk_governance_rule_editor FOREIGN KEY (updated_by_employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 按原表“人员配置”精确姓名初始化；重名不猜测。停用/调整后的成员不会被重复迁移覆盖。
INSERT IGNORE INTO project_governance_members (employee_id, governance_role)
SELECT e.id, 'chair' FROM employees e
WHERE e.name IN ('于洋','栾鑫','翟建跃')
  AND (SELECT COUNT(*) FROM employees e2 WHERE e2.name=e.name)=1;
INSERT IGNORE INTO project_governance_members (employee_id, governance_role)
SELECT e.id, 'committee' FROM employees e
WHERE e.name IN ('刘玉霜','曲俊泽','冯超')
  AND (SELECT COUNT(*) FROM employees e2 WHERE e2.name=e.name)=1;

-- 原表“核心规则说明”；全部先作为待确认口径，不直接计算或扣发。
INSERT IGNORE INTO project_governance_rules (rule_code,title,role_scope,cadence_note,reward_amount,penalty_amount,rule_text) VALUES
('chair_pool','轮值董事长季度尽职奖金池','chair','每季度',NULL,NULL,'原表记载初始额度 10000 元。历史记录和奖金计算表尚未完全勾稽，余额须人工核对。'),
('committee_pool','监委会季度尽职奖金池','committee','每季度',NULL,NULL,'原表记载初始额度 3000 元。实际奖惩按核验记录逐笔登记，余额须人工核对。'),
('chair_idea','新想法与落地','chair','原规则写每 6 天；历史记录有每 3 天 / 每周',100.00,-300.00,'有效提出建议奖励 100 元；未提交或敷衍被否决扣 300 元。开始执行可另奖 200 元，烂尾可扣 500 元。周期存在来源冲突，确认后再启用。'),
('chair_promise','承诺与逾期','chair','按约定截止节点',NULL,-300.00,'承诺到期未交付，每拖延 3 天原规则写扣 300 元；需记录承诺、截止和实际进展，由监委会核验。'),
('chair_hard_problem','解决核心卡点','chair','每月主动认领 1–2 项',500.00,-500.00,'彻底解决一个核心难题原规则写奖励 500 元；15 天无动作后每周扣 500 元。须有可核实结果。'),
('committee_oversight','监委会监督反馈','committee','原规则写每 6 个工作日',150.00,-150.00,'有效监督反馈原规则写奖励 150 元；到期无人发布监督结果，每位成员扣 150 元。由其他监委核验，不可自评。'),
('procurement_quotes','大额采购比价','all','采购前',NULL,NULL,'原表提出超过 5000 元的采购需公示至少三个供应商报价对比。作为记录和审核要求，暂不与项目成本审批自动联动。');
