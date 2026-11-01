<?php
/** 离线测试：七牛 HTTPS 证书任务队列（SQLite，不联网）。php -d extension=pdo_sqlite tests/qiniu_https_queue.php */
require_once __DIR__ . '/../includes/QiniuCdn.php';
function ps_audit() {}
require_once __DIR__ . '/../includes/QiniuCdnHttps.php';
$c = 0; function qq($ok, $m) { global $c; if (!$ok) throw new RuntimeException('FAIL: ' . $m); $c++; }
$p = new PDO('sqlite::memory:'); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$p->exec('CREATE TABLE qiniu_https_jobs (domainname TEXT PRIMARY KEY, state TEXT, message TEXT, attempts INTEGER DEFAULT 0, requested_by TEXT, requested_at INTEGER, next_try_at INTEGER DEFAULT 0, updated_at INTEGER)');
$now = time();
qq(qh_enqueue($p, 'WWW.A.com', 'employee:1') === 'pending' && qh_job($p, 'www.a.com')['state'] === 'pending', 'enqueue normalises the domain');
qh_enqueue($p, 'www.a.com'); qq((int)$p->query('SELECT COUNT(*) FROM qiniu_https_jobs')->fetchColumn() === 1, 'enqueue twice keeps one job');
// 未就绪：继续等，2 分钟后再看
$r = qh_work($p, 5, $now, null, function () { return 'wait'; });
$j = $p->query("SELECT state,next_try_at FROM qiniu_https_jobs")->fetch(PDO::FETCH_ASSOC);
qq($r['waiting'] === 1 && $j['state'] === 'pending' && (int)$j['next_try_at'] === $now + 120, 'not ready -> waits 2 minutes');
qq(qh_work($p, 5, $now + 60, null, function () { throw new RuntimeException('should not be asked yet'); }) === ['done' => 0, 'waiting' => 0, 'failed' => 0], 'job not due is skipped');
// 就绪：签发成功
$called = [];
$r = qh_work($p, 5, $now + 200, function ($n) use (&$called) { $called[] = $n; return '证书已签发'; }, function () { return 'go'; });
qq($r['done'] === 1 && $called === ['www.a.com'] && qh_job($p, 'www.a.com')['state'] === 'done', 'ready -> issued and marked done');
// 已有证书：直接标记完成，不重复签发
qh_enqueue($p, 'www.a.com', 'u', true); $called = [];
qh_work($p, 5, $now + 300, function ($n) use (&$called) { $called[] = $n; }, function () { return 'enabled'; });
qq(!$called && qh_job($p, 'www.a.com')['state'] === 'done', 'already has https -> no issuance');
// 失败：退避重试，达到上限后停止
qh_enqueue($p, 'www.b.com', 'u'); $fail = function () { throw new RuntimeException('LE down'); }; $t = $now + 400;
for ($i = 1; $i <= QH_MAX_ATTEMPTS; $i++) { qh_work($p, 5, $t, $fail, function () { return 'go'; }); $t += 7200; }
$j = qh_job($p, 'www.b.com');
qq($j['state'] === 'failed' && (int)$j['attempts'] === QH_MAX_ATTEMPTS && strpos($j['message'], 'LE down') !== false && strpos($j['message'], '重新申请') !== false, 'gives up after max attempts with a readable message');
qh_enqueue($p, 'www.b.com', 'u', true); qq(qh_job($p, 'www.b.com')['state'] === 'pending' && (int)qh_job($p, 'www.b.com')['attempts'] === 0, 'manual button re-queues a failed job');
// 一直等不到就绪：3 天后放弃
qh_enqueue($p, 'www.c.com', 'u'); $p->exec("UPDATE qiniu_https_jobs SET requested_at=" . ($now - QH_WAIT_LIMIT - 10) . " WHERE domainname='www.c.com'");
qh_work($p, 5, $now, null, function () { return 'wait'; }); qq(qh_job($p, 'www.c.com')['state'] === 'failed', 'gives up waiting after 3 days');
// 签发中的任务不会被重复入队
$p->exec("UPDATE qiniu_https_jobs SET state='running' WHERE domainname='www.a.com'"); qq(qh_enqueue($p, 'www.a.com', 'u', true) === 'running', 'running job is not re-queued');
// 续签阈值：剩余不足总有效期 1/3（90 天证书 = 30 天；45 天证书 = 15 天）
$t0 = time();
qq(!qh_needs_renewal(['not_before' => $t0, 'not_after' => $t0 + 90 * 86400], $t0 + 50 * 86400) && qh_needs_renewal(['not_before' => $t0, 'not_after' => $t0 + 90 * 86400], $t0 + 61 * 86400), '90-day cert renews in the last third');
qq(!qh_needs_renewal(['not_before' => $t0, 'not_after' => $t0 + 45 * 86400], $t0 + 20 * 86400) && qh_needs_renewal(['not_before' => $t0, 'not_after' => $t0 + 45 * 86400], $t0 + 31 * 86400), 'shorter certs renew proportionally');
qq(qh_needs_renewal(['not_after' => $t0 + 29 * 86400], $t0) && !qh_needs_renewal(['not_after' => $t0 + 31 * 86400], $t0), 'missing start time falls back to 30 days');
// 旧证书清理：只删本域名、auto- 开头、非当前证书
$deleted = [];
$GLOBALS['qc_mock'] = function ($service, $method, $path) use (&$deleted) { $deleted[] = [$method, $path]; return [200, ['code' => 200]]; };
$certs = [
    ['certid' => 'new', 'name' => 'auto-www-a-com-20270101', 'common_name' => 'www.a.com'],
    ['certid' => 'old1', 'name' => 'auto-www-a-com-20261001', 'common_name' => 'www.a.com'],
    ['certid' => 'manual', 'name' => 'a-wildcard', 'common_name' => 'www.a.com'],
    ['certid' => 'other', 'name' => 'auto-www-b-com-20261001', 'common_name' => 'www.b.com'],
];
qq(qh_cleanup_old('www.a.com', 'new', $certs) === 1 && $deleted === [['DELETE', '/sslcert/old1']], 'cleanup deletes only superseded auto cert of this domain');
$GLOBALS['qc_mock'] = function () { throw new QiniuCdnError('证书已绑定CDN域名', 400, 400611); };
qq(qh_cleanup_old('www.a.com', 'new', $certs) === 0, 'cleanup ignores certs that are still bound');
// 续签失败记录
qh_note_failure($p, 'www.d.com', '自动续签失败（证书剩余 9 天）：x');
qq(qh_job($p, 'www.d.com')['state'] === 'failed' && strpos(qh_job($p, 'www.d.com')['message'], '剩余 9 天') !== false, 'renewal failure recorded for the page');
qh_enqueue($p, 'www.e.com', 'u'); $p->exec("UPDATE qiniu_https_jobs SET state='running' WHERE domainname='www.e.com'"); qh_note_failure($p, 'www.e.com', 'x');
qq(qh_job($p, 'www.e.com')['state'] === 'running', 'failure note never overwrites a running job');
echo json_encode(['passed' => $c, 'failed' => 0, 'real_provider_calls' => 0]) . "\n";
