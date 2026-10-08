<?php
// 列表筛选：月份 + 业务 + 待办 + 关键字（订单号/客户/付款昵称/项目账号的类型·说明·地址·账号·备注）。
$filterBusiness = (string)($_GET['filter_business'] ?? '');
$filterState = (string)($_GET['state'] ?? '');
$keyword = trim((string)($_GET['q'] ?? ''));
$credentialHits = [];
$importFileId = (int)($_GET['import_file'] ?? 0);
$importFileReport = [];
$where = [];
$params = [];
if ($importFileId > 0) {
    try { ps_import_file_get($importFileId, $actor); }
    catch (RuntimeException $e) { http_response_code(403); exit(e($e->getMessage())); }
    $importFileReport = ps_import_result_get($importFileId);
    $fileOrderIds = array_map('intval', $importFileReport['order_ids'] ?? []);
    $where[] = $fileOrderIds ? 'o.id IN (' . implode(',', $fileOrderIds) . ')' : '1=0';
} elseif ($keyword !== '') {
    $credentialHits = pv_search_order_hits($keyword);
    $credentialSql = $credentialHits ? ' OR o.id IN (' . implode(',', array_map('intval', array_keys($credentialHits))) . ')' : '';
    $where[] = '(o.order_no LIKE ? OR o.customer_name LIKE ? OR s.payment_nickname LIKE ?' . $credentialSql . ')';
    array_push($params, '%' . $keyword . '%', '%' . $keyword . '%', '%' . $keyword . '%');
} else {
    $where[] = 'o.' . $dateBasis . '>=? AND o.' . $dateBasis . '<?';
    array_push($params, $month . '-01', date('Y-m-d', strtotime($month . '-01 +1 month')));
}
if (!$participationOnly && $filterBusiness !== '' && isset($businessCatalog[$filterBusiness])) { $where[] = 'o.project_type=?'; $params[] = $filterBusiness; }
if ($filterState === 'open') $where[] = "o.settlement_status IN ('draft','review')";
if ($filterState === 'approved') $where[] = "o.settlement_status IN ('approved','locked')";
if ($filterState === 'unfinished') $where[] = "o.delivery_status='unfinished'";
if ($filterState === 'finished') $where[] = "o.delivery_status='finished'";
if ($filterState === 'pending_delivery') $where[] = "EXISTS (SELECT 1 FROM project_order_requests por WHERE por.order_id=o.id AND por.request_type='delivery_completion' AND por.status='pending')";
if ($filterState === 'pending_upgrade') $where[] = "EXISTS (SELECT 1 FROM project_order_requests por WHERE por.order_id=o.id AND por.request_type='product_upgrade' AND por.status='pending')";
if ($filterState === 'pending_backend') $where[] = "o.project_type IN ('AI网站定制', '网站定制', '网站模板') AND NOT EXISTS (SELECT 1 FROM project_participants p WHERE p.order_id=o.id AND p.commission_group='technical' AND p.role_name LIKE '%后端%')";
if ($actor['role'] === 'finance') {
    if ($filterEmployeeId > 0) { $where[] = 'EXISTS (SELECT 1 FROM project_participants mp WHERE mp.order_id=o.id AND mp.employee_id=?)'; $params[] = $filterEmployeeId; }
} elseif ($filterState === 'pending_backend' && $actor['role'] === 'technical') {
    // 技术人员筛选“待指定后端”时，允许查看所有未指定后端的网站类订单以便认领
} else {
    $where[] = $participationOnly ? 'EXISTS (SELECT 1 FROM project_participants mp WHERE mp.order_id=o.id AND mp.employee_id=?)' : '(EXISTS (SELECT 1 FROM project_participants mp WHERE mp.order_id=o.id AND mp.employee_id=?) OR EXISTS (SELECT 1 FROM project_department_uploaders du WHERE du.order_id=o.id AND du.employee_id=?))';
    $params[] = $actor['employee_id'];
    if (!$participationOnly) $params[] = $actor['employee_id'];
}
$reviewSelect = pa_storage_available() ? 'a.state auto_review_state,a.policy_version auto_review_policy,a.checked_row_version auto_review_version,a.checked_source_at auto_review_source_at,a.reasons_json auto_review_reasons,a.evidence_json auto_review_evidence,a.checked_at auto_review_checked_at,' : 'NULL auto_review_state,';
$reviewJoin = pa_storage_available() ? ' LEFT JOIN project_auto_reviews a ON a.order_id=o.id' : '';
$sql = "SELECT o.*, " . $reviewSelect . " s.synced_at source_synced_at,COALESCE(s.price_source,'missing') price_source, COALESCE(s.payment_nickname,'') payment_nickname, r.domain_mode,
    (SELECT COUNT(*) FROM project_costs c WHERE c.order_id=o.id AND c.review_status='pending') pending_costs,
    (SELECT COALESCE(SUM(c.amount),0) FROM project_costs c WHERE c.order_id=o.id AND c.review_status='approved') approved_costs,
    (SELECT COUNT(*) FROM project_cash_movements m WHERE m.order_id=o.id AND m.review_status='pending') pending_cash,
    (SELECT COUNT(*) FROM project_order_requests por WHERE por.order_id=o.id AND por.request_type='delivery_completion' AND por.status='pending') pending_delivery_requests,
    (SELECT COUNT(*) FROM project_order_requests por WHERE por.order_id=o.id AND por.request_type='product_upgrade' AND por.status='pending') pending_upgrade_requests,
    (SELECT COUNT(*) FROM project_participants p WHERE p.order_id=o.id AND p.commission_group='technical') tech_count,
    (SELECT COUNT(*) FROM project_participants p WHERE p.order_id=o.id AND p.commission_group='technical' AND p.role_name LIKE '%后端%') backend_tech_count,
    EXISTS (SELECT 1 FROM project_department_orders d WHERE d.order_id=o.id) is_department_order,
    (SELECT sp.parent_order_id FROM project_order_splits sp WHERE sp.child_order_id=o.id LIMIT 1) split_parent_id,(SELECT COUNT(*) FROM project_order_splits sc WHERE sc.parent_order_id=o.id) split_children,
    (SELECT GROUP_CONCAT(e.name ORDER BY p.commission_group DESC,p.id SEPARATOR '、') FROM project_participants p JOIN employees e ON e.id=p.employee_id WHERE p.order_id=o.id) people
    FROM project_orders o LEFT JOIN project_order_sources s ON s.order_id=o.id LEFT JOIN project_order_resources r ON r.order_id=o.id" . $reviewJoin . "
    WHERE " . implode(' AND ', $where) . ' ORDER BY o.' . $dateBasis . ' DESC,o.id DESC';
$q = db()->prepare($sql);
$q->execute($params);
$orders = $q->fetchAll();
if ($participationOnly && $filterBusiness !== '') $orders = array_values(array_filter($orders, function ($row) use ($filterBusiness) { return ps_partner_business_bucket($row['project_type']) === $filterBusiness; }));
foreach ($orders as $i => $row) {
    $orders[$i]['auto_review'] = pa_view($row);
    $orders[$i]['monthly_allowance_verified'] = $orders[$i]['auto_review']['state'] !== 'queued' && !empty((json_decode($row['auto_review_evidence']??'{}',true)?:[])['monthly_allowance_rules']);
    $orders[$i]['todos'] = ps_order_todos($row);
    if ($orders[$i]['auto_review']['reasons']) $orders[$i]['todos'] = array_map(function ($reason) { return [$reason['text'], $reason['kind'] === 'exception' ? 'danger' : 'warning']; }, $orders[$i]['auto_review']['reasons']);
    elseif (in_array($orders[$i]['auto_review']['state'], ['ready','auto_passed','settled'], true)) $orders[$i]['todos'] = [];
}
$reviewCounts = [];
foreach ($orders as $row) $reviewCounts[$row['auto_review']['state']] = ($reviewCounts[$row['auto_review']['state']] ?? 0) + 1;
$filterReview = (string)($_GET['check'] ?? '');
if (in_array($filterReview, ['wait_sync','wait_finance','wait_data','exception','ready','auto_passed','settled','queued'], true)) $orders = array_values(array_filter($orders, function ($row) use ($filterReview) { return $row['auto_review']['state'] === $filterReview; }));
if ($filterState === 'todo') $orders = array_values(array_filter($orders, function ($row) { return (bool)$row['todos']; }));
$totals = ['contract' => 0.0, 'receipt' => 0.0, 'cost' => 0.0, 'todo' => 0];
foreach ($orders as $row) {
    $totals['contract'] += (float)$row['contract_amount'];
    $totals['receipt'] += (float)$row['receipt_amount'] - (float)$row['refund_amount'];
    $totals['cost'] += (float)$row['approved_costs'];
    if ($row['todos']) $totals['todo']++;
}
// 分页：每页 30 单；统计仍按全部筛选结果，预计分成只算当前页。
$perPage = 30;
$totalOrders = count($orders);
$totalPages = max(1, (int)ceil($totalOrders / $perPage));
$page = min(max(1, (int)($_GET['page'] ?? 1)), $totalPages);
$pageOrders = array_slice($orders, ($page - 1) * $perPage, $perPage);
$pageQuery = function ($p) { return '?' . http_build_query(array_merge($_GET, ['page' => $p])); };
$commissionCells = [];
$snapshotMap = [];
$approvedIds = array_map(function ($r) { return (int)$r['id']; }, array_filter($pageOrders, function ($r) { return in_array($r['settlement_status'], ['approved', 'locked'], true); }));
if ($approvedIds) {
    $snapQuery = db()->query('SELECT order_id,employee_id,commission_group,commission_amount,subsidy_amount FROM project_commission_snapshots WHERE order_id IN (' . implode(',', $approvedIds) . ')');
    foreach ($snapQuery->fetchAll() as $snap) $snapshotMap[$snap['order_id'] . ':' . $snap['commission_group'] . ':' . $snap['employee_id']] = round((float)$snap['commission_amount'] + (float)$snap['subsidy_amount'], 2);
}
foreach ($pageOrders as $row) {
    $cells = [];
    try {
        $rowPeople = ps_participants((int)$row['id']);
        $isApproved = in_array($row['settlement_status'], ['approved', 'locked'], true);
        $rowSum = $isApproved ? null : ps_summary($row, ps_costs((int)$row['id']), $rowPeople);
        foreach ($rowPeople as $rp) {
            if ($actor['role'] !== 'finance' && (int)$rp['employee_id'] !== (int)$actor['employee_id']) continue;
            $amount = null;
            if ($isApproved) $amount = $snapshotMap[$row['id'] . ':' . $rp['commission_group'] . ':' . $rp['employee_id']] ?? null;
            else foreach ($rowSum['groups'][$rp['commission_group']]['people'] ?? [] as $sp) if ((int)$sp['employee_id'] === (int)$rp['employee_id'] && $sp['estimated_calc']) $amount = round($sp['estimated_calc']['share'] + $sp['estimated_calc']['subsidy'], 2);
            $cells[] = ['name' => $rp['name'], 'group' => $rp['commission_group'], 'employee_id' => (int)$rp['employee_id'], 'amount' => $amount, 'estimated' => !$isApproved];
        }
    } catch (Throwable $e) { $cells = []; }
    $commissionCells[(int)$row['id']] = $cells;
}
$createdOrder = null;
if (isset($_GET['created'])) {
    $createdQuery = db()->prepare('SELECT id,order_no,project_type FROM project_orders WHERE id=?');
    $createdQuery->execute([(int)$_GET['created']]);
    $createdOrder = $createdQuery->fetch() ?: null;
}
$openEntry = $error !== '' || isset($_GET['entry']);
$bulkResult = $_SESSION['project_bulk_result'] ?? null;
unset($_SESSION['project_bulk_result']);
$deleteResult = $_SESSION['project_delete_result'] ?? null;
unset($_SESSION['project_delete_result']);
$page_title = $actor['role'] === 'finance' ? '项目订单结算' : '我的项目订单';
