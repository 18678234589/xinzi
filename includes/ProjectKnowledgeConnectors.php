<?php
/** 外部知识连接器：仅固定厂商 HTTPS 接口；无外部写文档能力。 */
require_once __DIR__ . '/ProjectKnowledge.php';
require_once __DIR__ . '/ProjectVault.php';

function pk_provider($value)
{
    if (!in_array($value,['feishu','dingtalk'],true)) throw new RuntimeException('请选择钉钉或飞书。');
    return $value;
}

function pk_external_id($value)
{
    $value = trim((string)$value);
    if (!preg_match('/^[A-Za-z0-9_.=-]{1,160}$/D',$value)) throw new RuntimeException('文档或空间 ID 格式不正确。');
    return $value;
}

function pk_document_reference($provider, $reference, $kind = 'docx')
{
    pk_provider($provider);
    $reference = trim((string)$reference);
    if (strpos($reference,'://') !== false) {
        $p = parse_url(pk_url($reference)); $host = $p['host']; $path = $p['path'] ?? '';
        if ($provider === 'feishu') {
            if (!preg_match('/(^|\.)feishu\.cn$/D',$host) || !preg_match('~^/(wiki|docx|docs)/([A-Za-z0-9_-]+)/*$~D',$path,$m)) throw new RuntimeException('请粘贴飞书 wiki / docx / docs 文档链接。');
            return ['id'=>pk_external_id($m[2]),'kind'=>$m[1]];
        }
        if ($host !== 'alidocs.dingtalk.com' || !preg_match('~^/i/nodes/([A-Za-z0-9_.=-]+)/*$~D',$path,$m)) throw new RuntimeException('请使用钉钉 /i/nodes/ 文档链接，或直接输入节点 ID。');
        return ['id'=>pk_external_id($m[1]),'kind'=>'node'];
    }
    if ($provider === 'feishu' && !in_array($kind,['wiki','docx','docs'],true)) throw new RuntimeException('文档类型无效。');
    return ['id'=>pk_external_id($reference),'kind'=>$provider === 'dingtalk' ? 'node' : $kind];
}

function pk_http($provider, $path, $method = 'GET', array $body = [], $token = '')
{
    pk_provider($provider);
    // 路径由本文件中的只读方法构造，不接受用户自定义 API 地址。
    if (!preg_match('~^/(open-apis/|v[12]\.0/)~',$path) || strpos($path, '://') !== false || strpos($path, "\n") !== false) throw new RuntimeException('接口路径无效。');
    if (!function_exists('curl_init')) throw new RuntimeException('服务器需要启用 cURL 扩展。');
    $url = ($provider === 'feishu' ? 'https://open.feishu.cn' : 'https://api.dingtalk.com') . $path;
    $headers = ['Accept: application/json','Content-Type: application/json'];
    if ($token !== '') $headers[] = $provider === 'feishu' ? 'Authorization: Bearer ' . $token : 'x-acs-dingtalk-access-token: ' . $token;
    $response = ''; $overflow = false;
    $ch = curl_init($url);
    curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>$headers,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>function($ch,$chunk) use (&$response,&$overflow) {
        if (strlen($response) + strlen($chunk) > 2097152) { $overflow = true; return 0; }
        $response .= $chunk; return strlen($chunk);
    }]);
    if ($method === 'POST') curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body,JSON_UNESCAPED_UNICODE));
    $ok = curl_exec($ch); $status = (int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    if ($overflow) throw new RuntimeException('文档返回内容超过 2 MB，请拆分为较小文档后同步。');
    if ($ok === false) throw new RuntimeException('连接超时或 TLS 校验失败，请稍后重试。');
    $data = json_decode($response,true);
    $code = is_array($data) ? ($data['code'] ?? $data['errcode'] ?? 0) : 'invalid_json';
    if ($status < 200 || $status >= 300 || !is_array($data) || ($code !== 0 && $code !== '0') || (($data['success'] ?? true) === false)) {
        // 不显示/记录厂商原始返回、请求头或密钥。
        $code = preg_replace('/[^A-Za-z0-9_.-]/','',(string)$code);
        throw new RuntimeException('外部接口未通过（HTTP ' . $status . ' / ' . substr($code,0,80) . '）。请核对应用权限、文档授权和配置。');
    }
    return $data;
}

function pk_integration($provider)
{
    pk_provider($provider);
    $q = db()->prepare('SELECT * FROM project_kb_integrations WHERE provider=?'); $q->execute([$provider]);
    $row = $q->fetch();
    if (!$row) throw new RuntimeException('请先保存应用配置。');
    return $row;
}

function pk_save_integration(array $input, array $ctx)
{
    if (empty($ctx['super'])) throw new RuntimeException('只有超级管理员可以配置 API。');
    $provider = pk_provider($input['provider'] ?? '');
    $appId = pk_external_id($input['app_id'] ?? '');
    $operator = pk_limit($input['operator_id'] ?? '',160,'操作人 UnionId');
    if ($provider === 'dingtalk') pk_external_id($operator);
    $secret = pk_limit($input['app_secret'] ?? '',512,'应用密钥');
    $q = db()->prepare('SELECT app_id,secret_cipher FROM project_kb_integrations WHERE provider=?'); $q->execute([$provider]); $previous = $q->fetch(); $old = $previous ? (string)$previous['secret_cipher'] : '';
    if ($previous && $previous['app_id'] !== $appId && $secret === '') throw new RuntimeException('更换应用 ID 时，请同时填写新应用的密钥。');
    if ($secret === '' && $old === '') throw new RuntimeException('首次配置需要填写应用密钥。');
    $cipher = $secret === '' ? $old : pv_encrypt($secret);
    $db = db(); $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO project_kb_integrations (provider,app_id,secret_cipher,operator_id,updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE app_id=VALUES(app_id),secret_cipher=VALUES(secret_cipher),operator_id=VALUES(operator_id),updated_by=VALUES(updated_by)')->execute([$provider,$appId,$cipher,$operator,$ctx['actor']['id']]);
        ps_audit('knowledge_integration',0,'configure',$ctx['actor'],['provider'=>$provider,'secret_changed'=>$secret !== '']);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}

function pk_access_token(array $config)
{
    if ($config['provider'] === 'feishu') {
        $j = pk_http('feishu','/open-apis/auth/v3/tenant_access_token/internal','POST',['app_id'=>$config['app_id'],'app_secret'=>pv_decrypt($config['secret_cipher'])]);
        $token = $j['tenant_access_token'] ?? '';
    } else {
        $j = pk_http('dingtalk','/v1.0/oauth2/accessToken','POST',['appKey'=>$config['app_id'],'appSecret'=>pv_decrypt($config['secret_cipher'])]);
        $token = $j['accessToken'] ?? '';
    }
    if (!is_string($token) || $token === '' || preg_match('/[\r\n]/',$token)) throw new RuntimeException('没有取得有效的访问令牌。');
    return $token; // 仅本次请求内存使用，不写数据库或浏览器。
}

function pk_external_list($provider, $space = '', $parent = '', $cursor = '')
{
    $cfg = pk_integration($provider); $token = pk_access_token($cfg);
    $space = trim($space); $parent = trim($parent);
    if (strlen($cursor) > 1000) throw new RuntimeException('分页游标无效。');
    if ($provider === 'feishu') {
        $query = ['page_size'=>50]; if ($cursor !== '') $query['page_token'] = $cursor;
        if ($space !== '') {
            $path = '/open-apis/wiki/v2/spaces/' . rawurlencode(pk_external_id($space)) . '/nodes';
            if ($parent !== '') $query['parent_node_token'] = pk_external_id($parent);
        } else $path = '/open-apis/wiki/v2/spaces';
        $j = pk_http($provider,$path . '?' . http_build_query($query),'GET',[],$token);
        return ['items'=>$j['data']['items'] ?? [],'next'=>!empty($j['data']['has_more']) ? ($j['data']['page_token'] ?? '') : '', 'space_list'=>$space === ''];
    }
    $query = ['operatorId'=>$cfg['operator_id'],'maxResults'=>50]; if ($cursor !== '') $query['nextToken'] = $cursor;
    if ($space !== '' && $parent === '') {
        $j = pk_http($provider,'/v2.0/wiki/workspaces/' . rawurlencode(pk_external_id($space)) . '?' . http_build_query(['operatorId'=>$cfg['operator_id']]),'GET',[],$token);
        $parent = $j['workspace']['rootNodeId'] ?? '';
        if ($parent === '') throw new RuntimeException('该知识库没有返回根节点，请填写根节点 ID。');
    }
    if ($parent !== '') { $query['parentNodeId'] = pk_external_id($parent); $path = '/v2.0/wiki/nodes'; }
    else $path = '/v2.0/wiki/workspaces';
    $j = pk_http($provider,$path . '?' . http_build_query($query),'GET',[],$token);
    return ['items'=>$j[$parent !== '' ? 'nodes' : 'workspaces'] ?? [],'next'=>$j['nextToken'] ?? '', 'space_list'=>$parent === ''];
}

/** 文档块只取已知文本字段，绝不执行外部 HTML/脚本。 */
function pk_block_text($value, $depth = 0)
{
    if ($depth > 24 || !is_array($value)) return '';
    $out = [];
    foreach ($value as $key=>$child) {
        if (is_string($child) && in_array($key,['text','content','textContent','plainText','insert'],true)) $out[] = $child;
        elseif (is_array($child)) { $text = pk_block_text($child,$depth+1); if ($text !== '') $out[] = $text; }
    }
    return implode("\n",$out);
}

function pk_fetch_document($provider, $reference, $kind)
{
    $ref = pk_document_reference($provider,$reference,$kind); $cfg = pk_integration($provider); $token = pk_access_token($cfg);
    $id = $ref['id']; $key = $ref['kind'] . ':' . $id;
    if ($provider === 'feishu') {
        $type = $ref['kind']; $title = '';
        if ($type === 'wiki') {
            $j = pk_http($provider,'/open-apis/wiki/v2/spaces/get_node?token=' . rawurlencode($id),'GET',[],$token);
            $node = $j['data']['node'] ?? []; $title = $node['title'] ?? ''; $id = pk_external_id($node['obj_token'] ?? ''); $type = $node['obj_type'] ?? '';
        }
        if (!in_array($type,['docx','doc','docs'],true)) throw new RuntimeException('暂支持飞书文字文档，表格、文件夹、附件不会被当作正文导入。');
        // wiki 链接与直接文档链接归一到同一对象，避免重复创建文章。
        $key = ($type === 'docx' ? 'docx' : 'docs') . ':' . $id;
        if ($type === 'docx') {
            $base = '/open-apis/docx/v1/documents/' . rawurlencode($id);
            $info = pk_http($provider,$base,'GET',[],$token); $title = $title ?: ($info['data']['document']['title'] ?? '');
        } else $base = '/open-apis/doc/v2/' . rawurlencode($id);
        $j = pk_http($provider,$base . '/raw_content','GET',[],$token); $body = $j['data']['content'] ?? '';
        if ($title === '') $title = '飞书文档 · ' . $id;
        $url = 'https://docs.feishu.cn/' . ($ref['kind'] === 'wiki' ? 'wiki/' . $ref['id'] : ($type === 'docx' ? 'docx/' : 'docs/') . $id);
    } else {
        $query = http_build_query(['operatorId'=>$cfg['operator_id']]);
        $j = pk_http($provider,'/v2.0/wiki/nodes/' . rawurlencode($id) . '?' . $query,'GET',[],$token);
        $node = $j['node'] ?? []; $title = $node['name'] ?? '';
        if (($node['type'] ?? '') === 'FOLDER') throw new RuntimeException('这是文件夹，请选择文件夹里的文字文档。');
        $j = pk_http($provider,'/v1.0/doc/suites/documents/' . rawurlencode($id) . '/blocks?' . $query,'GET',[],$token);
        if (!empty($j['nextToken']) || !empty($j['hasMore']) || !empty($j['result']['hasMore'])) throw new RuntimeException('这份文档需要分页读取，暂不导入不完整正文，请拆分文档后重试。');
        $body = pk_block_text($j['result']['data'] ?? []);
        $url = 'https://alidocs.dingtalk.com/i/nodes/' . rawurlencode($id);
    }
    if (!is_string($body) || trim($body) === '') throw new RuntimeException('未读取到文字正文。请核对文档类型、应用读权限及操作人授权，原知识不会被覆盖。');
    return ['key'=>$key,'document'=>['title'=>$title,'body'=>$body,'url'=>$url]];
}
