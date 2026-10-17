<?php
// 网站定制：订单已有前端、没有后端，后端本人导入表格时作为后端加入（原先会被“请由财务核对”整批拦下）。
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
    $dong = $actorOf('董旭'); $yu = $actorOf('于海波'); $liuShuai = $E('刘帅');
    $check($dong && $yu && $liuShuai, '董旭、于海波、刘帅存在');
    $nb = "33190$tag" . '55551';
    $webRow = function ($front, $back) use ($nb, $stateKey, $tag) { return ['日期' => '2026.9.5', '店铺' => '美呀美', '付款昵称' => 'nickB' . $tag, '订单编号' => $nb, '售价' => '300', $stateKey => '已完成', '客服' => '董旭', '前端（技术）' => $front, '后端' => $back, '域名使用' => '否']; };
    [$pv, $imp, $err] = $importAs($dong, 'AI网站定制', $csvOf('AI网站定制', [$webRow('刘帅', '')]));
    $check($imp === 1, '客服先建单（前端刘帅，没有后端）：' . ($pv[0]['error'] ?? $err));
    [$pv, $imp, $err] = $importAs($yu, 'AI网站定制', $csvOf('AI网站定制', [$webRow('刘帅', '于海波')]));
    $check(!empty($pv[0]['base_valid']) && ($pv[0]['join_group'] ?? '') === 'backend' && $imp === 1, '后端本人导入：作为后端加入：' . ($pv[0]['error'] ?? $err));
    $ppl = $participants($nb); $roles = []; foreach ($ppl as $p) $roles[$p['name']] = $p['r'];
    $check(($roles['于海波'] ?? '') === '后端' && ($roles['刘帅'] ?? '') === '前端' && count($ppl) === 3, '于海波记为后端，刘帅仍是前端，客服不变：' . json_encode($roles, JSON_UNESCAPED_UNICODE));
    [$pv, $imp, $err] = $importAs($yu, 'AI网站定制', $csvOf('AI网站定制', [$webRow('刘帅', '于海波')]));
    $check(count($participants($nb)) === 3, '重复上传不会重复加入');
    echo "
=== 后端加入测试全部通过 ===
";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "
");
    exit(1);
} finally {
    foreach ($stored as $s) @unlink(ps_private_dir('imports') . '/' . basename($s) . '.php');
}
