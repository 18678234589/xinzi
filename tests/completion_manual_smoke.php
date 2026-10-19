<?php
if (PHP_SAPI !== 'cli') exit(1);
$root = getenv('HEZUOSHANG_APP') ?: dirname(__DIR__);
require_once $root . '/includes/ProjectCompletionSync.php';
function manual_completion_check($name, $ok) {
    if (!$ok) throw new RuntimeException($name);
    echo "PASS $name\n";
}
function manual_completion_rejected($fn) {
    try { $fn(); } catch (RuntimeException $e) { return true; }
    return false;
}
$p=db();$ids=[];$finance=['type'=>'admin','id'=>1,'role'=>'finance'];$feng=['type'=>'employee','id'=>82,'employee_id'=>71,'role'=>'governance'];$staff=['type'=>'employee','id'=>33,'employee_id'=>33,'role'=>'customer_service'];
try {
    manual_completion_check('only finance and Feng can access', pcs_can_access($finance) && pcs_can_access($feng) && !pcs_can_access($staff) && !pcs_can_access([]));
    $tag='MANUAL-COMPLETION-'.bin2hex(random_bytes(6));
    $insert=$p->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,shop,contract_amount,order_date,delivery_status,settlement_status) VALUES (?,'manual test',?,'manual test',100,'2099-01-01','unfinished',?)");
    foreach ([['网站模板','draft'],['小程序开发','draft'],['AI网站定制','locked']] as $i=>$row) {$insert->execute([$tag.'-'.$i,$row[0],$row[1]]);$ids[]=(int)$p->lastInsertId();}
    $result=pcs_manual_finish($ids[0],$finance);$order=$p->query('SELECT delivery_status,settlement_status,row_version FROM project_orders WHERE id='.$ids[0])->fetch();
    $audit=$p->query("SELECT details_json FROM project_audit_logs WHERE entity_type='order' AND entity_id=".$ids[0]." AND action='sync_completion' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $money=(int)$p->query('SELECT COUNT(*) FROM project_commission_snapshots WHERE order_id='.$ids[0])->fetchColumn()+(int)$p->query('SELECT COUNT(*) FROM project_cash_movements WHERE order_id='.$ids[0])->fetchColumn();
    manual_completion_check('manual finish records delivery and audit without settling money', $result['finished']===1 && $order['delivery_status']==='finished' && $order['settlement_status']==='draft' && strpos($audit,'财务手动确认')!==false && $money===0);
    $again=pcs_manual_finish($ids[0],$finance);$version=$p->query('SELECT row_version FROM project_orders WHERE id='.$ids[0])->fetchColumn();
    manual_completion_check('repeat confirmation is idempotent', $again['finished']===0 && (int)$version===(int)$order['row_version']);
    manual_completion_check('non-website and locked orders cannot be changed', manual_completion_rejected(function()use($ids,$finance){pcs_manual_finish($ids[1],$finance);}) && manual_completion_rejected(function()use($ids,$finance){pcs_manual_finish($ids[2],$finance);}));
    manual_completion_check('Feng can view but finance must confirm', manual_completion_rejected(function()use($ids,$feng){pcs_manual_finish($ids[0],$feng);}) && manual_completion_rejected(function()use($feng){pcs_apply(['mark_finished'=>[],'mark_unfinished'=>[],'approved_unfinished'=>[]],false,$feng);}));
    manual_completion_check('missing order is rejected', manual_completion_rejected(function()use($finance){pcs_manual_finish(0,$finance);}));
} finally {
    foreach ($ids as $id) {
        $p->exec("DELETE FROM project_audit_logs WHERE entity_type='order' AND entity_id=".$id);
        $p->exec('DELETE FROM project_orders WHERE id='.$id);
    }
}
