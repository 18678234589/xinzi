<?php
// 网站模板 / AI网站定制 xlsx 新模板：域名、域名 / 服务器到期日期（可写“永久”）、续费联系方式、域名归属导入后写入续费资料；模板列与下拉。
// 只允许本地临时库：DB_HOST=127.0.0.1 DB_PORT=13399 php tests/import_web_template_smoke.php
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
$items = function ($no) use ($pdo) {
    $q = $pdo->prepare("SELECT r.resource_type t,r.resource_name n,r.expires_on e,r.status s,r.owner o,r.phone_hash<>'' ph,COALESCE(r.wechat_hash,'')<>'' wx FROM project_renewal_items r JOIN project_orders o ON o.id=r.order_id WHERE o.order_no=? ORDER BY r.resource_type");
    $q->execute([$no]);
    $by = []; foreach ($q->fetchAll() as $r) $by[$r['t']][] = $r;
    return $by;
};
try {
    require_once __DIR__ . '/../includes/ProjectMiniappTemplate.php';
    $check(pmt_businesses() === ['小程序开发', '网站模板', 'AI网站定制', '商标'], '原三个业务保留专用Excel，并新增商标专业模板');
    foreach (['网站模板', 'AI网站定制'] as $b) {
        $h = ps_business_import_headers($b);
        foreach (['网站域名', '域名到期日期', '服务器到期日期', '续费联系方式', '域名归属'] as $need) $check(in_array($need, $h, true), "{$b} 模板有“{$need}”列");
        $m = ps_business_import_map($b, $h, false);
        $check(isset($m['order_no'], $m['contract_amount'], $m['order_date'], $m['status']), "$b 模板表头可被导入识别");
    }
    $check(strlen(pmt_xlsx('网站模板')) > 5000 && substr(pmt_xlsx('AI网站定制'), 0, 2) === 'PK', '两个业务的 xlsx 都能生成');

    $song = $actorOf('宋倩倩'); $zgp = $actorOf('张光萍');
    $check($song && $zgp, '账号存在');
    $n1 = "33194$tag" . '00001'; $n2 = "33194$tag" . '00002'; $n3 = "33194$tag" . '00003';
    $base = ['日期' => '2026.9.8', '店铺' => '美呀美', '付款昵称' => "w$tag", '售价' => '998', $sk => '已完成', '客服' => '宋倩倩', '模板技术' => '张强', '程序名称' => 'jsp展示中级版'];
    [$pv, $imp, $err] = $importAs($song, '网站模板', $csvOf('网站模板', [
        $base + ['订单编号' => $n1, '订单类型' => '新订单', '网站域名' => "a$tag.com", '域名使用' => '是', '域名归属' => '我们代管', '域名到期日期' => '2027-09-08', '服务器到期日期' => '永久', '续费联系方式' => '13800138000'],
        $base + ['订单编号' => $n2, '订单类型' => '新订单', '网站域名' => "b$tag.com", '域名使用' => '否', '域名归属' => '客户自有', '续费联系方式' => 'wx_example'],
    ]));
    $check($err === '' && $imp === 2, '网站模板 2 行导入：' . $imp . ' ' . implode('；', array_map(function ($r) { return $r['error'] ?? ''; }, $pv)) . $err);
    $a = $items($n1);
    $check(isset($a['domain'][0]) && $a['domain'][0]['n'] === "a$tag.com" && $a['domain'][0]['e'] === '2027-09-08' && $a['domain'][0]['ph'], '域名条目：域名、到期日、续费手机号已写入');
    $check(isset($a['server'][0]) && $a['server'][0]['e'] === '2099-01-01', '服务器到期日期写“永久” → 2099-01-01');
    $b = $items($n2);
    $check(isset($b['domain'][0]) && $b['domain'][0]['o'] === 'customer' && $b['domain'][0]['wx'], '客户自有域名：不续费，续费联系方式识别为微信号');

    [$pv, $imp, $err] = $importAs($zgp, 'AI网站定制', $csvOf('AI网站定制', [
        ['日期' => '2026.9.9', '店铺' => '美呀美', '付款昵称' => "x$tag", '订单编号' => $n3, '售价' => '3000', $sk => '已完成', '客服' => '张光萍', '前端（技术）' => '李子晖', '网站域名' => "c$tag.com", '域名到期日期' => '2027-10-01', '服务器到期日期' => '2027-10-01', '续费联系方式' => '13900139000'],
    ]));
    $check($err === '' && $imp === 1, 'AI网站定制导入：' . $imp . ' ' . ($pv[0]['error'] ?? $err));
    $c = $items($n3);
    $check(isset($c['domain'][0]) && $c['domain'][0]['e'] === '2027-10-01' && $c['domain'][0]['ph'] && isset($c['server'][0]) && $c['server'][0]['e'] === '2027-10-01', 'AI网站定制：域名 / 服务器到期日与联系方式写入续费资料');

    // 旧版表（无续费联系方式列）提醒下载新版
    $old = "日期,店铺,付款昵称,订单编号,售价,状态(填已完成/未完成),客服,模板技术,程序名称\n2026.9.10,美呀美,old$tag,33194{$tag}00009,500,已完成,宋倩倩,张强,jsp展示中级版\n";
    $fileId = $upload($song, '网站模板', $old);
    $run($song, ['action' => 'repreview', 'business' => '网站模板', 'file_id' => $fileId, 'all_sheets' => 1]);
    $notes = implode(' ', array_column($_SESSION['project_import_sheets'] ?? [], 'ai'));
    $check(mb_strpos($notes, '旧版模板') !== false && mb_strpos($notes, '续费联系方式') !== false, '旧版表上传时提醒下载新版');
    echo "\n=== 网站类 xlsx 模板测试全部通过 ===\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
} finally {
    foreach ($stored as $s) @unlink(ps_private_dir('imports') . '/' . basename($s) . '.php');
}
