<?php
/** 离线测试：订单域名（本地 SQLite，不联网）。php -d extension=pdo_sqlite tests/bizcn_order_domains.php */
$checks = 0;
function ot($ok, $message) { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $message); $checks++; }
function orej($fn) { try { $fn(); return false; } catch (Throwable $e) { return true; } }
$audit = []; $publicNs = [];
function ps_audit($entity, $id, $action, $actor, $data = []) { global $audit; $audit[] = [$action, $data]; }
function bd_domain($value) { $v = strtolower(trim((string)$value)); if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/D', $v)) throw new InvalidArgumentException('bad domain'); return $v; }
function dns_public_ns($domain) { global $publicNs; return $publicNs[$domain] ?? []; }
function bdr_provider(array $ns) { if (!$ns) return 'none'; foreach ($ns as $h) if (!preg_match('/\.cnmsn\.net$/', $h)) return 'other'; return 'bizcn'; }
$p = new PDO('sqlite::memory:'); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
function db() { global $p; return $p; }
require __DIR__ . '/../includes/BizcnDomainStatus.php';
require __DIR__ . '/../includes/BizcnOrderDomains.php';
$p->exec('CREATE TABLE project_orders (id INTEGER PRIMARY KEY, order_no TEXT, customer_name TEXT)');
$p->exec('CREATE TABLE project_participants (order_id INTEGER, employee_id INTEGER)');
$p->exec('CREATE TABLE project_renewal_items (order_id INTEGER, resource_type TEXT, resource_name TEXT, expires_on TEXT)');
$p->exec('CREATE TABLE project_order_details (order_id INTEGER, details_json TEXT)');
$p->exec('CREATE TABLE bizcn_order_domains (order_id INTEGER, domainname TEXT, source TEXT DEFAULT "renewal", expires_on TEXT, ns_hosts TEXT DEFAULT "", ns_provider TEXT DEFAULT "", ns_checked_at INTEGER DEFAULT 0, created_by TEXT DEFAULT "", created_at TEXT, PRIMARY KEY(order_id,domainname))');
$p->exec('CREATE TABLE bizcn_domain_catalog (provider_account TEXT, domainname TEXT, display_name TEXT DEFAULT "", registrar TEXT DEFAULT "", owner_username TEXT DEFAULT "", regdate TEXT, expireddate TEXT, status TEXT DEFAULT "", source_as_of TEXT DEFAULT "2026-10-14", PRIMARY KEY(provider_account,domainname))');
$p->exec('CREATE TABLE bizcn_domain_status (provider_account TEXT, domainname TEXT, audit_status TEXT DEFAULT "", audit_code TEXT DEFAULT "", audit_reason TEXT DEFAULT "", dnvc_status TEXT DEFAULT "", whois_status TEXT DEFAULT "", status_text TEXT DEFAULT "", expire_date TEXT, checked_at INTEGER NOT NULL, error TEXT DEFAULT "", PRIMARY KEY(provider_account,domainname))');
$now = strtotime('2026-10-14 12:00:00 +0800');
foreach ([[1, 'A001', '甲客户'], [2, 'A002', '乙客户'], [3, 'A003', '丙客户'], [4, 'A004', '丁客户']] as $o) $p->prepare('INSERT INTO project_orders VALUES (?,?,?)')->execute($o);
// 员工 10 参与订单 1、2；员工 11 参与订单 3；订单 4 无人
foreach ([[1, 10], [2, 10], [3, 11]] as $x) $p->prepare('INSERT INTO project_participants VALUES (?,?)')->execute($x);
$p->exec("INSERT INTO project_renewal_items VALUES (1,'domain','one.com','2026-10-20'),(1,'domain','one.com','2027-10-20'),(2,'domain','two.cn','2026-09-01'),(3,'domain','three.net',NULL),(3,'server','not-a-domain-row','2027-01-01'),(1,'domain','垃圾文本','2027-01-01')");
$p->exec("INSERT INTO project_order_details VALUES (4,'{\"website_url\":\"https://www.four.com/index\"}'),(2,'{\"website_url\":\"https://two.cn\"}')");
$p->exec("INSERT INTO bizcn_domain_catalog (provider_account,domainname,registrar,regdate,expireddate,status) VALUES ('acct','one.com','商中','2025-10-20','2027-10-20','转移保护'),('acct','two.cn','商中','2024-09-01','2026-09-01','正常')");
bs_store($p, 'acct', 'two.cn', ['audit_status' => 'not realname', 'dnvc_status' => '1', 'status_text' => 'ok,', 'expire_date' => '2026-09-01'], $now);

// 重建：同一订单同一域名只留一条（取较晚的到期日），非域名与垃圾文本忽略，订单登记的网站也算
list($add, $upd, $del) = bo_rebuild($p);
ot($add === 4, 'links created: ' . $add);
ot($p->query("SELECT expires_on FROM bizcn_order_domains WHERE domainname='one.com'")->fetchColumn() === '2027-10-20', 'latest renewal date kept');
ot($p->query("SELECT source FROM bizcn_order_domains WHERE domainname='two.cn' AND order_id=2")->fetchColumn() === 'renewal', 'renewal source wins over detail');
ot($p->query("SELECT source FROM bizcn_order_domains WHERE domainname='four.com'")->fetchColumn() === 'detail', 'website_url link');
list($a2, $u2, $d2) = bo_rebuild($p); ot($a2 === 0 && $u2 === 0 && $d2 === 0, 'rebuild is idempotent');

// 手动 / 注册绑定在重建时保留；自动来源失效后被清理
$emp10 = ['type' => 'employee', 'id' => 5, 'employee_id' => 10, 'role' => 'technical'];
$emp11 = ['type' => 'employee', 'id' => 6, 'employee_id' => 11, 'role' => 'customer_service'];
$admin = ['type' => 'admin', 'id' => 1];
$r = bo_action('order_bind', ['domain' => 'manual.com', 'order_no' => 'A001'], $emp10);
ot(count($r['orders']) === 1 && $r['orders'][0]['source'] === 'manual', 'participant can bind');
ot(orej(function () use ($emp11) { bo_action('order_bind', ['domain' => 'manual.com', 'order_no' => 'A001'], $emp11); }), 'non-participant cannot bind');
ot(orej(function () use ($emp10) { bo_action('order_bind', ['domain' => 'manual.com', 'order_no' => 'A999'], $emp10); }), 'unknown order refused');
bo_action('order_bind', ['domain' => 'manual.com', 'order_no' => 'A004'], $admin);
$p->exec("DELETE FROM project_renewal_items WHERE resource_name='three.net'");
list(, , $del) = bo_rebuild($p); ot($del === 1 && $p->query("SELECT COUNT(*) FROM bizcn_order_domains WHERE domainname='three.net'")->fetchColumn() == 0, 'stale auto link removed');
ot($p->query("SELECT COUNT(*) FROM bizcn_order_domains WHERE domainname='manual.com'")->fetchColumn() == 2, 'manual links survive rebuild');
bo_link($p, 1, 'one.com', 'registration', $emp10);
ot($p->query("SELECT source FROM bizcn_order_domains WHERE order_id=1 AND domainname='one.com'")->fetchColumn() === 'registration', 'registration link upgrades auto link');
bo_rebuild($p); ot($p->query("SELECT source FROM bizcn_order_domains WHERE order_id=1 AND domainname='one.com'")->fetchColumn() === 'registration', 'registration link survives rebuild');
$r = bo_action('order_unbind', ['domain' => 'manual.com', 'order_no' => 'A001'], $emp10); ot(count($r['orders']) === 1, 'unbind removes only that order');
ot(bo_order_for_actor($p, $emp10, 'A002')['id'] == 2 && orej(function () use ($p, $emp10) { bo_order_for_actor($p, $emp10, 'A003'); }), 'registration order must be own order');

// 我的订单域名：只含自己参与订单；一域名一行；到期日取注册商 / 状态表，否则订单登记日期
$mine = bo_my_list($p, 'acct', $emp10, '', '', 1, $now);
ot(array_column($mine['rows'], 'domainname') === ['two.cn', 'one.com'], 'employee sees own orders only, soonest expiry first: ' . implode(',', array_column($mine['rows'], 'domainname')));
ot($mine['rows'][0]['order_no'] === 'A002' && strpos($mine['rows'][0]['owner_username'], '乙客户') === 0, 'row carries order and customer');
ot($mine['rows'][1]['source'] === 'bizcn', 'catalog rows marked as Bizcn');
$tags = array_column($mine['rows'][0]['status_tags'], 'key'); ot(in_array('unsubmitted', $tags, true) && in_array('expired', $tags, true), 'status badges on my rows');
$other = bo_my_list($p, 'acct', $emp11, '', '', 1, $now); ot(count($other['rows']) === 0, 'other employee sees nothing of theirs not linked');
$all = bo_my_list($p, 'acct', $admin, '', '', 1, $now); ot($all['total'] === 4, 'admin sees every linked domain (4)');
$s = bo_my_list($p, 'acct', $emp10, '', '乙客户', 1, $now); ot($s['total'] === 1, 'search by customer');
$s = bo_my_list($p, 'acct', $emp10, '', 'A001', 1, $now); ot($s['total'] === 1 && $s['rows'][0]['domainname'] === 'one.com', 'search by order no');
$s = bo_my_list($p, 'acct', $emp10, '', '%', 1, $now); ot($s['total'] === 0, 'wildcard escaped');
$s = bo_my_list($p, 'acct', $emp10, 'expired', '', 1, $now); ot($s['total'] === 1 && $s['rows'][0]['domainname'] === 'two.cn', 'status filter inside my scope');
$c = bo_my_counts($p, 'acct', $emp10, $now); ot($c['total'] === 2 && $c['counts']['expired'] === 1 && $c['counts']['unsubmitted'] === 1, 'my status counts');
ot(bo_mine_total($p, 'acct', $emp10) === 2 && bo_mine_total($p, 'acct', $emp11) === 0, 'tab total per employee');

// 外部（不在商务中国目录）订单域名也显示，用订单登记的到期日；NS 归属一天刷新一次且受时间限制
$o3 = bo_my_list($p, 'acct', $emp11, '', '', 1, $now); ot(count($o3['rows']) === 0, 'removed link no longer listed');
$p->exec("INSERT INTO bizcn_order_domains (order_id,domainname,source,expires_on) VALUES (3,'ext.org','renewal','2026-10-25')");
$row = bo_my_list($p, 'acct', $emp11, '', '', 1, $now)['rows'][0];
ot($row['source'] === 'renewal' && $row['registrar'] === '外部注册商（订单登记）' && $row['expireddate'] === '2026-10-25' && $row['status_tags'][0]['key'] === 'expiring', 'external order domain row + expiring badge');
$publicNs = ['ext.org' => ['a.ns.cloudflare.com'], 'one.com' => ['ns7.cnmsn.net', 'ns8.cnmsn.net']];
ot(bo_ns_refresh($p, 50, 30, $now) === 5, 'ns refresh covers every distinct domain once');
$row = bo_my_list($p, 'acct', $emp11, '', '', 1, $now)['rows'][0];
ot($row['ns_provider'] === 'other' && $row['ns_label'] === '外部解析' && $row['nameserver'] === ['a.ns.cloudflare.com'], 'ns provider stored from public NS');
$before = $p->query('SELECT SUM(ns_checked_at) FROM bizcn_order_domains')->fetchColumn();
$n60 = bo_ns_refresh($p, 50, 30, $now + 60); ot($n60 === 0, 'fresh NS not looked up again within a day: ' . $n60 . ' ' . json_encode($p->query('SELECT domainname, ns_checked_at FROM bizcn_order_domains')->fetchAll(PDO::FETCH_NUM)));
ot(bo_ns_refresh($p, 50, 30, $now + 90000) > 0, 'NS looked up again after a day');
echo json_encode(['passed' => $checks, 'failed' => 0, 'real_provider_calls' => 0], JSON_UNESCAPED_UNICODE) . "\n";
