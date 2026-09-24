<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectDepartmentImport.php';

$pdo = db();
$pdo->beginTransaction();
$stored = null;
try {
    $q = $pdo->query("SELECT u.id,u.employee_id,u.role FROM project_users u JOIN employees e ON e.id=u.employee_id JOIN project_user_businesses b ON b.user_id=u.id WHERE e.department='网站售后部' AND b.business_name='网站续费' AND u.is_active=1 ORDER BY u.id LIMIT 1");
    $user = $q->fetch();
    if (!$user) throw new RuntimeException('缺少网站售后续费账号');
    $actor = ['type' => 'employee', 'id' => (int)$user['id'], 'employee_id' => (int)$user['employee_id'], 'role' => $user['role']];
    $people = $pdo->query("SELECT id,name FROM employees WHERE department='网站售后部' AND id<>" . (int)$user['employee_id'] . ' ORDER BY id LIMIT 2')->fetchAll();
    if (count($people) !== 2) throw new RuntimeException('缺少两名网站售后参与人');
    $defaults = ps_department_import_people($actor, '网站续费', array_column($people, 'id'));
    $fixture = __DIR__ . '/fixtures/website_department.csv';
    $stored = ps_private_store('imports', $fixture, 'department_test_' . bin2hex(random_bytes(5)) . '.csv');
    $pdo->prepare("INSERT INTO project_import_files (business_name,original_name,stored_name,file_size,uploaded_by_type,uploaded_by_id,employee_id) VALUES ('网站续费','部门测试.csv',?,?,'employee',?,?)")
        ->execute([$stored, filesize($fixture), $actor['id'], $actor['employee_id']]);
    $fileId = (int)$pdo->lastInsertId();
    $_SESSION['project_user_id'] = $actor['id'];
    $_SESSION['project_import_scope'] = 'department';
    $_SESSION['project_import_people'] = $defaults;
    $_SERVER['SCRIPT_NAME'] = '/project/import.php';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['csrf' => ps_csrf_token(), 'action' => 'repreview', 'business' => '网站续费', 'scope' => 'department', 'file_id' => $fileId, 'all_sheets' => 1];
    ob_start(); include __DIR__ . '/../project/import.php'; $html = ob_get_clean();
    if (!empty($error)) throw new RuntimeException('部门预览失败：' . $error);
    $preview = $_SESSION['project_import_preview'] ?? [];
    if (count($preview) !== 1 || empty($preview[0]['base_valid']) || count($preview[0]['people']['customer_service']) !== 2) throw new RuntimeException('部门预览没有按默认参与人分单');
    if (strpos($html, '部门订单') === false) throw new RuntimeException('部门核对页面缺失');
    if (strpos($html, '部门订单上传') === false || strpos($html, '店铺交易流水') !== false) throw new RuntimeException('售后导航未指向新版部门入口');

    $_POST = ['csrf' => ps_csrf_token(), 'action' => 'commit', 'business' => '网站续费', 'scope' => 'department'];
    ob_start(); include __DIR__ . '/../project/import.php'; ob_end_clean();
    if (!empty($error)) throw new RuntimeException('部门提交失败：' . $error);
    $q = $pdo->prepare('SELECT id FROM project_orders WHERE order_no=?'); $q->execute(['DEPT-FLOW-TEST-20260924']);
    $orderId = (int)$q->fetchColumn();
    if (!$orderId || !ps_department_import_is_order($orderId)) throw new RuntimeException('部门订单未写入新版项目结算');
    $q = $pdo->prepare('SELECT COUNT(*) FROM project_participants WHERE order_id=? AND commission_group=?'); $q->execute([$orderId, 'customer_service']);
    if ((int)$q->fetchColumn() !== 2 || !ps_department_import_uploader_access($orderId, $actor['employee_id'])) throw new RuntimeException('部门分单或代录人查看权限缺失');
    unset($_SESSION['project_user_id'], $_SESSION['project_import_scope'], $_SESSION['project_import_people']);
    $pdo->rollBack();
    echo "网站售后部门 CSV 预览、多人默认分单与提交通过；测试数据已回滚\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
} finally {
    if ($stored !== null) @unlink(ps_private_dir('imports') . '/' . basename($stored) . '.php');
}
