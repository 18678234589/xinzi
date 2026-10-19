<?php
// 看板页真实提交流程：合作人员在 project/dashboard.php 全选提交 → 财务在 project/settlement_requests.php 收到并导出 → 点已结算 → 订单标注已结算。只允许本地隔离库。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$step = $argv[1] ?? '';
chdir(dirname(__DIR__)); define('BASE_URL', '');
require_once 'config/database.php';
require_once 'tests/require_isolated_database.php';
$pdo = db(); require_isolated_test_database($pdo);
if (DB_HOST !== '127.0.0.1' || (string)DB_PORT !== '13399') throw new RuntimeException('只允许本地隔离库');
session_start();
$_SERVER['REQUEST_METHOD'] = 'POST'; $_SESSION['project_csrf'] = 'tok';
$row = $pdo->query("SELECT c.employee_id,c.payroll_month FROM project_commission_snapshots c JOIN project_users u ON u.employee_id=c.employee_id AND u.is_active=1 WHERE c.payroll_month < DATE_FORMAT(CURDATE(),'%Y-%m') GROUP BY c.employee_id,c.payroll_month HAVING COUNT(DISTINCT c.order_id)>=3 ORDER BY c.payroll_month DESC,c.employee_id LIMIT 1")->fetch();
$eid = (int)$row['employee_id']; $month = $row['payroll_month'];
$uid = (int)$pdo->query("SELECT id FROM project_users WHERE employee_id=$eid AND is_active=1 LIMIT 1")->fetchColumn();
$adminId = (int)$pdo->query('SELECT MIN(id) FROM admins')->fetchColumn();
if ($step === 'emp') { // 合作人员：看板页全选订单后提交
    $_SESSION['project_user_id'] = $uid; $_SERVER['SCRIPT_NAME'] = '/project/dashboard.php';
    require_once 'includes/ProjectSettlementRequest.php';
    $ids = array_column(array_filter(psr_orders($eid, $month), function ($o) { return $o['state'] === 'available' && empty($o['is_monthly']); }), 'order_id');
    $_POST = ['action' => 'request_settle', 'csrf' => 'tok', 'month' => $month, 'employee_id' => $eid, 'order_ids' => $ids];
    ob_start(); include 'project/dashboard.php'; echo ob_get_clean(), "\n";
    exit;
}
if ($step === 'fin') {
    $_SESSION['admin_id'] = $adminId; $_SERVER['SCRIPT_NAME'] = '/project/settlement_requests.php';
    $rid = (int)$pdo->query("SELECT id FROM project_settlement_requests WHERE status='pending' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $_POST = ['csrf' => 'tok', 'do' => $argv[2], 'ids' => [$rid], 'id' => $rid];
    include 'project/settlement_requests.php';
    exit;
}
echo "$eid|$month|$uid\n";
