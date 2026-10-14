<?php
/**
 * 域名状态栏目：命名审核失败 / 待提交实名 / 提交实名审核中 / 实名审核失败 / 模板实名审核失败 / 实名异常 / 即将过期 / 已过期 / 域名赎回。
 * 商务中国没有“按状态列出域名”的接口，所以由后台任务逐个域名查询（domainAuditQuery + infoDomainWhois，各约 0.13 秒）后存入
 * bizcn_domain_status；栏目计数与筛选都读这张表，并与导出目录（bizcn_domain_catalog）关联。数据库部分不联网，便于离线测试。
 */
function bs_categories()
{
    return [
        'naming_failed' => '命名审核失败',
        'unsubmitted' => '待提交实名',
        'auditing' => '提交实名审核中',
        'realname_failed' => '实名审核失败',
        'template_failed' => '模板实名审核失败',
        'abnormal' => '实名异常',
        'expiring' => '即将过期',
        'expired' => '已过期',
        'redemption' => '域名赎回',
    ];
}
function bs_expiry_expr() { return 'COALESCE(s.expire_date,c.expireddate)'; }
/** 某栏目的 SQL 条件（别名 c=目录，s=状态）及参数。即将过期=30天内到期且未过期。 */
function bs_condition($key, $today, $soon, $expiry = null)
{
    $expiry = $expiry ?? bs_expiry_expr();
    $hold = "s.status_text LIKE '%clientHold%'";
    switch ($key) {
        case 'naming_failed': return ["s.dnvc_status='-1'", []];
        case 'unsubmitted': return ["s.audit_status='not realname'", []];
        case 'auditing': return ["s.audit_status='auditing'", []];
        case 'realname_failed': return ["(s.audit_status='failed' AND s.audit_reason NOT LIKE '%模板%')", []];
        case 'template_failed': return ["(s.audit_status='failed' AND s.audit_reason LIKE '%模板%')", []];
        // 被 Hold 却不是上面几种实名 / 命名原因：需要人工到商务中国核对。
        case 'abnormal': return ["($hold AND COALESCE(s.audit_status,'') NOT IN ('not realname','auditing','failed') AND COALESCE(s.dnvc_status,'')<>'-1')", []];
        case 'expiring': return ['(' . $expiry . ' >= ? AND ' . $expiry . ' <= ?)', [$today, $soon]];
        case 'expired': return ['(' . $expiry . ' < ?)', [$today]];
        case 'redemption': return ["(s.status_text LIKE '%redemption%' OR s.status_text LIKE '%pendingDelete%' OR c.status LIKE '%赎回%')", []];
    }
    throw new InvalidArgumentException('不支持此状态栏目');
}
function bs_dates($now = null)
{
    $now = $now ?? time();
    $zone = new DateTimeZone('Asia/Shanghai');
    return [(new DateTimeImmutable('@' . $now))->setTimezone($zone)->format('Y-m-d'), (new DateTimeImmutable('@' . ($now + 30 * 86400)))->setTimezone($zone)->format('Y-m-d')];
}
function bs_from() { return ' FROM bizcn_domain_catalog c LEFT JOIN bizcn_domain_status s ON s.provider_account=c.provider_account AND s.domainname=c.domainname '; }

/** 各栏目计数、总数与同步进度；一次查询。 */
function bs_counts($pdo, $account, $now = null)
{
    list($today, $soon) = bs_dates($now);
    $select = ['COUNT(*) total', 'COUNT(s.domainname) synced', 'MAX(s.checked_at) last_checked'];
    $params = [];
    foreach (array_keys(bs_categories()) as $key) {
        list($sql, $p) = bs_condition($key, $today, $soon);
        $select[] = "SUM(CASE WHEN $sql THEN 1 ELSE 0 END) k_$key";
        $params = array_merge($params, $p);
    }
    $params[] = $account;
    $q = $pdo->prepare('SELECT ' . implode(',', $select) . bs_from() . 'WHERE c.provider_account=?');
    $q->execute($params);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    $counts = [];
    foreach (array_keys(bs_categories()) as $key) $counts[$key] = (int)$r['k_' . $key];
    return ['counts' => $counts, 'total' => (int)$r['total'], 'synced' => (int)$r['synced'], 'last_checked' => $r['last_checked'] === null ? null : (int)$r['last_checked']];
}

/** 一行域名的栏目标签（与 SQL 条件同一套口径），用于列表里的状态徽标。 */
function bs_row_tags(array $row, $today, $soon)
{
    $tags = [];
    $audit = (string)($row['audit_status'] ?? '');
    $reason = (string)($row['audit_reason'] ?? '');
    $text = (string)($row['status_text'] ?? '');
    $expire = (string)($row['expireddate'] ?? '');
    if (($row['dnvc_status'] ?? '') === '-1') $tags[] = 'naming_failed';
    if ($audit === 'not realname') $tags[] = 'unsubmitted';
    if ($audit === 'auditing') $tags[] = 'auditing';
    if ($audit === 'failed') $tags[] = mb_strpos($reason, '模板') !== false ? 'template_failed' : 'realname_failed';
    if (stripos($text, 'clientHold') !== false && !in_array($audit, ['not realname', 'auditing', 'failed'], true) && ($row['dnvc_status'] ?? '') !== '-1') $tags[] = 'abnormal';
    if ($expire !== '' && $expire < $today) $tags[] = 'expired';
    elseif ($expire !== '' && $expire <= $soon) $tags[] = 'expiring';
    if (stripos($text, 'redemption') !== false || stripos($text, 'pendingDelete') !== false || mb_strpos((string)($row['status'] ?? ''), '赎回') !== false) $tags[] = 'redemption';
    return $tags;
}
function bs_decorate($pdo, $account, array $rows, $now = null)
{
    list($today, $soon) = bs_dates($now);
    $names = [];
    foreach ($rows as $r) if (!empty($r['domainname']) && ($r['source'] ?? 'bizcn') === 'bizcn') $names[strtolower($r['domainname'])] = true;
    $status = [];
    if ($names) {
        $in = implode(',', array_fill(0, count($names), '?'));
        $q = $pdo->prepare("SELECT domainname,audit_status,audit_reason,dnvc_status,status_text,expire_date,checked_at FROM bizcn_domain_status WHERE provider_account=? AND domainname IN ($in)");
        $q->execute(array_merge([$account], array_keys($names)));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $s) $status[strtolower($s['domainname'])] = $s;
    }
    $labels = bs_categories();
    foreach ($rows as &$r) {
        $s = $status[strtolower($r['domainname'] ?? '')] ?? null;
        if (!$s) {
            // 还没同步到实名状态的域名，仍可按目录里的到期日和状态给出过期 / 赎回标签。
            $r['status_tags'] = array_map(function ($k) use ($labels) { return ['key' => $k, 'label' => $labels[$k]]; }, bs_row_tags($r, $today, $soon));
            $r['status_checked'] = false;
            continue;
        }
        if (!empty($s['expire_date'])) $r['expireddate'] = $s['expire_date'];
        $keys = bs_row_tags($r + $s, $today, $soon);
        $r['status_tags'] = array_map(function ($k) use ($labels) { return ['key' => $k, 'label' => $labels[$k]]; }, $keys);
        $r['status_checked'] = true;
        $r['audit_status'] = (string)$s['audit_status'];
        $r['audit_reason'] = (string)$s['audit_reason'];
    }
    unset($r);
    return $rows;
}

/** 栏目列表：只含商务中国目录里的域名；分页30，按到期日排序。 */
function bs_list($pdo, $account, $category, $search, $page, $now = null)
{
    list($today, $soon) = bs_dates($now);
    list($cond, $condParams) = bs_condition($category, $today, $soon);
    $where = 'c.provider_account=? AND ' . $cond;
    $values = array_merge([$account], $condParams);
    $search = trim((string)$search);
    if ($search !== '') {
        $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
        $where .= " AND (CONVERT(c.domainname USING utf8mb4) LIKE ? ESCAPE '!' OR c.display_name LIKE ? ESCAPE '!' OR c.owner_username LIKE ? ESCAPE '!')";
        array_push($values, $pattern, $pattern, $pattern);
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') $where = str_replace('CONVERT(c.domainname USING utf8mb4)', 'c.domainname', $where);
    }
    $q = $pdo->prepare('SELECT COUNT(*)' . bs_from() . 'WHERE ' . $where);
    $q->execute($values);
    $total = (int)$q->fetchColumn();
    $page = max(1, min((int)$page, max(1, (int)ceil($total / 30))));
    $q = $pdo->prepare('SELECT c.domainname,c.display_name,c.registrar,c.owner_username,c.regdate,' . bs_expiry_expr() . ' expireddate,c.status,c.source_as_of' . bs_from() . 'WHERE ' . $where . ' ORDER BY ' . bs_expiry_expr() . ',c.domainname LIMIT 30 OFFSET ' . (($page - 1) * 30));
    $q->execute($values);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['source'] = 'bizcn'; $r['source_badge'] = 'bizcn'; $r['source_label'] = '商务中国'; $r['can_bizcn_panel'] = true; }
    unset($r);
    return ['rows' => bs_decorate($pdo, $account, $rows, $now), 'total' => $total, 'page' => $page, 'page_size' => 30, 'source' => 'status', 'category' => $category, 'category_label' => bs_categories()[$category]];
}

/* ---------- 同步（联网部分） ---------- */

function bs_store($pdo, $account, $domain, array $record, $now)
{
    $sql = 'INSERT INTO bizcn_domain_status (provider_account,domainname,audit_status,audit_code,audit_reason,dnvc_status,whois_status,status_text,expire_date,checked_at,error) VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE audit_status=VALUES(audit_status),audit_code=VALUES(audit_code),audit_reason=VALUES(audit_reason),dnvc_status=VALUES(dnvc_status),whois_status=VALUES(whois_status),status_text=VALUES(status_text),expire_date=VALUES(expire_date),checked_at=VALUES(checked_at),error=VALUES(error)';
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') $sql = 'INSERT INTO bizcn_domain_status (provider_account,domainname,audit_status,audit_code,audit_reason,dnvc_status,whois_status,status_text,expire_date,checked_at,error) VALUES (?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(provider_account,domainname) DO UPDATE SET audit_status=excluded.audit_status,audit_code=excluded.audit_code,audit_reason=excluded.audit_reason,dnvc_status=excluded.dnvc_status,whois_status=excluded.whois_status,status_text=excluded.status_text,expire_date=excluded.expire_date,checked_at=excluded.checked_at,error=excluded.error';
    $expire = preg_match('/^\d{4}-\d{2}-\d{2}/', (string)($record['expire_date'] ?? '')) ? substr($record['expire_date'], 0, 10) : null;
    $pdo->prepare($sql)->execute([$account, $domain, mb_substr((string)($record['audit_status'] ?? ''), 0, 20), mb_substr((string)($record['audit_code'] ?? ''), 0, 20), mb_substr((string)($record['audit_reason'] ?? ''), 0, 300), mb_substr((string)($record['dnvc_status'] ?? ''), 0, 6), mb_substr((string)($record['whois_status'] ?? ''), 0, 20), mb_substr((string)($record['status_text'] ?? ''), 0, 200), $expire, $now, mb_substr((string)($record['error'] ?? ''), 0, 200)]);
}
/** 查一个域名的实名与命名审核状态（两次只读调用）。接口失败时返回 error，不抛出，避免整批中断。 */
function bs_fetch($domain)
{
    $record = ['audit_status' => '', 'audit_code' => '', 'audit_reason' => '', 'dnvc_status' => '', 'whois_status' => '', 'status_text' => '', 'expire_date' => '', 'error' => ''];
    try {
        $w = bd_call('infoDomainWhoisService', 'infoDomainWhois', ['infoDomainWhois' => ['domainname' => $domain, 'detailed' => 'false']]);
        $record['dnvc_status'] = (string)($w['dnvc_status'] ?? '');
        $record['whois_status'] = (string)($w['whois_status'] ?? '');
        $record['status_text'] = (string)($w['status'] ?? '');
        $record['expire_date'] = (string)($w['expire_date'] ?? '');
    } catch (RuntimeException $e) {
        $record['error'] = strpos($e->getMessage(), '531') !== false ? '不在本账户或已删除' : '详情读取失败';
    }
    try {
        $a = bd_call('domainService', 'domainAuditQuery', ['domainname' => $domain]);
        $record['audit_status'] = (string)($a['status'] ?? '');
        $record['audit_code'] = (string)($a['failed_code'] ?? '');
        $record['audit_reason'] = (string)($a['failed_reason'] ?? '');
    } catch (RuntimeException $e) {
        if ($record['error'] === '') $record['error'] = '实名状态读取失败';
    }
    return $record;
}
/**
 * 选出需要（重新）查询的域名：从没查过的优先；有问题的（未通过实名 / 命名失败 / Hold / 上次出错）一小时后复查；其余 3 天复查。
 */
function bs_pending($pdo, $account, $limit, $now)
{
    $q = $pdo->prepare("SELECT c.domainname FROM bizcn_domain_catalog c LEFT JOIN bizcn_domain_status s ON s.provider_account=c.provider_account AND s.domainname=c.domainname WHERE c.provider_account=? AND (s.domainname IS NULL OR (s.checked_at<? AND (s.audit_status<>'pass' OR s.dnvc_status NOT IN ('1','2') OR s.status_text LIKE '%clientHold%' OR s.error<>'')) OR s.checked_at<?) ORDER BY (s.domainname IS NULL) DESC, CASE WHEN c.status LIKE '%Hold%' THEN 0 ELSE 1 END, c.regdate DESC, c.domainname LIMIT " . (int)$limit);
    $q->execute([$account, $now - 3600, $now - 3 * 86400]);
    return $q->fetchAll(PDO::FETCH_COLUMN);
}
function bs_sync($pdo, $account, $limit, $seconds, $now = null)
{
    $started = time();
    $now = $now ?? $started;
    $done = 0;
    $errors = 0;
    foreach (bs_pending($pdo, $account, $limit, $now) as $domain) {
        if (time() - $started >= $seconds) break;
        $record = bs_fetch($domain);
        bs_store($pdo, $account, $domain, $record, $now + (time() - $started));
        $done++;
        if ($record['error'] !== '') $errors++;
        usleep(30000);
    }
    return ['checked' => $done, 'errors' => $errors, 'seconds' => time() - $started];
}

/** domains.php 的 list 动作入口：scope=mine 为“我的订单域名”（只读数据库）；表尚未迁移时退回原列表，不影响页面。 */
function bs_list_action($search, $page, $source, $statusKey, $forceRefresh, $scope = 'all', $actor = null)
{
    $pdo = db();
    $account = bd_config()['username'];
    try {
        if ($scope === 'mine' && $actor) {
            $category = ($statusKey !== '' && isset(bs_categories()[$statusKey])) ? $statusKey : '';
            $result = bo_my_list($pdo, $account, $actor, $category, $search, $page);
            $summary = bo_my_counts($pdo, $account, $actor);
            $result['counts'] = null;
            $result['status_summary'] = ['counts' => $summary['counts'], 'total' => $summary['total'], 'labels' => bs_categories(), 'scope' => 'mine'];
            $result['mine_total'] = $summary['total'];
            return $result;
        }
        if ($statusKey !== '' && isset(bs_categories()[$statusKey])) {
            $result = bs_list($pdo, $account, $statusKey, $search, $page);
            $result['counts'] = bd_list('', 1, 'bizcn', false)['counts'] ?? null;
        } else {
            $result = bd_list($search, $page, $source, $forceRefresh);
            $result['rows'] = bs_decorate($pdo, $account, $result['rows']);
        }
        $result['status_summary'] = bs_counts($pdo, $account) + ['labels' => bs_categories()];
        if ($actor) $result['mine_total'] = bo_mine_total($pdo, $account, $actor);
        return $result;
    } catch (PDOException $e) {
        if ($e->getCode() !== '42S02') throw $e;
        return bd_list($search, $page, $source, $forceRefresh);
    }
}
