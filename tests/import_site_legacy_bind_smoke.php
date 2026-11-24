<?php
// 已有订单（没有网站项目标识）带域名重传：不再要求财务先登记第一个网站，系统把这一行当作原订单的网站并绑定标识。
// 只允许本地临时库：DB_HOST=127.0.0.1 DB_PORT=13399 php tests/import_site_legacy_bind_smoke.php
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
    $ai = $actorOf('张光萍'); $tech = $actorOf('李子晖');
    $check($ai && $tech, '账号存在');
    $n1 = "33195$tag" . '00001'; $n2 = "33195$tag" . '00002';
    $base = ['日期' => '2026.10.8', '店铺' => '美呀美', '付款昵称' => "l$tag", '售价' => '700', $sk => '已完成', '客服' => '张光萍', '前端（技术）' => '李子晖'];
    // 先按旧写法导入（没写域名 → 没有网站项目标识）
    [$pv, $imp, $err] = $importAs($ai, 'AI网站定制', $csvOf('AI网站定制', [$base + ['订单编号' => $n1], $base + ['订单编号' => $n2, '售价' => '600']]));
    $check($err === '' && $imp === 2, '旧订单先导入（无域名）：' . $imp . ' ' . $err);
    $siteOf = function ($no) use ($pdo) { $q = $pdo->prepare('SELECT p.site_key,p.root_order_id,o.id FROM project_orders o LEFT JOIN project_site_projects p ON p.order_id=o.id WHERE o.order_no=?'); $q->execute([$no]); return $q->fetch(); };
    $check(!$siteOf($n1)['site_key'], '旧订单没有网站项目标识');
    // 技术带域名重传：原来报“原订单尚未绑定网站项目标识，请财务先登记”
    [$pv, $imp, $err] = $importAs($tech, 'AI网站定制', $csvOf('AI网站定制', [$base + ['订单编号' => $n1, '网站域名' => "old$tag.com"]]));
    $e1 = $pv[0]['error'] ?? '';
    $check(strpos($e1, '请财务先') === false && ($pv[0]['base_valid'] ?? false), '带域名重传旧订单：不再被“财务先登记第一个网站”拦住：' . $e1 . ' ' . $err);
    $s1 = $siteOf($n1);
    $check($s1 && $s1['site_key'] === "old$tag.com" && (int)$s1['root_order_id'] === (int)$s1['id'], '原订单绑定为这一行的网站：' . json_encode($s1));
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM project_orders WHERE order_no LIKE '" . $n1 . "%'")->fetchColumn();
    $check($cnt === 1, '没有另建重复订单：' . $cnt);
    // 同一付款号再来第二个网站：作为同付款号下的子网站，不被拦
    [$pv, $imp, $err] = $importAs($tech, 'AI网站定制', $csvOf('AI网站定制', [$base + ['订单编号' => $n1, '网站域名' => "old$tag.com"], $base + ['订单编号' => $n1, '网站域名' => "new$tag.com", '售价' => '300']]));
    $check($err === '' && !array_filter($pv, function ($r) { return strpos($r['error'] ?? '', '请财务先') !== false; }), '同付款号第二个网站可导入：' . $err . ' ' . implode('；', array_map(function ($r) { return $r['error'] ?? ''; }, $pv)));
    // 同一次上传里，旧订单（无标识）的付款号写了两个网站：第一个绑定原订单，第二个成为子网站
    [$pv, $imp, $err] = $importAs($tech, 'AI网站定制', $csvOf('AI网站定制', [$base + ['订单编号' => $n2, '售价' => '600', '网站域名' => "p$tag.com"], $base + ['订单编号' => $n2, '售价' => '200', '网站域名' => "q$tag.com"]]));
    $s2 = $siteOf($n2);
    $kids = (int)$pdo->query("SELECT COUNT(*) FROM project_site_projects WHERE external_order_no='$n2'")->fetchColumn();
    $check($err === '' && $s2 && $s2['site_key'] === "p$tag.com" && $kids === 2, '一次上传两个网站：第一个绑定原订单，第二个另成子网站（共 ' . $kids . ' 个标识）：' . $err . ' ' . implode('；', array_map(function ($r) { return $r['error'] ?? ''; }, $pv)));
    // 同一付款号已按“网站模板”录过（没有网站标识），AI网站定制的人带域名上传同号订单：不再被拦（原单 / 分单都不重复建）
    $song = $actorOf('宋倩倩'); $n3 = "33195$tag" . '00003';
    [$pv, $imp, $err] = $importAs($song, '网站模板', $csvOf('网站模板', [['日期' => '2026.9.8', '店铺' => '美呀美', '付款昵称' => "w$tag", '订单编号' => $n3, '售价' => '600', $sk => '已完成', '客服' => '宋倩倩', '模板技术' => '张强', '程序名称' => 'jsp展示中级版']]));
    $check($imp === 1, '同付款号先按网站模板录入：' . $imp . ' ' . $err . ' ' . ($pv[0]['error'] ?? ''));
    [$pv, $imp, $err] = $importAs($ai, 'AI网站定制', $csvOf('AI网站定制', [$base + ['订单编号' => $n3, '售价' => '400', '网站域名' => "w$tag.com"]]));
    $e3 = $pv[0]['error'] ?? '';
    $check(strpos($e3, '网站项目标识') === false && strpos($e3, '请财务先') === false, '跨业务同付款号带域名上传：不再报网站项目标识：' . $e3 . ' ' . $err . ' imp=' . $imp);
    echo "
=== 旧订单带域名重传测试全部通过 ===
";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "
");
    exit(1);
} finally {
    foreach ($stored as $s) @unlink(ps_private_dir('imports') . '/' . basename($s) . '.php');
}
