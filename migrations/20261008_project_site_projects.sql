CREATE TABLE IF NOT EXISTS project_site_projects (
  order_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  root_order_id BIGINT UNSIGNED NOT NULL,
  external_order_no VARCHAR(100) NOT NULL,
  site_key VARCHAR(160) NOT NULL,
  created_by_type VARCHAR(20) NOT NULL DEFAULT '',
  created_by_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_site_identity (external_order_no, site_key),
  KEY idx_site_root (root_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_site_verifications (
  root_order_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  paid_amount DECIMAL(14,2) NOT NULL,
  allocation_hash CHAR(64) NOT NULL,
  evidence_note VARCHAR(500) NOT NULL,
  verified_by_admin BIGINT UNSIGNED NOT NULL,
  verified_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
