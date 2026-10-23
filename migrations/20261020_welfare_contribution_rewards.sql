-- One shared contribution record holds evidence and payment status for each welfare submission.
ALTER TABLE project_welfare_contributions
  ADD COLUMN governance_record_id BIGINT UNSIGNED NULL,
  ADD UNIQUE KEY uk_welfare_governance_record (governance_record_id);
