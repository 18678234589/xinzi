-- 明确轮值期后，按“每 6 天未交有效脑洞扣 300 元”自动记入董事长奖金池。
CREATE TABLE IF NOT EXISTS project_governance_rotations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  chair_employee_id INT NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NULL,
  note VARCHAR(255) NOT NULL DEFAULT '',
  created_by_employee_id INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_governance_rotation_dates (start_date,end_date),
  CONSTRAINT fk_governance_rotation_chair FOREIGN KEY (chair_employee_id) REFERENCES employees(id),
  CONSTRAINT fk_governance_rotation_creator FOREIGN KEY (created_by_employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_governance_penalties (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rotation_id BIGINT UNSIGNED NOT NULL,
  chair_employee_id INT NOT NULL,
  window_start DATE NOT NULL,
  window_end DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT -300.00,
  state ENUM('applied','waived') NOT NULL DEFAULT 'applied',
  waived_by_employee_id INT NULL,
  waiver_reason VARCHAR(500) NOT NULL DEFAULT '',
  waived_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_governance_penalty_window (rotation_id,window_start),
  KEY idx_governance_penalty_chair (chair_employee_id,window_end),
  CONSTRAINT fk_governance_penalty_rotation FOREIGN KEY (rotation_id) REFERENCES project_governance_rotations(id),
  CONSTRAINT fk_governance_penalty_chair FOREIGN KEY (chair_employee_id) REFERENCES employees(id),
  CONSTRAINT fk_governance_penalty_waiver FOREIGN KEY (waived_by_employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_governance_pools (
  quarter_start DATE NOT NULL,
  pool_role ENUM('chair','committee') NOT NULL,
  opening_amount DECIMAL(12,2) NOT NULL,
  source_note VARCHAR(500) NOT NULL DEFAULT '',
  set_by_employee_id INT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (quarter_start,pool_role),
  CONSTRAINT fk_governance_pool_setter FOREIGN KEY (set_by_employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 用户明确要求执行原规则中的 6 天 / 300 元；其他模糊条款仍保持草案。
UPDATE project_governance_rules
SET cadence_note='每 6 天',penalty_amount=300.00,
    rule_text='每 6 天提交一个可落地的新想法；连续 6 天没有提交，或经监委会评审为无效方案，扣董事长尽职奖金池 300 元。轮值起止日期须由监委会明确登记；系统按每个完整 6 天窗口自动记账，监委会可逐笔说明理由后豁免。有效提出奖励 100 元、开始执行另奖 200 元、烂尾扣 500 元仍须人工核验。',
    rule_state='confirmed',version=version+1
WHERE rule_code='chair_idea' AND rule_state='draft';

UPDATE project_governance_rules
SET reward_amount=10000.00,rule_state='confirmed',version=version+1
WHERE rule_code='chair_pool' AND rule_state='draft';

-- 删除本轮核对过程中临时记录的“每周扣 500”草案；原表明确是 6 天扣 300。
DELETE FROM project_governance_rules WHERE rule_code='chair_idea_missing_weekly_review' AND rule_state='draft';
