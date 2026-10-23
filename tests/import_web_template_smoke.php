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
    foreach ($rows as $row) foreach (array_keys($row) as $k) if (!in_array($k, $head, true)) $head[] = $k; // 老写法的列（店铺 / 订单类型 / 模板技术 / 续费联系方式…）照常识别
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
    $check(!array_diff(['小程序开发', '网站模板', 'AI网站定制', '商标', '森动备案'], pmt_businesses()), '原三个业务保留专用Excel，并新增商标、森动备案专业模板');
    foreach (['网站模板', 'AI网站定制'] as $b) {
        $h = ps_business_import_headers($b);
        $needs = $b === '网站模板' ? ['网站域名', '域名到期日期', '服务器到期日期', '续费联系方式电话', '续费联系方式邮箱', '续费联系方式微信', '续费金额', '空间来源', '域名来源', '程序名称', '年限', '付款账号', '技术'] : ['网站域名', '域名到期日期', '服务器到期日期', '续费联系方式', '空间来源'];
        foreach ($needs as $need) $check(in_array($need, $h, true), "{$b} 模板有“{$need}”列");
        $check(!in_array('域名归属', $h, true), "{$b} 模板不再有重复的“域名归属”列（改为空间来源）");
        $m = ps_business_import_map($b, $h, false);
        $check(isset($m['order_no'], $m['contract_amount'], $m['order_date'], $m['status']), "$b 模板表头可被导入识别");
    }
    $hh = ps_business_import_headers('网站模板');
    $check(array_search('售价', $hh) + 1 === array_search('孙曼要成本', $hh) && array_search('孙曼要成本', $hh) + 1 === array_search('成本', $hh), '网站模板：售价后面紧跟“孙曼要成本”“成本”两列');
    $check(array_search('编号类型', $hh) === 1 && array_search('编号类型', $hh) < array_search('订单编号', $hh) && array_search('订单编号', $hh) < array_search('售价', $hh), '网站模板：“编号类型”下拉在订单编号左边');
    $check(!array_intersect(['店铺', '订单类型', 'SSL证书使用', '业务'], $hh), '网站模板：新模板没有 店铺 / 订单类型 / SSL证书使用 / 业务 列');
    $check(!in_array('孙曼要成本', ps_business_import_headers('AI网站定制'), true) && substr(ps_business_import_headers('AI网站定制')[count(ps_business_import_headers('AI网站定制')) - 1], 0, 6) !== '', 'AI网站定制 不加成本两列');
    $check(strlen(pmt_xlsx('网站模板')) > 5000 && substr(pmt_xlsx('AI网站定制'), 0, 2) === 'PK', '两个业务的 xlsx 都能生成');

    $song = $actorOf('宋倩倩'); $zgp = $actorOf('张光萍');
    $check($song && $zgp, '账号存在');
    $n1 = "33194$tag" . '00001'; $n2 = "33194$tag" . '00002'; $n3 = "33194$tag" . '00003';
    $base = ['日期' => '2026.9.8', '店铺' => '美呀美', '付款昵称' => "w$tag", '售价' => '998', $sk => '已完成', '客服' => '宋倩倩', '模板技术' => '张强', '程序名称' => 'jsp展示中级版'];
    [$pv, $imp, $err] = $importAs($song, '网站模板', $csvOf('网站模板', [
        $base + ['订单编号' => $n1, '订单类型' => '新订单', '网站域名' => "a$tag.com", '域名来源' => '我们注册', '空间来源' => '我们的空间', '孙曼要成本' => '260', '成本' => '360', '域名到期日期' => '2027-09-08', '服务器到期日期' => '永久', '续费联系方式' => '13800138000'],
        $base + ['订单编号' => $n2, '订单类型' => '新订单', '网站域名' => "b$tag.com", '域名来源' => '客户提供', '空间来源' => '客户自有空间', '续费联系方式' => 'wx_example'],
    ]));
    $check($err === '' && $imp === 2, '网站模板 2 行导入：' . $imp . ' ' . implode('；', array_map(function ($r) { return $r['error'] ?? ''; }, $pv)) . $err);
    $a = $items($n1);
    $check(isset($a['domain'][0]) && $a['domain'][0]['n'] === "a$tag.com" && $a['domain'][0]['e'] === '2027-09-08' && $a['domain'][0]['ph'], '域名条目：域名、到期日、续费手机号已写入');
    $check(isset($a['server'][0]) && $a['server'][0]['e'] === '2099-01-01', '服务器到期日期写“永久” → 2099-01-01');
    $b = $items($n2);
    $check(isset($b['domain'][0]) && $b['domain'][0]['o'] === 'customer' && $b['domain'][0]['wx'], '新模板：域名来源写“客户提供”＝客户自有域名（不续费），续费联系方式识别为微信号');

    [$pv, $imp, $err] = $importAs($zgp, 'AI网站定制', $csvOf('AI网站定制', [
        ['日期' => '2026.9.9', '店铺' => '美呀美', '付款昵称' => "x$tag", '订单编号' => $n3, '售价' => '3000', $sk => '已完成', '客服' => '张光萍', '前端（技术）' => '李子晖', '网站域名' => "c$tag.com", '域名到期日期' => '2027-10-01', '服务器到期日期' => '2027-10-01', '续费联系方式' => '13900139000'],
    ]));
    $check($err === '' && $imp === 1, 'AI网站定制导入：' . $imp . ' ' . ($pv[0]['error'] ?? $err));
    $c = $items($n3);
    $check(isset($c['domain'][0]) && $c['domain'][0]['e'] === '2027-10-01' && $c['domain'][0]['ph'] && isset($c['server'][0]) && $c['server'][0]['e'] === '2027-10-01', 'AI网站定制：域名 / 服务器到期日与联系方式写入续费资料');

    // 新列落库：孙曼要成本 → 商品明细的技术成本（利润分析用），成本 → 报表成本（核算用）；空间来源写入订单资料；客户自有空间在预览里提示财务核对
    $it1 = $pdo->query("SELECT i.technical_cost,i.reported_cost FROM project_order_items i JOIN project_orders o ON o.id=i.order_id WHERE o.order_no='$n1' LIMIT 1")->fetch();
    $check($it1 && abs((float)$it1['technical_cost'] - 260) < 0.01 && abs((float)$it1['reported_cost'] - 360) < 0.01, '孙曼要成本 260 / 成本 360 分别写入：' . json_encode($it1));
    $det = json_decode((string)$pdo->query("SELECT d.details_json FROM project_order_details d JOIN project_orders o ON o.id=d.order_id WHERE o.order_no='$n2'")->fetchColumn(), true) ?: [];
    $check(($det['space_source'] ?? '') === '客户自有空间', '空间来源写入订单资料');

    // 兼容原表：旧列序（微信交易流水号在最后）、没有两列成本、仍用“域名归属”的旧表照常导入
    $n4 = "33194$tag" . '00004';
    [$pv4, $imp4, $err4] = $importAs($song, '网站模板', "日期,店铺,付款昵称,订单编号,售价,程序名称,网站域名,域名使用,域名归属,续费联系方式,状态(填已完成/未完成),客服,模板技术,备注,微信交易流水号
2026.9.11,美呀美,old4$tag,$n4,500,jsp展示中级版,d$tag.com,是,客户自有,13800138000,已完成,宋倩倩,张强,旧版列序,
");
    $check($err4 === '' && $imp4 === 1, '原表格（旧列序 + 域名归属）仍能导入：' . $imp4 . ' ' . ($pv4[0]['error'] ?? $err4));
    $o4 = $items($n4);
    $check(isset($o4['domain'][0]) && $o4['domain'][0]['o'] === 'customer', '原表格的“域名归属”仍按原语义识别');

    // 年限下拉：模板里“年限”紧跟程序名称，1-10 年；年限列 + 程序名称 ≡ 直接在名称里写“5年xxx”
    $yh = ps_business_import_headers('网站模板'); $yl = null;
    foreach (pmt_columns('网站模板') as $col) if ($col[0] === '年限') $yl = $col;
    $check(array_search('程序名称', $yh) + 1 === array_search('年限', $yh) && $yl && $yl[2] === 'list' && $yl[3] === array_map(function ($n) { return $n . '年'; }, range(1, 10)), '网站模板：年限下拉（1-10 年）紧跟程序名称');
    $n5 = "33194$tag" . '00005'; $n6 = "33194$tag" . '00006'; $n7 = "33194$tag" . '00007';
    $y5 = ['日期' => '2026.9.12', '店铺' => '美呀美', '付款昵称' => "y$tag", '售价' => '1500', $sk => '已完成', '客服' => '宋倩倩', '模板技术' => '张强', '网站域名' => "y$tag.com", '续费联系方式' => '13800138000'];
    [$pvA, $impA, $errA] = $importAs($song, '网站模板', $csvOf('网站模板', [$y5 + ['订单编号' => $n5, '程序名称' => 'jsp展示中级版', '年限' => '5年']]));
    [$pvB, $impB, $errB] = $importAs($song, '网站模板', $csvOf('网站模板', [$y5 + ['订单编号' => $n6, '程序名称' => '5年jsp展示中级版']]));
    [$pvC, $impC, $errC] = $importAs($song, '网站模板', $csvOf('网站模板', [$y5 + ['订单编号' => $n7, '程序名称' => 'jsp展示中级版']]));
    $check($impA === 1 && $impB === 1 && $impC === 1, '三种写法都能导入：' . $errA . $errB . $errC);
    $tpl = function ($no) use ($pdo) { $q = $pdo->prepare('SELECT i.template_id FROM project_order_items i JOIN project_orders o ON o.id=i.order_id WHERE o.order_no=? ORDER BY i.id LIMIT 1'); $q->execute([$no]); return $q->fetchColumn(); };
    $check($tpl($n5) && $tpl($n5) === $tpl($n6), '年限列“5年”+“jsp展示中级版” 与 直接写“5年jsp展示中级版” 匹配同一个套餐：' . json_encode([$tpl($n5), $tpl($n6)]));
    $check($tpl($n7) !== $tpl($n5), '年限不填按 1 年，与 5 年不是同一个套餐：' . json_encode([$tpl($n7), $tpl($n5)]));

    // 编号类型下拉 + 订单编号列粘贴编号：淘宝订单编号 / 微信交易流水号 / 对公转账编号
    $common = ['日期' => '2026.9.13', '店铺' => '美呀美', '售价' => '1250', $sk => '已完成', '客服' => '宋倩倩', '模板技术' => '张强', '程序名称' => 'jsp展示中级版', '年限' => '1年', '续费联系方式' => '13800138000'];
    $nk = (function () { foreach (pmt_columns('网站模板') as $col) if ($col[0] === '编号类型') return $col; return null; })();
    $check($nk && $nk[2] === 'list' && $nk[3] === ['美呀美旗舰店', '清风易软件专营店', '微信交易流水号', '对公转账编号', '美丽街旗舰店', '美啊美设计旗舰店'], '编号类型下拉就是 6 项：4 家店铺 + 微信交易流水号 + 对公转账编号');
    $tb = "33195$tag" . '00001'; $wxc = "4200002012$tag" . '8888'; $gs = "GS$tag" . '0001';
    [$pT, $iT] = $importAs($song, '网站模板', $csvOf('网站模板', [$common + ['付款昵称' => "t$tag", '网站域名' => "t$tag.com", '编号类型' => '淘宝订单编号', '订单编号' => $tb]]));
    $check($iT === 1 && (string)($pT[0]['order_no'] ?? '') === $tb, '选“淘宝订单编号”：粘贴的就是订单号');
    [$pW, $iW] = $importAs($song, '网站模板', $csvOf('网站模板', [$common + ['付款昵称' => "w$tag", '网站域名' => "w$tag.com", '编号类型' => '微信交易流水号', '订单编号' => $wxc]]));
    $noW = (string)($pW[0]['order_no'] ?? '');
    $refW = (string)$pdo->query("SELECT s.payment_reference FROM project_order_sources s JOIN project_orders o ON o.id=s.order_id WHERE o.order_no='" . $noW . "'")->fetchColumn();
    $check($iW === 1 && strpos($noW, 'WX-') === 0 && $refW === $wxc, '选“微信交易流水号”：编号存为支付流水号，生成内部号：' . $noW . ' ' . $refW);
    [$pG, $iG] = $importAs($song, '网站模板', $csvOf('网站模板', [$common + ['付款昵称' => "g$tag", '店铺' => '对公转账/启硕', '网站域名' => "g$tag.com", '编号类型' => '对公转账编号', '订单编号' => $gs]]));
    $noG = (string)($pG[0]['order_no'] ?? '');
    $refG = (string)$pdo->query("SELECT s.payment_reference FROM project_order_sources s JOIN project_orders o ON o.id=s.order_id WHERE o.order_no='" . $noG . "'")->fetchColumn();
    $check($iG === 1 && strpos($noG, 'WX-') === 0 && $refG === $gs && strpos((string)($pG[0]['warning'] ?? ''), '没有填对公编码') === false, '选“对公转账编号”：编号存为对公编码 / 流水号，没有“没填编码”提示：' . $noG . ' ' . $refG);
    [$pG2] = $importAs($song, '网站模板', $csvOf('网站模板', [$common + ['付款昵称' => "g$tag", '店铺' => '对公转账/启硕', '网站域名' => "g$tag.com", '编号类型' => '对公转账编号', '订单编号' => $gs]]));
    $check((string)($pG2[0]['order_no'] ?? '') === $noG && (int)$pdo->query("SELECT COUNT(*) FROM project_orders WHERE order_no='" . $noG . "'")->fetchColumn() === 1, '同一对公编码重传：不重复建单');
    // 对公类型但没粘贴编号：仍可导入，临时内部号并提示补编码
    [$pG3, $iG3] = $importAs($song, '网站模板', $csvOf('网站模板', [$common + ['付款昵称' => "h$tag", '店铺' => '对公转账/启硕', '网站域名' => "h$tag.com", '编号类型' => '对公转账编号', '订单编号' => '']]));
    $check($iG3 === 1 && strpos((string)($pG3[0]['order_no'] ?? ''), 'DG-') === 0 && strpos((string)($pG3[0]['warning'] ?? ''), '没有填对公编码') !== false, '选了对公转账编号但没粘贴编号：临时内部号并提示补上');
    // 老写法兼容：没有“编号类型”列，订单编号写“对公”也行
    $dg = ['日期' => '2026.9.13', '店铺' => '对公转账/启硕', '付款昵称' => "公司$tag", '订单编号' => '对公', '售价' => '1250', $sk => '已完成', '客服' => '宋倩倩', '模板技术' => '张强', '程序名称' => 'jsp展示中级版', '年限' => '1年', '网站域名' => "dg$tag.com", '续费联系方式' => '13800138000'];
    [$pvD, $impD] = $importAs($song, '网站模板', $csvOf('网站模板', [$dg]));
    $check($impD === 1 && strpos((string)($pvD[0]['order_no'] ?? ''), 'DG-') === 0, '老写法：不选类型、订单编号只写“对公”仍可导入');

    // 新模板没有“订单类型”列：程序名称下拉含 博山定制 / AI定制 / 华梦定制 / 纯利润 / 补差价 / 域名注册 / SSL证书，直接决定订单类型；老写法（订单类型列）仍可识别
    $progCol = (function () { foreach (pmt_columns('网站模板') as $col) if ($col[0] === '程序名称') return $col; return null; })();
    $check($progCol && $progCol[2] === 'list_sheet' && !array_diff(['PHP', '优站', 'SSL证书', '纯利润', '补差价', '域名注册', '博山定制', 'AI定制', '华梦定制', 'JSP展示国际旗舰版', '森动强推版', '青站全推版'], $progCol[3]) && count($progCol[3]) === 31, '程序名称下拉含 31 项（与客户提供的模板一致）');
    $check(pmt_program_kind('博山定制') === '博山定制' && pmt_program_kind('纯利润') === '加购/纯利润' && pmt_program_kind('SSL证书') === '加购/纯利润' && pmt_program_kind('森动中级版') === '', '程序名称 → 订单类型对应关系');
    $bin = pmt_xlsx('网站模板'); // 自带 zip 写入是 store（不压缩），可直接在二进制里找内容
    $check(strpos($bin, 'xl/worksheets/sheet3.xml') !== false && strpos($bin, '选项&apos;!$A$1:$A$31') !== false && substr_count($bin, '<dataValidation ') >= 8, 'xlsx：程序名称下拉引用“选项”分表（31 项），其余下拉都带校验');
    $ck = ['日期' => '2026.9.14', '店铺' => '美呀美', '售价' => '3000', $sk => '已完成', '客服' => '宋倩倩', '续费联系方式' => '13800138000', '编号类型' => '淘宝订单编号'];
    $kn = [];
    foreach (['博山定制', 'AI定制', '华梦定制'] as $ki => $kind) {
        $no = "33196$tag" . '0000' . ($ki + 1); $kn[$kind] = $no;
        $tech = $kind === 'AI定制' ? ['模板技术' => '张强'] : ['模板技术' => '张强'];
        [$pk, $ik, $ek] = $importAs($song, '网站模板', $csvOf('网站模板', [$ck + $tech + ['付款昵称' => "k$ki$tag", '订单编号' => $no, '订单类型' => $kind, '程序名称' => $kind === 'AI定制' ? '定制开发' : 'jsp展示中级版', '网站域名' => "k$ki$tag.com"]]));
        $check($ik === 1, "订单类型选“{$kind}”能导入：" . ($pk[0]['error'] ?? $ek) . ' ' . ($pk[0]['warning'] ?? ''));
        $row = $pdo->query("SELECT project_type,order_kind FROM project_orders WHERE order_no='$no'")->fetch();
        $check($row && $row['project_type'] === 'AI网站定制' && $row['order_kind'] === $kind, "“{$kind}”：业务 AI网站定制，订单类型记为 {$kind}：" . json_encode($row, JSON_UNESCAPED_UNICODE));
    }
    $nm = "33196$tag" . '00009';
    [$pn, $in] = $importAs($song, '网站模板', $csvOf('网站模板', [$ck + ['模板技术' => '张强', '付款昵称' => "n$tag", '订单编号' => $nm, '订单类型' => '模板新建网站', '程序名称' => 'jsp展示中级版', '网站域名' => "n$tag.com"]]));
    $rowN = $pdo->query("SELECT project_type,order_kind FROM project_orders WHERE order_no='$nm'")->fetch();
    $check($in === 1 && $rowN && $rowN['project_type'] === '网站模板' && $rowN['order_kind'] === '新订单', '模板新建网站仍记为网站模板 / 新订单：' . json_encode($rowN, JSON_UNESCAPED_UNICODE));
    $check(ps_rule_for('technical', 'AI网站定制', '2026-09-14', '前端', 'AI定制') !== null && ps_rule_for('customer_service', 'AI网站定制', '2026-09-14', '客服', '华梦定制') !== null, '新类型暂无单独规则时沿用原 AI网站定制 规则，分成照常计算');

    // 新模板整表（客户提供的版式）：付款账号 / 技术 / 续费金额 / 电话 / 邮箱 / 微信 / 编号类型选店铺 / 程序名称决定订单类型
    $nh = ps_business_import_headers('网站模板');
    $nw = fn($no, $extra) => array_merge(['日期' => '2026.9.16', '编号类型' => '淘宝订单编号', '付款账号' => "nw$tag", '订单编号' => $no, '售价' => '998', '孙曼要成本' => '260', '成本' => '360', '程序名称' => 'JSP展示中级版', '年限' => '1年', '域名来源' => '我们注册', '空间来源' => '我们的空间', '客服' => '宋倩倩', '技术' => '张强', $sk => '已完成'], $extra);
    $nn1 = "33198$tag" . '00001'; $nn2 = "33198$tag" . '00002'; $nn3 = "33198$tag" . '00003';
    [$pn1, $in1, $en1] = $importAs($song, '网站模板', $csvOf('网站模板', [
        $nw($nn1, ['编号类型' => '美呀美旗舰店', '续费金额' => '300', '续费联系方式电话' => '13800138000', '续费联系方式邮箱' => 'a@example.com', '网站域名' => "nw1$tag.com", '域名到期日期' => '2027-09-16', '服务器到期日期' => '2027-09-16']),
        $nw($nn2, ['续费联系方式微信' => 'wx_new_tpl', '域名来源' => '客户提供', '空间来源' => '客户自有空间', '网站域名' => "nw2$tag.com", '服务器到期日期' => '永久']),
        $nw($nn3, ['程序名称' => '博山定制', '年限' => '', '续费联系方式电话' => '13800138001', '网站域名' => "nw3$tag.com"]),
    ]));
    $check($in1 === 3, '新模板 3 行（无订单类型列）能导入：' . $en1 . ' ' . implode('；', array_map(function ($r) { return $r['error'] ?? ''; }, $pn1)));
    $r1 = $pdo->query("SELECT project_type,order_kind,shop FROM project_orders WHERE order_no='$nn1'")->fetch();
    $check($r1 && $r1['project_type'] === '网站模板' && strpos((string)$r1['shop'], '美呀美') !== false, '编号类型选店铺“美呀美旗舰店”：订单号照常，店铺记为美呀美：' . json_encode($r1, JSON_UNESCAPED_UNICODE));
    $d1 = json_decode((string)$pdo->query("SELECT d.details_json FROM project_order_details d JOIN project_orders o ON o.id=d.order_id WHERE o.order_no='$nn1'")->fetchColumn(), true) ?: [];
    $check(($d1['renew_amount'] ?? '') === '300' && ($d1['renew_email'] ?? '') === 'a@example.com' && ($d1['space_source'] ?? '') === '我们的空间', '续费金额 / 续费邮箱写入订单资料：' . json_encode($d1, JSON_UNESCAPED_UNICODE));
    $i1 = $items($nn1); $i2 = $items($nn2);
    $check(isset($i1['domain'][0]) && $i1['domain'][0]['ph'] && $i1['domain'][0]['e'] === '2027-09-16', '“续费联系方式电话”写入续费手机号和域名到期日');
    $check(isset($i2['domain'][0]) && $i2['domain'][0]['wx'] && $i2['domain'][0]['o'] === 'customer' && isset($i2['server'][0]) && $i2['server'][0]['e'] === '2099-01-01', '“续费联系方式微信”写入微信号；域名来源客户提供＝客户自有；服务器写永久');
    $r3 = $pdo->query("SELECT project_type,order_kind FROM project_orders WHERE order_no='$nn3'")->fetch();
    $check($r3 && $r3['project_type'] === 'AI网站定制' && $r3['order_kind'] === '博山定制', '程序名称“博山定制”、没有订单类型列：归入 AI网站定制，订单类型博山定制：' . json_encode($r3, JSON_UNESCAPED_UNICODE));
    $tech = $pdo->query("SELECT e.name FROM project_participants p JOIN project_orders o ON o.id=p.order_id JOIN employees e ON e.id=p.employee_id WHERE o.order_no='$nn1' AND p.commission_group='technical'")->fetchColumn();
    $check($tech === '张强', '“技术”列识别为建站技术人员');

    // 成本中心套餐同步（财务 2026-10-23 程序名称成本表）+ 选好程序名称 / 年限 / 域名来源后自动匹配套餐成本
    require_once __DIR__ . '/../includes/ProjectPresets.php';
    $adminId = (int)$pdo->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetchColumn();
    ps_sync_program_costs(['type' => 'admin', 'id' => $adminId]);
    $again = ps_sync_program_costs(['type' => 'admin', 'id' => $adminId]);
    $check($again['added'] === 0 && $again['repriced'] === 0 && $again['same'] > 100, '成本表同步可重复执行，第二次没有新增或改价：' . json_encode($again));
    $tplRow = function ($name, $spec) use ($pdo) { $q = $pdo->prepare("SELECT supplier_price,price FROM project_cost_templates WHERE category='program' AND name=? AND specification=? AND is_active=1 ORDER BY version DESC LIMIT 1"); $q->execute([$name, $spec]); return $q->fetch(); };
    $c1 = $tplRow('JSP展示中级版', '3年 空间+域名'); $c2 = $tplRow('青站全推版', '1年 空间+域名'); $c3 = $tplRow('JSP展示国际旗舰版', '1年 空间');
    $check($c1 && (float)$c1['supplier_price'] === 620.0 && (float)$c1['price'] === 770.0 && (float)$c2['supplier_price'] === 960.0 && (float)$c3['price'] === 1250.0, '成本中心有 JSP 3 年、国际旗舰版，青站全推版采购价改为 960');
    $auto = function ($no, $program, $years, $source) use ($importAs, $song, $nw, $csvOf, $pdo, $tag) {
        $importAs($song, '网站模板', $csvOf('网站模板', [$nw($no, ['程序名称' => $program, '年限' => $years, '域名来源' => $source, '续费联系方式电话' => '13800138003', '网站域名' => 'ac' . substr($no, -5) . "$tag.com"])]));
        return (string)$pdo->query("SELECT CONCAT(t.name,' ',t.specification) FROM project_order_items i JOIN project_orders o ON o.id=i.order_id JOIN project_cost_templates t ON t.id=i.template_id WHERE o.order_no='$no' ORDER BY i.id LIMIT 1")->fetchColumn();
    };
    $a1 = $auto("33200$tag" . '00001', '森动中级版', '5年', '我们注册');
    $a2 = $auto("33200$tag" . '00002', '森动中级版', '5年', '客户提供');
    $a3 = $auto("33200$tag" . '00003', 'JSP展示中级版', '3年', '我们注册');
    $a4 = $auto("33200$tag" . '00004', '青站', '2年', '客户提供');
    $check($a1 === '森动中级版 5年(三送二) 空间+域名', '森动中级版 + 5年 + 我们注册 → 空间+域名套餐：' . $a1);
    $check($a2 === '森动中级版 5年(三送二) 空间', '森动中级版 + 5年 + 客户提供域名 → 空间套餐（不计域名）：' . $a2);
    $check($a3 === 'JSP展示中级版 3年 空间+域名', 'JSP展示中级版 + 3年 → 新增的 3 年套餐：' . $a3);
    $check($a4 === '青站 2年 空间', '青站 + 2年 + 客户提供 → 青站 2 年空间：' . $a4);

    // 模板名称 / 版本与程序名称重复：新模板不再有这一列；老表里写了仍照常导入
    $check(!in_array('模板名称 / 版本', ps_business_import_headers('网站模板'), true) && in_array('部署与交付说明', ps_business_import_headers('网站模板'), true), '新模板去掉“模板名称 / 版本”，保留“部署与交付说明”');
    $nt = "33197$tag" . '00001';
    [$pOld, $iOld, $eOld] = $importAs($song, '网站模板', "日期,店铺,付款昵称,订单编号,售价,程序名称,网站域名,续费联系方式,状态(填已完成/未完成),客服,模板技术,模板名称 / 版本,部署与交付说明
2026.9.15,美呀美,tpl$tag,$nt,500,jsp展示中级版,tp$tag.com,13800138000,已完成,宋倩倩,张强,旧版名称V2,已部署
");
    $detOld = json_decode((string)$pdo->query("SELECT d.details_json FROM project_order_details d JOIN project_orders o ON o.id=d.order_id WHERE o.order_no='$nt'")->fetchColumn(), true) ?: [];
    $check($iOld === 1, '老表带“模板名称 / 版本”列仍可导入：' . $eOld . ($pOld[0]['error'] ?? ''));

    // 店铺写“微信 / 对公”：订单编号里粘贴的是微信交易流水号 / 对公编码；AI网站定制、小程序开发 模板不再有单独的流水号列
    foreach (['AI网站定制', '小程序开发'] as $sb) $check(!in_array('微信交易流水号', ps_business_import_headers($sb), true) && in_array('店铺', ps_business_import_headers($sb), true), "{$sb} 模板去掉单独的微信交易流水号列，保留店铺列");
    $zg = $actorOf('张光萍');
    $chBase = ['日期' => '2026.9.20', '售价' => '3000', $sk => '已完成', '客服' => '张光萍', '前端（技术）' => '李子晖', '续费联系方式' => '13800138000', '域名到期日期' => '2027-10-01', '服务器到期日期' => '2027-10-01'];
    $wxCode = "4200003388$tag" . '1111'; $gsCode = "GS$tag" . '7777'; $tbNo = "33198$tag" . '00001';
    [$pW, $iW] = $importAs($zg, 'AI网站定制', $csvOf('AI网站定制', [$chBase + ['店铺' => '微信', '付款昵称' => "cw$tag", '订单编号' => $wxCode, '网站域名' => "cw$tag.com"]]));
    $noW = (string)($pW[0]['order_no'] ?? '');
    $refW = (string)$pdo->query("SELECT s.payment_reference FROM project_order_sources s JOIN project_orders o ON o.id=s.order_id WHERE o.order_no='" . $noW . "'")->fetchColumn();
    $check($iW === 1 && strpos($noW, 'WX-') === 0 && $refW === $wxCode, '店铺写微信 + 订单编号填流水号：流水号存为支付流水号，系统生成内部号：' . $noW . ' ' . $refW . ' ' . ($pW[0]['error'] ?? ''));
    [$pG, $iG] = $importAs($zg, 'AI网站定制', $csvOf('AI网站定制', [$chBase + ['店铺' => '对公转账/启硕', '付款昵称' => "cg$tag", '订单编号' => $gsCode, '网站域名' => "cg$tag.com"]]));
    $noG = (string)($pG[0]['order_no'] ?? '');
    $refG = (string)$pdo->query("SELECT s.payment_reference FROM project_order_sources s JOIN project_orders o ON o.id=s.order_id WHERE o.order_no='" . $noG . "'")->fetchColumn();
    $check($iG === 1 && strpos($noG, 'WX-') === 0 && $refG === $gsCode, '店铺写对公 + 订单编号填对公编码：编号存为支付流水号：' . $noG . ' ' . $refG);
    [$pG2] = $importAs($zg, 'AI网站定制', $csvOf('AI网站定制', [$chBase + ['店铺' => '对公转账/启硕', '付款昵称' => "cg$tag", '订单编号' => $gsCode, '网站域名' => "cg$tag.com"]]));
    $check((string)($pG2[0]['order_no'] ?? '') === $noG && (int)$pdo->query("SELECT COUNT(*) FROM project_orders WHERE order_no='" . $noG . "'")->fetchColumn() === 1, '同一编码重传：不重复建单');
    [$pT, $iT] = $importAs($zg, 'AI网站定制', $csvOf('AI网站定制', [$chBase + ['店铺' => '美呀美', '付款昵称' => "ct$tag", '订单编号' => $tbNo, '网站域名' => "ct$tag.com"]]));
    $check($iT === 1 && strpos((string)($pT[0]['order_no'] ?? ''), $tbNo) === 0, '淘宝店铺：订单编号照旧当店铺订单号（网站类按域名带 ~site 后缀）：' . ($pT[0]['order_no'] ?? ''));
    // 历史上已把这个编号当订单号导入过（库里有同号订单）：保持原样，不改成内部号
    $histNo = "4200004499$tag" . '2222';
    [$pH0, $iH0] = $importAs($zg, 'AI网站定制', $csvOf('AI网站定制', [$chBase + ['店铺' => '美呀美', '付款昵称' => "ch$tag", '订单编号' => $histNo, '网站域名' => "ch$tag.com"]]));
    [$pH, $iH] = $importAs($zg, 'AI网站定制', $csvOf('AI网站定制', [$chBase + ['店铺' => '微信', '付款昵称' => "ch$tag", '订单编号' => $histNo, '网站域名' => "ch$tag.com"]]));
    $check($iH0 === 1 && strpos((string)($pH[0]['order_no'] ?? ''), $histNo) === 0 && (int)$pdo->query("SELECT COUNT(*) FROM project_orders WHERE order_no LIKE '" . $histNo . "%'")->fetchColumn() === 1, '库里已有同号订单：店铺写微信也按原订单号对应，不新建、不对不上');
    // 老表仍带单独的“微信交易流水号”列：不动，照旧识别
    $oldWx = "4200005500$tag" . '3333';
    [$pO, $iO] = $importAs($zg, 'AI网站定制', "日期,店铺,付款昵称,订单编号,售价,网站域名,续费联系方式,状态(填已完成/未完成),客服,前端（技术）,备注,微信交易流水号
2026.9.21,微信,old$tag,,3000,old$tag.com,13800138000,已完成,张光萍,李子晖,旧表,$oldWx
");
    $refO = (string)$pdo->query("SELECT s.payment_reference FROM project_order_sources s JOIN project_orders o ON o.id=s.order_id WHERE s.payment_reference='" . $oldWx . "'")->fetchColumn();
    $check($iO === 1 && $refO === $oldWx, '老表带单独的微信交易流水号列：照旧识别');

    // 旧版表（无续费联系方式列）提醒下载新版
    $old = "日期,店铺,付款昵称,订单编号,售价,状态(填已完成/未完成),客服,模板技术,程序名称\n2026.9.10,美呀美,old$tag,33194{$tag}00009,500,已完成,宋倩倩,张强,jsp展示中级版\n";
    $fileId = $upload($song, '网站模板', $old);
    $run($song, ['action' => 'repreview', 'business' => '网站模板', 'file_id' => $fileId, 'all_sheets' => 1]);
    $notes = implode(' ', array_column($_SESSION['project_import_sheets'] ?? [], 'ai'));
    $check(mb_strpos($notes, '旧版模板') !== false && mb_strpos($notes, '续费联系方式') !== false, '旧版表上传时提醒下载新版');
    $fileNew = $upload($song, '网站模板', $csvOf('网站模板', [$nw("33199$tag" . '00001', ['续费联系方式电话' => '13800138002', '网站域名' => "nx$tag.com"])]));
    $run($song, ['action' => 'repreview', 'business' => '网站模板', 'file_id' => $fileNew, 'all_sheets' => 1]);
    $check(mb_strpos(implode(' ', array_column($_SESSION['project_import_sheets'] ?? [], 'ai')), '旧版模板') === false, '新模板（电话 / 邮箱 / 微信三列）不再被当旧版提示');
    echo "\n=== 网站类 xlsx 模板测试全部通过 ===\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
} finally {
    foreach ($stored as $s) @unlink(ps_private_dir('imports') . '/' . basename($s) . '.php');
}
