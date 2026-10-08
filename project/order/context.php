<?php
$order = ps_order($id, $actor);
$error = '';
$success = '';

/* split: project/order/actions/dispatch.php */ include (dirname(__DIR__, 1)) . '/order/actions/dispatch.php';$order = ps_order($id, $actor);
$sourceQuery = db()->prepare('SELECT * FROM project_order_sources WHERE order_id=?');
$sourceQuery->execute([$id]);
$orderSource = $sourceQuery->fetch() ?: ['payment_nickname' => '', 'trade_status' => '', 'price_source' => (float)$order['contract_amount'] > 0 ? 'manual' : 'missing'];
$resourceQuery = db()->prepare('SELECT * FROM project_order_resources WHERE order_id=?');
$resourceQuery->execute([$id]);
$orderResource = $resourceQuery->fetch();
$detailsQuery = db()->prepare('SELECT details_json FROM project_order_details WHERE order_id=?');
$detailsQuery->execute([$id]);
$businessDetails = json_decode((string)($detailsQuery->fetchColumn() ?: '{}'), true) ?: [];
$businessDefinition = ps_business_catalog()[$order['project_type']] ?? null;
$costs = ps_costs($id);
$cashMovements = ps_cash_movements($id);
$people = ps_participants($id);
$sum = ps_summary($order, $costs, $people);
$canEdit = !in_array($order['settlement_status'], ['approved','locked'], true);
$approvedSnapshots = [];
$snapshotByPerson = [];
$snapshotPool = ['technical' => 0.0, 'customer_service' => 0.0];
if (!$canEdit) {
    $snapshotQuery = db()->prepare('SELECT * FROM project_commission_snapshots WHERE order_id=? ORDER BY id');
    $snapshotQuery->execute([$id]);
    $approvedSnapshots = $snapshotQuery->fetchAll();
    foreach ($approvedSnapshots as $snapshot) {
        $snapshotByPerson[$snapshot['commission_group'] . ':' . $snapshot['employee_id']] = $snapshot;
        $snapshotPool[$snapshot['commission_group']] += (float)$snapshot['commission_amount'];
    }
}
$ownCommission = 0.0;
$ownCommissionConfigured = true;
if ($actor['role'] !== 'finance') {
    if ($canEdit) {
        foreach ($people as $person) if ((int)$person['employee_id'] === $actor['employee_id']) {
            $calcPerson = $personLookup[$person['commission_group'] . ':' . $person['employee_id']] ?? null;
            if (!$calcPerson || !$calcPerson['estimated_calc']) $ownCommissionConfigured = false;
            else $ownCommission += round($calcPerson['estimated_calc']['share'] + $calcPerson['estimated_calc']['subsidy'], 2);
        }
    } else {
        foreach ($approvedSnapshots as $snapshot) if ((int)$snapshot['employee_id'] === $actor['employee_id']) $ownCommission += (float)$snapshot['commission_amount'];
    }
}
$templateQuery = db()->prepare("SELECT * FROM project_cost_templates WHERE is_active=1 AND (business_scope='' OR business_scope=?) ORDER BY FIELD(category,'program','domain','server','certificate','plugin','certification','api','outsourcing','other'),name,specification"
    );
$templateQuery->execute([ps_business_normalize($order['project_type'])]);
$templates = $templateQuery->fetchAll();
$shopMatches = ps_shop_order_lookup($order['order_no']);
$orderKinds = ps_business_order_kinds($order['project_type']);
$backendTechCount = count(array_filter($people, function ($p) { return $p['commission_group'] === 'technical' && in_array('后端', ps_role_keys($p['role_name'] ?? ''), true); }));
$todoRow = $order + ['price_source' => $orderSource['price_source'], 'domain_mode' => $orderResource['domain_mode'] ?? '', 'pending_costs' => count(array_filter($costs, function ($c
    ) { return $c['review_status'] === 'pending'; })), 'pending_cash' => count(array_filter($cashMovements, function ($m) { return $m['review_status'] === 'pending'; })), 'tech_count'
    => count(array_filter($people, function ($p) { return $p['commission_group'] === 'technical'; })), 'backend_tech_count' => $backendTechCount];
$todos = ps_order_todos($todoRow);
$personLookup = [];
foreach (['technical', 'customer_service'] as $groupKey) foreach ($sum['groups'][$groupKey]['people'] as $calcPerson) $personLookup[$groupKey . ':' . $calcPerson['employee_id']] =
    $calcPerson;
$employees = $actor['role'] === 'finance' ? db()->query('SELECT id,name,department FROM employees ORDER BY department,name,id')->fetchAll() : [];
$shops = db()->query('SELECT name FROM shops ORDER BY sort,id')->fetchAll(PDO::FETCH_COLUMN);
$websiteOrder = ps_is_website_order($order['project_type']);
$collabOrder = ps_business_requires_technical($order['project_type']);
$counterpartGroup = $actor['role'] === 'customer_service' ? 'technical' : 'customer_service';
$counterpartCount = 0;
foreach ($people as $person) if ($person['commission_group'] === $counterpartGroup) $counterpartCount++;
$counterpartChoices = [];
if ($collabOrder && $canEdit && !$counterpartCount && in_array($actor['role'], ['customer_service','technical'], true)) {
    $q = db()->prepare('SELECT e.id,e.name,e.department FROM employees e JOIN project_users u ON u.employee_id=e.id WHERE u.role=? AND u.is_active=1 ORDER BY e.name,e.id');
    $q->execute([$counterpartGroup]);
    foreach ($q->fetchAll() as $person) if (ps_active_employee_for_business($person['id'], $counterpartGroup, ps_business_normalize($order['project_type']))) $counterpartChoices[]
    = $person;
}

$isWebsiteOrder = ps_is_website_order($order['project_type']);
$hasBackendTech = false;
$hasFrontendTech = false;
$isFullstack = false;
$currentFrontendPerson = null;
$currentBackendPerson = null;
foreach ($people as $person) {
    if ($person['commission_group'] === 'technical') {
        $rKeys = ps_role_keys($person['role_name']);
        $hasBack = in_array('后端', $rKeys, true);
        $hasFront = in_array('前端', $rKeys, true) || in_array('外包前端', $rKeys, true) || in_array('定制前端', $rKeys, true) || in_array('模板技术', $rKeys, true) ||
    in_array('技术', $rKeys, true) || in_array('制作技术', $rKeys, true) || !$hasBack;
        if ($hasBack && $hasFront) {
            $isFullstack = true;
            $hasBackendTech = true;
            $hasFrontendTech = true;
            $currentBackendPerson = $person;
            $currentFrontendPerson = $person;
        } elseif ($hasBack) {
            $hasBackendTech = true;
            $currentBackendPerson = $person;
        } elseif ($hasFront) {
            $hasFrontendTech = true;
            if (!$currentFrontendPerson) {
                $currentFrontendPerson = $person;
            }
        }
    }
}
if (!$currentFrontendPerson) {
    foreach ($people as $person) {
        if ($person['commission_group'] === 'technical') {
            $currentFrontendPerson = $person;
            $hasFrontendTech = true;
            break;
        }
    }
}
$backendChoices = [];
if ($isWebsiteOrder) {
    $qB = db()->query('SELECT e.id,e.name,e.department FROM employees e JOIN project_users u ON u.employee_id=e.id WHERE u.role="technical" AND u.is_active=1 ORDER BY e.name,e.id')
    ;
    foreach ($qB->fetchAll() as $person) {
        if (ps_active_employee_for_business($person['id'], 'technical', ps_business_normalize($order['project_type']))
            || ps_active_employee_for_business($person['id'], 'technical', 'AI网站定制')
            || ps_active_employee_for_business($person['id'], 'technical', '网站模板')) {
            $backendChoices[] = $person;
        }
    }
}

$orderRequests = ps_order_requests($id);
$pendingDeliveryReq = ps_order_pending_request($id, 'delivery_completion');
$pendingUpgradeReq = ps_order_pending_request($id, 'product_upgrade');
$assignedReviewerUsername = ps_business_reviewer($order['project_type']);
$assignedReviewerObj = null;
foreach (ps_admin_reviewers() as $r) if (strtolower($r['username']) === strtolower($assignedReviewerUsername)) $assignedReviewerObj = $r;
$assignedReviewerName = $assignedReviewerObj ? $assignedReviewerObj['real_name'] : $assignedReviewerUsername;
$canReviewThisBusiness = ps_actor_can_review_business($actor, $order['project_type']);
$upgradePrograms = db()->query("SELECT id, name, specification, price FROM project_cost_templates WHERE category='program' AND is_active=1 ORDER BY name, price")->fetchAll();

if ($pendingDeliveryReq) $todos[] = ['交付凭证待审核', 'warning'];
if ($pendingUpgradeReq) $todos[] = ['产品升级待审核', 'primary'];

$page_title = '订单结算单 ' . $order['order_no'];
