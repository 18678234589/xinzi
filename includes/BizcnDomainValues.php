<?php
/** Pure domain/SOAP value validation, shared by the adapter and offline tests. */
function bd_policy($role, $department, $businesses, $maintenance = false)
{
    if ($maintenance) return true;
    if (preg_match('/网站|小程序|售后|备案|服务器维护/u', (string)$department)) return true;
    foreach ((array)$businesses as $business) {
        if (preg_match('/网站|小程序|备案|环境配置/u', is_array($business) ? (string)($business['business_type'] ?? $business['name'] ?? '') : (string)$business)) return true;
    }
    return false;
}
function bd_domain($value)
{
    $value = strtolower(rtrim(trim((string)$value), '.'));
    if (function_exists('idn_to_ascii') && preg_match('/[^\x00-\x7f]/', $value)) $value = idn_to_ascii($value, 0, INTL_IDNA_VARIANT_UTS46);
    if (!$value || strlen($value) > 253 || filter_var($value, FILTER_VALIDATE_IP) || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/D', $value)) throw new InvalidArgumentException('请输入完整域名，不包含 https:// 或路径');
    return $value;
}

function bd_xml($values)
{
    $xml = '';
    foreach ($values as $name => $value) {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/D', $name)) throw new InvalidArgumentException('无效接口参数');
        foreach (is_array($value) && array_keys($value) === range(0, count($value)-1) ? $value : [$value] as $item) {
            $xml .= '<' . $name . '>' . (is_array($item) ? bd_xml($item) : htmlspecialchars((string)$item, ENT_XML1 | ENT_QUOTES, 'UTF-8')) . '</' . $name . '>';
        }
    }
    return $xml;
}

function bd_node($node)
{
    $result = [];
    foreach ($node->children() as $key => $child) {
        $value = count($child->children()) ? bd_node($child) : (string)$child;
        if (array_key_exists($key, $result)) {
            if (!is_array($result[$key]) || !isset($result[$key][0])) $result[$key] = [$result[$key]];
            $result[$key][] = $value;
        } else $result[$key] = $value;
    }
    foreach ($node->attributes() as $key => $value) $result[$key] = (string)$value;
    return $result;
}

function bd_parse($xml, $method = '', $secrets = [])
{
    if (strlen($xml) > 6000000 || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) throw new RuntimeException('接口响应格式异常');
    $prior = libxml_use_internal_errors(true);
    try {
        $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
        if ($doc === false) throw new RuntimeException('商务中国暂未返回有效数据');
        if ($doc->xpath('//*[local-name()="Fault"]')) throw new RuntimeException('商务中国接口暂不可用，请稍后重试');
        $nodes = $doc->xpath('//*[local-name()="response"]');
        if (!$nodes) throw new RuntimeException('商务中国响应缺少结果');
        $data = bd_node($nodes[0]);
        $result = isset($data['result']) ? $data['result'] : $data;
        if (!isset($result['code']) || !in_array((string)$result['code'], ['200'], true)) {
            if ((string)($result['code'] ?? '') === '531') {
                if (in_array($method, ['getDomainList','getDomainCount'], true)) throw new RuntimeException('商务中国域名列表接口授权未通过（531），不代表账户内没有域名。请管理员向商务中国确认该账户的列表接口权限与调用要求，无需反复填写密码');
                throw new RuntimeException('商务中国接口授权未通过（531），请管理员确认该操作的账户权限与接口配置');
            }
            if ((string)($result['code'] ?? '') === '532') throw new RuntimeException('商务中国尚未允许此服务器访问，请联系管理员设置接口 IP 白名单');
            $msg = (string)($result['msg'] ?? '请求未获通过');
            $msg = str_replace($secrets, '[已隐藏]', $msg);
            throw new RuntimeException('商务中国：' . mb_substr(strip_tags($msg), 0, 180));
        }
        return $data;
    } finally { libxml_clear_errors(); libxml_use_internal_errors($prior); }
}

function bd_ns($raw)
{
    $values = preg_split('/[\s,;，；]+/u', trim((string)$raw), -1, PREG_SPLIT_NO_EMPTY);
    $values = array_values(array_unique(array_map('bd_domain', $values)));
    if (count($values) < 2 || count($values) > 6) throw new InvalidArgumentException('请填写 2–6 个不同的 NS 服务器，一行一个');
    return $values;
}

function bd_rows($value)
{
    if (!$value || !is_array($value)) return [];
    return isset($value[0]) ? $value : [$value];
}

function bd_record($input, $mode)
{
    $type = strtoupper(trim((string)($input['type'] ?? '')));
    if (!in_array($type, ['A','AAAA','CNAME','MX','TXT','NS','SRV','CAA'], true)) throw new InvalidArgumentException('请选择有效记录类型');
    $host = trim((string)($input['host'] ?? '@'));
    if (!preg_match('/^(?:@|\*|[a-zA-Z0-9_*.-]{1,253})$/D', $host)) throw new InvalidArgumentException('主机记录格式不正确');
    $value = trim((string)($input['value'] ?? '')); $old = trim((string)($input['old_value'] ?? ''));
    if ($mode !== 'delete' && ($value === '' || strlen($value) > 2048 || preg_match('/[\x00-\x1f]/', $value))) throw new InvalidArgumentException('请填写有效解析值');
    if ($mode !== 'delete' && (($type==='A' && !filter_var($value,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)) || ($type==='AAAA' && !filter_var($value,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)))) throw new InvalidArgumentException('IP 地址格式不正确');
    if ($mode !== 'delete' && $type === 'CNAME') {
        try { $value = bd_domain($value); }
        catch (InvalidArgumentException $e) { throw new InvalidArgumentException('CNAME 记录值必须是完整目标域名，不要填写 IP、https:// 或路径'); }
        $owner = $host === '@' ? bd_domain($input['domain'] ?? '') : strtolower($host) . '.' . bd_domain($input['domain'] ?? '');
        if ($value === $owner) throw new InvalidArgumentException('CNAME 不能指向自身，否则会造成解析循环');
    }
    if ($mode !== 'add' && ($old === '' || strlen($old)>2048)) throw new InvalidArgumentException('原记录已变化，请刷新后重试');
    $priority = (int)($input['priority'] ?? 10);
    if ($priority<0 || $priority>65535) throw new InvalidArgumentException('优先级应为 0–65535');
    $args = ['domainname'=>bd_domain($input['domain'] ?? ''), 'resolvetype'=>$type, 'resolvehost'=>$host];
    if ($mode!=='add') $args['resolveoldvalue']=$old;
    if ($mode!=='delete') $args['resolvevalue']=$value;
    $args['mxlevel']=(string)$priority;
    return $args;
}
