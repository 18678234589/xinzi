-- 已确认交接：于洋于 2026-09-14 结束，栾鑫 2026-09-15 接任；不臆造于洋的开始日期。
ALTER TABLE project_governance_rotations MODIFY created_by_employee_id INT NULL;
CREATE TABLE IF NOT EXISTS project_governance_handovers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  previous_chair_employee_id INT NOT NULL,
  next_chair_employee_id INT NOT NULL,
  handover_date DATE NOT NULL,
  source_note VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_governance_handover_date (handover_date),
  CONSTRAINT fk_gov_handover_previous FOREIGN KEY (previous_chair_employee_id) REFERENCES employees(id),
  CONSTRAINT fk_gov_handover_next FOREIGN KEY (next_chair_employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS project_governance_rotation_guard (
  rotation_id BIGINT UNSIGNED PRIMARY KEY,
  penalty_effective_from DATE NOT NULL,
  CONSTRAINT fk_gov_guard_rotation FOREIGN KEY (rotation_id) REFERENCES project_governance_rotations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS project_governance_elections (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rotation_id BIGINT UNSIGNED NOT NULL,
  reminder_date DATE NOT NULL,
  deadline_date DATE NOT NULL,
  status ENUM('reminder','voting','closed') NOT NULL DEFAULT 'reminder',
  opened_by_employee_id INT NULL,
  opened_at DATETIME NULL,
  elected_employee_id INT NULL,
  closed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_gov_election_rotation (rotation_id),
  CONSTRAINT fk_gov_election_rotation FOREIGN KEY (rotation_id) REFERENCES project_governance_rotations(id),
  CONSTRAINT fk_gov_election_opener FOREIGN KEY (opened_by_employee_id) REFERENCES employees(id),
  CONSTRAINT fk_gov_election_winner FOREIGN KEY (elected_employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS project_governance_votes (
  election_id BIGINT UNSIGNED NOT NULL,
  voter_employee_id INT NOT NULL,
  candidate_employee_id INT NOT NULL,
  voted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (election_id,voter_employee_id),
  CONSTRAINT fk_gov_vote_election FOREIGN KEY (election_id) REFERENCES project_governance_elections(id),
  CONSTRAINT fk_gov_vote_voter FOREIGN KEY (voter_employee_id) REFERENCES employees(id),
  CONSTRAINT fk_gov_vote_candidate FOREIGN KEY (candidate_employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO project_governance_handovers (previous_chair_employee_id,next_chair_employee_id,handover_date,source_note)
SELECT prev.id,next.id,'2026-09-15','用户确认：2026-09-15 于洋换届为栾鑫'
FROM employees prev CROSS JOIN employees next
WHERE prev.name='于洋' AND next.name='栾鑫'
  AND (SELECT COUNT(*) FROM employees WHERE name='于洋')=1
  AND (SELECT COUNT(*) FROM employees WHERE name='栾鑫')=1;
INSERT INTO project_governance_rotations (chair_employee_id,start_date,end_date,note,created_by_employee_id)
SELECT e.id,'2026-09-15','2026-12-14','按用户确认的 2026-09-15 换届事实补录；历史不追扣',NULL
FROM employees e WHERE e.name='栾鑫' AND (SELECT COUNT(*) FROM employees WHERE name='栾鑫')=1
AND NOT EXISTS (SELECT 1 FROM project_governance_rotations WHERE start_date<='2026-09-15' AND (end_date IS NULL OR end_date>='2026-09-15'));
INSERT IGNORE INTO project_governance_rotation_guard (rotation_id,penalty_effective_from)
SELECT id,CURDATE() FROM project_governance_rotations WHERE start_date='2026-09-15' AND end_date='2026-12-14' AND note LIKE '按用户确认的%';
