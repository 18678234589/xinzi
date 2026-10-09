<?php
// 订单删除规则：客服 / 技术可删本人参与的未审核订单；有收款登记的不行；已审核订单财务可删（删前备份）、普通客服不行；已核算月份 / 已锁定不可删。
// 会写入测试数据，只允许本地临时库：DB_HOST=127.0.0.1 DB_PORT=13399 php tests/order_delete_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectOrderDelete.php';
if ((string)DB_PORT !== '13399' || DB_HOST !== '127.0.0.1') { fwrite(STDERR, "拒绝运行：本测试会写入数据，只能连本地临时库（DB_PORT=13399）\n"); exit(2); }
$pdo = db(); $tag = bin2hex(random_bytes(3));
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$actorOf = function ($name) use ($pdo) { $q = $pdo->prepare("SELECT u.id,u.employee_id,u.role FROM project_users u JOIN employees e ON e.id=u.employee_id WHERE e.name=? AND u.is_active=1"); $q->execute([$name]); $u = $q->fetch(); return ['type' => 'employee', 'id' => (int)$u['id'], 'employee_id' => (int)$u['employee_id'], 'role' => $u['role']]; };
$finance = ['type' => 'admin', 'id' => 1, 'role' => 'finance', 'employee_id' => 0];
$cs = $actorOf('宋倩倩'); $other = $actorOf('秦婷婷'); $dept = $actorOf('于洋');
$mk = function ($suffix, $type, $status, $date, $emp) use ($pdo, $tag) {
    $no = "DEL$tag$suffix";
    $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,order_date,delivery_status,settlement_status,note) VALUES (?,'测试',?,'','美呀美',100,?,'unfinished',?,'测试')")->execute([$no, $type, $date, $status]);
    $id = (int)$pdo->lastInsertId();
    if ($emp) $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'customer_service','客服',1)")->execute([$id, $emp]);
    return ['id' => $id, 'order_no' => $no, 'project_type' => $type, 'order_date' => $date, 'settlement_status' => $status];
};
try {
    $draft = $mk('A', '网站模板', 'draft', '2026-10-05', $cs['employee_id']);
    $check(pod_blocker($draft, $cs) === '', '客服删除本人参与的草稿订单：允许');
    $check(pod_blocker($draft, $other) !== '', '别的客服不能删：' . pod_blocker($draft, $other));
    $check(pod_blocker($draft, $finance) === '', '财务删除草稿订单：允许');
    $pdo->prepare("INSERT INTO project_cash_movements (order_id,movement_type,amount,note,review_status,submitted_by_type,submitted_by_id) VALUES (?,'receipt',50,'测试','approved','admin',1)")->execute([$draft['id']]);
    $check(pod_blocker($draft, $cs) !== '' && pod_blocker($draft, $finance) === '', '有收款登记：客服不能自己删，财务可以');
    $appr = $mk('B', '备案-单量', 'approved', '2026-10-06', $dept['employee_id']);
    $check(pod_blocker($appr, $dept) === '', '售后部删除已审核的备案-单量订单：允许');
    $check(pod_blocker($appr, $cs) !== '', '普通客服不能删已审核订单');
    $check(pod_blocker($appr, $finance) === '', '财务删除已审核订单：允许');
    $otherDept = $mk('F', '备案-单量', 'approved', '2026-10-06', $pdo->query("SELECT id FROM employees WHERE name='房烁'")->fetchColumn());
    $check(pod_blocker($otherDept, $dept) === '', '售后部主管可删同部门成员（房烁）的已审核备案-单量订单');
    $check(pod_blocker($otherDept, $cs) !== '', '网站客服不能删售后部成员的订单');
    $apprWeb = $mk('C', '网站模板', 'approved', '2026-10-06', $dept['employee_id']);
    $check(pod_blocker($apprWeb, $dept) !== '', '售后部不能删已审核的非售后业务订单（网站模板）');
    $old = $mk('D', '备案-单量', 'approved', '2026-07-06', $dept['employee_id']);
    $check(strpos(pod_blocker($old, $finance), '已核算') !== false, '已核算月份（2026-07）的已审核订单：财务也不能删');
    $locked = $mk('E', '备案-单量', 'locked', '2026-10-06', $dept['employee_id']);
    $check(pod_blocker($locked, $finance) !== '', '已锁定订单不能删');
    // 回收站：删除 → 进回收站 → 还原
    require_once __DIR__ . '/../includes/ProjectOrderTrash.php';
    pot_ensure();
    $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'technical','技术',1)")->execute([$appr['id'], $cs['employee_id']]);
    $pdo->prepare("INSERT INTO project_costs (order_id,category,item_name,quantity,unit,unit_price,amount,cost_kind,is_custom,reason,review_status) VALUES (?,'outsourcing','测试成本',1,'项',10,10,'one_time',1,'测试','approved')")->execute([$appr['id']]);
    $trashId = (int)substr(pod_backup($appr['id'], $appr['order_no'], $dept), strlen('回收站#'));
    $check($trashId > 0, '放入回收站：记录 #' . $trashId);
    foreach (['project_participants', 'project_costs'] as $t) $pdo->prepare("DELETE FROM $t WHERE order_id=?")->execute([$appr['id']]);
    $pdo->prepare('DELETE FROM project_orders WHERE id=?')->execute([$appr['id']]);
    $check(count(array_filter(pot_list($dept), function ($r) use ($trashId) { return (int)$r['id'] === $trashId; })) === 1, '删除人在回收站里看得到');
    $check(count(array_filter(pot_list($other), function ($r) use ($trashId) { return (int)$r['id'] === $trashId; })) === 0, '别的客服看不到这条');
    $bad = false; try { pot_restore($trashId, $other); } catch (RuntimeException $x) { $bad = true; }
    $check($bad, '别的客服不能还原');
    $no = pot_restore($trashId, $dept);
    $r = $pdo->prepare('SELECT (SELECT COUNT(*) FROM project_orders WHERE id=?) o,(SELECT COUNT(*) FROM project_participants WHERE order_id=?) p,(SELECT COUNT(*) FROM project_costs WHERE order_id=?) c'); $r->execute([$appr['id'], $appr['id'], $appr['id']]);
    $row = $r->fetch(); $check($no === $appr['order_no'] && (int)$row['o'] === 1 && (int)$row['p'] === 2 && (int)$row['c'] === 1, '还原：订单、参与人、成本都回来了');
    $bad = false; try { pot_restore($trashId, $dept); } catch (RuntimeException $x) { $bad = true; }
    $check($bad, '同一条不能重复还原');
    // 订单号被新订单占用时不能还原
    $t2 = (int)substr(pod_backup($appr['id'], $appr['order_no'], $dept), strlen('回收站#'));
    $pdo->prepare('DELETE FROM project_participants WHERE order_id=?')->execute([$appr['id']]); $pdo->prepare('DELETE FROM project_costs WHERE order_id=?')->execute([$appr['id']]); $pdo->prepare('DELETE FROM project_orders WHERE id=?')->execute([$appr['id']]);
    $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,order_date,delivery_status,note) VALUES (?,'新','备案-单量','','',0,'2026-10-06','finished','重新导入')")->execute([$appr['order_no']]);
    $bad = false; try { pot_restore($t2, $dept); } catch (RuntimeException $x) { $bad = mb_strpos($x->getMessage(), '已被') !== false; }
    $check($bad, '订单号被重新导入的订单占用时，提示先处理');
    $pdo->prepare("UPDATE project_order_trash SET expires_at=DATE_SUB(NOW(),INTERVAL 1 DAY) WHERE id=?")->execute([$t2]);
    pot_purge_expired(); $c = $pdo->prepare('SELECT COUNT(*) FROM project_order_trash WHERE id=?'); $c->execute([$t2]);
    $check((int)$c->fetchColumn() === 0, '超过 30 天的记录自动清除');
    echo "\n=== 订单删除规则测试全部通过 ===\n";
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
