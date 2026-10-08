<?php
require_once __DIR__.'/../includes/ProjectReviewPolicy.php';
$n=0;
function rp_check($ok,$text) { global $n; $n++; if(!$ok)throw new RuntimeException($text); }
$fixed=['rate'=>0,'per_order_subsidy'=>1,'low_profit_threshold'=>null];
rp_check(prp_cash_independent('order',$fixed),'pure fixed allowance eligible');
rp_check(!prp_cash_independent('order',array_replace($fixed,['rate'=>0.019])),'percentage plus allowance requires receipt');
rp_check(!prp_cash_independent('order',array_replace($fixed,['low_profit_threshold'=>100])),'profit threshold requires receipt');
rp_check(!prp_cash_independent('order',array_replace($fixed,['per_order_subsidy'=>0])),'zero amount is not a fixed allowance');
prp_validate('order',$fixed,true,'order'); rp_check(true,'matching basis accepted');
foreach([['order',$fixed,true,'monthly'],['order',['rate'=>0.019],true,'order'],['monthly',['rule_type'=>'dept_share'],true,'monthly']] as $t) {
    try { prp_validate($t[0],$t[1],$t[2],$t[3]);rp_check(false,'unsafe policy accepted'); } catch(RuntimeException $e) { rp_check(true,'unsafe policy refused'); }
}
$r=['rule_type'=>'legacy_sheet','params'=>['gate_column'=>'接单客服','counters'=>[['unit'=>1,'column'=>''],['unit'=>0.5,'column'=>'拍建站','keywords'=>'网站链接+小程序链接','match'=>'any']]]];
rp_check(prp_cash_independent('monthly',$r),'fixed monthly sheet is independent');
$rows=[['order_no'=>'A','shop'=>'S','order_amount'=>0,'raw_data'=>['接单客服'=>'郭文娟,刘媛媛','旺旺'=>'客户','日期'=>'2026-09-01','__order_status__'=>'未核验']]];
rp_check(prp_monthly_order_eligible($r,'郭文娟',$rows,'A','S'),'missing receipt can qualify monthly quantity');
rp_check(!prp_monthly_order_eligible($r,'其他人',$rows,'A','S'),'unrelated person excluded');
rp_check(!prp_monthly_order_eligible($r,'郭文娟',$rows,'B','S'),'other order excluded');
rp_check(!prp_monthly_order_eligible($r,'郭文娟',$rows,'A','OTHER'),'other shop excluded');
foreach(['交易关闭','退款成功','退款中','售后中'] as $state) { $x=$rows;$x[0]['raw_data']['__order_status__']=$state;rp_check(!prp_monthly_order_eligible($r,'郭文娟',$x,'A','S'),'refund excluded '.$state); }
$x=$rows;$x[0]['raw_data']['旺旺']='';rp_check(!prp_monthly_order_eligible($r,'郭文娟',$x,'A','S'),'missing count identity excluded');
$x=$rows;$x[0]['raw_data']['旺旺']='';$x[0]['raw_data']['拍建站']='小程序链接';rp_check(prp_monthly_order_eligible($r,'郭文娟',$x,'A','S'),'alternate counter keyword qualifies');
$x=$r;$x['params']['counters'][0]['unit']=-1;rp_check(!prp_cash_independent('monthly',$x),'negative units rejected');
$x=$r;$x['params']['counters'][0]['unit']='abc';rp_check(!prp_cash_independent('monthly',$x),'non numeric units rejected');
echo "PASS $n review policy assertions; no database writes\n";
