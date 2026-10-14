-- 微信代写：自接的淘宝 / 微信渠道与编辑对接分开核算。
-- 按用户 2026-10-13 明确口径，编辑对接每人每单 2.5 元；新规则不改写已锁月份快照。
-- 角色比通用技术规则更具体；合并单和退款另建零补助规则，防止被通配规则覆盖。
INSERT INTO project_commission_rules
    (commission_group,project_type,role_name,order_kind,calc_mode,rate,service_fee_rate,per_order_subsidy,min_contract_amount,note,effective_from,allow_negative)
SELECT 'technical','软文代写','对接编辑',k.kind,'individual',0,0.057,k.subsidy,0,
       '微信代写对接编辑：每人每单 2.5 元，合并/退款不计单量，不参与自接利润池（2026-10-13 修正）','2026-01-01',0
FROM (SELECT '*' kind,2.50 subsidy UNION ALL SELECT '合并单',0 UNION ALL SELECT '退款冲减',0) k
WHERE NOT EXISTS (SELECT 1 FROM project_commission_rules r WHERE r.commission_group='technical' AND r.project_type='软文代写' AND r.role_name='对接编辑' AND r.order_kind=k.kind AND r.effective_from='2026-01-01' AND r.note LIKE '%2026-10-13 修正%');

-- 微信自接保留零单量补助；淘宝自接原有 3 元规则不变。
-- 合并/退款只保留业绩调整，不给淘宝自接单量补助。
INSERT INTO project_commission_rules
    (commission_group,project_type,role_name,order_kind,calc_mode,rate,service_fee_rate,per_order_subsidy,min_contract_amount,note,effective_from,allow_negative)
SELECT 'customer_service','微信代写','*',k.kind,'pool',0,0,0,0,
       '微信代写合并/退款行：不计自接单量补助（2026-10-13 修正）','2026-01-01',1
FROM (SELECT '合并单' kind UNION ALL SELECT '退款冲减') k
WHERE NOT EXISTS (SELECT 1 FROM project_commission_rules r WHERE r.commission_group='customer_service' AND r.project_type='微信代写' AND r.role_name='*' AND r.order_kind=k.kind AND r.effective_from='2026-01-01');
