<?php
/**
 * Unified domain aggregator:
 * 1. DNS hosted domains (Cloudflare / DNSPod) - self-operated/platform core domains
 * 2. Renewal & project order domains (project_renewal_items & project_order_details) - client domains
 * 3. Bizcn domain catalog (bizcn_domain_catalog) - registrar snapshot
 */
require_once __DIR__ . '/BizcnDomainValues.php';
require_once __DIR__ . '/BizcnDomainCatalog.php';
require_once __DIR__ . '/DnsProviders.php';
require_once __DIR__ . '/BizcnDnsRouting.php';

function bda_cache_file($name)
{
    $dir = dirname(__DIR__) . '/storage';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir . '/' . $name;
}

function bda_clear_cache()
{
    @unlink(bda_cache_file('dns_zones.json'));
    @unlink(bda_cache_file('renewal_domains.json'));
}

function bda_get_dns_domains($forceRefresh = false)
{
    static $memoryCache = null;
    if ($memoryCache !== null && !$forceRefresh) return $memoryCache;

    $cacheFile = bda_cache_file('dns_zones.json');
    if (!$forceRefresh && is_file($cacheFile) && (time() - filemtime($cacheFile) < 300)) {
        $content = @file_get_contents($cacheFile);
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            $memoryCache = $decoded;
            return $memoryCache;
        }
    }

    $domains = [];
    foreach (DNS_PROVIDERS as $provider => $label) {
        if (!dns_provider_configured($provider)) continue;
        try {
            $zones = dns_zones($provider);
            foreach ($zones as $z) {
                $d = strtolower($z['domain']);
                $ns = $z['ns'] ?? [];
                if (!$ns && $provider === 'dnspod') {
                    $ns = ['f1g1ns1.dnspod.net', 'f1g1ns2.dnspod.net'];
                }
                $registrar = ($d === 'laibangwo.com' ? '阿里云 / 外部' : '外部注册商') . '（' . $label . '托管）';
                $domains[$d] = [
                    'domainname' => $d,
                    'display_name' => $d,
                    'registrar' => $registrar,
                    'owner_username' => '平台官方 / 核心主域',
                    'regdate' => null,
                    'expireddate' => null,
                    'status' => '正常解析 (' . ($z['status'] ?: 'active') . ')',
                    'source' => 'dns',
                    'source_badge' => $provider,
                    'source_label' => $label . ' 解析',
                    'nameserver' => $ns,
                    'provider' => $provider,
                    'zone_id' => (string)$z['id'],
                    'can_bizcn_panel' => false,
                ];
            }
        } catch (Throwable $e) {
            error_log('bda_get_dns_domains error (' . $provider . '): ' . $e->getMessage());
        }
    }

    @file_put_contents($cacheFile, json_encode($domains, JSON_UNESCAPED_UNICODE));
    $memoryCache = $domains;
    return $domains;
}

function bda_get_renewal_domains($forceRefresh = false)
{
    static $memoryCache = null;
    if ($memoryCache !== null && !$forceRefresh) return $memoryCache;

    $cacheFile = bda_cache_file('renewal_domains.json');
    if (!$forceRefresh && is_file($cacheFile) && (time() - filemtime($cacheFile) < 300)) {
        $content = @file_get_contents($cacheFile);
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            $memoryCache = $decoded;
            return $memoryCache;
        }
    }

    $domains = [];
    try {
        $pdo = db();
        // 1. From project_renewal_items
        $stmt = $pdo->query("SELECT r.id, r.order_id, r.resource_name, r.expires_on, r.owner, r.note, o.order_no, o.customer_name, o.project_type FROM project_renewal_items r JOIN project_orders o ON o.id=r.order_id WHERE r.resource_type='domain' AND r.resource_name<>'' ORDER BY r.expires_on ASC");
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $d = strtolower(trim($r['resource_name']));
            if (!isset($domains[$d])) {
                $ownerText = $r['owner'] === 'ours' ? '公司代管' : '客户自持';
                $domains[$d] = [
                    'domainname' => $d,
                    'display_name' => $d,
                    'registrar' => '业务续费（' . $ownerText . '）',
                    'owner_username' => ($r['customer_name'] ?: '客户') . ' · 订单:' . $r['order_no'],
                    'regdate' => null,
                    'expireddate' => $r['expires_on'] ?: null,
                    'status' => '业务续费',
                    'source' => 'renewal',
                    'source_badge' => 'renewal',
                    'source_label' => '业务续费',
                    'nameserver' => [],
                    'order_id' => (int)$r['order_id'],
                    'order_no' => (string)$r['order_no'],
                    'customer_name' => (string)$r['customer_name'],
                    'project_type' => (string)$r['project_type'],
                    'renewal_owner' => (string)$r['owner'],
                    'note' => (string)($r['note'] ?? ''),
                    'can_bizcn_panel' => false,
                ];
            }
        }

        // 2. From project_order_details website_url
        $stmt = $pdo->query("SELECT d.order_id, d.details_json, o.order_no, o.customer_name, o.project_type FROM project_order_details d JOIN project_orders o ON o.id=d.order_id WHERE d.details_json LIKE '%website_url%'");
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $det = json_decode($r['details_json'], true) ?: [];
            $url = trim($det['website_url'] ?? '');
            if ($url !== '') {
                if (preg_match('/([a-z0-9][-a-z0-9]*\.(?:com\.cn|net\.cn|org\.cn|com|cn|net|org|cc|top|xyz|vip|shop|wang|site|ltd|co))/i', $url, $m)) {
                    $d = strtolower($m[1]);
                    if (!isset($domains[$d])) {
                        $domains[$d] = [
                            'domainname' => $d,
                            'display_name' => $d,
                            'registrar' => '客户网站（订单登记）',
                            'owner_username' => ($r['customer_name'] ?: '客户') . ' · 订单:' . $r['order_no'],
                            'regdate' => null,
                            'expireddate' => null,
                            'status' => '客户网站',
                            'source' => 'renewal',
                            'source_badge' => 'renewal',
                            'source_label' => '业务订单',
                            'nameserver' => [],
                            'order_id' => (int)$r['order_id'],
                            'order_no' => (string)$r['order_no'],
                            'customer_name' => (string)$r['customer_name'],
                            'project_type' => (string)$r['project_type'],
                            'renewal_owner' => 'customer',
                            'note' => '',
                            'can_bizcn_panel' => false,
                        ];
                    }
                }
            }
        }
    } catch (Throwable $e) {
        error_log('bda_get_renewal_domains error: ' . $e->getMessage());
    }

    @file_put_contents($cacheFile, json_encode($domains, JSON_UNESCAPED_UNICODE));
    $memoryCache = $domains;
    return $domains;
}

function bda_find_domain($domain)
{
    $domain = strtolower(trim((string)$domain));
    if ($domain === '') return null;

    $dnsDomains = bda_get_dns_domains();
    if (isset($dnsDomains[$domain])) return $dnsDomains[$domain];

    $snapshot = bc_find($domain);
    if ($snapshot) {
        return [
            'domainname' => $domain,
            'display_name' => $snapshot['display_name'] ?: $domain,
            'registrar' => $snapshot['registrar'] ?: '商务中国',
            'owner_username' => $snapshot['owner_username'] ?: '',
            'regdate' => $snapshot['regdate'],
            'expireddate' => $snapshot['expireddate'],
            'status' => $snapshot['status'] ?: '正常',
            'source' => 'bizcn',
            'source_badge' => 'bizcn',
            'source_label' => '商务中国',
            'nameserver' => [],
            'can_bizcn_panel' => true,
        ];
    }

    $renewalDomains = bda_get_renewal_domains();
    if (isset($renewalDomains[$domain])) return $renewalDomains[$domain];

    return null;
}

function bda_unified_list($search, $page = 1, $sourceFilter = 'all', $forceRefresh = false)
{
    $search = trim((string)$search);
    if (strlen($search) > 253 || preg_match('/[<>\x00-\x1f]/', $search)) {
        throw new InvalidArgumentException('搜索内容过长或含无效字符');
    }
    $page = max(1, min(10000, (int)$page));
    $sourceFilter = in_array($sourceFilter, ['all', 'dns', 'renewal', 'bizcn'], true) ? $sourceFilter : 'all';

    $dnsDomains = bda_get_dns_domains($forceRefresh);
    $renewalDomains = bda_get_renewal_domains($forceRefresh);

    $account = '';
    try {
        $account = bd_config()['username'];
    } catch (Throwable $e) {}

    $bizcnTotal = 0;
    $bizcnSnapshotDate = '';
    $bizcnRegistrations = 0;
    if ($account !== '') {
        try {
            $q = db()->prepare('SELECT MAX(CASE WHEN import_id>0 THEN source_as_of END) snapshot, COUNT(*) c, SUM(import_id=0) registrations FROM bizcn_domain_catalog WHERE provider_account=?');
            $q->execute([$account]);
            $meta = $q->fetch();
            $bizcnTotal = (int)($meta['c'] ?? 0);
            $bizcnSnapshotDate = (string)($meta['snapshot'] ?? '');
            $bizcnRegistrations = (int)($meta['registrations'] ?? 0);
        } catch (Throwable $e) {}
    }

    $counts = [
        'all' => count($dnsDomains) + count($renewalDomains) + $bizcnTotal,
        'dns' => count($dnsDomains),
        'renewal' => count($renewalDomains),
        'bizcn' => $bizcnTotal,
    ];

    $pageSize = 30;

    // SCENARIO 1: WITH SEARCH KEYWORD
    if ($search !== '') {
        $qLower = strtolower($search);
        $matchedDns = [];
        if ($sourceFilter === 'all' || $sourceFilter === 'dns') {
            foreach ($dnsDomains as $d => $row) {
                if (stripos($d, $qLower) !== false || stripos($row['display_name'], $search) !== false || stripos($row['registrar'], $search) !== false || stripos($row['source_label'], $search) !== false) {
                    $matchedDns[$d] = $row;
                }
            }
        }

        $matchedRenewal = [];
        if ($sourceFilter === 'all' || $sourceFilter === 'renewal') {
            foreach ($renewalDomains as $d => $row) {
                if (stripos($d, $qLower) !== false || stripos($row['customer_name'], $search) !== false || stripos($row['order_no'], $search) !== false || stripos($row['project_type'], $search) !== false) {
                    $matchedRenewal[$d] = $row;
                }
            }
        }

        $matchedBizcn = [];
        if ($account !== '' && ($sourceFilter === 'all' || $sourceFilter === 'bizcn')) {
            $pattern = '%' . str_replace(['!','%', '_'], ['!!','!%','!_'], $search) . '%';
            $sql = "SELECT domainname, display_name, registrar, owner_username, regdate, expireddate, status, source_as_of FROM bizcn_domain_catalog WHERE provider_account=? AND (CONVERT(domainname USING utf8mb4) LIKE ? ESCAPE '!' OR display_name LIKE ? ESCAPE '!' OR owner_username LIKE ? ESCAPE '!' OR registrar LIKE ? ESCAPE '!') ORDER BY CASE WHEN expireddate>=? THEN 0 WHEN expireddate IS NOT NULL THEN 1 ELSE 2 END, expireddate, domainname LIMIT 300";
            $stmt = db()->prepare($sql);
            $stmt->execute([$account, $pattern, $pattern, $pattern, $pattern, date('Y-m-d')]);
            while ($b = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $d = strtolower($b['domainname']);
                $matchedBizcn[$d] = [
                    'domainname' => $d,
                    'display_name' => $b['display_name'] ?: $d,
                    'registrar' => $b['registrar'] ?: '商务中国',
                    'owner_username' => $b['owner_username'] ?: '',
                    'regdate' => $b['regdate'],
                    'expireddate' => $b['expireddate'],
                    'status' => $b['status'] ?: '正常',
                    'source' => 'bizcn',
                    'source_badge' => 'bizcn',
                    'source_label' => '商务中国',
                    'nameserver' => [],
                    'can_bizcn_panel' => true,
                ];
            }
        }

        $merged = [];
        // Exact domain match first!
        if (isset($matchedDns[$qLower])) {
            $merged[$qLower] = $matchedDns[$qLower];
            unset($matchedDns[$qLower]);
        } elseif (isset($matchedRenewal[$qLower])) {
            $merged[$qLower] = $matchedRenewal[$qLower];
            unset($matchedRenewal[$qLower]);
        } elseif (isset($matchedBizcn[$qLower])) {
            $merged[$qLower] = $matchedBizcn[$qLower];
            unset($matchedBizcn[$qLower]);
        }

        // DNS matches
        foreach ($matchedDns as $d => $row) {
            if (!isset($merged[$d])) $merged[$d] = $row;
        }

        // Renewal matches
        foreach ($matchedRenewal as $d => $row) {
            if (!isset($merged[$d])) {
                $merged[$d] = $row;
            } else {
                $merged[$d]['renewal_order'] = $row['order_no'];
                $merged[$d]['customer_name'] = $row['customer_name'];
            }
        }

        // Bizcn matches
        foreach ($matchedBizcn as $d => $row) {
            if (!isset($merged[$d])) {
                $merged[$d] = $row;
            } else {
                $merged[$d]['bizcn_snapshot'] = true;
                $merged[$d]['can_bizcn_panel'] = true;
            }
        }

        $allRows = array_values($merged);
        $totalMatches = count($allRows);
        $offset = ($page - 1) * $pageSize;
        return [
            'rows' => array_slice($allRows, $offset, $pageSize),
            'total' => $totalMatches,
            'page' => $page,
            'page_size' => $pageSize,
            'source' => 'unified',
            'counts' => $counts,
            'updated_at' => $bizcnSnapshotDate ?: date('Y-m-d H:i:s'),
        ];
    }

    // SCENARIO 2: FILTER 'dns'
    if ($sourceFilter === 'dns') {
        $rows = array_values($dnsDomains);
        $total = count($rows);
        $offset = ($page - 1) * $pageSize;
        return [
            'rows' => array_slice($rows, $offset, $pageSize),
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'source' => 'unified',
            'counts' => $counts,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }

    // SCENARIO 3: FILTER 'renewal'
    if ($sourceFilter === 'renewal') {
        $rows = array_values($renewalDomains);
        usort($rows, function ($a, $b) {
            $ea = $a['expireddate'] ?: '9999-12-31';
            $eb = $b['expireddate'] ?: '9999-12-31';
            return strcmp($ea, $eb);
        });
        $total = count($rows);
        $offset = ($page - 1) * $pageSize;
        return [
            'rows' => array_slice($rows, $offset, $pageSize),
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'source' => 'unified',
            'counts' => $counts,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }

    // SCENARIO 4: FILTER 'bizcn'
    if ($sourceFilter === 'bizcn') {
        $bizcnList = bc_list('', $page);
        if ($bizcnList) {
            foreach ($bizcnList['rows'] as &$row) {
                $row['source'] = 'bizcn';
                $row['source_badge'] = 'bizcn';
                $row['source_label'] = '商务中国';
                $row['can_bizcn_panel'] = true;
            }
            unset($row);
            $bizcnList['counts'] = $counts;
            return $bizcnList;
        }
    }

    // SCENARIO 5: FILTER 'all' (BROWSING)
    if ($page === 1) {
        $head = array_values($dnsDomains);
        $need = $pageSize - count($head);
        $bizcnList = bc_list('', 1);
        $tail = [];
        if ($bizcnList && !empty($bizcnList['rows'])) {
            foreach ($bizcnList['rows'] as $r) {
                $d = strtolower($r['domainname']);
                if (isset($dnsDomains[$d])) continue;
                $r['source'] = 'bizcn';
                $r['source_badge'] = 'bizcn';
                $r['source_label'] = '商务中国';
                $r['can_bizcn_panel'] = true;
                $tail[] = $r;
                if (count($tail) >= $need) break;
            }
        }
        return [
            'rows' => array_merge($head, $tail),
            'total' => $counts['all'],
            'page' => 1,
            'page_size' => $pageSize,
            'source' => 'unified',
            'counts' => $counts,
            'updated_at' => $bizcnSnapshotDate ?: date('Y-m-d H:i:s'),
        ];
    } else {
        $bizcnList = bc_list('', $page);
        $rows = [];
        if ($bizcnList && !empty($bizcnList['rows'])) {
            foreach ($bizcnList['rows'] as $r) {
                $d = strtolower($r['domainname']);
                if (isset($dnsDomains[$d])) continue;
                $r['source'] = 'bizcn';
                $r['source_badge'] = 'bizcn';
                $r['source_label'] = '商务中国';
                $r['can_bizcn_panel'] = true;
                $rows[] = $r;
            }
        }
        return [
            'rows' => $rows,
            'total' => $counts['all'],
            'page' => $page,
            'page_size' => $pageSize,
            'source' => 'unified',
            'counts' => $counts,
            'updated_at' => $bizcnSnapshotDate ?: date('Y-m-d H:i:s'),
        ];
    }
}
