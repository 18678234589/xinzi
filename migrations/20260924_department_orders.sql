CREATE TABLE IF NOT EXISTS project_department_orders (
  order_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  department VARCHAR(100) NOT NULL,
  created_by_type VARCHAR(20) NOT NULL,
  created_by_id BIGINT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_department (department)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_department_uploaders (
  order_id BIGINT UNSIGNED NOT NULL,
  employee_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (order_id,employee_id),
  KEY idx_employee (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
