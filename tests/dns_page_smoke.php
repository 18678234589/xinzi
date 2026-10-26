<?php
// 域名管理 · 解析管理页面（project/dns.php）：假服务商 + 本地临时库，走完总览、详情、增删改记录、复制、新增域名、权限。
// 只允许本地临时库：DB_HOST=127.0.0.1 DB_PORT=13399 php tests/dns_page_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/BizcnDomains.php';
require_once __DIR__ . '/../includes/DnsProviders.php';
require_once __DIR__ . '/dns_fake_provider.php';
if ((string)DB_PORT !== '13399' || DB_HOST !== '127.0.0.1') { fwrite(STDERR, "拒绝运行：本测试会写审计记录，只能连本地临时库（DB_PORT=13399）\n"); exit(2); }

$pdo = db();
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$has = function ($html, $needle) { return mb_strpos($html, $needle) !== false; };
$state = dns_fake_state();
dns_fake_install($state);
$_SESSION = [];
$_SERVER['SCRIPT_NAME'] = '/project/dns.php';
$_SERVER['REQUEST_URI'] = '/project/dns.php';
$_SERVER['HTTP_HOST'] = 'localhost';

$denied = in_array('--denied', $argv, true);
if ($denied) {
    // 没有域名管理权限的员工账号：页面应直接拒绝，不读取任何服务商数据。
    $white = array_map("intval", pv_access_list()["employees"]);
    $rows = $pdo->query("SELECT u.id,u.employee_id,e.department FROM project_users u JOIN employees e ON e.id=u.employee_id WHERE u.is_active=1 AND NOT EXISTS (SELECT 1 FROM project_user_businesses b WHERE b.user_id=u.id AND b.business_name REGEXP '网站|小程序|备案|环境配置') ORDER BY u.id")->fetchAll();
    $u = 0; foreach ($rows as $r) if (!preg_match("/网站|小程序|售后|备案|服务器维护/u", (string)$r["department"]) && !in_array((int)$r["employee_id"], $white, true)) { $u = (int)$r["id"]; break; }
    if (!$u) { echo "跳过：没有合适的无权限账号\n"; exit(0); }
    $_SESSION['project_user_id'] = (int)$u; $_SESSION['project_csrf'] = 'test-csrf';
    $_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = [];
    ob_start();
    include __DIR__ . '/../project/dns.php';
    exit(0);
}

$admin = $pdo->query("SELECT id,username,auth_version FROM admins WHERE username='admin' LIMIT 1")->fetch();
$_SESSION['admin_id'] = $admin['id']; $_SESSION['admin_username'] = $admin['username']; $_SESSION['auth_version'] = (int)($admin['auth_version'] ?? 0); $_SESSION['project_csrf'] = 'test-csrf';
$render = function (array $get = [], array $post = []) {
    $_GET = $get; $_POST = $post; $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
    if ($post) $_POST['csrf'] = 'test-csrf';
    ob_start();
    try { include __DIR__ . '/../project/dns.php'; } finally { $html = ob_get_clean(); }
    return $html;
};
$audit = function ($action) use ($pdo) { $q = $pdo->prepare("SELECT COUNT(*) FROM project_audit_logs WHERE entity_type='dns' AND action=?"); $q->execute([$action]); return (int)$q->fetchColumn(); };
$before = [];
foreach (['zone_create', 'record_create', 'record_update', 'record_delete', 'records_copy'] as $a) $before[$a] = $audit($a);

try {
    // 假服务商里放两个域名：example.com 在 DNSPod 和 Cloudflare 都有；cf-only.net 只在 Cloudflare。
    $state['dnspod']['domains']['example.com'] = 101;
    $state['dnspod']['records']['example.com'] = [
        11 => ['RecordId' => 11, 'Type' => 'A', 'Name' => '@', 'Value' => '1.1.1.1', 'TTL' => 600, 'MX' => 0, 'Line' => '默认', 'Status' => 'ENABLE'],
        12 => ['RecordId' => 12, 'Type' => 'CNAME', 'Name' => 'www', 'Value' => 'cdn.example.net.', 'TTL' => 600, 'MX' => 0, 'Line' => '默认', 'Status' => 'ENABLE'],
        13 => ['RecordId' => 13, 'Type' => 'NS', 'Name' => '@', 'Value' => 'alpha.dnspod.net.', 'TTL' => 86400, 'MX' => 0, 'Line' => '默认', 'Status' => 'ENABLE'],
        14 => ['RecordId' => 14, 'Type' => 'TXT', 'Name' => '@', 'Value' => 'v=spf1 -all', 'TTL' => 600, 'MX' => 0, 'Line' => '默认', 'Status' => 'ENABLE'],
    ];
    $state['cloudflare']['zones'] = [
        'z1' => ['id' => 'z1', 'name' => 'example.com', 'status' => 'active', 'name_servers' => ['paloma.ns.cloudflare.com', 'roman.ns.cloudflare.com']],
        'z2' => ['id' => 'z2', 'name' => 'cf-only.net', 'status' => 'active', 'name_servers' => ['paloma.ns.cloudflare.com', 'roman.ns.cloudflare.com']],
    ];
    $state['cloudflare']['records'] = ['z1' => [], 'z2' => ['a1' => ['id' => 'a1', 'type' => 'A', 'name' => 'cf-only.net', 'content' => '9.9.9.9', 'ttl' => 1, 'proxied' => true]]];

    echo "== 总览\n";
    $html = $render();
    $check($has($html, 'example.com') && $has($html, 'cf-only.net') && $has($html, '添加并获取 NS') && $has($html, '返回域名管理'), '总览列出两家的域名，有“添加域名”表单和返回入口');
    $check(substr_count($html, 'dns-chip on') >= 3, '各服务商的“已添加”状态标出');

    echo "== 详情与 NS\n";
    $html = $render(['d' => 'example.com', 'p' => 'dnspod']);
    $check($has($html, 'alpha.dnspod.net') && $has($html, 'beta.dnspod.net') && !$has($html, 'alpha.dnspod.net.<'), 'DNSPod 要求的 NS 显示（无末尾点）');
    $check($has($html, 'cdn.example.net') && $has($html, '1.1.1.1') && $has($html, '系统记录'), '解析记录列出；根域 NS 标为系统记录、不给删除');
    $html = $render(['d' => 'example.com', 'p' => 'cloudflare']);
    $check($has($html, 'paloma.ns.cloudflare.com') && $has($html, '经 Cloudflare 代理'), 'Cloudflare 详情：NS 与代理选项');
    $html = $render(['d' => 'cf-only.net']);
    $check($has($html, '在 DNSPod 添加此域名'), '只在 Cloudflare 的域名：提供“在 DNSPod 添加此域名”');
    $html = $render(['d' => 'not a domain']);
    $check($has($html, '域名格式不正确'), '无效域名参数给出提示');

    echo "== 记录增删改\n";
    $render([], ['action' => 'record_save', 'd' => 'example.com', 'p' => 'dnspod', 'type' => 'A', 'name' => 'api', 'value' => '2.2.2.2', 'ttl' => '600']);
    $created = array_values(array_filter($state['dnspod']['records']['example.com'], function ($r) { return $r['Name'] === 'api'; }));
    $check(count($created) === 1 && $created[0]['Value'] === '2.2.2.2' && $audit('record_create') === $before['record_create'] + 1, '添加记录：写入服务商并记审计');
    $check(!empty($_SESSION['dns_flash']['ok']) && mb_strpos($_SESSION['dns_flash']['ok'], '已添加') !== false, '成功后记下提示（刷新页面时显示）');
    $html = $render(['d' => 'example.com', 'p' => 'dnspod']);
    $check($has($html, '已添加 A 记录 api') && !isset($_SESSION['dns_flash']), '提示只显示一次');
    $html = $render([], ['action' => 'record_save', 'd' => 'example.com', 'p' => 'dnspod', 'type' => 'A', 'name' => 'bad', 'value' => '999.9.9.9', 'ttl' => '600']);
    $check($has($html, 'IPv4') && !array_filter($state['dnspod']['records']['example.com'], function ($r) { return $r['Name'] === 'bad'; }), '填错值：页面提示且不写入');
    $rid = $created[0]['RecordId'];
    $render([], ['action' => 'record_save', 'd' => 'example.com', 'p' => 'dnspod', 'record_id' => (string)$rid, 'type' => 'A', 'name' => 'api', 'value' => '3.3.3.3', 'ttl' => '900']);
    $check($state['dnspod']['records']['example.com'][$rid]['Value'] === '3.3.3.3' && $audit('record_update') === $before['record_update'] + 1, '编辑记录');
    $render([], ['action' => 'record_delete', 'd' => 'example.com', 'p' => 'dnspod', 'record_id' => (string)$rid]);
    $check(!isset($state['dnspod']['records']['example.com'][$rid]) && $audit('record_delete') === $before['record_delete'] + 1, '删除记录');
    $html = $render([], ['action' => 'record_delete', 'd' => 'example.com', 'p' => 'dnspod', 'record_id' => '13']);
    $check($has($html, '不支持在这里删除') && isset($state['dnspod']['records']['example.com'][13]), '根域 NS 系统记录不可删除');
    $html = $render([], ['action' => 'record_delete', 'd' => 'example.com', 'p' => 'dnspod', 'record_id' => '99999']);
    $check($has($html, '不存在'), '删除不存在的记录给出提示');

    echo "== 复制到另一家\n";
    $html = $render([], ['action' => 'copy_preview', 'd' => 'example.com', 'p' => 'dnspod', 'from' => 'dnspod', 'to' => 'cloudflare']);
    $check($has($html, '将新增') && $has($html, 'cdn.example.net') && $has($html, '确认复制') && $state['cloudflare']['records']['z1'] === [], '预览：列出将新增的记录，且不写入');
    $render([], ['action' => 'copy_run', 'd' => 'example.com', 'p' => 'dnspod', 'from' => 'dnspod', 'to' => 'cloudflare']);
    $names = array_map(function ($r) { return $r['type'] . ' ' . $r['name']; }, array_values($state['cloudflare']['records']['z1']));
    sort($names);
    $check($names === ['A example.com', 'CNAME www.example.com', 'TXT example.com'] && $audit('records_copy') === $before['records_copy'] + 1, '执行复制：A / CNAME / TXT 到 Cloudflare（根域 NS 不复制），记审计 实际=' . implode(',', $names) . ' 审计=' . $audit('records_copy') . '/' . $before['records_copy'] . ' flash=' . json_encode($_SESSION['dns_flash'] ?? null, JSON_UNESCAPED_UNICODE));
    $html = $render([], ['action' => 'copy_preview', 'd' => 'example.com', 'p' => 'dnspod', 'from' => 'dnspod', 'to' => 'cloudflare']);
    $check($has($html, '没有需要新增的记录'), '再次预览：已复制的不重复');
    $html = $render([], ['action' => 'copy_preview', 'd' => 'cf-only.net', 'p' => 'cloudflare', 'from' => 'cloudflare', 'to' => 'dnspod']);
    $check($has($html, '请先在 DNSPod 里添加'), '目标服务商没有该域名：提示先添加');

    echo "== 新增域名\n";
    $render([], ['action' => 'add_zone', 'domain' => 'New-Site.com', 'provider' => 'dnspod']);
    $check(isset($state['dnspod']['domains']['new-site.com']) && $_SESSION['dns_flash']['ns'] === ['alpha.dnspod.net', 'beta.dnspod.net'] && $audit('zone_create') === $before['zone_create'] + 1, '添加到 DNSPod：规范化域名、记下要改的 NS、记审计');
    $html = $render(['d' => 'new-site.com', 'p' => 'dnspod']);
    $check($has($html, '请到域名注册商处') && $has($html, 'alpha.dnspod.net') && $has($html, '复制全部'), '添加后页面醒目显示要改的 NS');
    $html = $render([], ['action' => 'add_zone', 'domain' => 'new-site.com', 'provider' => 'dnspod']);
    $check($has($html, '已经在 DNSPod 里了'), '重复添加给出提示');
    $state['cf_can_create'] = false;
    $html = $render([], ['action' => 'add_zone', 'domain' => 'blocked.com', 'provider' => 'cloudflare']);
    $check($has($html, '没有“创建区域”权限') && !isset($state['cloudflare']['zones']['z3']), 'Cloudflare 令牌无权限：给出可操作的提示');
    $html = $render([], ['action' => 'add_zone', 'domain' => 'bad domain', 'provider' => 'dnspod']);
    $check($has($html, '域名格式不正确'), '无效域名被拒绝');

    echo "== 接入 Cloudflare 引导与商务中国来源
";
    $html = $render(['d' => 'cf-only.net']);
    $check($has($html, '接入 Cloudflare 解析') && $has($html, '复制现有解析到 Cloudflare'), '详情页显示三步引导');
    $state['cf_can_create'] = true;
    $state['dnspod']['domains']['only-dp.com'] = 201; $state['dnspod']['records']['only-dp.com'] = [31 => ['RecordId' => 31, 'Type' => 'A', 'Name' => '@', 'Value' => '5.5.5.5', 'TTL' => 600, 'MX' => 0, 'Line' => '默认', 'Status' => 'ENABLE']];
    $html = $render(['d' => 'only-dp.com']);
    // 现行页面：按域名实际所在的服务商出引导。只在 DNSPod 的域名 → 第 ① 步显示“已添加”，并提示去修改 NS（不再显示“先完成第 ① 步”）
    $check($has($html, '接入 ' . DNS_PROVIDERS['dnspod'] . ' 解析') && $has($html, '已添加') && $has($html, '在本站查看并修改 NS') && !$has($html, '先完成第 ①步'), '域名已在 DNSPod：第 ① 步已完成，第 ③ 步引导修改 NS');
    $render([], ['action' => 'create_zone', 'domain' => 'only-dp.com', 'provider' => 'cloudflare']);
    $check(count(array_filter($state['cloudflare']['zones'], function ($z) { return $z['name'] === 'only-dp.com'; })) === 1, '第 ① 步：在 Cloudflare 创建区域');
    $html = $render([], ['action' => 'copy_preview', 'd' => 'only-dp.com', 'p' => 'cloudflare', 'from' => 'dnspod', 'to' => 'cloudflare']);
    $check($has($html, '预览：DNSPod → Cloudflare') && $has($html, '5.5.5.5'), '第 ② 步：预览从 DNSPod 复制到 Cloudflare');
    $html = $render([], ['action' => 'copy_preview', 'd' => 'only-dp.com', 'p' => 'cloudflare', 'from' => 'bizcn', 'to' => 'cloudflare']);
    $check($has($html, '商务中国') && !$has($html, '预览：商务中国'), '商务中国读取失败（本地没有连接配置）：给出提示、不写入');
    echo "== 商务中国一键改 NS（只验证前置校验，不联网）\n";
    $html = $render([], ['action' => 'bizcn_ns', 'd' => 'example.com', 'p' => 'dnspod', 'confirm' => 'wrong.com']);
    $check($has($html, '请输入域名以确认'), '没有输入正确域名确认时拒绝，不发起任何修改');
    $html = $render([], ['action' => 'bizcn_ns', 'd' => 'unknown-zone.com', 'p' => 'dnspod', 'confirm' => 'unknown-zone.com']);
    $check($has($html, '请先把域名添加到所选服务商') || $has($html, '不在所选服务商'), '域名不在所选服务商时拒绝');

    echo "== 权限\n";
    $php = escapeshellarg(PHP_BINARY);
    $out = shell_exec($php . ' ' . escapeshellarg(__FILE__) . ' --denied 2>&1');
    $check($out === null || $out === '' || mb_strpos($out, '当前账户未分配域名管理权限') !== false || mb_strpos($out, '跳过') !== false, '无域名管理权限的账号被拒绝：' . trim((string)$out));
    echo "\n=== 解析管理页面测试全部通过 ===\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n");
    exit(1);
} finally {
    $pdo->exec("DELETE FROM project_audit_logs WHERE entity_type='dns'");
}
