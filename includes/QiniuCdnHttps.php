<?php
/**
 * 七牛 CDN 域名自动 HTTPS：Let's Encrypt 单域名证书（DNS-01，验证记录写入域名当前的解析服务商）→ 上传七牛 → 绑定到加速域名。
 * 前置：七牛状态「配置成功」且公网已 CNAME 到七牛（否则开了 HTTPS 也没流量，验证也无意义）。证书 90 天有效，到期前由 jobs 续签。
 */
require_once __DIR__ . '/AcmeClient.php';
require_once __DIR__ . '/BizcnDnsRouting.php';
require_once __DIR__ . '/QiniuCdnDns.php';

function qh_account_key_file() { return dirname(__DIR__) . '/storage/private/acme/account.pem'; }

/** 验证 TXT 的增删与公网可见性检查，按域名当前 NS 的解析服务商实现。 */
function qh_txt_io($zone, $log = null)
{
    $context = bdr_context($zone);
    $rel = function ($fqdn) use ($zone) { return substr($fqdn, 0, -(strlen($zone) + 1)); };
    $ids = [];
    return [
        'add_txt' => function ($name, $value) use ($context, $zone, $rel, &$ids) {
            $host = $rel($name);
            if ($context['provider'] === 'bizcn') {
                bd_call('addDnsRecordService', 'addDnsRecord', ['add' => ['domainname' => $zone, 'resolvetype' => 'TXT', 'resolvehost' => $host, 'resolvevalue' => $value, 'mxlevel' => '10']]);
                return;
            }
            $rec = dns_validate_record('TXT', $host, $value, $context['provider'] === 'cloudflare' ? 1 : 600, null, false, $context['provider']);
            dns_record_create($context['provider'], $context['zone'], $rec);
        },
        'del_txt' => function ($name, $value) use ($context, $zone, $rel) {
            $host = $rel($name);
            if ($context['provider'] === 'bizcn') {
                bd_call('delDnsRecordService', 'delDnsRecord', ['del' => ['domainname' => $zone, 'resolvetype' => 'TXT', 'resolvehost' => $host, 'resolveoldvalue' => $value, 'mxlevel' => '10']]);
                return;
            }
            foreach (dns_records($context['provider'], $context['zone']) as $r) if ($r['type'] === 'TXT' && $r['name'] === $host && $r['value'] === $value) dns_record_delete($context['provider'], $context['zone'], $r['id']);
        },
        'txt_visible' => function ($name, $value) {
            foreach ((array)@dns_get_record($name, DNS_TXT) as $r) if (isset($r['txt']) && trim($r['txt'], '"') === $value) return true;
            return false;
        },
    ];
}

function qh_points_to_qiniu($name, $cname)
{
    foreach ((array)@dns_get_record($name, DNS_CNAME) as $r) if (!empty($r['target']) && strtolower(rtrim($r['target'], '.')) === strtolower(rtrim($cname, '.'))) return true;
    return false;
}
function qh_cert_name($name) { return 'auto-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) . '-' . date('Ymd'); }
function qh_https_enabled(array $raw) { return !empty($raw['https']['certId']); }

/** 签发并启用 HTTPS。$log 回调接收进度文字。返回结果文字。 */
function qh_enable($name, $log = null, array $override = [])
{
    $log = $log ?: function () {};
    $name = qc_domain($name);
    $detail = qc_call('domain', 'GET', '/domain/' . rawurlencode($name));
    if (($detail['operatingState'] ?? '') !== 'success') throw new RuntimeException('七牛还没有配置成功，暂不能申请证书');
    if (qh_https_enabled($detail)) return '已启用 HTTPS（证书 ' . $detail['https']['certId'] . '）';
    // 以解析服务商里的记录为准（公网递归解析器有缓存，刚改完会滞后）；验证 TXT 走权威服务器，不受影响。
    $plan = qd_plan($name, (string)$detail['cname'], (string)$detail['operatingState']);
    if ($plan['action'] !== 'noop') throw new RuntimeException('域名还没有解析到七牛（需要 CNAME → ' . $detail['cname'] . '），请先完成解析再申请证书');
    list(, $zone) = qc_split_host($name);
    $log('签发证书（Let\'s Encrypt，DNS 验证）');
    $txt = $override['txt_io'] ?? qh_txt_io($zone);
    list($chain, $keyPem) = acme_issue($name, qh_account_key_file(), $txt + ['log' => $log]);
    $log('上传证书到七牛');
    $up = qc_call('fusion', 'POST', '/sslcert', ['name' => qh_cert_name($name), 'common_name' => $name, 'pri' => $keyPem, 'ca' => $chain]);
    $certId = (string)($up['certID'] ?? $up['certid'] ?? '');
    if ($certId === '') throw new RuntimeException('七牛没有返回证书编号');
    $log('绑定证书 ' . $certId);
    $body = ['certId' => $certId, 'forceHttps' => false, 'http2Enable' => true];
    try { qc_call('domain', 'PUT', '/domain/' . rawurlencode($name) . '/sslize', $body); }
    catch (QiniuCdnError $e) { qc_call('domain', 'PUT', '/domain/' . rawurlencode($name) . '/httpsconf', $body); }
    try { qh_cleanup_old($name, $certId); } catch (Throwable $e) { /* 清理失败不影响启用 */ }
    return '证书已签发并绑定（证书编号 ' . $certId . '），七牛配置生效一般需要几分钟';
}

/** 续签：重新签发并把新证书绑定到域名（不检查“已启用”）。 */
function qh_renew($name, $log = null)
{
    $log = $log ?: function () {};
    $name = qc_domain($name);
    list(, $zone) = qc_split_host($name);
    list($chain, $keyPem) = acme_issue($name, qh_account_key_file(), qh_txt_io($zone) + ['log' => $log]);
    $up = qc_call('fusion', 'POST', '/sslcert', ['name' => qh_cert_name($name), 'common_name' => $name, 'pri' => $keyPem, 'ca' => $chain]);
    $certId = (string)($up['certID'] ?? $up['certid'] ?? '');
    if ($certId === '') throw new RuntimeException('七牛没有返回证书编号');
    $body = ['certId' => $certId, 'forceHttps' => false, 'http2Enable' => true];
    try { qc_call('domain', 'PUT', '/domain/' . rawurlencode($name) . '/sslize', $body); }
    catch (QiniuCdnError $e) { qc_call('domain', 'PUT', '/domain/' . rawurlencode($name) . '/httpsconf', $body); }
    try { qh_cleanup_old($name, $certId); } catch (Throwable $e) { /* 清理失败不影响续签 */ }
    return '续签完成，新证书 ' . $certId;
}

/* ---------- 任务队列：添加域名后自动入队，页面按钮托底；由 jobs/qiniu_https_worker.php 每几分钟处理 ---------- */

const QH_MAX_ATTEMPTS = 6;
const QH_WAIT_LIMIT = 3 * 86400; // 等待“七牛配置成功 + 解析到七牛”最长 3 天

function qh_enqueue($pdo, $name, $by = '', $force = false)
{
    $name = qc_domain($name);
    $now = time();
    $q = $pdo->prepare('SELECT state FROM qiniu_https_jobs WHERE domainname=?');
    $q->execute([$name]);
    $state = $q->fetchColumn();
    if ($state === 'running') return 'running';
    if ($state === false) $pdo->prepare("INSERT INTO qiniu_https_jobs (domainname,state,message,attempts,requested_by,requested_at,next_try_at,updated_at) VALUES (?,'pending','已排队，等待七牛配置成功和解析生效',0,?,?,0,?)")->execute([$name, $by, $now, $now]);
    elseif ($force || $state === 'failed' || $state === 'done') $pdo->prepare("UPDATE qiniu_https_jobs SET state='pending',message='已重新排队',attempts=0,requested_by=?,requested_at=?,next_try_at=0,updated_at=? WHERE domainname=?")->execute([$by, $now, $now, $name]);
    return 'pending';
}
function qh_job($pdo, $name)
{
    $q = $pdo->prepare('SELECT state,message,attempts,requested_at,updated_at FROM qiniu_https_jobs WHERE domainname=?');
    $q->execute([qc_domain($name)]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}
function qh_job_label($state) { return ['pending' => '排队中', 'running' => '签发中', 'done' => '已启用', 'failed' => '失败'][$state] ?? ''; }
function qh_set($pdo, $name, $state, $message, $attempts = null, $next = 0)
{
    $pdo->prepare('UPDATE qiniu_https_jobs SET state=?,message=?,attempts=COALESCE(?,attempts),next_try_at=?,updated_at=? WHERE domainname=?')->execute([$state, mb_substr($message, 0, 300), $attempts, $next, time(), $name]);
}
/** 处理一批待办；$enable / $ready 可注入测试。返回统计。 */
function qh_work($pdo, $limit = 5, $now = null, $enable = null, $ready = null)
{
    $now = $now ?? time();
    $enable = $enable ?: 'qh_enable';
    $ready = $ready ?: function ($name) {
        $d = qc_call('domain', 'GET', '/domain/' . rawurlencode($name));
        if (qh_https_enabled($d)) return 'enabled';
        if (($d['operatingState'] ?? '') !== 'success') return 'wait';
        return qd_plan($name, (string)$d['cname'], 'success')['action'] === 'noop' ? 'go' : 'wait';
    };
    $stats = ['done' => 0, 'waiting' => 0, 'failed' => 0];
    $q = $pdo->prepare("SELECT domainname,attempts,requested_at FROM qiniu_https_jobs WHERE state='pending' AND next_try_at<=? ORDER BY requested_at LIMIT " . (int)$limit);
    $q->execute([$now]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $job) {
        $name = $job['domainname'];
        try {
            $r = $ready($name);
            if ($r === 'enabled') { qh_set($pdo, $name, 'done', '已启用 HTTPS', null); $stats['done']++; continue; }
            if ($r === 'wait') {
                if ($now - (int)$job['requested_at'] > QH_WAIT_LIMIT) { qh_set($pdo, $name, 'failed', '等待超过 3 天：七牛仍未配置成功或解析仍未指向七牛', null); $stats['failed']++; }
                else { qh_set($pdo, $name, 'pending', '等待七牛配置成功并解析到七牛（自动重试中）', null, $now + 120); $stats['waiting']++; }
                continue;
            }
            qh_set($pdo, $name, 'running', '正在签发证书…', null);
            $msg = call_user_func($enable, $name);
            qh_set($pdo, $name, 'done', $msg, null);
            $stats['done']++;
        } catch (Throwable $e) {
            $n = (int)$job['attempts'] + 1;
            $final = $n >= QH_MAX_ATTEMPTS;
            qh_set($pdo, $name, $final ? 'failed' : 'pending', '第 ' . $n . ' 次失败：' . mb_substr($e->getMessage(), 0, 200) . ($final ? '（已停止自动重试，可在页面重新申请）' : '（稍后自动重试）'), $n, $final ? 0 : $now + min(3600, 300 * $n));
            $stats['failed']++;
        }
    }
    return $stats;
}

/* ---------- 证书清理 / 到期判断 / 失败记录 ---------- */

/** 续签阈值：剩余有效期不足总有效期的 1/3 就续（Let's Encrypt 缩短有效期后依然适用）；缺少起止时间时按 30 天。 */
function qh_needs_renewal(array $cert, $now = null)
{
    $now = $now ?? time();
    $after = (int)($cert['not_after'] ?? 0); $before = (int)($cert['not_before'] ?? 0);
    $life = $after - $before;
    $margin = ($before > 0 && $life > 0) ? (int)($life / 3) : 30 * 86400;
    return $after <= $now + $margin;
}
/** 续签 / 首次绑定成功后，删除这个域名之前由系统自动签发的旧证书（七牛账号证书数量有上限）；仍被占用的删不掉，忽略。返回删除数。 */
function qh_cleanup_old($domain, $keepCertId, array $certs = null)
{
    $domain = strtolower($domain);
    $certs = $certs ?? qc_certificates();
    $deleted = 0;
    foreach ($certs as $c) {
        if (strpos((string)($c['name'] ?? ''), 'auto-') !== 0 || strtolower((string)($c['common_name'] ?? '')) !== $domain || (string)$c['certid'] === (string)$keepCertId) continue;
        try { qc_call('fusion', 'DELETE', '/sslcert/' . rawurlencode($c['certid'])); $deleted++; }
        catch (Throwable $e) { /* 仍绑定在别处或已删除，保留 */ }
    }
    return $deleted;
}
/** 记录续签失败，页面「HTTPS 证书」卡片会显示（不改变已启用的证书）。 */
function qh_note_failure($pdo, $name, $message)
{
    $now = time();
    $q = $pdo->prepare('SELECT 1 FROM qiniu_https_jobs WHERE domainname=?');
    $q->execute([$name]);
    if ($q->fetchColumn()) $pdo->prepare("UPDATE qiniu_https_jobs SET state='failed',message=?,updated_at=? WHERE domainname=? AND state<>'running'")->execute([mb_substr($message, 0, 300), $now, $name]);
    else $pdo->prepare("INSERT INTO qiniu_https_jobs (domainname,state,message,attempts,requested_by,requested_at,next_try_at,updated_at) VALUES (?,'failed',?,0,'renew',?,0,?)")->execute([$name, mb_substr($message, 0, 300), $now, $now]);
}
