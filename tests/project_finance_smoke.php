<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectSettlement.php';

function check_project_value($actual, $expected, $label)
{
    if (abs((float)$actual - (float)$expected) > 0.001) {
        throw new RuntimeException($label . ': expected ' . $expected . ', got ' . $actual);
    }
}

$pdo = db();
$pdo->beginTransaction();
try {
    $websiteMigration = file_get_contents(__DIR__ . '/../migrations/20260923_website_commission_rules.sql');
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $websiteMigration) as $statement) if (trim($statement) !== '') $pdo->exec($statement);
    $defaultCount = (int)$pdo->query("SELECT COUNT(*) FROM project_commission_rules WHERE (commission_group='technical' AND project_type='网站模板') OR (commission_group='customer_service' AND project_type IN ('网站模板','AI网站定制'))")->fetchColumn();
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $websiteMigration) as $statement) if (trim($statement) !== '') $pdo->exec($statement);
    $repeatCount = (int)$pdo->query("SELECT COUNT(*) FROM project_commission_rules WHERE (commission_group='technical' AND project_type='网站模板') OR (commission_group='customer_service' AND project_type IN ('网站模板','AI网站定制'))")->fetchColumn();
    if ($repeatCount !== $defaultCount) throw new RuntimeException('网站默认规则迁移重复执行后产生了重复规则');
    $no = 'SMOKE-' . bin2hex(random_bytes(8));
    $pdo->prepare("INSERT INTO project_orders (order_no,project_type,contract_amount,order_date,delivery_status) VALUES (?,?,6800,CURDATE(),'finished')")
        ->execute([$no, $no]);
    $orderId = (int)$pdo->lastInsertId();
    $insertCash = $pdo->prepare("INSERT INTO project_cash_movements (order_id,movement_type,amount,review_status,submitted_by_type,submitted_by_id) VALUES (?,?,?,'approved','system',0)");
    $insertCash->execute([$orderId, 'receipt', 6800]);
    $insertCash->execute([$orderId, 'refund', 100]);
    $pdo->prepare("INSERT INTO project_cash_movements (order_id,movement_type,amount,review_status,submitted_by_type,submitted_by_id) VALUES (?,'receipt',200,'pending','system',0)")
        ->execute([$orderId]);
    ps_recalculate_cash($orderId);
    $q = $pdo->prepare('SELECT * FROM project_orders WHERE id=?');
    $q->execute([$orderId]);
    $order = $q->fetch();
    check_project_value($order['receipt_amount'], 6800, 'approved receipt');
    check_project_value($order['refund_amount'], 100, 'approved refund');

    $costs = [
        ['amount' => 1215, 'review_status' => 'approved'],
        ['amount' => 200, 'review_status' => 'pending'],
    ];
    $pdo->prepare("INSERT INTO project_commission_rules (commission_group,project_type,rate,effective_from) VALUES ('technical',?,0.12,'2000-01-01'),('customer_service',?,0.05,'2000-01-01')")
        ->execute([$no, $no]);
    $participants = [
        ['commission_group' => 'technical', 'group_weight' => 0.5],
        ['commission_group' => 'technical', 'group_weight' => 0.3],
        ['commission_group' => 'technical', 'group_weight' => 0.2],
        ['commission_group' => 'customer_service', 'group_weight' => 1],
    ];
    $summary = ps_summary($order, $costs, $participants);
    check_project_value($summary['income'], 6700, 'net income');
    check_project_value($summary['profit'], 5485, 'contribution profit');
    check_project_value($summary['estimated_profit'], 5285, 'pending-cost estimate');
    check_project_value($summary['groups']['technical']['pool'], 658.20, 'technical pool');
    check_project_value($summary['groups']['customer_service']['pool'], 274.25, 'customer-service pool');
    $pdo->prepare("INSERT INTO project_commission_rules (commission_group,project_type,rate,effective_from) VALUES ('technical','网站模板',0.13,'2098-01-01'),('customer_service','网站模板',0.08,'2098-01-01'),('technical','AI网站定制',0.12,'2098-01-01')")->execute();
    $websiteOrder = ['project_type' => '网站模板', 'contract_amount' => 10000, 'receipt_amount' => 10000, 'refund_amount' => 0, 'order_date' => '2099-01-01'];
    $websitePeople = [['commission_group' => 'technical', 'group_weight' => 1], ['commission_group' => 'customer_service', 'group_weight' => 0.5], ['commission_group' => 'customer_service', 'group_weight' => 0.5]];
    $websiteSummary = ps_summary($websiteOrder, [['amount' => 1875, 'review_status' => 'approved']], $websitePeople);
    check_project_value($websiteSummary['service_fee'], 300, 'website template service fee');
    check_project_value($websiteSummary['profit'], 7825, 'website template contribution profit');
    check_project_value($websiteSummary['groups']['technical']['pool'], 1017.25, 'website template technical pool 13%');
    check_project_value($websiteSummary['groups']['customer_service']['pool'], 638, 'website template customer-service pool: two CS each (10000-1875-1.5%)x4% per 网站核算 主次');
    $customOrder = $websiteOrder;
    $customOrder['project_type'] = '网站定制';
    $customSummary = ps_summary($customOrder, [], [['commission_group' => 'technical', 'group_weight' => 1]]);
    check_project_value($customSummary['service_fee'], 0, 'website custom should not inherit template fee');
    check_project_value($customSummary['groups']['technical']['pool'], 1200, 'website custom uses AI technical rate');
    check_project_value(ps_settlement_preview(6000, 274.25), 6274.25, 'customer-service additive settlement');
    check_project_value(ps_settlement_preview(6000, 603.35, 100, true), 6503.35, 'technical old commission replaced');
    if (ps_settlement_preview(6000, 603.35, 0, false) !== null) throw new RuntimeException('待核对的技术分成不能显示预计应结算金额');
    if (ps_settlement_preview(null, 274.25) !== null) throw new RuntimeException('未生成原系统记录时不能计算完整应结算金额');
    $pennyShares = ps_allocate_pool_cents(1, [['group_weight' => 0.4], ['group_weight' => 0.4], ['group_weight' => 0.2]]);
    if ($pennyShares !== [1, 0, 0]) throw new RuntimeException('分润尾差没有归入最高权重者');
    $adminId = (int)$pdo->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetchColumn();
    $employeeIds = $pdo->query('SELECT id FROM employees ORDER BY id LIMIT 3')->fetchAll(PDO::FETCH_COLUMN);
    if ($adminId <= 0 || count($employeeIds) < 3) throw new RuntimeException('财务测试需要一名管理员及三名已有员工');
    $pdo->prepare("INSERT INTO project_costs (order_id,category,item_name,quantity,unit,unit_price,amount,review_status) VALUES (?,'server','测试成本',1,'项',1215,1215,'approved')")
        ->execute([$orderId]);
    $insertPerson = $pdo->prepare('INSERT INTO project_participants (order_id,employee_id,commission_group,group_weight) VALUES (?,?,?,?)');
    foreach ([0.5, 0.3, 0.2] as $i => $weight) $insertPerson->execute([$orderId, $employeeIds[$i], 'technical', $weight]);
    $insertPerson->execute([$orderId, $employeeIds[0], 'customer_service', 1]);
    $testPeriod = '2099-12';
    try {
        ps_approve_order($orderId, ['type' => 'admin', 'id' => $adminId, 'role' => 'finance'], $testPeriod);
        throw new RuntimeException('待审收款错误地允许订单审核');
    } catch (RuntimeException $expected) {
        if (strpos($expected->getMessage(), '待审核的收款或退款') === false) throw $expected;
    }
    $pdo->prepare("UPDATE project_cash_movements SET review_status='rejected' WHERE order_id=? AND review_status='pending'")->execute([$orderId]);
    ps_approve_order($orderId, ['type' => 'admin', 'id' => $adminId, 'role' => 'finance'], $testPeriod);
    $snapshotQuery = $pdo->prepare('SELECT commission_group,commission_amount FROM project_commission_snapshots WHERE order_id=?');
    $snapshotQuery->execute([$orderId]);
    $snapshots = $snapshotQuery->fetchAll();
    if (count($snapshots) !== 4) throw new RuntimeException('审核未生成四条技术/客服快照');
    $totalCommission = array_sum(array_map(function ($r) { return (float)$r['commission_amount']; }, $snapshots));
    check_project_value($totalCommission, 932.45, 'approved commission snapshots');

    $pdo->prepare("INSERT INTO project_orders (order_no,project_type,contract_amount,order_date,delivery_status) VALUES (?,?,6800,CURDATE(),'finished')")
        ->execute([$no . '-LOCK', $no]);
    $lockedOrderId = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE project_payroll_periods SET status='locked' WHERE period=?")->execute([$testPeriod]);
    try {
        ps_approve_order($lockedOrderId, ['type' => 'admin', 'id' => $adminId, 'role' => 'finance'], $testPeriod);
        throw new RuntimeException('已锁月份错误地允许订单审核');
    } catch (RuntimeException $expected) {
        if (strpos($expected->getMessage(), '已锁定') === false) throw $expected;
    }
    if ($adminId > 0) {
        $_SESSION['admin_id'] = $adminId;
        $_GET['id'] = $orderId;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['SCRIPT_NAME'] = '/project/order.php';
        ob_start();
        include __DIR__ . '/../project/order.php';
        $html = ob_get_clean();
        if (strpos($html, '收款与退款记录') === false || strpos($html, '项目贡献利润') === false) throw new RuntimeException('订单结算单未正常渲染');
        $pdo->prepare('INSERT INTO salaries (employee_id,month,net_pay,base_salary_amount,commission) VALUES (?,?,6000,5000,1000)')
            ->execute([$employeeIds[0], $testPeriod]);
        $_GET = ['month' => $testPeriod, 'employee_id' => $employeeIds[0]];
        ob_start();
        include __DIR__ . '/../project/payroll.php';
        $settlementHtml = ob_get_clean();
        if (strpos($settlementHtml, '待技术分成核对') === false || strpos($settlementHtml, '6,603.35') !== false) throw new RuntimeException('技术分成未核对时错误显示金额');
        $technicalSnapshotId = $pdo->query("SELECT id FROM project_commission_snapshots WHERE order_id=$orderId AND employee_id=" . (int)$employeeIds[0] . " AND commission_group='technical'")->fetchColumn();
        $salaryId = $pdo->query('SELECT id FROM salaries WHERE employee_id=' . (int)$employeeIds[0] . " AND month='" . $testPeriod . "'")->fetchColumn();
        $pdo->prepare('INSERT INTO project_technical_reconciliations (snapshot_id,legacy_salary_month,legacy_salary_id,legacy_amount,basis_note,reviewed_by_admin,reviewed_at) VALUES (?,?,?,?,?,?,NOW())')
            ->execute([$technicalSnapshotId, $testPeriod, $salaryId, 100, '测试：旧技术分成核销', $adminId]);
        ob_start();
        include __DIR__ . '/../project/payroll.php';
        $settlementHtml = ob_get_clean();
        if (strpos($settlementHtml, '预计应结算金额') === false || strpos($settlementHtml, '6,503.35') === false) throw new RuntimeException('去重后项目报酬结算单金额错误');
    }
    $pdo->rollBack();
    echo "收退款审核、双组分成、锁月与技术旧分成去重验证通过；测试数据已回滚\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
