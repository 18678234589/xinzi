<?php
// 订单审核申请：合作人员提交 → 财务抽查后一键通过（登记收款 + 交付完成 + 审核生成分成）；异常订单默认不会被带上；非财务不能审核。
// 会写入测试数据，只允许专用测试库：DB_NAME=xinzi_test DB_HOST=127.0.0.1 DB_PORT=13399 php tests/batch_review_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/require_isolated_database.php';
require_once __DIR__ . '/../includes/ProjectBatchReview.php';
if ((string)DB_PORT !== '13399' || DB_HOST !== '127.0.0.1') { fwrite(STDERR, "拒绝运行：只能连本地临时库（DB_PORT=13399）\n"); exit(2); }
$pdo = db(); require_isolated_test_database($pdo); $tag = bin2hex(random_bytes(3));
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$emp = (int)$pdo->query("SELECT id FROM employees WHERE name='阎泸琪'")->fetchColumn();
$user = $pdo->query("SELECT id FROM project_users WHERE employee_id=$emp LIMIT 1")->fetchColumn();
$mine = ['type' => 'employee', 'id' => (int)$user, 'employee_id' => $emp, 'role' => 'technical'];
$finance = ['type' => 'admin', 'id' => 1, 'role' => 'finance', 'employee_id' => 0];
$ids = []; $month = '2026-09';
$mk = function ($suffix, $amount) use ($pdo, $tag, $emp, &$ids) {
    $no = "BR$tag$suffix";
    $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,order_date,delivery_status,settlement_status,note) VALUES (?,'测试','平面设计','新订单','内部',?,'2026-09-12','unfinished','draft','测试')")->execute([$no, $amount]);
    $id = (int)$pdo->lastInsertId(); $ids[] = $id;
    $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'technical','设计',1)")->execute([$id, $emp]);
    return $id;
};
try {
    $check($emp > 0 && $user, '阎泸琪账号存在');
    $a = $mk('A', 100); $b = $mk('B', 60); $c = $mk('C', 30);
    $rows = pbr_orders($emp, $month);
    $mineRows = array_values(array_filter($rows, function ($r) use ($ids) { return in_array((int)$r['id'], $ids, true); }));
    $check(count($mineRows) === 3 && in_array('还没有确认的收款', $mineRows[0]['why'], true) && in_array('还没确认业务已完成', $mineRows[0]['why'], true), '申请人看得到 3 单，并写明卡在“没确认收款 / 没确认交付”');
    $rid = pbr_submit($mine, $month, '都是已到账的');
    $check($rid > 0 && pbr_pending_count() >= 1, '提交申请成功，财务待办 +1');
    $check(pbr_submit($mine, $month, '再提交一次') === $rid, '同月重复提交只更新同一条申请');
    $threw = false; try { pbr_approve($mine, $rid, [$a]); } catch (RuntimeException $e) { $threw = true; }
    $check($threw, '合作人员自己不能审核');
    [$done, $failed] = pbr_approve($finance, $rid, [$a, $b]);
    $st = function ($id) use ($pdo) { return $pdo->query("SELECT settlement_status,delivery_status,receipt_amount FROM project_orders WHERE id=$id")->fetch(); };
    $check($done === 2 && !$failed, '财务勾选 2 单一键通过：' . $done . ' ' . implode('；', $failed));
    $sa = $st($a); $sc = $st($c);
    $check($sa['settlement_status'] === 'approved' && $sa['delivery_status'] === 'finished' && (float)$sa['receipt_amount'] === 100.0, '通过的订单：已登记收款 100、交付完成、已审核');
    $check($sc['settlement_status'] === 'draft' && (float)$sc['receipt_amount'] === 0.0, '没勾选的订单保持原样');
    $check((int)$pdo->query("SELECT status='pending' FROM project_batch_reviews WHERE id=$rid")->fetchColumn() === 1, '还有没通过的订单：申请仍是待处理');
    [$done2] = pbr_approve($finance, $rid, [$c]);
    $left = count(pbr_orders($emp, $month));
    $check($done2 === 1 && ($left > 0 || $pdo->query("SELECT status FROM project_batch_reviews WHERE id=$rid")->fetchColumn() === 'done'), '剩下的也通过；该月没有未审核订单时申请自动变为已处理（该月还剩 ' . $left . ' 单）');
    $check((int)$pdo->query("SELECT COUNT(*) FROM project_commission_snapshots WHERE order_id=$a")->fetchColumn() === 1, '审核生成了分成快照');
    $d = $mk('D', 20);
    $check(in_array($emp, array_map('intval', array_column(pbr_employees($month), 'id')), true), '财务选人列表里有这位员工');
    $threw = false; try { pbr_approve($finance, 0, [$d]); } catch (RuntimeException $e) { $threw = true; }
    $check($threw, '直接审核必须选员工和月份');
    $pdo->prepare("INSERT INTO project_costs (order_id,category,item_name,quantity,unit,unit_price,amount,cost_kind,is_custom,reason,review_status) VALUES (?,'outsourcing','外包',1,'项',5,5,'one_time',1,'测试','pending')")->execute([$d]);
    [$done3, $failed3] = pbr_approve($finance, 0, [$d], $emp, $month);
    $check($done3 === 1 && !$failed3 && $st($d)['settlement_status'] === 'approved', '财务不用申请，直接选员工 + 月份审核通过');
    $check((int)$pdo->query("SELECT COUNT(*) FROM project_costs WHERE order_id=$d AND review_status='approved'")->fetchColumn() === 1, '批量审核时待审核成本一并通过');
    echo "全部通过\n";
} finally {
    require_once __DIR__ . '/../includes/ProjectOrderTrash.php';
    foreach ($ids as $id) { foreach (POT_TABLES as $t) { try { $pdo->exec("DELETE FROM $t WHERE order_id=$id"); } catch (Throwable $e) {} } try { $pdo->exec("DELETE FROM project_auto_reviews WHERE order_id=$id"); } catch (Throwable $e) {} $pdo->exec("DELETE FROM project_orders WHERE id=$id"); }
    $pdo->exec("DELETE FROM project_batch_reviews WHERE employee_id=$emp AND month='$month' AND note LIKE '%已到账%' OR note='再提交一次'");
}
