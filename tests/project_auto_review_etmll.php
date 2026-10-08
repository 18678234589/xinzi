<?php
require_once __DIR__.'/../includes/etmll_sync.php';
$n=0;
function are_check($label,$value,$expected){global $n;$n++;if($value!==$expected)throw new RuntimeException($label.': '.json_encode($value));}
function are_map(array $o){$r=etmll_map_order($o);$r['raw']=json_decode($r['raw_json'],true);return $r;}
$o=['id'=>999,'total_amount'=>100,'refund_amount'=>0,'raw_status'=>'交易成功','order_pay_time'=>'2026-09-30 12:34:56.000','created_at'=>'2026-09-29 12:00:00','shop_name'=>'fixture','order_no'=>'fixture-order','product_title'=>'fixture','commission'=>0,'proxy_amount'=>0,'buyer_paid_amount'=>'95.0000','alipay_no'=>'fixture-pay'];
$r=are_map($o);
are_check('sale not overwritten',$r['raw']['__original_price__'],100);
are_check('independent paid',$r['raw']['买家实际支付金额'],95);
are_check('server provenance',$r['raw']['__financial_source__'],'etmll_paid');
are_check('actual paid time',$r['raw']['__actual_pay_time__'],'2026-09-30 12:34:56');
are_check('alipay ref',$r['raw']['支付宝交易号'],'fixture-pay');
$zero=$o;$zero['buyer_paid_amount']=0;$z=are_map($zero);are_check('unknown paid clears evidence',$z['raw']['__financial_source__'],'');are_check('no inherited paid value',$z['raw']['买家实际支付金额'],'');
$missing=$o;unset($missing['buyer_paid_amount']);are_check('sale cannot supply missing paid',are_map($missing)['raw']['买家实际支付金额'],'');
$time=$o;$time['order_pay_time']='';are_check('created time cannot prove payment',are_map($time)['raw']['__financial_source__'],'');
$sig=etmll_sync_signature($r['amount'],$r['order_date'],$r['shop'],$r['order_no'],$r['raw']);
$changed=$o;$changed['buyer_paid_amount']=96;$c=are_map($changed);are_check('paid changes cache signature',$sig!==etmll_sync_signature($c['amount'],$c['order_date'],$c['shop'],$c['order_no'],$c['raw']),true);
$raw=$r['raw'];$raw['__etmll_synced_at__']='2099-01-01 00:00:00';are_check('sync clock not in signature',etmll_sync_signature($r['amount'],$r['order_date'],$r['shop'],$r['order_no'],$raw),$sig);
$raw=$r['raw'];$raw['支付宝交易号']='different-ref';are_check('reference changes signature',etmll_sync_signature($r['amount'],$r['order_date'],$r['shop'],$r['order_no'],$raw)!==$sig,true);
echo "PASS $n ETMLL payment evidence assertions; no database/network writes.\n";
