-- 代写 / 期刊 / 微信代写：退款冲减按负数计入、低利润单低档补助、部门利润池月度规则。
ALTER TABLE project_commission_rules ADD COLUMN allow_negative TINYINT(1) NOT NULL DEFAULT 0 COMMENT '退款冲减/亏损单按负数计入分成';
ALTER TABLE project_commission_rules ADD COLUMN low_profit_threshold DECIMAL(12,2) NULL COMMENT '整单利润低于此值时改用低档补助';
ALTER TABLE project_commission_rules ADD COLUMN low_profit_subsidy DECIMAL(12,2) NULL COMMENT '低利润单每单补助';
ALTER TABLE project_monthly_rules MODIFY rule_type ENUM('tier_rate','threshold_bonus','ranking','dept_share','fixed','per_unit','base_fee','attendance_bonus','manual','profit_pool','perf_rank') NOT NULL;
