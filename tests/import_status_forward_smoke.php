<?php
// 重复上传时状态只前进：之前未完成 / 没到账的订单，新表写已完成 / 到账后自动更新为已完成；写未完成不会把已完成改回去。
// 只允许本地临时库：DB_HOST=127.0.0.1 DB_PORT=13399 php tests/import_status_forward_smoke.php
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
$csvOf = function ($business, array $rows) { $head = ps_business_import_headers($business); $out = implode(',', $head) . "\n"; foreach ($rows as $row) { $cells = []; foreach ($head as $h) $cells[] = $row[$h] ?? ''; $out .= implode(',', $cells) . "\n"; } return $out; };
$sk = '状态(填已完成/未完成)';
$state = function ($no) use ($pdo) { $q = $pdo->prepare('SELECT delivery_status FROM project_orders WHERE order_no=?'); $q->execute([$no]); return $q->fetchColumn(); };
try {
    $tech = $actorOf('石凯新');
    $kk = '订单类型(下拉选择)';
    $row = function ($i, $status) use ($tag, $sk, $kk) { return ['日期' => '2026.9.8', '店铺' => '美呀美', '业务' => '小程序商城', $kk => '新订单', '付款昵称' => "sf$tag$i", '订单编号' => "33195$tag" . "0000$i", '售价' => '300', $sk => $status, '客服' => '朱俊英', '制作技术' => '石凯新']; };
    $importAs($tech, '小程序开发', $csvOf('小程序开发', [$row(1, '未完成'), $row(2, '未完成'), $row(3, '已完成')]));
    $n = function ($i) use ($tag) { return "33195$tag" . "0000$i"; };
    $check($state($n(1)) === 'unfinished' && $state($n(3)) === 'finished', '首次上传：1 未完成、3 已完成');
    [$pv, $imp, $err] = $importAs($tech, '小程序开发', $csvOf('小程序开发', [$row(1, '已完成'), $row(2, '未完成'), $row(3, '未完成')]));
    $check(mb_strpos((string)$pv[0]['warning'], '将更新为已完成') !== false, '预览提示：原单未完成，导入后更新为已完成');
    $check($state($n(1)) === 'finished', '重传写“已完成”：1 更新为已完成');
    $check($state($n(2)) === 'unfinished', '仍写“未完成”：2 不变');
    $check($state($n(3)) === 'finished', '已完成的订单，表里写“未完成”不会改回去（只前进）');
    $pdo->prepare("UPDATE project_orders SET settlement_status='approved' WHERE order_no=?")->execute([$n(2)]);
    $importAs($tech, '小程序开发', $csvOf('小程序开发', [$row(2, '已完成')]));
    $check($state($n(2)) === 'unfinished', '已审核订单不被改动');
    echo "\n=== 状态只前进测试全部通过 ===\n";
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
finally { foreach ($stored as $s) @unlink(ps_private_dir('imports') . '/' . basename($s) . '.php'); }
