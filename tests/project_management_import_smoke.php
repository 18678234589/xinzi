<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
setlocale(LC_CTYPE, 'C');
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/require_isolated_database.php';
$pdo = db(); require_isolated_test_database($pdo);
if (DB_HOST !== '127.0.0.1' || (string)DB_PORT !== '13399') throw new RuntimeException('只允许本地隔离库');
require __DIR__ . '/../migrations/apply_management_accounts.php';
$tag = bin2hex(random_bytes(5)); $no = 'MANAGER-IMPORT-'.$tag;
$employeeIds = []; $uid = 0; $fileId = 0; $stored = null; $orderId = 0;
$assert = function ($ok, $label) { if (!$ok) throw new RuntimeException($label); };
$run = function ($post) {
    $_SERVER['SCRIPT_NAME']='/project/import.php'; $_SERVER['REQUEST_METHOD']='POST';
    $_POST=$post+['csrf'=>'management-test']; $_FILES=[]; $_GET=[];
    ob_start(); include __DIR__ . '/../project/import.php'; ob_end_clean();
    return [$imported ?? 0,$error ?? ''];
};
try {
    foreach (['管理测试'.$tag,'客服测试'.$tag] as $name) {
        $pdo->prepare('INSERT INTO employees (name,department,password) VALUES (?,?,?)')->execute([$name,'商标',md5($tag)]);
        $employeeIds[]=(int)$pdo->lastInsertId();
    }
    $pdo->prepare('INSERT INTO project_users (employee_id,username,password_hash,role) VALUES (?,?,?,?)')->execute([$employeeIds[0],'manager-import-'.$tag,password_hash($tag,PASSWORD_DEFAULT),'management']);
    $uid=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO project_user_businesses (user_id,business_name,is_default) VALUES (?,?,1)')->execute([$uid,'商标']);
    ps_account_management_save($uid,'management',['management_scope'=>'assigned','management_title'=>'商标主管']);
    $_SESSION['project_user_id']=$uid; $_SESSION['project_csrf']='management-test'; unset($_SESSION['admin_id']);
    $csv="日期,店铺,订单编号,售价,成本,客服,订单类型,商标个数\n".date('Y-m-d').",管理测试店,$no,330,270,客服测试$tag,普通订单,2\n";
    $tmp=tempnam(sys_get_temp_dir(),'manager-import-');file_put_contents($tmp,$csv);
    $stored=ps_private_store('imports',$tmp,'manager_import_'.$tag.'.csv');unlink($tmp);
    $pdo->prepare("INSERT INTO project_import_files (business_name,original_name,stored_name,file_size,uploaded_by_type,uploaded_by_id,employee_id) VALUES (?,'管理代录测试.csv',?,?,'employee',?,?)")->execute(['商标',$stored,strlen($csv),$uid,$employeeIds[0]]);
    $fileId=(int)$pdo->lastInsertId();
    [$count,$error]=$run(['action'=>'repreview','business'=>'商标','file_id'=>$fileId,'all_sheets'=>1]);
    $preview=$_SESSION['project_import_preview']??[];
    $assert($error==='' && !empty($preview[0]['base_valid']), '管理代录预览失败：'.$error.' '.json_encode($preview[0]??[],JSON_UNESCAPED_UNICODE));
    $assert(isset($preview[0]['people']['customer_service'][$employeeIds[1]]) && !isset($preview[0]['people']['customer_service'][$employeeIds[0]]), '管理代录自动计入主管分成');
    [$count,$error]=$run(['action'=>'commit','business'=>'商标','auto_import'=>1]);
    $assert($count===1 && $error==='', '管理代录提交失败：'.$error);
    $q=$pdo->prepare('SELECT id FROM project_orders WHERE order_no=?');$q->execute([$no]);$orderId=(int)$q->fetchColumn();
    $assert($orderId>0,'代录没有创建订单');
    $q=$pdo->prepare('SELECT employee_id FROM project_participants WHERE order_id=?');$q->execute([$orderId]);$ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
    $assert($ids===[$employeeIds[1]], '主管被加入分成参与人，或实际客服丢失');
    $actor=ps_actor();$assert((int)ps_order($orderId,$actor)['id']===$orderId,'管理无法查看代录订单');
    echo "PASS management previews and submits colleague orders without adding the manager to commission participants\n";
} finally {
    if (!$orderId) { $q=$pdo->prepare('SELECT id FROM project_orders WHERE order_no=?');$q->execute([$no]);$orderId=(int)$q->fetchColumn(); }
    if ($orderId) foreach (['project_import_result_rows','project_order_items','project_commission_snapshots','project_commission_adjustments','project_cash_movements','project_costs','project_participants','project_order_sources','project_order_resources','project_order_details','project_department_orders','project_department_uploaders','project_auto_reviews','project_order_requests','project_order_credentials','project_refund_import_rows'] as $table) {
        try {$pdo->prepare('DELETE FROM '.$table.' WHERE order_id=?')->execute([$orderId]);}catch(PDOException $e){if($e->getCode()!=='42S02' && $e->getCode()!=='42S22')throw $e;}
    }
    if($orderId)$pdo->prepare('DELETE FROM project_orders WHERE id=?')->execute([$orderId]);
    if($fileId)foreach(['project_import_edits','project_import_result_rows','project_import_followups'] as $table){try{$pdo->prepare('DELETE FROM '.$table.' WHERE file_id=?')->execute([$fileId]);}catch(PDOException $e){if($e->getCode()!=='42S02' && $e->getCode()!=='42S22')throw $e;}}
    if($fileId)$pdo->prepare('DELETE FROM project_import_files WHERE id=?')->execute([$fileId]);
    if($stored)ps_private_delete('imports',$stored);
    if($uid){$pdo->prepare('DELETE FROM project_user_businesses WHERE user_id=?')->execute([$uid]);$pdo->prepare('DELETE FROM project_users WHERE id=?')->execute([$uid]);}
    foreach($employeeIds as $id){$pdo->prepare('DELETE FROM project_messages WHERE employee_id=?')->execute([$id]);$pdo->prepare('DELETE FROM employees WHERE id=?')->execute([$id]);}
    unset($_SESSION['project_user_id']);
}
