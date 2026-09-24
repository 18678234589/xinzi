-- 退款流水与原支付流水分别留存；退款先登记待审，核实原订单后冲减实收并重算分成。
ALTER TABLE project_refund_import_rows ADD COLUMN source_payment_reference VARCHAR(200) NOT NULL DEFAULT '' AFTER payment_method;
CREATE INDEX idx_refund_source_reference ON project_refund_import_rows (source_payment_reference);
CREATE INDEX idx_project_source_payment_reference ON project_order_sources (payment_reference);
