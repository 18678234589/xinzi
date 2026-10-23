<?php
// 订单回收站页面能正常打开（曾因缺少依赖整页 500），并能列出已删订单。只允许本地隔离库。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectSettlement.php';
require_once __DIR__ . '/../includes/ProjectOrderTrash.php';
require_once __DIR__ . '/require_isolated_database.php';
$pdo = db(); require_isolated_test_database($pdo);
if (DB_HOST !== '127.0.0.1' || (string)DB_PORT !== '13399') throw new RuntimeException('只允许本地隔离库');
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('FAIL ' . $label); echo "PASS $label\n"; };
pot_ensure(); // 建表是 DDL，须在事务之前
$pdo->prepare("INSERT INTO admins (username,password) VALUES (?,?)")->execute(['trashadm' . bin2hex(random_bytes(2)), md5('x')]);
$adminId = (int)$pdo->lastInsertId();
$orderNo = 'TRASH-' . bin2hex(random_bytes(4));
try {
    $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,receipt_amount,order_date,delivery_status,settlement_status) VALUES (?,'回收站客户','商标','普通订单','测试',100,100,'2026-09-10','finished','draft')")->execute([$orderNo]);
    $orderId = (int)$pdo->lastInsertId();
    pot_trash($orderId, $orderNo, ['type' => 'admin', 'id' => $adminId, 'role' => 'finance', 'employee_id' => null]);
    $pdo->prepare('DELETE FROM project_orders WHERE id=?')->execute([$orderId]);
    // 在独立进程里以财务身份渲染页面（页面会 exit/redirect，不能 include 进测试进程）
    $runner = sys_get_temp_dir() . '/trash_page_' . bin2hex(random_bytes(3)) . '.php';
    file_put_contents($runner, "<?php chdir('" . addslashes(dirname(__DIR__)) . "/project');\n\$_SERVER['REQUEST_METHOD']='GET';\n\$_SERVER['SCRIPT_NAME']='/project/trash.php';\nsession_id('trashtest');session_start();\$_SESSION=['admin_id'=>$adminId,'auth_version'=>0];session_write_close();\nrequire dirname('" . addslashes(dirname(__DIR__)) . "/project/trash.php').'/trash.php';\n");
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d log_errors=0 ' . escapeshellarg($runner) . ' 2>&1');
    @unlink($runner);
    $check(strpos((string)$out, '订单回收站') !== false && strpos((string)$out, '</html>') !== false, '回收站页面正常渲染');
    $check(strpos((string)$out, $orderNo) !== false, '页面列出已删除的订单');
    $check(strpos((string)$out, 'Fatal error') === false && strpos((string)$out, 'Uncaught') === false, '页面无致命错误');
    $no = pot_restore((int)$pdo->query("SELECT id FROM project_order_trash WHERE order_no=" . $pdo->quote($orderNo))->fetchColumn(), ['type' => 'admin', 'id' => $adminId, 'role' => 'finance', 'employee_id' => null]);
    $check($no === $orderNo && (int)$pdo->query("SELECT COUNT(*) FROM project_orders WHERE order_no=" . $pdo->quote($orderNo))->fetchColumn() === 1, '一键还原后订单回到订单表');
    echo "OK\n";
} finally {
    $pdo->prepare('DELETE FROM project_orders WHERE order_no=?')->execute([$orderNo]);
    $pdo->prepare('DELETE FROM project_order_trash WHERE order_no=?')->execute([$orderNo]);
    $pdo->prepare('DELETE FROM admins WHERE id=?')->execute([$adminId]);
    @unlink(sys_get_temp_dir() . '/sess_trashtest');
}
