CREATE TABLE IF NOT EXISTS project_user_businesses (
  user_id BIGINT UNSIGNED NOT NULL,
  business_name VARCHAR(100) NOT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id,business_name),
  CONSTRAINT fk_project_user_business_user FOREIGN KEY (user_id) REFERENCES project_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_order_details (
  order_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  business_name VARCHAR(100) NOT NULL,
  details_json JSON NOT NULL,
  CONSTRAINT fk_project_order_details_order FOREIGN KEY (order_id) REFERENCES project_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
