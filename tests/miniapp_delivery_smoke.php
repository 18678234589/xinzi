<?php
$root = getenv('HEZUOSHANG_APP') ?: dirname(__DIR__);
require_once $root . '/includes/ProjectExpectedSettlement.php';
require_once $root . '/includes/ProjectAutoReviewMath.php';
function delivery_check($name, $ok) {
    if (!$ok) throw new RuntimeException($name);
    echo "PASS $name\n";
}
$order = ['id'=>1,'order_no'=>'DELIVERY-TEST','project_type'=>'小程序开发','order_kind'=>'定制','delivery_status'=>'finished','settlement_status'=>'draft','order_date'=>'2026-09-30','contract_amount'=>100,'receipt_amount'=>100,'refund_amount'=>0];
delivery_check('waiting shipment overrides mistaken completion', ps_miniapp_custom_unshipped($order, ['trade_status'=>'买家已付款,等待卖家发货']));
delivery_check('receipt text and numeric receipt are not shipment', ps_miniapp_custom_unshipped($order, ['trade_status'=>'到账']) && ps_miniapp_custom_unshipped($order, ['trade_status'=>'￥1,000.00']));
delivery_check('shipped orders and other businesses keep existing rules', !ps_miniapp_custom_unshipped($order, ['trade_status'=>'卖家已发货，等待买家确认']) && !ps_miniapp_custom_unshipped(array_replace($order,['project_type'=>'AI网站定制']), ['trade_status'=>'待发货']));
$rule = ['id'=>1,'rate'=>.1,'calc_mode'=>'pool','service_fee_rate'=>0,'per_order_subsidy'=>0];
$person = ['employee_id'=>1,'role_name'=>'技术','group_weight'=>1,'rule'=>$rule,'estimated_calc'=>ps_calc_person($rule,100,0,100,1,0)];
$summary = ['income'=>100,'profit'=>100,'service_fee_rate'=>0,'direct_cost'=>0,'pending_cost'=>0,'groups'=>['technical'=>['people'=>[$person],'weight'=>1,'missing_rule'=>false,'subsidy'=>0,'subsidy_rule'=>false]]];
delivery_check('unshipped expected commission is zero', ps_expected_snapshot_rows($order,$summary,['trade_status'=>'买家已付款,等待卖家发货']) === [] && ps_expected_snapshot_rows(array_replace($order,['delivery_status'=>'unfinished']),$summary) === []);
$rows = ps_expected_snapshot_rows($order,$summary,['trade_status'=>'交易成功']);
$closed = ps_expected_snapshot_rows(array_replace($order,['refund_amount'=>100]),$summary,['trade_status'=>'交易关闭']);
delivery_check('shipped commission and subsequent refund remain supported', (float)$rows[0]['commission_amount'] === 10.0 && (float)$closed[0]['commission_amount'] === 0.0);
$ctx = ['order'=>$order,'source'=>['trade_status'=>'待发货'],'cash'=>[['movement_type'=>'receipt','amount'=>100,'note'=>'核验','review_status'=>'approved']],'costs'=>[],'resource'=>[],'catalog'=>[],'technical_count'=>1,'pending_requests'=>0,'pending_refunds'=>0,'snapshot_count'=>0,'period_status'=>'open','today'=>'2026-10-18','later_refund'=>0,'payment'=>['paid_cents'=>10000,'refund_cents'=>0,'warnings'=>[],'references'=>[],'matched_sources'=>1],'summary'=>$summary];
$blocked = pa_evaluate($ctx);
$ctx['source']['trade_status'] = '交易成功';
$allowed = pa_evaluate($ctx);
delivery_check('automatic approval waits for shipment then resumes', !$blocked['can_apply'] && in_array('miniapp_unshipped',array_column($blocked['reasons'],'code'),true) && $allowed['can_apply']);
