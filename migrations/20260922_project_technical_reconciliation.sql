-- 技术项目分成与旧系统同单分成的逐单核销；旧月度结算记录保持原样。
CREATE TABLE IF NOT EXISTS project_technical_reconciliations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  snapshot_id BIGINT UNSIGNED NOT NULL,
  legacy_salary_month CHAR(7) NULL,
  legacy_salary_id INT NULL,
  legacy_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  basis_note VARCHAR(500) NOT NULL,
  reviewed_by_admin INT NOT NULL,
  reviewed_at DATETIME NOT NULL,
  UNIQUE KEY uk_technical_snapshot (snapshot_id),
  CONSTRAINT fk_reconcile_snapshot FOREIGN KEY (snapshot_id) REFERENCES project_commission_snapshots(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
