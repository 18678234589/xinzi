-- 订单来源字段独立保存：售价与财务确认的实收严格分开。
CREATE TABLE IF NOT EXISTS project_order_sources (
  order_id BIGINT UNSIGNED PRIMARY KEY,
  payment_nickname VARCHAR(200) NOT NULL DEFAULT '',
  trade_status VARCHAR(100) NOT NULL DEFAULT '',
  price_source ENUM('missing','manual','shop_upload') NOT NULL DEFAULT 'missing',
  nickname_source ENUM('missing','manual','shop_upload') NOT NULL DEFAULT 'missing',
  status_source ENUM('missing','manual','shop_upload') NOT NULL DEFAULT 'missing',
  source_order_id INT NULL,
  synced_at DATETIME NULL,
  CONSTRAINT fk_project_order_source_order FOREIGN KEY (order_id) REFERENCES project_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE project_order_resources MODIFY domain_mode ENUM('pending','none','template') NOT NULL;
