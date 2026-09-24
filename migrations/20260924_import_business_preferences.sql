-- 同一合作人员的同版式表格，经实际导入确认后记住其业务归属。
CREATE TABLE IF NOT EXISTS project_import_business_preferences (
  employee_id INT NOT NULL,
  layout_signature CHAR(64) NOT NULL,
  business_name VARCHAR(100) NOT NULL,
  confirmed_count INT NOT NULL DEFAULT 1,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (employee_id, layout_signature),
  CONSTRAINT fk_project_import_business_pref_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
