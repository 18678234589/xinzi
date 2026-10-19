-- 按客户旺旺去重的单量补助（穆楠等设计客服）：月度规则类型追加 unique_customer，仅在枚举末尾追加，不动现有数据。
ALTER TABLE project_monthly_rules MODIFY rule_type ENUM('tier_rate','threshold_bonus','ranking','dept_share','fixed','per_unit','base_fee','attendance_bonus','manual','profit_pool','perf_rank','order_count','sales_package','legacy_sheet','legacy_module','overtime_pay','unique_customer') NOT NULL;
