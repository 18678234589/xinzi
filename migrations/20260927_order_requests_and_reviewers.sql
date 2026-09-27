-- 20260927: 订单交付凭证申请、产品升级补差申请与业务审核人配置

CREATE TABLE IF NOT EXISTS project_order_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  request_type ENUM('delivery_completion', 'product_upgrade') NOT NULL,
  status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  applicant_type VARCHAR(20) NOT NULL, -- 'employee' 或 'admin'
  applicant_id INT NOT NULL,
  applicant_name VARCHAR(80) NOT NULL,
  data_json JSON NOT NULL,
  reviewer_id INT NULL,
  reviewed_at DATETIME NULL,
  review_note TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_order_req (order_id, request_type),
  INDEX idx_status_type (status, request_type),
  CONSTRAINT fk_por_order FOREIGN KEY (order_id) REFERENCES project_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
