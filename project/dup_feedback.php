<?php
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/dup_feedback.php';
// 订单号重复说明：上传人填写原因；其部门主管、财务和管理员可查看，财务确认后归档。
$actor = ps_require_actor();
pd_ensure();
$finance = pd_is_dedicated_finance($actor);
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    try {
        $fid = (int)($_POST['id'] ?? 0);
        if (($_POST['action'] ?? '') === 'close') pd_close($fid, $actor); else pd_answer($fid, $actor, $_POST['reason'] ?? '');
        header('Location: ' . BASE_URL . '/project/dup_feedback.php?done=1'); exit;
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$sql = 'SELECT f.*, u.name AS uploader FROM project_dup_feedback f JOIN employees u ON u.id=f.employee_id ORDER BY (f.status="asking") DESC, (f.status="answered") DESC, f.id DESC LIMIT 200';
$rows = array_values(array_filter(db()->query($sql)->fetchAll(), function ($r) use ($actor) { return pd_can_view($actor, $r); }));
$focus = (int)($_GET['id'] ?? 0);
$page_title = '订单号重复说明';
include __DIR__ . '/../includes/header.php';
$labels = ['asking' => ['待上传人说明', 'warning'], 'answered' => ['已说明，待财务确认', 'info'], 'closed' => ['已确认', 'secondary']];
?>
<div class="container-fluid">
<h4 class="mb-3">订单号重复说明</h4>
<p class="text-muted small">同一订单号在上传表里出现多行时，系统会把售价合并相加。请上传人写明原因；上传人、部门主管、财务和管理员都能看到。</p>
<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
<?php if (isset($_GET['done'])): ?><div class="alert alert-success">已保存</div><?php endif; ?>
<?php if (!$rows): ?><div class="alert alert-light">暂无记录。</div><?php endif; ?>
<?php foreach ($rows as $r): [$stLabel, $stClass] = $labels[$r['status']]; $mine = (int)$r['employee_id'] === (int)($actor['employee_id'] ?? 0); ?>
<div class="card mb-3<?php echo $focus === (int)$r['id'] ? ' border-warning' : ''; ?>"><div class="card-body">
  <div class="d-flex justify-content-between flex-wrap"><strong>订单号 <?php echo e($r['order_no']); ?> · 上传人 <?php echo e($r['uploader']); ?></strong><span class="badge badge-<?php echo $stClass; ?>"><?php echo e($stLabel); ?></span></div>
  <div class="small text-muted mt-1">表格第 <?php echo e($r['lines_text']); ?> 行合并；合并后售价 ¥<?php echo number_format((float)$r['amount'], 2); ?> · <a href="<?php echo BASE_URL; ?>/project/order.php?id=<?php echo (int)$r['order_id']; ?>">查看订单</a> · <?php echo e(substr($r['created_at'], 0, 16)); ?></div>
  <?php if ($r['reason'] !== null): ?><div class="mt-2"><strong>原因：</strong><?php echo nl2br(e($r['reason'])); ?></div><?php endif; ?>
  <?php if ($mine && $r['status'] !== 'closed'): ?>
  <form method="post" class="mt-2"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
    <textarea name="reason" class="form-control" rows="2" required minlength="5" placeholder="请写明原因：确为两笔订单，还是重复录入需改回？"><?php echo e($r['reason'] ?? ''); ?></textarea>
    <button class="btn btn-success btn-sm mt-2"><?php echo $r['status'] === 'asking' ? '提交说明' : '修改说明'; ?></button></form>
  <?php endif; ?>
  <?php if ($finance && $r['status'] === 'answered'): ?>
  <form method="post" class="mt-2"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>"><input type="hidden" name="action" value="close"><button class="btn btn-outline-secondary btn-sm">已阅并确认</button></form>
  <?php endif; ?>
</div></div>
<?php endforeach; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
