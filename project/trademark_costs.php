<?php
require_once __DIR__ . '/../includes/ProjectTrademarkDupCost.php';
// 商标成本核对：同一客户、同一商标、同一办理事项的多张订单都带了官费，疑似重复计成本，财务一键确认。
$actor = ps_require_actor();
if (($actor['role'] ?? '') !== 'finance') { http_response_code(403); exit('仅管理员或财务可以核对商标成本'); }
$notice = $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    try { $notice = ptd_decide($_POST['group'] ?? '', (string)($_POST['decision'] ?? ''), $actor); } catch (Throwable $e) { $error = $e->getMessage(); }
}
$groups = ptd_candidates();
$page_title = '商标成本核对';
include __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/correction_tabs.php';
$cssFile = dirname(__DIR__) . '/assets/css/rule_algo.css';
?>
<link rel="stylesheet" href="<?php echo e(BASE_URL . '/assets/css/rule_algo.css?v=' . (is_file($cssFile) ? filemtime($cssFile) : 1)); ?>">
<style>.tmc-why{background:#fff8e6;border:1px solid #f0dca0;border-radius:10px;padding:.8rem 1rem;margin:.6rem 0}.tmc-why b{color:#8a5a00}.tmc-tags span{display:inline-block;margin:.15rem .3rem .15rem 0;padding:.1rem .55rem;border-radius:999px;font-size:.82rem}.tmc-same span{background:#e3f3ea;color:#1f6a52}.tmc-diff span{background:#fdeaea;color:#a33}.tmc-table{width:100%;font-size:.9rem;margin:.6rem 0}.tmc-table th,.tmc-table td{padding:.35rem .5rem;border-bottom:1px solid #eee;text-align:left}.tmc-act{display:grid;grid-template-columns:1fr 1fr;gap:.8rem;margin-top:.6rem}.tmc-act form{border:1px solid #dfe8e3;border-radius:10px;padding:.7rem .9rem}.tmc-act p{font-size:.85rem;color:#555;margin:.3rem 0 .6rem}@media(max-width:720px){.tmc-act{grid-template-columns:1fr}}</style>
<div class="ra-page">
  <?php pc_tabs('tm'); ?>
  <div class="ra-page-head"><div><h2>商标成本核对</h2><p>同一客户、同一商标、同一办理事项的多张订单都带入了官费，可能是同一件商标被重复计了成本。请对照下面的原因判断，一键确认即可。</p></div></div>
  <?php if ($notice): ?><div class="alert alert-success"><?php echo e($notice); ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
  <?php if (!$groups): ?><div class="ra-empty"><i class="far fa-check-circle"></i><h3>没有需要核对的商标成本</h3><p>系统发现疑似重复计官费的订单时，会出现在这里，并提醒在顶部铃铛和左侧栏。</p></div><?php endif; ?>
  <?php foreach ($groups as $g): ?>
  <article class="ra-req">
    <header><div><strong><?php echo e($g['customer']); ?></strong> · <?php echo e($g['trademark']); ?> · <?php echo e($g['service']); ?> <small class="text-muted">共 <?php echo count($g['orders']); ?> 张订单</small></div></header>
    <div class="tmc-why"><b>原因：</b>这 <?php echo count($g['orders']); ?> 张订单每张都带入了官费，合计比只计一次多出 <b>¥<?php echo e(number_format($g['dupe'], 2)); ?></b>。如果它们其实是同一件商标（例如一单被拆成两次付款），官费只应计一次，多计的会让这些订单的预计分成偏低甚至为负。</div>
    <div class="tmc-tags tmc-same"><small class="text-muted">相同：</small><?php foreach ($g['same'] as $t): ?><span><?php echo e($t); ?></span><?php endforeach; ?></div>
    <?php if ($g['diff']): ?><div class="tmc-tags tmc-diff"><small class="text-muted">不同：</small><?php foreach ($g['diff'] as $t): ?><span><?php echo e($t); ?></span><?php endforeach; ?></div><?php endif; ?>
    <table class="tmc-table"><thead><tr><th>订单</th><th>日期</th><th>类型</th><th>售价</th><th>官费</th><th>官费是怎么来的</th></tr></thead><tbody>
    <?php foreach ($g['orders'] as $o): ?><tr><td><a class="ra-link" target="_blank" href="<?php echo BASE_URL; ?>/project/order.php?id=<?php echo (int)$o['id']; ?>"><?php echo e($o['order_no']); ?></a><?php if ($o['frozen']): ?> <small class="text-danger">已审核</small><?php endif; ?></td><td><?php echo e($o['order_date']); ?></td><td><?php echo e($o['order_kind']); ?></td><td>¥<?php echo e(number_format((float)$o['contract_amount'], 2)); ?></td><td>¥<?php echo e(number_format($o['cost'], 2)); ?></td><td><?php echo e($o['source']); ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <div class="tmc-act">
      <form method="post"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="group" value="<?php echo e($g['key']); ?>"><input type="hidden" name="decision" value="same">
        <strong>是同一件商标，官费只计一次</strong>
        <p><?php if ($g['blocked']): ?>其中有已审核 / 锁定结算的订单，重复官费不能自动作废，请先通过<a href="<?php echo e(BASE_URL . '/project/corrections.php'); ?>">分成更正申请</a>处理。<?php else: ?>官费保留在售价最高的订单上，其余订单的重复官费作废（记录保留，可追溯），预计分成随即重算。<?php endif; ?></p>
        <button class="ra-btn" type="submit" <?php echo $g['blocked'] ? 'disabled' : ''; ?> onclick="return confirm('确认这几张订单是同一件商标，官费只计一次？');"><i class="fas fa-link"></i> 合并，只计一次</button></form>
      <form method="post"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="group" value="<?php echo e($g['key']); ?>"><input type="hidden" name="decision" value="separate">
        <strong>不是同一件，各自独立</strong>
        <p>每张订单保留自己的官费，不做任何改动，这组订单以后不再提醒。适用于同一客户先后办理不同的商标或不同的案件。</p>
        <button class="ra-btn ghost" type="submit" onclick="return confirm('确认它们是各自独立的订单，保留各自成本？');"><i class="fas fa-check"></i> 各自保留，不再提醒</button></form>
    </div>
  </article>
  <?php endforeach; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
