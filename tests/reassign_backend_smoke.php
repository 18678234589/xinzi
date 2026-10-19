<?php
// 已审核订单补指定后端（财务）：撤回快照 → 指定后端 → 原月份重新审核；失败整体回滚。
// 会写入测试数据，只允许本地隔离库：DB_HOST=127.0.0.1 DB_PORT=13399 DB_NAME=xinzi_test php tests/reassign_backend_smoke.php
if (PHP_SAPI !== 'cli' || !preg_match('/_(test|ci|sandbox)$/', (string)getenv('DB_NAME')) || getenv('DB_PORT') !== '13399') { fwrite(STDERR, "只能在本地隔离库运行\n"); exit(1); }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectSettlement.php';
$pdo = db(); $tag = bin2hex(random_bytes(3));
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$E = function ($name) use ($pdo) { $q = $pdo->prepare('SELECT id FROM employees WHERE name=? ORDER BY id LIMIT 1'); $q->execute([$name]); return (int)$q->fetchColumn(); };
$front = $E('刘帅'); $back = $E('崔鑫栋'); $cs = $E('董旭');
$id = 0;
try {
    $check($front && $back && $cs, '刘帅、崔鑫栋、董旭存在');
    $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,receipt_amount,order_date,delivery_status,settlement_status,note) VALUES (?,'测试','AI网站定制','','内部',1000,1000,'2026-09-05','finished','draft','测试')")->execute(['RB' . $tag]);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'technical','前端',1)")->execute([$id, $front]);
    $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'customer_service','客服',1)")->execute([$id, $cs]);
    $pdo->prepare("INSERT INTO project_order_resources (order_id,source_type,domain_mode) VALUES (?,'manual','none')")->execute([$id]);
    $finance = ['type' => 'admin', 'id' => 1, 'role' => 'finance', 'employee_id' => 0, 'username' => 'admin'];
    ps_approve_order($id, $finance, '2026-09');
    $snaps = function () use ($pdo, $id) { return (int)$pdo->query("SELECT COUNT(*) FROM project_commission_snapshots WHERE order_id=$id")->fetchColumn(); };
    $before = $snaps();
    $check($before >= 2, '先按只有前端审核通过：生成 ' . $before . ' 条快照');
    $run = function ($actor, $backendId) use ($pdo, $id) {
        $_POST = ['backend_employee_id' => $backendId]; $finance = ($actor['role'] === 'finance');
        $q = $pdo->prepare('SELECT * FROM project_orders WHERE id=?'); $q->execute([$id]); $order = $q->fetch();
        try { include __DIR__ . '/../project/order/actions/participants/reassign_backend_approved.php'; return null; } catch (RuntimeException $e) { return $e->getMessage(); }
    };
    $err = $run(['type' => 'employee', 'id' => 9, 'role' => 'technical', 'employee_id' => $front], $back);
    $check($err === '只有财务可以对已审核订单指定后端' && $snaps() === $before, '非财务不能操作，数据不变');
    $err = $run($finance, $back);
    $roles = $pdo->query("SELECT p.employee_id,p.role_name FROM project_participants p WHERE p.order_id=$id AND p.commission_group='technical'")->fetchAll(PDO::FETCH_KEY_PAIR);
    $st = $pdo->query("SELECT settlement_status FROM project_orders WHERE id=$id")->fetchColumn();
    $check($err === null && ($roles[$back] ?? '') === '后端' && ($roles[$front] ?? '') === '前端' && $st === 'approved', '财务指定后端：后端加入、前端不变、订单重新审核通过：' . ($err ?? 'ok'));
    $hasBackSnap = (int)$pdo->query("SELECT COUNT(*) FROM project_commission_snapshots WHERE order_id=$id AND employee_id=$back")->fetchColumn();
    $check($hasBackSnap === 1 && $snaps() === $before + 1, '后端有了分成快照，其他人快照重新生成');
    $bk = (string)$pdo->query("SELECT details_json FROM project_audit_logs WHERE entity_type='order' AND entity_id=$id AND action='reassign_backend_after_approval' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $check(strpos($bk, 'snapshots_backup') !== false, '撤回前的快照备份在审计日志里');
    $pdo->prepare("UPDATE project_payroll_periods SET status='locked' WHERE period='2026-09'")->execute();
    $err = $run($finance, $front);
    $check($err !== null && mb_strpos($err, '已锁定') !== false && $snaps() === $before + 1, '该月项目分成已锁定时拒绝，数据不变：' . $err);
} finally {
    $pdo->exec("UPDATE project_payroll_periods SET status='draft' WHERE period='2026-09'");
    if ($id) { foreach (['project_commission_snapshots', 'project_participants', 'project_order_resources'] as $t) $pdo->exec("DELETE FROM $t WHERE order_id=$id"); $pdo->exec("DELETE FROM project_audit_logs WHERE entity_type='order' AND entity_id=$id"); $pdo->exec("DELETE FROM project_orders WHERE id=$id"); }
}
echo "全部通过，测试数据已清理\n";
