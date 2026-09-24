<?php
require_once __DIR__ . '/../includes/ProjectPartnerDashboard.php';
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
[$from, $to] = ps_partner_month_bounds('2026-08');
check($from === '2026-08-01' && $to === '2026-09-01', 'month bounds');
$rows = [
    ['id'=>1,'order_no'=>'A','order_date'=>'2026-08-05','project_type'=>'网站定制','contract_amount'=>1000,'receipt_amount'=>800,'refund_amount'=>100,'weight'=>0.5,'pending_costs'=>1,'pending_refunds'=>0],
    ['id'=>2,'order_no'=>'B','order_date'=>'2026-08-09','project_type'=>'网站模板','contract_amount'=>600,'receipt_amount'=>0,'refund_amount'=>0,'weight'=>1,'pending_costs'=>0,'pending_refunds'=>0],
];
$s = ps_partner_summary($rows);
check($s['orders'] === 2 && $s['contract'] === 1100.0, 'weighted contract');
check($s['receipts'] === 400.0 && $s['refunds'] === 50.0 && $s['net'] === 350.0, 'weighted cash');
check(abs($s['refund_rate'] - .125) < .0001, 'refund rate');
check($s['alert_count'] === 1 && $s['unverified_cash_orders'] === 1, 'separate data gaps from actionable alerts');
$tips = ps_partner_fallback_guidance($s);
check(count($tips['plan']) === 3 && $tips['future_path'] !== '', 'reskilling fallback');
$empty = ps_partner_summary([]);
check($empty['refund_rate'] === null && $empty['alert_count'] === 0, 'empty rate unavailable');
$validated = ps_partner_ai_validate(['strength'=>'a','risk'=>'b','opportunity'=>'c','future_path'=>'d','plan'=>['e','f','g'],'encouragement'=>'h']);
check(count($validated['plan']) === 3, 'validated AI shape');
$blocked = false;
try { ps_partner_ai_validate(['strength'=>'a','risk'=>'b','opportunity'=>'去接私单','future_path'=>'d','plan'=>['e','f','g'],'encouragement'=>'h']); }
catch (RuntimeException $e) { $blocked = true; }
check($blocked, 'platform cooperation guard');
check(mb_strlen(ps_partner_ai_text(str_repeat('建议先核对。', 40), 80)) <= 80, 'sentence-safe short output');
echo "partner dashboard smoke OK\n";
