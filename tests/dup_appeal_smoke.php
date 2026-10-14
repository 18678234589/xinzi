<?php
// 森动备案：二次备案没有订单号 / 售价也能识别（域名＋联系微信号＋月份生成内部号，同域名重复各算一单），业务列定类型，首次备案未填成本给提示。
// 只允许本地临时库：DB_HOST=127.0.0.1 DB_PORT=13399 php tests/dup_appeal_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
if ((string)DB_PORT !== '13399' || DB_HOST !== '127.0.0.1') { fwrite(STDERR, "拒绝运行：本测试会提交数据，只能连本地临时库（DB_PORT=13399）\n"); exit(2); }

$pdo = db();
require_once __DIR__ . '/require_isolated_database.php';
require_isolated_test_database($pdo);
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
$sk = '状态(填已完成/未完成)';
require_once __DIR__ . '/../includes/ProjectDupAppeal.php';
$cleanup = [];
try {
    $luan = $actorOf('栾鑫');
    $check($luan !== false && $luan !== null, '栾鑫账号存在');
    $d1 = "dup$tag-a.com"; $d2 = "dup$tag-b.com";
    $row = function ($shop, $nick, $domain, $amount = '100') use ($sk) { return ['日期' => '2026.9.7', '店铺' => $shop, '业务' => '备案', '付款昵称' => $nick, '售价' => $amount, '成本' => '80', $sk => '已完成', '域名' => $domain]; };
    // 1 首次导入：微信、没有订单号 → 生成内部号
    [$pv1, $imp1, $err1] = $importAs($luan, '森动备案', $csvOf('森动备案', [$row('微信', 'cust' . $tag, $d1)]));
    $check($err1 === '' && $imp1 === 1 && strpos($pv1[0]['order_no'], 'WX-') === 0, '首次导入 1 单，内部号 WX-…：' . ($pv1[0]['order_no'] ?? ''));
    $firstNo = $pv1[0]['order_no'];
    $cnt = function () use ($pdo, $d1, $d2) { $q = $pdo->prepare("SELECT COUNT(*) FROM project_orders o JOIN project_order_details d ON d.order_id=o.id WHERE o.project_type='森动备案' AND (d.details_json LIKE ? OR d.details_json LIKE ?)"); $q->execute(['%' . $d1 . '%', '%' . $d2 . '%']); return (int)$q->fetchColumn(); };
    $check($cnt() === 1, '库里 1 单');
    // 2 同日期、同金额、同昵称，只是店铺换了写法（微信 → 科恒扫码收款）：沿用已有订单，不新建
    [$pv2, $imp2, $err2] = $importAs($luan, '森动备案', $csvOf('森动备案', [$row('科恒扫码收款', 'cust' . $tag, $d1)]));
    $check($pv2[0]['order_no'] === $firstNo && !empty($pv2[0]['dup_adopted']) && $cnt() === 1, '店铺换了写法重传：沿用已有订单 ' . $firstNo . '，没有新建（' . $err2 . '）');
    $check(mb_strpos((string)$pv2[0]['warning'], '已按同一订单处理') !== false && strpos((string)$pv2[0]['dup_adopted']['url'], 'dup_appeals.php') !== false, '预览里有说明和“去申诉”链接');
    // 3 另一位客户（昵称、域名都不同），同日期同金额：独立订单
    [$pv3, $imp3, $err3] = $importAs($luan, '森动备案', $csvOf('森动备案', [$row('微信', 'other' . $tag, $d2)]));
    $check(empty($pv3[0]['dup_adopted']) && $pv3[0]['order_no'] !== $firstNo && $cnt() === 2, '昵称和域名都不同的另一位客户：独立订单（库里 2 单）');
    // 4 申诉：理由太短 / 没有证据被拒；写清理由并上传证据后提交，财务通过，再传同一行才作为独立订单
    $ev = tempnam(sys_get_temp_dir(), 'ev'); file_put_contents($ev, 'png-bytes');
    $in = ['business' => '森动备案', 'existing' => (int)$pdo->query("SELECT id FROM project_orders WHERE order_no=" . $pdo->quote($firstNo))->fetchColumn(), 'date' => '2026-09-07', 'amount' => '100', 'nickname' => 'cust' . $tag, 'domain' => $d1, 'shop' => '科恒扫码收款', 'reason' => '短'];
    $file = ['tmp_name' => $ev, 'name' => 'proof.png', 'error' => UPLOAD_ERR_OK, 'size' => 9];
    $threw = false; try { pda_submit($luan, $in, $file); } catch (RuntimeException $e) { $threw = true; }
    $check($threw, '理由写得太短：不能提交');
    $in['reason'] = '这是同一位客户的第二个网站，另一笔独立付款，域名不同，有付款截图';
    $threw = false; try { pda_submit($luan, $in, ['error' => UPLOAD_ERR_NO_FILE]); } catch (RuntimeException $e) { $threw = true; }
    $check($threw, '没有上传证据：不能提交');
    $aid = pda_submit($luan, $in, $file); $cleanup[] = $aid;
    $check($aid > 0 && pda_pending_count() >= 1, '写明理由并上传证据：申诉提交成功，待财务处理');
    [$pv4] = $importAs($luan, '森动备案', $csvOf('森动备案', [$row('科恒扫码收款', 'cust' . $tag, $d1)]));
    $check($pv4[0]['order_no'] === $firstNo, '申诉没通过前：仍按同一订单处理');
    $threw = false; try { pda_handle($aid, $luan, 'approved', ''); } catch (RuntimeException $e) { $threw = true; }
    $check($threw, '业务人员不能自己通过申诉');
    $finance = ['type' => 'admin', 'id' => 1, 'role' => 'finance', 'employee_id' => 0];
    pda_handle($aid, $finance, 'approved', '证据属实');
    [$pv5, $imp5] = $importAs($luan, '森动备案', $csvOf('森动备案', [$row('科恒扫码收款', 'cust' . $tag, $d1)]));
    $check($pv5[0]['order_no'] !== $firstNo && empty($pv5[0]['dup_adopted']) && $cnt() === 3, '申诉通过后重传：作为独立订单导入（库里 3 单）：' . $pv5[0]['order_no']);
    [$pv6] = $importAs($luan, '森动备案', $csvOf('森动备案', [$row('科恒扫码收款', 'cust' . $tag, $d1)]));
    $check($pv6[0]['order_no'] === $pv5[0]['order_no'] && $cnt() === 3, '再传一次：不重复建单');
    @unlink($ev);
    echo "全部通过
";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "
"); $failed = true;
} finally {
    foreach ($stored as $sf) @unlink(ps_private_dir('imports') . '/' . basename($sf) . '.php');
    require_once __DIR__ . '/../includes/ProjectOrderTrash.php';
    $q = $pdo->prepare("SELECT o.id FROM project_orders o JOIN project_order_details d ON d.order_id=o.id WHERE o.project_type='森动备案' AND d.details_json LIKE ?"); $q->execute(['%dup' . $tag . '%']);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $oid) { foreach (POT_TABLES as $t) { try { $pdo->exec("DELETE FROM $t WHERE order_id=" . (int)$oid); } catch (Throwable $e) {} } $pdo->exec('DELETE FROM project_orders WHERE id=' . (int)$oid); }
    foreach ($cleanup as $aid) $pdo->exec('DELETE FROM project_dup_appeals WHERE id=' . (int)$aid);
    if (!empty($failed)) exit(1);
}
