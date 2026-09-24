<?php
// PHPweb 程序成本区间表：客服 / 资料员按售价档位计 PHP 成本，技术售价 ≥400 再 +100；订单毛利仍按实际成本。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectPresets.php';

function check_php($actual, $expected, $label)
{
    if (abs((float)$actual - (float)$expected) > 0.001) throw new RuntimeException($label . ': expected ' . $expected . ', got ' . $actual);
}

$pdo = db();
$pdo->beginTransaction();
try {
    $actor = ['type' => 'admin', 'id' => (int)$pdo->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetchColumn(), 'employee_id' => null, 'role' => 'finance'];
    $pdo->exec('UPDATE project_commission_rules SET is_active=0');
    $pdo->exec("DELETE FROM project_settings WHERE setting_key='php_cost_bands'");
    ps_setting_get('php_cost_bands', null, true);
    ps_apply_preset_rules($actor, '2026-01-01');

    // 区间表逐档核对
    foreach ([[100, 170], [149, 170], [150, 100], [268, 100], [269, 150], [399, 150], [400, 200], [599, 250], [799, 300], [1199, 400], [1599, 500], [1999, 600], [2399, 700]] as [$price, $cost]) {
        check_php(ps_php_cost_for($price, 'customer_service', '客服', 170)[0], $cost, "客服 售价 $price");
        check_php(ps_php_cost_for($price, 'technical', '资料员', 170)[0], $cost, "资料员 售价 $price");
        check_php(ps_php_cost_for($price, 'technical', '模板技术', 170)[0], $cost + ($price >= 400 ? 100 : 0), "技术 售价 $price");
    }

    $order = ['project_type' => '网站模板', 'order_kind' => '新订单', 'contract_amount' => 500, 'receipt_amount' => 500, 'refund_amount' => 0, 'order_date' => '2026-09-15'];
    $costs = [['category' => 'program', 'item_name' => 'PHP · 1年 空间+域名', 'amount' => 170, 'review_status' => 'approved']];
    $people = [
        ['employee_id' => 1, 'commission_group' => 'technical', 'role_name' => '资料员', 'group_weight' => 1],
        ['employee_id' => 2, 'commission_group' => 'customer_service', 'role_name' => '客服', 'group_weight' => 1],
    ];
    $sum = ps_summary($order, $costs, $people);
    // 资料员：(500 − 250 − 15) × 10% = 23.50；客服：(500 − 250 − 15) × 8% = 18.80；订单毛利按实际成本 500 − 170 − 15 = 315
    check_php($sum['groups']['technical']['people'][0]['calc']['share'], 23.5, '资料员提成');
    check_php($sum['groups']['customer_service']['people'][0]['calc']['share'], 18.8, '客服提成');
    check_php($sum['profit'], 315, '订单毛利按实际成本');
    if (mb_strpos($sum['groups']['technical']['people'][0]['calc']['note'], 'PHP 成本按区间表 250') === false) throw new RuntimeException('算式应注明 PHP 区间成本');

    // 模板技术：(500 − 350 − 15) × 13% = 17.55
    $people[0]['role_name'] = '模板技术';
    check_php(ps_summary($order, $costs, $people)['groups']['technical']['people'][0]['calc']['share'], 17.55, '模板技术 +100');

    // 非 PHP 程序不受影响：优站 230 → (500 − 230 − 15) × 13% = 33.15
    $costs[0]['item_name'] = '优站 · 1年 空间+域名'; $costs[0]['amount'] = 230;
    check_php(ps_summary($order, $costs, $people)['groups']['technical']['people'][0]['calc']['share'], 33.15, '非 PHP 程序');

    // 规则中心改档立即生效
    ps_setting_set('php_cost_bands', ['enabled' => true, 'bands' => [['from' => 150, 'cost' => 120]], 'tech_from' => 400, 'tech_extra' => 100, 'exclude_roles' => ['资料员']], $actor['id']);
    check_php(ps_php_cost_for(500, 'technical', '资料员', 170)[0], 120, '改档即时生效');

    $pdo->rollBack();
    ps_setting_get('php_cost_bands', null, true);
    echo "PHPweb 成本区间（客服 / 资料员 / 技术 +100）与资料员 10% 提成核对通过；数据已回滚\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
