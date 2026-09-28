-- 冯超（网站人事，兼监委会）：固定服务费 3900 + 全勤 200 + 经理补助 100 = 4200；补上经理补助。
INSERT INTO project_monthly_rules (name,rule_type,scope_business,scope_group,scope_role,employee_id,metric,params_json,effective_from,note)
SELECT '冯超 经理补助','fixed','*','*','*',e.id,'profit','{"amount":100,"separate":false}','2026-09','经理补助，每月固定'
FROM employees e WHERE e.name='冯超'
  AND NOT EXISTS (SELECT 1 FROM project_monthly_rules WHERE name='冯超 经理补助' AND is_active=1)
LIMIT 1;

-- 全员福利池期初：上期余下 4500 元（原表：二季度结转 5600，2026-09-02 发放建议奖励 1100 后余 4500）。
INSERT IGNORE INTO project_welfare_ledger (source_key,event_type,amount,quarter_key,employee_id,note)
VALUES ('opening:2026-09-15','opening_balance',4500.00,'2026-Q3',NULL,'上期余下的全员福利池余额（二季度结转 5600 − 9/2 发放建议奖励 1100），管理者确认录入');
