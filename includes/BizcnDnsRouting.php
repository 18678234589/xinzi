<?php
/** Resolve the registrar's current NS before choosing a DNS account and record API. */
require_once __DIR__.'/DnsProviders.php';

function bdr_nameservers(array $info)
{
    $ns=[];
    for($i=1;$i<=6;$i++) if(!empty($info['dns_host'.$i])) $ns[]=strtolower(dns_trim_dot($info['dns_host'.$i]));
    return $ns;
}

function bdr_provider(array $ns)
{
    $ns=array_map(function($host){return strtolower(dns_trim_dot($host));},$ns);
    if(!$ns) return 'none';
    $business=true;
    foreach($ns as $host) if(!preg_match('/(?:\.cnmsn\.net$|^bizcn[12]\.dnspod\.net$)/',$host)) $business=false;
    return $business ? 'bizcn' : dns_ns_resolver($ns);
}

function bdr_context($domain, array $info=null)
{
    if ($info === null) {
        $snapshot = function_exists('bc_find') ? bc_find($domain) : null;
        if ($snapshot) {
            try {
                $info = bd_require_owned($domain);
            } catch (Throwable $e) {
                $publicNs = dns_public_ns($domain);
                $info = [];
                foreach ($publicNs as $i => $h) $info['dns_host' . ($i + 1)] = $h;
            }
        } else {
            $publicNs = dns_public_ns($domain);
            $info = [];
            foreach ($publicNs as $i => $h) $info['dns_host' . ($i + 1)] = $h;
        }
    }
    $ns=bdr_nameservers($info);
    $provider=bdr_provider($ns);
    if($provider==='bizcn') return ['provider'=>$provider,'label'=>'商务中国','zone'=>null,'ns'=>$ns];
    if(!isset(DNS_PROVIDERS[$provider])) throw new RuntimeException('当前 NS 暂无法匹配本站支持的解析服务商，请核对 NS 后刷新重试');
    if(!dns_provider_configured($provider)) throw new RuntimeException('请先在接口设置中配置 '.DNS_PROVIDERS[$provider].' 的 API 凭据');
    if($provider==='dnspod') {
        $r=dns_call('dnspod','POST','DescribeDomain',['Domain'=>$domain]);
        $d=$r['DomainInfo'] ?? [];
        if(empty($d['DomainId'])) throw new RuntimeException('当前 DNSPod API 账号无法管理此域名');
        $assigned=array_map(function($host){return strtolower(dns_trim_dot($host));},(array)($d['DnspodNsList'] ?? []));
        $zone=['domain'=>$domain,'id'=>(string)$d['DomainId'],'ns'=>$assigned];
    } else {
        $r=dns_call('cloudflare','GET','/zones?name='.rawurlencode($domain).'&per_page=50');$zone=null;
        foreach((array)($r['result'] ?? []) as $z) if(strtolower($z['name'])===$domain) {
            $zone=['domain'=>$domain,'id'=>(string)$z['id'],'ns'=>(array)($z['name_servers'] ?? [])];break;
        }
        if(!$zone) throw new RuntimeException('当前 Cloudflare API 账号无法管理此域名');
        $assigned=array_map(function($host){return strtolower(dns_trim_dot($host));},$zone['ns']);
    }
    $actual=$ns;sort($actual);sort($assigned);
    if(!$assigned || $actual!==$assigned) throw new RuntimeException('注册商 NS 与本站 '.DNS_PROVIDERS[$provider].' 账号分配的 NS 不一致，请核对域名所属账号');
    return ['provider'=>$provider,'label'=>DNS_PROVIDERS[$provider],'zone'=>$zone,'ns'=>$ns];
}

function bdr_revision(array $row)
{
    $fields=[];
    foreach(['id','type','name','value','ttl','priority','proxied','line','enabled'] as $key) $fields[$key]=$row[$key] ?? null;
    return hash('sha256',json_encode($fields));
}

function bdr_records($domain, array $context)
{
    if($context['provider']==='bizcn') {
        $r=bd_call('infoDnsRecordService','infoDnsRecord',['domainname'=>$domain]);
        $rows=bd_rows($r['records']['record'] ?? []);
    } else {
        $rows=dns_records($context['provider'],$context['zone']);
        foreach($rows as &$row) {
            $row['host']=$row['name'];$row['mxlevel']=$row['priority'];
            // Preserve disabled records until the drawer has a status editor.
            $row['editable']=$row['editable'] && $row['enabled'];
            $row['revision']=bdr_revision($row);
        }
        unset($row);
    }
    return ['provider'=>$context['provider'],'provider_label'=>$context['label'],'rows'=>$rows];
}

function bdr_save($action,$domain,array $post,$actor,array $context)
{
    if(!in_array($action,['record_add','record_edit','record_delete'],true)) throw new RuntimeException('不支持此解析操作');
    $provider=$context['provider'];
    if(!isset(DNS_PROVIDERS[$provider])) throw new RuntimeException('请刷新解析记录后重试');
    if(($post['provider'] ?? '')!==$provider) throw new RuntimeException('域名的解析服务商已变化，请刷新解析记录后重试');
    if(($post['confirm'] ?? '')!==$domain) throw new RuntimeException('请确认正在修改的域名');
    $rows=dns_records($provider,$context['zone']);$existing=null;
    if($action!=='record_add') {
        foreach($rows as $row) if($row['id']===(string)($post['record_id'] ?? '')) $existing=$row;
        if(!$existing || !$existing['editable'] || !$existing['enabled']) throw new RuntimeException('这条记录不存在或不支持修改，请刷新后重试');
        if(!hash_equals(bdr_revision($existing),(string)($post['record_revision'] ?? ''))) throw new RuntimeException('原解析记录已变化，请刷新后再修改，避免覆盖他人操作');
    }
    $rec=$action==='record_delete' ? $existing : dns_validate_record($post['type'] ?? '',$post['host'] ?? '',$post['value'] ?? '',$post['ttl'] ?? 600,$post['priority'] ?? null,($post['proxied'] ?? '')==='1',$provider);
    if($action==='record_add') foreach($rows as $row) {
        if($row['type']===$rec['type'] && $row['name']===$rec['name'] && $row['value']===$rec['value']) throw new RuntimeException('这条解析记录已存在，无需重复添加');
    }
    $audit=['provider'=>$provider,'domain'=>$domain,'type'=>$rec['type'],'name'=>$rec['name']];
    ps_audit('dns',0,$action.'_requested',$actor,$audit);
    if($action==='record_delete') dns_record_delete($provider,$context['zone'],$existing['id']);
    elseif($action==='record_edit') dns_record_update($provider,$context['zone'],$existing['id'],$rec,$existing['line']);
    else dns_record_create($provider,$context['zone'],$rec);
    ps_audit('dns',0,$action.'_success',$actor,$audit);
    return ['provider'=>$provider,'message'=>$context['label'].' 解析记录已'.($action==='record_delete'?'删除':'保存成功')];
}
