<?php
$root = getenv('HEZUOSHANG_APP') ?: dirname(__DIR__);
require_once $root . '/includes/ProjectExpectedSettlement.php';
function miniapp_rate_check($name, $ok) {
    if (!$ok) throw new RuntimeException($name);
    echo "PASS $name\n";
}
$order = ['id'=>0,'customer_name'=>'外包吕','project_type'=>'小程序开发','order_kind'=>'定制','order_date'=>'2026-09-08','contract_amount'=>100,'receipt_amount'=>100,'refund_amount'=>0,'delivery_status'=>'finished'];
$people = [['employee_id'=>33,'commission_group'=>'customer_service','role_name'=>'客服','group_weight'=>1],['employee_id'=>53,'commission_group'=>'technical','role_name'=>'定制技术15','group_weight'=>1]];
$external = ps_summary($order,[],$people);
$ai = ps_summary(array_replace($order,['customer_name'=>'AI客户']),[],$people);
$ext = $external['groups']['customer_service']['people'][0];$normal = $ai['groups']['customer_service']['people'][0];
miniapp_rate_check('external customer 5%, AI customer 10%', (float)$ext['calc']['rate']===0.05 && (float)$normal['calc']['rate']===0.1 && (float)$ext['estimated_calc']['rate']===0.05);
miniapp_rate_check('only customer rate changes', $external['groups']['technical']===$ai['groups']['technical'] && $ext['calc']['subsidy']===$normal['calc']['subsidy'] && $ext['calc']['fee']===$normal['calc']['fee']);
$fixed = ps_summary($order,[],[['employee_id'=>33,'commission_group'=>'customer_service','role_name'=>'固定薪酬无提成','group_weight'=>1]]);
miniapp_rate_check('fixed salary role remains without commission', (float)$fixed['groups']['customer_service']['people'][0]['calc']['rate']===0.0);
$new = ps_summary(array_replace($order,['order_kind'=>'新订单']),[],$people);
$similar = ps_summary(array_replace($order,['customer_name'=>'外包吕其他客户']),[],$people);
miniapp_rate_check('new orders and other customers retain rules', (float)$new['groups']['customer_service']['people'][0]['calc']['subsidy']===20.0 && (float)$similar['groups']['customer_service']['people'][0]['calc']['rate']===0.1);
miniapp_rate_check('unshipped custom orders still excluded', ps_expected_snapshot_rows(array_replace($order,['delivery_status'=>'unfinished']),$external)===[]);
