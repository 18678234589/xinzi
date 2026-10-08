<?php
require_once __DIR__.'/../includes/ProjectAutoReviewMath.php';
$n=0;
function ar_check($name,$actual,$expected){global $n;$n++;if($actual!==$expected)throw new RuntimeException($name.': '.json_encode($actual,JSON_UNESCAPED_UNICODE));}
function ar_base(){return [
    'order'=>['id'=>1,'receipt_amount'=>100,'refund_amount'=>0,'contract_amount'=>100,'order_date'=>'2026-09-30','delivery_status'=>'finished','settlement_status'=>'draft','order_kind'=>'新订单'],
    'cash'=>[['id'=>1,'movement_type'=>'receipt','amount'=>100,'note'=>'财务核验收款','review_status'=>'approved']],
    'costs'=>[],'resource'=>[],'catalog'=>[],'technical_count'=>1,'pending_requests'=>0,'pending_refunds'=>0,'snapshot_count'=>0,'period_status'=>'open','today'=>'2026-10-07','later_refund'=>0,
    'payment'=>['paid_cents'=>null,'refund_cents'=>0,'warnings'=>[],'references'=>[],'matched_sources'=>0],
    'summary'=>['income'=>100,'profit'=>100,'service_fee_rate'=>0,'groups'=>['technical'=>['people'=>[['rule'=>['id'=>9,'allow_negative'=>0,'rate'=>.1]]],'weight'=>1,'missing_rule'=>false,'subsidy'=>0,'subsidy_rule'=>false]]],
];}
function ar_state($name,$change,$state){$ctx=ar_base();$change($ctx);$r=pa_evaluate($ctx);ar_check($name,$r['state'],$state);ar_check($name.' applicable',$r['can_apply'],$state==='ready');return $r;}
ar_check('cents',pa_cents('￥1,234.50元'),123450);
foreach(['1e3','NaN','一百',true,null,[], '12.345','12,34','1,2,3','12 34'] as $v)ar_check('invalid money '.json_encode($v),pa_cents($v),null);
ar_check('negative cents',pa_cents('-12.30'),-1230);
ar_state('verified ledger',function(&$x){},'ready');
ar_state('sale is not receipt',function(&$x){$x['order']['receipt_amount']=0;$x['cash']=[];$x['summary']['income']=0;},'wait_sync');
ar_state('ledger mismatch',function(&$x){$x['order']['receipt_amount']=99;},'exception');
ar_state('unreviewed cash',function(&$x){$x['cash'][]=['review_status'=>'pending'];},'exception');
ar_state('unreviewed external refund',function(&$x){$x['pending_refunds']=1;},'exception');
ar_state('unfinished',function(&$x){$x['order']['delivery_status']='unfinished';},'wait_data');
ar_state('delivery request is not approved',function(&$x){$x['pending_requests']=1;},'exception');
ar_state('resource unconfirmed',function(&$x){$x['catalog']['resources']=true;},'wait_data');
ar_state('technical missing',function(&$x){$x['catalog']['requires_technical']=true;$x['technical_count']=0;},'wait_data');
ar_state('order type missing',function(&$x){$x['catalog']['kind_required']=true;$x['order']['order_kind']='';},'wait_data');
ar_state('locked period',function(&$x){$x['period_status']='locked';},'exception');
ar_state('future date',function(&$x){$x['order']['order_date']='2026-10-08';},'wait_data');
ar_state('invalid date',function(&$x){$x['order']['order_date']='2026-02-30';},'wait_data');
ar_state('no people',function(&$x){$x['summary']['groups']['technical']['people']=[];},'wait_data');
ar_state('missing rule',function(&$x){$x['summary']['groups']['technical']['missing_rule']=true;},'exception');
ar_state('wrong weights',function(&$x){$x['summary']['groups']['technical']['weight']=.9;},'exception');
ar_state('negative profit',function(&$x){$x['summary']['profit']=-1;},'exception');
ar_state('allowed negative',function(&$x){$x['summary']['profit']=-1;$x['summary']['groups']['technical']['people'][0]['rule']['allow_negative']=1;},'ready');
ar_state('existing snapshot',function(&$x){$x['snapshot_count']=1;},'exception');
ar_state('later refund',function(&$x){$x['later_refund']=20;},'exception');
ar_state('pending cost',function(&$x){$x['costs'][]=['category'=>'server','review_status'=>'pending'];},'exception');
ar_state('custom cost lacks proof',function(&$x){$x['costs'][]=['category'=>'server','review_status'=>'approved','amount'=>10,'is_custom'=>1];},'exception');
ar_state('verified custom cost',function(&$x){$x['costs'][]=['category'=>'server','review_status'=>'approved','amount'=>10,'is_custom'=>1,'reviewed_by_admin'=>1];},'ready');
ar_state('wrong cost subtotal',function(&$x){$x['costs'][]=['category'=>'server','review_status'=>'approved','quantity'=>2,'unit_price'=>5,'amount'=>11];},'exception');
ar_state('duplicate cost',function(&$x){$c=['category'=>'server','review_status'=>'approved','amount'=>10];$x['costs']=[$c,$c];},'exception');
ar_state('SSL missing',function(&$x){$x['resource']['ssl_expected_amount']=30;},'exception');
ar_state('sale-based fee lacks sale',function(&$x){$x['order']['contract_amount']=0;$x['summary']['service_fee_rate']=.03;},'wait_data');
ar_state('legacy estimate',function(&$x){$x['cash'][0]['note']='交易成功自动按售价确认实收';},'exception');
ar_state('independently confirmed estimate',function(&$x){$x['cash'][0]['note']='按售价';$x['payment']['paid_cents']=10000;},'ready');
ar_state('confirmed receipt conflict',function(&$x){$x['payment']['paid_cents']=9900;},'exception');
ar_state('unconfirmed discounted payment requires finance',function(&$x){$x['order']['receipt_amount']=0;$x['cash']=[];$x['payment']['paid_cents']=9900;},'exception');
ar_state('payment exceeds sale',function(&$x){$x['payment']['paid_cents']=11000;},'exception');
ar_state('partial deposit not finalized',function(&$x){$x['order']['contract_amount']=200;$x['cash'][0]['note']='首笔定金';},'exception');
ar_state('percentage without price holds',function(&$x){$x['order']['contract_amount']=0;},'wait_data');
ar_state('missing shop refund',function(&$x){$x['payment']['refund_cents']=2000;},'exception');
ar_state('fixed-only subsidy can lack receipt',function(&$x){$x['order']['receipt_amount']=0;$x['cash']=[];$x['summary']['income']=0;$x['summary']['profit']=-10;$x['summary']['groups']['technical']['subsidy_rule']=true;$x['summary']['groups']['technical']['people'][0]['rule']['rate']=0;},'ready');
ar_state('percentage plus subsidy waits for real income',function(&$x){$x['order']['receipt_amount']=0;$x['cash']=[];$x['summary']['income']=0;$x['summary']['profit']=-10;$x['summary']['groups']['technical']['subsidy_rule']=true;},'wait_sync');
ar_state('profit-dependent fixed bonus waits for income',function(&$x){$x['order']['receipt_amount']=0;$x['cash']=[];$x['summary']['income']=0;$x['summary']['groups']['technical']['subsidy_rule']=true;$x['summary']['groups']['technical']['people'][0]['rule']['rate']=0;$x['summary']['groups']['technical']['people'][0]['rule']['low_profit_threshold']=5;},'wait_sync');
$r=ar_state('verified explicit paid column',function(&$x){$x['order']['receipt_amount']=0;$x['cash']=[];$x['payment']['paid_cents']=10000;},'ready');ar_check('receipt plan',$r['receipt_to_add_cents'],10000);
ar_state('approved left untouched',function(&$x){$x['order']['settlement_status']='approved';},'settled');
ar_state('locked left untouched',function(&$x){$x['order']['settlement_status']='locked';},'settled');
ar_state('historic estimate flagged, not rewritten',function(&$x){$x['order']['settlement_status']='approved';$x['cash'][0]['note']='按售价';},'exception');
$source=['id'=>9,'shop'=>'A','raw'=>['__financial_source__'=>'shop_statement','__order_status__'=>'交易成功','实际支付金额（元）'=>'100.00','退款金额'=>'0']];
$e=pa_payment_evidence([$source],'A');ar_check('trusted statement paid',$e['paid_cents'],10000);ar_check('trusted field name',$e['references'][0]['field'],'实际支付金额');
$sale=$source;$sale['raw']=['__financial_source__'=>'shop_statement','__order_status__'=>'交易成功','售价'=>100,'__original_price__'=>100];ar_check('sale not payment',pa_payment_evidence([$sale],'A')['paid_cents'],null);
$etm=$sale;$etm['raw']['__etmll_id__']=10;$etm['raw']['订单金额']=100;ar_check('ETMLL total is not explicit paid',pa_payment_evidence([$etm],'A')['paid_cents'],null);
$untrusted=$source;unset($untrusted['raw']['__financial_source__']);ar_check('partner upload not financial evidence',pa_payment_evidence([$untrusted],'A')['paid_cents'],null);
$other=$source;$other['shop']='B';ar_check('cross-shop not guessed',isset(pa_payment_evidence([$source,$other],'')['warnings']['shop_ambiguous']),true);ar_check('explicit shop isolates proof',pa_payment_evidence([$source,$other],'A')['warnings'],[]);
$conflict=$source;$conflict['raw']['实际支付金额（元）']=90;ar_check('conflicting payments',isset(pa_payment_evidence([$source,$conflict],'A')['warnings']['payment_conflict']),true);
$unpaid=$source;$unpaid['raw']['__order_status__']='等待买家付款';ar_check('unpaid not confirmed',pa_payment_evidence([$unpaid],'A')['paid_cents'],null);
$refund=$source;$refund['raw']['退款金额']=30;ar_check('explicit refund',pa_payment_evidence([$refund],'A')['refund_cents'],3000);
$refund['raw']['__order_status__']='退款中';ar_check('after-sale held',isset(pa_payment_evidence([$refund],'A')['warnings']['source_after_sale']),true);
$paidEtm=$source;$paidEtm['raw']['__financial_source__']='etmll_paid';$paidEtm['raw']['__actual_pay_time__']='2026-09-30 13:00:00';
ar_check('ETMLL explicit paid, not total',pa_payment_evidence([$paidEtm],'A','2026-10-07 12:00:00')['paid_cents'],10000);
$waitingEtm=$paidEtm;$waitingEtm['raw']['__order_status__']='卖家已发货';ar_check('ETMLL not yet settled',pa_payment_evidence([$waitingEtm],'A')['paid_cents'],null);
$missingTime=$paidEtm;unset($missingTime['raw']['__actual_pay_time__']);ar_check('ETMLL payment time missing',pa_payment_evidence([$missingTime],'A')['paid_cents'],null);
ar_check('ETMLL future payment held',pa_payment_evidence([$paidEtm],'A','2026-09-01 00:00:00')['paid_cents'],null);
$refundEtm=$paidEtm;$refundEtm['raw']['退款金额']=20;ar_check('ETMLL refund basis held',isset(pa_payment_evidence([$refundEtm],'A')['warnings']['etmll_refund_basis']),true);
$failed=$source;$failed['raw']['__order_status__']='未支付成功';ar_check('negative success phrase rejected',pa_payment_evidence([$failed],'A')['paid_cents'],null);
$one=$source;$one['order_no']='one';$one['matched_by_reference']=true;$two=$one;$two['order_no']='two';ar_check('combined payment not guessed',isset(pa_payment_evidence([$one,$two],'A')['warnings']['transaction_ambiguous']),true);
$etmOne=$paidEtm;$etmOne['raw']['__etmll_id__']=123;$etmTwo=$etmOne;$etmTwo['id']=999;ar_check('API order source key survives warehouse copies',pa_payment_evidence([$etmOne],'A')['references'][0]['source_key'],pa_payment_evidence([$etmTwo],'A')['references'][0]['source_key']);
foreach(['wait_sync','wait_data','exception','ready','auto_passed','settled','queued'] as $state)ar_check('state label '.$state,count(pa_state_meta($state)),2);
echo "PASS $n automatic-review assertions; no database or network writes.\n";
