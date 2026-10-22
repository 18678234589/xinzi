<?php
// 导入回执：已审核而跳过的行显示原表日期 / 售价；与原单冲突的行单独计为“与原单不一致”，不再算“待补全”。
// 原说明：同一笔销售只记一次（跨业务金额相同并入原单）、多位客服共同接单、预览页在线补接单技术、部门代录默认本人、对公收款、店铺流水价裁决售价。
// 导入流程含建表语句会提前提交，所以只允许在本地临时库运行。DB_HOST=127.0.0.1 DB_PORT=13399 php tests/import_join_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
if ((string)DB_PORT !== '13399' || DB_HOST !== '127.0.0.1') { fwrite(STDERR, "拒绝运行：本测试会提交数据，只能连本地临时库（DB_PORT=13399）\n"); exit(2); }

$pdo = db();
$stored = [];
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$E = function ($name) use ($pdo) { $q = $pdo->prepare('SELECT id FROM employees WHERE name=? ORDER BY id LIMIT 1'); $q->execute([$name]); return (int)$q->fetchColumn(); };
$actorOf = function ($name) use ($pdo) { $q = $pdo->prepare("SELECT u.id,u.employee_id,u.role FROM project_users u JOIN employees e ON e.id=u.employee_id WHERE e.name=? AND u.is_active=1"); $q->execute([$name]); $u = $q->fetch(); return $u ? ['type' => 'employee', 'id' => (int)$u['id'], 'employee_id' => (int)$u['employee_id'], 'role' => $u['role']] : null; };
$upload = function ($actor, $business, $csv) use ($pdo, &$stored) {
    $tmp = tempnam(sys_get_temp_dir(), 'ws') . '.csv'; file_put_contents($tmp, $csv);
    $name = ps_private_store('imports', $tmp, 'avoid_' . bin2hex(random_bytes(5)) . '.csv'); $stored[] = $name; @unlink($tmp);
    $pdo->prepare("INSERT INTO project_import_files (business_name,original_name,stored_name,file_size,uploaded_by_type,uploaded_by_id,employee_id) VALUES (?,'测试.csv',?,?,'employee',?,?)")->execute([$business, $name, strlen($csv), $actor['id'], $actor['employee_id']]);
    return (int)$pdo->lastInsertId();
};
$run = function ($actor, $post) { $_SESSION = array_merge($_SESSION ?? [], ['project_user_id' => $actor['id'], 'project_csrf' => 'test-csrf']); $_SERVER['SCRIPT_NAME'] = '/project/import.php'; $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = $post + ['csrf' => 'test-csrf']; $GLOBALS['error'] = ''; $GLOBALS['imported'] = 0; ob_start(); include __DIR__ . '/../project/import.php'; ob_end_clean(); $GLOBALS['imported'] = $imported ?? 0; $GLOBALS['error'] = $error ?? ''; };
$importAs = function ($actor, $business, $csv) use ($upload, $run) {
    $fileId = $upload($actor, $business, $csv);
    $run($actor, ['action' => 'repreview', 'business' => $business, 'file_id' => $fileId, 'all_sheets' => 1]);
    $preview = $_SESSION['project_import_preview'] ?? [];
    $run($actor, ['action' => 'commit', 'business' => $business, 'auto_import' => 1]);
    return [$preview, $GLOBALS['imported'], $GLOBALS['error']];
};
$orders = function ($like) use ($pdo) { $q = $pdo->prepare('SELECT id,order_no,project_type,contract_amount FROM project_orders WHERE order_no LIKE ? ORDER BY id'); $q->execute([$like]); return $q->fetchAll(); };
$tag = bin2hex(random_bytes(3));
$csvOf = function ($business, array $rows) {
    $head = ps_business_import_headers($business);
    $out = implode(',', $head) . "\n";
    foreach ($rows as $row) { $cells = []; foreach ($head as $h) $cells[] = $row[$h] ?? ''; $out .= implode(',', $cells) . "\n"; }
    return $out;
};
$previewOf = function ($actor, $business, $csv, $extra = []) use ($upload, $run) {
    $fileId = $upload($actor, $business, $csv);
    $run($actor, ['action' => 'repreview', 'business' => $business, 'file_id' => $fileId, 'all_sheets' => 1] + $extra);
    return [$fileId, $_SESSION['project_import_preview'] ?? [], $GLOBALS['error']];
};
$participants = function ($orderNo) use ($pdo) {
    $q = $pdo->prepare('SELECT e.name,p.commission_group g,p.role_name r,p.group_weight w FROM project_participants p JOIN project_orders o ON o.id=p.order_id JOIN employees e ON e.id=p.employee_id WHERE o.order_no=? ORDER BY p.id');
    $q->execute([$orderNo]);
    return $q->fetchAll();
};
$stateKey = '状态(填已完成/未完成)';

try {
    $dong = $actorOf('董旭'); $liuShuai = $E('刘帅');
    $check($dong && $liuShuai, '董旭、刘帅存在');
    $nb = "33191$tag" . '66661';
    $row = function ($amount) use ($nb, $stateKey, $tag) { return ['日期' => '2026.9.5', '店铺' => '美呀美', '付款昵称' => 'nickC' . $tag, '订单编号' => $nb, '售价' => $amount, $stateKey => '已完成', '客服' => '董旭', '前端（技术）' => '刘帅', '域名使用' => '否']; };
    [$pv, $imp, $err] = $importAs($dong, 'AI网站定制', $csvOf('AI网站定制', [$row('300')]));
    $check($imp === 1, '先导入一单：' . ($pv[0]['error'] ?? $err));
    $pdo->prepare("UPDATE project_orders SET settlement_status='approved' WHERE order_no=?")->execute([$nb]);
    [$fid, $pv, $err] = $previewOf($dong, 'AI网站定制', $csvOf('AI网站定制', [$row('300')]));
    $check(($pv[0]['status'] ?? '') === '已导入过' && ($pv[0]['order_date'] ?? '') === '2026-09-05' && (string)($pv[0]['contract_amount'] ?? '') === '300', '已审核而跳过的行保留原表日期和售价：' . json_encode([$pv[0]['status'] ?? '', $pv[0]['order_date'] ?? '', $pv[0]['contract_amount'] ?? ''], JSON_UNESCAPED_UNICODE));
    $html = file_get_contents(__DIR__ . '/../project/import/view/render_63.php');
    $check(strpos($html, '已跳过') !== false && strpos($html, "in_array(\$row['status'] ?? '', ['已导入过', '他人订单'], true)") !== false, '页面对已跳过的行只标“已跳过”，不报缺日期 / 待补');
    // 回执分类
    $rows = [['line' => 1, 'order_no' => $nb, 'status' => '补充已有订单'], ['line' => 2, 'order_no' => $nb, 'status' => '已导入过'],
        ['line' => 3, 'order_no' => 'X', 'status' => '需处理', 'existing_snapshot' => ['id' => 1], 'error' => '原单与上传表的售价不一致，请由财务核对'],
        ['line' => 4, 'order_no' => '', 'status' => '需处理', 'error' => '缺订单号']];
    $rep = ps_import_result_save(0, $rows, [1], $dong, $dong, 1);
    $check($rep['pending'] === 1 && $rep['conflict'] === 1 && $rep['existing'] === 1, '回执：缺资料 1 行、与原单不一致 1 行，已导入过 1 行分别计数');
    $pdo->exec("DELETE FROM project_audit_logs WHERE entity_type='import_file' AND entity_id=0");
    echo "
=== 回执与跳过行测试全部通过 ===
";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "
");
    exit(1);
} finally {
    foreach ($stored as $s) @unlink(ps_private_dir('imports') . '/' . basename($s) . '.php');
    $pdo->prepare("DELETE FROM project_participants WHERE order_id IN (SELECT id FROM project_orders WHERE order_no=?)")->execute([$nb ?? '']);
}
