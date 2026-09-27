<?php
// 建议 / Bug 奖励台账：财务与监委会录入、结论、发放、编辑及权限；事务内执行，结束回滚。
// php tests/governance_contributions_smoke.php（需先执行 20260927_governance_contribution_entry.sql）
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectGovernance.php';

$pdo = db();
$pdo->beginTransaction();
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$employee = function ($name) use ($pdo) { $q = $pdo->prepare('SELECT id FROM employees WHERE name=?'); $q->execute([$name]); return (int)$q->fetchColumn(); };
$run = function ($session, $method, $post = [], $get = []) {
    $_SESSION = $session + ['project_csrf' => 'test-csrf'];
    $_SERVER['SCRIPT_NAME'] = '/project/contributions.php';
    $_SERVER['REQUEST_METHOD'] = $method;
    $_POST = $post ? $post + ['csrf' => 'test-csrf'] : [];
    $_GET = $get;
    $_FILES = [];
    $error = ''; $savedId = 0;
    ob_start();
    include __DIR__ . '/../project/contributions.php';
    $html = ob_get_clean();
    return [$error, $savedId, $html];
};
try {
    $xie = $employee('谢文婷'); $qu = $employee('曲俊泽'); $luan = $employee('栾鑫');
    $admin = (int)$pdo->query("SELECT id FROM admins WHERE username='liuqun'")->fetchColumn();
    $check($xie && $qu && $luan && $admin, '测试人员与财务账号存在');
    $finance = ['admin_id' => $admin];

    echo "=== 财务录入 ===\n";
    [$error, , $html] = $run($finance, 'GET');
    $check($error === '' && strpos($html, '记录台账') !== false && strpos($html, 'evidence_files[]') !== false, '财务可打开页面并看到多文件举证框');
    [$error] = $run($finance, 'POST', ['action' => 'create', 'owner_employee_id' => $xie, 'record_date' => '2026-09-20', 'category' => '测试bug', 'conclusion' => 'approved', 'bonus_delta' => '200']);
    $check(mb_strpos($error, '评判人') !== false, '给结论必须选评判人');
    [$error] = $run($finance, 'POST', ['action' => 'create', 'owner_employee_id' => $xie, 'record_date' => '2026-09-20', 'category' => '测试bug', 'conclusion' => 'pending', 'bonus_delta' => '200']);
    $check(mb_strpos($error, '已完成') !== false, '待评判不能填奖金');
    [$error, $id] = $run($finance, 'POST', ['action' => 'create', 'owner_employee_id' => $xie, 'record_date' => '2026-09-20', 'category' => '测试bug', 'description' => '测试描述', 'conclusion' => 'approved', 'bonus_delta' => '200', 'reviewer_employee_id' => $qu, 'reward_status' => 'unpaid']);
    $row = $pdo->query('SELECT * FROM project_governance_records WHERE id=' . (int)$id)->fetch();
    $check($error === '' && $row && $row['review_state'] === 'approved' && (float)$row['bonus_delta'] === 200.0 && $row['reward_status'] === 'unpaid' && (int)$row['created_by_admin_id'] === $admin && $row['created_by_employee_id'] === null, '财务登记已完成事项：奖金 200、待发放、录入人为财务账号');
    [$error] = $run($finance, 'POST', ['action' => 'mark_paid', 'record_id' => $id]);
    $row = $pdo->query('SELECT reward_status,reward_paid_on FROM project_governance_records WHERE id=' . (int)$id)->fetch();
    $check($error === '' && $row['reward_status'] === 'paid' && $row['reward_paid_on'] === date('Y-m-d'), '一键登记奖励已发');
    [$error] = $run($finance, 'POST', ['action' => 'update', 'record_id' => $id, 'owner_employee_id' => $xie, 'record_date' => '2026-09-20', 'category' => '测试bug', 'conclusion' => 'approved', 'bonus_delta' => '250', 'reviewer_employee_id' => $qu, 'reward_status' => 'paid', 'reward_paid_on' => '2026-09-25', 'flow_note' => '随 9 月工资']);
    $row = $pdo->query('SELECT bonus_delta,reward_paid_on,flow_note FROM project_governance_records WHERE id=' . (int)$id)->fetch();
    $audit = (int)$pdo->query("SELECT COUNT(*) FROM project_audit_logs WHERE entity_type='governance_record' AND entity_id=" . (int)$id . " AND action='contribution_update'")->fetchColumn();
    $check($error === '' && (float)$row['bonus_delta'] === 250.0 && $row['reward_paid_on'] === '2026-09-25' && $row['flow_note'] === '随 9 月工资' && $audit === 1, '编辑奖金 / 发放日期 / 备注并留审计');
    [, , $html] = $run($finance, 'GET', [], ['status' => 'paid']);
    $check(strpos($html, 'id="c-' . (int)$id . '"') !== false && strpos($html, '奖励已发 2026-09-25') !== false, '按“奖励已发”筛选可见');

    echo "=== 监委会 ===\n";
    $quUser = (int)$pdo->query('SELECT id FROM project_users WHERE is_active=1 AND employee_id=' . $qu)->fetchColumn();
    $check($quUser > 0, '曲俊泽有登录账号');
    [$error, , $html] = $run(['project_user_id' => $quUser], 'GET');
    $check($error === '' && strpos($html, 'id="c-' . (int)$id . '"') !== false, '监委会可打开并看到财务录入的记录');
    [$error] = $run(['project_user_id' => $quUser], 'POST', ['action' => 'create', 'owner_employee_id' => $qu, 'record_date' => '2026-09-20', 'category' => '本人建议', 'conclusion' => 'approved', 'bonus_delta' => '100', 'reviewer_employee_id' => $employee('冯超')]);
    $check(mb_strpos($error, '本人的事项') !== false, '监委不能给自己的事项下结论');
    [$error, $pendingId] = $run(['project_user_id' => $quUser], 'POST', ['action' => 'create', 'owner_employee_id' => $luan, 'record_date' => '2026-09-20', 'category' => '董事长发现bug', 'conclusion' => 'approved', 'bonus_delta' => '300', 'reviewer_employee_id' => $qu]);
    $check($error === '' && $pendingId > 0, '监委为董事长登记已完成的 bug 奖励');
    $yuna = (int)$pdo->query("SELECT id FROM project_users WHERE username='yuna'")->fetchColumn();
    $check(!pg_member(['type' => 'employee', 'employee_id' => (int)$pdo->query('SELECT employee_id FROM project_users WHERE id=' . $yuna)->fetchColumn()]), '普通客服不是管理层成员（页面 403）');

    echo "=== 与董事长目标额度隔离 ===\n";
    $rotation = pg_active_rotation('2026-09-20');
    if ($rotation) {
        $pool = pg_chair_pool($rotation['start_date']);
        $withoutFilter = (float)$pdo->query("SELECT COALESCE(SUM(GREATEST(bonus_delta,0)),0) FROM project_governance_records WHERE review_state='approved' AND owner_employee_id=" . (int)$rotation['chair_employee_id'] . " AND record_date>='" . $rotation['start_date'] . "'")->fetchColumn();
        $check((int)$rotation['chair_employee_id'] !== $luan || $pool['approved_reward'] <= $withoutFilter - 300, '董事长的 bug 奖励不占用董事长目标额度');
    } else echo "  （当前无轮值任期，跳过）\n";

    $_SERVER['SCRIPT_NAME'] = '/project/governance.php'; $_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = ['kind' => 'contribution']; $_SESSION = ['project_user_id' => $quUser];
    ob_start(); include __DIR__ . '/../project/governance.php'; $html = ob_get_clean();
    $check(strpos($html, 'record-' . (int)$id) !== false, '事项台账仍显示财务录入的记录');

    $pdo->rollBack();
    echo "\n=== 建议 / Bug 奖励台账全部通过，测试数据已回滚 ===\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
