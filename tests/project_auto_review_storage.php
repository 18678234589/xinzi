<?php
/** Explicit rollback-only fixtures, never mutate any existing order. All helpers must be migrated first. */
if (PHP_SAPI!=='cli'||!in_array('--rollback-fixtures',$argv,true)){fwrite(STDERR,"Use --rollback-fixtures only after migrations.\n");exit(2);}
require_once __DIR__.'/../includes/ProjectAutoReview.php';
$p=db();$n=0;
function ars_check($name,$actual,$expected){global $n;$n++;if($actual!==$expected)throw new RuntimeException($name.': '.json_encode($actual));}
if(!pa_storage_available())throw new RuntimeException('Missing migration');
$seed=null;
foreach($p->query("SELECT id FROM project_orders WHERE settlement_status='approved' AND receipt_amount>0 ORDER BY id DESC LIMIT 500")->fetchAll(PDO::FETCH_COLUMN) as $id){
    $c=pa_context($id);$v=$c;$v['order']['settlement_status']='draft';$v['order']['receipt_amount']=100;$v['order']['refund_amount']=0;$v['order']['contract_amount']=100;$v['cash']=[['id'=>0,'movement_type'=>'receipt','amount'=>100,'review_status'=>'approved','note'=>'fixture']];$v['snapshot_count']=0;$v['pending_requests']=0;$v['pending_refunds']=0;$v['later_refund']=0;$v['costs']=[];$v['resource']=['domain_mode'=>'none'];$v['payment']=['paid_cents'=>null,'refund_cents'=>0,'references'=>[],'warnings'=>[],'matched_sources'=>0];$v['summary']=ps_summary($v['order'],[],$c['participants']);
    if(pa_evaluate($v)['can_apply']){$seed=$c;break;}
}
if(!$seed)throw new RuntimeException('No valid existing rule fixture seed; no writes performed');
$p->beginTransaction();
try{
    // Enable only inside this rollback transaction; other sessions still see the committed switch.
    ps_setting_set('auto_review_enabled',true,null);
    $prefix='AUTO-REVIEW-FIXTURE-'.bin2hex(random_bytes(6));
    $make=function($suffix,$receipt)use($p,$seed,$prefix){
        $p->prepare("INSERT INTO project_orders (order_no,project_type,shop,contract_amount,receipt_amount,refund_amount,order_date,delivery_status,settlement_status,order_kind) VALUES (?,?,?,100,?,0,?,'finished','draft',?)")->execute([$prefix.$suffix,$seed['order']['project_type'],'AUTO-REVIEW-FIXTURE',$receipt,$seed['order']['order_date'],$seed['order']['order_kind']]);$id=(int)$p->lastInsertId();
        $p->prepare("INSERT INTO project_order_resources (order_id,domain_mode) VALUES (?,'none')")->execute([$id]);
        foreach($seed['participants'] as $person)$p->prepare('INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,?,?,?)')->execute([$id,$person['employee_id'],$person['commission_group'],$person['role_name'],$person['group_weight']]);
        if($receipt>0)$p->prepare("INSERT INTO project_cash_movements (order_id,movement_type,amount,note,review_status,submitted_by_type,submitted_by_id,reviewed_at) VALUES (?,'receipt',?,'rollback fixture verified receipt','approved','admin',0,NOW())")->execute([$id,$receipt]);
        return $id;
    };
    $count=function($table,$id)use($p){$q=$p->prepare("SELECT COUNT(*) FROM $table WHERE order_id=?");$q->execute([$id]);return(int)$q->fetchColumn();};
    $id=$make('-ledger',100);$r=pa_check_order($id,false,false);ars_check('dry ready',$r['state'],'ready');ars_check('dry no check writes',$count('project_auto_reviews',$id),0);ars_check('dry no snapshot',$count('project_commission_snapshots',$id),0);
    $r=pa_check_order($id,true,true);ars_check('apply',$r['applied'],true);$snapshots=$count('project_commission_snapshots',$id);ars_check('snapshots exist',$snapshots>0,true);
    $ec=pa_context($id);$ev=pa_evidence($ec);$q=$p->prepare('SELECT SUM(commission_amount) FROM project_commission_snapshots WHERE order_id=?');$q->execute([$id]);
    ars_check('evidence uses snapshot cent totals',(int)round(array_sum(array_column($ev['groups'],'amount'))*100),(int)round((float)$q->fetchColumn()*100));
    $fake=$ec;$members=[];foreach([900001,900002,900003] as $eid)$members[]=['employee_id'=>$eid,'role_name'=>'fixture','group_weight'=>1/3,'rule'=>['id'=>999999,'allow_negative'=>false],'calc'=>['mode'=>'pool','blocked'=>false,'share'=>1/3,'subsidy'=>0,'base'=>1,'rate'=>1,'note'=>'fixture']];
    $fake['summary']['groups']=['technical'=>['people'=>$members]];$ev=pa_evidence($fake);
    ars_check('evidence preserves pool rounding tail',array_map(function($g){return(int)round($g['amount']*100);},$ev['groups']),[34,33,33]);
    ars_check('evidence pool exactly one yuan',(int)round(array_sum(array_column($ev['groups'],'amount'))*100),100);
    $r=pa_check_order($id,true,true);ars_check('repeat does not apply',$r['applied'],false);ars_check('snapshot idempotent',$count('project_commission_snapshots',$id),$snapshots);ars_check('receipt idempotent',$count('project_cash_movements',$id),1);
    $source=function($id,$suffix)use($p,$prefix){$raw=['__financial_source__'=>'shop_statement','__order_status__'=>'交易成功','实际支付金额'=>'100.00','退款金额'=>'0','支付宝交易号'=>$prefix.'-transaction'];$p->prepare("INSERT INTO orders (employee_id,order_scope,order_no,shop,order_amount,order_date,raw_data) VALUES (0,'department',?,?,100,?,?)")->execute([$prefix.$suffix,'AUTO-REVIEW-FIXTURE',date('Y-m-d'),json_encode($raw,JSON_UNESCAPED_UNICODE)]);};
    $paid=$make('-paid',0);$source($paid,'-paid');$r=pa_check_order($paid,true,true);ars_check('explicit paid applies',$r['applied'],true);ars_check('one evidence',$count('project_auto_cash_evidence',$paid),1);ars_check('one source receipt',$count('project_cash_movements',$paid),1);$sn=$count('project_commission_snapshots',$paid);pa_check_order($paid,true,true);ars_check('repeat source receipt',$count('project_cash_movements',$paid),1);ars_check('repeat source snapshots',$count('project_commission_snapshots',$paid),$sn);
    $reuse=$make('-reuse',0);$p->prepare('INSERT INTO project_order_sources (order_id,payment_reference) VALUES (?,?)')->execute([$reuse,$prefix.'-paid']);$r=pa_check_order($reuse,true,true);ars_check('source reused across orders blocked',$r['state'],'exception');ars_check('reused source no cash',$count('project_cash_movements',$reuse),0);
    $json=$make('-json',0);$p->prepare('INSERT INTO project_order_sources (order_id,payment_reference) VALUES (?,?)')->execute([$json,$prefix.'-transaction']);$r=pa_check_order($json,false,false);ars_check('exact JSON transaction match',$r['state'],'exception');ars_check('exact reference detects source reuse',in_array('source_reused',array_column($r['reasons'],'code'),true),true);
    $fail=$make('-fail',0);$source($fail,'-fail');$p->prepare("INSERT INTO project_auto_cash_evidence (order_id,movement_type,cash_movement_id,source_order_id,amount,evidence_hash,created_at) VALUES (?,'receipt',?,0,100,?,NOW())")->execute([$fail,random_int(100000000,999999999),str_repeat('0',64)]);
    $thrown=false;try{pa_check_order($fail,true,true);}catch(PDOException $e){$thrown=true;}ars_check('forced evidence conflict fails',$thrown,true);ars_check('failed receipt rolled back',$count('project_cash_movements',$fail),0);ars_check('failed snapshots rolled back',$count('project_commission_snapshots',$fail),0);ars_check('outer transaction retained',$p->inTransaction(),true);
    echo "PASS $n rollback-only storage assertions.\n";
}finally{if(!$p->inTransaction())throw new RuntimeException('Fixture isolation lost');$p->rollBack();}
