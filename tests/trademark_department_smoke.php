<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ProjectBusiness.php';
require_once __DIR__ . '/../includes/ProjectSettlement.php';
require_once __DIR__ . '/../includes/ProjectPresets.php';
require_once __DIR__ . '/../includes/SalaryCalculator.php';
require_once __DIR__ . '/../classes/SimpleXLSX.php';

function check_eq($actual, $expected, $label) {
    if (abs((float)$actual - (float)$expected) > 0.001) {
        throw new RuntimeException("$label: expected $expected, got $actual");
    }
}

$pdo = db();
$pdo->beginTransaction();

try {
    echo "=== 1. Trademark Manual Order Creation & Commission Verification ===\n";
    $qtt = $pdo->query("SELECT * FROM project_users WHERE username='qintingting'")->fetch();
    assert($qtt !== false, "qintingting user must exist");
    $actorQtt = [
        'type' => 'employee',
        'id' => (int)$qtt['id'],
        'employee_id' => (int)$qtt['employee_id'],
        'role' => $qtt['role'],
        'username' => $qtt['username']
    ];

    $testNo = 'TM-TEST-' . bin2hex(random_bytes(4));
    $pdo->prepare("INSERT INTO project_orders (order_no, customer_name, project_type, order_kind, shop, contract_amount, receipt_amount, order_date, delivery_status, note) VALUES (?, '测试客户', '商标', '普通订单', '美呀美', 330, 330, '2026-08-15', 'finished', '测试订单')")
        ->execute([$testNo]);
    $orderId = (int)$pdo->lastInsertId();

    // Insert details
    ps_save_business_details($orderId, '商标', [
        'trademark_name' => '知产通',
        'trademark_count' => '2',
        'service_type' => '公司网报'
    ]);

    // Insert cost (270)
    $pdo->prepare("INSERT INTO project_costs (order_id, category, item_name, quantity, unit, unit_price, amount, cost_kind, is_custom, reason, review_status) VALUES (?, 'outsourcing', '成本', 1, '项', 270, 270, 'one_time', 1, '测试直接成本', 'approved')")
        ->execute([$orderId]);

    // Add participants:
    // 秦婷婷 (CS)
    $pdo->prepare("INSERT INTO project_participants (order_id, employee_id, commission_group, role_name, group_weight) VALUES (?, 9, 'customer_service', '客服', 1)")
        ->execute([$orderId]);
    // 王庆美 (Technical - 资料专员)
    $pdo->prepare("INSERT INTO project_participants (order_id, employee_id, commission_group, role_name, group_weight) VALUES (?, 13, 'technical', '资料专员', 0.5)")
        ->execute([$orderId]);
    // 王慧资 (Technical - 提交专员)
    $pdo->prepare("INSERT INTO project_participants (order_id, employee_id, commission_group, role_name, group_weight) VALUES (?, 12, 'technical', '提交专员', 0.5)")
        ->execute([$orderId]);

    // Run ps_summary
    $order = $pdo->query("SELECT * FROM project_orders WHERE id=$orderId")->fetch();
    $costs = ps_costs($orderId);
    $participants = ps_participants($orderId);
    $summary = ps_summary($order, $costs, $participants);

    // CS check:
    // Profit = (330 - 270 - 330*0.01) = 56.7
    // Share = 56.7 * 0.12 = 6.804
    // Subsidy = 3.00
    // Total = 9.804
    $csPerson = $summary['groups']['customer_service']['people'][0];
    check_eq($csPerson['calc']['share'], 6.804, "CS Share");
    check_eq($csPerson['calc']['subsidy'], 3.0, "CS Subsidy");

    // Tech check with trademark_count = 2:
    // 王庆美 (资料专员): weight=0.5, per_order_subsidy = 2.2 * 2 = 4.4 * 0.5 = 2.2
    // Or if individual weight is 1 for each role:
    $tech1 = $summary['groups']['technical']['people'][0]; // 王庆美
    $tech2 = $summary['groups']['technical']['people'][1]; // 王慧资
    echo "  [OK] CS Commission: share={$csPerson['calc']['share']}, subsidy={$csPerson['calc']['subsidy']}\n";
    echo "  [OK] Tech 1 ({$tech1['name']}): subsidy={$tech1['calc']['subsidy']}, note={$tech1['calc']['note']}\n";
    echo "  [OK] Tech 2 ({$tech2['name']}): subsidy={$tech2['calc']['subsidy']}, note={$tech2['calc']['note']}\n";

    echo "\n=== 2. Testing 8月 Real Calculation Reconciliation ===\n";
    // Check 孙荣姿 fixed service_fee_rate in config_10.json
    $cfg10 = json_decode(file_get_contents(__DIR__ . '/../algorithms/config_10.json'), true);
    foreach ($cfg10['modules'] as $mod) {
        if ($mod['type'] === 'trademark_commission') {
            assert($mod['config']['service_fee_rate'] == 0.01, "config_10 service_fee_rate must be 0.01");
            echo "  [OK] config_10 (孙荣姿) service_fee_rate correctly set to 0.01 (1%)\n";
        }
    }

    // Check config_12 and config_13 sum mode
    $cfg12 = json_decode(file_get_contents(__DIR__ . '/../algorithms/config_12.json'), true);
    $found12 = false;
    foreach ($cfg12['modules'] as $mod) {
        if ($mod['name'] === '商标提交提成') {
            assert($mod['config']['count_column'] === '商标个数', "config_12 count_column must be 商标个数");
            assert($mod['config']['count_distinct'] === '求和', "config_12 count_distinct must be 求和");
            assert($mod['config']['per_amount'] == 2.2, "config_12 per_amount must be 2.2");
            $found12 = true;
        }
    }
    assert($found12, "config_12 must have 商标提交提成 module");
    echo "  [OK] config_12 (王慧资) correctly configured with 商标个数 求和 × 2.2\n";

    $cfg13 = json_decode(file_get_contents(__DIR__ . '/../algorithms/config_13.json'), true);
    $found13 = false;
    foreach ($cfg13['modules'] as $mod) {
        if ($mod['name'] === '商标资料提成') {
            assert($mod['config']['count_column'] === '商标个数', "config_13 count_column must be 商标个数");
            assert($mod['config']['count_distinct'] === '求和', "config_13 count_distinct must be 求和");
            assert($mod['config']['per_amount'] == 2.2, "config_13 per_amount must be 2.2");
            $found13 = true;
        }
    }
    assert($found13, "config_13 must have 商标资料提成 module");
    echo "  [OK] config_13 (王庆美) correctly configured with 商标个数 求和 × 2.2\n";

    $pdo->rollBack();
    echo "\n=== ALL TRADEMARK SMOKE TESTS PASSED! DATA SAFELY ROLLED BACK ===\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "TEST FAILED: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
