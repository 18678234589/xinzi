-- 项目利润结算 V1：增量建表，不修改旧订单与薪资数据。
-- 在完成数据库备份后，由管理员执行一次。
CREATE TABLE IF NOT EXISTS project_users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  username VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('technical','customer_service') NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_project_username (username),
  UNIQUE KEY uk_project_employee (employee_id),
  CONSTRAINT fk_project_user_employee FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_orders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_no VARCHAR(100) NOT NULL,
  customer_name VARCHAR(200) NOT NULL DEFAULT '',
  project_type VARCHAR(100) NOT NULL DEFAULT 'AI网站定制',
  shop VARCHAR(150) NOT NULL DEFAULT '',
  contract_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  receipt_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  refund_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  order_date DATE NOT NULL,
  delivery_status ENUM('unfinished','finished') NOT NULL DEFAULT 'unfinished',
  settlement_status ENUM('draft','review','approved','locked') NOT NULL DEFAULT 'draft',
  note TEXT NULL,
  created_by_admin INT NULL,
  row_version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_project_order_no (order_no),
  KEY idx_project_order_date (order_date),
  KEY idx_project_status (settlement_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_cost_templates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category ENUM('domain','server','certificate','certification','api','plugin','outsourcing','other') NOT NULL,
  name VARCHAR(150) NOT NULL,
  specification VARCHAR(150) NOT NULL DEFAULT '',
  unit VARCHAR(30) NOT NULL DEFAULT '项',
  cost_kind ENUM('one_time','annual','monthly') NOT NULL DEFAULT 'one_time',
  price DECIMAL(14,2) NOT NULL,
  requires_proof TINYINT(1) NOT NULL DEFAULT 0,
  auto_approve TINYINT(1) NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_project_template_active (is_active, category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_costs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  template_id BIGINT UNSIGNED NULL,
  template_version INT UNSIGNED NULL,
  category VARCHAR(30) NOT NULL,
  item_name VARCHAR(200) NOT NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
  unit VARCHAR(30) NOT NULL DEFAULT '项',
  unit_price DECIMAL(14,2) NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  cost_kind ENUM('one_time','annual','monthly') NOT NULL DEFAULT 'one_time',
  is_custom TINYINT(1) NOT NULL DEFAULT 0,
  reason VARCHAR(500) NOT NULL DEFAULT '',
  proof_path VARCHAR(255) NULL,
  review_status ENUM('approved','pending','rejected') NOT NULL DEFAULT 'pending',
  submitted_by_employee INT NULL,
  reviewed_by_admin INT NULL,
  review_note VARCHAR(500) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_project_cost_order (order_id, review_status),
  CONSTRAINT fk_project_cost_order FOREIGN KEY (order_id) REFERENCES project_orders(id),
  CONSTRAINT fk_project_cost_template FOREIGN KEY (template_id) REFERENCES project_cost_templates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_commission_rules (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  commission_group ENUM('technical','customer_service') NOT NULL,
  project_type VARCHAR(100) NOT NULL DEFAULT '*',
  rate DECIMAL(7,6) NOT NULL,
  effective_from DATE NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_project_rule (commission_group, project_type, effective_from, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_participants (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  employee_id INT NOT NULL,
  commission_group ENUM('technical','customer_service') NOT NULL,
  role_name VARCHAR(80) NOT NULL DEFAULT '',
  group_weight DECIMAL(7,6) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_project_participant (order_id, employee_id, commission_group),
  KEY idx_project_participant_employee (employee_id, order_id),
  CONSTRAINT fk_project_participant_order FOREIGN KEY (order_id) REFERENCES project_orders(id),
  CONSTRAINT fk_project_participant_employee FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_commission_snapshots (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  employee_id INT NOT NULL,
  commission_group ENUM('technical','customer_service') NOT NULL,
  income_amount DECIMAL(14,2) NOT NULL,
  direct_cost DECIMAL(14,2) NOT NULL,
  contribution_profit DECIMAL(14,2) NOT NULL,
  rule_id BIGINT UNSIGNED NOT NULL,
  rate DECIMAL(7,6) NOT NULL,
  group_weight DECIMAL(7,6) NOT NULL,
  commission_amount DECIMAL(14,2) NOT NULL,
  payroll_month CHAR(7) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_project_commission_person (order_id, employee_id, commission_group),
  KEY idx_project_commission_month (employee_id, payroll_month),
  CONSTRAINT fk_project_commission_order FOREIGN KEY (order_id) REFERENCES project_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity_type VARCHAR(40) NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  action VARCHAR(40) NOT NULL,
  actor_type VARCHAR(20) NOT NULL,
  actor_id BIGINT UNSIGNED NOT NULL,
  details_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_project_audit_entity (entity_type, entity_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
