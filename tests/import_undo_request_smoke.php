<?php
// 撤销上传申请：合作人员传错表、订单被系统自动核对（自己撤不了）→ 申请财务同意 → 财务一键同意 / 不同意；
// 人工收退款 / 已申请结算 / 月份锁定的订单任何人都撤不了；原件被“删除原件”后财务仍能撤订单。
// 会提交数据（撤销内含事务），只允许本地隔离库：DB_HOST=127.0.0.1 DB_PORT=13399 DB_NAME=xinzi_test php tests/import_undo_request_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectImportUndoRequests.php';
require_once __DIR__ . '/require_isolated_database.php';
$pdo = db(); require_isolated_test_database($pdo);
if (DB_HOST !== '127.0.0.1' || (string)DB_PORT !== '13399') throw new RuntimeException('只允许本地隔离库');
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$throws = function (callable $f) { try { $f(); } catch (RuntimeException $e) { return $e->getMessage(); } return null; };
piu_ensure(); pu_owned_tables(); // 建表（DDL）要在任何事务之前
$tag = 'UT' . bin2hex(random_bytes(3));
$ids = []; $fileIds = []; $emps = [];
$fin = ['type' => 'admin', 'id' => (int)$pdo->query('SELECT MIN(id) FROM admins')->fetchColumn(), 'employee_id' => null, 'role' => 'finance', 'username' => 'admin'];
$mkEmp = function ($name) use ($pdo, &$emps) { $pdo->prepare('INSERT INTO employees (name,department,password) VALUES (?,?,?)')->execute([$name, '网站资料部', 'x']); return $emps[] = (int)$pdo->lastInsertId(); };
$mkFile = function ($empId, $name) use ($pdo, &$fileIds, $tag) {
    $pdo->prepare("INSERT INTO project_import_files (business_name,original_name,stored_name,uploaded_by_type,uploaded_by_id,employee_id,status,imported_count) VALUES ('网站模板',?,?,'employee',1,?,'imported',2)")->execute([$name, $tag . '_' . bin2hex(random_bytes(3)) . '.xlsx', $empId]);
    return $fileIds[] = (int)$pdo->lastInsertId();
};
// 一张自动核对过的订单：已审核、系统登记的收款、分成快照（月份可指定）、自动核对记录
$mkOrder = function ($no, $month = '2099-05') use ($pdo, &$ids, $tag) {
    $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,receipt_amount,order_date,delivery_status,settlement_status,note) VALUES (?,'测试客户','网站模板','','美呀美',200,200,?,'finished','approved','测试')")->execute([$tag . $no, $month . '-05']);
    $id = (int)$pdo->lastInsertId(); $ids[] = $id;
    $pdo->prepare("INSERT INTO project_cash_movements (order_id,movement_type,amount,review_status,submitted_by_type,submitted_by_id) VALUES (?,'receipt',200,'approved','system',0)")->execute([$id]);
    $pdo->prepare("INSERT INTO project_commission_snapshots (order_id,employee_id,commission_group,income_amount,direct_cost,contribution_profit,rule_id,rate,group_weight,commission_amount,payroll_month) VALUES (?,1,'customer_service',200,0,200,0,0.1,1,0,?)")->execute([$id, $month]);
    $pdo->prepare("INSERT INTO project_auto_reviews (order_id,state,policy_version,checked_row_version,fingerprint,reasons_json,evidence_json,checked_at,applied_at) VALUES (?,'auto_approved','t',0,'x','[]','{}',NOW(),NOW())")->execute([$id]);
    return $id;
};
$report = function ($fileId, array $orderIds, $empId) use ($pdo, $fin) {
    ps_audit('import_file', $fileId, 'import_result', ['type' => 'employee', 'id' => 1, 'employee_id' => $empId, 'role' => 'technical'], ['written' => count($orderIds), 'existing' => 0, 'pending' => 0, 'conflict' => 0, 'ignored' => 0, 'order_ids' => $orderIds, 'written_order_ids' => $orderIds, 'operator' => ['type' => 'employee', 'id' => 1, 'employee_id' => $empId, 'role' => 'technical']]);
};
$cleanup = function () use ($pdo, &$ids, &$fileIds, &$emps, $tag) {
    require_once __DIR__ . '/../includes/ProjectOrderDelete.php';
    foreach ($ids as $id) { try { pod_delete_rows((int)$id); } catch (Throwable $e) {} }
    $pdo->exec("DELETE FROM project_orders WHERE order_no LIKE '" . $tag . "%'");
    foreach ($fileIds as $f) { $pdo->exec("DELETE FROM project_import_files WHERE id=$f"); $pdo->exec("DELETE FROM project_import_undo_requests WHERE file_id=$f"); $pdo->exec("DELETE FROM project_audit_logs WHERE entity_type='import_file' AND entity_id=$f"); }
    $pdo->exec("DELETE FROM project_messages WHERE category='import_undo' AND (dedupe_key LIKE 'import_undo_req:%' OR dedupe_key LIKE 'import_undo_done:%')");
    foreach ($emps as $e) { $pdo->exec("DELETE FROM project_messages WHERE employee_id=$e"); $pdo->exec("DELETE FROM employees WHERE id=$e"); }
    $pdo->exec("DELETE FROM project_payroll_periods WHERE period='2099-06'");
    $pdo->exec("DELETE FROM project_order_trash WHERE order_no LIKE '" . $tag . "%'");
};
try {
    $gao = $mkEmp($tag . '高'); $other = $mkEmp($tag . '别人');
    $partner = ['type' => 'employee', 'id' => 1, 'employee_id' => $gao, 'role' => 'technical', 'username' => 'gao'];
    $stranger = ['type' => 'employee', 'id' => 2, 'employee_id' => $other, 'role' => 'technical', 'username' => 'bie'];

    echo "=== 一、自动核对的订单：合作人员自己撤不了，可申请财务同意 ===\n";
    $f1 = $mkFile($gao, '工资计算表.xlsx'); $o1 = $mkOrder('-A'); $o2 = $mkOrder('-B'); $report($f1, [$o1, $o2], $gao);
    $plan = pu_plan($f1, $partner);
    $check($plan['removable'] === 0 && $plan['approvable'] === 2 && $plan['kept'] === 2, '预演：直接可撤 0 单，需财务同意 2 单：' . json_encode([$plan['removable'], $plan['approvable']]));
    $check($throws(function () use ($f1, $partner) { pu_execute($f1, $partner, true); }) !== null, '合作人员直接撤销被拒（订单未动）');
    $check($throws(function () use ($f1, $partner) { pu_execute($f1, $partner, true, true); }) !== null, '合作人员不能自己带上“自动核对”的订单');
    $check($throws(function () use ($f1, $stranger) { pu_plan($f1, $stranger); }) !== null, '别人不能操作这张表格');
    $check($throws(function () use ($f1, $fin) { piu_submit($f1, $fin, '财务不用申请'); }) !== null, '财务不用申请，直接撤');
    $r = piu_submit($f1, $partner, '传错了表');
    $check($r['approvable'] === 2 && piu_pending_count() >= 1 && isset(piu_pending_by_file([$f1])[$f1]), '提交申请：待财务同意 ' . piu_pending_count() . ' 条，文件行可显示“撤销待财务同意”');
    $check($throws(function () use ($f1, $partner) { piu_submit($f1, $partner, '再来一次'); }) !== null, '同一张表格不能重复提交');
    $msg = (int)$pdo->query("SELECT COUNT(*) FROM project_messages WHERE category='import_undo' AND title LIKE '%申请撤销上传%'")->fetchColumn();
    echo "  [INFO] 给财务发出的站内信条数：$msg\n";
    $rid = (int)$pdo->query("SELECT id FROM project_import_undo_requests WHERE file_id=$f1")->fetchColumn();
    $check($throws(function () use ($rid, $partner) { piu_handle($rid, 'approved', '', $partner); }) !== null, '合作人员不能同意自己的申请');
    $check($throws(function () use ($rid, $fin) { piu_handle($rid, 'rejected', '', $fin); }) !== null, '不同意必须写原因');

    echo "=== 二、财务同意：立即撤销，订单进回收站，给申请人发站内信 ===\n";
    $res = piu_handle($rid, 'approved', '', $fin);
    $left = (int)$pdo->query('SELECT COUNT(*) FROM project_orders WHERE id IN (' . $o1 . ',' . $o2 . ')')->fetchColumn();
    $check($res['removed'] === 2 && $left === 0, '同意后 2 单已撤销，订单已不在库：removed=' . $res['removed']);
    $trash = (int)$pdo->query("SELECT COUNT(*) FROM project_order_trash WHERE order_no LIKE '" . $tag . "%'")->fetchColumn();
    $check($trash === 2, '订单进了回收站（30 天内可还原）：' . $trash . ' 条');
    $snapLeft = (int)$pdo->query("SELECT COUNT(*) FROM project_commission_snapshots WHERE order_id IN ($o1,$o2)")->fetchColumn() + (int)$pdo->query("SELECT COUNT(*) FROM project_cash_movements WHERE order_id IN ($o1,$o2)")->fetchColumn();
    $check($snapLeft === 0, '系统自动登记的收款和分成快照一起清掉');
    $check($pdo->query("SELECT status FROM project_import_undo_requests WHERE id=$rid")->fetchColumn() === 'approved' && (int)$pdo->query("SELECT COUNT(*) FROM project_import_files WHERE id=$f1")->fetchColumn() === 0, '申请标为已同意，原始表格一并删除');
    $note = (int)$pdo->query("SELECT COUNT(*) FROM project_messages WHERE employee_id=$gao AND category='import_undo' AND title LIKE '%已同意%'")->fetchColumn();
    $check($note === 1, '申请人收到站内信');
    $check($throws(function () use ($rid, $fin) { piu_handle($rid, 'approved', '', $fin); }) !== null, '已处理的申请不能重复处理');

    echo "=== 三、不同意：订单原样保留，申请人收到原因 ===\n";
    $f2 = $mkFile($gao, '第二张.xlsx'); $o3 = $mkOrder('-C'); $report($f2, [$o3], $gao);
    piu_submit($f2, $partner, '');
    $rid2 = (int)$pdo->query("SELECT id FROM project_import_undo_requests WHERE file_id=$f2 AND status='pending'")->fetchColumn();
    piu_handle($rid2, 'rejected', '这张表是对的，不要撤', $fin);
    $check((int)$pdo->query("SELECT COUNT(*) FROM project_orders WHERE id=$o3")->fetchColumn() === 1 && $pdo->query("SELECT status FROM project_import_undo_requests WHERE id=$rid2")->fetchColumn() === 'rejected', '不同意：订单还在，申请标为未同意');
    $check((int)$pdo->query("SELECT COUNT(*) FROM project_messages WHERE employee_id=$gao AND body LIKE '%这张表是对的，不要撤%'")->fetchColumn() === 1, '申请人收到不同意的原因');

    echo "=== 四、有人工操作 / 已申请结算 / 月份锁定的订单，财务同意也撤不了 ===\n";
    $f3 = $mkFile($gao, '第三张.xlsx'); $h1 = $mkOrder('-D'); $h2 = $mkOrder('-E'); $h3 = $mkOrder('-F', '2099-06'); $ok = $mkOrder('-G'); $report($f3, [$h1, $h2, $h3, $ok], $gao);
    $pdo->prepare("INSERT INTO project_cash_movements (order_id,movement_type,amount,review_status,submitted_by_type,submitted_by_id) VALUES (?,'refund',50,'approved','admin',1)")->execute([$h1]);
    $pdo->prepare("INSERT INTO project_settlement_requests (employee_id,payroll_month,status,amount,order_count) VALUES (?,'2099-05','pending',10,1)")->execute([$gao]); $reqId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO project_settlement_request_items (request_id,employee_id,order_id,payroll_month,amount) VALUES (?,?,?,?,10)')->execute([$reqId, $gao, $h2, '2099-05']);
    $pdo->prepare("INSERT INTO project_payroll_periods (period,status) VALUES ('2099-06','locked')")->execute();
    $plan = pu_plan($f3, $fin); $by = []; foreach ($plan['orders'] as $o) $by[$o['order_no']] = $o;
    $check(!$by[$tag . '-D']['approvable'] && !$by[$tag . '-E']['approvable'] && !$by[$tag . '-F']['approvable'] && $by[$tag . '-G']['approvable'], '人工退款 / 已申请结算 / 月份锁定的订单不可撤，干净的自动核对订单可撤：' . json_encode(array_map(function ($o) { return $o['approvable']; }, $by)));
    $check(mb_strpos(implode('、', $by[$tag . '-D']['hard']), '人工登记') !== false && mb_strpos(implode('、', $by[$tag . '-E']['hard']), '申请结算') !== false && mb_strpos(implode('、', $by[$tag . '-F']['hard']), '已核算或锁定') !== false, '每单都说明了为什么不能撤');
    $res = pu_execute($f3, $fin, true, true);
    $alive = (int)$pdo->query("SELECT COUNT(*) FROM project_orders WHERE id IN ($h1,$h2,$h3)")->fetchColumn();
    $check($res['removed'] === 1 && $alive === 3 && $res['kept'] === 3 && !$res['file_deleted'], '财务撤销：只撤了干净的 1 单，其余 3 单保留，原始表格也保留：' . json_encode($res));
    $pdo->exec("DELETE FROM project_settlement_request_items WHERE request_id=$reqId"); $pdo->exec("DELETE FROM project_settlement_requests WHERE id=$reqId");

    echo "=== 五、原件被“删除原件”后，订单还在：财务仍能直接撤销 ===\n";
    $f4 = $mkFile($gao, '被删原件.xlsx'); $d1 = $mkOrder('-H'); $report($f4, [$d1], $gao);
    ps_import_file_delete($f4, $partner);
    $fileGone = (int)$pdo->query("SELECT COUNT(*) FROM project_import_files WHERE id=$f4")->fetchColumn() === 0;
    $orph = array_values(array_filter(piu_deleted_uploads(), function ($x) use ($f4) { return $x['file_id'] === $f4; }));
    $check($fileGone && count($orph) === 1 && $orph[0]['alive'] === 1 && $orph[0]['name'] === '被删原件.xlsx', '原件已删，但订单还在库里：财务页面能列出来');
    $plan = pu_plan($f4, $fin);
    $check($plan['supported'] && $plan['approvable'] === 1 && !empty($plan['file']['deleted']), '财务可以对已删除原件的上传做预演');
    $check($throws(function () use ($f4, $stranger) { pu_plan($f4, $stranger); }) !== null, '别人仍不能操作');
    $res = pu_execute($f4, $fin, true, true);
    $check($res['removed'] === 1 && (int)$pdo->query("SELECT COUNT(*) FROM project_orders WHERE id=$d1")->fetchColumn() === 0 && !$res['file_deleted'], '财务撤销已删除原件的上传：订单撤掉');
    echo "=== 六、页面渲染：财务在“撤销上传”页签和原始表格页看到待同意的申请 ===\n";
    $f5 = $mkFile($gao, '渲染用.xlsx'); $r1 = $mkOrder('-R1'); $report($f5, [$r1], $gao);
    piu_submit($f5, $partner, '渲染测试：传错了');
    $_SESSION = ['admin_id' => (int)$fin['id'], 'project_csrf' => 'test-csrf']; $_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = []; $_POST = [];
    $_SERVER['SCRIPT_NAME'] = '/project/import_undo_requests.php';
    ob_start(); include __DIR__ . '/../project/import_undo_requests.php'; $html = ob_get_clean();
    $check(strpos($html, '渲染用.xlsx') !== false && strpos($html, '同意并撤销') !== false && strpos($html, '渲染测试：传错了') !== false && strpos($html, '撤销上传') !== false && strpos($html, $tag . '-R1') !== false, '撤销上传页签：显示申请人、文件、理由、订单和“同意并撤销”按钮');
    $_SERVER['SCRIPT_NAME'] = '/project/files.php';
    ob_start(); include __DIR__ . '/../project/files.php'; $html = ob_get_clean();
    $check(strpos($html, '撤销上传申请') !== false && strpos($html, 'import_undo_requests.php') !== false && strpos($html, '撤销待财务同意') !== false, '原始表格页：顶部提示有撤销申请待同意，文件行标“撤销待财务同意”');
    echo "\n=== 撤销上传申请测试全部通过，测试数据已清理 ===\n";
} finally {
    $cleanup();
}
