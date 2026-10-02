<?php
// 只读核对真实账号的列表结果，不执行超期自动审核、不生成结算快照。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
$GLOBALS['project_order_list_cli'] = true;
$fileIds = array_values(array_filter(array_map('intval', explode(',', $argv[1] ?? '353,349'))));
function iv_list($user, $get)
{
    $_SESSION = $user + ['ps_last_auto_finish_time' => time()];
    $_GET = $get; $_POST = []; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['SCRIPT_NAME'] = '/project/index.php';
    ob_start(); include __DIR__ . '/../project/index.php'; ob_end_clean();
    return ['orders' => count($orders), 'pages' => $totalPages, 'page_size' => count($pageOrders), 'ids' => array_column($orders, 'id')];
}
$admin = (int)db()->query('SELECT MIN(id) FROM admins')->fetchColumn();
$full = iv_list(['admin_id' => $admin], ['month' => '2026-09']);
$expected = (int)db()->query("SELECT COUNT(*) FROM project_orders WHERE order_date>='2026-09-01' AND order_date<'2026-10-01'")->fetchColumn();
if ($full['orders'] !== $expected) throw new RuntimeException('Finance list is truncated');
echo json_encode(['finance_september' => $full['orders'], 'expected' => $expected, 'pages' => $full['pages']]) . "\n";
foreach ($fileIds as $id) {
    $q = db()->prepare('SELECT uploaded_by_type,uploaded_by_id,employee_id FROM project_import_files WHERE id=?'); $q->execute([$id]); $f = $q->fetch();
    $login = [$f['uploaded_by_type'] === 'admin' ? 'admin_id' : 'project_user_id' => (int)$f['uploaded_by_id']];
    $result = iv_list($login, ['import_file' => $id]);
    foreach ($result['ids'] as $orderId) if (!ps_import_order_visible((int)$orderId, $actor = ps_actor())) throw new RuntimeException('Unowned order leaked');
    echo json_encode(['file' => $id, 'visible' => $result['orders'], 'pages' => $result['pages'], 'first_page' => $result['page_size']]) . "\n";
}
$report = ps_import_result_get($fileIds[0] ?? 0);
$orderIds = array_map('intval', $report['order_ids'] ?? []);
if ($orderIds) {
    $users = db()->query('SELECT DISTINCT u.id,u.employee_id FROM project_users u JOIN project_participants p ON p.employee_id=u.employee_id WHERE u.is_active=1 AND p.commission_group=\'technical\' AND p.order_id IN (' . implode(',', $orderIds) . ')')->fetchAll();
    foreach ($users as $user) {
        $result = iv_list(['project_user_id' => (int)$user['id']], ['month' => '2026-09']);
        $q = db()->prepare('SELECT DISTINCT order_id FROM project_participants WHERE employee_id=? AND order_id IN (' . implode(',', $orderIds) . ')'); $q->execute([(int)$user['employee_id']]);
        $expectedIds = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
        if (array_diff($expectedIds, array_map('intval', $result['ids']))) throw new RuntimeException('Assigned technician cannot see their order');
        echo json_encode(['technical_user' => (int)$user['id'], 'assigned_visible' => count($expectedIds)]) . "\n";
    }
}
