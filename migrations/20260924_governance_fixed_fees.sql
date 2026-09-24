-- 8 月汇总表中两位满月成员的固定服务费和全勤奖；从 2026-09 起作为月度规则。
-- 曲俊泽 8 月只出勤 14 天、实记 1260 元，不能把该数直接作为长期月标准。
INSERT INTO project_monthly_rules (name,rule_type,scope_business,scope_group,scope_role,employee_id,metric,params_json,effective_from,note)
SELECT CONCAT(e.name,' 固定服务费'),'base_fee','*','*','*',e.id,'profit',
       CASE e.name WHEN '栾鑫' THEN '{"amount":3300}' WHEN '冯超' THEN '{"amount":3900}' END,
       '2026-09','据 2026 年 8 月收入表基本工资列设置；按现行考勤口径折算，后续可由财务在规则中心调整'
FROM employees e
WHERE e.name IN ('栾鑫','冯超')
  AND (SELECT COUNT(*) FROM employees x WHERE x.name=e.name)=1
  AND NOT EXISTS (SELECT 1 FROM project_monthly_rules r WHERE r.employee_id=e.id AND r.rule_type='base_fee' AND r.name=CONCAT(e.name,' 固定服务费') AND r.is_active=1);

INSERT INTO project_monthly_rules (name,rule_type,scope_business,scope_group,scope_role,employee_id,metric,params_json,effective_from,note)
SELECT CONCAT(e.name,' 全勤奖'),'attendance_bonus','*','*','*',e.id,'profit','{"amount":200}',
       '2026-09','据 2026 年 8 月收入表全勤奖列设置；实际结算按考勤判定'
FROM employees e
WHERE e.name IN ('栾鑫','冯超')
  AND (SELECT COUNT(*) FROM employees x WHERE x.name=e.name)=1
  AND NOT EXISTS (SELECT 1 FROM project_monthly_rules r WHERE r.employee_id=e.id AND r.rule_type='attendance_bonus' AND r.name=CONCAT(e.name,' 全勤奖') AND r.is_active=1);
