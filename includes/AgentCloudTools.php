<?php
/** Shared website adapters; provider credentials never leave ProjectApiSettings. */
require_once __DIR__ . '/QiniuCdn.php';

const AC_ACTIONS = ['dns_zones','dns_records','dns_preview','cdn_domains','cdn_detail','cdn_certificates','cdn_preview','commit'];

function ac_signature($raw, $stamp, $secret)
{
    return hash_hmac('sha256', 'request.' . $stamp . '.' . $raw, $secret);
}

function ac_authenticate($raw, $stamp, $signature, $secret, $now)
{
    if (strlen($secret) < 32 || strlen($raw) > 65536 || !ctype_digit((string)$stamp) || abs($now - (int)$stamp) > 30 || !hash_equals(ac_signature($raw, $stamp, $secret), (string)$signature)) throw new RuntimeException('智能体请求鉴权失败');
    $request = json_decode($raw, true);
    if (!is_array($request) || !preg_match('/^(admin|project):[1-9][0-9]{0,17}$/D', (string)($request['subject'] ?? ''))) throw new RuntimeException('智能体身份无效');
    return $request;
}

function ac_ticket($actorKey, $kind, array $plan, $secret, $now)
{
    $json = json_encode(['actor'=>$actorKey, 'kind'=>$kind, 'plan'=>$plan, 'expires'=>$now+600, 'nonce'=>bin2hex(random_bytes(16))], JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $encoded = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    return $encoded . '.' . hash_hmac('sha256', 'preview.' . $encoded, $secret);
}

function ac_ticket_read($ticket, $actorKey, $domain, $ack, $secret, $now)
{
    $parts = explode('.', (string)$ticket);
    if (count($parts) !== 2 || !hash_equals(hash_hmac('sha256', 'preview.' . $parts[0], $secret), $parts[1])) throw new RuntimeException('预览凭据无效，请重新预览');
    $data = json_decode(base64_decode(strtr($parts[0], '-_', '+/'), true), true);
    if (!is_array($data) || ($data['actor'] ?? '') !== $actorKey || ($data['expires'] ?? 0) <= $now || !preg_match('/^[a-f0-9]{32}$/D', (string)($data['nonce'] ?? ''))) throw new RuntimeException('预览已过期或不属于当前账号');
    if ($ack !== true || $domain !== ($data['plan']['domain'] ?? '')) throw new RuntimeException('请明确确认预览中的完整域名');
    return $data;
}

function ac_record_version(array $rows)
{
    usort($rows, function ($a, $b) { return strcmp((string)$a['id'], (string)$b['id']); });
    return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
}

function ac_dns_plan(array $p)
{
    $domain = dns_validate_domain($p['domain'] ?? '');
    $operation = (string)($p['operation'] ?? 'save');
    if (!in_array($operation, ['save','delete','status','create_zone'], true)) throw new RuntimeException('不支持的 DNS 操作');
    $zone = dns_zone_find('dnspod', $domain);
    if ($operation === 'create_zone') {
        if ($zone) throw new RuntimeException('此域名已在 DNSPod 中，请查询现有解析');
        return ['domain'=>$domain, 'operation'=>$operation];
    }
    if (!$zone) throw new RuntimeException('此域名未接入网站配置的 DNSPod 账号');
    $rows = dns_records('dnspod', $zone);
    $id = (string)($p['record_id'] ?? ''); $before = null;
    foreach ($rows as $row) if ($row['id'] === $id) $before = $row;
    if (($id !== '' || $operation !== 'save') && (!$before || !$before['editable'])) throw new RuntimeException('记录不存在或不允许修改，请重新查询');
    $plan = ['domain'=>$domain, 'operation'=>$operation, 'zone_id'=>$zone['id'], 'record_id'=>$id, 'before'=>$before, 'version'=>ac_record_version($rows)];
    if ($operation === 'status') {
        if (!isset($p['enabled']) || !is_bool($p['enabled'])) throw new RuntimeException('enabled 必须为 true 或 false');
        $plan['enabled'] = $p['enabled'];
    } elseif ($operation === 'save') {
        $rec = dns_validate_record($p['type'] ?? '', $p['name'] ?? '@', $p['value'] ?? '', $p['ttl'] ?? 600, $p['priority'] ?? null, false, 'dnspod');
        if (isset($p['enabled']) && !is_bool($p['enabled'])) throw new RuntimeException('enabled 必须为 true 或 false');
        $rec['enabled'] = $p['enabled'] ?? ($before['enabled'] ?? true);
        $plan['record'] = $rec;
    }
    return $plan;
}

function ac_dns_commit(array $p)
{
    if ($p['operation'] === 'create_zone') {
        $zone = dns_create_zone('dnspod', $p['domain']);
        bda_clear_cache();
        return ['ok'=>true, 'synchronized'=>true, 'zone'=>$zone, 'message'=>'已接入 DNSPod 并刷新网站域名缓存；NS 委派请按用户要求另行处理'];
    }
    $zone = dns_zone_find('dnspod', $p['domain']);
    if (!$zone || (string)$zone['id'] !== (string)$p['zone_id']) throw new RuntimeException('域名归属已变化，请重新预览');
    $rows = dns_records('dnspod', $zone);
    if (!hash_equals($p['version'], ac_record_version($rows))) throw new RuntimeException('解析已被修改，请重新查询和预览，避免覆盖其他人的改动');
    $id = $p['record_id'];
    if ($p['operation'] === 'delete') dns_record_delete('dnspod', $zone, $id);
    elseif ($p['operation'] === 'status') dns_call('dnspod', 'POST', 'ModifyRecordStatus', ['Domain'=>$p['domain'], 'RecordId'=>(int)$id, 'Status'=>$p['enabled'] ? 'ENABLE' : 'DISABLE']);
    elseif ($id !== '') dns_record_update('dnspod', $zone, $id, $p['record'], $p['before']['line']);
    else {
        foreach ($rows as $row) if (dns_record_key($row) === dns_record_key($p['record'])) throw new RuntimeException('已有同类型、同名、同值记录，请修改该记录而非重复添加');
        $id = dns_record_create('dnspod', $zone, $p['record']);
    }
    // Invalidate first: a later provider read failure must not leave a stale website cache.
    bda_clear_cache();
    $after = null;
    foreach (dns_records('dnspod', $zone) as $row) if ($row['id'] === $id) $after = $row;
    $synced = $p['operation'] === 'delete' ? $after === null : $after !== null;
    if ($synced && $p['operation'] === 'status') $synced = $after['enabled'] === $p['enabled'];
    if ($synced && $p['operation'] === 'save') {
        $expectedTtl = $p['record']['ttl'] === 1 ? 600 : $p['record']['ttl'];
        $synced = dns_record_key($after) === dns_record_key($p['record']) && $after['ttl'] === $expectedTtl && $after['enabled'] === $p['record']['enabled'];
    }
    return ['ok'=>true, 'synchronized'=>$synced, 'domain'=>$p['domain'], 'before'=>$p['before'], 'record'=>$after, 'message'=>$synced ? 'DNSPod 已回读确认，网站解析管理使用同一数据源，域名缓存已刷新' : '服务商已接受操作，但回读尚未一致；请先查询，不要重试写入'];
}

/** A consumed ticket is never executed twice, including after a crash or network timeout. */
function ac_once($directory, $key, callable $run, $resource)
{
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('无法保存操作凭据，未执行变更');
    $file = $directory . '/' . hash('sha256', $key) . '.json';
    $f = fopen($file, 'c+');
    if (!$f) throw new RuntimeException('无法保存操作凭据，未执行变更');
    chmod($file, 0600);
    $resourceLock = null;
    try {
        if (!flock($f, LOCK_EX|LOCK_NB)) throw new RuntimeException('此操作正在执行，请查询状态，不要重复提交');
        $old = stream_get_contents($f);
        if ($old !== '') {
            $receipt = json_decode($old, true);
            if (!is_array($receipt) || !isset($receipt['result'])) throw new RuntimeException('上次操作结果不确定，请查询服务商状态，不要再次提交');
            return $receipt['result'] + ['replayed'=>true];
        }
        // Serialize different tickets for the same domain before checking the live snapshot.
        $lockFile = $directory . '/resource-' . hash('sha256', $resource) . '.lock';
        $resourceLock = fopen($lockFile, 'c');
        if (!$resourceLock) throw new RuntimeException('无法锁定域名，未执行变更');
        chmod($lockFile, 0600);
        if (!flock($resourceLock, LOCK_EX|LOCK_NB)) throw new RuntimeException('同一域名已有操作正在执行，请查询后再用原票据提交');
        $started = json_encode(['started_at'=>time()]);
        if (fwrite($f, $started) !== strlen($started) || !fflush($f) || (function_exists('fsync') && !fsync($f))) throw new RuntimeException('无法持久保存操作凭据，未执行变更');
        try { $result = $run(); }
        catch (Throwable $e) { $result = ['ok'=>false, 'message'=>$e instanceof RuntimeException ? $e->getMessage() : '操作结果不确定，请查询状态，不要重试写入']; }
        $result['completed_at'] = time();
        $json = json_encode(['result'=>$result], JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        rewind($f);
        if (!ftruncate($f, 0) || fwrite($f, $json) !== strlen($json) || !fflush($f) || (function_exists('fsync') && !fsync($f))) throw new RuntimeException('操作回执保存失败，请查询服务商状态，禁止重复写入');
        return $result;
    } finally {
        if ($resourceLock) { flock($resourceLock, LOCK_UN); fclose($resourceLock); }
        flock($f, LOCK_UN); fclose($f);
    }
}

function ac_dispatch($action, array $p, array $actor, $secret, $directory, $now)
{
    if (!in_array($action, AC_ACTIONS, true)) throw new RuntimeException('不支持的云资源操作');
    $actorKey = $actor['type'] . ':' . $actor['id'];
    if ($action === 'dns_zones') return ['ok'=>true, 'zones'=>dns_zones('dnspod')];
    if ($action === 'dns_records') {
        $zone = dns_zone_find('dnspod', dns_validate_domain($p['domain'] ?? ''));
        if (!$zone) throw new RuntimeException('此域名不在网站配置的 DNSPod 账号中');
        $rows = dns_records('dnspod', $zone);
        if (isset($p['name'])) $rows = array_values(array_filter($rows, function ($r) use ($p) { return $r['name'] === $p['name']; }));
        $offset = max(0, min(6000, (int)($p['offset'] ?? 0))); $limit = max(1, min(100, (int)($p['limit'] ?? 100)));
        return ['ok'=>true, 'zone'=>$zone, 'records'=>array_slice($rows, $offset, $limit), 'total'=>count($rows), 'has_more'=>$offset+$limit<count($rows)];
    }
    if ($action === 'cdn_domains') return ['ok'=>true, 'domains'=>qc_list((string)($p['marker'] ?? ''))];
    if ($action === 'cdn_detail') return ['ok'=>true, 'domain'=>qc_detail($p['domain'] ?? '')];
    if ($action === 'cdn_certificates') return ['ok'=>true, 'certificates'=>qc_certificates()];
    if ($action === 'dns_preview' || $action === 'cdn_preview') {
        $plan = $action === 'dns_preview' ? ac_dns_plan($p) : ['domain'=>qc_domain($p['domain'] ?? ''), 'payload'=>qc_payload($p)];
        $kind = $action === 'dns_preview' ? 'dns' : 'cdn';
        return ['ok'=>true, 'preview'=>$plan, 'ticket'=>ac_ticket($actorKey, $kind, $plan, $secret, $now), 'expires_in'=>600, 'commit_requires'=>['confirm_domain'=>$plan['domain'], 'ack'=>true]];
    }
    $ticket = ac_ticket_read($p['ticket'] ?? '', $actorKey, $p['confirm_domain'] ?? '', $p['ack'] ?? false, $secret, $now);
    return ac_once($directory, $actorKey . ':' . $ticket['nonce'], function () use ($ticket, $actor) {
        $plan = $ticket['plan'];
        ps_audit('agent_cloud', 0, 'change_requested', $actor, ['kind'=>$ticket['kind'], 'plan'=>$plan, 'operation'=>$ticket['nonce']]);
        if ($ticket['kind'] === 'dns') $result = ac_dns_commit($plan);
        elseif ($ticket['kind'] === 'cdn') {
            qc_create($plan['payload']);
            $result = ['ok'=>true, 'synchronized'=>false, 'domain'=>$plan['domain'], 'message'=>'七牛已接受请求，尚待回读确认；请查询域名状态，勿重复创建'];
            try { $result['domain'] = qc_detail($plan['domain']); $result['synchronized'] = true; $result['message'] = '七牛域名已回读，网站 CDN 管理使用同一实时列表；请核对 operatingState 和 CNAME'; }
            catch (Throwable $e) { /* Accepted creation remains accepted if readback is temporarily unavailable. */ }
        } else throw new RuntimeException('预览类型无效');
        $result['website_url'] = 'https://me.laibangwo.com/project/' . ($ticket['kind'] === 'dns' ? 'dns.php?p=dnspod&d=' : 'cdn.php?d=') . rawurlencode($plan['domain']);
        try { ps_audit('agent_cloud', 0, 'change_result', $actor, ['operation'=>$ticket['nonce'], 'result'=>$result]); }
        catch (Throwable $e) { error_log('Agent cloud change completed; result audit unavailable'); }
        return $result;
    }, $ticket['kind'] . ':' . $ticket['plan']['domain']);
}
