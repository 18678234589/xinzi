<?php
// 上传预览里的拦截项能在页面上解决：同号几行“已导入过”合并后仍算跳过；订单已有别的技术时，勾选“我也参与这单”作为共同技术加入。
// 只允许本地临时库：DB_HOST=127.0.0.1 DB_PORT=13399 php tests/import_conflict_inline_smoke.php
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
$csvOf = function ($business, array $rows) {
    $head = ps_business_import_headers($business);
    foreach ($rows as $row) foreach (array_keys($row) as $k) if (!in_array($k, $head, true)) $head[] = $k; // 老写法的列（店铺 / 订单类型 / 模板技术 / 续费联系方式…）照常识别
    $out = implode(',', $head) . "\n";
    foreach ($rows as $row) { $cells = []; foreach ($head as $h) $cells[] = $row[$h] ?? ''; $out .= implode(',', $cells) . "\n"; }
    return $out;
};
$sk = '状态(填已完成/未完成)';

try {
    $ai = $actorOf('张光萍'); $tech = $actorOf('李子晖'); $tech2 = $actorOf('翟建跃');
    $check($ai && $tech && $tech2, '账号存在');
    $payTag = function ($n) use ($tag) { return "33197$tag" . $n; };
    // 一、AI网站定制订单已在系统里，技术在“小程序开发”表里把同一单写了两行：合并后是“已导入过”（跳过），不是“需处理”
    $n1 = $payTag('00001');
    $base = ['日期' => '2026.10.8', '店铺' => '美呀美', '付款昵称' => "l$tag", '售价' => '300', $sk => '已完成', '客服' => '张光萍', '前端（技术）' => '李子晖'];
    [$pv, $imp, $err] = $importAs($ai, 'AI网站定制', $csvOf('AI网站定制', [$base + ['订单编号' => $n1]]));
    $check($imp === 1, 'AI网站定制订单先导入');
    $row = ['日期' => '2026.10.8', '店铺' => '美呀美', '付款昵称' => "l$tag", '订单编号' => $n1, '售价' => '300', $sk => '已完成', '制作技术' => '李子晖', '客服' => '张光萍'];
    [$pv, $imp, $err] = $importAs($tech, '小程序开发', $csvOf('小程序开发', [$row, $row]));
    $check(count($pv) === 1 && $pv[0]['status'] === '已导入过' && !empty($pv[0]['mismatch_order_id']), '同号两行都“已导入过”：合并后仍是跳过，并给出原订单入口：' . ($pv[0]['status'] ?? '?') . ' ' . ($pv[0]['error'] ?? ''));
    $check(($pv[0]['order_date'] ?? '') === '2026-10-08', '跳过行带出表格里的日期：' . ($pv[0]['order_date'] ?? ''));
    // 二、订单已有别的技术：不勾选 → 提示可勾选；勾选 → 作为共同技术加入，技术分成均分
    $n2 = $payTag('00002');
    $b2 = ['日期' => '2026.10.9', '店铺' => '美呀美', '付款昵称' => "m$tag", '订单编号' => $n2, '售价' => '100', $sk => '已完成', '客服' => '张光萍', '制作技术' => '李子晖'];
    [$pv, $imp, $err] = $importAs($ai, '小程序开发', $csvOf('小程序开发', [$b2]));
    $check($imp === 1, '小程序订单先由李子晖+张光萍导入：' . $imp . ' ' . $err . ' ' . ($pv[0]['error'] ?? ''));
    $mine = $b2; $mine['制作技术'] = '翟建跃';
    [$pv, $imp, $err] = $importAs($tech2, '小程序开发', $csvOf('小程序开发', [$mine]));
    $check($imp === 0 && !empty($pv[0]['can_join']) && strpos($pv[0]['error'], '我也参与这单') !== false, '翟建跃上传同号订单：被拦，并出现可勾选的“我也参与这单”：' . ($pv[0]['error'] ?? '') . $err);
    // 勾选后重新核对再导入
    $fileId = (int)$_SESSION['project_import_file'];
    $run($tech2, ['action' => 'repair_preview', 'business' => '小程序开发', 'fix_join' => [$pv[0]['line'] => '1']]);
    $pv2 = $_SESSION['project_import_preview'] ?? [];
    $check(!empty($pv2[0]['base_valid']) && ($pv2[0]['join_group'] ?? '') === 'technical', '勾选后重新核对：这一行通过，将作为共同技术加入：' . ($pv2[0]['error'] ?? ''));
    $run($tech2, ['action' => 'commit', 'business' => '小程序开发', 'auto_import' => 1]);
    $q = $pdo->prepare("SELECT e.name,p.group_weight FROM project_participants p JOIN employees e ON e.id=p.employee_id JOIN project_orders o ON o.id=p.order_id WHERE o.order_no=? AND p.commission_group='technical' ORDER BY p.id");
    $q->execute([$n2]); $techs = $q->fetchAll(PDO::FETCH_KEY_PAIR);
    $check(count($techs) === 2 && isset($techs['翟建跃']) && abs((float)$techs['翟建跃'] - 0.5) < 0.000001, '共同技术加入，技术分成均分：' . json_encode($techs, JSON_UNESCAPED_UNICODE) . ' ' . ($GLOBALS['error'] ?? ''));
    echo "
=== 预览页拦截项在线解决测试全部通过 ===
";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "
");
    exit(1);
} finally {
    foreach ($stored as $s) @unlink(ps_private_dir('imports') . '/' . basename($s) . '.php');
}
