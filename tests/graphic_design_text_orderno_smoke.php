<?php
// 平面设计：订单号格里只有姓名 / 店铺名（“内部”“张姐”）时不当订单号，改走无订单号流程（WX- 内部号）。只预览，事务内执行并回滚。
// php tests/graphic_design_flow_smoke.php（需先执行 20260927_graphic_design_package.sql 与 provision_graphic_designer.php）
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectMonthly.php';

$pdo = db();
$pdo->beginTransaction();
$stored = null;
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
try {
    $user = $pdo->query("SELECT u.id,u.employee_id,u.role FROM project_users u JOIN employees e ON e.id=u.employee_id WHERE e.name='阎泸琪' AND u.is_active=1")->fetch();
    $check((bool)$user, '阎泸琪已开通项目账号');
    $actor = ['type' => 'employee', 'id' => (int)$user['id'], 'employee_id' => (int)$user['employee_id'], 'role' => $user['role']];
    $check(ps_actor_businesses($actor) === ['平面设计'], '账号业务为“平面设计”');
    $month = '2026-10';
    $pdo->prepare('DELETE FROM project_payroll_periods WHERE period=? AND status<>\'locked\'')->execute([$month]);

    echo "=== 上传原表 ===\n";
    $fixture = __DIR__ . '/fixtures/graphic_design_text_orderno.csv';
    $stored = ps_private_store('imports', $fixture, 'graphic_test_' . bin2hex(random_bytes(5)) . '.csv');
    $pdo->prepare("INSERT INTO project_import_files (business_name,original_name,stored_name,file_size,uploaded_by_type,uploaded_by_id,employee_id) VALUES ('平面设计','阎泸琪文字订单号.csv',?,?,'employee',?,?)")
        ->execute([$stored, filesize($fixture), $actor['id'], $actor['employee_id']]);
    $fileId = (int)$pdo->lastInsertId();
    $_SESSION = ['project_user_id' => $actor['id'], 'project_csrf' => 'test-csrf'];
    $_SERVER['SCRIPT_NAME'] = '/project/import.php';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['csrf' => 'test-csrf', 'action' => 'repreview', 'business' => '平面设计', 'file_id' => $fileId, 'all_sheets' => 1];
    $error = ''; ob_start(); include __DIR__ . '/../project/import.php'; ob_end_clean();
    $check($error === '', '预览成功' . ($error ? '：' . $error : ''));
    $preview = $_SESSION['project_import_preview'] ?? [];
    $valid = array_values(array_filter($preview, function ($r) { return !empty($r['base_valid']); }));
    if (count($valid) !== 3) fwrite(STDERR, json_encode(array_map(function ($r) { return [$r['order_no'] ?? '', $r['base_valid'] ?? null, $r['errors'] ?? $r['error'] ?? $r['message'] ?? '']; }, $preview), JSON_UNESCAPED_UNICODE) . "
");
    $check(count($valid) === 3, '3 单可导入');
    $nos = array_column($valid, 'order_no');
    $check(count(array_filter($nos, function ($n) { return strpos($n, 'WX-') === 0; })) === 2 && in_array('GD-TXT-003', $nos, true), '订单号格里的姓名不当订单号，生成稳定的 WX- 内部号；真实订单号照旧');
    $check(!in_array('测试姓名甲', $nos, true) && !in_array('测试姓名乙', $nos, true), '没有把姓名 / 店铺名当订单号建单');
    $pdo->rollBack();
    echo "
=== 全部通过，测试数据已回滚 ===
";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . "
" . $e->getTraceAsString() . "
");
    exit(1);
} finally {
    if ($stored !== null) @unlink(ps_private_dir('imports') . '/' . basename($stored) . '.php');
}
