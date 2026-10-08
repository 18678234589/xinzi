-- 定制订单的客服组应按订单类型匹配；不要求导入岗位名必须为“定制客服”。
-- 保留技术 30% / 指定技术 15% 的独立规则，不把客服 10% 套给技术。
INSERT INTO project_commission_rules
 (commission_group,project_type,role_name,order_kind,calc_mode,rate,service_fee_rate,per_order_subsidy,min_contract_amount,note,effective_from,is_active)
SELECT 'customer_service','小程序开发','*','定制','pool',0.10,0.03,10,50,
 '小程序定制客服组：整单贡献利润 × 10% + 整单补助 10 元；多人按组权重分摊，普通客服岗位同样适用；售价低于 50 元不计。修复通用 5% 回退。','2026-07-01',1
WHERE NOT EXISTS (SELECT 1 FROM project_commission_rules WHERE commission_group='customer_service' AND project_type='小程序开发' AND role_name='*' AND order_kind='定制' AND effective_from='2026-07-01' AND rate=0.10 AND per_order_subsidy=10 AND is_active=1);
