-- 网站核算.xlsx：模板技术 13%、模板客服池 8%、定制客服池 10%。
-- 同组两人时使用参与人权重各 50%，不要创建另一张“客服订单”。
-- 网站模板逐单 3% 店铺服务费由 ps_summary() 按售价计算；月度固定服务费/奖励沿用原结算，不在这里重复计提。
-- 已由财务配置过具体业务规则时不覆盖。网站定制技术继续沿用 AI网站定制 规则。
INSERT INTO project_commission_rules (commission_group,project_type,rate,effective_from)
SELECT 'technical','网站模板',0.130000,'2026-09-01'
WHERE NOT EXISTS (SELECT 1 FROM project_commission_rules WHERE commission_group='technical' AND project_type='网站模板' AND is_active=1);

INSERT INTO project_commission_rules (commission_group,project_type,rate,effective_from)
SELECT 'customer_service','网站模板',0.080000,'2026-09-01'
WHERE NOT EXISTS (SELECT 1 FROM project_commission_rules WHERE commission_group='customer_service' AND project_type='网站模板' AND is_active=1);

INSERT INTO project_commission_rules (commission_group,project_type,rate,effective_from)
SELECT 'customer_service','AI网站定制',0.100000,'2026-09-01'
WHERE NOT EXISTS (SELECT 1 FROM project_commission_rules WHERE commission_group='customer_service' AND project_type='AI网站定制' AND is_active=1);
