<?php
// 森动备案：二次备案没有订单号 / 售价也能识别（域名＋联系微信号＋月份生成内部号，同域名重复各算一单），业务列定类型，首次备案未填成本给提示。
// 只允许本地临时库：DB_HOST=127.0.0.1 DB_PORT=13399 php tests/import_filing_smoke.php
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
$sk = '状态(填已完成/未完成)';
try {
    $luan = $actorOf('栾鑫');
    $check($luan !== false && $luan !== null, '栾鑫账号存在');
    $check(in_array('成本', ps_business_import_headers('森动备案'), true), '森动备案模板有“成本”列');
    $pdo->prepare("INSERT INTO project_cost_templates (category,business_scope,name,specification,unit,price_mode,cost_kind,price,requires_proof,auto_approve,version) VALUES ('other','森动备案','森动备案成本','首次备案 · 每单','单','fixed','one_time',80,0,1,1)")->execute();
    $d1 = "t$tag-a.com"; $d2 = "t$tag-b.com";
    $rows = [
        ['日期' => '2026.9.5', '店铺' => '微信', '业务' => '备案', '付款昵称' => 'nickE' . $tag, '售价' => '100', '成本' => '80', $sk => '已完成', '微信交易流水号' => 'WXE' . $tag, '联系微信号' => 'nickE' . $tag, '域名' => $d1],
        ['日期' => '2026.9.6', '店铺' => '微信', '业务' => '备案', '付款昵称' => 'nickF' . $tag, '售价' => '100', $sk => '已完成', '微信交易流水号' => 'WXF' . $tag, '联系微信号' => 'nickF' . $tag, '域名' => $d2],
        ['日期' => '2026.9.10', '业务' => '二次备案', $sk => '已完成', '联系微信号' => 'nickE' . $tag, '域名' => $d1],
        ['日期' => '2026.9.12', '业务' => '二次备案', $sk => '已完成', '联系微信号' => 'nickE' . $tag, '域名' => $d1],
        ['日期' => '2026.9.12', '业务' => '二次备案', $sk => '已完成', '联系微信号' => 'nickG' . $tag, '域名' => "t$tag-c.com", '成本' => '30'],
        ['日期' => '2026.9.8', '店铺' => '微信', '业务' => '备案', '付款昵称' => 'nickH' . $tag, '售价' => '100', '成本' => '0', $sk => '已完成', '微信交易流水号' => 'WXH' . $tag, '联系微信号' => 'nickH' . $tag, '域名' => "t$tag-d.com"],
        ['日期' => '2026.9.30'],
    ];
    $csv = $csvOf('森动备案', $rows);
    [$pv, $imp, $err] = $importAs($luan, '森动备案', $csv);
    $check($err === '' && count($pv) === 6, '6 行订单（尾部只有日期的行忽略）：行数 ' . count($pv) . ' ' . $err);
    $check($imp === 6, '全部导入：' . $imp . ' ' . implode('；', array_map(function ($r) { return $r['error'] ?? ''; }, $pv)));
    $kinds = array_column($pv, 'order_kind');
    $check($kinds === ['备案', '备案', '二次备案', '二次备案', '二次备案', '备案'], '订单类型按“业务”列：' . implode(',', $kinds));
    $nos = array_column($pv, 'order_no');
    $check(count(array_unique($nos)) === 6 && strpos($nos[2], 'EB-') === 0 && strpos($nos[3], 'EB-') === 0, '同月同域名的两次二次备案各一单，号不同：' . implode(',', array_slice($nos, 2)));
    $check(mb_strpos((string)$pv[1]['warning'], '成本中心') !== false && mb_strpos((string)$pv[0]['warning'], '没填成本') === false, '首次备案：填了成本不提示，没填提示将按成本中心补录');
    $q = $pdo->prepare("SELECT order_kind,COUNT(*) FROM project_orders WHERE order_no IN (" . implode(',', array_fill(0, 6, '?')) . ") GROUP BY order_kind");
    $q->execute($nos);
    $by = $q->fetchAll(PDO::FETCH_KEY_PAIR);
    $check(($by['备案'] ?? 0) == 3 && ($by['二次备案'] ?? 0) == 3, '落库：备案 3、二次备案 3');
    $cost = $pdo->prepare("SELECT COALESCE(SUM(amount),0), COUNT(*) FROM project_costs WHERE order_id=(SELECT id FROM project_orders WHERE order_no=?)");
    $cost->execute([$nos[0]]); [$c0, $n0] = $cost->fetch(PDO::FETCH_NUM);
    $cost->execute([$nos[1]]); [$c1, $n1] = $cost->fetch(PDO::FETCH_NUM);
    $cost->execute([$nos[2]]); [$c2, $n2] = $cost->fetch(PDO::FETCH_NUM);
    $check((float)$c0 === 80.0 && (int)$n0 === 1, '表里写了成本 80：按表格记 1 笔，不重复补录');
    $check((float)$c1 === 80.0 && (int)$n1 === 1, '表里没写成本：自动按成本中心模板补录 ¥80');
    $check((int)$n2 === 0, '二次备案表里没填成本：不带成本，也不套用首次备案的 ¥80');
    $cost->execute([$nos[4]]); [$c4, $n4] = $cost->fetch(PDO::FETCH_NUM);
    $check((int)$n4 === 0, '二次备案没有成本：表里即使写了 30 也不记');
    $cost->execute([$nos[5]]); [$c5, $n5] = $cost->fetch(PDO::FETCH_NUM);
    $check((int)$n5 === 0, '首次备案表里明确写 0：以表格为准，不再套用成本中心 ¥80');
    // 之前没带成本上传的老订单：清掉成本后用带成本的表重传，补上表格成本（0 的仍无成本，没填的首次备案走成本中心）
    $pdo->prepare("DELETE FROM project_costs WHERE order_id IN (SELECT id FROM project_orders WHERE order_no IN (" . implode(',', array_fill(0, 6, '?')) . "))")->execute($nos);
    [$pv3, $imp3, $err3] = $importAs($luan, '森动备案', $csv);
    $cost->execute([$nos[0]]); [$d0, $m0] = $cost->fetch(PDO::FETCH_NUM);
    $cost->execute([$nos[1]]); [$d1c, $m1] = $cost->fetch(PDO::FETCH_NUM);
    $cost->execute([$nos[2]]); [, $m2] = $cost->fetch(PDO::FETCH_NUM);
    $cost->execute([$nos[4]]); [$d4, $m4] = $cost->fetch(PDO::FETCH_NUM);
    $cost->execute([$nos[5]]); [, $m5] = $cost->fetch(PDO::FETCH_NUM);
    $check($err3 === '' && (float)$d0 === 80.0 && (int)$m0 === 1, '重传补成本：表格 80 → 补 80（' . $err3 . '）');
    $check((float)$d1c === 80.0 && (int)$m1 === 1, '重传补成本：没填成本的首次备案 → 成本中心 ¥80');
    $check((int)$m2 === 0 && (int)$m5 === 0 && (int)$m4 === 0, '重传补成本：二次备案 / 首次备案写 0 → 无成本');
    [$pv4] = $importAs($luan, '森动备案', $csv);
    $cost->execute([$nos[0]]); [, $m0b] = $cost->fetch(PDO::FETCH_NUM);
    $check((int)$m0b === 1, '再重传不重复补成本');
    [$pv2, $imp2] = $importAs($luan, '森动备案', $csv);
    $n = $pdo->prepare("SELECT COUNT(*) FROM project_orders WHERE order_no IN (" . implode(',', array_fill(0, 6, '?')) . ")"); $n->execute($nos);
    $check((int)$n->fetchColumn() === 6, '整张表重传不重复建单');
    echo "\n=== 森动备案测试全部通过 ===\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
} finally {
    foreach ($stored as $s) @unlink(ps_private_dir('imports') . '/' . basename($s) . '.php');
}
