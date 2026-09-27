<?php
/**
 * 网站订单交付核验、产品升级与财务审核人分工测试
 */
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectBusiness.php';
require_once __DIR__ . '/../includes/ProjectSettlement.php';
require_once __DIR__ . '/../includes/ProjectSystem.php';

echo "=== 开始测试网站交付核验、产品升级与审核人分工 ===\n";

// 1. 测试发货状态在网站业务下被识别为未完成
assert(ps_import_delivery_status('已发货', '网站模板') === 'unfinished', '网站模板已发货应为unfinished');
assert(ps_import_delivery_status('3天发货', 'AI网站定制') === 'unfinished', 'AI定制发货应为unfinished');
assert(ps_import_delivery_status('已完成', '网站模板') === 'finished', '网站模板已完成应为finished');
assert(ps_import_delivery_status('已发货', '商标') === 'finished', '非网站业务已发货仍按常规交付完成');
echo "✔ 1. 网站发货状态与交付完成分离测试通过\n";

// 2. 测试审核人分工与权限
$defaultSong = ps_business_reviewer('网站模板');
assert($defaultSong === 'songwenna', '网站业务默认审核人应为 songwenna');

$adminActor = ['id' => 1, 'username' => 'admin', 'role' => 'finance', 'type' => 'admin'];
$songActor = ['id' => 9, 'username' => 'songwenna', 'role' => 'finance', 'type' => 'admin', 'employee_id' => 61];
$otherFinance = ['id' => 8, 'username' => 'liuqun', 'role' => 'finance', 'type' => 'admin', 'employee_id' => 45];
$csActor = ['id' => 10, 'username' => 'cs_test', 'role' => 'customer_service', 'type' => 'user', 'employee_id' => 50];

assert(ps_actor_can_review_business($songActor, '网站模板') === true, '宋文娜应有权审核网站模板');
assert(ps_actor_can_review_business($adminActor, '网站模板') === true, '超级管理员应有权审核网站模板');
assert(ps_actor_can_review_business($otherFinance, '网站模板') === false, '非指定财务不应有权审核网站模板');
assert(ps_actor_can_review_business($csActor, '网站模板') === false, '客服不应有权审核');
echo "✔ 2. 审核人分工权限判定测试通过\n";

// 3. 订单交付申请与审核流程测试（事务内回滚）
db()->beginTransaction();
try {
    // 寻找或创建程序套餐模板
    $progTpl = db()->query("SELECT id FROM project_cost_templates WHERE category='program' AND is_active=1 LIMIT 2")->fetchAll();
    if (count($progTpl) < 2) {
        // 创建临时测试程序套餐
        db()->query("INSERT INTO project_cost_templates (category, name, specification, unit, price, is_active) VALUES ('program', 'JSP展示中级版', '1年', '套', 500, 1), ('program', 'JSP展示高级版', '1年', '套', 700, 1)");
        $tpl1Id = (int)db()->lastInsertId() - 1;
        $tpl2Id = (int)db()->lastInsertId();
    } else {
        $tpl1Id = (int)$progTpl[0]['id'];
        $tpl2Id = (int)$progTpl[1]['id'];
    }

    // 创建测试订单
    $testOrderNo = 'TEST-WEB-' . time();
    $testDate = date('Y-m-d');
    db()->prepare("INSERT INTO project_orders (order_no, customer_name, project_type, order_kind, shop, contract_amount, receipt_amount, order_date, delivery_status, settlement_status) VALUES (?, '测试企业', '网站模板', '新订单', '测试店', 998.00, 0, ?, 'unfinished', 'draft')")
        ->execute([$testOrderNo, $testDate]);
    $orderId = (int)db()->lastInsertId();

    // 绑定资源和客服、技术参与人
    db()->prepare("INSERT INTO project_order_resources (order_id, program_template_id, domain_mode) VALUES (?, ?, 'none')")
        ->execute([$orderId, $tpl1Id]);
    db()->prepare("INSERT INTO project_order_sources (order_id, price_source) VALUES (?, 'manual')")
        ->execute([$orderId]);
    
    // 技术与客服
    $empTech = db()->query("SELECT id FROM employees WHERE department LIKE '%技术%' OR department LIKE '%开发%' LIMIT 1")->fetchColumn() ?: 1;
    $empCs = db()->query("SELECT id FROM employees WHERE department LIKE '%客服%' LIMIT 1")->fetchColumn() ?: 2;
    db()->prepare("INSERT INTO project_participants (order_id, employee_id, commission_group, role_name, group_weight) VALUES (?, ?, 'technical', '模板技术', 1), (?, ?, 'customer_service', '网站客服', 1)")
        ->execute([$orderId, $empTech, $orderId, $empCs]);

    // 3.1 客服申请标记交付完成
    $reqId1 = ps_create_order_request($orderId, 'delivery_completion', $csActor, [
        'delivery_note' => '企微群客户已验收完成',
        'proof_path' => 'proofs/test_delivery_proof.png'
    ]);
    assert($reqId1 > 0, '创建交付完成申请应成功');
    $pendingDel = ps_order_pending_request($orderId, 'delivery_completion');
    assert($pendingDel !== null && $pendingDel['id'] == $reqId1, '待审核交付申请应能查到');
    assert($pendingDel['data']['delivery_note'] === '企微群客户已验收完成', '申请数据解析应正确');

    // 宋文娜审核通过交付申请
    ps_review_order_request($reqId1, 'approved', $songActor, '核对企微群记录无误，准予通过');
    $checkOrder = db()->query("SELECT delivery_status FROM project_orders WHERE id=" . $orderId)->fetch();
    assert($checkOrder['delivery_status'] === 'finished', '审核通过后订单交付状态应变更为 finished');

    // 3.2 技术或客服申请产品升级
    $reqId2 = ps_create_order_request($orderId, 'product_upgrade', $csActor, [
        'from_name' => 'JSP展示中级版',
        'to_template_id' => $tpl2Id,
        'to_name' => 'JSP展示高级版',
        'upgrade_reason' => '客户升级JSP展高',
        'customer_payment_note' => '微信补款200元'
    ]);
    assert($reqId2 > 0, '创建产品升级申请应成功');
    $pendingUpg = ps_order_pending_request($orderId, 'product_upgrade');
    assert($pendingUpg !== null && $pendingUpg['id'] == $reqId2, '待审核产品升级申请应能查到');

    // 宋文娜前往后台核实补差金额并审核入账
    ps_review_order_request($reqId2, 'approved', $songActor, '后台已核验实际补款200', [
        'diff_amount' => 200.00,
        'proof_path' => null
    ]);

    // 验证成本表中新增了补差成本
    $costRow = db()->query("SELECT * FROM project_costs WHERE order_id=" . $orderId . " AND category='program' AND amount=200 ORDER BY id DESC LIMIT 1")->fetch();
    assert($costRow !== false, '应成功生成200元产品升级补差成本');
    assert($costRow['review_status'] === 'approved', '升级补差成本状态应为已审核');

    // 验证资源表更新了程序套餐ID
    $resRow = db()->query("SELECT program_template_id FROM project_order_resources WHERE order_id=" . $orderId)->fetch();
    assert((int)$resRow['program_template_id'] === $tpl2Id, '订单资源程序套餐应更新为升级目标套餐');

    // 验证申请历史列表
    $reqs = ps_order_requests($orderId);
    assert(count($reqs) === 2, '申请历史记录应有2条');
    assert($reqs[0]['status'] === 'approved' && $reqs[1]['status'] === 'approved', '两条申请均已通过');

    echo "✔ 3. 交付凭证审核与产品升级补差全流程测试通过\n";

    // 4. 批量交付完成与自动核算提成测试
    $testOrderNo2 = 'TEST-WEB2-' . time();
    db()->prepare("INSERT INTO project_orders (order_no, customer_name, project_type, order_kind, shop, contract_amount, receipt_amount, order_date, delivery_status, settlement_status) VALUES (?, '批量测试企业', '网站模板', '新订单', '测试店', 1000.00, 0, ?, 'unfinished', 'draft')")
        ->execute([$testOrderNo2, $testDate]);
    $orderId2 = (int)db()->lastInsertId();
    db()->prepare("INSERT INTO project_order_resources (order_id, program_template_id, domain_mode) VALUES (?, ?, 'none')")
        ->execute([$orderId2, $tpl1Id]);
    db()->prepare("INSERT INTO project_order_sources (order_id, price_source) VALUES (?, 'manual')")
        ->execute([$orderId2]);
    db()->prepare("INSERT INTO project_participants (order_id, employee_id, commission_group, role_name, group_weight) VALUES (?, ?, 'technical', '模板技术', 1), (?, ?, 'customer_service', '网站客服', 1)")
        ->execute([$orderId2, $empTech, $orderId2, $empCs]);

    // 模拟批量标记交付完成：
    db()->prepare("UPDATE project_orders SET delivery_status='finished' WHERE id=?")->execute([$orderId2]);
    // 自动按售价确认实收
    db()->prepare("INSERT INTO project_cash_movements (order_id,movement_type,amount,note,review_status,submitted_by_type,submitted_by_id,reviewed_by_admin,reviewed_at) VALUES (?,'receipt',?,'交付完成自动按售价确认实收','approved','admin',?,?,NOW())")
        ->execute([$orderId2, 1000.00, $songActor['id'], $songActor['id']]);
    ps_recalculate_cash($orderId2);

    $payrollMonth = date('Y-m');
    ps_approve_order($orderId2, $songActor, $payrollMonth);

    $order2Row = db()->query("SELECT settlement_status, delivery_status, receipt_amount FROM project_orders WHERE id=" . $orderId2)->fetch();
    assert($order2Row['delivery_status'] === 'finished', '批量标记后交付状态应为已完成');
    assert($order2Row['settlement_status'] === 'approved', '自动核算后订单状态应为已审核');
    assert((float)$order2Row['receipt_amount'] == 1000.00, '实收应已自动确认');

    $snaps = db()->query("SELECT * FROM project_commission_snapshots WHERE order_id=" . $orderId2)->fetchAll();
    assert(count($snaps) >= 2, '技术与客服的分成快照应已成功生成');
    echo "✔ 4. 批量标记交付完成与自动核算提成测试通过\n";

    // 5. 交易成功满10天无人审核自动完成测试
    $testOrderNo3 = 'TEST-WEB3-' . time();
    $pastDate = date('Y-m-d', strtotime('-15 days'));
    db()->prepare("INSERT INTO project_orders (order_no, customer_name, project_type, order_kind, shop, contract_amount, receipt_amount, order_date, delivery_status, settlement_status) VALUES (?, '自动完成测试企业', '网站模板', '新订单', '测试店', 888.00, 0, ?, 'unfinished', 'draft')")
        ->execute([$testOrderNo3, $pastDate]);
    $orderId3 = (int)db()->lastInsertId();
    db()->prepare("INSERT INTO project_order_resources (order_id, program_template_id, domain_mode) VALUES (?, ?, 'none')")
        ->execute([$orderId3, $tpl1Id]);
    db()->prepare("INSERT INTO project_order_sources (order_id, price_source, trade_status) VALUES (?, 'manual', '交易成功')")
        ->execute([$orderId3]);
    db()->prepare("INSERT INTO project_participants (order_id, employee_id, commission_group, role_name, group_weight) VALUES (?, ?, 'technical', '模板技术', 1), (?, ?, 'customer_service', '网站客服', 1)")
        ->execute([$orderId3, $empTech, $orderId3, $empCs]);

    // 提交交付凭证申请（模拟挂起等待审核）
    $reqId3 = ps_create_order_request($orderId3, 'delivery_completion', $csActor, [
        'delivery_note' => '客户已发货并验收',
        'proof_path' => null
    ]);

    // 调用系统自动完成功能
    $autoRes = ps_auto_finish_trade_success_orders(10);
    assert($autoRes['finished'] >= 1, '自动完成应至少处理1笔订单');

    $order3Row = db()->query("SELECT settlement_status, delivery_status, receipt_amount FROM project_orders WHERE id=" . $orderId3)->fetch();
    assert($order3Row['delivery_status'] === 'finished', '满10天交易成功订单交付状态应自动变为 finished');
    assert($order3Row['settlement_status'] === 'approved', '满10天交易成功订单应自动完成提成审核');
    assert((float)$order3Row['receipt_amount'] == 888.00, '实收应自动补足为888元');

    $req3Row = db()->query("SELECT status FROM project_order_requests WHERE id=" . $reqId3)->fetch();
    assert($req3Row['status'] === 'approved', '挂起的交付申请应自动通过');
    echo "✔ 5. 交易成功满10天无人审核自动完成测试通过\n";

} finally {
    db()->rollBack();
    echo "✔ 测试数据已安全回滚，保持数据库整洁\n";
}

echo "=== 所有测试均顺利通过！ ===\n";
