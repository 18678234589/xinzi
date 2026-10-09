<?php
// 网站：1) 两个部门同一订单号（网站售后先录、网站技术后录，技术表没有售价）后录的不再被拦；
//       2) 一个客户做多个网站、订单号相同、客服分多行记录：按稳定项目标识分别建单，重传和调序不重复计单。
// 导入流程含建表语句会提前提交，所以只允许在本地临时库运行。DB_HOST=127.0.0.1 DB_PORT=13399 php tests/website_split_import_smoke.php
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
    $name = ps_private_store('imports', $tmp, 'web_split_' . bin2hex(random_bytes(5)) . '.csv'); $stored[] = $name; @unlink($tmp);
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
    $zhang = $actorOf('张强'); $fang = $E('房烁'); $song = $actorOf('宋倩倩');
    $check($zhang && $fang && $song, '张强（网站技术）、房烁（网站售后）、宋倩倩（网站客服）账号存在');

    echo "=== 一、两个部门同一订单号：售后先录，网站技术后录（技术表没有售价） ===\n";
    $no = "33168$tag" . '7355027397';
    $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,order_date,delivery_status,note) VALUES (?,'测试客户','网站修改','','美呀美',100,'2026-09-13','unfinished','测试')")->execute([$no]);
    $parentId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'customer_service','客服',1)")->execute([$parentId, $fang]);
    $head = "日期,店铺,订单编号,程序名称,模板技术,域名使用（写是/否）\n";
    [$pv, $imp, $err] = $importAs($zhang, '网站模板', $head . "2026.9.13,美呀美,$no,jsp展示,张强,否\n");
    $check(!empty($pv[0]['base_valid']), '预览可导入：' . ($pv[0]['error'] ?? '') . ' ' . ($pv[0]['warning'] ?? ''));
    $check(($pv[0]['order_no'] ?? '') === "$no~网站模板", '后录入的技术按分单子单建单');
    $check($err === '' && $imp === 1, '导入 1 单' . ($err ? '：' . $err : ''));
    $rows = $orders("$no%");
    $check(count($rows) === 2 && (float)$rows[0]['contract_amount'] === 100.0 && $rows[0]['project_type'] === '网站修改', '售后原单 ¥100、业务不变');
    $check($rows[1]['project_type'] === '网站模板' && (float)$rows[1]['contract_amount'] === 0.0, '技术分单业务“网站模板”，售价留空待补（技术表没写售价）');
    $src = $pdo->prepare('SELECT price_source FROM project_order_sources WHERE order_id=?'); $src->execute([$rows[1]['id']]);
    $check($src->fetchColumn() === 'missing', '分单标记“售价待补”，客服 / 财务补填');
    [$pv2, $imp2] = $importAs($zhang, '网站模板', $head . "2026.9.13,美呀美,$no,jsp展示,张强,否\n");
    $check(count($orders("$no%")) === 2 && ($pv2[0]['status'] ?? '') === '补充已有订单', '技术重复上传不重复建单');

    echo "=== 二、先后顺序反过来：网站技术先录，售后后录 ===\n";
    $no2 = "33168$tag" . '9999999';
    [$pv, $imp, $err] = $importAs($zhang, '网站模板', $head . "2026.9.14,美呀美,$no2,jsp展示,张强,否\n");
    $check($err === '' && $imp === 1, '技术先录入 1 单');
    $liu = $actorOf('张宁');
    $fixHead = "日期,店铺,订单编号,价格,状态\n";
    [$pv, $imp, $err] = $importAs($liu, '网站修改', $fixHead . "2026.9.14,美呀美,$no2,100,已完成\n");
    $check(!empty($pv[0]['base_valid']) && $err === '' && $imp === 1, '售后后录入同号订单也能上传：' . ($pv[0]['error'] ?? $err));

    echo "=== 三、一个客户做多个网站：订单号相同、客服分多行记录 ===\n";
    $no3 = "33168$tag" . '50666';
    $no4 = "33168$tag" . '50668';
    $csHead = "日期,店铺,付款昵称,订单编号,程序名称,客服,模板技术,售价,状态,网站项目标识\n";
    $csv = $csHead
        . "2026.9.13,美呀美,qiangzi,$no3,jsp展示,宋倩倩,张强,350,已完成,site-a.example\n"
        . "2026.9.13,美呀美,qiangzi,$no3,jsp展示,宋倩倩,张强,350,已完成,site-b.example\n"
        . "2026.9.13,美呀美,qiangzi,$no4,jsp展示,宋倩倩,张强,350,已完成,site-c.example\n";
    [$pv, $imp, $err] = $importAs($song, '网站模板', $csv);
    $check($err === '' && $imp === 3, '3 行导入 3 单（同号两行不再合并成一单）' . ($err ? '：' . $err : '') . " imported=$imp");
    $rows = array_merge($orders("$no3%"), $orders("$no4%"));
    $check(count($rows) === 3, '库里 3 张订单：' . implode('、', array_column($rows, 'order_no')));
    $check(array_sum(array_column($rows, 'contract_amount')) == 1050.0 && count(array_filter($rows, fn($r) => (float)$r['contract_amount'] === 350.0)) === 3, '每张 ¥350，合计 ¥1050，不是合并后的 ¥700 + ¥350');
    $siteChildNo = psp_child_no($no3, 'site-b.example');
    $check(!!array_filter($rows, fn($r) => $r['order_no'] === $siteChildNo), '第二个网站使用项目标识生成稳定编号');
    $link = $pdo->prepare('SELECT COUNT(*) FROM project_order_splits s JOIN project_orders c ON c.id=s.child_order_id WHERE c.order_no=?'); $link->execute([$siteChildNo]);
    $check((int)$link->fetchColumn() === 1, '第 2 个网站与第 1 个订单关联，收款证据按同一个店铺订单号核对');
    [$pv, $imp, $err] = $importAs($song, '网站模板', $csv);
    $check(count($orders("$no3%")) === 2 && count($orders("$no4%")) === 1, '整张表重复上传不重复建单');
    $reordered = $csHead
        . "2026.9.13,美呀美,qiangzi,$no3,jsp展示,宋倩倩,张强,350,已完成,site-b.example\n"
        . "2026.9.13,美呀美,qiangzi,$no3,jsp展示,宋倩倩,张强,350,已完成,site-a.example\n";
    $importAs($song, '网站模板', $reordered);
    $check(count($orders("$no3%")) === 2, '行顺序调换后仍是相同两张网站项目订单');

    echo "=== 四、备案人员上传的订单号已是网站模板订单（备案是在网站订单上追加的服务） ===\n";
    $filer = $actorOf('刘媛媛');
    $no5 = "51277$tag" . '61970300';
    $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,order_date,delivery_status,note) VALUES (?,'测试客户','网站模板','','美呀美',250,'2026-08-28','unfinished','测试')")->execute([$no5]);
    $filerHead = "日期,订单编号,订单类型,项目执行\n";
    [$pv, $imp, $err] = $importAs($filer, '备案-提成', $filerHead . "2026.8.28,$no5,备案,刘媛媛\n");
    $check(!empty($pv[0]['base_valid']) && ($pv[0]['error'] ?? '') === '', '备案上传同号网站订单不再提示“已属于其他业务”：' . ($pv[0]['error'] ?? ''));
    $rows = $orders("$no5%");
    $check($err === '' && $imp === 1 && count($rows) === 2 && $rows[1]['project_type'] === '备案-提成', '备案另记一张“备案-提成”订单，网站订单不变');

    echo "=== 五、原始表格在线编辑后直接核对并导入 ===\n";
    require_once __DIR__ . '/../includes/ProjectSheetEdit.php';
    $editedNo = '63990' . $tag . '1234567890';
    $fileId = $upload($zhang, '网站模板', "日期,店铺,订单编号,程序名称,模板技术,域名使用（写是/否）,备注\n2026-09-01,美呀美,原订单号,jsp展示,张强,否,原备注\n");
    $file = pse_file($fileId, $zhang); $sheet = (string)array_keys(ps_import_file_sheets($file))[0];
    pse_load($fileId, $sheet, $zhang);
    pse_save($fileId, $sheet, [['row' => 1, 'col' => 0, 'v' => '2026-10-08'], ['row' => 1, 'col' => 2, 'v' => $editedNo], ['row' => 1, 'col' => 6, 'v' => '在线更改的备注']], $zhang);
    $run($zhang, ['action' => 'repreview', 'business' => '网站模板', 'file_id' => $fileId, 'all_sheets' => 1]);
    $pv = $_SESSION['project_import_preview'] ?? [];
    $check(!empty($pv[0]['base_valid']) && $pv[0]['order_no'] === $editedNo && $pv[0]['order_date'] === '2026-10-08', '核对页面使用在线修改后的订单号和日期');
    // 核对后再修改必须阻止旧预览提交；重新核对后才能导入。
    pse_save($fileId, $sheet, [['row' => 1, 'col' => 0, 'v' => '2026-10-09']], $zhang);
    $run($zhang, ['action' => 'commit', 'business' => '网站模板']);
    $check(mb_strpos($GLOBALS['error'], '核对后又有修改') !== false && !$orders($editedNo), '核对后的新修改不会被旧预览覆盖');
    $run($zhang, ['action' => 'repreview', 'business' => '网站模板', 'file_id' => $fileId, 'all_sheets' => 1]);
    $run($zhang, ['action' => 'commit', 'business' => '网站模板']);
    $q = $pdo->prepare('SELECT order_date FROM project_orders WHERE order_no=?'); $q->execute([$editedNo]);
    $check($GLOBALS['error'] === '' && $q->fetchColumn() === '2026-10-09', '用户确认后正确写入订单，原附件不需重传');

    echo "\n=== 网站分单与多网站同号测试全部通过 ===\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
} finally {
    foreach ($stored as $s) @unlink(ps_private_dir('imports') . '/' . basename($s) . '.php');
}
