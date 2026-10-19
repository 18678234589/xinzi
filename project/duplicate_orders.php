<?php
require_once __DIR__ . '/../includes/ProjectDuplicateOrders.php';
// 重复订单：同一订单被录入了两次（订单号只是写法不同），财务一键删掉多余的一张。
$actor = ps_require_actor();
if (($actor['role'] ?? '') !== 'finance') { http_response_code(403); exit('仅管理员或财务可以处理重复订单'); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    try { $_SESSION['pdo_flash'] = ['ok', pdo_delete_duplicate($_POST['group'] ?? '', (int)($_POST['delete_id'] ?? 0), $actor)]; }
    catch (Throwable $e) { $_SESSION['pdo_flash'] = ['err', $e->getMessage()]; }
    header('Location: ' . BASE_URL . '/project/duplicate_orders.php'); exit;
}
$flash = $_SESSION['pdo_flash'] ?? null; unset($_SESSION['pdo_flash']);
$groups = pdo_duplicate_groups();
$totalMoney = round(array_sum(array_column($groups, 'duplicated_money')), 2);
$page_title = '重复订单';
include __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/correction_tabs.php';
$cssFile = dirname(__DIR__) . '/assets/css/rule_algo.css';
$stLabel = ['draft' => '草稿', 'review' => '审核中', 'approved' => '已审核', 'locked' => '已锁定'];
?>
<link rel="stylesheet" href="<?php echo e(BASE_URL . '/assets/css/rule_algo.css?v=' . (is_file($cssFile) ? filemtime($cssFile) : 1)); ?>">
<style>.dup-sum{display:flex;flex-wrap:wrap;gap:.6rem;margin:.2rem 0 1rem}.dup-sum div{background:#f6faf7;border:1px solid #e1e9e4;border-radius:12px;padding:.55rem 1rem;font-size:.9rem}.dup-sum b{font-size:1.15rem;color:#1f6a52}.dup-sum .warn b{color:#a8650a}
.dup-why{background:#fff8e6;border:1px solid #f0dca0;border-radius:10px;padding:.8rem 1rem;margin:.6rem 0}.dup-why b{color:#8a5a00}
.dup-table{width:100%;font-size:.9rem;margin:.6rem 0}.dup-table th,.dup-table td{padding:.35rem .5rem;border-bottom:1px solid #eee;text-align:left;vertical-align:top}.dup-keep{background:#eef7f1}.dup-tag{display:inline-block;padding:0 .5rem;border-radius:999px;font-size:.72rem;color:#fff;background:#1f6a52}.dup-tag.del{background:#a33}</style>
<div class="ra-page">
  <?php pc_tabs('dup'); ?>
  <div class="ra-page-head"><div><h2>重复订单</h2><p>订单号写法不同（如带“交易号：”“订单编号：”前缀），规范化后相同，可能是同一单重复录入。请先核实客户、成本和收款资料已完整保留，再点“删除这张”：删除的订单进回收站，30 天内可还原，对应的分成记录同时撤回。</p><a href="<?php echo e(BASE_URL . '/project/trash.php'); ?>">查看订单回收站 / 还原误删订单</a></div></div>
  <?php if ($flash): ?><div class="alert alert-<?php echo $flash[0] === 'ok' ? 'success' : 'danger'; ?>"><?php echo e($flash[1]); ?></div><?php endif; ?>
  <?php if ($groups): ?><div class="dup-sum"><div class="warn">重复订单 <b><?php echo count($groups); ?></b> 组</div><div>重复生成的分成合计 <b>¥<?php echo e(number_format($totalMoney, 2)); ?></b></div></div><?php endif; ?>
  <?php if (!$groups): ?><div class="ra-empty"><i class="far fa-check-circle"></i><h3>没有重复订单</h3><p>系统发现同一订单号被录入两次时会出现在这里，并提醒在顶部铃铛和左侧栏。</p></div><?php endif; ?>
  <?php foreach ($groups as $g): $k = $g['keep']; ?>
  <article class="ra-req">
    <header><div><strong><?php echo e($g['project_type']); ?></strong> · 订单号 <?php echo e(ps_order_no_canonical($k['order_no'])); ?> <small class="text-muted">共 <?php echo count($g['dups']) + 1; ?> 张</small></div></header>
    <div class="dup-why"><b>原因：</b>这几张订单的订单号去掉前缀 / 备注后是同一个号，日期<?php echo $g['same_date'] ? '相同' : '<b>不同</b>'; ?>、售价<?php echo $g['same_amount'] ? '相同' : '<b>不同</b>'; ?>，可能由不同表格重复录入。<?php if ($g['duplicated_money'] > 0): ?>疑似重复的订单已经生成了 <b>¥<?php echo e(number_format($g['duplicated_money'], 2)); ?></b> 分成，请核实是否重复计入。<?php endif; ?></div>
    <div class="table-responsive"><table class="dup-table"><thead><tr><th></th><th>订单号（原样）</th><th>客户昵称</th><th>日期</th><th>售价</th><th>状态</th><th>参与人</th><th>已生成分成</th><th>录入时间</th><th></th></tr></thead><tbody>
      <tr class="dup-keep"><td><span class="dup-tag">保留</span></td><td><a class="ra-link" target="_blank" href="<?php echo BASE_URL; ?>/project/order.php?id=<?php echo (int)$k['id']; ?>"><?php echo e($k['order_no']); ?></a></td><td><?php echo e($k['customer_name'] !== '' ? $k['customer_name'] : '—'); ?></td><td><?php echo e($k['order_date']); ?></td><td>¥<?php echo e(number_format((float)$k['contract_amount'], 2)); ?></td><td><?php echo e($stLabel[$k['settlement_status']] ?? $k['settlement_status']); ?></td><td><?php echo (int)$k['ppl']; ?> 人</td><td>¥<?php echo e(number_format((float)$k['snap_total'], 2)); ?></td><td><?php echo e(substr((string)$k['created_at'], 0, 16)); ?></td><td></td></tr>
      <?php foreach ($g['dups'] as $d): ?><tr><td><span class="dup-tag del">多余</span></td><td><a class="ra-link" target="_blank" href="<?php echo BASE_URL; ?>/project/order.php?id=<?php echo (int)$d['id']; ?>"><?php echo e($d['order_no']); ?></a></td><td><?php echo e($d['customer_name'] !== '' ? $d['customer_name'] : '—'); ?></td><td><?php echo e($d['order_date']); ?></td><td>¥<?php echo e(number_format((float)$d['contract_amount'], 2)); ?></td><td><?php echo e($stLabel[$d['settlement_status']] ?? $d['settlement_status']); ?></td><td><?php echo (int)$d['ppl']; ?> 人</td><td>¥<?php echo e(number_format((float)$d['snap_total'], 2)); ?></td><td><?php echo e(substr((string)$d['created_at'], 0, 16)); ?></td>
        <td><?php if (!empty($g['deletable'][(int)$d['id']])): ?><form method="post" onsubmit="return confirm('删除这张多余的订单？它会进回收站（30 天内可还原），重复的分成同时收回。')"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="group" value="<?php echo e($g['key']); ?>"><input type="hidden" name="delete_id" value="<?php echo (int)$d['id']; ?>"><button class="ra-btn" type="submit"><i class="fas fa-trash"></i> 删除这张</button></form><?php else: ?><small class="text-danger"><?php echo e($g['blocked_why'][(int)$d['id']] ?? '不能自动删除'); ?></small><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <small class="text-muted">保留依据：有客户昵称、参与人更多、已审核的优先。想保留另一张，可以到订单列表里手动删除（财务可删已审核订单）。</small>
  </article>
  <?php endforeach; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
