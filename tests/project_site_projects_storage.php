<?php
/** Rollback-only fixture against a migrated database. */
if (PHP_SAPI !== 'cli' || !in_array('--rollback-fixtures', $argv, true)) { fwrite(STDERR, "Use --rollback-fixtures after migration.\n"); exit(2); }
require_once __DIR__ . '/../includes/ProjectSettlement.php';
require_once __DIR__ . '/../includes/ProjectSiteProjects.php';
$pdo = db();
$check = function ($ok, $message) { if (!$ok) throw new RuntimeException($message); };
$actor = ['type' => 'admin', 'id' => 1, 'role' => 'finance'];
$external = 'SITE-ROLLBACK-' . bin2hex(random_bytes(8));
$pdo->beginTransaction();
try {
    $insert = $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,shop,contract_amount,receipt_amount,order_date,delivery_status,note) VALUES (?,'回滚测试','网站模板','fixture',350,350,CURDATE(),'finished','rollback only')");
    $insert->execute([$external]); $root = (int)$pdo->lastInsertId();
    $insert->execute([psp_child_no($external, 'b.example')]); $child = (int)$pdo->lastInsertId();
    foreach ([$root, $child] as $id) $pdo->prepare("INSERT INTO project_cash_movements (order_id,movement_type,amount,note,review_status,submitted_by_type,submitted_by_id) VALUES (?,'receipt',350,'rollback only','approved','admin',1)")->execute([$id]);
    psp_register($root, $root, $external, 'a.example', $actor);
    psp_register($child, $root, $external, 'b.example', $actor);
    $check(count(psp_members($root)) === 2, '两个独立网站项目均已关联同一付款号');
    $blocked = false; try { psp_approve_guard($child); } catch (RuntimeException $e) { $blocked = true; }
    $check($blocked, '财务确认前不能审核分成');
    $blocked = false; try { ps_approve_order($child, $actor, date('Y-m')); } catch (RuntimeException $e) { $blocked = str_contains($e->getMessage(), '付款号'); }
    $check($blocked, '实际分成审核入口已接入付款分配校验');
    psp_verify($root, '700.00', 'rollback fixture payment evidence', $actor);
    psp_approve_guard($root); psp_approve_guard($child);
    $pdo->prepare('UPDATE project_orders SET receipt_amount=300 WHERE id=?')->execute([$child]);
    $blocked = false; try { psp_approve_guard($root); } catch (RuntimeException $e) { $blocked = true; }
    $check($blocked, '任一项目收款变更后确认失效');
    echo "PASS rollback-only site allocation storage checks\n";
} finally {
    if (!$pdo->inTransaction()) throw new RuntimeException('Fixture transaction was committed unexpectedly');
    $pdo->rollBack();
}
