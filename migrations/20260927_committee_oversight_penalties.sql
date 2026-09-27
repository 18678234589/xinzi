-- 监委会督战：轮值董事长每录入一项任务，每位监委须在 7 天内（遇法定节假日顺延）提交监督意见；
-- 逾期未提交自动扣 150 元（从本人本任期监委奖励中扣，任期结束随未获得额度转入全员福利池）。其他监委可写明理由豁免。
CREATE TABLE IF NOT EXISTS project_governance_committee_penalties (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  task_record_id BIGINT UNSIGNED NOT NULL,
  employee_id INT NOT NULL,
  due_date DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  state ENUM('applied','waived') NOT NULL DEFAULT 'applied',
  waived_by_employee_id INT NULL,
  waiver_reason VARCHAR(500) NOT NULL DEFAULT '',
  waived_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_committee_penalty (task_record_id, employee_id),
  KEY idx_committee_penalty_due (due_date, state),
  CONSTRAINT fk_committee_penalty_task FOREIGN KEY (task_record_id) REFERENCES project_governance_records(id),
  CONSTRAINT fk_committee_penalty_member FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

UPDATE project_governance_rules
SET cadence_note='轮值董事长录入任务后 7 天内',
    reward_amount=50.00,
    penalty_amount=150.00,
    rule_text='轮值董事长每录入一项任务（2026-09-28 起，本人录入、未被退回），每位监委会成员须在 7 天内（遇法定节假日顺延）提交监督意见：挂在该任务下或未挂任务的监督记录均可，本人评审该任务也算。逾期未提交，该成员自动扣 150 元，从本人本任期监委奖励中扣减，任期结束随未获得额度转入全员福利池；截止前 2 天站内信督促，扣减后通知本人；其他监委可写明理由豁免，不能自我豁免。有效监督按系统现行口径每条 50 元，个人每任期上限 1000 元。',
    rule_state='confirmed',
    version=version+1
WHERE rule_code='committee_oversight' AND rule_state='draft';
