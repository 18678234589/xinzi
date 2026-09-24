-- 同一合作人员/业务/表格布局的已确认默认订单类型；显式类型和清晰关键字优先。
CREATE TABLE IF NOT EXISTS project_import_kind_preferences (
  employee_id INT NOT NULL,
  business_name VARCHAR(100) NOT NULL,
  layout_signature CHAR(40) NOT NULL,
  order_kind VARCHAR(80) NOT NULL,
  source VARCHAR(30) NOT NULL DEFAULT 'confirmed_upload',
  confirmed_count INT NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (employee_id,business_name,layout_signature)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
