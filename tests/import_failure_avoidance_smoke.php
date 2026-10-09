<?php
// 导入失败规避：附加项行并回原单、多网站无域名时自动编号、售价算式、店铺 / 昵称写法不同不再拦截、技术上传时客服列写了非人名。
// 导入流程含建表语句会提前提交，所以只允许在本地临时库运行。DB_HOST=127.0.0.1 DB_PORT=13399 php tests/import_failure_avoidance_smoke.php
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

try {
    $song = $actorOf('宋倩倩'); $zhang = $actorOf('张强'); $sun = $actorOf('孙妍'); $wang = $actorOf('王慧资'); $qin = $actorOf('秦婷婷');
    $check($song && $zhang && $sun && $wang && $qin, '相关账号存在');

    echo "=== 一、同付款号的附加项行（SSL / 安全证书）并回原单，不要求网站项目标识 ===\n";
    $n1 = "33170$tag" . '11111';
    $head = "日期,店铺,付款昵称,订单编号,程序名称,客服,模板技术,售价,状态\n";
    [$pv, $imp, $err] = $importAs($song, '网站模板', $head
        . "2026.9.5,美呀美,nickA,$n1,jsp展示中级版,宋倩倩,张强,998,已完成\n"
        . "2026.9.5,美呀美,nickA,$n1,安全证书,宋倩倩,张强,200,已完成\n");
    $check($err === '' && $imp === 1 && count($pv) === 1 && !empty($pv[0]['base_valid']), '2 行并成 1 张订单可导入：' . ($pv[0]['error'] ?? $err));
    $o = $pdo->prepare('SELECT contract_amount FROM project_orders WHERE order_no=?'); $o->execute([$n1]);
    $check((float)$o->fetchColumn() === 1198.0, '订单售价 = 998 + 200 = 1198');

    echo "=== 二、一个付款号多个网站且表里没有域名：按顺序自动标识，全部可导入，重传不重复 ===\n";
    $n2 = "33170$tag" . '22222';
    $csv2 = $head
        . "2026.9.6,美呀美,nickB,$n2,php,宋倩倩,张强,200,已完成\n"
        . "2026.9.6,美呀美,nickB,$n2,php,宋倩倩,张强,170,已完成\n"
        . "2026.9.6,美呀美,nickB,$n2,php,宋倩倩,张强,170,已完成\n"
        . "2026.9.6,美呀美,nickB,$n2,ssl一年,宋倩倩,张强,30,已完成\n";
    [$pv, $imp, $err] = $importAs($song, '网站模板', $csv2);
    $valid = count(array_filter($pv, fn($r) => !empty($r['base_valid'])));
    $check($valid === count($pv) && $err === '' && $imp === 3, '3 个网站各成一单（SSL 行并入第 1 个网站）：行 ' . count($pv) . ' 可导入 ' . $valid . ' 导入 ' . $imp . ' ' . ($pv[1]['error'] ?? ''));
    $q = $pdo->prepare('SELECT o.order_no,o.contract_amount,p.site_key FROM project_orders o LEFT JOIN project_site_projects p ON p.order_id=o.id WHERE o.order_no=? OR o.order_no LIKE ? ORDER BY o.id'); $q->execute([$n2, addcslashes($n2, '\%_') . '~%']); $rows = $q->fetchAll();
    $check(count($rows) === 3 && array_column($rows, 'site_key') === ['auto-1', 'auto-2', 'auto-3'], '三张订单的网站标识为 auto-1 / auto-2 / auto-3');
    $check((float)$rows[0]['contract_amount'] === 230.0 && (float)$rows[1]['contract_amount'] === 170.0, '金额：第 1 个网站 200 + SSL 30，其余 170');
    [$pv2, $imp2] = $importAs($song, '网站模板', $csv2);
    $q->execute([$n2, addcslashes($n2, '\%_') . '~%']);
    $check(count($q->fetchAll()) === 3, '整张表重传不重复建单');

    echo "=== 三、同一付款号一行写了域名、另一行没写：仍须补全，不乱编号 ===\n";
    $n3 = "33170$tag" . '33333';
    $head3 = "日期,店铺,付款昵称,订单编号,程序名称,客服,模板技术,售价,状态,网站项目标识\n";
    [$pv, $imp, $err] = $importAs($song, '网站模板', $head3
        . "2026.9.7,美呀美,nickC,$n3,php,宋倩倩,张强,200,已完成,a.example.com\n"
        . "2026.9.7,美呀美,nickC,$n3,php,宋倩倩,张强,170,已完成,\n");
    $check($imp === 0 && count(array_filter($pv, fn($r) => empty($r['base_valid']))) === 2 && mb_strpos((string)$pv[0]['error'], '网站项目标识') !== false, '混合写法被拦并提示补全网站项目标识');

    echo "=== 四、售价写成算式 640+260 ===\n";
    $n4 = "33170$tag" . '44444';
    $head4 = "日期,店铺,订单编号,售价,状态,商标名称,商标个数\n";
    [$pv, $imp, $err] = $importAs($wang, '商标', $head4 . "2026.9.8,微信,$n4,640+260,已完成,图形 9类注册,1\n");
    $check($err === '' && $imp === 1 && ($pv[0]['contract_amount'] ?? '') === '900.00', '“640+260”按 ¥900 导入并提示：' . ($pv[0]['error'] ?? ($pv[0]['contract_amount'] ?? '')));

    echo "=== 五、店铺 / 付款昵称写法不同但售价一致：关联已有订单，不再拦截；售价不一致仍拦截 ===\n";
    $n5 = "33170$tag" . '55555'; $n6 = "33170$tag" . '66666';
    foreach ([[$n5, 300], [$n6, 400]] as [$no, $amt]) {
        $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,order_date,delivery_status,note) VALUES (?,'测试','商标','普通订单','微信',?,'2026-09-09','unfinished','测试')")->execute([$no, $amt]);
        $id = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO project_order_sources (order_id,payment_nickname,price_source) VALUES (?,'wujunrong885','manual')")->execute([$id]);
        $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'technical','资料专员',1)")->execute([$id, $E('王庆美')]);
    }
    $head5 = "日期,店铺,付款昵称,订单编号,售价,状态,商标名称,商标个数\n";
    [$pv, $imp, $err] = $importAs($qin, '商标', $head5
        . "2026.9.9,美呀美,wujunrong885  老客户Angel,$n5,300,已完成,图 9类注册,1\n"
        . "2026.9.9,美呀美,wujunrong885,$n6,450,已完成,图 9类注册,1\n");
    $byNo = []; foreach ($pv as $r) $byNo[$r['order_no']] = $r;
    $check(!empty($byNo[$n5]['base_valid']) && mb_strpos((string)($byNo[$n5]['warning'] ?? ''), '写法不同') !== false, '店铺不同 + 昵称包含、售价一致：可关联，给出提示');
    $check(empty($byNo[$n6]['base_valid']) && mb_strpos((string)$byNo[$n6]['error'], '售价') !== false, '售价 450 对 400：仍拦截');
    $part = $pdo->prepare('SELECT COUNT(*) FROM project_participants p JOIN project_orders o ON o.id=p.order_id WHERE o.order_no=? AND p.employee_id=?'); $part->execute([$n5, $E('秦婷婷')]);
    $check((int)$part->fetchColumn() === 1, '秦婷婷已关联到该订单');

    echo "=== 六、技术上传时客服列写了非人名（如“大连”）：忽略并提示，不拦整行 ===\n";
    $n7 = "33170$tag" . '77777';
    [$pv, $imp, $err] = $importAs($sun, '网站模板', $head . "2026.9.10,美呀美,nickD,$n7,jsp展示中级版,大连,孙妍,200,已完成\n");
    $check(!empty($pv[0]['base_valid']) && $imp === 1 && mb_strpos((string)($pv[0]['warning'] ?? ''), '大连') !== false, '“大连”被忽略并提示，订单导入：' . ($pv[0]['error'] ?? ''));

    echo "\n=== 导入失败规避测试全部通过 ===\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
} finally {
    foreach ($stored as $s) @unlink(ps_private_dir('imports') . '/' . basename($s) . '.php');
}
