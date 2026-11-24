<?php
// 上传表格原始内容：导入时每一行的全部列（包括系统没有对应字段的列）存下来，订单详情页完整显示。
// 只允许本地临时库：DB_HOST=127.0.0.1 DB_PORT=13399 php tests/order_source_rows_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../tools/seed_pure_domain_templates.php';
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
    $song = $actorOf('高晶晶'); pdt_seed($pdo);
    $n1 = "33177$tag" . '00001'; $n2 = "33177$tag" . '00002';
    $base = ['日期' => '2026.10.8', '店铺' => '美呀美', '付款昵称' => "s$tag", '售价' => '120', $sk => '已完成', '客服' => '宋倩倩', '模板技术' => '高晶晶'];
    [$pv, $imp, $err] = $importAs($song, '网站模板', $csvOf('网站模板', [
        $base + ['订单编号' => $n1, '网站域名' => "sr$tag.com", '域名来源' => '我们注册', '程序名称' => '1年域名注册', '客户特别要求' => '页脚加备案号，logo 换成新的'],
        $base + ['售价' => '200', '订单编号' => $n2, '网站域名' => "sr$tag.cn", '域名来源' => '我们注册', '程序名称' => '1年域名注册', '技术备注' => "对接人张三 {$tag}"],
    ]));
    $check($err === '' && $imp === 2, '两行导入成功：' . $imp . ' ' . $err . ' ' . json_encode(array_map(function ($r) { return [$r['order_no'], $r['status'] ?? '', $r['error'] ?? '', $r['warning'] ?? '']; }, $pv), JSON_UNESCAPED_UNICODE));
    require_once __DIR__ . '/../includes/ProjectOrderSourceRows.php';
    $o1 = $orders($n1)[0] ?? null; $o2 = $orders($n2)[0] ?? null;
    $r1 = $o1 ? osr_for_order((int)$o1['id']) : []; $r2 = $o2 ? osr_for_order((int)$o2['id']) : [];
    $check(count($r1) === 1 && count($r2) === 1, '每张订单都存了对应的表格行');
    $c1 = json_decode($r1[0]['cells_json'], true); $map1 = array_column($c1, 'v', 'h');
    $check(($map1['客户特别要求'] ?? '') === '页脚加备案号，logo 换成新的', '系统没有对应字段的列也存下来了：' . json_encode($map1, JSON_UNESCAPED_UNICODE));
    $check(($map1['订单编号'] ?? '') === $n1 && ($map1['付款昵称'] ?? '') === "s$tag", '常规列也在');
    $html = osr_render(['role' => 'finance', 'employee_id' => 0], (int)$o2['id']);
    $check(strpos($html, "对接人张三 {$tag}") !== false && strpos($html, '上传表格原始内容') !== false, '订单页卡片显示了技术备注列的内容');
    echo "
=== 上传表格原始内容测试全部通过 ===
";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "
");
    exit(1);
} finally {
    foreach ($stored as $s) @unlink(ps_private_dir('imports') . '/' . basename($s) . '.php');
}
