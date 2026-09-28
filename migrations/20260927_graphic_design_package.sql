-- 平面设计（阎泸琪）：逐单不计提成，按月营业额阶梯结算——
-- ≤3000 保底 1800；≤6000 底薪 2100 + 5% + ≥50 元每单 2 元 / <50 元每单 0.5 元；≤9000 2300 + 8% + 3 / 0.5；
-- ≤12000 3300 + 10% + 5 / 0.5；≤15000 4800 + 10% + 5 / 0.5（超过按最高档）；全勤 200；老客户找回 +10%；好评率 <10% 扣 100。
ALTER TABLE project_monthly_rules MODIFY rule_type ENUM('tier_rate','threshold_bonus','ranking','dept_share','fixed','per_unit','base_fee','attendance_bonus','manual','profit_pool','perf_rank','order_count','sales_package') NOT NULL;

INSERT INTO project_commission_rules (commission_group,project_type,role_name,order_kind,calc_mode,rate,service_fee_rate,per_order_subsidy,min_contract_amount,note,effective_from,allow_negative)
SELECT 'technical','平面设计','*','*','pool',0,0,0,0,'平面设计逐单不计提成，按月“营业额阶梯薪酬”结算','2026-01-01',0
WHERE NOT EXISTS (SELECT 1 FROM project_commission_rules WHERE commission_group='technical' AND project_type='平面设计' AND is_active=1);

INSERT INTO project_monthly_rules (name,rule_type,scope_business,scope_group,scope_role,employee_id,metric,params_json,effective_from,note)
SELECT '阎泸琪 营业额阶梯薪酬','sales_package','平面设计','*','*',e.id,'sales',
  '{"tiers":[{"upto":3000,"base":1800,"rate":0,"big":0,"small":0},{"upto":6000,"base":2100,"rate":0.05,"big":2,"small":0.5},{"upto":9000,"base":2300,"rate":0.08,"big":3,"small":0.5},{"upto":12000,"base":3300,"rate":0.1,"big":5,"small":0.5},{"upto":15000,"base":4800,"rate":0.1,"big":5,"small":0.5}],"big_threshold":50,"returning_rate":0.1,"review_min":10,"review_penalty":100}',
  '2026-09','平面设计：按月营业额落档，底薪按考勤折算；老客户找回 +10%；好评率每月填写，低于 10% 扣 100'
FROM employees e WHERE e.name='阎泸琪'
  AND NOT EXISTS (SELECT 1 FROM project_monthly_rules WHERE name='阎泸琪 营业额阶梯薪酬' AND is_active=1)
LIMIT 1;

INSERT INTO project_monthly_rules (name,rule_type,scope_business,scope_group,scope_role,employee_id,metric,params_json,effective_from,note)
SELECT '阎泸琪 全勤奖','attendance_bonus','*','*','*',e.id,'profit','{"amount":200}','2026-09','请假 <4 小时全额、≥4 小时减半、≥8 小时不发'
FROM employees e WHERE e.name='阎泸琪'
  AND NOT EXISTS (SELECT 1 FROM project_monthly_rules WHERE name='阎泸琪 全勤奖' AND is_active=1)
LIMIT 1;

INSERT INTO project_employee_roles (employee_id,business_name,commission_group,role_name)
SELECT e.id,'平面设计','technical','平面设计' FROM employees e WHERE e.name='阎泸琪'
ON DUPLICATE KEY UPDATE role_name=VALUES(role_name);
