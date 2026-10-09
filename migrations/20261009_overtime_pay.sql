-- 超时补贴：固定服务费 ÷ 30 × 延时服务天数 × 倍率；节假日当天（元旦、除夕、春节初一 / 初二、清明、5.1、端午、中秋、10.1）1.5 倍，其余日期 1 倍。
-- 延时服务天数来自考勤表（attendances.overtime_days / holiday_overtime_days，由 ensureAttendanceTable() 或 apply_overtime_pay.php 补建）。
ALTER TABLE project_monthly_rules MODIFY rule_type ENUM('tier_rate','threshold_bonus','ranking','dept_share','fixed','per_unit','base_fee','attendance_bonus','manual','profit_pool','perf_rank','order_count','sales_package','legacy_sheet','legacy_module','overtime_pay') NOT NULL;

INSERT INTO project_monthly_rules (name,rule_type,scope_business,scope_group,scope_role,employee_id,metric,params_json,effective_from,note)
SELECT '超时补贴','overtime_pay','*','*','*',NULL,'profit','{"holiday_rate":1.5,"normal_rate":1}','2026-10',
  '固定服务费 ÷ 30 × 延时服务天数 × 倍率：节假日当天（元旦、除夕、春节初一 / 初二、清明、5.1、端午、中秋、10.1）1.5 倍，其余日期 1 倍；延时服务天数取自考勤表（“26+2”，节假日写“26+1(10.1)”）'
WHERE NOT EXISTS (SELECT 1 FROM project_monthly_rules WHERE rule_type='overtime_pay' AND is_active=1);
