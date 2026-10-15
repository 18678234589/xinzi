<?php
// 域名解析管理测试用的假服务商（内存中的 DNSPod / Cloudflare）。dns_fake_install($state) 后，所有 dns_call 都走它，不联网。
function dns_fake_state() { return ["dnspod" => ["domains" => [], "records" => [], "seq" => 100], "cloudflare" => ["zones" => [], "records" => [], "seq" => 0], "cf_can_create" => true, "cf_accounts" => [["id" => "acc1"]]]; }
function dns_fake_install(array &$state)
{
    $GLOBALS["dns_mock"] = function ($provider, $method, $path, $body) use (&$state) {
        if ($provider === 'dnspod') {
            $d = &$state['dnspod'];
            switch ($path) {
                case 'DescribeDomainList':
                    $list = []; foreach ($d['domains'] as $name => $id) $list[] = ['Name' => $name, 'DomainId' => $id, 'Status' => 'ENABLE', 'RecordCount' => count($d['records'][$name] ?? []), 'DNSStatus' => 'DNSERROR'];
                    return ['DomainList' => array_slice($list, $body['Offset'], $body['Limit'])];
                case 'DescribeDomain': return ['DomainInfo' => ['DnspodNsList' => ['alpha.dnspod.net.', 'beta.dnspod.net.']]];
                case 'CreateDomain':
                    if (isset($d['domains'][$body['Domain']])) return ['Error' => ['Code' => 'InvalidParameter.DomainExists', 'Message' => '域名已存在']];
                    $d['domains'][$body['Domain']] = ++$d['seq']; $d['records'][$body['Domain']] = []; return ['RequestId' => 'x'];
                case 'DescribeRecordList':
                    $rows = array_values($d['records'][$body['Domain']] ?? []);
                    if (!$rows) return ['Error' => ['Code' => 'ResourceNotFound.NoDataOfRecord', 'Message' => '记录列表为空']];
                    return ['RecordList' => array_slice($rows, $body['Offset'], $body['Limit'])];
                case 'CreateRecord':
                    $id = ++$d['seq'];
                    $d['records'][$body['Domain']][$id] = ['RecordId' => $id, 'Type' => $body['RecordType'], 'Name' => $body['SubDomain'], 'Value' => $body['Value'], 'TTL' => $body['TTL'], 'MX' => $body['MX'] ?? 0, 'Line' => $body['RecordLine'], 'Status' => $body['Status'] ?? 'ENABLE'];
                    return ['RecordId' => $id];
                case 'ModifyRecord':
                    $r = &$d['records'][$body['Domain']][$body['RecordId']]; $r['Type'] = $body['RecordType']; $r['Name'] = $body['SubDomain']; $r['Value'] = $body['Value']; $r['TTL'] = $body['TTL']; $r['MX'] = $body['MX'] ?? 0; $r['Line'] = $body['RecordLine'];
                    if (isset($body['Status'])) $r['Status'] = $body['Status'];
                    return ['RecordId' => $body['RecordId']];
                case 'ModifyRecordStatus':
                    $d['records'][$body['Domain']][$body['RecordId']]['Status'] = $body['Status'];
                    return ['RecordId' => $body['RecordId']];
                case 'DeleteRecord': unset($d['records'][$body['Domain']][$body['RecordId']]); return ['RequestId' => 'x'];
            }
            return ['Error' => ['Code' => 'x', 'Message' => '未知接口 ' . $path]];
        }
        $c = &$state['cloudflare'];
        $path = urldecode($path);
        if (strpos($path, '/accounts') === 0) return ['success' => true, 'result' => $state['cf_accounts']];
        if ($path === '/zones' && $method === 'POST') {
            if (!$state['cf_can_create']) return ['success' => false, 'errors' => [['code' => 0, 'message' => 'Requires permission "com.cloudflare.api.account.zone.create" to create zones for the selected account']]];
            $id = 'z' . (++$c['seq']); $c['zones'][$id] = ['id' => $id, 'name' => $body['name'], 'status' => 'pending', 'name_servers' => ['paloma.ns.cloudflare.com', 'roman.ns.cloudflare.com']]; $c['records'][$id] = [];
            return ['success' => true, 'result' => $c['zones'][$id]];
        }
        if (strpos($path, '/zones?') === 0) return ['success' => true, 'result' => array_values($c['zones']), 'result_info' => ['total_pages' => 1]];
        if (preg_match('#^/zones/([^/]+)/dns_records(?:/([^/?]+))?(?:\?.*)?$#', $path, $m)) {
            $zid = $m[1]; $rid = $m[2] ?? null;
            if ($method === 'GET') return ['success' => true, 'result' => array_values($c['records'][$zid]), 'result_info' => ['total_pages' => 1]];
            if ($method === 'POST') { $id = 'r' . (++$c['seq']); $c['records'][$zid][$id] = ['id' => $id] + $body; return ['success' => true, 'result' => ['id' => $id]]; }
            if ($method === 'PATCH') { $c['records'][$zid][$rid] = ['id' => $rid] + $body; return ['success' => true, 'result' => ['id' => $rid]]; }
            if ($method === 'DELETE') { unset($c['records'][$zid][$rid]); return ['success' => true, 'result' => ['id' => $rid]]; }
        }
        return ['success' => false, 'errors' => [['code' => 1, 'message' => '未知路径 ' . $method . ' ' . $path]]];
    };
}
