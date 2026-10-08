<?php
// Audited repair of this bug only; never rewrites locked periods or refund-adjusted snapshots.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__.'/../includes/ProjectSettlement.php';
$apply=in_array('--apply',$argv,true);$pdo=db();$out=['apply'=>$apply,'updated'=>0,'changes'=>[],'skipped'=>[]];
$journalExists=(bool)$pdo->query("SHOW TABLES LIKE 'project_snapshot_repairs'")->fetchColumn();
if($apply) $pdo->exec("CREATE TABLE IF NOT EXISTS project_snapshot_repairs (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, snapshot_id BIGINT UNSIGNED NOT NULL,
 repair_key VARCHAR(80) NOT NULL, before_json LONGTEXT NOT NULL, after_json LONGTEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_repair(snapshot_id,repair_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$ids=$pdo->query("SELECT DISTINCT s.order_id FROM project_commission_snapshots s JOIN project_orders o ON o.id=s.order_id
 WHERE s.commission_group='customer_service' AND ((o.project_type='小程序开发' AND o.order_kind='定制' AND s.rate=.05)
 OR (s.calc_mode='pool' AND (s.rate<>0 OR s.subsidy_amount<>0) AND (SELECT COUNT(*) FROM project_commission_snapshots x WHERE x.order_id=s.order_id AND x.commission_group='customer_service')>1)) ORDER BY s.order_id")->fetchAll(PDO::FETCH_COLUMN);
foreach($ids as $id) {
    $pdo->beginTransaction();
    try {
        $q=$pdo->prepare('SELECT * FROM project_orders WHERE id=? FOR UPDATE');$q->execute([$id]);$order=$q->fetch();
        $q=$pdo->prepare("SELECT * FROM project_commission_snapshots WHERE order_id=? AND commission_group='customer_service' ORDER BY id FOR UPDATE");$q->execute([$id]);$rows=$q->fetchAll();
        $skip='';$people=[];
        if($order['settlement_status']==='locked')$skip='locked_order';
        if((float)$order['refund_amount']>0)$skip='refund_present';
        $q=$pdo->prepare('SELECT COUNT(*) FROM project_commission_adjustments WHERE order_id=?');$q->execute([$id]);if($q->fetchColumn())$skip='adjustments_present';
        foreach($rows as $s) {
            $q=$pdo->prepare('SELECT status FROM project_payroll_periods WHERE period=? FOR UPDATE');$q->execute([$s['payroll_month']]);if($q->fetchColumn()==='locked')$skip='locked_period';
            $r=ps_rule_for('customer_service',$order['project_type'],$order['order_date'],$s['role_name'],$order['order_kind']);
            if(!$r || $r['calc_mode']!=='pool'){$skip='non_pool_rule';break;}
            $isMiniFix=$order['project_type']==='小程序开发' && $order['order_kind']==='定制' && (float)$s['rate']===.05;
            if((int)$r['id']!==(int)$s['rule_id'] && !$isMiniFix){$skip='other_rule_version_changed';break;}
            $c=ps_calc_person($r,(float)$s['income_amount'],(float)$s['direct_cost'],(float)$order['contract_amount'],(float)$s['group_weight'],(float)($r['service_fee_rate']??0));
            $only=array_filter(array_map('intval',preg_split('/[^\d]+/',(string)($r['subsidy_employee_ids']??''))));
            if($only && !in_array((int)$s['employee_id'],$only,true)){$c['subsidy']=0;$c['subsidy_pool']=0;}
            $people[]=['rule'=>$r,'group_weight'=>(float)$s['group_weight'],'calc'=>$c];
        }
        if(abs(array_sum(array_column($people,'group_weight'))-1)>0.000001)$skip=$skip?:'invalid_weights';
        if($skip){$out['skipped'][]=['order_id'=>(int)$id,'reason'=>$skip];$pdo->rollBack();continue;}
        $shares=ps_group_share_cents($people);$subsidies=ps_group_subsidy_cents($people);
        foreach($rows as $i=>$s) {
            $c=$people[$i]['calc'];$r=$people[$i]['rule'];$amount=($shares[$i]+$subsidies[$i])/100;
            if((int)round($amount*100)===(int)round($s['commission_amount']*100) && (float)$s['rate']===(float)$r['rate'] && (int)round((float)$s['subsidy_amount']*100)===$subsidies[$i])continue;
            if($journalExists) {
                $q=$pdo->prepare('SELECT 1 FROM project_snapshot_repairs WHERE snapshot_id=? AND repair_key=?');$q->execute([$s['id'],'joint-customer-20261008']);
                if($q->fetchColumn()){$out['skipped'][]=['snapshot_id'=>(int)$s['id'],'reason'=>'already_repaired'];continue;}
            }
            $after=['rule_id'=>(int)$r['id'],'rate'=>$c['rate'],'service_fee'=>$c['fee'],'contribution_profit'=>$c['base'],'commission_amount'=>$amount,'commission_exact'=>round($c['share']+$subsidies[$i]/100,6),'subsidy_amount'=>$subsidies[$i]/100,'calc_note'=>$c['note']];
            $out['changes'][]=['snapshot_id'=>(int)$s['id'],'order_id'=>(int)$id,'employee_id'=>(int)$s['employee_id'],'before'=>(float)$s['commission_amount'],'after'=>$amount,'rate'=>$c['rate'],'subsidy'=>$after['subsidy_amount']];
            if($apply) {
                $pdo->prepare('INSERT INTO project_snapshot_repairs(snapshot_id,repair_key,before_json,after_json) VALUES (?,?,?,?)')->execute([$s['id'],'joint-customer-20261008',json_encode($s,JSON_UNESCAPED_UNICODE),json_encode($after,JSON_UNESCAPED_UNICODE)]);
                $pdo->prepare('UPDATE project_commission_snapshots SET rule_id=?,rate=?,service_fee=?,contribution_profit=?,commission_amount=?,commission_exact=?,subsidy_amount=?,calc_note=? WHERE id=?')->execute([$after['rule_id'],$after['rate'],$after['service_fee'],$after['contribution_profit'],$after['commission_amount'],$after['commission_exact'],$after['subsidy_amount'],mb_substr($after['calc_note'],0,500),$s['id']]);
                ps_audit('order',(int)$id,'joint_customer_snapshot_repair',['type'=>'system','id'=>0],['snapshot_id'=>(int)$s['id'],'before'=>$s,'after'=>$after]);$out['updated']++;
            }
        }
        if($apply)$pdo->commit();else $pdo->rollBack();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
$out['change_count']=count($out['changes']);$out['skipped_count']=count($out['skipped']);
if(!in_array('--details',$argv,true)) { $out['changes']=array_slice($out['changes'],0,25);$out['skipped']=array_slice($out['skipped'],0,25); }
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
