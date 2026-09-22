-- 订单录入时选择的资源要求，供财务审核时校验，避免报备 SSL 成本被遗漏。
CREATE TABLE IF NOT EXISTS project_order_resources (
  order_id BIGINT UNSIGNED PRIMARY KEY,
  source_type ENUM('manual','excel') NOT NULL,
  source_line INT NULL,
  domain_mode ENUM('none','template') NOT NULL,
  domain_template_id BIGINT UNSIGNED NULL,
  server_template_id BIGINT UNSIGNED NULL,
  ssl_expected_amount DECIMAL(14,2) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_project_resource_order FOREIGN KEY (order_id) REFERENCES project_orders(id),
  CONSTRAINT fk_project_resource_domain FOREIGN KEY (domain_template_id) REFERENCES project_cost_templates(id),
  CONSTRAINT fk_project_resource_server FOREIGN KEY (server_template_id) REFERENCES project_cost_templates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
