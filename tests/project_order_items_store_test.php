<?php
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../includes/ProjectIntake.php';
poi_ensure();
$pdo=db(); $pdo->beginTransaction(); $checks=0;
function item_check($ok,$label) { global $checks; if(!$ok) throw new RuntimeException($label); $checks++; }
try {
    $actor=['type'=>'system','id'=>0,'role'=>'finance'];
    $templates=ps_intake_templates(null,'网站模板');
    $q=$pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,contract_amount,receipt_amount,order_date,delivery_status) VALUES (?,'多商品测试','网站模板',750,750,'2099-10-01','finished')");
    $q->execute(['ITEM-TEST-'.bin2hex(random_bytes(6))]); $id=(int)$pdo->lastInsertId();
    ps_intake_save_resources($id,'excel',3,null,null,null,'pending');
    $items=array_merge(poi_from_row('青站',550,['成本'],[196],$templates,3),poi_from_row('SSL证书',200,['成本'],[30],$templates,5));
    poi_save($id,$items,0,$actor);
    item_check(count(poi_items($id))===2,'明细两条');
    item_check(count(ps_costs($id))===2,'成本两条');
    item_check(array_sum(array_column(ps_costs($id),'amount'))==226,'标准成本196+30');
    item_check(count(array_filter(ps_costs($id),function($c){return $c['review_status']==='pending';}))===2,'资源待确认，成本预估不冒充已审核');
    poi_save($id,$items,0,$actor);
    item_check(count(poi_items($id))===2 && count(ps_costs($id))===2,'二次上传不重复商品和成本');
    $q=$pdo->prepare('SELECT receipt_amount,contract_amount FROM project_orders WHERE id=?'); $q->execute([$id]);$o=$q->fetch();
    item_check($o['receipt_amount']==750 && $o['contract_amount']==750,'不重复收入');
    ps_intake_confirm_resources($id,'none',0,0,$actor,poi_template('青站',$templates)['id']);
    item_check(count(ps_costs($id))===2,'确认套餐不重复成本');
    item_check(count(array_filter(ps_costs($id),function($c){return $c['review_status']==='approved';}))===2,'确认后证书同步核对');
    $before=poi_items($id);
    poi_save($id,$items,0,$actor);
    item_check(poi_items($id)===$before,'审核成本不被重复上传改写');
    $pdo->prepare("UPDATE project_orders SET settlement_status='approved' WHERE id=?")->execute([$id]);
    poi_save($id,poi_from_row('泛域名SSL证书',250,[],[],$templates,9),0,$actor);
    item_check(count(poi_items($id))===2,'已结算锁定不被导入更改');
    $pdo->prepare("UPDATE project_orders SET settlement_status='draft' WHERE id=?")->execute([$id]);
    $m=poi_from_row('泛域名SSL证书',250,['成本'],[200],$templates,9);
    poi_save($id,$m,0,$actor);
    item_check(count(ps_costs($id))===2,'来源成本冲突不能自动增加标准成本');
    $all=poi_items($id);
    item_check(empty($all[2]['cost_id']),'冲突明细保留，须核对');
    try { poi_link_cost($id,$all[2]['id'],$all[1]['cost_id'],$actor); throw new RuntimeException('重复关联未阻止'); }
    catch(RuntimeException $e) { item_check(strpos($e->getMessage(),'不能重复关联')!==false,'不让同一成本重复抵扣'); }
    $pdo->prepare("UPDATE project_orders SET settlement_status='locked' WHERE id=?")->execute([$id]);
    $oldCosts=ps_costs($id);
    poi_save($id,poi_from_row('证书维护说明',0,[],[],$templates,10),0,$actor,true);
    item_check(count(poi_items($id))===4,'锁定历史单允许仅补商品展示');
    item_check(ps_costs($id)===$oldCosts,'历史展示修复不改成本和金额');
    $pdo->rollBack();
    echo "PASS $checks storage checks; all fixtures rolled back\n";
} catch(Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1); }
