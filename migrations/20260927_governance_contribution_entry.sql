-- 建议 / Bug / 主动做事奖励：财务（管理员账号，无员工 ID）与监委会都可录入、补凭证、登记发放。
ALTER TABLE project_governance_records MODIFY created_by_employee_id INT NULL;
ALTER TABLE project_governance_records ADD COLUMN created_by_admin_id INT NULL AFTER created_by_employee_id;
ALTER TABLE project_governance_records ADD COLUMN reward_status ENUM('unpaid','paid') NULL AFTER flow_note;
ALTER TABLE project_governance_records ADD COLUMN reward_paid_on DATE NULL AFTER reward_status;
ALTER TABLE project_governance_evidence MODIFY uploaded_by_employee_id INT NULL;
ALTER TABLE project_governance_evidence ADD COLUMN uploaded_by_admin_id INT NULL AFTER uploaded_by_employee_id;
