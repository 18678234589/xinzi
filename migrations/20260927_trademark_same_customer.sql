-- 商标客服单量补助按当月去重客户计（8 月总表 Sheet2 客户名单：于娜 65、孙荣姿 45）：
-- 同一客服同一客户当月第二单起记“同客户”，照常 (售价 − 成本 − 1%服务费)×12%，不计 3 / 6 元单量补助。
-- 已存在有效的“同客户”规则时不重复插入。
INSERT INTO project_commission_rules (commission_group,project_type,role_name,order_kind,calc_mode,rate,service_fee_rate,per_order_subsidy,min_contract_amount,note,effective_from,allow_negative)
SELECT 'customer_service','商标','*','同客户','pool',0.120000,0.010000,0,0,'商标客服同一客户当月第二单起：(售价 − 成本 − 1%服务费)×12%，不计单量补助','2026-01-01',1
WHERE NOT EXISTS (SELECT 1 FROM project_commission_rules WHERE commission_group='customer_service' AND project_type='商标' AND order_kind='同客户' AND is_active=1);
