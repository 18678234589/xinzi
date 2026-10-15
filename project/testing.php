<?php
require_once __DIR__ . '/../includes/ProjectSettlement.php';
$actor = ps_require_actor();
$finance = $actor['role'] === 'finance';
$month = (string)($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = date('Y-m');
$page = max(1, (int)($_GET['page'] ?? 1));
$where = "p.commission_group='technical' AND p.role_name='软件测试' AND o.order_date>='2026-10-01' AND COALESCE(s.payroll_month,DATE_FORMAT(o.order_date,'%Y-%m'))=?";
$params = [$month];
if (!$finance) {
    $where .= ' AND p.employee_id=?';
    $params[] = (int)$actor['employee_id'];
}
$q = db()->prepare("SELECT o.*,p.employee_id,e.name,e.department,s.commission_amount AS testing_snapshot,s.income_amount AS testing_income,s.direct_cost AS testing_cost FROM project_participants p JOIN project_orders o ON o.id=p.order_id JOIN employees e ON e.id=p.employee_id LEFT JOIN project_commission_snapshots s ON s.order_id=p.order_id AND s.employee_id=p.employee_id AND s.commission_group=p.commission_group WHERE $where ORDER BY o.order_date DESC,o.id DESC,p.employee_id LIMIT 21 OFFSET " . (($page - 1) * 20));
$q->execute($params);
$rows = $q->fetchAll();
$hasNext = count($rows) > 20;
$rows = array_slice($rows, 0, 20);
$page_title = $finance ? '软件测试订单与提成' : '我的软件测试订单与提成';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3"><h4><?php echo e($page_title); ?></h4><a class="btn btn-outline-secondary" href="<?php echo BASE_URL; ?>/project/index.php">返回工作台</a></div>
<div class="card mb-3"><div class="card-body">
    <h5>软件测试提成＝（营业额－成本）×5%</h5>
    <p class="mb-2">自2026年10月1日起按订单日期生效，只计算订单中明确指派的软件测试工作。营业额按实收减退款，扣整单已审核实际成本；独立计提，不占技术组池权重，不另扣店铺服务费、不叠加补贴。退款、亏损按同一公式冲减。</p>
    <p class="text-muted small">未审核订单显示预估，包含待审核成本；未收款时使用合同金额预估。已审核订单显示已保存快照，后续变更沿用调整流程。</p>
    <form method="get" class="form-inline"><label for="testing-month" class="mr-2">归属月份</label><input id="testing-month" name="month" type="month" class="form-control mr-2" value="<?php echo e($month); ?>"><button class="btn btn-primary">查询</button></form>
    <?php if ($finance): ?><p class="small mt-3 mb-0">指派入口：打开订单详情 → “参与人与分成计算” → “软件测试 · 独立提成” → 选择李雪 → 点击“指派软件测试（独立5%）”。</p><?php endif; ?>
</div></div>
<div class="card"><div class="table-responsive"><table class="table mb-0"><thead><tr><th>订单日期 / 单号</th><th>业务</th><th>软件测试人员</th><th>营业额</th><th>成本</th><th>测试提成</th><th>状态</th></tr></thead><tbody>
<?php foreach ($rows as $row):
    $frozen = in_array($row['settlement_status'], ['approved','locked'], true);
    $calc = null;
    if (!$frozen) {
        $sum = ps_summary($row, ps_costs($row['id']), ps_participants($row['id']));
        foreach ($sum['groups']['technical']['people'] as $person) if ((int)$person['employee_id'] === (int)$row['employee_id']) $calc = $person['estimated_calc'];
    }
    $amount = $frozen ? $row['testing_snapshot'] : ($calc['share'] ?? null);
    $income = $frozen ? $row['testing_income'] : ($calc['income'] ?? null);
    $cost = $frozen ? $row['testing_cost'] : ($calc['cost_basis'] ?? null);
?>
<tr><td><?php echo e($row['order_date']); ?><br><a href="<?php echo BASE_URL; ?>/project/order.php?id=<?php echo (int)$row['id']; ?>#software-testing"><?php echo e($row['order_no']); ?></a></td><td><?php echo e($row['project_type']); ?></td><td><?php echo e($row['name']); ?></td><td><?php echo $income === null ? '—' : '¥' . money($income); ?></td><td><?php echo $cost === null ? '—' : '¥' . money($cost); ?></td><td><?php echo $amount === null ? '待核对' : '¥' . money($amount); ?></td><td><?php echo $frozen ? '已审核快照' : '预估 · 待审核'; ?></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="7" class="text-muted text-center py-4">本月暂无指派的软件测试订单。指派后会在此显示订单和提成。</td></tr><?php endif; ?>
</tbody></table></div></div>
<div class="mt-3 d-flex justify-content-between"><?php if ($page > 1): ?><a href="?<?php echo e(http_build_query(['month'=>$month,'page'=>$page-1])); ?>">上一页</a><?php else: ?><span></span><?php endif; ?><?php if ($hasNext): ?><a href="?<?php echo e(http_build_query(['month'=>$month,'page'=>$page+1])); ?>">下一页</a><?php endif; ?></div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
