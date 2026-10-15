<?php
$testersQuery = db()->prepare("SELECT DISTINCT e.id,e.name,e.department FROM employees e JOIN project_employee_roles r ON r.employee_id=e.id WHERE r.commission_group='technical' AND r.role_name='软件测试' AND r.business_name IN (?, '*') ORDER BY e.name,e.id");
$testersQuery->execute([ps_business_normalize($order['project_type'])]);
$softwareTesters = $testersQuery->fetchAll();
$testingRule = ps_rule_for('technical', $order['project_type'], $order['order_date'], '软件测试', $order['order_kind'] ?? '');
?>
<div class="card-body border-top" id="software-testing">
    <h6>软件测试 · 独立提成</h6>
    <p class="small text-muted">2026年10月1日起，按订单日期生效。提成＝（营业额－成本）×5%；营业额按实收减退款，扣整单已审核实际成本，不另扣店铺服务费、不叠加补贴。测试不占技术组池权重，保留原客服岗位。</p>
    <?php if (!$testingRule): ?>
        <div class="alert alert-info mb-0">该订单日期尚未达到软件测试规则生效日期，不能指派测试提成。</div>
    <?php elseif (!$softwareTesters): ?>
        <div class="alert alert-info mb-0">本业务尚未配置软件测试人员，请在规则中心配置人员岗位。</div>
    <?php else: ?>
    <form method="post" class="form-inline">
        <input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>">
        <input type="hidden" name="action" value="add_participant">
        <input type="hidden" name="commission_group" value="technical">
        <input type="hidden" name="role_name" value="软件测试">
        <input type="hidden" name="group_weight" value="100">
        <label for="software-tester" class="mr-2">软件测试人员</label>
        <select id="software-tester" name="employee_id" class="form-control mr-2" required>
            <?php foreach ($softwareTesters as $tester): ?><option value="<?php echo (int)$tester['id']; ?>"><?php echo e($tester['name'] . ' · ' . $tester['department']); ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn-primary" type="submit">指派软件测试（独立5%）</button>
    </form>
    <?php endif; ?>
</div>
