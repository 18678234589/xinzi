<?php
// DB_NAME=xinzi_test DB_HOST=127.0.0.1 DB_PORT=13399 php tests/batch_review_void_smoke.php
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../includes/ProjectBatchReview.php';
require_once __DIR__ . '/../includes/ProjectOrderDelete.php';
require_once __DIR__ . '/../includes/ProjectOrderTrash.php';
require_once __DIR__ . '/require_isolated_database.php';
$pdo=db();require_isolated_test_database($pdo);
if (DB_HOST!=='127.0.0.1' || (string)DB_PORT!=='13399') throw new RuntimeException('Local sandbox only');
function void_check($okay,$message) { if (!$okay) throw new RuntimeException($message); }
function void_rejected($call) { try { $call(); } catch (RuntimeException $e) { return true; } return false; }
$tag=bin2hex(random_bytes(4));$ids=[];$employees=[];$requests=[];$trigger='pbr_void_fail_'.$tag;
$finance=['type'=>'admin','role'=>'finance','id'=>(int)$pdo->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetchColumn(),'employee_id'=>0];
foreach (['A','B'] as $label) {
    $pdo->prepare("INSERT INTO employees(name,department,password) VALUES (?,'批量作废测试',MD5('sandbox'))")->execute(['VOID-'.$tag.$label]);
    $employees[]=(int)$pdo->lastInsertId();
}
[$emp,$other]=$employees;
$make=function ($label,$owner=null,$date='2026-09-12',$status='draft') use ($pdo,$tag,$emp,&$ids) {
    $pdo->prepare("INSERT INTO project_orders(order_no,customer_name,project_type,order_kind,shop,contract_amount,order_date,delivery_status,settlement_status,note) VALUES (?,'sandbox','平面设计','新订单','内部',100,?,'unfinished',?,'作废回滚测试')")
        ->execute(['VOID-'.$tag.$label,$date,$status]);$id=(int)$pdo->lastInsertId();$ids[]=$id;
    $pdo->prepare("INSERT INTO project_participants(order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'technical','设计',1)")->execute([$id,$owner??$emp]);return $id;
};
$exists=function ($id) use ($pdo) { $q=$pdo->prepare('SELECT COUNT(*) FROM project_orders WHERE id=?');$q->execute([$id]);return (int)$q->fetchColumn(); };
try {
    pbr_ensure();pot_ensure();
    $a=$make('A');$unticked=$make('UNTICKED');$wrongOwner=$make('OWNER',$other);$wrongMonth=$make('MONTH',null,'2026-10-01');$approved=$make('APPROVED',null,'2026-09-12','approved');$locked=$make('LOCKED',null,'2026-09-12','locked');
    void_check(void_rejected(function () use ($a,$emp) { pbr_void(['type'=>'employee','role'=>'technical','employee_id'=>$emp],0,[$a],$emp,'2026-09','重复测试订单'); }),'Ordinary staff cannot void');
    void_check(void_rejected(function () use ($a,$emp) { pbr_void(['type'=>'employee','role'=>'governance','employee_id'=>$emp],0,[$a],$emp,'2026-09','重复测试订单'); }),'Approval delegates do not gain finance deletion powers');
    void_check(void_rejected(function () use ($finance,$emp) { pbr_void($finance,0,[],$emp,'2026-09','重复测试订单'); }) && void_rejected(function () use ($finance,$a,$emp) { pbr_void($finance,0,[$a],$emp,'2026-09',''); }),'Selection and reason required');
    void_check($exists($a)===1,'Permission checks must not change orders');
    echo "PASS finance permission and required confirmation data\n";

    $pdo->prepare("INSERT INTO project_costs(order_id,category,item_name,unit_price,amount,review_status) VALUES (?,'服务成本','sandbox cost',12,12,'pending')")->execute([$a]);
    $pdo->prepare("INSERT INTO project_cash_movements(order_id,movement_type,amount,note,review_status,submitted_by_type,submitted_by_id) VALUES (?,'receipt',100,'待核实','pending','admin',?)")->execute([$a,$finance['id']]);
    [$done,$failed]=pbr_void($finance,0,[$a,$a,$wrongOwner,$wrongMonth,$approved,$locked],$emp,'2026-09','重复导入的测试订单');
    void_check($done===1 && count($failed)===4 && !$exists($a) && $exists($unticked) && $exists($wrongOwner) && $exists($wrongMonth) && $exists($approved) && $exists($locked),'Only selected eligible orders may be voided');
    $q=$pdo->prepare("SELECT * FROM project_order_trash WHERE order_id=? AND status='trashed'");$q->execute([$a]);$trash=$q->fetch();$snapshot=json_decode($trash['snapshot_json'],true);
    void_check((int)$snapshot['order']['id']===$a && count($snapshot['project_participants'])===1 && count($snapshot['project_costs'])===1 && count($snapshot['project_cash_movements'])===1,'All financial and participant rows must be backed up');
    void_check(pot_restore((int)$trash['id'],$finance)===$snapshot['order']['order_no'] && $exists($a)===1,'Canonical restore must recover the original order');
    $q=$pdo->prepare('SELECT COUNT(*) FROM project_costs WHERE order_id=?');$q->execute([$a]);void_check((int)$q->fetchColumn()===1,'Restore must recover original costs');
    echo "PASS selection boundaries, duplicate IDs, complete backup and restoration\n";

    $cash=$make('CASH');$pdo->prepare('UPDATE project_orders SET receipt_amount=10 WHERE id=?')->execute([$cash]);
    $movement=$make('MOVEMENT');$pdo->prepare("INSERT INTO project_cash_movements(order_id,movement_type,amount,note,review_status,submitted_by_type,submitted_by_id) VALUES (?,'receipt',10,'已确认','approved','admin',?)")->execute([$movement,$finance['id']]);
    $parent=$make('PARENT');$child=$make('CHILD');
    $pdo->prepare('INSERT INTO project_order_splits(parent_order_id,child_order_id) VALUES (?,?)')->execute([$parent,$child]);
    [$done,$failed]=pbr_void($finance,0,[$cash,$movement,$parent,$child],$emp,'2026-09','重复导入的测试订单');
    void_check($done===0 && count($failed)===4 && $exists($cash) && $exists($movement) && $exists($parent) && $exists($child),'Confirmed cash and split relationships must be protected');
    echo "PASS confirmed receipt/refund and linked-order protection\n";

    $failure=$make('FAILURE');
    $pdo->exec("CREATE TRIGGER `$trigger` BEFORE DELETE ON project_orders FOR EACH ROW BEGIN IF OLD.id=$failure THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='sandbox injected failure'; END IF; END");
    [$done,$failed]=pbr_void($finance,0,[$failure],$emp,'2026-09','事务回滚验证');
    $q=$pdo->prepare('SELECT COUNT(*) FROM project_participants WHERE order_id=?');$q->execute([$failure]);
    void_check($done===0 && count($failed)===1 && $exists($failure) && (int)$q->fetchColumn()===1,'Delete failure must restore the order and its related rows');
    $q=$pdo->prepare('SELECT COUNT(*) FROM project_order_trash WHERE order_id=?');$q->execute([$failure]);void_check((int)$q->fetchColumn()===0,'Failed transaction must not leave a trash entry');
    $pdo->exec("DROP TRIGGER `$trigger`");
    echo "PASS injected deletion failure rolls back order, related rows and backup\n";

    $requestOrder=$make('REQUEST',$other);
    // Isolate this request from the other employee's September fixture.
    $pdo->prepare("UPDATE project_orders SET order_date='2026-10-01' WHERE id=?")->execute([$wrongOwner]);
    $pdo->prepare("INSERT INTO project_batch_reviews(employee_id,month,order_count,amount) VALUES (?,'2026-09',1,100)")->execute([$other]);$rid=(int)$pdo->lastInsertId();$requests[]=$rid;
    [$done,$failed]=pbr_void($finance,$rid,[$requestOrder],0,'','无效测试订单');
    $q=$pdo->prepare('SELECT status FROM project_batch_reviews WHERE id=?');$q->execute([$rid]);
    void_check($done===1 && !$failed && $q->fetchColumn()==='done','Requests with all pending orders handled must close');
    void_check(void_rejected(function () use ($finance,$rid,$requestOrder) { pbr_void($finance,$rid,[$requestOrder],0,'','无效测试订单'); }),'Processed requests cannot be replayed');
    echo "PASS request completion and replay protection\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $pdo->exec("DROP TRIGGER IF EXISTS `$trigger`");
    foreach ($ids as $id) {
        $pdo->prepare('DELETE FROM project_order_splits WHERE parent_order_id=? OR child_order_id=?')->execute([$id,$id]);
        pod_delete_rows($id);
        $pdo->prepare('DELETE FROM project_order_trash WHERE order_id=?')->execute([$id]);
        $pdo->prepare("DELETE FROM project_audit_logs WHERE entity_type='order' AND entity_id=?")->execute([$id]);
    }
    foreach ($requests as $id) {
        $pdo->prepare('DELETE FROM project_batch_reviews WHERE id=?')->execute([$id]);
        $pdo->prepare("DELETE FROM project_audit_logs WHERE entity_type='batch_review' AND entity_id=?")->execute([$id]);
    }
    $pdo->prepare("DELETE FROM project_audit_logs WHERE entity_type='batch_review' AND entity_id=0 AND action='void' AND JSON_UNQUOTE(JSON_EXTRACT(details_json,'$.employee_id'))=?")->execute([(string)$emp]);
    foreach ($employees as $id) $pdo->prepare('DELETE FROM employees WHERE id=?')->execute([$id]);
}
