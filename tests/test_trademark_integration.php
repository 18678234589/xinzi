<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ProjectBusiness.php';
require_once __DIR__ . '/../includes/ProjectSettlement.php';
require_once __DIR__ . '/../includes/ProjectPresets.php';
require_once __DIR__ . '/../includes/SalaryCalculator.php';
require_once __DIR__ . '/../classes/SimpleXLSX.php';

echo "=== 1. Testing Staff Accounts & Credentials ===\n";
$pdo = db();
$users = [
    9 => ['qintingting', '秦婷婷', 'customer_service', '客服'],
    10 => ['sunrongzi', '孙荣姿', 'customer_service', '客服'],
    11 => ['yuna', '于娜', 'customer_service', '客服'],
    12 => ['wanghuizi', '王慧资', 'technical', '提交专员'],
    13 => ['wangqingmei', '王庆美', 'technical', '资料专员'],
];

foreach ($users as $empId => [$pinyin, $name, $role, $title]) {
    // Check employees table
    $empStmt = $pdo->prepare('SELECT id, name, department, password FROM employees WHERE id=?');
    $empStmt->execute([$empId]);
    $emp = $empStmt->fetch();
    assert($emp !== false, "Employee $name not found");
    assert($emp['password'] === md5($pinyin), "Employee $name password mismatch in employees table");

    // Check project_users table
    $puStmt = $pdo->prepare('SELECT * FROM project_users WHERE employee_id=?');
    $puStmt->execute([$empId]);
    $pu = $puStmt->fetch();
    assert($pu !== false, "Project user for $name not found");
    assert($pu['username'] === $pinyin, "Project user username mismatch: {$pu['username']} vs $pinyin");
    assert(password_verify($pinyin, $pu['password_hash']), "Project user password_verify failed for $pinyin");
    assert($pu['role'] === $role, "Project user role mismatch: {$pu['role']} vs $role");
    assert((int)$pu['is_active'] === 1, "Project user is_active should be 1");

    // Check businesses
    $actor = ['type' => 'employee', 'id' => (int)$pu['id'], 'employee_id' => $empId, 'role' => $role];
    $businesses = ps_actor_businesses($actor);
    assert(in_array('商标', $businesses, true), "$name should have access to '商标'");

    echo "  [OK] $name ($pinyin): credentials valid, project access confirmed\n";
}

echo "\n=== 2. Testing Business Catalog & Preset Rules for 商标 ===\n";
$catalog = ps_business_catalog();
assert(isset($catalog['商标']), "Catalog '商标' should exist");
assert(in_array('普通订单', $catalog['商标']['order_kinds'], true), "Order kind '普通订单' should exist");
assert(in_array('新客户', $catalog['商标']['order_kinds'], true), "Order kind '新客户' should exist");
assert(in_array('小额返款', $catalog['商标']['order_kinds'], true), "Order kind '小额返款' should exist");
assert(!empty($catalog['商标']['import_cost']), "import_cost should be true");
assert($catalog['商标']['service_fee_rate'] == 0.01, "service_fee_rate should be 0.01");

$rules = $pdo->query("SELECT * FROM project_commission_rules WHERE project_type='商标' AND is_active=1")->fetchAll();
assert(count($rules) >= 4, "Should have active rules for 商标");
echo "  [OK] Found " . count($rules) . " active project commission rules for '商标'\n";

echo "\n=== 3. Testing Import Column Mapping for Trademark Files ===\n";
$filePath = __DIR__ . '/../订单模板与成本及算法/8月商标工资/8月商标总.xlsx';
assert(file_exists($filePath), "File 8月商标总.xlsx must exist");

$sheets = SimpleXLSX::sheetNames($filePath);
assert(in_array('客服', $sheets, true), "Sheet '客服' must exist");
assert(in_array('客服小额', $sheets, true), "Sheet '客服小额' must exist");

$all = SimpleXLSX::parseAll($filePath);
$csHead = $all['客服'][0];
$csMap = ps_business_import_map('商标', $csHead, true);
assert(isset($csMap['order_no']), "Must map order_no in 客服");
assert(isset($csMap['contract_amount']), "Must map contract_amount in 客服");
assert(isset($csMap['direct_cost']), "Must map direct_cost in 客服");
assert(isset($csMap['customer_service']), "Must map customer_service in 客服");
assert(isset($csMap['detail:trademark_name']), "Must map detail:trademark_name in 客服");
assert(isset($csMap['detail:trademark_count']), "Must map detail:trademark_count in 客服");
echo "  [OK] Sheet '客服' column mapping succeeded\n";

$refundHead = $all['客服小额'][0];
$refundMap = ps_business_import_map('商标', $refundHead, true);
assert(isset($refundMap['order_no']), "Must map order_no in 客服小额");
assert(isset($refundMap['contract_amount']), "Must map contract_amount in 客服小额");
assert(isset($refundMap['customer_service']), "Must map customer_service in 客服小额");
echo "  [OK] Sheet '客服小额' column mapping succeeded\n";

echo "\n=== 4. Testing Project Commission Calculation for 商标 ===\n";
// Simulated calculation:
// Customer Service - Normal order (330 price, 270 cost, 1% fee = 3.3, profit = 56.7, 12% = 6.804 + 3 = 9.804)
$csNormalRule = ps_rule_for('customer_service', '商标', '2026-08-15', '客服', '普通订单');
assert($csNormalRule !== null, "csNormalRule must exist");
$calcCsNormal = ps_calc_person($csNormalRule, 330, 270, 330, 1.0, 0.01);
assert(abs($calcCsNormal['share'] - 6.804) < 0.001, "CS share mismatch: {$calcCsNormal['share']}");
assert(abs($calcCsNormal['subsidy'] - 3.0) < 0.001, "CS subsidy mismatch: {$calcCsNormal['subsidy']}");
echo "  [OK] Customer service normal order: share=6.804, subsidy=3.0, total=9.804\n";

// Customer Service - New customer (330 price, 270 cost, 1% fee = 3.3, profit = 56.7, 12% = 6.804 + 6 = 12.804)
$csNewRule = ps_rule_for('customer_service', '商标', '2026-08-15', '客服', '新客户');
assert($csNewRule !== null, "csNewRule must exist");
$calcCsNew = ps_calc_person($csNewRule, 330, 270, 330, 1.0, 0.01);
assert(abs($calcCsNew['share'] - 6.804) < 0.001, "CS new share mismatch: {$calcCsNew['share']}");
assert(abs($calcCsNew['subsidy'] - 6.0) < 0.001, "CS new subsidy mismatch: {$calcCsNew['subsidy']}");
echo "  [OK] Customer service new customer order: share=6.804, subsidy=6.0, total=12.804\n";

// Customer Service - Small refund order (10 price, 0 cost, 0% fee, 0 profit commission, subsidy 3.0)
$csRefundRule = ps_rule_for('customer_service', '商标', '2026-08-15', '客服', '小额返款');
assert($csRefundRule !== null, "csRefundRule must exist");
$calcCsRefund = ps_calc_person($csRefundRule, 10, 0, 10, 1.0, 0.01);
assert(abs($calcCsRefund['share'] - 0.0) < 0.001, "CS refund share mismatch: {$calcCsRefund['share']}");
assert(abs($calcCsRefund['subsidy'] - 3.0) < 0.001, "CS refund subsidy mismatch: {$calcCsRefund['subsidy']}");
echo "  [OK] Customer service small refund order: share=0, subsidy=3.0, total=3.0\n";

// Technical - Submission specialist (2.2 per item)
$techRule = ps_rule_for('technical', '商标', '2026-08-15', '提交专员', '普通订单');
assert($techRule !== null, "techRule must exist");
$calcTech = ps_calc_person($techRule, 330, 0, 330, 1.0, 0.01);
assert(abs($calcTech['subsidy'] - 2.2) < 0.001, "Tech subsidy mismatch: {$calcTech['subsidy']}");
echo "  [OK] Technical specialist subsidy: 2.2 per item\n";

echo "\n=== 5. Testing SalaryCalculator per_order sum mode ===\n";
// Test sum mode for 王庆美 (695 items) and 王慧资 (704 items)
$dummyOrders = [
    ['order_no' => 'O1', 'order_amount' => 100, 'raw_data' => json_encode(['商标个数' => '1'])],
    ['order_no' => 'O2', 'order_amount' => 200, 'raw_data' => json_encode(['商标个数' => '2'])],
    ['order_no' => 'O3', 'order_amount' => 100, 'raw_data' => json_encode(['商标个数' => 3])],
];
$cfg = ['count_column' => '商标个数', 'count_distinct' => '求和', 'per_amount' => 2.2, 'per_reward' => 0];
$context = ['orders' => $dummyOrders];
$refMethod = new ReflectionMethod('SalaryCalculator', 'calcPerOrder');
$refMethod->setAccessible(true);
$sumRes = $refMethod->invoke(null, $cfg, $context);
assert($sumRes['amount'] == 13.2, "Sum amount should be (1+2+3)*2.2 = 13.2, got {$sumRes['amount']}");
echo "  [OK] SalaryCalculator per_order sum mode correctly sums: 6 items * 2.2 = 13.20\n";

echo "\nALL TESTS PASSED SUCCESSFULLY!\n";
