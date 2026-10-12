<?php
// Narrow, audited repair of two proven duplicate sales. Dry-run unless --apply is supplied.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$appRoot = getenv('HEZUOSHANG_PROJECT_ROOT') ?: dirname(__DIR__);
require_once $appRoot . '/includes/ProjectIntake.php';
require_once $appRoot . '/includes/ProjectOrderSource.php';
require_once $appRoot . '/includes/ProjectSiteProjects.php';
$apply = in_array('--apply',$argv,true); $backupDir='';
foreach($argv as $arg)if(strpos($arg,'--backup-dir=')===0)$backupDir=substr($arg,13);
if($apply){$privateRoot=getenv('HEZUOSHANG_REPAIR_BACKUP_ROOT')?:$appRoot.'-runtime';if(!is_dir($privateRoot))mkdir($privateRoot,0700,true);$runtime=realpath($privateRoot);if(!$runtime||$runtime===realpath($appRoot)||strpos($runtime,realpath($appRoot).DIRECTORY_SEPARATOR)===0||strpos($backupDir,$runtime.DIRECTORY_SEPARATOR)!==0||strpos($backupDir,'..')!==false)throw new RuntimeException('必须使用站点之外的私有备份');if(!is_dir($backupDir))mkdir($backupDir,0700,true);if(strpos(realpath($backupDir),$runtime.DIRECTORY_SEPARATOR)!==0)throw new RuntimeException('备份目录越界');}
$p=db();$actor=['type'=>'system','id'=>0,'employee_id'=>null,'role'=>'finance'];$out=[];
foreach([[57242,57282,'site'],[53160,52952,'canonical']] as [$keepId,$dupId,$reason]) {
    $p->beginTransaction();
    try {
        $get=$p->prepare('SELECT * FROM project_orders WHERE id=? FOR UPDATE');$get->execute([$keepId]);$keep=$get->fetch();$get->execute([$dupId]);$dup=$get->fetch();
        if(!$dup){$p->rollBack();$out[]=['keep'=>$keepId,'duplicate'=>$dupId,'state'=>'already_merged'];continue;}
        if(!$keep||$dup['settlement_status']!=='draft'||!in_array($keep['settlement_status'],['draft','review'],true)||(float)$dup['receipt_amount']!==0.0||(float)$dup['refund_amount']!==0.0||$keep['order_date']!==$dup['order_date']||$keep['contract_amount']!==$dup['contract_amount'])throw new RuntimeException('合并前订单状态或金额已变化');
        $allowed=['project_auto_reviews','project_costs','project_order_details','project_order_items','project_order_resources','project_order_sources','project_order_splits','project_participants','project_renewal_items','project_site_projects'];
        $backup=['keep'=>$keep,'duplicate'=>$dup,'tables'=>[]];
        $cols=$p->query("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'project_%' AND COLUMN_NAME IN ('order_id','parent_order_id','child_order_id','root_order_id')")->fetchAll();
        foreach($cols as $c){$t=$c['TABLE_NAME'];$col=$c['COLUMN_NAME'];$q=$p->prepare("SELECT * FROM `$t` WHERE `$col` IN (?,?) FOR UPDATE");$q->execute([$keepId,$dupId]);$rows=$q->fetchAll();if($rows)$backup['tables'][$t.'.'.$col]=$rows;foreach($rows as $r)if((int)$r[$col]===$dupId&&!in_array($t,$allowed,true))throw new RuntimeException('重复单已有实际业务记录，拒绝自动合并：'.$t);}
        $q=$p->prepare('SELECT * FROM project_order_sources WHERE order_id=? FOR UPDATE');$q->execute([$dupId]);$source=$q->fetch();if($source&&!empty($source['source_order_id']))throw new RuntimeException('重复单已独立匹配流水，拒绝自动合并');
        $q=$p->prepare('SELECT * FROM project_participants WHERE order_id=? ORDER BY employee_id,commission_group FOR UPDATE');$q->execute([$keepId]);$kp=$q->fetchAll();$q->execute([$dupId]);$dp=$q->fetchAll();
        if($reason==='site') {
            $a=psp_order($keepId);$b=psp_order($dupId);
            if(!$a||!$b||$a['root_order_id']!==$b['root_order_id']||$a['external_order_no']!==$b['external_order_no']||strpos($b['site_key'],'auto-')!==0||strpos($a['site_key'],'auto-')===0||$keep['customer_name']!==$dup['customer_name']||$keep['project_type']!==$dup['project_type'])throw new RuntimeException('网站身份不满足明确重复条件');
            $shape=function($rows){return array_map(function($r){return array_intersect_key($r,array_flip(['employee_id','commission_group','role_name','group_weight']));},$rows);};if($shape($kp)!==$shape($dp))throw new RuntimeException('两网站参与人不同，需人工核对');
        } elseif(ps_order_no_resolve($dup['order_no'])!==$keep['order_no']) throw new RuntimeException('不是同一个付款编号');
        $q=$p->prepare('SELECT * FROM project_costs WHERE order_id=? FOR UPDATE');$q->execute([$dupId]);$costs=$q->fetchAll();foreach($costs as $c)if($c['review_status']!=='pending'||empty($c['template_id']))throw new RuntimeException('重复单有人工或已核验成本，需人工核对');
        $q=$p->prepare('SELECT * FROM project_renewal_items WHERE order_id=? FOR UPDATE');$q->execute([$dupId]);$renewals=$q->fetchAll();foreach($renewals as $r){if($r['resource_name']!==''||!empty($r['phone_hash'])||!empty($r['wechat_hash'])||$r['expiry_source']!=='estimated'||($r['owner']??'ours')!=='ours')throw new RuntimeException('重复单含真实续费资料，拒绝丢弃');foreach(['project_renewal_history','project_renewal_sms'] as $t){$q=$p->prepare('SELECT * FROM '.$t.' WHERE item_id=? FOR UPDATE');$q->execute([(int)$r['id']]);$children=$q->fetchAll();if($children)throw new RuntimeException('重复单续费资料已有历史或发送记录');}}
        $q=$p->prepare('SELECT * FROM project_order_items WHERE order_id=? ORDER BY id FOR UPDATE');$q->execute([$keepId]);$ki=$q->fetchAll();$q->execute([$dupId]);$di=$q->fetchAll();
        if($di && (count($ki)!==1||count($di)!==1||$ki[0]['item_name']!==$di[0]['item_name']||$ki[0]['sale_amount']!==$di[0]['sale_amount']||!empty($ki[0]['cost_id'])||$ki[0]['reported_cost']!==null||$ki[0]['technical_cost']!==null))throw new RuntimeException('商品明细已有独立核验，拒绝覆盖');
        if($apply){$path=$backupDir.'/merge_'.$dupId.'.json';if(file_exists($path))throw new RuntimeException('已存在合并备份，请检查上次执行结果');if(file_put_contents($path,json_encode($backup,JSON_UNESCAPED_UNICODE))===false)throw new RuntimeException('备份失败');chmod($path,0600);}
        foreach($dp as $person){$found=false;foreach($kp as $existing)if($existing['employee_id']===$person['employee_id']&&$existing['commission_group']===$person['commission_group']){$found=true;break;}if(!$found)$p->prepare('INSERT INTO project_participants(order_id,employee_id,commission_group,role_name,group_weight)VALUES(?,?,?,?,?)')->execute([$keepId,$person['employee_id'],$person['commission_group'],$person['role_name'],$person['group_weight']]);}
        if($di){$p->prepare('UPDATE project_order_items SET category=?,template_id=?,cost_id=?,resource_hint=?,updated_at=NOW() WHERE id=?')->execute([$di[0]['category'],$di[0]['template_id'],$di[0]['cost_id'],$di[0]['resource_hint'],$ki[0]['id']]);}
        if($costs)$p->prepare('UPDATE project_costs SET order_id=? WHERE order_id=?')->execute([$keepId,$dupId]);
        $q=$p->prepare('SELECT * FROM project_order_resources WHERE order_id=? FOR UPDATE');$q->execute([$dupId]);$res=$q->fetch();if($res)$p->prepare('UPDATE project_order_resources SET program_template_id=COALESCE(program_template_id,?) WHERE order_id=?')->execute([$res['program_template_id'],$keepId]);
        if($reason==='canonical'){
            // The original technical upload and September source both identify 博山定制; no settled commission exists on either duplicate.
            if($dup['project_type']!=='AI网站定制'||$keep['project_type']!=='网站模板')throw new RuntimeException('业务归属证据已变化');
            $p->prepare('UPDATE project_orders SET project_type=?,order_kind=? WHERE id=?')->execute([$dup['project_type'],$dup['order_kind'],$keepId]);
            $p->prepare('UPDATE project_order_details SET business_name=? WHERE order_id=?')->execute([$dup['project_type'],$keepId]);
        }
        foreach(['project_auto_reviews','project_order_items','project_order_details','project_order_resources','project_order_sources','project_participants','project_renewal_items','project_site_projects'] as $t)$p->prepare('DELETE FROM '.$t.' WHERE order_id=?')->execute([$dupId]);
        $p->prepare('DELETE FROM project_order_splits WHERE child_order_id=?')->execute([$dupId]);
        $p->prepare('DELETE FROM project_orders WHERE id=?')->execute([$dupId]);
        $p->prepare('UPDATE project_orders SET row_version=row_version+1 WHERE id=?')->execute([$keepId]);
        ps_audit('order',$keepId,'repair_duplicate_import',$actor,['duplicate_id'=>$dupId,'reason'=>$reason,'amount_removed_from_duplicate'=>$dup['contract_amount'],'confirmed_money_preserved'=>true]);
        if($apply)$p->commit();else$p->rollBack();
        $out[]=['keep'=>$keepId,'duplicate'=>$dupId,'duplicate_amount'=>$dup['contract_amount'],'mode'=>$apply?'applied':'dry_run'];
    } catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
}
echo json_encode($out,JSON_UNESCAPED_UNICODE).PHP_EOL;
