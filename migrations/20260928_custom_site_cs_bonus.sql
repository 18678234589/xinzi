-- AI网站定制（定制类订单）客服：每单补助 10 元（2026-09-01 起订单；比例 10%、服务费 3%、博山定制成本按售价 65% 不变）；
-- 每月定制客服毛利第一名奖励 500 元。
INSERT INTO project_commission_rules (commission_group,project_type,role_name,order_kind,calc_mode,rate,service_fee_rate,per_order_subsidy,min_contract_amount,min_cost_rate,note,effective_from,allow_negative)
SELECT 'customer_service','AI网站定制','*','*','pool',0.100000,0.030000,10.00,0,0.650000,'定制客服 10%（两人合接各 5%）+ 每单补助 10 元；博山定制成本按售价 65% 计，华梦外包按实际 80%','2026-09-01',0
WHERE NOT EXISTS (SELECT 1 FROM project_commission_rules WHERE commission_group='customer_service' AND project_type='AI网站定制' AND role_name='*' AND order_kind='*' AND effective_from='2026-09-01' AND is_active=1);

INSERT INTO project_monthly_rules (name,rule_type,scope_business,scope_group,scope_role,employee_id,metric,params_json,effective_from,note)
SELECT '定制客服月度第一名奖','ranking','AI网站定制','customer_service','*',NULL,'profit','{"awards":[500]}','2026-09','AI网站定制客服按当月毛利排名，第一名奖励 500 元'
WHERE NOT EXISTS (SELECT 1 FROM project_monthly_rules WHERE name='定制客服月度第一名奖' AND is_active=1);
