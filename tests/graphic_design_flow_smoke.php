<?php
// 平面设计端到端：阎泸琪上传原表 → 财务确认实收并审核 → 规则中心填好评率 → 月度“营业额阶梯薪酬”结果。事务内执行，结束回滚。
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
    $fixture = __DIR__ . '/fixtures/graphic_design_orders.csv';
    $stored = ps_private_store('imports', $fixture, 'graphic_test_' . bin2hex(random_bytes(5)) . '.csv');
    $pdo->prepare("INSERT INTO project_import_files (business_name,original_name,stored_name,file_size,uploaded_by_type,uploaded_by_id,employee_id) VALUES ('平面设计','阎泸琪10月.csv',?,?,'employee',?,?)")
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
    $check(count($valid) === 5, '5 单可导入（合计行不当订单）');
    $byNo = []; foreach ($valid as $r) $byNo[$r['order_no']] = $r;
    $check($byNo['GD-TEST-002']['order_kind'] === '老客户找回' && $byNo['GD-TEST-001']['order_kind'] === '新订单', '“老客户”列有内容 → 老客户找回，其余新订单');
    $check(!array_filter($valid, function ($r) { return $r['delivery_status'] !== 'finished'; }), '无表头的到账列识别为已完成');
    $check(isset($byNo['GD-TEST-001']['people']['technical'][(int)$actor['employee_id']]), '本人自动作为平面设计参与人');
    $_POST = ['csrf' => 'test-csrf', 'action' => 'commit', 'business' => '平面设计'];
    $error = ''; $imported = 0; ob_start(); include __DIR__ . '/../project/import.php'; ob_end_clean();
    $check($error === '' && $imported === 5, '导入 5 单');

    echo "=== 财务确认实收并审核 ===\n";
    $finance = ['type' => 'admin', 'id' => (int)$pdo->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetchColumn(), 'employee_id' => null, 'role' => 'finance'];
    $ids = $pdo->query("SELECT id FROM project_orders WHERE order_no LIKE 'GD-TEST-%' ORDER BY order_no")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        $pdo->prepare('UPDATE project_orders SET receipt_amount=contract_amount WHERE id=?')->execute([(int)$id]);
        ps_approve_order((int)$id, $finance, $month);
    }
    $snap = $pdo->query("SELECT COUNT(*),SUM(commission_amount) FROM project_commission_snapshots s JOIN project_orders o ON o.id=s.order_id WHERE o.order_no LIKE 'GD-TEST-%'")->fetch(PDO::FETCH_NUM);
    $check((int)$snap[0] === 5 && (float)$snap[1] == 0.0, '5 单审核通过，逐单分成为 0（全部按月结算）');

    echo "=== 月度结果 ===\n";
    $rule = $pdo->query("SELECT id FROM project_monthly_rules WHERE name='阎泸琪 营业额阶梯薪酬' AND is_active=1")->fetchColumn();
    $check((bool)$rule, '月度规则“阎泸琪 营业额阶梯薪酬”已启用');
    $pdo->prepare('INSERT INTO project_monthly_inputs (payroll_month,rule_id,employee_id,value,note) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)')->execute([$month, (int)$rule, $actor['employee_id'], 8, '测试']);
    $mine = array_values(array_filter(ps_monthly_results($month, true), function ($r) use ($actor) { return (int)$r['employee_id'] === (int)$actor['employee_id']; }));
    $lines = []; foreach ($mine as $r) $lines[$r['rule_name']] = (float)$r['amount'];
    foreach ($mine as $r) echo '     ' . $r['rule_name'] . '  ¥' . $r['amount'] . '  ' . $r['detail'] . "\n";
    $check(abs(($lines['阎泸琪 营业额阶梯薪酬 · 底薪'] ?? 0) - 2100) < 0.01 || strpos(json_encode($mine, JSON_UNESCAPED_UNICODE), '请假') !== false, '营业额 5000 落 ≤6000 档：底薪 2100（按考勤折算）');
    $check(($lines['阎泸琪 营业额阶梯薪酬 · 营业额提成'] ?? 0) == 250.0, '营业额提成 5000 × 5% = 250');
    $check(($lines['阎泸琪 营业额阶梯薪酬 · 单量补助'] ?? 0) == 7.0, '单量补助 3 × 2 + 2 × 0.5 = 7');
    $check(($lines['阎泸琪 营业额阶梯薪酬 · 老客户找回'] ?? 0) == 150.0, '老客户找回 1500 × 10% = 150');
    $check(($lines['阎泸琪 营业额阶梯薪酬 · 好评率罚款'] ?? 0) == -100.0, '好评率 8%：扣 100');

    $pdo->rollBack();
    echo "\n=== 平面设计上传 → 审核 → 月度工资全部通过，测试数据已回滚 ===\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
} finally {
    if ($stored !== null) @unlink(ps_private_dir('imports') . '/' . basename($stored) . '.php');
}
