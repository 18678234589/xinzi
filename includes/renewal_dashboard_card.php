<?php
require_once __DIR__.'/ProjectRenewals.php';
$renewalViewActor=$actor;
// Finance viewing a selected partner must not show another partner's count.
if ($actor['role']==='finance' && !empty($employeeId)) {
    $rq=db()->prepare('SELECT id,employee_id,role FROM project_users WHERE employee_id=? AND is_active=1 ORDER BY id LIMIT 1'); $rq->execute([(int)$employeeId]); $ru=$rq->fetch();
    $renewalViewActor=$ru?array_merge($ru,['type'=>'employee']):null;
}
if ($renewalViewActor && pr_ready() && pr_scope($renewalViewActor)!=='none'):
$renewalStats=pr_stats($renewalViewActor);
?>
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/renewals.css?v=20261002.2">
<div class="pr-dashboard-card"><div><strong>待续费 <?php echo (int)$renewalStats['active_orders']; ?> 单</strong><p><?php echo (int)$renewalStats['due_orders']; ?> 单在 30 天内或已到期 · <?php echo (int)$renewalStats['incomplete']; ?> 条资源待补 / 待核实 · <?php echo (int)$renewalStats['overdue']; ?> 条已到期</p></div><a href="<?php echo BASE_URL; ?>/project/renewals.php<?php echo $actor['role']==='finance' && !empty($employeeId)?'?employee_id='.(int)$employeeId:''; ?>">查看到期顺序与续费清单 →</a></div>
<?php endif; ?>
