<?php
// 环境配置模板：成本紧跟售价、备注在最后一列；成本随表导入并写入订单成本。
// 只允许本地临时库：DB_HOST=127.0.0.1 DB_PORT=13399 php tests/import_env_config_cost_smoke.php
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
$bizAdded = 0;
try {
    $h = ps_business_import_headers('环境配置');
    $check(array_search('成本', $h) === array_search('售价', $h) + 1, '环境配置模板：成本紧跟售价');
    $check($h[count($h) - 1] === '备注（写客户电话或者微信）' && array_search('服务器 / 环境说明', $h) < count($h) - 1, '环境配置模板：备注在最后一列');
    $check(isset(ps_business_import_map('环境配置', $h, false)['direct_cost']), '成本列可被导入识别');
    $check(!in_array('微信交易流水号', $h, true), '环境配置模板没有单独的微信交易流水号列');
    $actor = $actorOf('张强') ?: $actorOf('纪鹏程');
    $check((bool)$actor, '技术账号存在');
    $hadBiz = (int)$pdo->query('SELECT COUNT(*) FROM project_user_businesses WHERE user_id=' . (int)$actor['id'] . " AND business_name='环境配置'")->fetchColumn();
    if (!$hadBiz) { $pdo->prepare("INSERT INTO project_user_businesses (user_id,business_name,is_default) VALUES (?,'环境配置',0)")->execute([(int)$actor['id']]); $bizAdded = (int)$actor['id']; }
    $no = "33300$tag" . '00001'; $no2 = "33300$tag" . '00002';
    $row = ['日期' => '2026.9.20', '店铺' => '美呀美', '付款昵称' => "env$tag", '售价' => '500', '成本' => '120', $sk => '已完成', '客服' => '宋倩倩', '技术' => $actor['employee_id'] ? ($pdo->query('SELECT name FROM employees WHERE id=' . (int)$actor['employee_id'])->fetchColumn()) : '张强', '备注（写客户电话或者微信）' => '13800138000'];
    $no3 = "33300$tag" . '00003'; $no4 = "33300$tag" . '00004';
    $csv = implode(',', $h) . "
";
    foreach ([$row + ['订单编号' => $no], array_merge($row, ['订单编号' => $no2, '成本' => '']), array_merge($row, ['店铺' => '微信', '订单编号' => $no3, '付款昵称' => "wx$tag"]), array_merge($row, ['店铺' => '对公', '订单编号' => $no4, '付款昵称' => "gg$tag"])] as $r) { $cells = []; foreach ($h as $col) $cells[] = $r[$col] ?? ''; $csv .= implode(',', $cells) . "
"; }
    [$pv, $imp, $err] = $importAs($actor, '环境配置', $csv);
    $check($err === '' && $imp === 4, '四行环境配置订单（含店铺写微信 / 对公）导入：' . $imp . ' ' . $err . ' ' . implode('；', array_map(function ($r) { return $r['error'] ?? ''; }, $pv)));
    $cost = function ($n) use ($pdo) { $q = $pdo->prepare("SELECT COALESCE(SUM(c.amount),0) FROM project_costs c JOIN project_orders o ON o.id=c.order_id WHERE o.order_no=?"); $q->execute([$n]); return (float)$q->fetchColumn(); };
    $check(abs($cost($no) - 120.0) < 0.01, '填了成本 120：写入订单成本 ' . $cost($no));
    $check(abs($cost($no2)) < 0.01, '成本留空：不记成本');
    $shopOf = function ($n) use ($pdo) { $q = $pdo->prepare('SELECT shop FROM project_orders WHERE order_no=?'); $q->execute([$n]); return $q->fetchColumn(); };
    $check($shopOf($no3) === '微信' && strpos((string)$shopOf($no4), '对公') === 0 && abs($cost($no3) - 120.0) < 0.01, '店铺写微信 / 对公、编码写在订单编号：订单号就是该编码，店铺记为微信 / 对公：' . json_encode([$shopOf($no3), $shopOf($no4)], JSON_UNESCAPED_UNICODE));
    echo "
=== 环境配置成本模板测试通过 ===
";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "
");
    exit(1);
} finally {
    if (!empty($bizAdded)) $pdo->prepare("DELETE FROM project_user_businesses WHERE user_id=? AND business_name='环境配置'")->execute([$bizAdded]);
    foreach ($stored as $s) @unlink(ps_private_dir('imports') . '/' . basename($s) . '.php');
}
