<?php
/** 用户确认的商标成本校准；默认演练回滚，--commit 才保存。不能重写冻结结算。 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__.'/../includes/ProjectTrademarkCost.php';
$pdo=db();$actor=['type'=>'system','id'=>0,'role'=>'finance','requested_by'=>'owner'];
$commit=in_array('--commit',$argv,true);
$rates=['商标注册'=>270,'商标转让'=>450,'商标续展'=>450,'商标补证'=>450,'商标变更'=>0,'商标超期续展'=>675,'商标许可备案'=>135,'商标注销'=>0,'商标撤回'=>0,'商标更正'=>0];
$rates+=['商标注册多选项目加收'=>27,'成品商标成本'=>1,'国际商标成本'=>1,'法务外包成本'=>1];
$backup=['database'=>(string)$pdo->query('SELECT DATABASE()')->fetchColumn(),'templates'=>[],'orders'=>[]];
$result=[];$changes=[];$unresolved=[];$templateChanges=[];
$pdo->beginTransaction();
try{
    foreach($rates as$name=>$price){
        $unit=in_array($name,['成品商标成本','国际商标成本','法务外包成本'],true)?'元':($name==='商标注册多选项目加收'?'个':'件');$auto=$unit==='元'?0:1;
        $q=$pdo->prepare("SELECT * FROM project_cost_templates WHERE business_scope='商标' AND name=? AND is_active=1 FOR UPDATE");$q->execute([$name]);$rows=$q->fetchAll();
        if(count($rows)!==1)throw new RuntimeException($name.'缺少唯一启用模板，请先核对成本中心');
        $t=$rows[0];$backup['templates'][]=$t;
        if((float)$t['price']!=(float)$price||$t['unit']!==$unit||$t['price_mode']!=='fixed'||(int)$t['auto_approve']!==$auto||(int)$t['requires_proof']!==0){
            $pdo->prepare("UPDATE project_cost_templates SET price=?,unit=?,price_mode='fixed',auto_approve=?,requires_proof=0,version=version+1 WHERE id=?")->execute([$price,$unit,$auto,$t['id']]);
            ps_audit('template',(int)$t['id'],'correct_trademark_price',$actor,['from'=>$t,'to_price'=>$price,'source'=>'用户2026-10-08确认的内部商标成本']);$templateChanges[]=$name;
        }
    }
    $q=$pdo->query("SELECT id,order_no,order_date,settlement_status FROM project_orders WHERE project_type='商标' AND settlement_status IN('draft','review') ORDER BY id FOR UPDATE");
    foreach($q->fetchAll()as$o){
        [$details,$context]=ptc_order_pricing_data($o['id']);$before=ps_costs($o['id']);
        $backup['orders'][]=['order'=>$o,'details'=>$details,'costs'=>$before];
        $action=ptc_sync_order_cost($o['id'],$actor,'成本修复');$result[$action]=($result[$action]??0)+1;
        if(in_array($action,['added','corrected'],true)){
            $active=function($rows){return array_values(array_filter($rows,function($r){return$r['review_status']!=='rejected';}));};
            $b=$active($before);$a=$active(ps_costs($o['id']));
            $changes[]=['order_no'=>$o['order_no'],'from'=>array_sum(array_column($b,'amount')),'to'=>array_sum(array_column($a,'amount')),'items'=>array_column($a,'item_name')];
        }elseif($action==='unresolved')$unresolved[]=['order_no'=>$o['order_no'],'reason'=>ptc_cost_plan(ptc_templates(),$details,$context)['message']];
    }
    $file=null;
    if($commit){
        $name='trademark_pricing_'.date('Ymd_His').'_'.bin2hex(random_bytes(4)).'.json';
        $file=ps_private_dir('maintenance').'/'.$name.'.php';
        if(file_put_contents($file,PS_PRIVATE_GUARD.json_encode($backup,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),LOCK_EX)===false)throw new RuntimeException('修复前备份写入失败');
        chmod($file,0600);$pdo->commit();
    }else$pdo->rollBack();
    echo json_encode(['committed'=>$commit,'templates_checked'=>$rates,'templates_updated'=>$templateChanges,'checked'=>count($backup['orders']),'result'=>$result,'changes'=>$changes,'unresolved'=>$unresolved,'backup'=>$file],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
}catch(Throwable$e){if($pdo->inTransaction())$pdo->rollBack();fwrite(STDERR,$e->getMessage()."\n");exit(1);}
