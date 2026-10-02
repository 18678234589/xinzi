<?php
// CLI 只读页面测试。测试身份仅在本进程内，结束时丢弃，不建立可复用的登录凭证。
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = $argv[1] ?? dirname(__DIR__);
$mode = $argv[2] ?? 'partner';
$_SERVER['DOCUMENT_ROOT'] = $root;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/project/dashboard.php';
require_once $root . '/includes/auth.php';
if ($mode === 'finance') {
    $_SESSION = ['admin_id' => (int)db()->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetchColumn()];
    $_GET = ['employee_id' => 53, 'month' => '2026-09'];
    $expectedPerson = 53;
} else {
    $_SESSION = ['project_user_id' => (int)db()->query('SELECT id FROM project_users WHERE employee_id=84 AND is_active=1 ORDER BY id LIMIT 1')->fetchColumn()];
    // 合作方请求别人编号，仍须显示本人数据。
    $_GET = ['employee_id' => 53, 'month' => '2026-09'];
    $expectedPerson = 84;
}
ob_start();
try {
    require $root . '/project/dashboard.php';
    $html = ob_get_clean();
} catch (Throwable $error) {
    ob_end_clean();
    throw $error;
} finally {
    $_SESSION = [];
    session_abort();
}
if ($employeeId !== $expectedPerson) throw new RuntimeException('页面身份范围不正确');
foreach (['2026-09 预期总收入', '预期总分成', '固定服务费', '预期全勤奖', '查看预期收入组成与计算依据'] as $label) {
    if (strpos($html, $label) === false) throw new RuntimeException('页面缺少：' . $label);
}
if (strpos($html, '<span>订单净实收</span>') !== false || strpos($html, '<span>退款率</span>') !== false) throw new RuntimeException('旧卡片仍存在');
echo json_encode(['mode' => $mode, 'rendered_employee' => $employeeId, 'month' => $month,
    'total' => $expectedIncome['total'], 'commission' => $expectedIncome['commission'], 'html_bytes' => strlen($html),
    'new_cards_present' => true, 'old_cards_absent' => true, 'session_saved' => false], JSON_UNESCAPED_UNICODE) . "\n";
