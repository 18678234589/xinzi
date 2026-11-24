<?php
// 小程序外包技术：小程序客服（如王宁）手动录入订单时，没有合适的本公司技术可选“外包技术”并填外包费用，不再卡在“请指定接收此单的技术”。
// 走真实的 project/index.php 录入流程。只允许本地隔离库：DB_HOST=127.0.0.1 DB_PORT=13399 DB_NAME=xinzi_test php -d extension=zip tests/miniapp_outsource_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/require_isolated_database.php';
$pdo = db(); require_isolated_test_database($pdo);
if (DB_HOST !== '127.0.0.1' || (string)DB_PORT !== '13399') throw new RuntimeException('只允许本地隔离库');
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
if (($argv[1] ?? '') === '--child') {
    $in = json_decode(file_get_contents($argv[2]), true);
    $_SESSION = ['project_user_id' => (int)$in['user'], 'project_csrf' => 'test-csrf']; $_SERVER['SCRIPT_NAME'] = '/project/index.php'; $_SERVER['REQUEST_METHOD'] = 'POST'; $_GET = []; $_POST = $in['post'];
    ob_start(); include __DIR__ . '/../project/index.php'; $html = ob_get_clean();
    echo 'CHILD-ERROR:' . json_encode(['error' => isset($error) ? $error : ''], JSON_UNESCAPED_UNICODE) . "\n"; exit(0);
}
ps_ensure_outsource_templates();
$tag = bin2hex(random_bytes(3));
$wang = $pdo->query("SELECT u.id,u.employee_id,u.role FROM project_users u JOIN employees e ON e.id=u.employee_id WHERE e.name='王宁' AND u.is_active=1")->fetch();
$check($wang && $wang['role'] === 'customer_service', '王宁（小程序客服）账号存在');
$tpl = $pdo->query("SELECT * FROM project_cost_templates WHERE category='outsourcing' AND business_scope='小程序开发' AND is_active=1 ORDER BY id DESC LIMIT 1")->fetch();
$check($tpl && ps_outsource_is_actual($tpl), '成本中心有“小程序外包技术”（按实际金额）：' . ($tpl['name'] ?? ''));
$tpl2 = null; ps_ensure_outsource_templates();
$check((int)$pdo->query("SELECT COUNT(*) FROM project_cost_templates WHERE category='outsourcing' AND business_scope='小程序开发' AND name='小程序外包技术'")->fetchColumn() === 1, '重复补种不会重复添加');
$tech = (int)$pdo->query("SELECT e.id FROM employees e JOIN project_users u ON u.employee_id=e.id WHERE e.name='石凯新' AND u.is_active=1")->fetchColumn();
$created = [];
$post = function (array $over) use ($wang, $tag, &$created) {
    static $n = 0; $n++;
    $post = $over + ['csrf' => 'test-csrf', 'project_type' => '小程序开发', 'order_no' => "OUT$tag-$n", 'order_date' => date('Y-m-d'), 'contract_amount' => '2000', 'shop' => '', 'customer_name' => '测试客户', 'payment_nickname' => 'out' . $tag, 'customer_service_id' => (string)$wang['employee_id'], 'frontend_id' => '0', 'backend_id' => '0', 'order_kind' => '定制', 'free_shop' => '1', 'info_later' => '1'];
    $tmp = tempnam(sys_get_temp_dir(), 'out'); file_put_contents($tmp, json_encode(['user' => (int)$wang['id'], 'post' => $post]));
    $cmd = escapeshellarg(PHP_BINARY) . ' -d extension=zip ' . escapeshellarg(__FILE__) . ' --child ' . escapeshellarg($tmp) . ' 2>&1';
    $out = (string)shell_exec($cmd); @unlink($tmp);
    $created[] = $post['order_no'];
    $err = preg_match('/CHILD-ERROR:(\{.*\})/', $out, $m) ? (json_decode($m[1], true)['error'] ?? '') : '';
    return [$err, $out, $post['order_no']];
};
try {
    echo "=== 一、表单：制作技术下拉里有“外包技术”，外包行有“外包费用” ===\n";
    $_SESSION = ['project_user_id' => (int)$wang['id']]; $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = []; $_GET = ['business' => '小程序开发']; $_SERVER['SCRIPT_NAME'] = '/project/index.php';
    ob_start(); include __DIR__ . '/../project/index.php'; $html = ob_get_clean();
    $check(strpos($html, 'value="outsource"') !== false && strpos($html, '外包技术（外部开发，不走本公司技术）') !== false && strpos($html, 'intakeOutsourceAmount') !== false && strpos($html, 'data-actual="1"') !== false, '制作技术下拉有“外包技术”，外包行有“外包费用”输入');

    echo "=== 二、不选技术、不选外包：报错并告诉她怎么办 ===\n";
    [$err] = $post([]);
    $check(mb_strpos($err, '请指定接收此单的技术') !== false && mb_strpos($err, '外包技术') !== false, '报错说明了出路（选本公司技术，或选“外包技术”并填费用）：' . $err);

    echo "=== 三、选外包技术 + 外包费用：建单成功，成本入账，没有本公司技术 ===\n";
    [$err, , $no] = $post(['frontend_id' => 'outsource', 'outsource_template_id' => (string)$tpl['id'], 'outsource_amount' => '800']);
    $o = $pdo->prepare('SELECT id,contract_amount FROM project_orders WHERE order_no=?'); $o->execute([$no]); $order = $o->fetch();
    $check($order, '订单建好了：' . ($err ?: 'ok'));
    $cost = $pdo->prepare("SELECT SUM(amount),MIN(review_status),COUNT(*) FROM project_costs WHERE order_id=? AND category='outsourcing'"); $cost->execute([$order['id']]); [$sum, $status, $cnt] = $cost->fetch(PDO::FETCH_NUM);
    $check((float)$sum === 800.0 && (int)$cnt === 1 && $status === 'pending', '外包成本 ¥800 入账，待财务核对：' . json_encode([$sum, $status, $cnt]));
    $tc = $pdo->prepare("SELECT COUNT(*) FROM project_participants WHERE order_id=? AND commission_group='technical'"); $tc->execute([$order['id']]);
    $cc = $pdo->prepare("SELECT COUNT(*) FROM project_participants WHERE order_id=? AND commission_group='customer_service' AND employee_id=?"); $cc->execute([$order['id'], $wang['employee_id']]);
    $check((int)$tc->fetchColumn() === 0 && (int)$cc->fetchColumn() === 1, '没有本公司技术，王宁是这单的客服');

    echo "=== 四、选了外包但没填费用：提示填费用 ===\n";
    [$err] = $post(['frontend_id' => 'outsource', 'outsource_template_id' => (string)$tpl['id'], 'outsource_amount' => '']);
    $check(mb_strpos($err, '实际费用') !== false, '没填外包费用被拒：' . $err);

    echo "=== 五、选本公司技术：照常建单，不带外包成本 ===\n";
    if ($tech) { [$err, , $no2] = $post(['frontend_id' => (string)$tech]); $o->execute([$no2]); $o2 = $o->fetch(); $check((bool)$o2, '选本公司技术照常建单：' . ($err ?: 'ok')); }
    echo "=== 六、上传表格：客服的小程序行没写技术，预览里选“外包技术”并填费用 ===\n";
    $stored = [];
    $actor = ['type' => 'employee', 'id' => (int)$wang['id'], 'employee_id' => (int)$wang['employee_id'], 'role' => 'customer_service'];
    $csvNo = "OUT$tag-CSV";
    $head = ps_business_import_headers('小程序开发');
    $row = array_fill_keys($head, ''); $row['日期'] = date('Y-m-d'); $row['店铺'] = '美呀美'; $row['付款昵称'] = 'imp' . $tag; $row['订单编号'] = $csvNo; $row['售价'] = '1500'; $row['状态(填已完成/未完成)'] = '未完成'; $row['客服'] = '王宁';
    foreach ($head as $h) if (mb_strpos($h, '订单类型') !== false) $row[$h] = '定制开发（按客户需求开发）';
    foreach ($head as $h) if (mb_strpos($h, '业务种类') !== false) $row[$h] = '永久';
    $csv = implode(',', $head) . "\n" . implode(',', array_map(function ($h) use ($row) { return $row[$h]; }, $head)) . "\n";
    $tmp = tempnam(sys_get_temp_dir(), 'ws') . '.csv'; file_put_contents($tmp, $csv);
    $name = ps_private_store('imports', $tmp, 'out_' . bin2hex(random_bytes(5)) . '.csv'); $stored[] = $name; @unlink($tmp);
    $pdo->prepare("INSERT INTO project_import_files (business_name,original_name,stored_name,file_size,uploaded_by_type,uploaded_by_id,employee_id) VALUES ('小程序开发','测试.csv',?,?,'employee',?,?)")->execute([$name, strlen($csv), $actor['id'], $actor['employee_id']]);
    $fileId = (int)$pdo->lastInsertId();
    $run = function ($post) use ($actor) { $_SESSION = array_merge($_SESSION ?? [], ['project_user_id' => $actor['id'], 'project_csrf' => 'test-csrf']); $_SERVER['SCRIPT_NAME'] = '/project/import.php'; $_SERVER['REQUEST_METHOD'] = 'POST'; $_GET = []; $_FILES = []; $_POST = $post + ['csrf' => 'test-csrf']; $GLOBALS['error'] = ''; $GLOBALS['imported'] = 0; ob_start(); include __DIR__ . '/../project/import.php'; ob_end_clean(); };
    $run(['action' => 'repreview', 'business' => '小程序开发', 'file_id' => $fileId, 'all_sheets' => 1]);
    $pv = $_SESSION['project_import_preview'] ?? [];
    $check(count($pv) === 1 && !empty($pv[0]['need_technical']) && mb_strpos((string)$pv[0]['error'], '接单技术') !== false, '没写技术：预览要求选接单技术：' . ($pv[0]['error'] ?? ''));
    $run(['action' => 'repair_preview', 'business' => '小程序开发', 'fix_technical' => [$pv[0]['line'] => 'outsource'], 'fix_outsource_amount' => [$pv[0]['line'] => '']]);
    $pv = $_SESSION['project_import_preview'] ?? [];
    $check(empty($pv[0]['base_valid']) && mb_strpos((string)$pv[0]['error'], '实际费用') !== false, '选了外包技术但没填费用：提示填费用：' . ($pv[0]['error'] ?? ''));
    $run(['action' => 'repair_preview', 'business' => '小程序开发', 'fix_technical' => [$pv[0]['line'] => 'outsource'], 'fix_outsource_amount' => [$pv[0]['line'] => '650']]);
    $pv = $_SESSION['project_import_preview'] ?? [];
    $check(!empty($pv[0]['base_valid']) && !empty($pv[0]['outsource']) && mb_strpos((string)$pv[0]['warning'], '外包技术') !== false, '选“外包技术”+ 费用 650：这一行可导入：' . ($pv[0]['warning'] ?? $pv[0]['error'] ?? ''));
    $run(['action' => 'commit', 'business' => '小程序开发']);
    $o = $pdo->prepare('SELECT id FROM project_orders WHERE order_no LIKE ?'); $o->execute([$csvNo . '%']); $oid = (int)$o->fetchColumn(); $created[] = $csvNo;
    $c = $pdo->prepare("SELECT SUM(amount),MIN(review_status) FROM project_costs WHERE order_id=? AND category='outsourcing'"); $c->execute([$oid]); [$sum, $st] = $c->fetch(PDO::FETCH_NUM);
    $tcount = $pdo->query("SELECT COUNT(*) FROM project_participants WHERE order_id=$oid AND commission_group='technical'")->fetchColumn();
    $check($oid && (float)$sum === 650.0 && $st === 'pending' && (int)$tcount === 0, '订单导入：外包成本 ¥650 入账待财务核对，没有本公司技术：' . json_encode([$oid, $sum, $st, $tcount]));
    foreach ($stored as $f) @unlink(ps_private_dir('imports') . '/' . basename($f) . '.php');
    $pdo->exec("DELETE FROM project_import_files WHERE id=$fileId");
    echo "=== 七、技术上传的表里写了没开通本业务账号的客服：预览提示确认姓名 ===\n";
    $shi = $pdo->query("SELECT u.id,u.employee_id FROM project_users u JOIN employees e ON e.id=u.employee_id WHERE e.name='石凯新' AND u.is_active=1")->fetch();
    $stranger = (int)$pdo->query("SELECT e.id FROM employees e WHERE e.name='宋倩倩' LIMIT 1")->fetchColumn();
    if ($shi && $stranger && !ps_active_employee_for_business($stranger, 'customer_service', '小程序开发')) {
        $actor2 = ['type' => 'employee', 'id' => (int)$shi['id'], 'employee_id' => (int)$shi['employee_id'], 'role' => 'technical'];
        $row2 = $row; $row2['订单编号'] = "OUT$tag-W"; $row2['客服'] = '宋倩倩'; foreach ($head as $h) if ($h === '制作技术') $row2[$h] = '石凯新';
        $csv2 = implode(',', $head) . "\n" . implode(',', array_map(function ($h) use ($row2) { return $row2[$h]; }, $head)) . "\n";
        $tmp = tempnam(sys_get_temp_dir(), 'ws') . '.csv'; file_put_contents($tmp, $csv2);
        $name2 = ps_private_store('imports', $tmp, 'out_' . bin2hex(random_bytes(5)) . '.csv'); @unlink($tmp);
        $pdo->prepare("INSERT INTO project_import_files (business_name,original_name,stored_name,file_size,uploaded_by_type,uploaded_by_id,employee_id) VALUES ('小程序开发','测试.csv',?,?,'employee',?,?)")->execute([$name2, strlen($csv2), $actor2['id'], $actor2['employee_id']]);
        $file2 = (int)$pdo->lastInsertId();
        $_SESSION = ['project_user_id' => $actor2['id'], 'project_csrf' => 'test-csrf']; $_SERVER['SCRIPT_NAME'] = '/project/import.php'; $_SERVER['REQUEST_METHOD'] = 'POST'; $_GET = []; $_FILES = []; $_POST = ['action' => 'repreview', 'business' => '小程序开发', 'file_id' => $file2, 'all_sheets' => 1, 'csrf' => 'test-csrf'];
        ob_start(); include __DIR__ . '/../project/import.php'; ob_end_clean();
        $pv2 = $_SESSION['project_import_preview'] ?? [];
        $check(!empty($pv2) && mb_strpos((string)($pv2[0]['warning'] ?? ''), '宋倩倩”没有开通“小程序开发”业务账号') !== false, '提示：宋倩倩没有开通小程序开发账号，这单会出现在她的项目订单里，请确认没写错：' . mb_substr((string)($pv2[0]['warning'] ?? $pv2[0]['error'] ?? ''), 0, 120));
        @unlink(ps_private_dir('imports') . '/' . basename($name2) . '.php'); $pdo->exec("DELETE FROM project_import_files WHERE id=$file2");
    } else echo "  （测试库里宋倩倩已开通小程序开发账号，跳过）\n";
    echo "\n=== 小程序外包技术测试全部通过，测试数据已清理 ===\n";
} finally {
    require_once __DIR__ . '/../includes/ProjectOrderDelete.php';
    foreach ($created as $no) { $q = $pdo->prepare('SELECT id FROM project_orders WHERE order_no=?'); $q->execute([$no]); $id = (int)$q->fetchColumn(); if ($id) { try { pod_delete_rows($id); } catch (Throwable $e) {} } }
    $pdo->exec("DELETE FROM project_audit_logs WHERE entity_type='order' AND details_json LIKE '%OUT$tag%'");
}
