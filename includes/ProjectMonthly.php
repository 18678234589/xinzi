<?php
/**
 * 规则中心 · 月度规则。在已审核的逐单分成快照之上按月汇总计算，未锁定月份实时生效，锁月时冻结结果。
 *
 * 指标（按人、按规则范围内的快照汇总；组池订单按组内权重折算，个人独立订单取本人计提基数）：
 *   profit     毛利 = Σ 计提基数（收入 − 直接成本 − 服务费）
 *   sales      售价 = Σ 可结算收入
 *   commission 已计项目分成 = Σ 逐单分成（含每单补助）
 * 规则类型：
 *   tier_rate        阶梯比例：按月指标落档，全部业绩统一按该档比例，补发 / 扣回与逐单比例的差额（刘帅、外包前端）
 *   threshold_bonus  超额奖金：(指标 − 门槛) × 比例（模板技术 1 万 × 1.5%、网站客服 2 万 × 0.8%）
 *   ranking          排名奖：范围内按指标排名，前几名依次奖励（网站客服 500 / 300 / 200）
 *   dept_share       部门主管提成：所选业务当月总毛利（每单计一次）− 当月其他费用 × 比例 × 分配比例（于洋 5% × 50%）
 *   fixed            固定补助：每月固定金额（经理补助、技术主管补助）
 *   per_unit         计件奖励：当月件数 × 单价，件数由财务在规则中心填写（优站模板每个 15 元）
 *   base_fee         固定服务费（原“基本工资”）：按考勤折算——请假 ≤4 天：金额 − 金额/30 × 请假天数；>4 天：金额/30 × 实际出勤天数；
 *                    当月可填写金额覆盖默认值（如网站客服每月不同的“补单提成”）
 *   overtime_pay     （即加班费，用户界面一律写“超时补贴”）超时补贴：固定服务费 ÷ 30 × 延时服务天数 × 倍率；节假日当天（元旦、除夕、春节初一 / 初二、清明、5.1、端午、中秋、10.1）1.5 倍，其余日期 1 倍；延时服务天数来自考勤表（见 includes/ProjectOvertime.php）
 *   attendance_bonus 全勤奖：默认不发，财务在规则中心“全勤奖审批”批准后按批准金额计入（考勤仅作建议：请假 <4 小时全额、≥4 小时减半、≥8 小时不发）
 *   manual           手工调整：当月逐人填写（上月漏记、未接入系统的业务提成等）
 *   legacy_sheet     原系统单量补贴（精确还原旧 referral_order staff_match）：门控列（接单客服）出现本人姓名 → 整表归属本人，
 *                    再按计列规则去重计数 × 每单金额；门控不通过则 0 元并写明原因（网站售后部单量补贴 ¥1、拍建站链接 ¥0.5）
 *   legacy_module    原系统单模块（直接调用旧引擎）：按员工 algorithms/config_<id>.json 里的同名模块实时计算，配置改动自动跟随，
 *                    找不到该模块时回退到规则参数里的创建时快照 params.module；订单范围与 legacy_sheet 相同（网站售后部部门共享由 dept_config 决定）
 *   sales_package    营业额阶梯薪酬：按月营业额落档，底薪（按考勤折算）+ 营业额 × 比例 + 单量补助 + 老客户找回加成 − 好评率罚款（平面设计）
 * 固定补助可标记“另行支付”（如法人补助），单列展示、不计入应结算金额。
 */
require_once __DIR__ . '/ProjectSettlement.php';
require_once __DIR__ . '/SalaryCalculator.php';
require_once __DIR__ . '/ProjectOvertime.php';
/* split: includes/monthly/rules_data.php */ require_once __DIR__ . '/monthly/rules_data.php';
/* split: includes/monthly/legacy_orders.php */ require_once __DIR__ . '/monthly/legacy_orders.php';
/* split: includes/ProjectMonthlyResults.php */ require_once __DIR__ . '/ProjectMonthlyResults.php';
/* split: includes/monthly/config_presets.php */ require_once __DIR__ . '/monthly/config_presets.php';
