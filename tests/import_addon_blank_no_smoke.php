<?php
// 订单编号留空的附加行（证书 / 加语言）并入上一行同买家同日期的订单，不再生成独立 WX- 单。
// 导入流程含建表语句会提前提交，所以只允许在本地临时库运行。DB_HOST=127.0.0.1 DB_PORT=13399 php tests/import_addon_blank_no_smoke.php
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
    foreach ($rows as $row) foreach (array_keys($row) as $k) if (!in_array($k, $head, true)) $head[] = $k; // 模板没有的列（接单方式等）照常识别
    $out = implode(',', $head) . "\n";
    foreach ($rows as $row) { $cells = []; foreach ($head as $h) $cells[] = $row[$h] ?? ''; $out .= implode(',', $cells) . "\n"; }
    return $out;
};
$previewOf = function ($actor, $business, $csv, $extra = []) use ($upload, $run) {
    $fileId = $upload($actor, $business, $csv);
    $run($actor, ['action' => 'repreview', 'business' => $business, 'file_id' => $fileId, 'all_sheets' => 1] + $extra);
    return [$fileId, $_SESSION['project_import_preview'] ?? [], $GLOBALS['error']];
};
$stateKey = '状态(填已完成/未完成)';

try {
    $liu = $actorOf('刘帅'); $check($liu, '刘帅账号存在');
    $no = "33170$tag" . '22222';
    $row = function ($no, $amt, $biz) use ($stateKey) { return ['日期' => '2026.9.8', '店铺' => '美呀美旗舰店', '付款昵称' => 'tb' . $GLOBALS['tag'], '订单编号' => $no, '售价' => $amt, '业务' => $biz, $stateKey => '已完成', '客服' => '宋倩倩', '前端（技术）' => '刘帅', '后端' => '于海波']; };
    echo "=== 订单编号留空的附加行（证书）并入上一行同买家同日期的订单 ===\n";
    [$fileId, $pv, $err] = $previewOf($liu, 'AI网站定制', $csvOf('AI网站定制', [$row($no, '1300', '企业官网'), $row('', '200', '证书')]));
    $check(count($pv) === 1 && $pv[0]['order_no'] === $no && (float)($pv[0]['contract_amount'] ?? 0) === 1500.0, '两行合并成一张订单（订单号不是 WX-，售价 1300 + 200 = 1500）：' . json_encode(array_map(function ($r) { return [$r['order_no'] ?? '', $r['contract_amount'] ?? '']; }, $pv)) . ' ' . $err);
    $pv2 = $previewOf($liu, 'AI网站定制', $csvOf('AI网站定制', [$row('', '200', '证书')]))[1];
    $check(count($pv2) === 1 && strpos((string)$pv2[0]['order_no'], 'WX-') === 0, '上面没有主订单的留空行：仍按原规则生成内部号');
    echo "=== 订单类型没选：按技术人数判断 ===
";
    $kindRow = function ($no, $backend) use ($stateKey) { return ['日期' => '2026.9.9', '店铺' => '美呀美旗舰店', '付款昵称' => 'k' . substr($no, -4), '订单编号' => $no, '售价' => '800', '业务' => '企业官网', $stateKey => '已完成', '客服' => '宋倩倩', '前端（技术）' => '刘帅', '后端' => $backend]; };
    $kOne = "33171$tag" . '33331'; $kTwo = "33172$tag" . '44442';
    [, $pvk, $errk] = $previewOf($liu, 'AI网站定制', $csvOf('AI网站定制', [$kindRow($kOne, '无'), $kindRow($kTwo, '于海波')]));
    $byNo = []; foreach ($pvk as $r) $byNo[$r['order_no']] = $r;
    $check(($byNo[$kOne]['order_kind'] ?? '') === 'AI定制', '一个技术做（后端“无”）→ AI定制：' . ($byNo[$kOne]['order_kind'] ?? '空') . ' ' . $errk);
    $check(($byNo[$kTwo]['order_kind'] ?? '') === '博山定制', '前端 + 后端两个技术 → 博山定制：' . ($byNo[$kTwo]['order_kind'] ?? '空'));
    echo "=== 网站模板：合并单元格导致第二行（SSL证书）订单编号为空 ===
";
    $tplRow = function ($no, $amt, $prog) use ($stateKey) { return ['日期' => '2026-10-07', '编号类型' => '美呀美旗舰店', '付款账号' => 'zhbao' . $GLOBALS['tag'], '订单编号' => $no, '售价' => $amt, '程序名称' => $prog, '年限' => '1年', '客服' => '孙湉湉', '技术' => '张强', $stateKey => '已完成']; };
    $tNo = "33164$tag" . '55553';
    $sun = $actorOf('孙湉湉');
    [, $pvt, $errt] = $previewOf($sun ?: $liu, '网站模板', $csvOf('网站模板', [$tplRow($tNo, '950', 'JSP展示中级版'), $tplRow('', '200', 'SSL证书')]));
    $nosT = array_map(function ($r) { return $r['order_no'] . '=' . ($r['contract_amount'] ?? '?'); }, $pvt);
    $check(count($pvt) === 1 && $pvt[0]['order_no'] === $tNo, '网站模板：两行并成一张订单，没有 WX- 单：' . implode(' , ', $nosT) . ' ' . $errt);
    echo "=== 域名来源：我们注册 / 客户提供 / 第三方；主订单没填要选，附加行可不填 ===
";
    $domRow = function ($no, $dom, $biz) use ($stateKey) { return ['日期' => '2026.9.10', '店铺' => '美呀美旗舰店', '付款昵称' => 'd' . substr($no, -4), '订单编号' => $no, '售价' => '800', '网站域名' => 'example' . substr($no, -3) . '.com', '域名来源' => $dom, '业务' => $biz, $stateKey => '已完成', '客服' => '宋倩倩', '前端（技术）' => '刘帅', '后端' => '无', 'AI定制类型' => 'AI定制']; };
    $d1 = "33173$tag" . '11111'; $d2 = "33174$tag" . '22222'; $d3 = "33175$tag" . '33333'; $d4 = "33176$tag" . '44444';
    [, $pvd, $errd] = $previewOf($liu, 'AI网站定制', $csvOf('AI网站定制', [$domRow($d1, '', '企业官网'), $domRow($d2, '第三方', '企业官网'), $domRow($d3, '我们注册', '企业官网'), $domRow($d4, '', 'SSL证书')]));
    $by = []; foreach ($pvd as $r) $by[$r['order_no']] = $r;
    $check(($by[$d1]['status'] ?? '') === '需确认域名', '主订单没填域名来源：需要选择（' . ($by[$d1]['status'] ?? '?') . '）' . $errd);
    $check(($by[$d2]['domain_mode'] ?? '') === 'none', '第三方：按客户自备域名处理，不计域名成本');
    $check(($by[$d3]['domain_mode'] ?? '') === 'template', '我们注册：按域名后缀计入域名成本');
    $check(($by[$d4]['status'] ?? '') !== '需确认域名' && ($by[$d4]['domain_mode'] ?? '') === 'none', 'SSL 证书附加行不填域名来源也不用选');
    echo "=== 小程序定制：有专属岗位的技术（翟建跃 定制技术15）不被降成“制作技术” ===
";
    $zj = $actorOf('翟建跃');
    if ($zj) {
        $zjId = (int)$pdo->query("SELECT id FROM employees WHERE name='翟建跃'")->fetchColumn();
        $had = $pdo->query("SELECT COUNT(*) FROM project_employee_roles WHERE employee_id=$zjId AND business_name='小程序开发' AND commission_group='technical'")->fetchColumn();
        if (!$had) $pdo->prepare("INSERT INTO project_employee_roles (employee_id,business_name,commission_group,role_name) VALUES (?,'小程序开发','technical','定制技术15')")->execute([$zjId]);
        $zjRow = function ($no) use ($stateKey) { return ['日期' => '2026.9.5', '店铺' => '美呀美', '业务' => '小程序商城', '订单类型(下拉选择)' => '定制', '付款昵称' => 'zj' . substr($no, -4), '订单编号' => $no, '售价' => '800', $stateKey => '已完成', '客服' => '曹双双', '制作技术' => '翟建跃']; };
        $zNo = "33191$tag" . '66661';
        [, $pvz, $errz] = $previewOf($zj, '小程序开发', $csvOf('小程序开发', [$zjRow($zNo)]));
        $roleZ = $pvz[0]['people']['technical'][$zjId]['role'] ?? '空';
        $check($roleZ === '定制技术15', '小程序定制：翟建跃写在“制作技术”列，岗位仍是定制技术15：' . $roleZ . ' ' . $errz);
        if (!$had) $pdo->exec("DELETE FROM project_employee_roles WHERE employee_id=$zjId AND business_name='小程序开发' AND commission_group='technical' AND role_name='定制技术15'");
    } else echo "  [跳过] 测试库没有翟建跃账号
";
    echo "=== 网站模板：空间+域名套餐不再另计域名成本 ===
";
    $pkgHas = $pdo->query("SELECT COUNT(*) FROM project_cost_templates WHERE category='program' AND name='JSP展示中级版' AND specification LIKE '1年 空间+域名%' AND is_active=1")->fetchColumn();
    if ($pkgHas) {
        $pkgRow = function ($no, $dom) use ($stateKey) { return ['日期' => '2026-10-07', '编号类型' => '美呀美旗舰店', '付款账号' => 'pk' . substr($no, -4), '订单编号' => $no, '售价' => '750', '程序名称' => 'JSP展示中级版', '年限' => '1年', '域名来源' => $dom, '客服' => '宋倩倩', '技术' => '张强', $stateKey => '已完成', '网站域名' => 'pk' . substr($no, -4) . '.com']; };
        $pkA = "33192$tag" . '77771'; $pkB = "33193$tag" . '88882';
        $song = $actorOf('张强'); [, $pvp, $errp] = $previewOf($song ?: $liu, '网站模板', $csvOf('网站模板', [$pkgRow($pkA, '我们注册'), $pkgRow($pkB, '客户提供')]));
        $byP = []; foreach ($pvp as $r) $byP[$r['order_no']] = $r;
        $check(($byP[$pkA]['domain_mode'] ?? '') === 'none', '套餐已含域名：域名来源写“我们注册”也不另计域名（' . json_encode(array_map(function ($r) { return [$r['order_no'], $r['domain_mode'] ?? null, $r['program_template_id'] ?? null, mb_substr((string)($r['error'] ?? ''), 0, 60)]; }, $pvp), JSON_UNESCAPED_UNICODE) . '）' . $errp);
        $check(strpos((string)($byP[$pkA]['warning'] ?? ''), '已含域名') !== false, '预览提示了“程序套餐已含域名”');
    } else echo "  [跳过] 测试库没有 JSP展示中级版 空间+域名 套餐
";
    echo "=== 订单类型与技术人数对不上：预览提示 ===
";
    $mm = function ($no, $kind, $back) use ($stateKey) { return ['日期' => '2026.9.11', '店铺' => '美呀美旗舰店', '付款昵称' => 'mm' . substr($no, -4), '订单编号' => $no, '售价' => '800', '业务' => '企业官网', $stateKey => '已完成', '客服' => '宋倩倩', '前端（技术）' => '刘帅', '后端' => $back, 'AI定制类型' => $kind]; };
    $m1 = "33194$tag" . '10001'; $m2 = "33195$tag" . '10002'; $m3 = "33196$tag" . '10003';
    [, $pvm, $errm] = $previewOf($liu, 'AI网站定制', $csvOf('AI网站定制', [$mm($m1, 'AI定制', '于海波'), $mm($m2, '博山定制', '无'), $mm($m3, 'AI定制', '无')]));
    $bm = []; foreach ($pvm as $r) $bm[$r['order_no']] = (string)$r['warning'];
    $check(strpos($bm[$m1] ?? '', '技术栏写了 2 个人') !== false, 'AI定制 却写了前端 + 后端两个技术：提示');
    $check(strpos($bm[$m2] ?? '', '只写了一个技术') !== false, '博山定制 只写了一个技术：提示');
    $check(strpos($bm[$m3] ?? '', '技术栏写了') === false && strpos($bm[$m3] ?? '', '只写了一个技术') === false, 'AI定制 一个技术：不提示 ' . $errm);
    echo "=== 同一付款号多个网站：先传没写域名、技术再传带域名的，不另建重复订单 ===
";
    $zq = $actorOf('张强');
    if ($zq) {
        $sNo = "33197$tag" . '90001';
        $site = function ($dom, $amt, $prog, $note) use ($stateKey, $sNo) { return ['日期' => '2026-10-12', '编号类型' => '美呀美旗舰店', '付款账号' => 'zy' . substr($sNo, -4), '订单编号' => $sNo, '售价' => $amt, '程序名称' => $prog, '年限' => '1年', '域名来源' => '我们注册', '备注' => $note, '客服' => '宋倩倩', '技术' => '张强', $stateKey => '已完成', '网站域名' => $dom]; };
        [, , $e1] = $importAs($zq, '网站模板', $csvOf('网站模板', [$site('', '260', 'PHP', '中文'), $site('', '200', 'PHP', '英文')]));
        $cnt1 = (int)$pdo->query("SELECT COUNT(*) FROM project_orders WHERE order_no LIKE '$sNo%'")->fetchColumn();
        [$pv2, $imp2, $e2] = $importAs($zq, '网站模板', $csvOf('网站模板', [$site('aa-site.com', '260', 'PHP', '中文'), $site('bb-site.com', '200', 'PHP', '英文')]));
        $cnt2 = (int)$pdo->query("SELECT COUNT(*) FROM project_orders WHERE order_no LIKE '$sNo%'")->fetchColumn();
        $keys = $pdo->query("SELECT GROUP_CONCAT(site_key ORDER BY order_id) FROM project_site_projects WHERE external_order_no='$sNo'")->fetchColumn();
        $check($cnt1 === 2, '第一次上传（没写域名）建了 2 张网站订单：' . $cnt1 . ' ' . $e1);
        $check($cnt2 === 2 && $keys === 'aa-site.com,bb-site.com', '技术再传带域名的表格：仍是 2 张订单，网站标识更新为域名（订单 ' . $cnt2 . '，标识 ' . var_export($keys, true) . '）' . $e2 . ' imported=' . $imp2 . ' ' . json_encode(array_map(function ($r) { return [$r['order_no'] ?? '', $r['site_key'] ?? '', $r['site_rebind_order_id'] ?? null, mb_substr((string)($r['warning'] ?? ''), 0, 90), $r['error'] ?? '']; }, $pv2), JSON_UNESCAPED_UNICODE));
    } else echo "  [跳过] 测试库没有张强账号
";
    echo "=== 网站业务的“已完成”只认技术 / 财务 ===
";
    $csUp = $actorOf('宋倩倩'); $techUp = $actorOf('张强');
    if ($csUp && $techUp) {
        $dRow = function ($no) use ($stateKey) { return ['日期' => '2026-10-12', '编号类型' => '美呀美旗舰店', '付款账号' => 'dl' . substr($no, -4), '订单编号' => $no, '售价' => '500', '程序名称' => 'PHP', '年限' => '1年', '域名来源' => '客户提供', '客服' => '宋倩倩', '技术' => '张强', $stateKey => '已完成']; };
        $dA = "33198$tag" . '20001'; $dB = "33199$tag" . '20002';
        [, $pvA] = $previewOf($csUp, '网站模板', $csvOf('网站模板', [$dRow($dA)])); [, $pvB] = $previewOf($techUp, '网站模板', $csvOf('网站模板', [$dRow($dB)]));
        $check(($pvA[0]['delivery_status'] ?? '') === 'unfinished' && strpos((string)$pvA[0]['warning'], '需技术确认') !== false, '客服表格里写已完成：记为未完成并提示');
        $check(($pvB[0]['delivery_status'] ?? '') === 'finished', '技术上传的表格写已完成：算完成');
    } else echo "  [跳过] 测试库缺宋倩倩 / 张强账号
";
    echo "=== 网站业务：后传的表格写“未完成”，原单已完成（没审核）→ 退回未完成 ===
";
    $zq2 = $actorOf('张强');
    if ($zq2) {
        $uNo = "33199$tag" . '30003';
        $uRow = function ($st) use ($stateKey, $uNo) { return ['日期' => '2026-10-12', '编号类型' => '美呀美旗舰店', '付款账号' => 'un' . substr($uNo, -4), '订单编号' => $uNo, '售价' => '500', '程序名称' => 'PHP', '年限' => '1年', '域名来源' => '客户提供', '客服' => '宋倩倩', '技术' => '张强', $stateKey => $st]; };
        $importAs($zq2, '网站模板', $csvOf('网站模板', [$uRow('已完成')]));
        $d1 = $pdo->query("SELECT delivery_status FROM project_orders WHERE order_no='$uNo'")->fetchColumn();
        $importAs($zq2, '网站模板', $csvOf('网站模板', [$uRow('未完成')]));
        $d2 = $pdo->query("SELECT delivery_status FROM project_orders WHERE order_no='$uNo'")->fetchColumn();
        $check($d1 === 'finished' && $d2 === 'unfinished', '先传已完成、后传未完成：订单退回未完成（' . $d1 . ' → ' . $d2 . '）');
    } else echo "  [跳过] 测试库缺张强账号
";
    echo "\n=== 附加行并入测试全部通过 ===\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
} finally {
    foreach ($stored as $s) @unlink(ps_private_dir('imports') . '/' . basename($s) . '.php');
}
