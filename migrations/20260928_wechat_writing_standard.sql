-- 《微信代写核算标准》（2026-09-27）：
-- 1) 编辑订单（微信代写编辑作为软文代写订单的对接编辑）补助由 2.5 元/单调为 3 元/单，自 2026-09-01 订单起；8 月及以前仍 2.5。
INSERT INTO project_commission_rules (commission_group,project_type,role_name,order_kind,calc_mode,rate,service_fee_rate,per_order_subsidy,min_contract_amount,note,effective_from,allow_negative)
SELECT 'technical','软文代写','*','*','pool',0,0.057,3.00,0,'微信代写编辑订单（对接编辑）补助 3 元/单（《微信代写核算标准》2026-09 起）','2026-09-01',0
WHERE NOT EXISTS (SELECT 1 FROM project_commission_rules WHERE commission_group='technical' AND project_type='软文代写' AND role_name='*' AND order_kind='*' AND effective_from='2026-09-01' AND is_active=1);

-- 2) 利润奖励：微信代写部门月总利润达到 6 万以上，每增加 1 万元，部门每人奖 100 元，封顶 1300 元。
UPDATE project_monthly_rules
SET params_json=JSON_SET(params_json,'$.milestone',JSON_OBJECT('from',60000,'step',10000,'amount',100,'cap',1300)),
    note=CONCAT(note,'；利润 6 万以上每增 1 万每人 +100，封顶 1300')
WHERE name='微信代写部门利润池' AND is_active=1 AND JSON_EXTRACT(params_json,'$.milestone') IS NULL;
