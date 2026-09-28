<?php
// 全勤奖默认不发、财务审批后才计入：规则中心“全勤奖审批”批准 / 撤销。事务内执行，结束回滚。php tests/attendance_approval_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectMonthly.php';

$pdo = db();
$pdo->beginTransaction();
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
try {
    $month = '2026-11';
    $rule = $pdo->query("SELECT r.id,r.employee_id,r.params_json FROM project_monthly_rules r JOIN employees e ON e.id=r.employee_id WHERE r.rule_type='attendance_bonus' AND r.is_active=1 AND e.name='冯超' LIMIT 1")->fetch();
    $check((bool)$rule, '冯超有全勤奖规则');
    $eid = (int)$rule['employee_id'];
    $targetRuleId = (int)$rule['id']; $targetEmployeeId = $eid; // rules.php 被 include 后会覆盖 $rule / $eid
    $pdo->prepare('DELETE FROM project_monthly_inputs WHERE payroll_month=? AND rule_id=?')->execute([$month, (int)$rule['id']]);
    $bonus = function () use ($month, $eid, $rule) { foreach (ps_monthly_results($month, true) as $r) if ((int)$r['employee_id'] === $eid && (int)$r['rule_id'] === (int)$rule['id']) return $r; return null; };
    $check($bonus() === null, '未批准：全勤奖默认不发（即使考勤满勤）');
    $check(ps_attendance_suggestion(200, ['work' => 208, 'absent' => 0]) === [200.0, '满勤'] && ps_attendance_suggestion(200, ['work' => 208, 'absent' => 5])[0] === 100.0 && ps_attendance_suggestion(200, null)[0] === 0.0, '考勤建议：满勤全额、请假 ≥4 小时减半、无考勤记录建议 0 待核对');

    $admin = (int)$pdo->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetchColumn();
    $_SESSION = ['admin_id' => $admin, 'project_csrf' => 'test-csrf'];
    $_SERVER['SCRIPT_NAME'] = '/project/rules.php';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_GET = [];
    $_POST = ['csrf' => 'test-csrf', 'action' => 'attendance_approve', 'month' => $month, 'approve' => [(int)$rule['id']], 'amount' => [(int)$rule['id'] => 200], 'approve_note' => [(int)$rule['id'] => '财务核对满勤']];
    $error = ''; $success = ''; ob_start(); include __DIR__ . '/../project/rules.php'; $html = ob_get_clean();
    $check($error === '' && strpos($success, '已批准 1 人') !== false, '财务在规则中心勾选批准');
    $row = $bonus();
    $check($row && (float)$row['amount'] === 200.0 && strpos($row['detail'], '财务批准') !== false, '批准后计入全勤奖 ¥200（' . ($row['detail'] ?? '') . '）');
    $check(strpos($html, 'attendance-approval') !== false && strpos($html, '已批准 ¥200.00') !== false, '审批表显示“已批准 ¥200”');

    $_POST = ['csrf' => 'test-csrf', 'action' => 'monthly_input_delete', 'month' => $month, 'rule_id' => $targetRuleId, 'employee_id' => $targetEmployeeId];
    $error = ''; ob_start(); include __DIR__ . '/../project/rules.php'; ob_end_clean();
    $check($error === '' && $bonus() === null, '撤销后恢复为不发');

    $pdo->rollBack();
    echo "\n=== 全勤奖审批全部通过，测试数据已回滚 ===\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
