<?php
// 设计客服已用“设计”业务录入的订单，设计师（阎泸琪，“平面设计”）上传同号订单：不再被拦，另记平面设计分单并沿用原单售价 / 日期；
// 另覆盖“两个业务各记一部分金额”的分单。导入流程含建表语句会提前提交，所以只允许在本地临时库运行。
// DB_HOST=127.0.0.1 DB_PORT=13399 php tests/designer_split_import_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
if (!in_array((string)DB_PORT, ['13399'], true) || DB_HOST !== '127.0.0.1') { fwrite(STDERR, "拒绝运行：本测试会提交数据，只能连本地临时库（DB_PORT=13399）\n"); exit(2); }

$pdo = db();
$tmp = null; $stored = null;
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$E = function ($name) use ($pdo) { $q = $pdo->prepare('SELECT id FROM employees WHERE name=? ORDER BY id LIMIT 1'); $q->execute([$name]); return (int)$q->fetchColumn(); };
$actorOf = function ($name) use ($pdo) { $q = $pdo->prepare("SELECT u.id,u.employee_id,u.role FROM project_users u JOIN employees e ON e.id=u.employee_id WHERE e.name=? AND u.is_active=1"); $q->execute([$name]); $u = $q->fetch(); return $u ? ['type' => 'employee', 'id' => (int)$u['id'], 'employee_id' => (int)$u['employee_id'], 'role' => $u['role']] : null; };
$upload = function ($actor, $business, $csv) use ($pdo, &$tmp, &$stored) {
    $tmp = tempnam(sys_get_temp_dir(), 'ds') . '.csv'; file_put_contents($tmp, $csv);
    $stored = ps_private_store('imports', $tmp, 'split_test_' . bin2hex(random_bytes(5)) . '.csv');
    $pdo->prepare("INSERT INTO project_import_files (business_name,original_name,stored_name,file_size,uploaded_by_type,uploaded_by_id,employee_id) VALUES (?,'测试.csv',?,?,'employee',?,?)")->execute([$business, $stored, filesize($tmp), $actor['id'], $actor['employee_id']]);
    return (int)$pdo->lastInsertId();
};
$run = function ($actor, $post) { $_SESSION = array_merge($_SESSION ?? [], ['project_user_id' => $actor['id'], 'project_csrf' => 'test-csrf']); $_SERVER['SCRIPT_NAME'] = '/project/import.php'; $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = $post + ['csrf' => 'test-csrf']; $GLOBALS['error'] = ''; $GLOBALS['imported'] = 0; ob_start(); include __DIR__ . '/../project/import.php'; ob_end_clean(); $GLOBALS['imported'] = $imported ?? 0; $GLOBALS['error'] = $error ?? ''; };
$tag = bin2hex(random_bytes(3));
try {
    $designer = $actorOf('阎泸琪'); $csId = $E('穆楠');
    $check((bool)$designer && $csId > 0, '阎泸琪账号与设计客服穆楠存在');

    echo "=== 一、设计师上传同号订单（表里无售价、无日期） ===\n";
    $no = "ZZ-DS-$tag";
    $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,order_date,delivery_status,note) VALUES (?,'测试客户','设计','PPT','微信',300,'2026-09-05','unfinished','测试')")->execute([$no]);
    $parentId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'customer_service','客服',1)")->execute([$parentId, $csId]);
    $fileId = $upload($designer, '平面设计', "内容,老客户,店铺,付款昵称,时间,订单号,订单金额,\nPPT设计,,,,,$no,,到账\n");
    $run($designer, ['action' => 'repreview', 'business' => '平面设计', 'file_id' => $fileId, 'all_sheets' => 1]);
    $check($error === '', '预览成功' . ($error ? '：' . $error : ''));
    $row = ($_SESSION['project_import_preview'] ?? [])[0] ?? [];
    $check(!empty($row['base_valid']), '该行可导入：' . ($row['error'] ?? ''));
    $check(($row['order_no'] ?? '') === "$no~平面设计", '按分单子单订单号建单');
    $check(($row['contract_amount'] ?? '') === '300.00', '沿用设计客服原单售价 ¥300');
    $check(($row['order_date'] ?? '') === '2026-09-05', '沿用原单日期');
    $run($designer, ['action' => 'commit', 'business' => '平面设计']);
    $check($error === '' && $imported === 1, '导入 1 单' . ($error ? '：' . $error : '') . ' ' . json_encode(['imported' => $imported ?? null, 'skipped' => $skipped ?? null, 'kind' => $row['order_kind'] ?? null, 'people' => array_map('array_keys', $row['people'] ?? [])], JSON_UNESCAPED_UNICODE));
    $q = $pdo->prepare('SELECT id,project_type,contract_amount FROM project_orders WHERE order_no=?');
    $q->execute(["$no~平面设计"]); $child = $q->fetch();
    $check($child && $child['project_type'] === '平面设计' && (float)$child['contract_amount'] === 300.0, '子单业务“平面设计”、售价 ¥300');
    $l = $pdo->prepare('SELECT COUNT(*) FROM project_order_splits WHERE parent_order_id=? AND child_order_id=?'); $l->execute([$parentId, $child['id']]);
    $check((int)$l->fetchColumn() === 1, '与设计客服原单建立分单关联');
    $p = $pdo->prepare('SELECT employee_id FROM project_participants WHERE order_id=?'); $p->execute([$child['id']]);
    $check($p->fetchAll(PDO::FETCH_COLUMN) == [(int)$designer['employee_id']], '子单参与人只有设计师本人');
    $p->execute([$parentId]);
    $check($p->fetchAll(PDO::FETCH_COLUMN) == [$csId], '设计客服原单参与人、业务不变');
    $q = $pdo->prepare('SELECT contract_amount,project_type FROM project_orders WHERE id=?'); $q->execute([$parentId]); $parent = $q->fetch();
    $check((float)$parent['contract_amount'] === 300.0 && $parent['project_type'] === '设计', '设计客服原单金额、业务不变');

    echo "=== 二、重复上传同一张表 ===\n";
    $run($designer, ['action' => 'repreview', 'business' => '平面设计', 'file_id' => $fileId, 'all_sheets' => 1]);
    $row = ($_SESSION['project_import_preview'] ?? [])[0] ?? [];
    $check(($row['status'] ?? '') === '补充已有订单' && !empty($row['base_valid']), '二次上传识别为已有子单，不报错：' . ($row['error'] ?? ''));
    $run($designer, ['action' => 'commit', 'business' => '平面设计']);
    $c = $pdo->prepare("SELECT COUNT(*) FROM project_orders WHERE order_no LIKE ?"); $c->execute(["$no%"]);
    $check((int)$c->fetchColumn() === 2, '没有重复建单（原单 + 1 张子单）');

    echo "=== 三、订单号写法不同也认作同一单 ===\n";
    $fileId2 = $upload($designer, '平面设计', "内容,老客户,店铺,付款昵称,时间,订单号,订单金额,\nPPT设计,,,,,订单编号：$no,,到账\n");
    $run($designer, ['action' => 'repreview', 'business' => '平面设计', 'file_id' => $fileId2, 'all_sheets' => 1]);
    $row = ($_SESSION['project_import_preview'] ?? [])[0] ?? [];
    $check(($row['order_no'] ?? '') === "$no~平面设计" && ($row['status'] ?? '') === '补充已有订单', '带“订单编号：”前缀的写法落到同一张子单');

    echo "=== 四、两个业务各记一部分金额（¥300 = 200 + 100） ===\n";
    $no2 = "ZZ-SP-$tag";
    $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,order_date,delivery_status,note) VALUES (?,'测试客户2','设计','图片','微信',100,'2026-09-06','unfinished','测试')")->execute([$no2]);
    $o2 = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'customer_service','客服',1)")->execute([$o2, $csId]);
    $fileId3 = $upload($designer, '平面设计', "内容,老客户,店铺,付款昵称,时间,订单号,订单金额,\n图,,,,2026.9.6,$no2,200,到账\n");
    $run($designer, ['action' => 'repreview', 'business' => '平面设计', 'file_id' => $fileId3, 'all_sheets' => 1]);
    $row = ($_SESSION['project_import_preview'] ?? [])[0] ?? [];
    $check(!empty($row['base_valid']) && in_array($row['contract_amount'] ?? '', ['200', '200.00'], true), '写了自己那份金额 ¥200：按 ¥200 另记，不沿用原单 ¥100');
    $check(mb_strpos((string)($row['warning'] ?? ''), '= ¥300.00') !== false, '预览提示合计 ¥300.00，供核对：' . ($row['warning'] ?? ''));

    echo "\n=== 设计师同号上传测试全部通过 ===\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
} finally {
    if ($stored !== null) @unlink(ps_private_dir('imports') . '/' . basename($stored) . '.php');
    if ($tmp) @unlink($tmp);
}
