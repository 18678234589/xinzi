<?php
// 重新上传覆盖：同号订单再次上传，表格里改过的店铺 / 日期 / 付款昵称 / 商标名称 / 办理事项以本次表格为准；已审核订单不动；商标提交专员的表只写资料专员时按本岗位加入。
// 只允许本地临时库：DB_HOST=127.0.0.1 DB_PORT=13399 php tests/import_overwrite_smoke.php
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
$csvOf = function ($business, array $rows) { $head = ps_business_import_headers($business); $out = implode(',', $head) . "\n"; foreach ($rows as $row) { $cells = []; foreach ($head as $h) $cells[] = $row[$h] ?? ''; $out .= implode(',', $cells) . "\n"; } return $out; };
$sk = '状态(填已完成/未完成)';
$orderOf = function ($no) use ($pdo) { $q = $pdo->prepare("SELECT o.id,o.shop,o.order_date,o.customer_name,o.settlement_status,s.payment_nickname,d.details_json FROM project_orders o LEFT JOIN project_order_sources s ON s.order_id=o.id LEFT JOIN project_order_details d ON d.order_id=o.id WHERE o.order_no=?"); $q->execute([$no]); $r = $q->fetch(); if ($r) $r['d'] = json_decode($r['details_json'] ?: '{}', true) ?: []; return $r; };
try {
    passthru('php ' . escapeshellarg(__DIR__ . '/../tools/seed_trademark_costs.php') . ' --commit > /dev/null', $rc);
    $qin = $actorOf('秦婷婷'); $hui = $actorOf('王慧资');
    $check($qin && $hui, '账号存在');
    $n1 = "OW$tag-1"; $n2 = "OW$tag-2"; $n3 = "OW$tag-3";
    $base = ['售价' => '660', $sk => '已完成', '客服' => '秦婷婷'];
    $row = function ($no, $over = []) use ($base, $sk) { return ['日期' => '2026.9.5', '店铺' => '微信', '付款昵称' => '旧昵称', '订单编号' => $no, '商标名称' => '旧商标 5类', '商标个数' => '2', '办理事项' => '注册', '网报类型' => '网报'] + $base; };
    [$pv0, $imp0, $err0] = $importAs($qin, '商标', $csvOf('商标', [$row($n1), $row($n2)]));
    $o = $orderOf($n1);
    if (!$o) echo '  首次上传失败原因：' . json_encode(array_map(function ($r) { return [$r['status'] ?? '', $r['error'] ?? '', $r['warning'] ?? '', $r['base_valid'] ?? null]; }, $pv0), JSON_UNESCAPED_UNICODE) . "
";
    $check($o && $o['shop'] === '微信' && $o['order_date'] === '2026-09-05', '首次上传已建单');

    echo "=== 一、重新上传改过店铺 / 日期 / 昵称 / 商标名称 / 办理事项 → 覆盖 ===\n";
    $new = ['日期' => '2026.9.6', '店铺' => '美呀美', '付款昵称' => '新昵称', '商标名称' => '新商标 9类', '网报类型' => '加急'];
    [$pv, $imp, $err] = $importAs($qin, '商标', $csvOf('商标', [array_merge($row($n1), $new)]));
    $o = $orderOf($n1);
    $check($o['shop'] === '美呀美' && $o['order_date'] === '2026-09-06' && $o['payment_nickname'] === '新昵称', '店铺 / 日期 / 付款昵称已更新：' . json_encode([$o['shop'], $o['order_date'], $o['payment_nickname']], JSON_UNESCAPED_UNICODE) . ($pv[0]['error'] ?? $err));
    $check(mb_strpos((string)$o['d']['trademark_name'], '新商标') !== false && $o['d']['service_type'] === '加急', '商标名称、网报类型已更新：' . json_encode($o['d'], JSON_UNESCAPED_UNICODE));
    $check($o['d']['trademark_count'] === '2', '商标件数不被客服表改动');
    $o2 = $orderOf($n2); $check($o2['shop'] === '微信' && $o2['payment_nickname'] === '旧昵称', '没重传的订单不受影响');
    $a = $pdo->prepare("SELECT COUNT(*) FROM project_audit_logs WHERE action IN ('import_overwrite_basics','import_overwrite_details') AND entity_id=?"); $a->execute([$o['id']]);
    $check((int)$a->fetchColumn() >= 2, '覆盖前后的值记入了审计');

    echo "=== 二、已审核订单不被覆盖 ===\n";
    $pdo->prepare("UPDATE project_orders SET settlement_status='approved' WHERE order_no=?")->execute([$n2]);
    $importAs($qin, '商标', $csvOf('商标', [array_merge($row($n2), $new)]));
    $o2 = $orderOf($n2); $check($o2['shop'] === '微信' && $o2['payment_nickname'] === '旧昵称', '已审核订单保持原样');

    echo "=== 三、提交专员的表只写了资料专员：按提交专员加入，不再当成他人订单 ===\n";
    $r3 = array_merge($row($n3), ['资料专员' => '王庆美']);
    [$pv, $imp, $err] = $importAs($hui, '商标', $csvOf('商标', [$r3]));
    $check(!empty($pv[0]['base_valid']) && $imp === 1, '王慧资的表（资料专员=王庆美）可导入：' . ($pv[0]['error'] ?? $err));
    $p = $pdo->prepare("SELECT e.name,p.role_name FROM project_participants p JOIN project_orders o ON o.id=p.order_id JOIN employees e ON e.id=p.employee_id WHERE o.order_no=? AND p.commission_group='technical' ORDER BY p.id"); $p->execute([$n3]);
    $roles = $p->fetchAll(PDO::FETCH_KEY_PAIR);
    $check(($roles['王庆美'] ?? '') === '资料专员' && ($roles['王慧资'] ?? '') === '提交专员', '订单上：王庆美=资料专员、王慧资=提交专员：' . json_encode($roles, JSON_UNESCAPED_UNICODE));
    echo "=== 四、订单已由客服和资料专员建好，提交专员的表（资料专员=王庆美）加入已有订单 ===\n";
    $n4 = "OW$tag-4"; $qing = $actorOf('王庆美');
    $importAs($qin, '商标', $csvOf('商标', [$row($n4)]));
    $importAs($qing, '商标', $csvOf('商标', [array_merge($row($n4), ['资料专员' => '王庆美'])]));
    [$pv, $imp, $err] = $importAs($hui, '商标', $csvOf('商标', [array_merge($row($n4), ['资料专员' => '王庆美'])]));
    $check(!empty($pv[0]['base_valid']) && $imp === 1, '提交专员的表可导入已有订单：' . ($pv[0]['error'] ?? $err));
    $p->execute([$n4]); $roles = $p->fetchAll(PDO::FETCH_KEY_PAIR);
    $check(($roles['王庆美'] ?? '') === '资料专员' && ($roles['王慧资'] ?? '') === '提交专员', '已有订单上两个专员各占一个岗位：' . json_encode($roles, JSON_UNESCAPED_UNICODE));
    echo "=== 五、客服本人更正自己写错的售价（草稿、无收款）→ 覆盖；有收款的不能改 ===\n";
    $n5 = "OW$tag-5"; $n6 = "OW$tag-6"; $priceOf = function ($no) use ($pdo) { $q = $pdo->prepare('SELECT contract_amount FROM project_orders WHERE order_no=?'); $q->execute([$no]); return (float)$q->fetchColumn(); };
    $importAs($qin, '商标', $csvOf('商标', [$row($n5), $row($n6)]));
    [$pv, $imp, $err] = $importAs($qin, '商标', $csvOf('商标', [array_merge($row($n5), ['售价' => '560'])]));
    $check(!empty($pv[0]['base_valid']) && $priceOf($n5) === 560.0, '售价 660 → 560：客服本人更正成功：' . ($pv[0]['error'] ?? $err));
    $check(mb_strpos((string)$pv[0]['warning'], '售价将按本次表格更正') !== false, '预览里提示“售价将按本次表格更正”');
    $pdo->prepare("UPDATE project_orders SET receipt_amount=100 WHERE order_no=?")->execute([$n6]);
    [$pv, $imp, $err] = $importAs($qin, '商标', $csvOf('商标', [array_merge($row($n6), ['售价' => '560'])]));
    $check(empty($pv[0]['base_valid']) && $priceOf($n6) === 660.0, '已有收款的订单：仍须财务核对，售价不变');
    echo "\n=== 重新上传覆盖测试全部通过 ===\n";
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
finally { foreach ($stored as $s) @unlink(ps_private_dir('imports') . '/' . basename($s) . '.php'); }
