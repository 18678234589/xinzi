<?php
require_once __DIR__ . '/../includes/ProjectGovernance.php';
$_SERVER['SCRIPT_NAME'] = '/project/governance_ideas.php';
$_SERVER['REQUEST_METHOD'] = 'GET';

$q = db()->query("SELECT m.governance_role,u.id AS user_id FROM project_governance_members m JOIN project_users u ON u.employee_id=m.employee_id AND u.is_active=1 WHERE m.is_active=1 ORDER BY m.employee_id");
$users = [];
foreach ($q->fetchAll() as $row) $users[$row['governance_role']] ??= (int)$row['user_id'];
if (empty($users['chair']) || empty($users['committee'])) throw new RuntimeException('缺少测试用管理层账号');

foreach (['chair','committee'] as $role) {
    $_SESSION['project_user_id'] = $users[$role];
    ob_start();
    include __DIR__ . '/../project/governance_ideas.php';
    $html = ob_get_clean();
    $hasPanel = strpos($html, 'id="penalties"') !== false;
    $hasEntry = strpos($html, '缺报核对与豁免') !== false;
    if ($hasPanel !== ($role === 'committee') || $hasEntry !== ($role === 'committee')) {
        throw new RuntimeException($role . ' 的缺报核对入口权限不正确');
    }
    if (strpos($html, 'name="action" value="create_idea"') === false) throw new RuntimeException($role . ' 无法提交脑洞');
}
echo "governance visibility smoke OK\n";
