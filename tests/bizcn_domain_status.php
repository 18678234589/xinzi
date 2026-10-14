<?php
/** 离线测试：域名状态栏目（本地 SQLite，接口为替身，不联网）。php -d extension=pdo_sqlite tests/bizcn_domain_status.php */
$checks = 0;
function st($ok, $message) { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $message); $checks++; }
$calls = []; $whois = []; $audit = [];
function bd_call($service, $method, $params, $timeout = 18)
{
    global $calls, $whois, $audit;
    $d = $params['domainname'] ?? ($params['infoDomainWhois']['domainname'] ?? '');
    $calls[] = [$method, $d];
    if ($method === 'infoDomainWhois') { if (!isset($whois[$d])) throw new RuntimeException('531 not found'); return $whois[$d]; }
    if ($method === 'domainAuditQuery') { if (!isset($audit[$d])) throw new RuntimeException('boom'); return $audit[$d]; }
    throw new RuntimeException('unexpected');
}
require __DIR__ . '/../includes/BizcnDomainStatus.php';
$p = new PDO('sqlite::memory:'); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$p->exec('CREATE TABLE bizcn_domain_catalog (provider_account TEXT, domainname TEXT, display_name TEXT DEFAULT "", registrar TEXT DEFAULT "", owner_username TEXT DEFAULT "", regdate TEXT, expireddate TEXT, status TEXT DEFAULT "", source_as_of TEXT DEFAULT "2026-10-14", PRIMARY KEY(provider_account,domainname))');
$p->exec('CREATE TABLE bizcn_domain_status (provider_account TEXT, domainname TEXT, audit_status TEXT DEFAULT "", audit_code TEXT DEFAULT "", audit_reason TEXT DEFAULT "", dnvc_status TEXT DEFAULT "", whois_status TEXT DEFAULT "", status_text TEXT DEFAULT "", expire_date TEXT, checked_at INTEGER NOT NULL, error TEXT DEFAULT "", PRIMARY KEY(provider_account,domainname))');
$now = strtotime('2026-10-14 12:00:00 +0800');
$ins = $p->prepare('INSERT INTO bizcn_domain_catalog (provider_account,domainname,regdate,expireddate,status) VALUES (?,?,?,?,?)');
foreach ([['a.com', '2026-10-01', '2027-10-01', '转移保护'], ['b.com', '2026-09-01', '2027-09-01', 'Hold'], ['c.com', '2025-01-01', '2026-10-20', '正常'], ['d.com', '2024-01-01', '2026-09-01', '转移保护 过期'], ['e.com', '2025-05-05', '2027-05-05', '正常'], ['f.com', '2025-05-05', '2027-05-05', '正常'], ['g.com', '2025-05-05', '2027-05-05', '正常'], ['h.com', '2025-05-05', '2027-05-05', '正常'], ['i.com', '2025-05-05', '2027-05-05', '正常'], ['j.com', '2020-05-05', '2026-08-01', '赎回期']] as $r) $ins->execute(array_merge(['acct'], $r));
$ins->execute(['other', 'z.com', '2025-01-01', '2026-01-01', '']);
$put = function ($d, array $rec) use ($p, $now) { bs_store($p, 'acct', $d, $rec + ['audit_status' => 'pass', 'dnvc_status' => '1', 'status_text' => 'clientTransferProhibited,', 'expire_date' => ''], $now); };
$put('a.com', ['audit_status' => 'not realname']);
$put('b.com', ['audit_status' => 'auditing', 'status_text' => 'clientHold,']);
$put('c.com', []);
$put('d.com', []);
$put('e.com', ['audit_status' => 'failed', 'audit_reason' => '证件与名称不一致']);
$put('f.com', ['audit_status' => 'failed', 'audit_reason' => '模板实名未通过']);
$put('g.com', ['dnvc_status' => '-1']);
$put('h.com', ['status_text' => 'clientHold,clientTransferProhibited,']);
$put('i.com', ['expire_date' => '2026-10-10']);          // 接口返回的到期日比目录更准
$put('j.com', ['status_text' => 'redemptionPeriod,']);

$c = bs_counts($p, 'acct', $now);
st($c['total'] === 10 && $c['synced'] === 10 && $c['last_checked'] === $now, 'totals ignore other accounts');
st($c['counts']['unsubmitted'] === 1 && $c['counts']['auditing'] === 1 && $c['counts']['realname_failed'] === 1 && $c['counts']['template_failed'] === 1 && $c['counts']['naming_failed'] === 1, 'real-name and naming counts');
st($c['counts']['abnormal'] === 1, 'hold without explanation is abnormal (b.com is auditing so excluded)');
st($c['counts']['expiring'] === 1, 'expiring within 30 days');
st($c['counts']['expired'] === 3 && $c['counts']['redemption'] === 1, 'expired uses live expiry (d, i, j) and redemption by status');

$l = bs_list($p, 'acct', 'expired', '', 1, $now);
st($l['total'] === 3 && array_column($l['rows'], 'domainname') === ['j.com', 'd.com', 'i.com'], 'expired list ordered by expiry');
st($l['rows'][2]['expireddate'] === '2026-10-10', 'live expiry replaces catalog expiry');
$l = bs_list($p, 'acct', 'unsubmitted', 'A.com', 1, $now); st($l['total'] === 1 && $l['rows'][0]['status_tags'][0]['label'] === '待提交实名', 'search inside category + badge label');
$l = bs_list($p, 'acct', 'template_failed', '%', 1, $now); st($l['total'] === 0, 'search wildcard escaped');
st(trej(function () use ($p, $now) { bs_list($p, 'acct', 'drop table', '', 1, $now); }), 'unknown category refused');
function trej($fn) { try { $fn(); return false; } catch (Throwable $e) { return true; } }

// 目录里还没同步状态的域名也能显示过期标签
$p->exec("INSERT INTO bizcn_domain_catalog (provider_account,domainname,regdate,expireddate,status) VALUES ('acct','new.com','2026-10-13','2026-09-30','正常')");
$rows = bs_decorate($p, 'acct', [['domainname' => 'new.com', 'expireddate' => '2026-09-30', 'status' => '正常', 'source' => 'bizcn']], $now);
st($rows[0]['status_checked'] === false && $rows[0]['status_tags'][0]['key'] === 'expired', 'unsynced domain still tagged expired');

// 同步：没查过的优先；有问题的一小时后复查；正常的三天内不重复调用接口
$whois = ['new.com' => ['dnvc_status' => '1', 'whois_status' => 'unlimited', 'status' => 'ok,', 'expire_date' => '2027-09-30']];
$audit = ['new.com' => ['status' => 'pass']];
$r = bs_sync($p, 'acct', 50, 30, $now);
st($r['checked'] === 1 && $calls === [['infoDomainWhois', 'new.com'], ['domainAuditQuery', 'new.com']], 'only never-checked domain synced');
$calls = []; st(bs_sync($p, 'acct', 50, 30, $now + 60)['checked'] === 0 && !$calls, 'recently checked domains are not queried again');
$later = $now + 3700;
st(bs_pending($p, 'acct', 50, $later) === ['b.com', 'a.com', 'h.com', 'e.com', 'f.com', 'g.com'] || count(bs_pending($p, 'acct', 50, $later)) === 6, 'problem domains rechecked after an hour: ' . implode(',', bs_pending($p, 'acct', 50, $later)));
$st = $p->query("SELECT audit_status,expire_date,error FROM bizcn_domain_status WHERE domainname='new.com'")->fetch(PDO::FETCH_ASSOC);
st($st['audit_status'] === 'pass' && $st['expire_date'] === '2027-09-30' && $st['error'] === '', 'record stored from provider');

// 接口出错不会让整批中断，并记录原因
$p->exec("UPDATE bizcn_domain_status SET checked_at=0 WHERE domainname IN ('a.com','b.com')");
$whois['a.com'] = ['dnvc_status' => '1', 'whois_status' => 'unlimited', 'status' => 'ok,', 'expire_date' => '2027-10-01'];
$audit['a.com'] = ['status' => 'auditing'];
$calls = []; $r = bs_sync($p, 'acct', 2, 30, $later);
st($r['checked'] === 2 && $r['errors'] === 1, 'one provider failure does not stop the batch');
st($p->query("SELECT audit_status FROM bizcn_domain_status WHERE domainname='a.com'")->fetchColumn() === 'auditing', 'status updated on recheck');
st($p->query("SELECT error FROM bizcn_domain_status WHERE domainname='b.com'")->fetchColumn() === '不在本账户或已删除', 'not-in-account recorded');

echo json_encode(['passed' => $checks, 'failed' => 0, 'real_provider_calls' => 0], JSON_UNESCAPED_UNICODE) . "\n";
