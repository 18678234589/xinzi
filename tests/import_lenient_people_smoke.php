<?php
// 上传容错（0929 反馈）：技术列误填项目名不拦整行、客服照常挂上、业务说明自动补全、已有订单补上漏掉的客服；
// 客服姓名写错仍拦截；下载模板精简。事务内执行，结束回滚。php tests/import_lenient_people_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';

$pdo = db();
$pdo->beginTransaction();
$stored = null;
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$E = function ($name) use ($pdo) { $q = $pdo->prepare('SELECT id FROM employees WHERE name=? ORDER BY id LIMIT 1'); $q->execute([$name]); return (int)$q->fetchColumn(); };
try {
    $user = $pdo->query("SELECT u.id,u.employee_id,u.role FROM project_users u JOIN employees e ON e.id=u.employee_id WHERE e.name='翟建跃' AND u.is_active=1")->fetch();
    $actor = ['type' => 'employee', 'id' => (int)$user['id'], 'employee_id' => (int)$user['employee_id'], 'role' => $user['role']];
    // 已有订单：之前只录了技术翟建跃，客服没挂上
    $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,order_date,delivery_status,note) VALUES ('LENIENT-TEST-004','tb382065155','小程序开发','定制','美呀美',600,'2026-09-04','finished','测试')")->execute();
    $existingId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'technical','制作技术',1)")->execute([$existingId, $actor['employee_id']]);

    $fixture = __DIR__ . '/fixtures/import_lenient_people.csv';
    $stored = ps_private_store('imports', $fixture, 'lenient_test_' . bin2hex(random_bytes(5)) . '.csv');
    $pdo->prepare("INSERT INTO project_import_files (business_name,original_name,stored_name,file_size,uploaded_by_type,uploaded_by_id,employee_id) VALUES ('小程序开发','翟建跃9月.csv',?,?,'employee',?,?)")->execute([$stored, filesize($fixture), $actor['id'], $actor['employee_id']]);
    $fileId = (int)$pdo->lastInsertId();
    $_SESSION = ['project_user_id' => $actor['id'], 'project_csrf' => 'test-csrf'];
    $_SERVER['SCRIPT_NAME'] = '/project/import.php';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['csrf' => 'test-csrf', 'action' => 'repreview', 'business' => '小程序开发', 'file_id' => $fileId, 'all_sheets' => 1];
    $error = ''; ob_start(); include __DIR__ . '/../project/import.php'; ob_end_clean();
    $check($error === '', '预览成功');
    $byNo = []; foreach ($_SESSION['project_import_preview'] ?? [] as $r) $byNo[$r['order_no']] = $r;
    $r1 = $byNo['LENIENT-TEST-001'];
    $check(!empty($r1['base_valid']) && isset($r1['people']['customer_service'][$E('曹双双')]) && isset($r1['people']['technical'][$actor['employee_id']]), '“制作技术”误填“农商行智e购”：不再拦整行，客服曹双双照常挂上，技术为上传人本人');
    $check(mb_strpos($r1['warning'], '不是合作人员，已忽略') !== false, '提示“技术列写的不是合作人员，已忽略”');
    $check(($r1['details']['miniapp_name'] ?? '') === '农商行智e购·线下扫码H5' && ($r1['details']['customer_wechat'] ?? '') === '张良民@微信', '小程序名称取“业务”列、客户微信取“备注”，不必重复填写');
    $check(($byNo['LENIENT-TEST-002']['details']['miniapp_name'] ?? '') === '加油小程序', '表格已填的小程序名称保留原值');
    $check(empty($byNo['LENIENT-TEST-003']['base_valid']) && mb_strpos($byNo['LENIENT-TEST-003']['error'], '曹双') !== false, '客服姓名写错（曹双）仍拦截提示，避免漏算客服提成');

    $_POST = ['csrf' => 'test-csrf', 'action' => 'commit', 'business' => '小程序开发'];
    $error = ''; $imported = 0; ob_start(); include __DIR__ . '/../project/import.php'; ob_end_clean();
    $check($error === '' && $imported === 3, '导入 3 单（含补充已有订单 1 单）');
    $cs = $pdo->prepare("SELECT e.name FROM project_participants p JOIN employees e ON e.id=p.employee_id WHERE p.order_id=? AND p.commission_group='customer_service'");
    $cs->execute([$existingId]);
    $check($cs->fetchColumn() === '曹双双', '已有订单（原只有技术）补上客服曹双双');
    $q = $pdo->prepare("SELECT o.id FROM project_orders o WHERE o.order_no='LENIENT-TEST-001'");
    $q->execute();
    $newId = (int)$q->fetchColumn();
    $cs->execute([$newId]);
    $check($cs->fetchColumn() === '曹双双', '新订单客服曹双双已记录，订单列表可见');

    $headers = ps_business_import_headers('小程序开发');
    $check(!array_intersect($headers, ['协作技术', '订单类型', '小程序名称', '制作要求', '客户微信']) && in_array('客服', $headers, true) && in_array('制作技术', $headers, true), '下载模板去掉可自动识别 / 补全的列：' . implode('、', $headers));

    $pdo->rollBack();
    echo "\n=== 上传容错全部通过，测试数据已回滚 ===\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
} finally {
    if ($stored !== null) @unlink(ps_private_dir('imports') . '/' . basename($stored) . '.php');
}
