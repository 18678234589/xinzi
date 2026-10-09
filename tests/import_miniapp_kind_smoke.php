<?php
// 小程序技术表：订单类型列 / 业务列写“新订单”“其他订单”被识别，新订单有 ¥20 补助、其他订单没有（技术服务 5% 无补助）。
// 只允许本地临时库：DB_HOST=127.0.0.1 DB_PORT=13399 php tests/import_miniapp_kind_smoke.php
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
    $out = implode(',', $head) . "\n";
    foreach ($rows as $row) { $cells = []; foreach ($head as $h) $cells[] = $row[$h] ?? ''; $out .= implode(',', $cells) . "\n"; }
    return $out;
};
$sk = '状态(填已完成/未完成)';
try {
    $tech = $actorOf('石凯新');
    $check($tech, '石凯新账号存在');
    $check(in_array('订单类型', ps_business_import_headers('小程序开发'), true), '小程序开发模板有“订单类型”列');
    $mk = function ($i, $kind, $biz, $note = '') use ($tag, $sk) { return ['日期' => '2026.9.5', '店铺' => '美呀美', '业务' => $biz, '订单类型' => $kind, '付款昵称' => "mk$tag$i", '订单编号' => "33190$tag" . "0000$i", '售价' => '300', $sk => '已完成', '备注（写客户电话或者微信）' => $note, '客服' => '朱俊英', '制作技术' => '石凯新']; };
    $rows = [
        $mk(1, '新订单', '小程序商城'),
        $mk(2, '其他订单', '小程序商城'),
        $mk(3, '', '新订单 小程序商城'),
        $mk(4, '', '小程序商城 其他订单'),
        $mk(5, '定制', '小程序商城'),
        $mk(6, '续费', '小程序商城'),
    ];
    [$pv, $imp, $err] = $importAs($tech, '小程序开发', $csvOf('小程序开发', $rows));
    $kinds = array_column($pv, 'order_kind');
    $check($kinds === ['新订单', '技术服务', '新订单', '技术服务', '定制', '续费'], '订单类型：' . implode(',', $kinds) . ' ' . ($pv[0]['error'] ?? $err));
    $check(count(array_filter($pv, function ($r) { return mb_strpos((string)$r['warning'], '表格未写订单类型') !== false; })) === 0, '写了类型的行不再提示“未写订单类型”');
    $check($imp === 6, '6 行全部导入：' . $imp);
    $sub = $pdo->prepare("SELECT o.order_kind FROM project_orders o WHERE o.order_no=?");
    $sub->execute(["33190$tag" . '00002']); $check($sub->fetchColumn() === '技术服务', '落库：其他订单 = 技术服务（无补助档）');
    $sub->execute(["33190$tag" . '00001']); $check($sub->fetchColumn() === '新订单', '落库：新订单（每单 ¥20 补助档）');
    // 没写订单类型：看“制作要求 / 业务”描述——小程序商城新搭建才是新订单，注册公众号等是其他订单，拿不准默认其他订单（不给补助）
    $g = function ($i, $biz) use ($tag, $sk) { return ['日期' => '2026.9.6', '店铺' => '美呀美', '业务' => $biz, '付款昵称' => "gk$tag$i", '订单编号' => "33191$tag" . "0000$i", '售价' => '300', $sk => '已完成', '客服' => '朱俊英', '制作技术' => '石凯新']; };
    [$pv2, $imp2, $err2] = $importAs($tech, '小程序开发', $csvOf('小程序开发', [$g(1, '小程序商城'), $g(2, '注册公众号'), $g(3, '重新注册'), $g(4, '外卖小程序'), $g(5, '张三的东西')]));
    $kinds2 = array_column($pv2, 'order_kind');
    $check($kinds2 === ['新订单', '技术服务', '技术服务', '新订单', '技术服务'], '按描述识别：商城/外卖=新订单，公众号/重新注册/不明=其他订单：' . implode(',', $kinds2) . ' ' . $err2);
    echo "\n=== 小程序订单类型测试全部通过 ===\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
} finally {
    foreach ($stored as $s) @unlink(ps_private_dir('imports') . '/' . basename($s) . '.php');
}
