<?php
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectBusiness.php';
require_once __DIR__ . '/../includes/ProjectOrderSource.php';
$actor = ps_require_actor();
$error = '';
$businessCatalog = ps_business_catalog();
$allowedBusinesses = ps_actor_businesses($actor);
// 只有固定报酬、不录订单的合作人员（如售后退款部）：直接进入“我的项目报酬”
if ($actor['role'] !== 'finance' && !$allowedBusinesses && $_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . BASE_URL . '/project/payroll.php'); exit; }
$selectedBusiness = ps_business_choice($actor, (string)($_POST['project_type'] ?? $_GET['business'] ?? ''));
$shops = db()->query('SELECT name FROM shops ORDER BY sort,id')->fetchAll(PDO::FETCH_COLUMN);
$month = (string)($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = date('Y-m');
$employees = db()->query('SELECT id,name,department FROM employees ORDER BY department,name,id')->fetchAll();
$employeesById = [];
foreach ($employees as $employee) $employeesById[(int)$employee['id']] = $employee;
$activeTechnicalIds = array_map('intval', db()->query("SELECT employee_id FROM project_users WHERE role='technical' AND is_active=1")->fetchAll(PDO::FETCH_COLUMN));
// 微信代写编辑员（客服账号）在代写订单上担任“对接编辑”，可在技术 / 对接栏选到
$activeTechnicalIds = array_values(array_unique(array_merge($activeTechnicalIds, array_map('intval', db()->query("SELECT u.employee_id FROM project_users u JOIN project_user_businesses b ON b.user_id=u.id AND b.business_name='微信代写' WHERE u.is_active=1")->fetchAll(PDO::FETCH_COLUMN)))));
$activeCustomerServiceIds = array_map('intval', db()->query("SELECT employee_id FROM project_users WHERE role='customer_service' AND is_active=1")->fetchAll(PDO::FETCH_COLUMN));
// 合作人员可在两栏都选到自己（身兼客服与技术的人员，如环境配置）。
$selfEmployeeId = (int)($actor['employee_id'] ?? 0);
$technicalChoices = $actor['role'] === 'finance' ? $employees : array_values(array_filter($employees, function ($emp) use ($activeTechnicalIds, $selfEmployeeId) { return in_array((int)$emp['id'], $activeTechnicalIds, true) || (int)$emp['id'] === $selfEmployeeId; }));
$customerServiceChoices = $actor['role'] === 'finance' ? $employees : array_values(array_filter($employees, function ($emp) use ($activeCustomerServiceIds, $selfEmployeeId) { return in_array((int)$emp['id'], $activeCustomerServiceIds, true) || (int)$emp['id'] === $selfEmployeeId; }));
$domainTemplates = ps_intake_templates('domain');
$serverTemplates = ps_intake_templates('server');
$programTemplates = ps_intake_templates('program');
$outsourceTemplates = ps_intake_templates('outsourcing');

// 财务批量操作：按售价确认实收 / 标记交付完成 / 批量审核。逐单独立事务，失败的订单列出原因，不影响其他订单。
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    ps_check_csrf();
    if ($actor['role'] !== 'finance') { http_response_code(403); exit('无权限'); }
    $bulkAction = (string)$_POST['bulk_action'];
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
    $payrollMonth = (string)($_POST['bulk_month'] ?? '');
    $done = 0;
    $failed = [];
    foreach (array_slice($ids, 0, 300) as $orderId) {
        $orderNo = '#' . $orderId;
        try {
            if ($bulkAction === 'approve') {
                $noQuery = db()->prepare('SELECT order_no FROM project_orders WHERE id=?');
                $noQuery->execute([$orderId]);
                $orderNo = (string)$noQuery->fetchColumn();
                ps_approve_order($orderId, $actor, $payrollMonth);
                $done++;
                continue;
            }
            db()->beginTransaction();
            $q = db()->prepare('SELECT o.*,COALESCE(s.price_source,\'missing\') price_source,COALESCE(s.trade_status,\'\') trade_status FROM project_orders o LEFT JOIN project_order_sources s ON s.order_id=o.id WHERE o.id=? FOR UPDATE');
            $q->execute([$orderId]);
            $row = $q->fetch();
            if (!$row) throw new RuntimeException('订单不存在');
            $orderNo = $row['order_no'];
            if (in_array($row['settlement_status'], ['approved', 'locked'], true)) throw new RuntimeException('已审核');
            if ($bulkAction === 'receipt') {
                if ((float)$row['receipt_amount'] != 0) throw new RuntimeException('已有实收，未重复登记');
                $isOffset = ($row['order_kind'] ?? '') === '退款冲减' && (float)$row['contract_amount'] < 0;
                if ($row['price_source'] === 'missing' || ((float)$row['contract_amount'] <= 0 && !$isOffset)) throw new RuntimeException('售价待补');
                if (mb_strpos($row['trade_status'], '关闭') !== false || mb_strpos($row['trade_status'], '退款') !== false) throw new RuntimeException('店铺状态为“' . $row['trade_status'] . '”，请逐单核对');
                db()->prepare("INSERT INTO project_cash_movements (order_id,movement_type,amount,note,review_status,submitted_by_type,submitted_by_id,reviewed_by_admin,reviewed_at) VALUES (?,'receipt',?,'批量按售价确认实收','approved','admin',?,?,NOW())")
                    ->execute([$orderId, $row['contract_amount'], $actor['id'], $actor['id']]);
                ps_recalculate_cash($orderId);
                ps_audit('cash', (int)db()->lastInsertId(), 'bulk_receipt', $actor, ['order_id' => $orderId, 'amount' => $row['contract_amount']]);
            } elseif ($bulkAction === 'finish') {
                if ($row['delivery_status'] === 'finished') throw new RuntimeException('已是完成状态');
                db()->prepare("UPDATE project_orders SET delivery_status='finished',row_version=row_version+1 WHERE id=?")->execute([$orderId]);
                ps_audit('order', $orderId, 'bulk_finish', $actor, []);
            } else throw new RuntimeException('操作无效');
            db()->commit();
            $done++;
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            $failed[] = $orderNo . '：' . ($e instanceof PDOException ? '保存失败' : $e->getMessage());
        }
    }
    $_SESSION['project_bulk_result'] = ['action' => ['receipt' => '确认实收', 'finish' => '标记交付完成', 'approve' => '审核并生成分成'][$bulkAction] ?? $bulkAction, 'done' => $done, 'failed' => $failed];
    header('Location: ' . BASE_URL . '/project/index.php?' . (string)($_POST['return_query'] ?? '')); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    if (!in_array($actor['role'], ['finance', 'customer_service', 'technical'], true)) { http_response_code(403); exit('无权限'); }
    try {
        $no = trim((string)($_POST['order_no'] ?? ''));
        $date = trim((string)($_POST['order_date'] ?? '')) ?: date('Y-m-d');
        $contract = trim((string)($_POST['contract_amount'] ?? ''));
        $receipt = $actor['role'] === 'finance' ? (string)($_POST['receipt_amount'] ?? '') : '0';
        if ($receipt === '') $receipt = '0';
        $projectType = (string)($_POST['project_type'] ?? '');
        $business = ps_require_business($actor, $projectType);
        $peopleLabels = ps_business_people_labels($projectType);
        $orderKind = ps_order_kind_valid($projectType, $_POST['order_kind'] ?? '');
        if ($orderKind === '' && !empty($business['default_kind'])) $orderKind = $business['default_kind'];
        $isOffset = $orderKind === '退款冲减';
        // 代写 / 期刊 / 微信代写 / 网站续费 / 网站修改：录单时直接填写稿费或成本（¥500 以内自动通过，超过由财务审核）
        $directCost = !empty($business['import_cost']) ? trim((string)($_POST['direct_cost'] ?? '')) : '';
        if ($directCost !== '' && (!preg_match($isOffset ? '/^-?\d+(?:\.\d{1,2})?$/' : '/^\d+(?:\.\d{1,2})?$/', $directCost) || abs((float)$directCost) > 999999999999.99)) throw new RuntimeException(($business['cost_label'] ?? '成本') . '须为金额，最多两位小数');
        if ($orderKind === '' && !empty($business['kind_required'])) throw new RuntimeException('请选择订单类型（新订单 / 定制 / 续费…），它决定分成比例和每单补助');
        $canChooseResources = $business['resources'] && $actor['role'] !== 'customer_service';
        $programTemplate = $canChooseResources && !empty($business['program']) && (int)($_POST['program_template_id'] ?? 0) > 0 ? ps_intake_template((int)$_POST['program_template_id'], 'program') : null;
        $domainMode = $business['resources'] ? ($canChooseResources ? (string)($_POST['domain_mode'] ?? 'pending') : 'pending') : 'none';
        // 程序套餐已含空间与域名：只选套餐即视为资源已确认、无需另购域名。
        if ($programTemplate && $domainMode === 'pending') $domainMode = 'none';
        $domainTemplate = $domainMode === 'template' ? ps_intake_template((int)($_POST['domain_template_id'] ?? 0), 'domain') : null;
        $serverTemplate = $canChooseResources && (int)($_POST['server_template_id'] ?? 0) > 0 ? ps_intake_template((int)$_POST['server_template_id'], 'server') : null;
        if (!in_array($domainMode, ['pending', 'none', 'template'], true)) throw new RuntimeException('请选择待补充、无需域名或具体域名成本模板');
        if ($no === '' || strlen($no) > 100) throw new RuntimeException('请填写有效订单号');
        $existing = db()->prepare('SELECT id FROM project_orders WHERE order_no=?');
        $existing->execute([$no]);
        $existingId = (int)$existing->fetchColumn();
        if ($existingId) {
            if ($actor['role'] !== 'finance') {
                $access = db()->prepare('SELECT 1 FROM project_participants WHERE order_id=? AND employee_id=?');
                $access->execute([$existingId, $actor['employee_id']]);
                if (!$access->fetchColumn()) throw new RuntimeException('该订单编号已由同事建档，请让对方在结算单中关联你，或联系财务核对归属');
            }
            header('Location: ' . BASE_URL . '/project/order.php?id=' . $existingId); exit;
        }
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) throw new RuntimeException('请选择有效日期');
        if (($contract !== '' && !preg_match($isOffset ? '/^-?\d+(?:\.\d{1,2})?$/' : '/^\d+(?:\.\d{1,2})?$/', $contract)) || !preg_match('/^\d+(?:\.\d{1,2})?$/', $receipt) || (float)$contract > 999999999999.99 || (float)$receipt > 999999999999.99) throw new RuntimeException('金额须为非负数，最多两位小数');
        $sslCost = $canChooseResources ? trim((string)($_POST['ssl_cost'] ?? '')) : '';
        if ($sslCost !== '' && (!preg_match('/^\d+(?:\.\d{1,2})?$/', $sslCost) || (float)$sslCost > 999999999999.99)) throw new RuntimeException('SSL 实际成本最多两位小数');
        $customer = trim((string)($_POST['customer_name'] ?? ''));
        $shop = trim((string)($_POST['shop'] ?? ''));
        if ($shop !== '' && empty($business['free_shop']) && !in_array($shop, $shops, true)) throw new RuntimeException('请选择店铺列表中的店铺');
        $details = ps_business_details($projectType, $actor['role'] === 'customer_service' && $projectType === '网站模板' ? [] : ($_POST['details'] ?? []));
        if (mb_strlen($customer) > 200 || mb_strlen($shop) > 150 || mb_strlen($projectType) > 100) throw new RuntimeException('客户、店铺或业务类型过长');
        $paymentNickname = trim((string)($_POST['payment_nickname'] ?? ''));
        $tradeStatus = trim((string)($_POST['trade_status'] ?? ''));
        $contactNote = trim((string)($_POST['contact_note'] ?? ''));
        $resourceNote = trim((string)($_POST['resource_note'] ?? ''));
        if (mb_strlen($paymentNickname) > 200 || mb_strlen($tradeStatus) > 100 || mb_strlen($contactNote) > 500 || mb_strlen($resourceNote) > 500) throw new RuntimeException('备注内容过长');
        // 外包给下游（如华梦）：成本按成本中心的外包模板计入，不需要指定本公司技术。
        $outsourceTemplate = (int)($_POST['outsource_template_id'] ?? 0) > 0 ? ps_intake_template((int)$_POST['outsource_template_id'], 'outsourcing') : null;
        if ($outsourceTemplate && $outsourceTemplate['business_scope'] !== '' && $outsourceTemplate['business_scope'] !== $projectType) throw new RuntimeException('所选外包成本不适用于' . $projectType);
        if ($outsourceTemplate && ($outsourceTemplate['price_mode'] ?? 'fixed') === 'percent' && ($contract === '' || (float)$contract <= 0)) throw new RuntimeException('外包成本按售价比例计算，请先填写售价');
        $groups = ['technical' => [], 'customer_service' => []];
        foreach (['customer_service_id' => ['customer_service', '客服'], 'frontend_id' => ['technical', $peopleLabels['frontend']], 'backend_id' => ['technical', $peopleLabels['backend']]] as $field => $target) {
            $personId = (int)($_POST[$field] ?? 0);
            if ($personId <= 0) continue;
            if (!isset($employeesById[$personId])) throw new RuntimeException('所选合作人员不存在');
            $group = $target[0];
            if (isset($groups[$group][$personId])) $groups[$group][$personId]['role'] .= '/' . $target[1];
            else $groups[$group][$personId] = ['id' => $personId, 'role' => $target[1]];
        }
        if ($actor['role'] !== 'finance') {
            // 本人已在任一栏（如纪鹏程作为客服录入别人做的环境配置单）时不再自动追加，避免多算一份分成。
            $selfGroup = $actor['role'] === 'technical' ? 'technical' : 'customer_service';
            $selfId = (int)$actor['employee_id'];
            if (!isset($groups['technical'][$selfId]) && !isset($groups['customer_service'][$selfId])) $groups[$selfGroup][$selfId] = ['id' => $selfId, 'role' => $selfGroup === 'technical' ? $peopleLabels['frontend'] : '客服'];
        }
        if (!$groups['technical'] && !$groups['customer_service']) throw new RuntimeException('请至少选择一位客服或技术参与人');
        // 客服与制作人员共用一张订单：客服建单须指定已开通同业务账号的技术，对方登录即可看到并补成本。
        if ($actor['role'] === 'customer_service' && ps_business_requires_technical($projectType) && !$outsourceTemplate) {
            if (!$groups['technical']) throw new RuntimeException('请指定接收此单的技术（外包给华梦等下游时，请在“外包”里选择外包成本）');
            foreach ($groups['technical'] as $person) if ((int)$person['id'] !== $selfEmployeeId && !ps_active_employee_for_business($person['id'], 'technical', $projectType)) throw new RuntimeException('指定的技术未开通当前业务的有效账号，请联系财务配置');
        }
        if ($actor['role'] === 'technical' && ps_business_requires_technical($projectType)) {
            foreach ($groups['customer_service'] as $person) if ((int)$person['id'] !== $selfEmployeeId && !ps_active_employee_for_business($person['id'], 'customer_service', $projectType)) throw new RuntimeException('指定的客服未开通当前业务的有效账号，请联系财务配置');
        }
        // 设计图片：同一客服同一客户当月已有图片单时，本单记“图片同客户”（不计 0.5 元单量）
        if ($projectType === '设计' && $orderKind === '图片' && trim($paymentNickname . $customer) !== '') {
            $csIds = array_keys($groups['customer_service']);
            if ($csIds) {
                $repeat = db()->prepare("SELECT 1 FROM project_orders o JOIN project_participants p ON p.order_id=o.id AND p.commission_group='customer_service' WHERE o.project_type='设计' AND o.order_kind='图片' AND DATE_FORMAT(o.order_date,'%Y-%m')=? AND p.employee_id IN (" . implode(',', array_map('intval', $csIds)) . ") AND REPLACE(LOWER(o.customer_name),' ','')=? LIMIT 1");
                $repeat->execute([substr($date, 0, 7), preg_replace('/\s+/u', '', mb_strtolower($customer !== '' ? $customer : $paymentNickname))]);
                if ($repeat->fetchColumn()) $orderKind = '图片同客户';
            }
        }
        if ($customer === '' && $paymentNickname !== '') $customer = $paymentNickname;
        $noteParts = [];
        if ($paymentNickname !== '') $noteParts[] = '付款昵称：' . $paymentNickname;
        if ($contactNote !== '') $noteParts[] = '客户联系方式：' . $contactNote;
        if ($programTemplate) $noteParts[] = '程序套餐：' . $programTemplate['name'] . ' ' . $programTemplate['specification'];
        if ($outsourceTemplate) $noteParts[] = '外包：' . $outsourceTemplate['name'] . ' ' . $outsourceTemplate['specification'];
        if ($business['resources'] && $domainMode !== 'pending' && !$programTemplate) $noteParts[] = $domainMode === 'none' ? '域名：无需域名' : '域名：' . $domainTemplate['name'] . ' ' . $domainTemplate['specification'];
        if ($resourceNote !== '') $noteParts[] = '域名/空间说明：' . $resourceNote;
        if ($sslCost !== '' && (float)$sslCost > 0) $noteParts[] = 'SSL 实际成本报备：¥' . $sslCost . '（待技术补充成本凭证）';
        db()->beginTransaction();
        $q = db()->prepare('INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,receipt_amount,order_date,delivery_status,note,created_by_admin) VALUES (?,?,?,?,?,?,0,?,?,?,?)');
        $q->execute([$no, $customer, $projectType, $orderKind, $shop, $contract === '' ? 0 : round((float)$contract, 2), $date, ($_POST['delivery_status'] ?? '') === 'finished' ? 'finished' : 'unfinished', implode('；', $noteParts), $actor['role'] === 'finance' ? $actor['id'] : null]);
        $id = (int)db()->lastInsertId();
        ps_source_record($id, $contract === '' ? 'missing' : 'manual', $paymentNickname, $tradeStatus);
        ps_save_business_details($id, $projectType, $details);
        if ($actor['role'] === 'finance' && (float)$receipt > 0) {
            db()->prepare("INSERT INTO project_cash_movements (order_id,movement_type,amount,note,review_status,submitted_by_type,submitted_by_id,reviewed_by_admin,reviewed_at) VALUES (?,'receipt',?,'新建订单初始实收','approved','admin',?,?,NOW())")
                ->execute([$id, round((float)$receipt, 2), $actor['id'], $actor['id']]);
            ps_audit('cash', (int)db()->lastInsertId(), 'create', $actor, ['order_id' => $id, 'type' => 'receipt', 'amount' => round((float)$receipt, 2), 'status' => 'approved']);
            ps_recalculate_cash($id);
        }
        ps_intake_participants($id, $groups, $projectType);
        if ($business['resources']) ps_intake_save_resources($id, 'manual', null, $domainTemplate, $serverTemplate, $sslCost !== '' ? $sslCost : null, $domainMode, $programTemplate);
        if ($programTemplate) ps_intake_add_template_cost($id, $programTemplate, $actor, '手动录入：程序套餐');
        if ($outsourceTemplate) ps_intake_add_template_cost($id, $outsourceTemplate, $actor, '手动录入：外包');
        if ($domainTemplate) ps_intake_add_template_cost($id, $domainTemplate, $actor, '手动录入：域名');
        if ($serverTemplate) ps_intake_add_template_cost($id, $serverTemplate, $actor, '手动录入：服务器');
        if ($directCost !== '' && (float)$directCost != 0) {
            $costAmount = round((float)$directCost, 2);
            db()->prepare("INSERT INTO project_costs (order_id,category,item_name,quantity,unit,unit_price,amount,cost_kind,is_custom,reason,review_status,submitted_by_employee) VALUES (?,'outsourcing',?,1,'项',?,?,'one_time',1,'手动录入',?,?)")
                ->execute([$id, $business['cost_label'] ?? '成本', $costAmount, $costAmount, abs($costAmount) <= 500 ? 'approved' : 'pending', $actor['employee_id'] ?? null]);
        }
        ps_audit('order', $id, 'create', $actor, ['order_no' => $no, 'order_kind' => $orderKind, 'program_template_id' => $programTemplate['id'] ?? null, 'domain_template_id' => $domainTemplate['id'] ?? null, 'server_template_id' => $serverTemplate['id'] ?? null, 'receipt_unconfirmed' => $actor['role'] !== 'finance']);
        ps_sync_existing_shop_order($id, $no, $shop);
        db()->commit();
        if (($_POST['after_save'] ?? '') === 'next') {
            header('Location: ' . BASE_URL . '/project/index.php?' . http_build_query(['business' => $projectType, 'created' => $id, 'entry' => 1])); exit;
        }
        header('Location: ' . BASE_URL . '/project/order.php?id=' . $id); exit;
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); $error = $e instanceof PDOException ? '订单号已存在或数据保存失败' : $e->getMessage(); }
}

// 列表筛选：月份 + 业务 + 待办 + 关键字（订单号/客户/付款昵称）。
$filterBusiness = (string)($_GET['filter_business'] ?? '');
$filterState = (string)($_GET['state'] ?? '');
$keyword = trim((string)($_GET['q'] ?? ''));
$where = [];
$params = [];
if ($keyword !== '') {
    $where[] = '(o.order_no LIKE ? OR o.customer_name LIKE ? OR s.payment_nickname LIKE ?)';
    array_push($params, '%' . $keyword . '%', '%' . $keyword . '%', '%' . $keyword . '%');
} else {
    $where[] = 'o.order_date>=? AND o.order_date<?';
    array_push($params, $month . '-01', date('Y-m-d', strtotime($month . '-01 +1 month')));
}
if ($filterBusiness !== '' && isset($businessCatalog[$filterBusiness])) { $where[] = 'o.project_type=?'; $params[] = $filterBusiness; }
if ($filterState === 'open') $where[] = "o.settlement_status IN ('draft','review')";
if ($filterState === 'approved') $where[] = "o.settlement_status IN ('approved','locked')";
if ($actor['role'] !== 'finance') { $where[] = 'EXISTS (SELECT 1 FROM project_participants mp WHERE mp.order_id=o.id AND mp.employee_id=?)'; $params[] = $actor['employee_id']; }
$sql = "SELECT o.*, COALESCE(s.price_source,'missing') price_source, COALESCE(s.payment_nickname,'') payment_nickname, r.domain_mode,
    (SELECT COUNT(*) FROM project_costs c WHERE c.order_id=o.id AND c.review_status='pending') pending_costs,
    (SELECT COALESCE(SUM(c.amount),0) FROM project_costs c WHERE c.order_id=o.id AND c.review_status='approved') approved_costs,
    (SELECT COUNT(*) FROM project_cash_movements m WHERE m.order_id=o.id AND m.review_status='pending') pending_cash,
    (SELECT COUNT(*) FROM project_participants p WHERE p.order_id=o.id AND p.commission_group='technical') tech_count,
    (SELECT GROUP_CONCAT(e.name ORDER BY p.commission_group DESC,p.id SEPARATOR '、') FROM project_participants p JOIN employees e ON e.id=p.employee_id WHERE p.order_id=o.id) people
    FROM project_orders o LEFT JOIN project_order_sources s ON s.order_id=o.id LEFT JOIN project_order_resources r ON r.order_id=o.id
    WHERE " . implode(' AND ', $where) . ' ORDER BY o.order_date DESC,o.id DESC LIMIT 500';
$q = db()->prepare($sql);
$q->execute($params);
$orders = $q->fetchAll();
foreach ($orders as $i => $row) $orders[$i]['todos'] = ps_order_todos($row);
if ($filterState === 'todo') $orders = array_values(array_filter($orders, function ($row) { return (bool)$row['todos']; }));
$totals = ['contract' => 0.0, 'receipt' => 0.0, 'cost' => 0.0, 'todo' => 0];
foreach ($orders as $row) {
    $totals['contract'] += (float)$row['contract_amount'];
    $totals['receipt'] += (float)$row['receipt_amount'] - (float)$row['refund_amount'];
    $totals['cost'] += (float)$row['approved_costs'];
    if ($row['todos']) $totals['todo']++;
}
$createdOrder = null;
if (isset($_GET['created'])) {
    $createdQuery = db()->prepare('SELECT id,order_no FROM project_orders WHERE id=?');
    $createdQuery->execute([(int)$_GET['created']]);
    $createdOrder = $createdQuery->fetch() ?: null;
}
$openEntry = $error !== '' || isset($_GET['entry']);
$bulkResult = $_SESSION['project_bulk_result'] ?? null;
unset($_SESSION['project_bulk_result']);
$page_title = $actor['role'] === 'finance' ? '项目订单结算' : '我的项目订单';
include __DIR__ . '/../includes/header.php';
$resourceHint = function ($t) { return trim($t['name'] . ' ' . $t['specification']) . ' · ¥' . money($t['price']); };
?>
<div class="project-intake-page">
<div class="project-hero mb-3"><div><div class="project-eyebrow">项目合作结算中心 · 订单入口</div><h2><?php if ($actor['role'] === 'finance'): echo e($page_title); else: $hour = (int)date('G'); echo ($hour < 11 ? '早上好' : ($hour < 14 ? '中午好' : ($hour < 18 ? '下午好' : '晚上好'))) . '，' . e($display_name); endif; ?></h2><p><?php echo $actor['role'] === 'customer_service' ? '客服录入买家与成交信息并指定技术；技术在同一订单号补资源和成本，双方看到的是同一张结算单。' : ($actor['role'] === 'technical' ? '打开本人参与的订单补技术资料与成本；先建单时可在结算单关联客服。' : '客服与技术共用一张订单结算单。输入订单号即可从店铺 / ETMLL 流水带出买家与售价，标准成本从成本中心带入。'); ?> 实收由财务确认。</p></div><div class="project-hero-actions"><?php if ($allowedBusinesses): ?><button class="btn btn-light" type="button" id="manualOrderToggle" aria-controls="manual-order" aria-expanded="<?php echo $openEntry ? 'true' : 'false'; ?>"><i class="fas fa-pen mr-1"></i> <span><?php echo $openEntry ? '收起手动录入' : '手动录入订单'; ?></span></button><a class="btn btn-outline-light" href="<?php echo BASE_URL; ?>/project/import.php?business=<?php echo rawurlencode($selectedBusiness); ?>"><i class="fas fa-file-excel mr-1"></i> 拖拽上传 Excel</a><?php endif; ?><?php if ($actor['role'] === 'finance'): ?><a class="btn btn-outline-light" href="<?php echo BASE_URL; ?>/project/settings.php#cost-center">成本中心</a><?php endif; ?></div></div>
<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
<?php if ($createdOrder): ?><div class="alert alert-success d-flex justify-content-between align-items-center flex-wrap"><span><i class="fas fa-check-circle mr-1"></i> 订单 <strong><?php echo e($createdOrder['order_no']); ?></strong> 已保存，可以继续录入下一单。</span><a class="btn btn-sm btn-outline-success" href="<?php echo BASE_URL; ?>/project/order.php?id=<?php echo (int)$createdOrder['id']; ?>">打开刚保存的结算单</a></div><?php endif; ?>
<?php if ($bulkResult): ?><div class="alert alert-<?php echo $bulkResult['failed'] ? 'warning' : 'success'; ?>"><strong>批量<?php echo e($bulkResult['action']); ?>：</strong>成功 <?php echo (int)$bulkResult['done']; ?> 单<?php if ($bulkResult['failed']): ?>，<?php echo count($bulkResult['failed']); ?> 单未处理：<ul class="mb-0 mt-1 small"><?php foreach (array_slice($bulkResult['failed'], 0, 30) as $failure): ?><li><?php echo e($failure); ?></li><?php endforeach; ?></ul><?php endif; ?></div><?php endif; ?>
<?php if (!$allowedBusinesses): ?><div class="alert alert-warning">当前账户尚未匹配业务类型，请联系财务在项目结算配置中分配。</div><?php endif; ?>
<?php if ($allowedBusinesses): ?>
<div id="manual-order" class="card project-form-card mb-4<?php echo $openEntry ? '' : ' d-none'; ?>"><div class="card-body">
  <div class="project-section-title"><span class="project-step">01</span><div><h5>手动录入订单</h5><p>先填订单号即可建档。系统会立即在店铺订单 / ETMLL 同步流水里查找同号订单，自动带出店铺、付款昵称、售价和交易状态；已建档的订单号会直接提示打开原结算单。</p></div></div>
  <form method="post" id="projectManualForm" autocomplete="off">
    <input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>">
    <?php if (ps_ai_ready()): ?>
    <div class="project-ai-box mb-3">
      <div class="d-flex justify-content-between align-items-center flex-wrap" style="gap:8px"><strong><i class="fas fa-wand-magic-sparkles mr-1"></i> AI 智能识别</strong><small class="text-muted">把淘宝订单详情、微信聊天或客户需求整段粘贴进来，AI 帮你填好下面的空白栏，保存前可以再改。</small></div>
      <textarea class="form-control mt-2" id="aiPaste" rows="3" maxlength="4000" placeholder="例如：订单号 5127194379715039218，美呀美店铺，买家 tb_xxx 付款 998 元，JSP展示中级版，客户微信 abc123…"></textarea>
      <div class="d-flex align-items-center mt-2" style="gap:10px"><button type="button" class="btn btn-outline-success btn-sm" id="aiParseBtn"><i class="fas fa-magic mr-1"></i> 识别并填写</button><span id="aiStatus" class="small text-muted" aria-live="polite"></span></div>
    </div>
    <?php endif; ?>
    <div class="form-row">
      <div class="form-group col-md-4"><label for="intakeOrderNo">订单编号 *</label><input class="form-control form-control-lg" id="intakeOrderNo" name="order_no" maxlength="100" value="<?php echo e($_POST['order_no'] ?? ''); ?>" placeholder="粘贴店铺订单号" required autofocus></div>
      <div class="form-group col-md-3"><label for="intakeBusiness">业务类型 *</label><select class="form-control form-control-lg" id="intakeBusiness" name="project_type" required><?php foreach ($allowedBusinesses as $businessName): ?><option value="<?php echo e($businessName); ?>" <?php echo $selectedBusiness === $businessName ? 'selected' : ''; ?>><?php echo e($businessName); ?></option><?php endforeach; ?></select></div>
      <div class="form-group col-md-2" id="intakeKindWrap"><label for="intakeKind">订单类型</label><select class="form-control form-control-lg" id="intakeKind" name="order_kind"><option value="">—</option></select></div>
      <div class="form-group col-md-3"><label for="intakeDate">日期</label><input class="form-control form-control-lg" id="intakeDate" type="date" name="order_date" value="<?php echo e($_POST['order_date'] ?? date('Y-m-d')); ?>"></div>
    </div>
    <div id="intakeLookup" class="project-lookup" hidden aria-live="polite"></div>
    <div class="form-row">
      <div class="form-group col-md-3"><label for="intakeShop">店铺（可后补）</label><input class="form-control" id="intakeShop" name="shop" list="intakeShopList" maxlength="150" value="<?php echo e($_POST['shop'] ?? ''); ?>" placeholder="可不填，待上传匹配" autocomplete="off"><datalist id="intakeShopList"><?php foreach ($shops as $shopName): ?><option value="<?php echo e($shopName); ?>"><?php endforeach; ?></datalist></div>
      <div class="form-group col-md-3"><label for="intakeNickname">付款昵称（可后补）</label><input class="form-control" id="intakeNickname" name="payment_nickname" maxlength="200" value="<?php echo e($_POST['payment_nickname'] ?? ''); ?>"></div>
      <div class="form-group col-md-3"><label for="intakePrice">售价（可后补）</label><div class="input-group"><div class="input-group-prepend"><span class="input-group-text">¥</span></div><input class="form-control" id="intakePrice" type="number" step="0.01" min="0" name="contract_amount" value="<?php echo e($_POST['contract_amount'] ?? ''); ?>" placeholder="待订单上传补全"></div></div>
      <div class="form-group col-md-3" id="intakeDirectCostWrap" hidden><label for="intakeDirectCost" id="intakeDirectCostLabel">成本</label><div class="input-group"><div class="input-group-prepend"><span class="input-group-text">¥</span></div><input class="form-control" id="intakeDirectCost" type="number" step="0.01" name="direct_cost" value="<?php echo e($_POST['direct_cost'] ?? ''); ?>" placeholder="如写手稿费"></div><small class="text-muted">¥500 以内自动通过，超过由财务审核</small></div>
      <div class="form-group col-md-3"><label for="intakeTrade">店铺交易状态（可后补）</label><input class="form-control" id="intakeTrade" name="trade_status" maxlength="100" value="<?php echo e($_POST['trade_status'] ?? ''); ?>" placeholder="如交易成功"></div>
    </div>
    <div class="form-row">
      <div class="form-group col-md-3"><label>客户 / 公司</label><input class="form-control" name="customer_name" maxlength="200" value="<?php echo e($_POST['customer_name'] ?? ''); ?>"></div>
      <div class="form-group col-md-3"><label>项目交付状态</label><select class="form-control" name="delivery_status"><option value="unfinished">未完成</option><option value="finished" <?php echo ($_POST['delivery_status'] ?? '') === 'finished' ? 'selected' : ''; ?>>已完成</option></select></div>
      <div class="form-group col-md-<?php echo $actor['role'] === 'finance' ? '4' : '6'; ?>"><label>备注 / 客户电话或微信</label><input class="form-control" name="contact_note" maxlength="500" value="<?php echo e($_POST['contact_note'] ?? ''); ?>" placeholder="仅参与本订单的合作人员和财务可见"></div>
      <?php if ($actor['role'] === 'finance'): ?><div class="form-group col-md-2"><label>已确认实收</label><input class="form-control" type="number" step="0.01" min="0" name="receipt_amount" value="<?php echo e($_POST['receipt_amount'] ?? ''); ?>" placeholder="0"></div><?php endif; ?>
    </div>
    <div class="project-divider"></div><div class="project-mini-title">参与人员 <small>本人会自动加入对应组；多人合作先均分，财务可在结算单调整权重。财务配置过默认岗位（如外包前端、售后）的人员会自动套用对应算法。</small></div>
    <div class="form-row">
      <div class="form-group col-md-4"><label id="intakeCustomerServiceLabel">客服</label><select class="form-control" name="customer_service_id"><option value="0">待关联，可在结算单补</option><?php foreach ($customerServiceChoices as $emp): ?><option value="<?php echo (int)$emp['id']; ?>" <?php echo (int)($_POST['customer_service_id'] ?? ($actor['role'] === 'customer_service' ? $actor['employee_id'] : 0)) === (int)$emp['id'] ? 'selected' : ''; ?>><?php echo e($emp['name'] . ' · ' . $emp['department']); ?></option><?php endforeach; ?></select></div>
      <div class="form-group col-md-4"><label id="intakeFrontendLabel"><?php echo e(ps_business_people_labels($selectedBusiness)['frontend']); ?></label><select class="form-control" name="frontend_id"><option value="0">待指定</option><?php foreach ($technicalChoices as $emp): ?><option value="<?php echo (int)$emp['id']; ?>" <?php echo (int)($_POST['frontend_id'] ?? ($actor['role'] === 'technical' ? $actor['employee_id'] : 0)) === (int)$emp['id'] ? 'selected' : ''; ?>><?php echo e($emp['name'] . ' · ' . $emp['department']); ?></option><?php endforeach; ?></select></div>
      <div class="form-group col-md-4"><label id="intakeBackendLabel"><?php echo e(ps_business_people_labels($selectedBusiness)['backend']); ?></label><select class="form-control" name="backend_id"><option value="0">无 / 待指定</option><?php foreach ($technicalChoices as $emp): ?><option value="<?php echo (int)$emp['id']; ?>" <?php echo (int)($_POST['backend_id'] ?? 0) === (int)$emp['id'] ? 'selected' : ''; ?>><?php echo e($emp['name'] . ' · ' . $emp['department']); ?></option><?php endforeach; ?></select></div>
    </div>
    <?php if ($outsourceTemplates): ?><div class="form-row" id="intakeOutsourceRow"><div class="form-group col-md-8"><label for="intakeOutsource">外包给下游（不走本公司技术时选择）</label><select class="form-control" id="intakeOutsource" name="outsource_template_id"><option value="0">不外包</option><?php foreach ($outsourceTemplates as $t): ?><option value="<?php echo (int)$t['id']; ?>" data-scope="<?php echo e($t['business_scope']); ?>" data-mode="<?php echo e($t['price_mode']); ?>" data-price="<?php echo e($t['price']); ?>" <?php echo (int)($_POST['outsource_template_id'] ?? 0) === (int)$t['id'] ? 'selected' : ''; ?>><?php echo e($t['name'] . ' · ' . ($t['price_mode'] === 'percent' ? '成本 = 售价 × ' . rtrim(rtrim($t['price'], '0'), '.') . '%' : '成本 ¥' . money($t['price']))); ?></option><?php endforeach; ?></select><small class="text-muted">如华梦定制：成本按售价的比例自动计入，客服无需指定本公司技术，客服分成照常计算。</small></div></div><?php endif; ?>
    <?php foreach ($allowedBusinesses as $businessName): $specificFields = $businessCatalog[$businessName]['fields']; if (!$specificFields || ($actor['role'] === 'customer_service' && $businessName === '网站模板')) continue; ?>
    <div class="project-business-fields" data-business="<?php echo e($businessName); ?>"><div class="project-divider"></div><div class="project-mini-title"><?php echo e($businessName); ?>专属信息</div><div class="form-row"><?php foreach ($specificFields as $fieldKey => $fieldLabel): ?><div class="form-group col-md-4"><label><?php echo e($fieldLabel); ?></label><input class="form-control" name="details[<?php echo e($fieldKey); ?>]" maxlength="300" value="<?php echo e($_POST['details'][$fieldKey] ?? ''); ?>" placeholder="填写<?php echo e($fieldLabel); ?>"></div><?php endforeach; ?></div></div>
    <?php endforeach; ?>
    <div id="intakeResourceFields"><div class="project-divider"></div><div class="project-mini-title">技术提交 · 资源与成本 <small>可先留空，技术确认后再计成本；无需域名不产生域名成本</small></div>
    <div class="form-row" id="intakeProgramRow">
      <div class="form-group col-md-8"><label for="intakeProgram">程序套餐（成本中心 · 含空间/域名/商投）</label><select class="form-control" id="intakeProgram" name="program_template_id"><option value="0">暂不选择 / 非套餐</option><?php $lastProgram = ''; foreach ($programTemplates as $t): if ($t['name'] !== $lastProgram): if ($lastProgram !== ''): ?></optgroup><?php endif; $lastProgram = $t['name']; ?><optgroup label="<?php echo e($t['name']); ?>"><?php endif; ?><option value="<?php echo (int)$t['id']; ?>" data-price="<?php echo e($t['price']); ?>" data-scope="<?php echo e($t['business_scope']); ?>" <?php echo (int)($_POST['program_template_id'] ?? 0) === (int)$t['id'] ? 'selected' : ''; ?>><?php echo e($t['name'] . ' · ' . $t['specification'] . ' · 成本 ¥' . money($t['price']) . ($t['supplier_price'] !== null ? '（采购 ¥' . money($t['supplier_price']) . '）' : '')); ?></option><?php endforeach; if ($lastProgram !== ''): ?></optgroup><?php endif; ?></select><?php if (!$programTemplates): ?><small class="text-warning">成本中心尚未导入程序套餐，财务可在成本中心一键导入《程序表记录》。</small><?php endif; ?></div>
    </div>
    <div class="form-row"><div class="form-group col-md-4"><label>域名使用</label><select class="form-control" id="intakeDomainMode" name="domain_mode"><option value="pending" <?php echo ($_POST['domain_mode'] ?? 'pending') === 'pending' ? 'selected' : ''; ?>>待技术确认</option><option value="none" <?php echo ($_POST['domain_mode'] ?? '') === 'none' ? 'selected' : ''; ?>>无需域名</option><option value="template" <?php echo ($_POST['domain_mode'] ?? '') === 'template' ? 'selected' : ''; ?>>使用标准域名</option></select></div><div class="form-group col-md-4" id="intakeDomainTemplateWrap"><label>域名规格与周期</label><select class="form-control" id="intakeDomainTemplate" name="domain_template_id"><option value="">请选择标准模板</option><?php foreach ($domainTemplates as $t): ?><option value="<?php echo (int)$t['id']; ?>" data-price="<?php echo e($t['price']); ?>" <?php echo (int)($_POST['domain_template_id'] ?? 0) === (int)$t['id'] ? 'selected' : ''; ?>><?php echo e($resourceHint($t) . '/' . $t['unit']); ?></option><?php endforeach; ?></select><?php if (!$domainTemplates): ?><small class="text-warning">尚无可用域名成本模板，请财务先配置价格。</small><?php endif; ?></div><div class="form-group col-md-4"><label>服务器 / 空间（如使用）</label><select class="form-control" id="intakeServerTemplate" name="server_template_id"><option value="0">本单不选标准服务器</option><?php foreach ($serverTemplates as $t): ?><option value="<?php echo (int)$t['id']; ?>" data-price="<?php echo e($t['price']); ?>" <?php echo (int)($_POST['server_template_id'] ?? 0) === (int)$t['id'] ? 'selected' : ''; ?>><?php echo e($resourceHint($t) . '/' . $t['unit']); ?></option><?php endforeach; ?></select></div></div>
    <div class="form-row"><div class="form-group col-md-8"><label>域名或空间说明</label><input class="form-control" name="resource_note" maxlength="500" value="<?php echo e($_POST['resource_note'] ?? ''); ?>" placeholder="如客户域名 example.com、服务器账户或续费提醒"></div><div class="form-group col-md-4"><label>SSL 证书真实成本（如有）</label><input class="form-control" type="number" step="0.01" min="0" name="ssl_cost" value="<?php echo e($_POST['ssl_cost'] ?? ''); ?>" placeholder="非标准成本，创建后补凭证"></div></div>
    </div>
    <div class="project-cost-strip"><span><i class="fas fa-receipt mr-1"></i> 标准成本</span><strong id="intakeCostTotal">¥0.00</strong><span class="project-cost-strip-sep" aria-hidden="true"></span><span>服务费 <b id="intakeFeeRate">0%</b></span><strong id="intakeFee">¥0.00</strong><span class="project-cost-strip-sep" aria-hidden="true"></span><span>预计贡献利润</span><strong id="intakeProfit">—</strong><small id="intakeCostHint">请先选择域名使用方式</small></div>
    <div class="project-form-footer"><p id="intakeFooterHint">合作人员提交的售价仅作订单申报，实收仍由财务审核。</p><div class="d-flex flex-wrap" style="gap:10px"><button class="btn btn-outline-success btn-lg" type="submit" name="after_save" value="next">保存并录入下一单</button><button class="btn btn-success btn-lg" type="submit" name="after_save" value="open">保存并打开结算单 <i class="fas fa-arrow-right ml-1"></i></button></div></div>
  </form>
</div></div>
<?php endif; ?>
<div class="card mb-3"><div class="card-body py-3"><form method="get" class="form-row align-items-end">
  <div class="col-md-2 mb-2"><label class="small text-muted mb-1" for="month">订单月份</label><input class="form-control" type="month" name="month" id="month" value="<?php echo e($month); ?>"></div>
  <div class="col-md-2 mb-2"><label class="small text-muted mb-1" for="filterBusiness">业务</label><select class="form-control" name="filter_business" id="filterBusiness"><option value="">全部业务</option><?php foreach ($businessCatalog as $businessName => $definition): ?><option value="<?php echo e($businessName); ?>" <?php echo $filterBusiness === $businessName ? 'selected' : ''; ?>><?php echo e($businessName . (!empty($definition['legacy']) ? '（历史）' : '')); ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2 mb-2"><label class="small text-muted mb-1" for="filterState">状态</label><select class="form-control" name="state" id="filterState"><option value="">全部</option><option value="todo" <?php echo $filterState === 'todo' ? 'selected' : ''; ?>>有待办</option><option value="open" <?php echo $filterState === 'open' ? 'selected' : ''; ?>>未审核</option><option value="approved" <?php echo $filterState === 'approved' ? 'selected' : ''; ?>>已审核</option></select></div>
  <div class="col-md-4 mb-2"><label class="small text-muted mb-1" for="filterQ">搜索（订单号 / 客户 / 付款昵称，搜索时不限月份）</label><input class="form-control" type="search" name="q" id="filterQ" value="<?php echo e($keyword); ?>" placeholder="输入关键字"></div>
  <div class="col-md-2 mb-2"><button class="btn btn-outline-primary btn-block">筛选</button></div>
</form></div></div>
<?php $mine = $actor['role'] !== 'finance' ? '我参与的' : ''; ?><div class="project-totals mb-3"><div><small><?php echo $mine; ?>订单</small><strong><?php echo count($orders); ?></strong></div><div><small><?php echo $mine; ?>售价合计</small><strong>¥<?php echo money($totals['contract']); ?></strong></div><div><small>已确认净实收</small><strong>¥<?php echo money($totals['receipt']); ?></strong></div><div><small>已审核直接成本</small><strong>¥<?php echo money($totals['cost']); ?></strong></div><div class="<?php echo $totals['todo'] ? 'is-alert' : ''; ?>"><small>有待办的订单</small><strong><?php echo $totals['todo']; ?></strong></div></div>
<?php $isFinance = $actor['role'] === 'finance'; ?>
<?php if ($isFinance): ?><form method="post" id="bulkForm"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="return_query" value="<?php echo e(http_build_query(array_intersect_key($_GET, array_flip(['month','filter_business','state','q'])))); ?>"><?php endif; ?>
<div class="card"><div class="card-header d-flex justify-content-between align-items-center flex-wrap" style="gap:8px"><span>订单列表（<?php echo count($orders); ?>）</span><?php if ($isFinance && $orders): ?><div class="project-bulk-bar"><span class="small text-muted" id="bulkCount">已选 0 单</span><button class="btn btn-sm btn-outline-success" name="bulk_action" value="receipt" onclick="return confirm('按售价为所选订单确认实收？已有实收、售价待补或店铺交易关闭的订单会跳过。')">按售价确认实收</button><button class="btn btn-sm btn-outline-success" name="bulk_action" value="finish">标记交付完成</button><input type="month" name="bulk_month" class="form-control form-control-sm" style="width:150px" value="<?php echo e($month); ?>" aria-label="分成归属月份"><button class="btn btn-sm btn-success" name="bulk_action" value="approve" onclick="return confirm('审核所选订单并生成项目分成？不满足条件的订单会跳过并列出原因。')">批量审核</button></div><?php endif; ?></div><div class="table-responsive"><table class="table table-hover mb-0 project-order-table">
  <thead><tr><?php if ($isFinance): ?><th style="width:34px"><input type="checkbox" id="bulkAll" aria-label="全选"></th><?php endif; ?><th>订单号 / 付款昵称</th><th>业务</th><th>日期</th><th>参与人</th><th class="text-right">售价</th><th class="text-right">净实收</th><th class="text-right">直接成本</th><th>待办</th><th>状态</th><th></th></tr></thead><tbody>
  <?php foreach ($orders as $order): ?><tr>
    <?php if ($isFinance): ?><td><?php if (!in_array($order['settlement_status'], ['approved','locked'], true)): ?><input type="checkbox" name="ids[]" value="<?php echo (int)$order['id']; ?>" class="bulk-item" aria-label="选择 <?php echo e($order['order_no']); ?>"><?php endif; ?></td><?php endif; ?>
    <td><strong><?php echo e($order['order_no']); ?></strong><div class="small text-muted"><?php echo e($order['payment_nickname'] ?: ($order['customer_name'] ?: '—')); ?></div></td>
    <td><?php echo e($order['project_type']); ?><?php if ($order['order_kind'] !== ''): ?><div class="small text-muted"><?php echo e($order['order_kind']); ?></div><?php endif; ?></td>
    <td class="text-nowrap"><?php echo e($order['order_date']); ?></td>
    <td class="small"><?php echo e($order['people'] ?: '—'); ?></td>
    <td class="text-right"><?php echo $order['price_source'] === 'missing' ? '<span class="text-muted">待补</span>' : '¥' . money($order['contract_amount']); ?></td>
    <td class="text-right">¥<?php echo money((float)$order['receipt_amount'] - (float)$order['refund_amount']); ?></td>
    <td class="text-right">¥<?php echo money($order['approved_costs']); ?></td>
    <td><?php foreach ($order['todos'] as [$text, $level]): ?><span class="badge badge-<?php echo e($level); ?> mr-1 mb-1"><?php echo e($text); ?></span><?php endforeach; ?><?php if (!$order['todos']): ?><span class="text-muted small">—</span><?php endif; ?></td>
    <td class="text-nowrap"><?php echo e(ps_label('settlement', $order['settlement_status'])); ?></td>
    <td><a class="btn btn-outline-primary btn-sm text-nowrap" href="<?php echo BASE_URL; ?>/project/order.php?id=<?php echo (int)$order['id']; ?>">打开结算单</a></td>
  </tr><?php endforeach; ?>
  <?php if (!$orders): ?><tr><td colspan="11" class="text-center text-muted py-4"><?php echo $keyword !== '' ? '没有匹配的订单' : '本月暂无可查看的项目订单'; ?></td></tr><?php endif; ?>
  </tbody></table></div></div>
<?php if ($isFinance): ?></form><?php endif; ?>
</div>
<script>
(function () {
  var all = document.getElementById('bulkAll');
  if (!all) return;
  var items = document.querySelectorAll('.bulk-item'), count = document.getElementById('bulkCount');
  function refresh() { var n = document.querySelectorAll('.bulk-item:checked').length; count.textContent = '已选 ' + n + ' 单'; document.querySelectorAll('.project-bulk-bar button').forEach(function (b) { b.disabled = n === 0; }); }
  all.addEventListener('change', function () { items.forEach(function (i) { i.checked = all.checked; }); refresh(); });
  items.forEach(function (i) { i.addEventListener('change', refresh); });
  refresh();
})();
</script>
<script>
(function () {
  var toggle = document.getElementById('manualOrderToggle');
  var panel = document.getElementById('manual-order');
  if (toggle && panel) toggle.addEventListener('click', function () {
    var opening = panel.classList.contains('d-none');
    panel.classList.toggle('d-none', !opening);
    toggle.setAttribute('aria-expanded', opening ? 'true' : 'false');
    toggle.querySelector('span').textContent = opening ? '收起手动录入' : '手动录入订单';
    if (opening) { panel.scrollIntoView({behavior:'smooth',block:'start'}); document.getElementById('intakeOrderNo').focus(); }
  });
  var business = document.getElementById('intakeBusiness');
  var resourceFields = document.getElementById('intakeResourceFields');
  if (!business) return;
  var canEditResources = <?php echo $actor['role'] === 'customer_service' ? 'false' : 'true'; ?>;
  var catalog = <?php echo json_encode(array_map(function ($d) { return ['resources' => !empty($d['resources']), 'program' => !empty($d['program']), 'kinds' => $d['order_kinds'] ?? [], 'fee' => (float)($d['service_fee_rate'] ?? 0), 'defaultKind' => $d['default_kind'] ?? '', 'costLabel' => !empty($d['import_cost']) ? ($d['cost_label'] ?? '成本') : '']; }, $businessCatalog), JSON_UNESCAPED_UNICODE); ?>;
  var peopleLabels = <?php echo json_encode(array_reduce(array_keys($businessCatalog), function ($result, $name) { $result[$name] = ps_business_people_labels($name); return $result; }, []), JSON_UNESCAPED_UNICODE); ?>;
  var selectedKind = <?php echo json_encode((string)($_POST['order_kind'] ?? ''), JSON_UNESCAPED_UNICODE); ?>;
  var mode = document.getElementById('intakeDomainMode');
  var domainWrap = document.getElementById('intakeDomainTemplateWrap');
  var domain = document.getElementById('intakeDomainTemplate');
  var server = document.getElementById('intakeServerTemplate');
  var program = document.getElementById('intakeProgram');
  var kind = document.getElementById('intakeKind');
  var price = document.getElementById('intakePrice');
  function optionPrice(select) { var option = select.options[select.selectedIndex]; return Number(option && option.dataset.price || 0); }
  function money(n) { return '¥' + n.toFixed(2); }
  function refreshKinds() {
    var kinds = catalog[business.value].kinds;
    kind.innerHTML = '<option value="">' + (kinds.length ? '请选择' : '—') + '</option>';
    var want = selectedKind || catalog[business.value].defaultKind;
    kinds.forEach(function (k) { var o = document.createElement('option'); o.value = k; o.textContent = k; if (k === want) o.selected = true; kind.appendChild(o); });
    document.getElementById('intakeKindWrap').hidden = !kinds.length;
  }
  function update() {
    var info = catalog[business.value];
    var resources = info.resources;
    document.getElementById('intakeFrontendLabel').textContent = peopleLabels[business.value].frontend;
    document.getElementById('intakeBackendLabel').textContent = peopleLabels[business.value].backend;
    document.getElementById('intakeCustomerServiceLabel').textContent = business.value === '网站模板' || business.value === 'AI网站定制' ? '网站客服' : '客服';
    document.getElementById('intakeFooterHint').textContent = '合作人员提交的售价仅作订单申报，实收仍由财务审核。' + (resources ? ' SSL 非标准成本须补凭证后审核。' : ' 如有特殊成本，可在结算单中补录凭证。');
    resourceFields.hidden = !resources || !canEditResources;
    resourceFields.querySelectorAll('input,select').forEach(function (input) { input.disabled = !resources || !canEditResources; });
    document.getElementById('intakeProgramRow').hidden = !info.program;
    program.disabled = !info.program || !canEditResources;
    document.querySelectorAll('.project-business-fields').forEach(function (section) {
      var active = section.dataset.business === business.value;
      section.hidden = !active;
      section.querySelectorAll('input').forEach(function (input) { input.disabled = !active; });
    });
    var usesProgram = info.program && Number(program.value) > 0;
    var usesDomain = resources && mode.value === 'template';
    domainWrap.hidden = !usesDomain;
    mode.required = resources && canEditResources && !usesProgram;
    domain.required = usesDomain && canEditResources;
    var sale = Number(price.value || 0);
    var outsource = document.getElementById('intakeOutsource');
    var outsourceCost = 0;
    if (outsource) {
      Array.prototype.forEach.call(outsource.options, function (o) { if (o.value !== '0') o.hidden = o.dataset.scope !== '' && o.dataset.scope !== business.value; });
      var picked = outsource.options[outsource.selectedIndex];
      if (picked && picked.hidden) outsource.value = '0';
      document.getElementById('intakeOutsourceRow').hidden = !Array.prototype.some.call(outsource.options, function (o) { return o.value !== '0' && !o.hidden; });
      picked = outsource.options[outsource.selectedIndex];
      if (outsource.value !== '0') outsourceCost = picked.dataset.mode === 'percent' ? Math.round(sale * Number(picked.dataset.price)) / 100 : Number(picked.dataset.price);
    }
    var directCost = document.getElementById('intakeDirectCost');
    document.getElementById('intakeDirectCostWrap').hidden = !info.costLabel;
    directCost.disabled = !info.costLabel;
    document.getElementById('intakeDirectCostLabel').textContent = info.costLabel || '成本';
    // 退款冲减：售价与稿费填负数
    var offset = kind.value === '退款冲减';
    price.min = offset ? '' : '0'; directCost.min = offset ? '' : '0';
    var cost = (info.costLabel ? Number(directCost.value || 0) : 0) + (resources && canEditResources ? (usesDomain ? optionPrice(domain) : 0) + optionPrice(server) + (usesProgram ? optionPrice(program) : 0) : 0) + outsourceCost;
    var fee = Math.round(sale * info.fee * 100) / 100;
    document.getElementById('intakeCostTotal').textContent = money(cost);
    document.getElementById('intakeFeeRate').textContent = (info.fee * 100).toFixed(1).replace(/\.0$/, '') + '%';
    document.getElementById('intakeFee').textContent = money(fee);
    document.getElementById('intakeProfit').textContent = price.value === '' ? '填售价后显示' : money(sale - fee - cost);
    document.getElementById('intakeCostHint').textContent = info.costLabel ? info.costLabel + '随订单入账（¥500 以内自动通过）' : !resources || !canEditResources ? '成本由技术在结算单确认' : (usesProgram ? '程序套餐已含空间与域名，保存时按成本中心现价入账' : (mode.value === 'pending' ? '域名待技术确认，暂不计成本' : (usesDomain && !domain.value ? '选择域名规格后显示标准价' : '最终以保存时成本模板单价为准')));
  }
  business.addEventListener('change', function () { selectedKind = ''; refreshKinds(); update(); });
  [mode, domain, server, program, document.getElementById('intakeOutsource')].forEach(function (el) { if (el) el.addEventListener('change', update); });
  price.addEventListener('input', update);
  document.getElementById('intakeDirectCost').addEventListener('input', update);
  kind.addEventListener('change', update);
  refreshKinds();
  update();

  // 订单号即时查询：已建档 → 提示打开原单；店铺 / ETMLL 流水 → 只填空白字段。
  var orderNo = document.getElementById('intakeOrderNo');
  var box = document.getElementById('intakeLookup');
  var timer = null, lastQuery = '';
  function setIfEmpty(el, value) { if (el && value !== null && value !== undefined && value !== '' && el.value === '') { el.value = value; el.classList.add('project-autofilled'); } }
  function escapeHtml(s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function lookup() {
    var value = orderNo.value.trim();
    if (value === lastQuery) return;
    lastQuery = value;
    if (value.length < 4) { box.hidden = true; return; }
    fetch('<?php echo BASE_URL; ?>/project/lookup.php?order_no=' + encodeURIComponent(value), {credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (data) {
      if (!data.ok || orderNo.value.trim() !== value) return;
      var html = '';
      if (data.project) {
        html = data.project.id ? '<i class="fas fa-folder-open mr-1"></i> 此订单号已建档（' + escapeHtml(data.project.business) + '）。<a class="font-weight-bold" href="' + escapeHtml(data.project.url) + '">打开原结算单继续补充</a>，无需重复录入。' : '<i class="fas fa-lock mr-1"></i> 此订单号已由同事建档。请让对方在结算单中关联你，或联系财务。';
        box.className = 'project-lookup is-existing';
      } else if (data.shop_orders.length === 1) {
        var m = data.shop_orders[0];
        var shop = document.getElementById('intakeShop');
        if (shop.value === '' && Array.prototype.some.call(shop.options, function (o) { return o.value === m.shop; })) { shop.value = m.shop; shop.classList.add('project-autofilled'); }
        setIfEmpty(document.getElementById('intakeNickname'), m.nickname);
        setIfEmpty(price, m.price);
        setIfEmpty(document.getElementById('intakeTrade'), m.status);
        if (m.date) { var d = document.getElementById('intakeDate'); if (d.value === '<?php echo date('Y-m-d'); ?>') { d.value = m.date; d.classList.add('project-autofilled'); } }
        html = '<i class="fas fa-magic mr-1"></i> 已从' + escapeHtml(m.source) + '流水带出：' + escapeHtml(m.shop) + (m.price !== null ? ' · 售价 ¥' + Number(m.price).toFixed(2) : '') + (m.status ? ' · ' + escapeHtml(m.status) : '') + '。只填了空白栏，可自行修改。' + (m.refund ? '<div class="text-danger mt-1"><i class="fas fa-exclamation-triangle mr-1"></i>店铺流水显示此单有退款 / 交易关闭' + (m.refund_amount ? '（¥' + Number(m.refund_amount).toFixed(2) + '）' : '') + '，请核对后再录入。</div>' : '');
        box.className = 'project-lookup is-found';
        update();
      } else if (data.shop_orders.length > 1) {
        html = '<i class="fas fa-random mr-1"></i> 店铺流水中有 ' + data.shop_orders.length + ' 个店铺使用同一订单号（' + data.shop_orders.map(function (m) { return escapeHtml(m.shop); }).join('、') + '），请手动选择店铺，系统不自动猜测。';
        box.className = 'project-lookup is-warning';
      } else {
        html = '<i class="fas fa-clock mr-1"></i> 店铺订单里暂未找到此单号。可以先保存，之后上传店铺订单或 ETMLL 同步时会自动补齐售价等空白信息。';
        box.className = 'project-lookup';
      }
      box.innerHTML = html;
      box.hidden = false;
    }).catch(function () {});
  }
  orderNo.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(lookup, 450); });
  orderNo.addEventListener('blur', lookup);
  if (orderNo.value) lookup();
})();
</script>
<?php if (ps_ai_ready()): ?>
<script>
(function () {
  var btn = document.getElementById('aiParseBtn');
  if (!btn) return;
  var status = document.getElementById('aiStatus');
  function fill(el, value) { if (el && value && !el.disabled && (el.value === '' || el.value === '0')) { el.value = value; el.classList.add('project-autofilled'); el.dispatchEvent(new Event('change')); return 1; } return 0; }
  function field(name) { return document.querySelector('#projectManualForm [name="' + name + '"]'); }
  btn.addEventListener('click', function () {
    var text = document.getElementById('aiPaste').value.trim();
    if (!text) { status.textContent = '请先粘贴内容'; return; }
    btn.disabled = true; status.textContent = 'AI 正在识别，大约需要几秒…';
    var body = new URLSearchParams({csrf: field('csrf').value, text: text});
    fetch('<?php echo BASE_URL; ?>/project/ai.php', {method: 'POST', credentials: 'same-origin', body: body}).then(function (r) { return r.json(); }).then(function (data) {
      btn.disabled = false;
      if (!data.ok) { status.textContent = data.error || '识别失败'; return; }
      var f = data.fields, n = 0;
      var business = document.getElementById('intakeBusiness');
      if (f.business && business.value !== f.business && Array.prototype.some.call(business.options, function (o) { return o.value === f.business; })) { business.value = f.business; business.dispatchEvent(new Event('change')); n++; }
      n += fill(document.getElementById('intakeOrderNo'), f.order_no);
      n += fill(document.getElementById('intakeKind'), f.order_kind);
      var date = document.getElementById('intakeDate');
      if (f.order_date && date.value === '<?php echo date('Y-m-d'); ?>' && f.order_date !== date.value) { date.value = f.order_date; date.classList.add('project-autofilled'); n++; }
      n += fill(document.getElementById('intakeShop'), f.shop);
      n += fill(document.getElementById('intakeNickname'), f.payment_nickname);
      n += fill(document.getElementById('intakePrice'), f.contract_amount);
      n += fill(document.getElementById('intakeTrade'), f.trade_status);
      n += fill(field('customer_name'), f.customer_name);
      n += fill(field('contact_note'), f.contact_note);
      n += fill(field('details[make_requirement]'), f.requirement);
      var program = document.getElementById('intakeProgram');
      if (program && !program.disabled && f.program_name && program.value === '0') {
        var target = f.program_name.replace(/\s+/g, ''), years = (target.match(/^(\d+)年/) || [0, '1'])[1], name = target.replace(/^\d+年/, '');
        var match = Array.prototype.filter.call(program.options, function (o) { var t = o.textContent.replace(/\s+/g, ''); return t.indexOf(name + '·' + years + '年') === 0 && t.indexOf('空间+域名') !== -1; });
        if (match.length === 1) { program.value = match[0].value; program.classList.add('project-autofilled'); program.dispatchEvent(new Event('change')); n++; }
      }
      var orderNo = document.getElementById('intakeOrderNo');
      orderNo.dispatchEvent(new Event('blur'));
      status.textContent = n ? '已填写 ' + n + ' 项（绿色高亮），请核对后保存。' : '没有识别到可填写的新信息，已有内容未改动。';
    }).catch(function () { btn.disabled = false; status.textContent = 'AI 服务暂时连不上，请稍后再试'; });
  });
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
