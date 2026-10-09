<?php
// 同一笔订单的加入与价格裁决：同一笔销售只记一次（跨业务金额相同并入原单）、多位客服共同接单、预览页在线补接单技术、部门代录默认本人、对公收款、店铺流水价裁决售价。
// 导入流程含建表语句会提前提交，所以只允许在本地临时库运行。DB_HOST=127.0.0.1 DB_PORT=13399 php tests/import_join_smoke.php
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
$previewOf = function ($actor, $business, $csv, $extra = []) use ($upload, $run) {
    $fileId = $upload($actor, $business, $csv);
    $run($actor, ['action' => 'repreview', 'business' => $business, 'file_id' => $fileId, 'all_sheets' => 1] + $extra);
    return [$fileId, $_SESSION['project_import_preview'] ?? [], $GLOBALS['error']];
};
$participants = function ($orderNo) use ($pdo) {
    $q = $pdo->prepare('SELECT e.name,p.commission_group g,p.role_name r,p.group_weight w FROM project_participants p JOIN project_orders o ON o.id=p.order_id JOIN employees e ON e.id=p.employee_id WHERE o.order_no=? ORDER BY p.id');
    $q->execute([$orderNo]);
    return $q->fetchAll();
};
$stateKey = '状态(填已完成/未完成)';

try {
    $liu = $actorOf('刘淑萍'); $xu = $actorOf('徐春'); $han = $actorOf('韩菲菲'); $cao = $actorOf('曹双双'); $fang = $actorOf('房烁'); $sun = $actorOf('孙妍'); $qin = $actorOf('秦婷婷');
    $check($liu && $xu && $han && $cao && $fang && $sun && $qin, '相关账号存在');

    echo "=== 一、同一笔销售只记一次：软文代写原单 + 微信代写同号同金额 → 加入原单，不另建分单 ===\n";
    $n1 = "33180$tag" . '11111';
    $liuRow = function ($no, $amt, $date) use ($stateKey) { return ['日期' => $date, '店铺' => '美呀美', '付款昵称' => 'nick' . substr($no, -5), '订单编号' => $no, '售价' => $amt, $stateKey => '已完成', '客服' => '刘淑萍', '写手编号' => 'W1']; };
    $hanRow = function ($no, $amt, $date) use ($stateKey) { return ['日期' => $date, '店铺' => '美呀美', '付款昵称' => 'nick' . substr($no, -5), '订单编号' => $no, '售价' => $amt, $stateKey => '已完成', '项目执行' => '韩菲菲', '写手编号' => 'W1']; };
    [$pv, $imp, $err] = $importAs($liu, '软文代写', $csvOf('软文代写', [$liuRow($n1, '300', '2026.9.5')]));
    $check($imp === 1, '软文代写原单建立：' . ($pv[0]['error'] ?? $err));
    [$pv, $imp, $err] = $importAs($han, '微信代写', $csvOf('微信代写', [$hanRow($n1, '300', '2026.9.5')]));
    $check(!empty($pv[0]['base_valid']) && $imp === 1 && !empty($pv[0]['join_parent']), '同金额的微信代写行走“加入原单”：' . ($pv[0]['error'] ?? $err));
    $check(count($orders("$n1%")) === 1, '没有另建分单子单');
    $ppl = $participants($n1);
    $names = array_column($ppl, 'name');
    $check(in_array('刘淑萍', $names, true) && in_array('韩菲菲', $names, true), '原单参与人：刘淑萍 + 韩菲菲（' . json_encode($ppl, JSON_UNESCAPED_UNICODE) . '）');
    foreach ($ppl as $p) if ($p['name'] === '韩菲菲') $check($p['g'] === 'technical' && $p['r'] === '对接编辑', '韩菲菲记为对接编辑（技术组）：' . $p['r']);
    [$pv, $imp] = $importAs($han, '微信代写', $csvOf('微信代写', [$hanRow($n1, '300', '2026.9.5')]));
    $check(count($orders("$n1%")) === 1 && count($participants($n1)) === count($ppl), '重传同一行：不重复加入、不重复建单');

    echo "=== 二、金额不同仍按分单处理 ===\n";
    $n2 = "33180$tag" . '22222';
    $importAs($liu, '软文代写', $csvOf('软文代写', [$liuRow($n2, '300', '2026.9.6')]));
    [$pv, $imp, $err] = $importAs($han, '微信代写', $csvOf('微信代写', [$hanRow($n2, '120', '2026.9.6')]));
    $check($imp === 1 && count($orders("$n2%")) === 2 && empty($pv[0]['join_parent']), '金额不同（300 对 120）：另建分单子单：' . ($pv[0]['error'] ?? $err));

    echo "=== 三、同业务多位客服共同接单（王宁 / 张欣 类） ===\n";
    $n3 = "33180$tag" . '33333';
    $importAs($liu, '软文代写', $csvOf('软文代写', [$liuRow($n3, '300', '2026.9.7')]));
    $xuRow = $liuRow($n3, '300', '2026.9.7'); $xuRow['客服'] = '徐春';
    [$pv, $imp, $err] = $importAs($xu, '软文代写', $csvOf('软文代写', [$xuRow]));
    $check(!empty($pv[0]['base_valid']) && $imp === 1, '第二位客服可导入同一订单：' . ($pv[0]['error'] ?? $err));
    $cs = array_values(array_filter($participants($n3), function ($p) { return $p['g'] === 'customer_service'; }));
    $check(count($cs) === 2 && abs($cs[0]['w'] - 0.5) < 0.000001 && abs($cs[1]['w'] - 0.5) < 0.000001, '两位客服各占一半权重：' . json_encode($cs, JSON_UNESCAPED_UNICODE));
    $check(count($orders("$n3%")) === 1, '仍是同一张订单');

    echo "=== 四、客服小程序单没写接单技术：预览页直接选技术，不改表格 ===\n";
    $n4 = "33180$tag" . '44444';
    $rowCao = ['日期' => '2026.9.8', '店铺' => '美呀美', '付款昵称' => 'nickD', '订单编号' => $n4, '售价' => '200', $stateKey => '已完成', '客服' => '曹双双'];
    [$fileId, $pv, $err] = $previewOf($cao, '小程序开发', $csvOf('小程序开发', [$rowCao]));
    $check(empty($pv[0]['base_valid']) && !empty($pv[0]['need_technical']), '没写技术：被拦且标记可在线补录：' . ($pv[0]['error'] ?? $err));
    $techId = array_key_first(poj_technician_choices('小程序开发'));
    $check($techId > 0, '有可选的接单技术');
    $run($cao, ['action' => 'repair_preview', 'business' => '小程序开发', 'fix_technical' => [$pv[0]['line'] => $techId]]);
    $pv2 = $_SESSION['project_import_preview'] ?? [];
    $check(!empty($pv2[0]['base_valid']) && count($pv2[0]['people']['technical']) === 1, '选好技术后重新核对通过：' . ($pv2[0]['error'] ?? ''));
    $run($cao, ['action' => 'commit', 'business' => '小程序开发']);
    $check(count($orders($n4)) === 1 && count(array_filter($participants($n4), function ($p) { return $p['g'] === 'technical'; })) === 1, '订单已导入且记了所选技术');

    echo "=== 五、部门代录没选默认参与人：默认记上传人本人 ===\n";
    $n5 = "33180$tag" . '55555';
    [$fileId, $pv, $err] = $previewOf($fang, '网站修改', $csvOf('网站修改', [['店铺' => '美呀美', '日期' => '2026.9.9', '订单编号' => $n5, '价格' => '100', '状态' => '已完成']]), ['scope' => 'department']);
    $check(!empty($pv[0]['base_valid']) && array_column($pv[0]['people']['customer_service'], 'name') === ['房烁'], '没选参与人也记为房烁：' . ($pv[0]['error'] ?? $err));

    echo "=== 六、对公收款：订单号写“对公”，只需对公交易号 ===\n";
    $_SESSION = []; // 上一节的部门代录会话不带到个人上传
    $n6 = 'DG' . strtoupper($tag) . '66666';
    $base6 = ['日期' => '2026.9.10', '店铺' => '美呀美', '付款昵称' => 'nickF', '售价' => '500', $stateKey => '已完成', '客服' => '宋倩倩', '模板技术' => '孙妍', '程序名称' => 'php'];
    [$pv, $imp, $err] = $importAs($sun, '网站模板', $csvOf('网站模板', [$base6 + ['订单编号' => '对公', '微信交易流水号' => $n6]]));
    $check($imp === 1 && strpos($pv[0]['order_no'], 'WX-') === 0 && $pv[0]['payment_reference'] === $n6 && !empty($pv[0]['public_transfer']), '“对公”+交易号列：导入，内部关联号 WX-…：' . ($pv[0]['error'] ?? $err));
    $n6b = 'DG' . strtoupper($tag) . '77777';
    [$pv, $imp, $err] = $importAs($sun, '网站模板', $csvOf('网站模板', [$base6 + ['订单编号' => '对公 ' . $n6b]]));
    $check($imp === 1 && $pv[0]['payment_reference'] === $n6b, '订单号格子写“对公 交易号”也识别：' . ($pv[0]['error'] ?? $err));
    $before = (int)$pdo->query("SELECT COUNT(*) FROM project_orders WHERE order_no LIKE 'WX-%'")->fetchColumn();
    [$pv, $imp, $err] = $importAs($sun, '网站模板', $csvOf('网站模板', [$base6 + ['订单编号' => '对公', '微信交易流水号' => $n6]]));
    $check((int)$pdo->query("SELECT COUNT(*) FROM project_orders WHERE order_no LIKE 'WX-%'")->fetchColumn() === $before, '重传不重复建单');

    echo "=== 七、售价不一致：用店铺流水价裁决 ===\n";
    $flow = $pdo->prepare("INSERT INTO orders (employee_id,order_scope,order_no,shop,order_amount,order_date,raw_data,is_deleted) VALUES (0,'department',?,'美呀美',?,'2026-09-09','{}',0)");
    $mk = function ($no, $amt) use ($pdo, $E) {
        $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,order_date,delivery_status,note) VALUES (?,'测试','商标','普通订单','美呀美',?,'2026-09-09','unfinished','测试')")->execute([$no, $amt]);
        $id = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO project_order_sources (order_id,payment_nickname,price_source) VALUES (?,'nickG','manual')")->execute([$id]);
        $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'technical','资料专员',1)")->execute([$id, $E('王庆美')]);
        return $id;
    };
    $pA = "33180$tag" . '88881'; $pB = "33180$tag" . '88882'; $pC = "33180$tag" . '88883';
    $idA = $mk($pA, 100); $idB = $mk($pB, 400); $idC = $mk($pC, 100);
    $flow->execute([$pA, 300]); $flow->execute([$pB, 400]);
    $head7 = "日期,店铺,付款昵称,订单编号,售价,状态,商标名称,商标个数\n";
    [$pv, $imp, $err] = $importAs($qin, '商标', $head7
        . "2026.9.9,美呀美,nickG,$pA,300,已完成,图 9类注册,1\n"
        . "2026.9.9,美呀美,nickG,$pB,450,已完成,图 9类注册,1\n"
        . "2026.9.9,美呀美,nickG,$pC,350,已完成,图 9类注册,1\n");
    $by = []; foreach ($pv as $r) $by[$r['order_no']] = $r;
    $check(!empty($by[$pA]['base_valid']) && ($by[$pA]['price_verdict'] ?? '') === 'adopt_sheet', '流水价 300 = 表格价：系统原价 100 将更正为 300');
    $check(!empty($by[$pB]['base_valid']) && ($by[$pB]['price_verdict'] ?? '') === 'keep_system', '流水价 400 = 系统价：沿用系统价，表格价 450 只提示');
    $check(empty($by[$pC]['base_valid']) && mb_strpos((string)$by[$pC]['error'], '售价') !== false, '没有流水可裁判：仍拦截交财务');
    $amt = function ($id) use ($pdo) { return (float)$pdo->query('SELECT contract_amount FROM project_orders WHERE id=' . (int)$id)->fetchColumn(); };
    $check($amt($idA) === 300.0 && $amt($idB) === 400.0 && $amt($idC) === 100.0, '落库金额：A 更正为 300，B 仍 400，C 仍 100');

    echo "\n=== 加入原单 / 价格裁决测试全部通过 ===\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
} finally {
    foreach ($stored as $s) @unlink(ps_private_dir('imports') . '/' . basename($s) . '.php');
}
