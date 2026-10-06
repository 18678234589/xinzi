-- 退款生效月份：订单下月才发生的退款，在退款所在月份补扣分成，不改动订单所属月份（已核算月份）。NULL 表示随订单所属月份计算。
ALTER TABLE project_cash_movements ADD COLUMN effective_month CHAR(7) NULL AFTER note;
