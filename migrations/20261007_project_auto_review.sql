-- Incremental review metadata. No changes to existing money, rules or snapshots.
CREATE TABLE IF NOT EXISTS project_auto_reviews (
  order_id BIGINT UNSIGNED PRIMARY KEY,
  state VARCHAR(24) NOT NULL DEFAULT 'queued',
  policy_version VARCHAR(40) NOT NULL,
  checked_row_version INT UNSIGNED NOT NULL,
  checked_source_at DATETIME NULL,
  fingerprint CHAR(64) NOT NULL,
  reasons_json TEXT NOT NULL,
  evidence_json MEDIUMTEXT NOT NULL,
  checked_at DATETIME NOT NULL,
  applied_at DATETIME NULL,
  KEY idx_auto_review_queue (state,checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_auto_cash_evidence (
  order_id BIGINT UNSIGNED NOT NULL,
  movement_type VARCHAR(16) NOT NULL,
  cash_movement_id BIGINT UNSIGNED NOT NULL,
  source_order_id INT NOT NULL,
  source_key CHAR(64) NULL,
  amount DECIMAL(14,2) NOT NULL,
  evidence_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (order_id,movement_type),
  UNIQUE KEY uk_auto_cash_movement (cash_movement_id),
  UNIQUE KEY uk_auto_cash_source (source_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
