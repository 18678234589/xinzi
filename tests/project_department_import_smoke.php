<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectDepartmentImport.php';

function department_check($condition, $message)
{
    if (!$condition) throw new RuntimeException($message);
}

$pdo = db();
$pdo->beginTransaction();
try {
    $adminId = (int)$pdo->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetchColumn();
    $q = $pdo->query("SELECT u.id user_id,u.employee_id,u.role,e.name,e.department FROM project_users u JOIN employees e ON e.id=u.employee_id WHERE e.department='网站售后部' ORDER BY e.id");
    $users = $q->fetchAll();
    department_check($adminId && count($users) >= 3, '需要财务及三名网站售后合作人员');
    $uploader = null;
    foreach ($users as $user) {
        $actor = ['type' => 'employee', 'id' => (int)$user['user_id'], 'employee_id' => (int)$user['employee_id'], 'role' => $user['role']];
        if (ps_department_import_allowed($actor, '网站续费')) { $uploader = $actor; break; }
    }
    department_check($uploader !== null, '网站售后续费人员未获部门代录权限');
    department_check(!ps_department_import_allowed($uploader, '小程序开发'), '部门代录不应扩大到其他业务');
    $peopleIds = array_slice(array_values(array_filter(array_map('intval', array_column($users, 'employee_id')), function ($id) use ($uploader) { return $id !== (int)$uploader['employee_id']; })), 0, 2);
    $people = ps_department_import_people($uploader, '网站续费', $peopleIds);
    department_check(count($people) === 2, '部门参与人选择失败');
    $rates = ps_department_renewal_rates('2026-09');
    $rateRule = null;
    foreach (ps_monthly_rules_for('2026-09') as $rule) if ($rule['rule_type'] === 'dept_share' && $rule['scope_business'] === '网站续费' && $rule['employee_id']) { $rateRule = $rule; break; }
    department_check($rateRule !== null && isset($rates[(int)$rateRule['employee_id']]), '续费比例未从规则中心读取');
    $changedParams = $rateRule['params'];
    $changedParams['rate'] = (float)$changedParams['rate'] + 0.001;
    $pdo->prepare('UPDATE project_monthly_rules SET params_json=? WHERE id=?')->execute([json_encode($changedParams, JSON_UNESCAPED_UNICODE), (int)$rateRule['id']]);
    $changedRates = ps_department_renewal_rates('2026-09');
    department_check(abs($changedRates[(int)$rateRule['employee_id']] - $rates[(int)$rateRule['employee_id']] - 0.001 * (float)($changedParams['share'] ?? 1)) < 0.000001, '规则中心改动后部门上传比例未同步');
    $outsider = (int)$pdo->query("SELECT id FROM employees WHERE department<>'网站售后部' ORDER BY id LIMIT 1")->fetchColumn();
    try { ps_department_import_people($uploader, '网站续费', [$outsider]); throw new RuntimeException('外部门人员不应可分单'); }
    catch (RuntimeException $expected) { if (strpos($expected->getMessage(), '不属于网站售后部') === false) throw $expected; }

    $orderNo = 'DEPT-TEST-' . bin2hex(random_bytes(5));
    $pdo->prepare("INSERT INTO project_orders (order_no,project_type,order_date,delivery_status) VALUES (?,'网站续费',CURDATE(),'finished')")->execute([$orderNo]);
    $orderId = (int)$pdo->lastInsertId();
    ps_intake_participants($orderId, ['customer_service' => $people], '网站续费');
    ps_department_import_record($orderId, $uploader);
    department_check(ps_department_import_is_order($orderId), '部门订单未标记');
    department_check(ps_department_import_uploader_access($orderId, (int)$uploader['employee_id']), '代录人无法查看部门订单');
    $rows = $pdo->query('SELECT employee_id,commission_group,group_weight FROM project_participants WHERE order_id=' . $orderId . ' ORDER BY employee_id')->fetchAll();
    department_check(count($rows) === 2 && $rows[0]['commission_group'] === 'customer_service' && abs((float)$rows[0]['group_weight'] - 0.5) < 0.001, '多人分单权重不正确');
    $view = ps_order($orderId, $uploader);
    department_check((int)$view['id'] === $orderId, '部门代录人订单访问失败');

    $_SESSION['admin_id'] = $adminId;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/project/import.php';
    $_GET = ['scope' => 'department', 'business' => '网站续费'];
    ob_start(); include __DIR__ . '/../project/import.php'; $html = ob_get_clean();
    department_check(strpos($html, '网站售后部门订单') !== false && strpos($html, 'name="dept_people[]"') !== false && strpos($html, 'name="scope" value="department"') !== false && strpos($html, '续费共享') !== false, '部门订单上传入口、参与人或规则比例未显示');
    department_check(strpos($html, '店铺交易流水') !== false && strpos($html, '店铺与订单') === false, '财务旧店铺入口未与项目订单区分');
    unset($_SESSION['admin_id']);
    $pdo->rollBack();
    echo "网站售后部门代录权限、多人分单、代录人访问及上传页面通过；测试数据已回滚\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}
