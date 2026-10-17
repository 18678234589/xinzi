<?php
// 回购订单（督导吴宁）：按她的原表格式上传——汇总行不当订单、订单号加 HG- 前缀不碰已有订单、每单 30 元提成、重传不重复。
// 默认用合成 CSV；设 BUYBACK_XLSX=<她的 9 月吴宁.xlsx 路径> 并启用 zip 扩展（php -d extension=zip）时改用真实 xlsx 跑同样的断言。
// 只允许本地临时库：DB_HOST=127.0.0.1 DB_PORT=13399 php tests/import_buyback_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
if ((string)DB_PORT !== '13399' || DB_HOST !== '127.0.0.1') { fwrite(STDERR, "拒绝运行：本测试会提交数据，只能连本地临时库（DB_PORT=13399）\n"); exit(2); }

$pdo = db();
require_once __DIR__ . '/require_isolated_database.php';
require_isolated_test_database($pdo);
$stored = [];
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$E = function ($name) use ($pdo) { $q = $pdo->prepare('SELECT id FROM employees WHERE name=? ORDER BY id LIMIT 1'); $q->execute([$name]); return (int)$q->fetchColumn(); };
$actorOf = function ($name) use ($pdo) { $q = $pdo->prepare("SELECT u.id,u.employee_id,u.role FROM project_users u JOIN employees e ON e.id=u.employee_id WHERE e.name=? AND u.is_active=1"); $q->execute([$name]); $u = $q->fetch(); return $u ? ['type' => 'employee', 'id' => (int)$u['id'], 'employee_id' => (int)$u['employee_id'], 'role' => $u['role']] : null; };
$upload = function ($actor, $business, $csv) use ($pdo, &$stored) {
    $tmp = tempnam(sys_get_temp_dir(), 'ws') . ($GLOBALS['ext'] ?? '.csv'); file_put_contents($tmp, $csv);
    $name = ps_private_store('imports', $tmp, 'avoid_' . bin2hex(random_bytes(5)) . ($GLOBALS['ext'] ?? '.csv')); $stored[] = $name; @unlink($tmp);
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
$tag = bin2hex(random_bytes(3));
try {
    $wu = $actorOf('吴宁');
    $check($wu !== false && $wu !== null, '吴宁账号存在');
    // 她的原表：序号 / 店铺 / 日期 / 旺旺号 / 订单编号 / 截图 / 返现 / 返现，表尾是汇总行
    $shared = '5127722533005024725'; // 这个号已是别人的店铺订单，回购订单不能把她挂进去
    $q0 = $pdo->prepare('SELECT id FROM project_orders WHERE order_no=?'); $q0->execute([$shared]); $sharedId = (int)$q0->fetchColumn();
    if (!$sharedId) { $pdo->prepare("INSERT INTO project_orders (order_no,project_type,order_date,customer_name,contract_amount,shop) VALUES (?, '网站模板', '2026-09-02', 'x', 1200, '美呀美')")->execute([$shared]); $sharedId = (int)$pdo->lastInsertId(); }
    $q0 = $pdo->prepare('SELECT COUNT(*) FROM project_participants WHERE order_id=?'); $q0->execute([$sharedId]); $sharedBefore = (int)$q0->fetchColumn();
    $rows = ["序号,店铺,日期,旺旺号,订单编号,截图,返现,返现"];
    $nos = [];
    for ($i = 1; $i <= 19; $i++) { $no = $i === 1 ? $shared : '3316' . $tag . str_pad((string)$i, 9, '0', STR_PAD_LEFT); $nos[] = $no; $rows[] = "$i,美呀美,9." . (1 + $i) . ",买家$i,$no,,0,30"; }
    $rows[] = ",,,,,,,"; $rows[] = ",,,,回购订单30元/单,（好评订单+话费）8元/单,提成总计,说明"; $rows[] = ",,,单量,19,0,,"; $rows[] = ",,,金额,570,0,570,"; $rows[] = ",,,提成,570,,,"; $rows[] = ",,,基本工资,1800,,,"; $rows[] = ",,,全勤奖,200,,,"; $rows[] = ",,,9月工资合计：,2570,,,";
    $csv = implode("\n", $rows) . "\n";
    if (($xl = getenv("BUYBACK_XLSX")) && is_file($xl)) { $csv = file_get_contents($xl); $GLOBALS["ext"] = ".xlsx"; }
    $pdo->prepare('INSERT IGNORE INTO project_user_businesses (user_id,business_name,is_default) VALUES (?,?,0)')->execute([(int)$wu['id'], '回购订单']); // 财务给吴宁账号分配业务
    // 规则中心：回购订单每单 30 元，只发给吴宁
    $pdo->prepare("INSERT INTO project_commission_rules (commission_group,project_type,role_name,order_kind,calc_mode,rate,service_fee_rate,per_order_subsidy,subsidy_employee_ids,min_contract_amount,note,effective_from,is_active) VALUES ('customer_service','回购订单','*','*','pool',0,0,30,?,0,'测试',?,1)")->execute([(string)$wu['employee_id'], '2026-09-01']);
    [$pv, $imp, $err] = $importAs($wu, '回购订单', $csv);
    $check($err === '', '导入无报错：' . $err);
    $check(count($pv) === 19, '汇总行不当订单，预览 19 行：' . count($pv));
    $check($imp === 19, '19 张全部导入：' . $imp . ' ' . implode('；', array_map(function ($r) { return $r['error'] ?? ''; }, $pv)));
    $hg = $pdo->prepare("SELECT COUNT(*) FROM project_orders WHERE project_type='回购订单' AND order_no LIKE 'HG-%'"); $hg->execute();
    $check((int)$hg->fetchColumn() === 19, '落库 19 张，订单号都带 HG- 前缀');
    $q = $pdo->prepare('SELECT COUNT(*) FROM project_participants WHERE order_id=?'); $q->execute([$sharedId]);
    $check((int)$q->fetchColumn() === $sharedBefore, '已有的店铺订单没有被加人（参与人数不变）');
    $q = $pdo->prepare("SELECT o.contract_amount,o.delivery_status,o.order_date,(SELECT COUNT(*) FROM project_participants p WHERE p.order_id=o.id AND p.employee_id=?) mine FROM project_orders o WHERE o.order_no=?"); $q->execute([(int)$wu['employee_id'], 'HG-' . $shared]);
    $o = $q->fetch();
    $check((float)$o['contract_amount'] === 0.0 && $o['delivery_status'] === 'finished' && (int)$o['mine'] === 1 && $o['order_date'] === '2026-09-02', '回购订单：售价 0、已完成、归吴宁、日期 9.2 → 2026-09-02：' . json_encode($o));
    [$pv2, $imp2, $err2] = $importAs($wu, '回购订单', $csv);
    $cnt = $pdo->query("SELECT COUNT(*) FROM project_orders WHERE project_type='回购订单'")->fetchColumn();
    $check((int)$cnt === 19, '整表重传不重复建单：' . $cnt . ' ' . $err2);
    // 导入后系统自动核对：不要求收款，直接通过并结算（每单补助 30 元，合计 570）
    $ids = $pdo->query("SELECT id FROM project_orders WHERE project_type='回购订单' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    $st = $pdo->query("SELECT settlement_status,COUNT(*) FROM project_orders WHERE project_type='回购订单' GROUP BY settlement_status")->fetchAll(PDO::FETCH_KEY_PAIR);
    $check($st === ['approved' => 19], '上传后 19 张自动核对通过并结算：' . json_encode($st));
    $sum = $pdo->prepare("SELECT COALESCE(SUM(commission_amount),0),COUNT(*) FROM project_commission_snapshots WHERE employee_id=? AND payroll_month='2026-09' AND order_id IN (" . implode(',', array_map('intval', $ids)) . ")");
    $sum->execute([(int)$wu['employee_id']]); [$total, $n] = $sum->fetch(PDO::FETCH_NUM);
    $check((float)$total === 570.0 && (int)$n === 19, '9 月提成合计 570（19 单 × 30）：' . $total . ' / ' . $n);
    echo "\n=== 回购订单测试全部通过 ===\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
} finally {
    foreach ($stored as $s) @unlink(ps_private_dir('imports') . '/' . basename($s) . '.php');
}
