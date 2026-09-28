<?php
// 标书：王宁上传 8 月原表 → 财务审核（成本、订单）→ 标书提成与 8 月核算表对账（777.85）。事务内执行，结束回滚。
// php tests/bid_flow_202608.php（需 订单模板与成本及算法/标书/8月王宁标书示例.xlsx，先执行 20260928_bid_business.sql）
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectMonthly.php';

$file = __DIR__ . '/../订单模板与成本及算法/标书/8月王宁标书示例.xlsx';
if (!is_file($file)) { echo "缺少样表，跳过\n"; exit(0); }
$pdo = db();
$pdo->beginTransaction();
$stored = null;
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
try {
    $user = $pdo->query("SELECT u.id,u.employee_id,u.role FROM project_users u JOIN employees e ON e.id=u.employee_id WHERE e.name='王宁' AND u.is_active=1")->fetch();
    $actor = ['type' => 'employee', 'id' => (int)$user['id'], 'employee_id' => (int)$user['employee_id'], 'role' => $user['role']];
    $check(in_array('标书', ps_actor_businesses($actor), true) && in_array('小程序开发', ps_actor_businesses($actor), true), '王宁同时有“标书”和小程序客服业务');
    $check(ps_employee_default_role($actor['employee_id'], '标书', 'customer_service') === '标书客服', '默认岗位：标书客服');
    $stored = ps_private_store('imports', $file, 'bid_test_' . bin2hex(random_bytes(5)) . '.xlsx');
    $pdo->prepare("INSERT INTO project_import_files (business_name,original_name,stored_name,file_size,uploaded_by_type,uploaded_by_id,employee_id) VALUES ('标书','8月王宁标书示例.xlsx',?,?,'employee',?,?)")
        ->execute([$stored, filesize($file), $actor['id'], $actor['employee_id']]);
    $fileId = (int)$pdo->lastInsertId();
    $_SESSION = ['project_user_id' => $actor['id'], 'project_csrf' => 'test-csrf'];
    $_SERVER['SCRIPT_NAME'] = '/project/import.php';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['csrf' => 'test-csrf', 'action' => 'repreview', 'business' => '标书', 'file_id' => $fileId, 'all_sheets' => 1];
    $error = ''; ob_start(); include __DIR__ . '/../project/import.php'; ob_end_clean();
    $check($error === '', '预览成功' . ($error ? '：' . $error : ''));
    $preview = $_SESSION['project_import_preview'] ?? [];
    $bad = array_values(array_filter($preview, function ($r) { return empty($r['base_valid']); }));
    foreach ($bad as $r) echo "     需处理 第{$r['line']}行 {$r['order_no']}：{$r['error']}\n";
    $check(count($preview) === 21 && !$bad, '21 单全部可导入（设计师续行与汇总行不当订单）');
    $revenue = array_sum(array_map(function ($r) { return (float)$r['contract_amount']; }, $preview));
    $cost = array_sum(array_map(function ($r) { return (float)$r['direct_cost']; }, $preview));
    $check($revenue == 16550.0 && $cost == 8275.0, '售价合计 16550、成本合计 8275（与核算表一致）');
    $_POST = ['csrf' => 'test-csrf', 'action' => 'commit', 'business' => '标书'];
    $error = ''; $imported = 0; ob_start(); include __DIR__ . '/../project/import.php'; ob_end_clean();
    $check($error === '' && $imported === 21, '导入 21 单');

    $finance = ['type' => 'admin', 'id' => (int)$pdo->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetchColumn(), 'employee_id' => null, 'role' => 'finance'];
    $ids = $pdo->prepare("SELECT o.id FROM project_orders o JOIN project_participants p ON p.order_id=o.id AND p.employee_id=? WHERE o.project_type='标书' AND o.settlement_status IN ('draft','review')");
    $ids->execute([$actor['employee_id']]);
    $orderIds = $ids->fetchAll(PDO::FETCH_COLUMN);
    $month = '2026-11';
    foreach ($orderIds as $id) {
        $pdo->prepare("UPDATE project_costs SET review_status='approved' WHERE order_id=? AND review_status='pending'")->execute([(int)$id]); // 财务审核 500 元以上的成本
        $pdo->prepare('UPDATE project_orders SET receipt_amount=contract_amount WHERE id=?')->execute([(int)$id]);
        ps_approve_order((int)$id, $finance, $month);
    }
    $snap = $pdo->prepare("SELECT COUNT(*),SUM(commission_amount),SUM(commission_exact),SUM(service_fee) FROM project_commission_snapshots s JOIN project_orders o ON o.id=s.order_id WHERE o.project_type='标书' AND s.employee_id=? AND s.payroll_month=?");
    $snap->execute([$actor['employee_id'], $month]);
    [$count, $commission, $exact, $fee] = $snap->fetch(PDO::FETCH_NUM);
    $check((int)$count === 21 && abs((float)$fee - 496.5) < 0.05, '21 单审核通过，服务费合计 ¥' . round((float)$fee, 2) . '（16550 × 3% = 496.5）');
    $rounding = 0.0;
    foreach (ps_monthly_results($month, true) as $r) if ((int)$r['employee_id'] === $actor['employee_id'] && $r['rule_type'] === 'rounding') $rounding += (float)$r['amount'];
    $check(abs((float)$commission + $rounding - 777.85) < 0.005, '标书提成 ¥' . round((float)$commission + $rounding, 2) . '（逐单 ¥' . round((float)$commission, 2) . ' + 尾差 ¥' . $rounding . '）= 核算表 777.85');

    $pdo->rollBack();
    echo "\n=== 标书上传 → 审核 → 提成与 8 月核算表一致，测试数据已回滚 ===\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
} finally {
    if ($stored !== null) @unlink(ps_private_dir('imports') . '/' . basename($stored) . '.php');
}
