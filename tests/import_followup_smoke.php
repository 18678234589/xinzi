<?php
// 上传后待补全（2026-09）：空白 / 占位行略过；只写订单号的续行沿用上一行；状态列写金额按已到账；
// 缺订单号 / 日期的真实订单导入后记为待补全、发站内信、弹窗直接补填提交导入，勾选“不是订单”不再追踪。
// 事务内执行，结束回滚。php tests/import_followup_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';

$pdo = db();
$pdo->beginTransaction();
$stored = null;
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
try {
    $user = $pdo->query("SELECT u.id,u.employee_id,u.role FROM project_users u JOIN employees e ON e.id=u.employee_id WHERE e.name='翟建跃' AND u.is_active=1")->fetch();
    $actor = ['type' => 'employee', 'id' => (int)$user['id'], 'employee_id' => (int)$user['employee_id'], 'role' => $user['role']];
    $fixture = __DIR__ . '/fixtures/import_followup.csv';
    $stored = ps_private_store('imports', $fixture, 'followup_test_' . bin2hex(random_bytes(5)) . '.csv');
    $pdo->prepare("INSERT INTO project_import_files (business_name,original_name,stored_name,file_size,uploaded_by_type,uploaded_by_id,employee_id) VALUES ('小程序开发','待补全测试.csv',?,?,'employee',?,?)")->execute([$stored, filesize($fixture), $actor['id'], $actor['employee_id']]);
    $fileId = (int)$pdo->lastInsertId();
    $page = function ($post) use ($actor) {
        $_SESSION = array_merge($_SESSION ?? [], ['project_user_id' => $actor['id'], 'project_csrf' => 'test-csrf']);
        $_SERVER['SCRIPT_NAME'] = '/project/import.php';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET = ['business' => '小程序开发'];
        $_POST = ['csrf' => 'test-csrf', 'business' => '小程序开发'] + $post;
        $error = ''; $imported = 0; ob_start(); include __DIR__ . '/../project/import.php'; $html = ob_get_clean();
        return [$error, $imported, $html];
    };
    $_SESSION = [];
    [$error, , $html] = $page(['action' => 'repreview', 'file_id' => $fileId, 'all_sheets' => 1]);
    $check($error === '', '预览成功');
    $byLine = []; foreach ($_SESSION['project_import_preview'] as $r) $byLine[(int)$r['line']] = $r;
    $check(!isset($byLine[5]) && mb_strpos($html, '已自动略过 1 行') !== false, '只写了技术姓名、没有订单号和金额的空白行自动略过并提示');
    $check(!empty($byLine[3]['base_valid']) && $byLine[3]['order_date'] === '2026-09-01' && isset($byLine[3]['people']['customer_service']) && count($byLine[3]['people']['customer_service']) === 1 && mb_strpos($byLine[3]['warning'], '沿用第 2 行') !== false, '只写订单号的续行：日期、客服、技术沿用上一行');
    $check(!empty($byLine[6]['base_valid']) && $byLine[6]['delivery_status'] === 'finished' && mb_strpos($byLine[6]['warning'], '金额“740”') !== false, '状态列写金额 740：按已到账处理');
    $check(empty($byLine[4]['base_valid']) && empty($byLine[7]['base_valid']) && empty($byLine[8]['base_valid']), '缺订单号（第 4、8 行）/ 缺日期（第 7 行）的行未通过');

    [$error, $imported] = $page(['action' => 'commit']);
    $check($error === '' && $imported === 3, '先导入 3 行合格订单（' . $error . '）');
    $fu = $pdo->prepare("SELECT * FROM project_import_followups WHERE file_id=?"); $fu->execute([$fileId]); $followup = $fu->fetch();
    $rows = json_decode($followup['rows_json'], true);
    $check($followup && $followup['status'] === 'open' && array_column($rows, 'line') === [4, 7, 8], '缺订单号 / 日期的 3 行记为待补全');
    $check($rows[0]['need_order'] && !$rows[0]['need_date'] && $rows[1]['need_date'] && $rows[1]['suggested_date'] === '2026-09-03', '待补全行标明缺什么，缺日期的按上一行预填');
    $msg = $pdo->prepare("SELECT title,link FROM project_messages WHERE employee_id=? AND category='import_fix' ORDER BY id DESC LIMIT 1"); $msg->execute([$actor['employee_id']]); $message = $msg->fetch();
    $check($message && mb_strpos($message['title'], '3 行缺订单号或日期') !== false && $message['link'] === '/project/import.php?followup=' . $followup['id'], '发站内信：' . ($message['title'] ?? ''));

    // 弹窗在任意页面显示，带补填输入框
    $project_staff = ['name' => '翟建跃']; ob_start(); include __DIR__ . '/../includes/import_followup_modal.php'; $modal = ob_get_clean();
    $check(mb_strpos($modal, 'id="importFollowupModal"') !== false && mb_strpos($modal, 'name="fix_order_no[4]"') !== false && mb_strpos($modal, 'name="fix_date[7]"') !== false && mb_strpos($modal, 'value="2026-09-03"') !== false && mb_strpos($modal, '交易单号') !== false, '弹窗列出待补全行：订单号 / 日期输入框、微信交易单号找法');

    // 弹窗补填提交：第 4 行补订单号、第 7 行补日期、第 8 行勾选不是订单
    [$error, $imported, $html] = $page(['action' => 'followup', 'followup_id' => $followup['id'], 'fix_order_no' => [4 => 'FOLLOW-TEST-002'], 'fix_date' => [7 => '2026-09-03'], 'ignore' => [8 => 1]]);
    $check($error === '' && $imported === 2, '弹窗补填后直接导入 2 行（' . $error . '）');
    $fu->execute([$fileId]);
    $check($fu->fetch()['status'] === 'done', '全部处理完，待补全关闭，不再弹窗');
    $orders = $pdo->query("SELECT order_no,order_date FROM project_orders WHERE order_no LIKE 'FOLLOW-TEST-%' ORDER BY order_no")->fetchAll(PDO::FETCH_KEY_PAIR);
    $check(array_keys($orders) === ['FOLLOW-TEST-001', 'FOLLOW-TEST-002', 'FOLLOW-TEST-004', 'FOLLOW-TEST-005', 'FOLLOW-TEST-006'] && $orders['FOLLOW-TEST-006'] === '2026-09-03', '订单入库：补填的订单号与日期都已生效，“不是订单”的行未入库');
    ob_start(); include __DIR__ . '/../includes/import_followup_modal.php'; $modal = ob_get_clean();
    $check(trim($modal) === '', '处理完后不再显示弹窗');

    $pdo->rollBack();
    echo "\n=== 上传后待补全全部通过，测试数据已回滚 ===\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
} finally {
    if ($stored !== null) @unlink(ps_private_dir('imports') . '/' . basename($stored) . '.php');
}
