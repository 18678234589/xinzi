-- 逐笔收款与退款；原有项目订单的累计金额以一次性基线记录保留。
CREATE TABLE IF NOT EXISTS project_cash_movements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  movement_type ENUM('receipt','refund') NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  note VARCHAR(500) NOT NULL DEFAULT '',
  source ENUM('manual','opening_balance') NOT NULL DEFAULT 'manual',
  review_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  submitted_by_type VARCHAR(20) NOT NULL,
  submitted_by_id BIGINT UNSIGNED NOT NULL,
  reviewed_by_admin INT NULL,
  reviewed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_project_cash_order (order_id, review_status),
  CONSTRAINT fk_project_cash_order FOREIGN KEY (order_id) REFERENCES project_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO project_cash_movements (order_id,movement_type,amount,note,source,review_status,submitted_by_type,submitted_by_id)
SELECT o.id,'receipt',o.receipt_amount,'迁移前累计实收','opening_balance','approved','system',0
FROM project_orders o
WHERE o.receipt_amount>0 AND NOT EXISTS (
  SELECT 1 FROM project_cash_movements m WHERE m.order_id=o.id AND m.movement_type='receipt'
);

INSERT INTO project_cash_movements (order_id,movement_type,amount,note,source,review_status,submitted_by_type,submitted_by_id)
SELECT o.id,'refund',o.refund_amount,'迁移前累计退款','opening_balance','approved','system',0
FROM project_orders o
WHERE o.refund_amount>0 AND NOT EXISTS (
  SELECT 1 FROM project_cash_movements m WHERE m.order_id=o.id AND m.movement_type='refund'
);
