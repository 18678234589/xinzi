<?php
// 订单审核提醒：授权审核人 / 财务侧栏的待审订单数；员工提交申请时同业务授权审核人收到站内信；月初汇总 job 只发给授权审核人。只允许本地隔离库。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/require_isolated_database.php';
require_once __DIR__ . '/../includes/ProjectBatchReview.php';
if ((string)DB_PORT !== '13399' || DB_HOST !== '127.0.0.1') { fwrite(STDERR, "拒绝运行：只能连本地临时库（DB_PORT=13399）\n"); exit(2); }
$pdo = db(); require_isolated_test_database($pdo); pbr_ensure();
$pdo->exec('CREATE TABLE IF NOT EXISTS project_batch_review_delegates (employee_id INT NOT NULL, business_name VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (employee_id, business_name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'); // 授权表由 tools/grant_batch_review_delegate.php 建，测试库补建
$tag = bin2hex(random_bytes(3));
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$emp = (int)$pdo->query("SELECT id FROM employees WHERE name='阎泸琪'")->fetchColumn();
$empUser = $pdo->query("SELECT id FROM project_users WHERE employee_id=$emp LIMIT 1")->fetchColumn();
$del = (int)$pdo->query("SELECT e.id FROM employees e JOIN project_users u ON u.employee_id=e.id WHERE e.id<>$emp AND e.name<>'' ORDER BY e.id LIMIT 1")->fetchColumn();
$delUser = (int)$pdo->query("SELECT id FROM project_users WHERE employee_id=$del LIMIT 1")->fetchColumn();
[$from] = pbr_review_window(); $month = substr($from, 0, 7);
$mine = ['type' => 'employee', 'id' => (int)$empUser, 'employee_id' => $emp, 'role' => 'technical'];
$delegate = ['type' => 'employee', 'id' => $delUser, 'employee_id' => $del, 'role' => 'technical'];
$finance = ['type' => 'admin', 'id' => 1, 'role' => 'finance', 'employee_id' => 0];
$ids = []; $reqId = 0;
try {
    $check($emp > 0 && $empUser && $del > 0 && $delUser, '测试账号存在');
    $before = pbr_reviewable_count($delegate);
    $check($before === 0, '没有授权时，待审数为 0');
    $pdo->prepare('INSERT INTO project_batch_review_delegates (employee_id,business_name) VALUES (?,?)')->execute([$del, '网站模板']);
    foreach ([1, 2] as $n) {
        $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,order_date,delivery_status,settlement_status,note) VALUES (?,'测试','网站模板','新订单','内部',100,?,'unfinished','draft','')")->execute(["RM$tag$n", $from]);
        $id = (int)$pdo->lastInsertId(); $ids[] = $id;
        $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'technical','模板技术',1)")->execute([$id, $emp]);
    }
    unset($_SESSION['pbr_cnt_' . $delUser . '_employee'], $_SESSION['pbr_cnt_1_admin']);
    $check(pbr_reviewable_count($delegate) >= 2, '授权后：授权审核人的待审订单数 ≥ 2（侧栏显示）');
    $check(pbr_reviewable_count($finance) >= pbr_reviewable_count($delegate), '财务的待审数不少于授权审核人');
    $check(pbr_reviewable_count($mine) === 0, '没有审核资格的合作人员待审数为 0');
    $reqId = pbr_submit($mine, $month, '测试');
    $msg = $pdo->prepare("SELECT title,link FROM project_messages WHERE employee_id=? AND dedupe_key=?"); $msg->execute([$del, 'pbr-submit:' . $reqId . ':' . $del]);
    $m = $msg->fetch();
");
    $check($m && strpos($m['link'], '/project/batch_review.php') === 0, '提交申请后，同业务授权审核人收到站内信');
    pbr_submit($mine, $month, '再提交');
    $n = $pdo->prepare("SELECT COUNT(*) FROM project_messages WHERE employee_id=? AND dedupe_key=?"); $n->execute([$del, 'pbr-submit:' . $reqId . ':' . $del]);
    $check((int)$n->fetchColumn() === 1, '重复提交不重复发信');
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 ' . escapeshellarg(__DIR__ . '/../jobs/batch_review_digest.php') . ' --dry 2>&1');
    $check(strpos((string)$out, $del . ': ') !== false && strpos((string)$out, '阎泸琪 2 单') !== false, '月初汇总列出人员和单数：' . trim(substr((string)$out, 0, 160)));
    echo "\n=== 订单审核提醒测试通过 ===\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n"); $rc = 1;
} finally {
    $pdo->prepare('DELETE FROM project_batch_review_delegates WHERE employee_id=? AND business_name=?')->execute([$del, '网站模板']);
    if ($reqId) { $pdo->prepare('DELETE FROM project_messages WHERE dedupe_key LIKE ?')->execute(['pbr-submit:' . $reqId . ':%']); $pdo->prepare('DELETE FROM project_batch_reviews WHERE id=?')->execute([$reqId]); }
    foreach ($ids as $id) { $pdo->prepare('DELETE FROM project_participants WHERE order_id=?')->execute([$id]); $pdo->prepare('DELETE FROM project_orders WHERE id=?')->execute([$id]); }
    if (!empty($rc)) exit($rc);
}
