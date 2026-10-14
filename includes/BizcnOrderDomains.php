<?php
/**
 * 订单域名：把业务订单与域名对上，供员工按“自己参与的订单”快速管理解析。
 * 列表只读数据库（本表 + 订单参与人 + 目录 + 状态表），不调用任何外部接口；NS 归属由后台任务用公网 NS 记录刷新。
 * 链接来源：renewal（续费台账）/ detail（订单登记的网站）由后台任务重建，registration（注册时绑定）/ manual（手动绑定）永久保留。
 */
function bo_provider_labels()
{
    return ['bizcn' => '商务中国解析', 'dnspod' => 'DNSPod', 'cloudflare' => 'Cloudflare', 'mixed' => 'NS 混用', 'other' => '外部解析', 'none' => '未读取到 NS', '' => ''];
}
function bo_extract_domain($text)
{
    if (!preg_match('/([a-z0-9][-a-z0-9]*\.(?:com\.cn|net\.cn|org\.cn|com|cn|net|org|cc|top|xyz|vip|shop|wang|site|ltd|co))/i', (string)$text, $m)) return '';
    return strtolower($m[1]);
}
function bo_auto_links($pdo)
{
    $links = [];
    $stmt = $pdo->query("SELECT order_id, resource_name, expires_on FROM project_renewal_items WHERE resource_type='domain' AND resource_name<>''");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $d = bo_extract_domain($r['resource_name']);
        if ($d === '') continue;
        $key = (int)$r['order_id'] . '|' . $d;
        $expires = preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$r['expires_on']) ? substr($r['expires_on'], 0, 10) : null;
        if (!isset($links[$key]) || ($expires !== null && ($links[$key]['expires_on'] === null || $expires > $links[$key]['expires_on']))) $links[$key] = ['order_id' => (int)$r['order_id'], 'domainname' => $d, 'source' => 'renewal', 'expires_on' => $expires];
    }
    $stmt = $pdo->query("SELECT order_id, details_json FROM project_order_details WHERE details_json LIKE '%website_url%'");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $det = json_decode($r['details_json'], true) ?: [];
        $d = bo_extract_domain($det['website_url'] ?? '');
        if ($d === '') continue;
        $key = (int)$r['order_id'] . '|' . $d;
        if (!isset($links[$key])) $links[$key] = ['order_id' => (int)$r['order_id'], 'domainname' => $d, 'source' => 'detail', 'expires_on' => null];
    }
    return $links;
}
/** 重建自动来源的链接；手动 / 注册绑定不动。返回 [新增, 更新, 删除]。 */
function bo_rebuild($pdo)
{
    $want = bo_auto_links($pdo);
    $have = [];
    foreach ($pdo->query('SELECT order_id, domainname, source, expires_on FROM bizcn_order_domains')->fetchAll(PDO::FETCH_ASSOC) as $r) $have[(int)$r['order_id'] . '|' . $r['domainname']] = $r;
    $add = $upd = $del = 0;
    $ins = $pdo->prepare("INSERT INTO bizcn_order_domains (order_id,domainname,source,expires_on) VALUES (?,?,?,?)");
    $set = $pdo->prepare('UPDATE bizcn_order_domains SET expires_on=? WHERE order_id=? AND domainname=?');
    $rm = $pdo->prepare('DELETE FROM bizcn_order_domains WHERE order_id=? AND domainname=?');
    $pdo->beginTransaction();
    try {
        foreach ($want as $key => $l) {
            if (!isset($have[$key])) { $ins->execute([$l['order_id'], $l['domainname'], $l['source'], $l['expires_on']]); $add++; }
            elseif (in_array($have[$key]['source'], ['renewal', 'detail'], true) && (string)$have[$key]['expires_on'] !== (string)$l['expires_on']) { $set->execute([$l['expires_on'], $l['order_id'], $l['domainname']]); $upd++; }
        }
        foreach ($have as $key => $r) if (!isset($want[$key]) && in_array($r['source'], ['renewal', 'detail'], true)) { $rm->execute([$r['order_id'], $r['domainname']]); $del++; }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return [$add, $upd, $del];
}
/** 用公网 NS 记录刷新 NS 归属（一天一次，每次最多 $limit 个、$seconds 秒）。 */
function bo_ns_refresh($pdo, $limit, $seconds, $now = null)
{
    $now = $now ?? time();
    $started = time();
    $q = $pdo->query('SELECT domainname FROM bizcn_order_domains GROUP BY domainname HAVING MIN(ns_checked_at) < ' . (int)($now - 86400) . ' ORDER BY MIN(ns_checked_at), domainname LIMIT ' . (int)$limit);
    $save = $pdo->prepare('UPDATE bizcn_order_domains SET ns_hosts=?, ns_provider=?, ns_checked_at=? WHERE domainname=?');
    $done = 0;
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $domain) {
        if (time() - $started >= $seconds) break;
        $ns = dns_public_ns($domain);
        $save->execute([mb_substr(implode(',', $ns), 0, 300), bdr_provider($ns), $now + (time() - $started), $domain]);
        $done++;
    }
    return $done;
}

/* ---------- 权限与绑定 ---------- */

function bo_employee_id($actor)
{
    return ($actor['type'] ?? '') === 'employee' ? (int)($actor['employee_id'] ?? 0) : 0;
}
function bo_can_manage_order($pdo, $actor, $orderId)
{
    if (($actor['type'] ?? '') === 'admin' || ($actor['role'] ?? '') === 'finance') return true;
    $employee = bo_employee_id($actor);
    if ($employee <= 0) return false;
    $q = $pdo->prepare('SELECT 1 FROM project_participants WHERE order_id=? AND employee_id=?');
    $q->execute([(int)$orderId, $employee]);
    return (bool)$q->fetchColumn();
}
function bo_find_order($pdo, $orderNo)
{
    $orderNo = trim((string)$orderNo);
    if ($orderNo === '' || mb_strlen($orderNo) > 100 || preg_match('/[\x00-\x1f]/', $orderNo)) throw new InvalidArgumentException('请填写正确的订单号');
    $q = $pdo->prepare('SELECT id, order_no, customer_name FROM project_orders WHERE order_no=?');
    $q->execute([$orderNo]);
    $order = $q->fetch(PDO::FETCH_ASSOC);
    if (!$order) throw new RuntimeException('没有找到订单 ' . $orderNo . '，请核对订单号');
    return $order;
}
/** 注册时校验订单：必须是自己参与的订单（管理员 / 财务不限）。返回订单行。 */
function bo_order_for_actor($pdo, $actor, $orderNo)
{
    $order = bo_find_order($pdo, $orderNo);
    if (!bo_can_manage_order($pdo, $actor, $order['id'])) throw new RuntimeException('只能把域名绑定到自己参与的订单');
    return $order;
}
function bo_link($pdo, $orderId, $domain, $source, $actor = null)
{
    $by = $actor ? ((string)($actor['type'] ?? '') . ':' . (int)($actor['id'] ?? 0)) : '';
    $exists = $pdo->prepare('SELECT source FROM bizcn_order_domains WHERE order_id=? AND domainname=?');
    $exists->execute([(int)$orderId, $domain]);
    $current = $exists->fetchColumn();
    if ($current === false) $pdo->prepare('INSERT INTO bizcn_order_domains (order_id,domainname,source,created_by) VALUES (?,?,?,?)')->execute([(int)$orderId, $domain, $source, $by]);
    elseif (in_array($current, ['renewal', 'detail'], true)) $pdo->prepare('UPDATE bizcn_order_domains SET source=?, created_by=? WHERE order_id=? AND domainname=?')->execute([$source, $by, (int)$orderId, $domain]);
}
function bo_orders_for($pdo, $actor, $domain)
{
    $q = $pdo->prepare('SELECT o.id, o.order_no, o.customer_name, od.source FROM bizcn_order_domains od JOIN project_orders o ON o.id=od.order_id WHERE od.domainname=? ORDER BY o.id DESC LIMIT 20');
    $q->execute([$domain]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['can_manage'] = bo_can_manage_order($pdo, $actor, $r['id']); }
    unset($r);
    return $rows;
}
function bo_action($action, $input, $actor)
{
    $pdo = db();
    $domain = bd_domain($input['domain'] ?? '');
    $order = bo_find_order($pdo, $input['order_no'] ?? '');
    if (!bo_can_manage_order($pdo, $actor, $order['id'])) throw new RuntimeException('只能绑定 / 解绑自己参与的订单');
    if ($action === 'order_bind') {
        bo_link($pdo, $order['id'], $domain, 'manual', $actor);
        ps_audit('domain', 0, 'order_bind', $actor, ['domain' => $domain, 'order_no' => $order['order_no']]);
        return ['message' => '已把 ' . $domain . ' 绑定到订单 ' . $order['order_no'], 'orders' => bo_orders_for($pdo, $actor, $domain)];
    }
    $pdo->prepare('DELETE FROM bizcn_order_domains WHERE order_id=? AND domainname=?')->execute([$order['id'], $domain]);
    ps_audit('domain', 0, 'order_unbind', $actor, ['domain' => $domain, 'order_no' => $order['order_no']]);
    return ['message' => '已解除 ' . $domain . ' 与订单 ' . $order['order_no'] . ' 的绑定', 'orders' => bo_orders_for($pdo, $actor, $domain)];
}

/* ---------- “我的订单域名”列表 ---------- */

function bo_expiry_expr() { return 'COALESCE(s.expire_date,c.expireddate,od.expires_on)'; }
function bo_from($actor)
{
    $employee = bo_employee_id($actor);
    $scoped = $employee > 0 && ($actor['role'] ?? '') !== 'finance';
    $inner = 'SELECT x.domainname, MAX(x.order_id) order_id FROM bizcn_order_domains x' . ($scoped ? ' JOIN project_participants p ON p.order_id=x.order_id AND p.employee_id=?' : '') . ' GROUP BY x.domainname';
    $sql = ' FROM (' . $inner . ') m JOIN bizcn_order_domains od ON od.order_id=m.order_id AND od.domainname=m.domainname JOIN project_orders o ON o.id=m.order_id'
        . ' LEFT JOIN bizcn_domain_catalog c ON c.provider_account=? AND c.domainname=m.domainname'
        . ' LEFT JOIN bizcn_domain_status s ON s.provider_account=? AND s.domainname=m.domainname ';
    return [$sql, $scoped ? [$employee] : []];
}
function bo_search_where($pdo, $search)
{
    $search = trim((string)$search);
    if ($search === '') return ['', []];
    $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
    $domainExpr = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'm.domainname' : 'CONVERT(m.domainname USING utf8mb4)';
    return [" AND ($domainExpr LIKE ? ESCAPE '!' OR o.order_no LIKE ? ESCAPE '!' OR o.customer_name LIKE ? ESCAPE '!')", [$pattern, $pattern, $pattern]];
}
function bo_my_counts($pdo, $account, $actor, $now = null)
{
    list($today, $soon) = bs_dates($now);
    list($from, $fromParams) = bo_from($actor);
    $select = ['COUNT(*) total'];
    $params = [];
    foreach (array_keys(bs_categories()) as $key) {
        list($sql, $p) = bs_condition($key, $today, $soon, bo_expiry_expr());
        $select[] = "SUM(CASE WHEN $sql THEN 1 ELSE 0 END) k_$key";
        $params = array_merge($params, $p);
    }
    $q = $pdo->prepare('SELECT ' . implode(',', $select) . $from);
    $q->execute(array_merge($params, $fromParams, [$account, $account]));
    $r = $q->fetch(PDO::FETCH_ASSOC);
    $counts = [];
    foreach (array_keys(bs_categories()) as $key) $counts[$key] = (int)$r['k_' . $key];
    return ['total' => (int)$r['total'], 'counts' => $counts];
}
function bo_my_list($pdo, $account, $actor, $category, $search, $page, $now = null)
{
    list($today, $soon) = bs_dates($now);
    list($from, $fromParams) = bo_from($actor);
    $where = '1=1';
    $whereParams = [];
    if ($category !== '') {
        list($cond, $condParams) = bs_condition($category, $today, $soon, bo_expiry_expr());
        $where .= ' AND ' . $cond;
        $whereParams = $condParams;
    }
    list($searchSql, $searchParams) = bo_search_where($pdo, $search);
    $where .= $searchSql;
    $values = array_merge($fromParams, [$account, $account], $whereParams, $searchParams);
    $q = $pdo->prepare('SELECT COUNT(*)' . $from . 'WHERE ' . $where);
    $q->execute($values);
    $total = (int)$q->fetchColumn();
    $page = max(1, min((int)$page, max(1, (int)ceil($total / 30))));
    $q = $pdo->prepare('SELECT m.domainname, m.order_id, o.order_no, o.customer_name, c.registrar, c.regdate, c.status c_status, c.domainname in_catalog, ' . bo_expiry_expr() . ' expireddate, od.ns_hosts, od.ns_provider' . $from . 'WHERE ' . $where . ' ORDER BY (' . bo_expiry_expr() . ' IS NULL), ' . bo_expiry_expr() . ', m.domainname LIMIT 30 OFFSET ' . (($page - 1) * 30));
    $q->execute($values);
    $labels = bo_provider_labels();
    $rows = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $inCatalog = $r['in_catalog'] !== null;
        $rows[] = [
            'domainname' => $r['domainname'], 'display_name' => $r['domainname'],
            'registrar' => $inCatalog ? ($r['registrar'] ?: '商务中国') : '外部注册商（订单登记）',
            'owner_username' => ($r['customer_name'] ?: '客户') . ' · 订单:' . $r['order_no'],
            'regdate' => $r['regdate'], 'expireddate' => $r['expireddate'], 'status' => $inCatalog ? ($r['c_status'] ?: '正常') : '客户域名',
            'source' => $inCatalog ? 'bizcn' : 'renewal', 'source_badge' => $inCatalog ? 'bizcn' : 'renewal', 'source_label' => $inCatalog ? '商务中国' : '订单域名',
            'nameserver' => $r['ns_hosts'] === '' ? [] : explode(',', $r['ns_hosts']), 'ns_provider' => $r['ns_provider'], 'ns_label' => $labels[$r['ns_provider']] ?? '',
            'order_id' => (int)$r['order_id'], 'order_no' => $r['order_no'], 'customer_name' => $r['customer_name'], 'can_bizcn_panel' => $inCatalog,
        ];
    }
    return ['rows' => bs_decorate($pdo, $account, $rows, $now), 'total' => $total, 'page' => $page, 'page_size' => 30, 'source' => 'mine', 'scope' => 'mine'];
}
function bo_mine_total($pdo, $account, $actor)
{
    list($from, $fromParams) = bo_from($actor);
    $q = $pdo->prepare('SELECT COUNT(*)' . $from);
    $q->execute(array_merge($fromParams, [$account, $account]));
    return (int)$q->fetchColumn();
}
