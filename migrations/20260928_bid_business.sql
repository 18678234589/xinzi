-- 标书业务：曹双双、王宁兼标书客服（另兼小程序客服，工资 = 800 + 全勤 200 + 标书提成 + 小程序提成）。
-- 标书客服提成 = (售价 − 成本 − 售价 × 3% 服务费) × 10%；成本 = 设计师佣金合计。
INSERT INTO project_commission_rules (commission_group,project_type,role_name,order_kind,calc_mode,rate,service_fee_rate,per_order_subsidy,min_contract_amount,note,effective_from,allow_negative)
SELECT 'customer_service','标书','*','*','pool',0.100000,0.030000,0,0,'标书客服：(售价 − 设计师佣金成本 − 售价 × 3% 服务费) × 10%','2026-01-01',0
WHERE NOT EXISTS (SELECT 1 FROM project_commission_rules WHERE commission_group='customer_service' AND project_type='标书' AND is_active=1);

-- 两人账号追加第二业务“标书”（小程序客服仍为默认业务）
INSERT INTO project_user_businesses (user_id,business_name,is_default)
SELECT u.id,'标书',0 FROM project_users u JOIN employees e ON e.id=u.employee_id
WHERE e.name IN ('曹双双','王宁') AND u.is_active=1
  AND NOT EXISTS (SELECT 1 FROM project_user_businesses b WHERE b.user_id=u.id AND b.business_name='标书');

INSERT INTO project_employee_roles (employee_id,business_name,commission_group,role_name)
SELECT e.id,'标书','customer_service','标书客服' FROM employees e WHERE e.name IN ('曹双双','王宁')
ON DUPLICATE KEY UPDATE role_name=VALUES(role_name);
