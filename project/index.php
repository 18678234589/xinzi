<?php
require_once __DIR__ . '/../includes/ProjectIntake.php';
poi_ensure();
require_once __DIR__ . '/../includes/ProjectOrderSplit.php';
pos_ensure();
require_once __DIR__ . '/../includes/ProjectImportResult.php';
require_once __DIR__ . '/../includes/ProjectBusiness.php';
require_once __DIR__ . '/../includes/ProjectOrderSource.php';
require_once __DIR__ . '/../includes/ProjectDepartmentImport.php';
require_once __DIR__ . '/../includes/ProjectVault.php';
require_once __DIR__ . '/../includes/commission_explain.php';
require_once __DIR__ . '/../includes/ProjectPartnerDashboard.php';
require_once __DIR__ . '/../includes/ProjectAutoReview.php';
$actor = ps_require_actor();
$participationOnly = ($_GET['participating'] ?? '') === '1';
$filterEmployeeId = ps_partner_list_employee_id($actor, $_GET['employee_id'] ?? 0);
$error = '';
$businessCatalog = ps_business_catalog();
$allowedBusinesses = ps_actor_businesses($actor);
$departmentImportBusiness = '';
foreach (['网站续费', '网站修改'] as $candidate) if (ps_department_import_allowed($actor, $candidate)) { $departmentImportBusiness = $candidate; break; }
// 只有固定报酬、不录订单的合作人员（如售后退款部）：直接进入“我的项目报酬”
if ($actor['role'] !== 'finance' && !$allowedBusinesses && !$participationOnly && $_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . BASE_URL . '/project/payroll.php');
    exit; }
$selectedBusiness = ps_business_choice($actor, (string)($_POST['project_type'] ?? $_GET['business'] ?? ''));
$roleDefaultKinds = [];
if ($actor['role'] !== 'finance') foreach ($allowedBusinesses as $businessName) {
    $roleHint = ps_employee_default_role((int)$actor['employee_id'], $businessName, $actor['role']);
    $roleDefaultKinds[$businessName] = ps_order_kind_from_role($businessName, $roleHint ?? '');
}
$shops = db()->query('SELECT name FROM shops ORDER BY sort,id')->fetchAll(PDO::FETCH_COLUMN);
$dateBasis = ($_GET['date_basis'] ?? '') === 'created_at' ? 'created_at' : 'order_date';
$month = (string)($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = date('Y-m');
// 未明确选月份时：本月还没有订单（如月初刚上传完上月表格），自动切到本人最近有订单的月份，避免“导入了却看不到”。
if (!isset($_GET['month']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    try {
        $monthScope = $actor['role'] === 'finance' ? '' : ' AND (EXISTS (SELECT 1 FROM project_participants mp WHERE mp.order_id=o.id AND mp.employee_id=' . (int)$actor['employee_id'
    ] . ') OR EXISTS (SELECT 1 FROM project_department_uploaders du WHERE du.order_id=o.id AND du.employee_id=' . (int)$actor['employee_id'] . '))';
        if (ps_is_management($actor)) $monthScope = ' AND (' . ps_management_order_condition($actor) . ')';
        $hasMonth = (int)db()->query("SELECT COUNT(*) FROM project_orders o WHERE o." . $dateBasis . ">='" . $month . "-01' AND o." . $dateBasis . "<'" . date('Y-m-d', strtotime($month
    . '-01 +1 month')) . "'" . $monthScope)->fetchColumn();
        if (!$hasMonth) {
            $latest = db()->query("SELECT DATE_FORMAT(MAX(o." . $dateBasis . "),'%Y-%m') FROM project_orders o WHERE o." . $dateBasis . "<'" . date('Y-m-d', strtotime($month . '-01 +1 month'
    )) . "'" . $monthScope)->fetchColumn();
            if ($latest) $month = $latest;
        }
    } catch (Throwable $e) {
        // 查询失败时保持本月
    }
}
$employees = db()->query('SELECT id,name,department FROM employees ORDER BY department,name,id')->fetchAll();
$employeesById = [];
foreach ($employees as $employee) $employeesById[(int)$employee['id']] = $employee;
$filterEmployeeName = $employeesById[$filterEmployeeId]['name'] ?? ('人员 #' . $filterEmployeeId);
$activeTechnicalIds = array_map('intval', db()->query("SELECT employee_id FROM project_users WHERE role='technical' AND is_active=1")->fetchAll(PDO::FETCH_COLUMN));
// 微信代写编辑员（客服账号）在代写订单上担任“对接编辑”，可在技术 / 对接栏选到
$activeTechnicalIds = array_values(array_unique(array_merge($activeTechnicalIds, array_map('intval', db()->query("SELECT u.employee_id FROM project_users u JOIN project_user_businesses b ON b.user_id=u.id AND b.business_name='微信代写' WHERE u.is_active=1"
    )->fetchAll(PDO::FETCH_COLUMN)))));
$activeCustomerServiceIds = array_map('intval', db()->query("SELECT employee_id FROM project_users WHERE role IN ('customer_service','management') AND is_active=1")->fetchAll(PDO::FETCH_COLUMN));
// 合作人员可在两栏都选到自己（身兼客服与技术的人员，如环境配置）。
$selfEmployeeId = (int)($actor['employee_id'] ?? 0);
$technicalChoices = $actor['role'] === 'finance' ? $employees : array_values(array_filter($employees, function ($emp) use ($activeTechnicalIds, $selfEmployeeId) { return in_array((int)
    $emp['id'], $activeTechnicalIds, true) || (int)$emp['id'] === $selfEmployeeId; }));
$customerServiceChoices = $actor['role'] === 'finance' ? $employees : array_values(array_filter($employees, function ($emp) use ($activeCustomerServiceIds, $selfEmployeeId) { return
    in_array((int)$emp['id'], $activeCustomerServiceIds, true) || (int)$emp['id'] === $selfEmployeeId; }));
$domainTemplates = ps_intake_templates('domain');
$serverTemplates = ps_intake_templates('server');
$programTemplates = ps_intake_templates('program');
$outsourceTemplates = ps_intake_templates('outsourcing');

// 删除传错订单的共用清理：先删无级联的子表，再删订单本体（已审核订单在调用前拦截，不可恢复）。
$deleteOrderRows = function ($orderId) {
    // 技术对账行挂在分成快照上，先删对账再删快照；order_requests / order_details 有级联，显式删除保持一致
    db()->prepare('DELETE t FROM project_technical_reconciliations t JOIN project_commission_snapshots s ON s.id=t.snapshot_id WHERE s.order_id=?')->execute([$orderId]);
    foreach (['project_order_items', 'project_commission_snapshots', 'project_commission_adjustments', 'project_cash_movements', 'project_costs', 'project_participants', 'project_order_sources'
    , 'project_order_resources', 'project_order_requests', 'project_order_details'] as $table) {
        db()->prepare('DELETE FROM ' . $table . ' WHERE order_id=?')->execute([$orderId]);
    }
    db()->prepare('DELETE FROM project_orders WHERE id=?')->execute([$orderId]);
};

// 删除权限：财务可删任意未审核单；网站售后部员工 + 指定放行名单（栾鑫）可删自己参与或代录的未审核单（即本人可见的订单）。
$isAfterSalesDept = false;
if ($actor['role'] !== 'finance' && !empty($actor['employee_id'])) {
    $deptQuery = db()->prepare('SELECT department FROM employees WHERE id=?');
    $deptQuery->execute([(int)$actor['employee_id']]);
    $isAfterSalesDept = $deptQuery->fetchColumn() === '网站售后部';
}
$deleteAllowEmployeeIds = [55 => '栾鑫']; // 个人放行：运营经理部，同样只能删本人参与/代录的未审核单
require_once __DIR__ . '/../includes/ProjectOrderDelete.php';
$canDeleteOrders = $actor['role'] === 'finance' || $isAfterSalesDept || (isset($deleteAllowEmployeeIds[(int)($actor['employee_id'] ?? 0)])) || in_array($actor['role'], ['customer_service', 'technical'], true); // 客服 / 技术可删本人参与、未审核的订单（规则见 pod_blocker）
$canSelectOrders = $canDeleteOrders || in_array($actor['role'], ['customer_service', 'technical', 'finance'], true);

// 单个删除传错的订单：仅未审核（草稿 / 审核中）可删，连同实收流水、成本、参与人、分成快照一并清理。
/* split: project/index/actions/delete.php */ include __DIR__ . '/index/actions/delete.php';
/* split: project/index/actions/bulk.php */ include __DIR__ . '/index/actions/bulk.php';
/* split: project/index/actions/create.php */ include __DIR__ . '/index/actions/create.php';
/* split: includes/ProjectOrderList.php */ include __DIR__ . '/../includes/ProjectOrderList.php';
if (PHP_SAPI === 'cli' && !empty($GLOBALS['project_order_list_cli'])) return;
/* split: project/index/view.php */ include __DIR__ . '/index/view.php';
