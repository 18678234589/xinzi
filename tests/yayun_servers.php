<?php
/** 离线测试：雅云服务器目录 / 开通 / 主机管理（SQLite + 接口替身，绝不联网、不扣费）。php -d extension=pdo_sqlite tests/yayun_servers.php */
$c = 0; function yt($ok, $m) { global $c; if (!$ok) throw new RuntimeException('FAIL: ' . $m); $c++; }
function yrej($fn) { try { $fn(); return false; } catch (Throwable $e) { return true; } }
function pas_get($p, $f) { return ''; }
function pas_is_set($p, $f) { return true; }
function pv_admin_username($id) { return $id === 1 ? 'admin' : 'other'; }
function bd_can_access($actor) { return !empty($actor['bd']); }
$GLOBALS['settings'] = [];
function ps_setting_get($k, $d = null) { return $GLOBALS['settings'][$k] ?? $d; }
function ps_setting_set($k, $v, $by) { $GLOBALS['settings'][$k] = $v; }
require __DIR__ . '/../includes/YayunAccess.php';
require __DIR__ . '/../includes/YayunCatalog.php';
require __DIR__ . '/../includes/YayunHosts.php';
require __DIR__ . '/../includes/YayunOrder.php';
require __DIR__ . '/../includes/YayunWallet.php';

// 目录：378 个商品都有国家和品类
$products = ya_products();
yt(count($products) === 378, '378 products in the snapshot');
$bad = array_filter($products, function ($p) { return !isset(ya_countries()[$p['country']]) || !isset(ya_categories()[$p['category']]); });
yt(!$bad, 'every product has a known country and category');
$byCountry = []; foreach ($products as $p) $byCountry[$p['country']] = ($byCountry[$p['country']] ?? 0) + 1;
yt(($byCountry['hk'] ?? 0) >= 40 && ($byCountry['us'] ?? 0) >= 40 && ($byCountry['jp'] ?? 0) >= 16 && ($byCountry['cn'] ?? 0) >= 100, 'main countries populated: ' . json_encode($byCountry));
yt(($byCountry['other'] ?? 0) <= 30, 'few products fall into other: ' . ($byCountry['other'] ?? 0));
$one = ya_find_product(1013); yt($one['country'] === 'cn' && $one['category'] === 'promo', 'promo cn product');
yt(ya_country(29, '美国云服务器', '美国CN2/9929 A区', '美国9929') === 'us', 'us by group');
yt(ya_country(17, '最新活动', '香港/美国/日本轻量云', '香港轻量云服务器 A型') === 'hk' && ya_country(17, '最新活动', '香港/美国/日本轻量云', '日本轻量云') === 'jp', 'mixed zone resolved by product title');
// 视图过滤
$prices = [1013 => ['cycles' => ['monthly' => 99.0, 'annually' => 1188.0], 'stock' => 0, 'stock_control' => 1, 'from_price' => 99.0, 'updated_at' => 1, 'error' => '']];
$v = ya_catalog_view($prices, ['country' => 'cn', 'category' => 'promo']); yt($v['total'] >= 1 && isset($v['tree']['cn']['promo']), 'filtered view');
$v2 = ya_catalog_view($prices, ['q' => '16核', 'only_stock' => true]); $ids = []; foreach ($v2['tree'] as $cat) foreach ($cat as $zones) foreach ($zones as $z) foreach ($z['products'] as $p) $ids[] = $p['id'];
yt(!in_array(1013, $ids, true), 'sold-out product hidden by only_stock');
yt(ya_display_price($prices[1013])['amount'] === 99.0 && ya_display_price(null) === null, 'display price');
yt(ya_cycles_from_pricing(['monthly' => '99.00', 'quarterly' => '297.00', 'biennially' => '-1.00', 'annually' => '1188.00']) === ['monthly' => 99.0, 'quarterly' => 297.0, 'annually' => 1188.0], 'unavailable cycles (-1) dropped');

// 权限
$super = ['type' => 'admin', 'id' => 1]; $staff = ['type' => 'employee', 'id' => 5, 'employee_id' => 19, 'bd' => true]; $luan = ['type' => 'employee', 'id' => 6, 'employee_id' => 55, 'bd' => false]; $other = ['type' => 'employee', 'id' => 7, 'employee_id' => 99, 'bd' => false];
$GLOBALS['settings']['yayun_provisioners'] = ['employees' => [19, 55]];
yt(ya_can_provision($super) && ya_can_provision($staff) && ya_can_provision($luan) && !ya_can_provision($other), 'provisioner list');
yt(ya_can_view($staff) && ya_can_view($luan) && !ya_can_view($other), 'view access: domain staff + provisioners');
$GLOBALS['settings']['yayun_provisioners'] = ['employees' => [55]];
yt(ya_can_view($staff) && !ya_can_provision($staff), 'domain staff can view but not provision when not listed');

// 开通：配置解析
$fixture = ['status' => 200, 'data' => ['products' => ['id' => 1013, 'name' => '测试云', 'qty' => 3, 'stock_control' => 1], 'product_pricings' => [['monthly' => '99.00', 'annually' => '1188.00', 'quarterly' => '-1.00']], 'config_groups' => [['options' => [
    ['id' => 1, 'option_name' => 'area|数据中心', 'hidden' => 0, 'sub' => [['id' => 11, 'option_name' => '27|CN^广州机房', 'qty_minimum' => 0, 'qty_maximum' => 0, 'hidden' => 0]]],
    ['id' => 2, 'option_name' => 'os|操作系统', 'hidden' => 0, 'sub' => [['id' => 21, 'option_name' => '27|CentOS^7', 'qty_minimum' => 0, 'qty_maximum' => 0], ['id' => 22, 'option_name' => '29|Debian^9', 'qty_minimum' => 0, 'qty_maximum' => 0]]],
    ['id' => 3, 'option_name' => 'bw|带宽', 'hidden' => 0, 'unit' => 'M', 'sub' => [['id' => 31, 'qty_minimum' => 25, 'qty_maximum' => 25], ['id' => 32, 'qty_minimum' => 26, 'qty_maximum' => 100]]],
    ['id' => 4, 'option_name' => 'ip_num|IP数量', 'hidden' => 0, 'sub' => [['id' => 41, 'qty_minimum' => 1, 'qty_maximum' => 1]]],
    ['id' => 5, 'option_name' => 'secret|隐藏项', 'hidden' => 1, 'sub' => [['id' => 51, 'qty_minimum' => 1, 'qty_maximum' => 1]]],
]]]]];
$calls = [];
$api = function ($path, array $data = [], $method = 'POST', $t = 30) use (&$calls, &$fixture, &$total, &$settle, &$pay) {
    $calls[] = [$path, $data];
    if ($path === 'cart/get_product_config') return $fixture;
    if ($path === 'cart/get_total') return ['status' => 200, 'products' => ['sale_total' => $total, 'setupfee_total' => '0.00', 'qty' => 3, 'stock_control' => 1]];
    if ($path === 'cart/add_to_shop') return ['status' => 200, 'msg' => '添加成功'];
    if ($path === 'cart/settle') return $settle;
    if ($path === 'apply_credit') return $pay;
    if ($path === 'invoice/detail') throw new RuntimeException('HTTP 404');
    throw new RuntimeException('unexpected ' . $path);
};
$total = '99.00'; $settle = ['status' => 200, 'data' => ['hostid' => [7001], 'invoiceid' => 55, 'total' => '99.00']]; $pay = ['status' => 1001, 'data' => ['hostid' => [7001]]];
$info = yo_options(1013, $api);
yt($info['cycles'] === ['monthly' => 99.0, 'annually' => 1188.0] && count($info['options']) === 4, 'options parsed, hidden dropped');
$kinds = array_column($info['options'], 'kind', 'key'); yt($kinds === ['area' => 'fixed_sub', 'os' => 'select', 'bw' => 'range', 'ip_num' => 'fixed'], 'option kinds: ' . json_encode($kinds));
$cfg = yo_build_config($info['options'], [2 => 22, 3 => 50]); yt($cfg === [1 => 11, 2 => 22, 3 => 50, 4 => 1], 'config built from selection');
yt(yrej(function () use ($info) { yo_build_config($info['options'], [2 => 999]); }), 'unknown OS refused');
yt(yrej(function () use ($info) { yo_build_config($info['options'], [3 => 1000]); }), 'out-of-range bandwidth refused');
yt(yo_build_config($info['options'], [])[2] === 21, 'defaults to the first choice');

// 报价 → 提交
$pdo = new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE yayun_orders (id INTEGER PRIMARY KEY AUTOINCREMENT, token_hash TEXT UNIQUE, actor_key TEXT, pid INTEGER, cycle TEXT, quote_json TEXT, amount_cents INTEGER, status TEXT DEFAULT 'quoted', message TEXT DEFAULT '', upstream_host_id INTEGER, invoice_id INTEGER, created_at INTEGER, started_at INTEGER, finished_at INTEGER)");
$pdo->exec('CREATE TABLE yayun_prices (pid INTEGER PRIMARY KEY, cycles_json TEXT, stock INTEGER, stock_control INTEGER DEFAULT 0, from_price REAL, updated_at INTEGER, error TEXT DEFAULT "")');
$GLOBALS['settings']['yayun_provisioners'] = ['employees' => [19]];
$now = time();
yt(yrej(function () use ($pdo, $other, $api) { yo_quote($pdo, $other, 1013, 'monthly', [], null, $api); }), 'non-provisioner cannot quote');
yt(yrej(function () use ($pdo, $staff, $api) { yo_quote($pdo, $staff, 1013, 'triennially', [], null, $api); }), 'unavailable cycle refused');
$q = yo_quote($pdo, $staff, 1013, 'monthly', [2 => 22, 3 => 50], $now, $api);
yt($q['amount'] === '99.00' && $q['details'] && strpos(implode('|', $q['details']), 'Debian') !== false, 'quote has amount and readable details');
$GLOBALS['settings']['x'] = 1; $calls = [];
yt(yrej(function () use ($pdo, $staff, $q, $api) { yo_submit($pdo, $staff, $q['token'], '1013', '0', null, $api); }) && !array_filter($calls, function ($x) { return $x[0] === 'cart/add_to_shop'; }), 'ack required before any purchase call');
yt(yrej(function () use ($pdo, $staff, $q, $api) { yo_submit($pdo, $staff, $q['token'], '9', '1', null, $api); }), 'wrong product number refused');
yt(yrej(function () use ($pdo, $staff, $q, $api, $now) { yo_submit($pdo, $staff, $q['token'], '1013', '1', $now + 700, $api); }), 'expired quote refused');
$total = '120.00';
yt(yrej(function () use ($pdo, $staff, $q, $api) { yo_submit($pdo, $staff, $q['token'], '1013', '1', null, $api); }), 'price change blocks purchase'); $total = '99.00';
$calls = [];
$row = yo_submit($pdo, $staff, $q['token'], '1013', '1', null, $api);
$paths = array_column($calls, 0);
yt($row['status'] === 'done' && (int)$row['upstream_host_id'] === 7001 && in_array('cart/add_to_shop', $paths, true) && in_array('cart/settle', $paths, true) && in_array('apply_credit', $paths, true), 'successful purchase flow: ' . implode(',', $paths));
$add = array_values(array_filter($calls, function ($x) { return $x[0] === 'cart/add_to_shop'; }))[0][1];
yt($add['configoption'] === [1 => 11, 2 => 22, 3 => 50, 4 => 1] && $add['pid'] === 1013 && $add['billingcycle'] === 'monthly' && strlen($add['password']) >= 10, 'quoted configuration reaches the purchase call');
$calls = []; $again = yo_submit($pdo, $staff, $q['token'], '1013', '1', null, $api);
yt($again['status'] === 'done' && !array_filter($calls, function ($x) { return in_array($x[0], ['cart/add_to_shop', 'cart/settle', 'apply_credit'], true); }), 'resubmitting the same quote never buys twice');
// 金额不一致 / 多台主机：不支付
$q2 = yo_quote($pdo, $staff, 1013, 'monthly', [], null, $api); $settle = ['status' => 200, 'data' => ['hostid' => [7002], 'invoiceid' => 56, 'total' => '198.00']]; $calls = [];
$r2 = yo_submit($pdo, $staff, $q2['token'], '1013', '1', null, $api);
yt($r2['status'] === 'unknown' && !in_array('apply_credit', array_column($calls, 0), true) && strpos($r2['message'], '不一致') !== false, 'invoice amount mismatch is never paid');
$q3 = yo_quote($pdo, $staff, 1013, 'monthly', [], null, $api); $settle = ['status' => 200, 'data' => ['hostid' => [7003, 7004], 'invoiceid' => 57, 'total' => '99.00']]; $calls = [];
$r3 = yo_submit($pdo, $staff, $q3['token'], '1013', '1', null, $api);
yt($r3['status'] === 'unknown' && !in_array('apply_credit', array_column($calls, 0), true), 'two hosts in one invoice is never paid');
$q4 = yo_quote($pdo, $staff, 1013, 'monthly', [], null, $api); $settle = ['status' => 200, 'data' => ['hostid' => [7005], 'invoiceid' => 58]]; $pay = ['status' => 400, 'msg' => '余额不足']; $calls = [];
$r4 = yo_submit($pdo, $staff, $q4['token'], '1013', '1', null, $api);
yt($r4['status'] === 'unknown' && strpos($r4['message'], '余额') !== false, 'unpaid invoice is reported, not retried');
$q5 = yo_quote($pdo, $staff, 1013, 'monthly', [], null, $api); $boom = function ($path, array $data = [], $m = 'POST', $t = 30) use ($api) { if ($path === 'cart/settle') throw new RuntimeException('timeout'); return $api($path, $data, $m, $t); };
$r5 = yo_submit($pdo, $staff, $q5['token'], '1013', '1', null, $boom); yt($r5['status'] === 'unknown', 'interruption leaves an unknown, never silently retried');
$total = '4000.00'; yt(yrej(function () use ($pdo, $staff, $api) { yo_quote($pdo, $staff, 1013, 'monthly', [], null, $api); }), 'over the per-order cap is refused'); $total = '99.00';
yt(count(yo_history($pdo)) === 5, 'history lists submitted orders only (not bare quotes)');

// 价格同步
$sync = yo_sync_prices($pdo, 30, $now, $api);
yt($sync['total'] === 378 && $sync['synced'] > 0, 'price sync walks the catalog');
$map = ya_price_map($pdo); yt(isset($map[1013]) && (float)$map[1013]['cycles']['monthly'] === 99.0 && $map[1013]['stock'] === 3, 'price cache readable');

// 主机列表 / 详情
$hostApi = function ($path, array $data = [], $m = 'GET', $t = 30) {
    if ($path === 'host/list') { $rows = []; for ($i = 1; $i <= 120; $i++) $rows[] = ['id' => $i, 'domain' => 'h' . $i, 'domainstatus' => 'Active', 'dedicatedip' => '1.1.1.' . $i, 'nextduedate' => time() + 86400 * 20, 'amount' => '99', 'password' => 'SECRET']; $p = (int)$data['page']; return ['status' => 200, 'data' => ['sum' => 120, 'list' => array_slice($rows, ($p - 1) * 100, 100)]]; }
    if ($path === 'host/header') return ['status' => 200, 'data' => ['host_data' => ['id' => (int)$data['host_id'], 'domain' => 'h', 'username' => 'root', 'password' => 'S3cret', 'port' => 22, 'dedicatedip' => '1.1.1.1'], 'cloud_os_group' => [['id' => 1, 'name' => 'Linux']], 'cloud_os' => [['id' => 9, 'group' => 1, 'name' => 'Debian']]]];
    if ($path === 'provision/default') return ['status' => 200, 'msg' => 'ok', 'data' => ['url' => 'https://vnc.example/x']];
    throw new RuntimeException('unexpected ' . $path);
};
list($rows, $totalHosts) = yh_list($hostApi); yt(count($rows) === 120 && $totalHosts === 120, 'host list pages through all hosts');
yt(strpos(json_encode($rows), 'SECRET') === false, 'list never carries passwords');
yt(yh_detail(5, false, $hostApi)['password'] === '' && yh_detail(5, true, $hostApi)['password'] === 'S3cret', 'password only for managers');
yt(yrej(function () use ($hostApi) { yh_detail(5, false, function ($p, $d = [], $m = 'GET', $t = 30) { return ['status' => 200, 'data' => ['host_data' => ['id' => 6]]]; }); }), 'mismatched host id refused');
list(, $vnc) = yh_action(5, 'vnc', [], $hostApi); yt($vnc['url'] === 'https://vnc.example/x', 'vnc url returned');
yt(yrej(function () use ($hostApi) { yh_action(5, 'reinstall', ['os' => 9], $hostApi); }), 'reinstall needs host number confirmation');
yt(yrej(function () use ($hostApi) { yh_action(5, 'reset_password', ['password' => 'short'], $hostApi); }), 'weak password refused');
yt(yrej(function () use ($hostApi) { yh_action(5, 'rm -rf', [], $hostApi); }), 'unknown action refused');
yt(in_array('reinstall', yh_manage_actions(), true) && !in_array('reboot', yh_manage_actions(), true), 'destructive actions are manager-only, reboot is not');
// 成本价：按默认配置逐周期取 cart/get_total 的 sale_total；折扣后低于标价
$discount = function ($path, array $data = [], $m = 'POST', $t = 30) use ($api) { if ($path === 'cart/get_total') return ['status' => 200, 'products' => ['sale_total' => (string)round(($data['billingcycle'] === 'annually' ? 1188 : 99) * 0.92, 2), 'setupfee_total' => '0.00', 'qty' => 3, 'stock_control' => 1]]; return $api($path, $data, $m, $t); };
$cost = yo_options(1013, $discount, true);
yt($cost['cycles'] === ['monthly' => 91.08, 'annually' => 1092.96] && $cost['list_cycles'] === ['monthly' => 99.0, 'annually' => 1188.0] && $cost['cost_ok'], 'cost price = discounted sale_total per cycle, list kept separately');
$plain = yo_options(1013, $api, false); yt($plain['cycles'] === ['monthly' => 99.0, 'annually' => 1188.0] && !$plain['cost_ok'], 'without cost lookup the list price is returned');
$pdo->exec('DELETE FROM yayun_prices'); $discountSync = $discount; yo_sync_prices($pdo, 5, $now, $discount); $cm = ya_price_map($pdo);
yt(isset($cm[1013]) && (float)$cm[1013]['cycles']['monthly'] === 91.08 && (float)$cm[1013]['list']['monthly'] === 99.0, 'price cache stores cost and list');
$pdo->prepare('REPLACE INTO yayun_prices (pid,cycles_json,stock,stock_control,from_price,updated_at,error) VALUES (?,?,?,?,?,?,?)')->execute([5, '{"monthly":50}', 1, 0, 50, 1, '']); yt((float)ya_price_map($pdo)[5]['cycles']['monthly'] === 50.0, 'old flat cache format still readable');
// 余额与充值
$walletCalls = [];
$wallet = function ($path, array $data = [], $m = 'GET', $t = 30) use (&$walletCalls) { $walletCalls[] = [$path, $data]; if ($path === 'user_info') return ['status' => 200, 'user' => ['credit' => '3296.59', 'is_open_credit_limit' => 0], 'gateways' => [['id' => 27, 'name' => 'AliPay', 'title' => '官方支付', 'status' => 1], ['id' => 28, 'name' => 'Off', 'title' => '停用', 'status' => 0]]]; if ($path === 'recharge') return ['status' => 200, 'data' => ['invoice_id' => '900001']]; throw new RuntimeException('unexpected ' . $path); };
$b = ya_balance($wallet); yt($b['balance'] === '3296.59' && count($b['gateways']) === 1 && $b['gateways'][0]['name'] === 'AliPay', 'balance and enabled gateways only');
$r = ya_recharge('500', $wallet, 'https://y.example'); yt($r['invoice_id'] === 900001 && $r['url'] === 'https://y.example/viewbilling?id=900001' && $r['amount'] === '500.00', 'recharge returns the invoice page url');
$last = end($walletCalls); yt($last[0] === 'recharge' && $last[1]['payment'] === 'AliPay' && $last[1]['amount'] === '500.00', 'recharge sends amount and gateway name');
foreach (['0', '0.5', '50001', 'abc', '10.123', '-5'] as $bad) yt(yrej(function () use ($bad, $wallet) { ya_recharge($bad, $wallet, 'https://y.example'); }), 'bad recharge amount refused: ' . $bad);
echo json_encode(['passed' => $c, 'failed' => 0, 'real_provider_calls' => 0]) . "\n";
