<?php
require_once __DIR__ . '/../includes/ProjectOrderTrash.php';
// 订单回收站：删除的订单保留 30 天，期内可一键还原（财务看全部，其他人看自己删除的）。
$actor = ps_require_actor();
pot_ensure();
pot_purge_expired();
$error = ''; $success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    try { $no = pot_restore((int)($_POST['restore_id'] ?? 0), $actor); $success = '订单 ' . $no . ' 已还原'; }
    catch (Throwable $e) { $error = $e instanceof RuntimeException ? $e->getMessage() : '还原失败，请稍后再试'; }
}
$rows = pot_list($actor);
$page_title = '订单回收站';
include __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid" style="max-width:1100px">
  <div class="rf-hero" style="background:linear-gradient(135deg,#f3f6f4,#fff);border:1px solid #dfe7e2;border-radius:18px;padding:20px 26px;margin:14px 0">
    <h2 style="margin:0 0 6px;font-size:1.35rem;font-weight:700;color:#1f3a2c"><i class="fas fa-trash-restore mr-2"></i>订单回收站</h2>
    <p style="margin:0;color:#5d6f64;font-size:.9rem;line-height:1.7">删除的订单会先放在这里，<b>保留 30 天</b>，期内可以一键还原（连同参与人、成本、收款和分成记录）；超过 30 天自动彻底清除。<?php echo ($actor['role'] ?? '') === 'finance' ? '你是财务，可以看到并还原所有人删除的订单。' : '这里只显示你自己删除的订单。'; ?></p>
  </div>
  <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
  <div class="card mb-4"><div class="table-responsive"><table class="table table-hover mb-0">
    <thead class="thead-light"><tr><th>订单号</th><th>业务</th><th>订单日期</th><th class="text-right">售价</th><th>删除时的状态</th><th>删除人 / 时间</th><th>剩余保留</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><strong><?php echo e($r['order_no']); ?></strong></td>
        <td><?php echo e($r['project_type']); ?></td>
        <td><?php echo e($r['order_date']); ?></td>
        <td class="text-right">¥<?php echo money($r['contract_amount']); ?></td>
        <td><?php echo e(['approved' => '已审核', 'draft' => '草稿', 'locked' => '已锁定'][$r['settlement_status']] ?? $r['settlement_status']); ?></td>
        <td class="small"><?php echo e($r['deleted_by_name'] ?: '—'); ?><div class="text-muted"><?php echo e($r['deleted_at']); ?></div></td>
        <td><span class="badge badge-<?php echo (int)$r['days_left'] <= 3 ? 'warning' : 'light'; ?>">还剩 <?php echo max(0, (int)$r['days_left']); ?> 天</span></td>
        <td class="text-right"><form method="post" class="d-inline" onsubmit="return confirm('还原订单 <?php echo e($r['order_no']); ?>？')"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="restore_id" value="<?php echo (int)$r['id']; ?>"><button class="btn btn-sm btn-success">还原</button></form></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-5">回收站是空的</td></tr><?php endif; ?>
    </tbody></table></div></div>
  <p class="small text-muted">还原时如果订单号已被重新导入的订单占用，需要先处理那张订单。<a href="<?php echo BASE_URL; ?>/project/index.php">返回订单列表</a></p>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
