<?php
// 域名成本按后缀自动计算：成本中心的 .cn/.com/.net/.org 注册 + 续费价；系统内注册域名自动记订单成本；表格“域名注册 / 域名续费”按域名列后缀带入。
// 只允许本地临时库：DB_NAME=xinzi_sandbox DB_HOST=127.0.0.1 DB_PORT=13399 php tests/domain_cost_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
define('BASE_URL', '');
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectPresets.php';
if ((string)DB_PORT !== '13399' || DB_HOST !== '127.0.0.1') { fwrite(STDERR, "拒绝运行：只能连本地临时库（DB_PORT=13399）\n"); exit(2); }
$pdo = db();
require_once __DIR__ . '/require_isolated_database.php';
require_isolated_test_database($pdo);
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$actor = ['type' => 'system', 'id' => 0, 'employee_id' => null, 'role' => 'finance'];
ps_apply_preset_templates($actor);
$check(ps_apply_preset_templates($actor) === 0, '预置模板重复应用不重复新增');
$want = ['cn' => [35, 35], 'com' => [58, 86], 'net' => [93, 97], 'org' => [85, 92]];
foreach ($want as $tld => [$reg, $renew]) {
    $a = ps_domain_cost_template("shop.$tld", '注册'); $b = ps_domain_cost_template("SHOP.$tld", '续费');
    $check($a && (float)$a['price'] === (float)$reg && $b && (float)$b['price'] === (float)$renew, ".$tld 注册 $reg / 续费 $renew");
}
$check(ps_domain_cost_template('a.com.cn', '注册') === null && ps_domain_cost_template('a.top', '注册') === null && ps_domain_cost_template('', '注册') === null, '.com.cn / 其他后缀 / 空不猜');
$tag = bin2hex(random_bytes(3));
$mk = function ($n, $status = 'draft') use ($pdo, $tag) { $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,shop,contract_amount,order_date,delivery_status,settlement_status) VALUES (?,?,?,?,?,CURDATE(),'unfinished',?)")->execute(["DOMT-$tag-$n", 't', '网站模板', 't', 100, $status]); return (int)$pdo->lastInsertId(); };
$costs = function ($id) use ($pdo) { $q = $pdo->prepare("SELECT item_name,quantity,amount FROM project_costs WHERE order_id=? AND review_status<>'rejected' ORDER BY id"); $q->execute([$id]); return $q->fetchAll(); };
$o = $mk(1);
$check(ps_domain_register_cost($o, 'Foo.com', 1, $actor) === 1, '注册 1 年：一笔');
$c = $costs($o); $check(count($c) === 1 && (float)$c[0]['amount'] === 58.0, '.com 首年 58');
$check(ps_domain_register_cost($o, 'foo.com', 1, $actor) === 0 && count($costs($o)) === 1, '同一域名不重复记');
$o2 = $mk(2);
$check(ps_domain_register_cost($o2, 'bar.net', 5, $actor) === 2, '注册 5 年：首年 + 续费 4 年');
$c = $costs($o2); $check(count($c) === 2 && (float)$c[0]['amount'] === 93.0 && (float)$c[1]['amount'] === 388.0, '.net 93 + 4×97');
$o3 = $mk(3, 'approved'); $check(ps_domain_register_cost($o3, 'x.cn', 1, $actor) === 0 && !$costs($o3), '已审核订单不动');
$templates = ps_intake_templates(null, '网站模板');
$head = ['日期', '店铺', '付款账号', '订单编号', '售价', '财务成本', '成本', '到账情况', '程序名称', '客户手机号', 'QQ', '微信', '模板编号', '域名', '空间+域名'];
$row = fn($name, $dom) => array_replace(array_fill(0, 15, ''), [8 => $name, 13 => $dom, 4 => '100']);
$it = poi_from_row('域名注册', '100', $head, $row('域名注册', 'xixi80.com'), $templates, 1);
$check(count($it) === 1 && (int)$it[0]['template_id'] === (int)ps_domain_cost_template('a.com', '注册')['id'], '表格“域名注册” + .com → 注册价模板');
$it = poi_from_row('域名续费', '100', $head, $row('域名续费', 'abc.org'), $templates, 1);
$check((int)$it[0]['template_id'] === (int)ps_domain_cost_template('a.org', '续费')['id'], '表格“域名续费” + .org → 续费价模板');
$it = poi_from_row('注册域名', '100', $head, $row('注册域名', 'abc.cn'), $templates, 1);
$check((int)$it[0]['template_id'] === (int)ps_domain_cost_template('a.cn', '注册')['id'], '“注册域名” + .cn');
foreach ([['域名', 'abc.com'], ['域名注册', ''], ['域名注册', '域名问题未确定，续费务必检查域名问题'], ['域名注册', 'abc.top']] as [$n, $d]) {
    $it = poi_from_row($n, '100', $head, $row($n, $d), $templates, 1);
    $check(empty($it[0]['template_id']), "不猜：名称“{$n}”域名“{$d}”");
}
echo "domain_cost_smoke 通过\n";
