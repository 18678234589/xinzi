<?php
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/commission_explain.php';
// 分成更正申请：合作人员在订单“计算过程”弹窗里提交，财务/管理员在此处理；处理结果回复到申请人的站内信。
$actor = ps_require_finance();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    try {
        ps_corr_handle((int)($_POST['correction_id'] ?? 0), $actor, (string)($_POST['decision'] ?? ''), (string)($_POST['handle_note'] ?? ''));
        header('Location: ' . BASE_URL . '/project/corrections.php?done=1'); exit;
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
ps_corr_ensure();
$status = (string)($_GET['status'] ?? 'pending');
if (!in_array($status, ['pending', 'resolved', 'rejected', 'all'], true)) $status = 'pending';
$sql = 'SELECT c.*, o.order_no, o.project_type, o.contract_amount, t.name AS target_name FROM project_commission_corrections c LEFT JOIN project_orders o ON o.id=c.order_id LEFT JOIN employees t ON t.id=c.target_employee_id' . ($status === 'all' ? '' : ' WHERE c.status=?') . ' ORDER BY c.id DESC LIMIT 200';
$q = db()->prepare($sql);
$q->execute($status === 'all' ? [] : [$status]);
$rows = $q->fetchAll();
$badge = ['pending' => '<span class="badge badge-warning">待处理</span>', 'resolved' => '<span class="badge badge-success">已处理</span>', 'rejected' => '<span class="badge badge-secondary">未采纳</span>'];
$page_title = '分成更正申请';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center flex-wrap mb-3"><h4 class="mb-0">分成更正申请</h4>
  <div class="btn-group btn-group-sm"><?php foreach (['pending' => '待处理', 'resolved' => '已处理', 'rejected' => '未采纳', 'all' => '全部'] as $key => $label): ?><a class="btn btn-outline-secondary<?php echo $status === $key ? ' active' : ''; ?>" href="?status=<?php echo $key; ?>"><?php echo $label; ?></a><?php endforeach; ?></div></div>
<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
<?php if (isset($_GET['done'])): ?><div class="alert alert-success">已处理，结果已回复给申请人。</div><?php endif; ?>
<p class="text-muted small">这里只登记申请，不会自动改金额。核对后请到订单里调整收款、成本、参与人或规则，再回来点“已处理”并写回复。</p>
<?php if (!$rows): ?><div class="card"><div class="card-body text-center text-muted">暂无申请。</div></div><?php endif; ?>
<?php foreach ($rows as $r): $detail = json_decode((string)$r['snapshot_json'], true) ?: []; ?>
<div class="card mb-3"><div class="card-header d-flex justify-content-between flex-wrap"><span><?php echo $badge[$r['status']] ?? ''; ?> <a href="<?php echo BASE_URL; ?>/project/order.php?id=<?php echo (int)$r['order_id']; ?>"><strong>订单 <?php echo e($r['order_no'] ?: '#' . $r['order_id']); ?></strong></a> · <?php echo e($r['project_type']); ?> · 售价 <?php echo e(ps_yuan($r['contract_amount'])); ?></span><small class="text-muted"><?php echo e(substr($r['created_at'], 0, 16)); ?> · 申请人 <?php echo e($r['applicant_name']); ?></small></div>
<div class="card-body">
  <div class="mb-2">被质疑的分成：<strong><?php echo e($r['target_name']); ?></strong>（<?php echo e(ps_label('group', $r['target_group'])); ?>），申请时页面显示 <strong><?php echo $r['shown_amount'] !== null ? e(ps_yuan($r['shown_amount'])) : '—'; ?></strong><?php if ($r['expected_amount'] !== null): ?>，申请人认为应为 <strong><?php echo e(ps_yuan($r['expected_amount'])); ?></strong><?php endif; ?></div>
  <div class="mb-2">理由：<?php echo nl2br(e($r['reason'])); ?></div>
  <?php if (!empty($detail['calc'])): $steps = ps_explain_calc_steps($detail['calc'], ['receipt_amount' => $detail['receipt'] ?? 0, 'refund_amount' => $detail['refund'] ?? 0]); ?>
    <details class="mb-2"><summary class="small text-muted">申请时的计算过程</summary><?php echo ps_explain_table($steps); ?></details>
  <?php elseif (!empty($detail['snapshot'])): ?>
    <details class="mb-2"><summary class="small text-muted">申请时的审核快照</summary><?php echo ps_explain_table(ps_explain_snapshot_steps($detail['snapshot'])); ?></details>
  <?php endif; ?>
  <?php if ($r['status'] === 'pending'): ?>
  <form method="post" class="form-row align-items-end"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="correction_id" value="<?php echo (int)$r['id']; ?>">
    <div class="form-group col-md-7 mb-2"><label class="small mb-1">给申请人的回复（不采纳时必填）</label><input name="handle_note" class="form-control" maxlength="500"></div>
    <div class="form-group col-md-5 mb-2"><button name="decision" value="resolved" class="btn btn-success btn-sm">已处理</button> <button name="decision" value="rejected" class="btn btn-outline-secondary btn-sm">不采纳</button> <a class="btn btn-outline-primary btn-sm" href="<?php echo BASE_URL; ?>/project/order.php?id=<?php echo (int)$r['order_id']; ?>">去订单核对</a></div></form>
  <?php else: ?><div class="small text-muted">由 <?php echo e($r['handler_name']); ?> 于 <?php echo e(substr((string)$r['handled_at'], 0, 16)); ?> 处理<?php echo $r['handle_note'] ? '：' . e($r['handle_note']) : ''; ?></div><?php endif; ?>
</div></div>
<?php endforeach; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
