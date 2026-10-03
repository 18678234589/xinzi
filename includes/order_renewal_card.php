<?php
require_once __DIR__.'/ProjectRenewals.php';
if (pr_ready() && pr_scope($actor)!=='none'):
try { pr_order($order['id'],$actor); $renewalVisible=true; } catch (RuntimeException $e) { $renewalVisible=false; }
if ($renewalVisible):
$renewalQuery=db()->prepare('SELECT id,resource_type,resource_name,expires_on,expiry_source FROM project_renewal_items WHERE order_id=? AND status=\'active\' ORDER BY expires_on IS NULL,expires_on,id'); $renewalQuery->execute([(int)$order['id']]); $renewalRows=$renewalQuery->fetchAll();
?>
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/renewals.css?v=20261002.2">
<div class="card mb-4"><div class="card-header"><i class="fas fa-calendar-check mr-2"></i>续费资料 <a class="float-right" href="<?php echo BASE_URL; ?>/project/renewals.php?order_id=<?php echo (int)$order['id']; ?>#renewal-form">＋ 登记资源</a></div><div class="card-body"><p class="small text-muted">域名 / 小程序认证到期日未填写时，默认下单日一年后，并提示核实；确认续费后保存新到期日，旧周期提醒自动停止。</p><?php if (!$renewalRows): ?><div class="pr-needs-date rounded p-3"><a href="<?php echo BASE_URL; ?>/project/renewals.php?order_id=<?php echo (int)$order['id']; ?>#renewal-form">请补录域名 / 小程序认证资料。默认预计到期：<?php echo e(pr_default_expiry($order['order_date'])?:'下单日期待补'); ?> →</a></div><?php endif; ?><?php foreach($renewalRows as $r): ?><div class="p-3 mb-2 rounded <?php echo $r['expiry_source']==='estimated'?'pr-needs-date':'border'; ?>"><a href="<?php echo BASE_URL; ?>/project/renewals.php?edit=<?php echo (int)$r['id']; ?>#renewal-form"><?php echo e($r['resource_name']?:pr_type_labels()[$r['resource_type']]); ?> · <?php echo e($r['expires_on']?:pr_default_expiry($order['order_date'])?:'待补日期'); ?><?php echo $r['expiry_source']==='estimated'?'（预计到期，请核实）':'（已核实）'; ?>　补录 / 确认续费 →</a></div><?php endforeach; ?></div></div>
<?php endif; endif; ?>
