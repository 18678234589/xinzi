<?php
// 森动备案：二次备案没有订单号 / 售价也能识别（域名＋联系微信号＋月份生成内部号，同域名重复各算一单），业务列定类型，首次备案未填成本给提示。
// 只允许本地临时库：DB_HOST=127.0.0.1 DB_PORT=13399 php tests/import_filing_smoke.php
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
    $luan = $actorOf('栾鑫');
    $rows = [
        ['日期' => '2026/9/1', '店铺' => '对公', '业务' => '备案', '付款昵称' => '黄渤小太阳' . $tag, '售价' => '100', '成本' => '80', $sk => '已完成'],
        ['日期' => '2026/9/2', '店铺' => '微信', '业务' => '备案', '付款昵称' => '中国旗袍官网' . $tag, '售价' => '100', '成本' => '80', $sk => '已完成'],
        ['日期' => '2026/9/7', '店铺' => '微信', '业务' => '备案', '付款昵称' => '百寿山泉' . $tag, '售价' => '100', $sk => '已完成'],
        ['日期' => '2026/9/7', '店铺' => '微信', '业务' => '备案', '付款昵称' => '百寿山泉' . $tag, '售价' => '100', $sk => '已完成'],
    ];
    [$pv, $imp, $err] = $importAs($luan, '森动备案', $csvOf('森动备案', $rows));
    echo "imported=$imp err=[$err]
";
    foreach ($pv as $r) echo json_encode(['no' => $r['order_no'] ?? '', 'shop' => $r['shop'] ?? '', 'nick' => $r['payment_nickname'] ?? '', 'kind' => $r['order_kind'] ?? '', 'err' => $r['error'] ?? '', 'warn' => $r['warning'] ?? '', 'pub' => $r['public_transfer'] ?? ''], JSON_UNESCAPED_UNICODE), "
";
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "
"); }

// cleanup
