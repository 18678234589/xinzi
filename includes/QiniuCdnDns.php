<?php
/**
 * 七牛 CDN 域名一键解析：把加速域名的 A / AAAA / CNAME 记录改成七牛分配的 CNAME（只在七牛配置成功后）。
 * 解析服务商由域名当前 NS 决定（商务中国 / DNSPod / Cloudflare）；先出方案（只读），确认后才修改，改前的旧记录写入审计便于回退。
 * 只处理加速域名这一个主机记录，不动其他记录；同一主机有多条冲突记录或根域时拒绝，交给人工。
 */
require_once __DIR__ . '/BizcnDnsRouting.php';
require_once __DIR__ . '/BizcnDnsRecords.php';

/** 返回方案：provider、zone、host、current（该主机上的 A/AAAA/CNAME）、action（noop / add / replace / refuse）、message。 */
function qd_plan($name, $cname, $state, array $io = [])
{
    $name = qc_domain($name);
    $cname = strtolower(rtrim(trim((string)$cname), '.'));
    if ($state !== 'success') throw new RuntimeException('七牛还没有配置成功（当前：' . qc_state_label($state) . '），请等状态变成“配置成功”再改解析');
    if ($cname === '' || $cname === $name) throw new RuntimeException('七牛暂未返回有效的 CNAME，请稍后刷新');
    list($host, $zone) = qc_split_host($name);
    $context = isset($io['context']) ? $io['context'] : bdr_context($zone);
    $rows = isset($io['rows']) ? $io['rows'] : bdr_records($zone, $context)['rows'];
    $current = [];
    foreach ($rows as $r) {
        $rHost = $context['provider'] === 'bizcn' ? (string)$r['host'] : (string)$r['name'];
        $type = strtoupper((string)$r['type']);
        if (strcasecmp($rHost, $host) !== 0 || !in_array($type, ['A', 'AAAA', 'CNAME'], true)) continue;
        if (isset($r['enabled']) && !$r['enabled']) continue;
        $current[] = ['type' => $type, 'value' => strtolower(rtrim((string)$r['value'], '.')), 'id' => (string)($r['id'] ?? ''), 'ttl' => (int)($r['ttl'] ?? 600), 'line' => (string)($r['line'] ?? '默认'), 'raw_value' => (string)$r['value'], 'mxlevel' => (string)($r['mxlevel'] ?? '10')];
    }
    $plan = ['provider' => $context['provider'], 'label' => $context['label'], 'zone' => $zone, 'host' => $host, 'cname' => $cname, 'current' => $current, 'context' => $context];
    foreach ($current as $c) if ($c['type'] === 'CNAME' && $c['value'] === $cname) return $plan + ['action' => 'noop', 'message' => '解析已经指向七牛，无需修改'];
    if ($host === '@' && $context['provider'] !== 'cloudflare') return $plan + ['action' => 'refuse', 'message' => '根域（@）不能设置 CNAME，请改用 www 等子域名，或到解析管理手动处理'];
    if (count($current) > 1) return $plan + ['action' => 'refuse', 'message' => '这个主机记录下有多条 A / AAAA / CNAME 记录，系统不自动合并，请到解析管理手动核对后再改'];
    return $plan + ($current ? ['action' => 'replace', 'message' => '将把现有 ' . $current[0]['type'] . ' 记录（' . $current[0]['value'] . '）改为 CNAME → ' . $cname] : ['action' => 'add', 'message' => '将新增一条 CNAME 记录 → ' . $cname]);
}

/** 执行方案；$io 仅供测试注入。返回提示语。 */
function qd_apply(array $plan, $actor, array $io = [])
{
    if ($plan['action'] === 'noop') return $plan['message'];
    if ($plan['action'] === 'refuse') throw new RuntimeException($plan['message']);
    $audit = isset($io['audit']) ? $io['audit'] : function ($action, $data) use ($actor) { ps_audit('cdn', 0, $action, $actor, $data); };
    $zone = $plan['zone']; $host = $plan['host']; $old = $plan['current'][0] ?? null;
    $data = ['domain' => $host === '@' ? $zone : $host . '.' . $zone, 'provider' => $plan['provider'], 'to' => $plan['cname'], 'from' => $old ? $old['type'] . ' ' . $old['raw_value'] : ''];
    $audit('dns_connect_requested', $data);
    if ($plan['provider'] === 'bizcn') {
        $newArgs = bd_record(['domain' => $zone, 'type' => 'CNAME', 'host' => $host, 'value' => $plan['cname']], 'add');
        if ($old) {
            $oldArgs = bd_record(['domain' => $zone, 'type' => $old['type'], 'host' => $host, 'old_value' => $old['raw_value'], 'priority' => $old['mxlevel']], 'delete');
            $message = bd_record_replace($zone, $oldArgs, $newArgs, $actor, isset($io['record_io']) ? $io['record_io'] : []);
        } else {
            $call = isset($io['call']) ? $io['call'] : 'bd_call';
            $call('addDnsRecordService', 'addDnsRecord', ['add' => $newArgs]);
            $message = '已新增 CNAME 解析记录';
        }
    } else {
        $rec = dns_validate_record('CNAME', $host, $plan['cname'], $plan['provider'] === 'cloudflare' ? 1 : 600, null, false, $plan['provider']);
        if ($old) dns_record_update($plan['provider'], $plan['context']['zone'], $old['id'], $rec, $old['line']);
        else dns_record_create($plan['provider'], $plan['context']['zone'], $rec);
        $message = ($old ? '已把原记录改为 CNAME' : '已新增 CNAME 解析记录');
    }
    $audit('dns_connect_success', $data);
    return $message . '。解析生效一般几分钟，稍后在“当前公网解析”里确认“已指向七牛”。';
}
