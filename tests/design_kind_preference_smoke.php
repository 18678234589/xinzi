<?php
// 设计：订单类型由表格版式决定（没有“设计师佣金”列 = 图片），不被“上次确认的类型”（PPT）覆盖。只预览，事务内执行并回滚。
// php tests/graphic_design_flow_smoke.php（需先执行 20260927_graphic_design_package.sql 与 provision_graphic_designer.php）
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectMonthly.php';

$pdo = db();
$pdo->beginTransaction();
$stored = null;
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
try {
    $user = $pdo->query("SELECT u.id,u.employee_id,u.role FROM project_users u JOIN employees e ON e.id=u.employee_id WHERE e.name='穆楠' AND u.is_active=1")->fetch();
    $check((bool)$user, '穆楠已开通项目账号');
    $actor = ['type' => 'employee', 'id' => (int)$user['id'], 'employee_id' => (int)$user['employee_id'], 'role' => $user['role']];
    $pdo->prepare("INSERT INTO project_import_kind_preferences (employee_id,business_name,layout_signature,order_kind,source) VALUES (?,'设计','*','PPT','finance') ON DUPLICATE KEY UPDATE order_kind='PPT',source='finance'")->execute([(int)$user['employee_id']]);
    $month = '2026-10';
    $pdo->prepare('DELETE FROM project_payroll_periods WHERE period=? AND status<>\'locked\'')->execute([$month]);

    echo "=== 上传原表 ===\n";
    $fixture = __DIR__ . '/fixtures/design_kind_layout.csv';
    $stored = ps_private_store('imports', $fixture, 'graphic_test_' . bin2hex(random_bytes(5)) . '.csv');
    $pdo->prepare("INSERT INTO project_import_files (business_name,original_name,stored_name,file_size,uploaded_by_type,uploaded_by_id,employee_id) VALUES ('设计','穆楠9月份图片.csv',?,?,'employee',?,?)")
        ->execute([$stored, filesize($fixture), $actor['id'], $actor['employee_id']]);
    $fileId = (int)$pdo->lastInsertId();
    $_SESSION = ['project_user_id' => $actor['id'], 'project_csrf' => 'test-csrf'];
    $_SERVER['SCRIPT_NAME'] = '/project/import.php';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['csrf' => 'test-csrf', 'action' => 'repreview', 'business' => '设计', 'file_id' => $fileId, 'all_sheets' => 1];
    $error = ''; ob_start(); include __DIR__ . '/../project/import.php'; ob_end_clean();
    $check($error === '', '预览成功' . ($error ? '：' . $error : ''));
    $preview = $_SESSION['project_import_preview'] ?? [];
    $valid = array_values(array_filter($preview, function ($r) { return !empty($r['base_valid']); }));
    $check(count($valid) === 2, '2 单可导入');
    $check(!array_filter($valid, function ($r) { return $r['order_kind'] !== '图片'; }), '没有设计师佣金列的表按图片导入，即使保存过“PPT”偏好');
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
