-- 微信付款或无店铺订单号的项目保留真实交易凭据，与系统内部订单编号分开。
ALTER TABLE project_order_sources ADD COLUMN payment_reference VARCHAR(200) NOT NULL DEFAULT '' AFTER payment_nickname;
