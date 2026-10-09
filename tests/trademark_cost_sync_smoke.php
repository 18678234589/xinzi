<?php
// 商标成本：补充上传补齐成本、Excel 成本与成本中心标准价取较大者且自动通过、资料 / 提交专员每件各 2.2。
// 只允许本地临时库：DB_HOST=127.0.0.1 DB_PORT=13399 php tests/trademark_cost_sync_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
if ((string)DB_PORT !== '13399' || DB_HOST !== '127.0.0.1') { fwrite(STDERR, "拒绝运行：本测试会提交数据，只能连本地临时库（DB_PORT=13399）\n"); exit(2); }

$pdo = db();
$stored = [];
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$E = function ($name) use ($pdo) { $q = $pdo->prepare('SELECT id FROM employees WHERE name=? ORDER BY id LIMIT 1'); $q->execute([$name]); return (int)$q->fetchColumn(); };
$actorOf = function ($name) use ($pdo) { $q = $pdo->prepare("SELECT u.id,u.employee_id,u.role FROM project_users u JOIN employees e ON e.id=u.employee_id WHERE e.name=? AND u.is_active=1"); $q->execute([$name]); $u = $q->fetch(); return $u ? ['type' => 'employee', 'id' => (int)$u['id'], 'employee_id' => (int)$u['employee_id'], 'role' => $u['role']] : null; };
$upload = function ($actor, $business, $csv) use ($pdo, &$stored) {
    $tmp = tempnam(sys_get_temp_dir(), 'ws') . '.csv'; file_put_contents($tmp, $csv);
    $name = ps_private_store('imports', $tmp, 'avoid_' . bin2hex(random_bytes(5)) . '.csv'); $stored[] = $name; @unlink($tmp);
    $pdo->prepare("INSERT INTO project_import_files (business_name,original_name,stored_name,file_size,uploaded_by_type,uploaded_by_id,employee_id) VALUES (?,'测试.csv',?,?,'employee',?,?)")->execute([$business, $name, strlen($csv), $actor['id'], $actor['employee_id']]);
    return (int)$pdo->lastInsertId();
};
$run = function ($actor, $post) { $_SESSION = array_merge($_SESSION ?? [], ['project_user_id' => $actor['id'], 'project_csrf' => 'test-csrf']); $_SERVER['SCRIPT_NAME'] = '/project/import.php'; $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = $post + ['csrf' => 'test-csrf']; $GLOBALS['error'] = ''; $GLOBALS['imported'] = 0; ob_start(); include __DIR__ . '/../project/import.php'; ob_end_clean(); $GLOBALS['imported'] = $imported ?? 0; $GLOBALS['error'] = $error ?? ''; };
$importAs = function ($actor, $business, $csv) use ($upload, $run) {
    $fileId = $upload($actor, $business, $csv);
    $run($actor, ['action' => 'repreview', 'business' => $business, 'file_id' => $fileId, 'all_sheets' => 1]);
    $preview = $_SESSION['project_import_preview'] ?? [];
    $run($actor, ['action' => 'commit', 'business' => $business, 'auto_import' => 1]);
    return [$preview, $GLOBALS['imported'], $GLOBALS['error']];
};
$orders = function ($like) use ($pdo) { $q = $pdo->prepare('SELECT id,order_no,project_type,contract_amount FROM project_orders WHERE order_no LIKE ? ORDER BY id'); $q->execute([$like]); return $q->fetchAll(); };
$tag = bin2hex(random_bytes(3));
require_once __DIR__ . '/../includes/ProjectTrademarkCost.php';
$costsOf = function ($no) use ($pdo) { $q = $pdo->prepare("SELECT c.amount,c.review_status,c.template_id FROM project_costs c JOIN project_orders o ON o.id=c.order_id WHERE o.order_no=? AND c.review_status<>'rejected' ORDER BY c.id"); $q->execute([$no]); return $q->fetchAll(); };
$head = "日期,店铺,付款昵称,订单编号,售价,状态,商标名称,商标个数,网报类型,成本\n";
try {
    passthru('php ' . escapeshellarg(__DIR__ . '/../tools/seed_trademark_costs.php') . ' --commit', $rc);
    $qin = $actorOf('秦婷婷'); $hui = $actorOf('王慧资'); $qing = $actorOf('王庆美');
    $check($qin && $hui && $qing, '账号存在');
    $no = function ($i) use ($tag) { return "TMS$tag-$i"; };

    echo "=== 一、先建单没有件数 / 成本，资料专员补充件数后成本补齐 ===\n";
    [$pv, $imp, $err] = $importAs($qin, '商标', $head . "2026.9.20,微信,甲,{$no(1)},700,已完成,图形 9类,,公司网报,\n");
    $check($err === '' && $imp === 1 && $costsOf($no(1)) === [], '客服表没写件数和成本：订单暂无成本：' . ($pv[0]['error'] ?? $err));
    [$pv, $imp, $err] = $importAs($hui, '商标', $head . "2026.9.20,微信,甲,{$no(1)},700,已完成,图形 9类,2,公司网报,\n");
    $c = $costsOf($no(1));
    $check($err === '' && count($c) === 1 && (float)$c[0]['amount'] === 540.0 && $c[0]['review_status'] === 'approved', '专员补充件数 2 后补齐成本 2 × 270 = ¥540（已通过）：' . json_encode($c) . ($pv[0]['error'] ?? $err));

    echo "=== 二、Excel 成本超过 ¥500：自动通过，不再卡在待审核 ===\n";
    [$pv, $imp, $err] = $importAs($qin, '商标', $head . "2026.9.20,微信,乙,{$no(2)},700,已完成,图形 9类,2,公司网报,540\n");
    $c = $costsOf($no(2));
    $check($imp === 1 && count($c) === 1 && (float)$c[0]['amount'] === 540.0 && $c[0]['review_status'] === 'approved', 'Excel 成本 540 与标准价一致：1 笔、已通过：' . json_encode($c));

    echo "=== 三、Excel 与成本中心取较大者 ===\n";
    [$pv, $imp, $err] = $importAs($qin, '商标', $head . "2026.9.20,微信,丙,{$no(3)},900,已完成,图形 9类,2,公司网报,600\n" . "2026.9.20,微信,丁,{$no(4)},700,已完成,图形 9类,2,公司网报,200\n" . "2026.9.20,微信,戊,{$no(5)},300,已完成,图形 9类,,公司网报,300\n");
    $c3 = $costsOf($no(3)); $c4 = $costsOf($no(4)); $c5 = $costsOf($no(5));
    $check(count($c3) === 1 && (float)$c3[0]['amount'] === 600.0 && $c3[0]['review_status'] === 'approved', '自动成本 540 < Excel 600：以 Excel 600 为准');
    $check(count($c4) === 1 && (float)$c4[0]['amount'] === 540.0, 'Excel 200 < 自动成本 540：取自动成本 540：' . json_encode($c4));
    $check(count($c5) === 1 && (float)$c5[0]['amount'] === 300.0, '没写件数、自动算不出：以 Excel 300 为准');
    [$pv, $imp, $err] = $importAs($qin, '商标', $head . "2026.9.20,微信,丙,{$no(3)},900,已完成,图形 9类,2,公司网报,600\n");
    $check(count($costsOf($no(3))) === 1, '重复上传不重复记成本');

    echo "=== 四、历史订单：待审核 / 无成本订单用修复工具补齐 ===\n";
    $oid = (int)$pdo->query("SELECT id FROM project_orders WHERE order_no='" . $no(2) . "'")->fetchColumn();
    $pdo->prepare("UPDATE project_costs SET review_status='pending',template_id=NULL,reason='Excel 第3行导入' WHERE order_id=?")->execute([$oid]);
    $oid1 = (int)$pdo->query("SELECT id FROM project_orders WHERE order_no='" . $no(1) . "'")->fetchColumn();
    $pdo->prepare("DELETE FROM project_costs WHERE order_id=?")->execute([$oid1]);
    ob_start(); passthru('php ' . escapeshellarg(__DIR__ . '/../tools/repair_trademark_costs.php') . ' 2026-09-01 --commit', $rc); $out = ob_get_clean();
    $check($rc === 0 && mb_strpos($out, '已提交') !== false, '修复工具运行成功');
    $c2 = $costsOf($no(2)); $c1 = $costsOf($no(1));
    $check(count($c2) === 1 && $c2[0]['review_status'] === 'approved', '待审核成本已自动通过');
    $check(count($c1) === 1 && (float)$c1[0]['amount'] === 540.0, '没有成本的订单已按件数补上 ¥540');

    echo "=== 五、资料专员、提交专员每件各 2.2（不再均分成 1.1） ===\n";
    $order = $pdo->query("SELECT * FROM project_orders WHERE order_no='" . $no(1) . "'")->fetch();
    $pdo->prepare("UPDATE project_order_details SET details_json=JSON_SET(details_json,'$.trademark_count','1') WHERE order_id=?")->execute([$order['id']]);
    ps_trademark_add_technical((int)$order['id'], (int)$qing['employee_id'], '提交专员');
    $parts = $pdo->query("SELECT p.*,e.name FROM project_participants p JOIN employees e ON e.id=p.employee_id WHERE p.order_id=" . (int)$order['id'])->fetchAll();
    $costs = $pdo->query("SELECT * FROM project_costs WHERE order_id=" . (int)$order['id'])->fetchAll();
    $sum = ps_summary($order, $costs, $parts);
    $sub = []; foreach ($sum['groups']['technical']['people'] as $p) $sub[$p['name']] = (float)$p['calc']['subsidy'];
    $check(count($sub) === 2 && array_values($sub) === [2.2, 2.2], '1 件：两位专员各 ¥2.20：' . json_encode($sub, JSON_UNESCAPED_UNICODE));
    echo "\n=== 商标成本与专员提成测试全部通过 ===\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
} finally {
    foreach ($stored as $s) @unlink(ps_private_dir('imports') . '/' . basename($s) . '.php');
}
