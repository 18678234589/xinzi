<?php
// “纯域名注册”程序套餐：价格取商务中国价；表格程序名称写“N年域名注册”时按域名后缀自动匹配；不再另选域名模板。
// 只允许本地临时库：DB_HOST=127.0.0.1 DB_PORT=13399 php tests/import_pure_domain_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../tools/seed_pure_domain_templates.php';
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
    foreach ($rows as $row) foreach (array_keys($row) as $k) if (!in_array($k, $head, true)) $head[] = $k; // 老写法的列（店铺 / 订单类型 / 模板技术 / 续费联系方式…）照常识别
    $out = implode(',', $head) . "\n";
    foreach ($rows as $row) { $cells = []; foreach ($head as $h) $cells[] = $row[$h] ?? ''; $out .= implode(',', $cells) . "\n"; }
    return $out;
};
$sk = '状态(填已完成/未完成)';

try {
    $song = $actorOf('高晶晶');
    pdt_seed($pdo);
    $check(pdt_seed($pdo) === 0, '重复执行不会重复添加套餐');
    $q = $pdo->query("SELECT specification,price FROM project_cost_templates WHERE category='program' AND name='纯域名注册'")->fetchAll(PDO::FETCH_KEY_PAIR);
    $check(count($q) === 16 && (float)$q['.com 1年（商务中国价）'] === 58.0 && (float)$q['.com 3年（商务中国价）'] === 232.0 && (float)$q['.cn 3年（商务中国价）'] === 109.0 && (float)$q['.net 2年（商务中国价）'] === 190.0, '套餐价格 = 商务中国首年价 + 续费价 × (年数-1)：' . json_encode($q, JSON_UNESCAPED_UNICODE));
    $base = ['日期' => '2026.10.8', '店铺' => '美呀美', '付款昵称' => "d$tag", '售价' => '120', $sk => '已完成', '客服' => '宋倩倩', '模板技术' => '高晶晶'];
    $n1 = "33198$tag" . '00001'; $n2 = "33198$tag" . '00002';
    $costOf = function ($no) use ($pdo) { $c = $pdo->prepare("SELECT c.item_name,c.amount FROM project_costs c JOIN project_orders o ON o.id=c.order_id WHERE o.order_no=? AND c.review_status<>'rejected'"); $c->execute([$no]); return $c->fetchAll(PDO::FETCH_KEY_PAIR); };
    [$pv, $imp, $err] = $importAs($song, '网站模板', $csvOf('网站模板', [
        $base + ['订单编号' => $n1, '程序名称' => '1年域名注册', '网站域名' => "pd$tag.com", '域名来源' => '我们注册'],
        $base + ['订单编号' => $n2, '程序名称' => '3年域名注册', '网站域名' => "pd$tag.cn", '域名来源' => '我们注册', '售价' => '200'],
    ]));
    $names = []; foreach ($pv as $r) $names[$r['order_no']] = $r;
    $t1 = (int)($names[$n1]['program_template_id'] ?? 0); $t2 = (int)($names[$n2]['program_template_id'] ?? 0);
    $spec = function ($id) use ($pdo) { $s = $pdo->prepare('SELECT specification FROM project_cost_templates WHERE id=?'); $s->execute([$id]); return (string)$s->fetchColumn(); };
    $check($t1 && strpos($spec($t1), '.com 1年') === 0 && $t2 && strpos($spec($t2), '.cn 3年') === 0, '程序名称“N年域名注册”按域名后缀和年数自动选套餐：' . $spec($t1) . ' / ' . $spec($t2) . ' ' . ($names[$n1]['error'] ?? ''));
    $check(($names[$n1]['domain_mode'] ?? '') === 'none' && ($names[$n2]['domain_mode'] ?? '') === 'none', '套餐已含域名成本：不再要求另选域名模板（' . ($names[$n1]['domain_mode'] ?? '?') . '）');
    $check($err === '' && $imp === 2, '两行导入成功：' . $imp . ' ' . $err);
    $c1 = $costOf($n1); $c2 = $costOf($n2);
    $check(count($c1) === 1 && (float)reset($c1) === 58.0 && strpos((string)key($c1), '纯域名注册') === 0, '.com 1年：成本 58（商务中国价）：' . json_encode($c1, JSON_UNESCAPED_UNICODE));
    $check(count($c2) === 1 && (float)reset($c2) === 109.0, '.cn 3年：成本 109 = 35 + 37 × 2：' . json_encode($c2, JSON_UNESCAPED_UNICODE));
    // 认不出域名后缀：不乱选，留给人工在下拉里选
    $n3 = "33198$tag" . '00003';
    [$pv, $imp, $err] = $importAs($song, '网站模板', $csvOf('网站模板', [$base + ['订单编号' => $n3, '程序名称' => '1年域名注册', '网站域名' => "pd$tag.top", '域名来源' => '我们注册']]));
    $check(empty($pv[0]['program_template_id']), '后缀不是 com/cn/net/org：不自动选，留给人工');
    echo "
=== 纯域名注册套餐测试全部通过 ===
";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "
");
    exit(1);
} finally {
    foreach ($stored as $s) @unlink(ps_private_dir('imports') . '/' . basename($s) . '.php');
}
