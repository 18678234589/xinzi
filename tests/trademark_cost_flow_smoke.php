<?php
// 商标成本与多客服分单：成本中心商标成本导入自动带入 / 订单页快捷录入 / 补带 / 多位客服同一订单号分单。
// 导入流程含建表语句会提前提交，所以只允许在本地临时库运行。DB_HOST=127.0.0.1 DB_PORT=13399 php tests/trademark_cost_flow_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectTrademarkCost.php';
if ((string)DB_PORT !== '13399' || DB_HOST !== '127.0.0.1') { fwrite(STDERR, "拒绝运行：本测试会提交数据，只能连本地临时库（DB_PORT=13399）\n"); exit(2); }

$pdo = db();
$tmp = null; $stored = [];
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$actorOf = function ($name) use ($pdo) { $q = $pdo->prepare("SELECT u.id,u.employee_id,u.role FROM project_users u JOIN employees e ON e.id=u.employee_id WHERE e.name=? AND u.is_active=1"); $q->execute([$name]); $u = $q->fetch(); return $u ? ['type' => 'employee', 'id' => (int)$u['id'], 'employee_id' => (int)$u['employee_id'], 'role' => $u['role']] : null; };
$upload = function ($actor, $csv) use ($pdo, &$stored) {
    $tmp = tempnam(sys_get_temp_dir(), 'tm') . '.csv'; file_put_contents($tmp, $csv);
    $name = ps_private_store('imports', $tmp, 'tm_cost_' . bin2hex(random_bytes(5)) . '.csv'); $stored[] = $name; @unlink($tmp);
    $pdo->prepare("INSERT INTO project_import_files (business_name,original_name,stored_name,file_size,uploaded_by_type,uploaded_by_id,employee_id) VALUES ('商标','测试.csv',?,?,'employee',?,?)")->execute([$name, strlen($csv), $actor['id'], $actor['employee_id']]);
    return (int)$pdo->lastInsertId();
};
$run = function ($actor, $post) { $_SESSION = array_merge($_SESSION ?? [], ['project_user_id' => $actor['id'], 'project_csrf' => 'test-csrf']); $_SERVER['SCRIPT_NAME'] = '/project/import.php'; $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = $post + ['csrf' => 'test-csrf']; $GLOBALS['error'] = ''; $GLOBALS['imported'] = 0; ob_start(); include __DIR__ . '/../project/import.php'; ob_end_clean(); $GLOBALS['imported'] = $imported ?? 0; $GLOBALS['error'] = $error ?? ''; };
$importAs = function ($actor, $csv) use ($upload, $run) {
    $fileId = $upload($actor, $csv);
    $run($actor, ['action' => 'repreview', 'business' => '商标', 'file_id' => $fileId, 'all_sheets' => 1]);
    $preview = $_SESSION['project_import_preview'] ?? [];
    $run($actor, ['action' => 'commit', 'business' => '商标']);
    return [$preview, $GLOBALS['imported'], $GLOBALS['error'], $fileId];
};
$ordersOf = function ($no) use ($pdo) { $q = $pdo->prepare('SELECT id,order_no,contract_amount,project_type FROM project_orders WHERE order_no=? OR order_no LIKE ? ORDER BY id'); $q->execute([$no, $no . '~%']); return $q->fetchAll(); };
$costsOf = function ($orderId) use ($pdo) { $q = $pdo->prepare('SELECT item_name,quantity,unit_price,amount,review_status FROM project_costs WHERE order_id=? ORDER BY id'); $q->execute([$orderId]); return $q->fetchAll(); };
$tag = bin2hex(random_bytes(3));
$head = "日期,店铺,付款昵称,订单编号,售价,状态,商标名称,商标个数,网报类型\n";
try {
    echo "=== 一、成本中心录入商标成本（演练工具） ===\n";
    passthru('php ' . escapeshellarg(__DIR__ . '/../tools/seed_trademark_costs.php') . ' --commit', $rc);
    $check($rc === 0, '商标成本已写入成本中心');
    $templates = ptc_templates();
    $price = []; foreach ($templates as $t) $price[$t['name']] = (float)$t['price'];
    $check(($price['商标注册'] ?? 0) == 270 && ($price['商标转让'] ?? 0) == 450 && ($price['商标超期续展'] ?? 0) == 675 && ($price['商标许可备案'] ?? 0) == 135 && ($price['商标变更'] ?? -1) == 0 && ($price['商标注册多选项目加收'] ?? 0) == 27, '价格：注册 270、转让 450、超期续展 675、许可备案 135、变更 0、多选加收 27');
    passthru('php ' . escapeshellarg(__DIR__ . '/../tools/seed_trademark_costs.php') . ' --commit | tail -1', $rc2);
    $count = (int)$pdo->query("SELECT COUNT(*) FROM project_cost_templates WHERE business_scope='商标' AND is_active=1")->fetchColumn();
    $check($count === 14, '重复执行不会重复录入（共 14 项）');

    echo "=== 二、客服上传：按表格文字识别服务项目并带入成本 ===\n";
    $qin = $actorOf('秦婷婷'); $sun = $actorOf('孙荣姿'); $yu = $actorOf('于娜');
    $no = fn($i) => "TMX$tag-$i";
    $csv = $head
        . "2026.9.10,微信,甲," . $no(1) . ",680,已完成,图形 10 28类,2,网报\n"
        . "2026.9.10,微信,乙," . $no(2) . ",450,已完成,LOGO 续展,1,网报\n"
        . "2026.9.10,微信,丙," . $no(3) . ",800,已完成,XX 超期续展,1,网报\n"
        . "2026.9.10,微信,丁," . $no(4) . ",10,已完成,小额返款,,网报\n"
        . "2026.9.10,微信,戊," . $no(5) . ",300,已完成,公司名称变更,1,网报\n";
    [$preview, $imported, $error] = $importAs($qin, $csv);
    $check($error === '' && $imported === 5, '导入 5 单' . ($error ? '：' . $error : ''));
    $by = []; foreach ([1, 2, 3, 4, 5] as $i) { $o = $ordersOf($no($i))[0] ?? null; $by[$i] = $o ? $costsOf($o['id']) : null; }
    $check(count($by[1]) === 1 && (float)$by[1][0]['amount'] === 540.0 && $by[1][0]['review_status'] === 'approved', '无服务名 → 按商标注册：2 件 × 270 = ¥540，自动通过（不受 ¥500 阈值）');
    $check(count($by[2]) === 1 && (float)$by[2][0]['amount'] === 450.0 && mb_strpos($by[2][0]['item_name'], '商标续展') !== false, '“续展” → 商标续展 ¥450');
    $check(count($by[3]) === 1 && (float)$by[3][0]['amount'] === 675.0 && mb_strpos($by[3][0]['item_name'], '超期续展') !== false, '“超期续展”取最长匹配 → ¥675，而不是续展 ¥450');
    $check($by[4] === [], '没有商标个数的小额单不带入成本');
    $check(count($by[5]) === 1 && (float)$by[5][0]['amount'] === 0.0, '“变更” → ¥0');

    echo "=== 三、多位客服同一订单号（客户一次付款）：各记各的金额与成本 ===\n";
    $parent = $ordersOf($no(1))[0];
    $csvSun = $head . "2026.9.10,微信,甲," . $no(1) . ",200,已完成,商标 A 9类,1,网报\n";
    [$pv, $imp, $err] = $importAs($sun, $csvSun);
    $check($err === '' && $imp === 1 && !empty($pv[0]['base_valid']), '孙荣姿同号上传不再被拦：' . ($pv[0]['error'] ?? $err));
    $check(mb_strpos((string)($pv[0]['warning'] ?? ''), '= ¥880.00') !== false, '预览提示合计 ¥880.00：' . ($pv[0]['warning'] ?? ''));
    $csvYu = $head . "2026.9.10,微信,甲," . $no(1) . ",100,已完成,商标 B 35类,1,网报\n";
    [$pv, $imp, $err] = $importAs($yu, $csvYu);
    $check($err === '' && $imp === 1 && !empty($pv[0]['base_valid']), '于娜（第三位客服）同号上传也不被拦：' . ($pv[0]['error'] ?? $err));
    $rows = $ordersOf($no(1));
    $check(count($rows) === 3, '同一订单号共 1 张原单 + 2 张分单');
    $check(!!array_filter($rows, fn($r) => $r['order_no'] === $no(1) . '~商标#10' && (float)$r['contract_amount'] === 200.0) && !!array_filter($rows, fn($r) => $r['order_no'] === $no(1) . '~商标#11' && (float)$r['contract_amount'] === 100.0), '孙荣姿分单 ¥200、于娜分单 ¥100');
    $check((float)$rows[0]['contract_amount'] === 680.0, '秦婷婷原单 ¥680 不变');
    foreach ($rows as $r) if ($r['id'] != $parent['id']) $check(count($costsOf($r['id'])) === 1 && (float)$costsOf($r['id'])[0]['amount'] === 270.0, "分单 {$r['order_no']} 带入自己的成本 1 件 × 270");
    [$pv, $imp, $err] = $importAs($sun, $csvSun);
    $check(($pv[0]['status'] ?? '') === '补充已有订单' && count($ordersOf($no(1))) === 3, '孙荣姿重复上传不重复建单');

    echo "=== 四、订单页快捷录入（客服无需自定义成本） ===\n";
    $oid = (int)$parent['id'];
    $pdo->beginTransaction();
    ptc_user_add($oid, (int)$pdo->query("SELECT id FROM project_cost_templates WHERE name='商标转让' AND is_active=1")->fetchColumn(), '2', $qin);
    $c = $costsOf($oid);
    $check(count(array_filter($c, fn($x) => $x['review_status'] === 'approved')) === 1 && (float)array_values(array_filter($c, fn($x) => $x['review_status'] === 'approved'))[0]['amount'] === 900.0, '改选“商标转让 × 2”= ¥900，原服务成本作废');
    ptc_user_add($oid, (int)$pdo->query("SELECT id FROM project_cost_templates WHERE name='商标注册多选项目加收' AND is_active=1")->fetchColumn(), '3', $qin);
    ptc_user_add($oid, (int)$pdo->query("SELECT id FROM project_cost_templates WHERE name='国际商标成本' AND is_active=1")->fetchColumn(), '1800.5', $qin);
    $c = $costsOf($oid);
    $byName = []; foreach ($c as $x) if ($x['review_status'] !== 'rejected') $byName[$x['item_name']] = $x;
    $check(isset($byName['商标注册多选项目加收 · 每多选 1 个项目']) && (float)$byName['商标注册多选项目加收 · 每多选 1 个项目']['amount'] === 81.0 && $byName['商标注册多选项目加收 · 每多选 1 个项目']['review_status'] === 'approved', '多选项目加收 3 × 27 = ¥81，自动通过');
    $intl = array_values(array_filter($c, fn($x) => mb_strpos($x['item_name'], '国际商标') !== false))[0] ?? null;
    $check($intl && (float)$intl['amount'] === 1800.5 && $intl['review_status'] === 'pending', '国际商标按实际金额 ¥1800.5，进财务审核');
    foreach (['0', '-1', 'abc'] as $bad) { try { ptc_user_add($oid, (int)$pdo->query("SELECT id FROM project_cost_templates WHERE name='商标转让' AND is_active=1")->fetchColumn(), $bad, $qin); $check(false, "数量 $bad 应被拒绝"); } catch (RuntimeException $e) { } }
    $check(true, '数量为 0 / 负数 / 非数字被拒绝');
    $other = (int)$pdo->query("SELECT id FROM project_cost_templates WHERE business_scope<>'商标' AND is_active=1 LIMIT 1")->fetchColumn();
    if ($other) { try { ptc_user_add($oid, $other, '1', $qin); $check(false, '非商标成本应被拒绝'); } catch (RuntimeException $e) { $check(true, '非商标成本项目被拒绝'); } }
    $pdo->rollBack();

    echo "=== 五、补带：只补没有成本的未审核订单 ===\n";
    $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,order_date,delivery_status,note) VALUES (?,'补带客户','商标','普通订单','微信',450,'2026-09-11','unfinished','商标补证')")->execute(["BF$tag"]);
    $bf = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO project_order_details (order_id,business_name,details_json) VALUES (?,'商标',?)")->execute([$bf, json_encode(['trademark_name' => '补证 X', 'trademark_count' => '1', 'service_type' => ''], JSON_UNESCAPED_UNICODE)]);
    $done = ptc_backfill(['type' => 'system', 'id' => 0, 'role' => 'finance']);
    $check($done['orders'] >= 1 && count($costsOf($bf)) === 1 && (float)$costsOf($bf)[0]['amount'] === 450.0, '补带：备注“商标补证” → ¥450');
    $again = ptc_backfill(['type' => 'system', 'id' => 0, 'role' => 'finance']);
    $check($again['orders'] === 0, '再次补带不重复入账');
    $check(count($costsOf((int)$ordersOf($no(2))[0]['id'])) === 1, '已有成本的订单不被重复补带');

    echo "\n=== 商标成本与多客服分单测试全部通过 ===\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
} finally {
    foreach ($stored as $s) @unlink(ps_private_dir('imports') . '/' . basename($s) . '.php');
}
