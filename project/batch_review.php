<?php
require_once __DIR__ . '/../includes/ProjectBatchReview.php';
// 订单审核申请：合作人员看自己哪些订单还没审核通过、卡在哪、怎么解决，并向财务提交批量审核申请；财务抽查后一键全部通过。
$actor = ps_require_actor();
pbr_ensure();
$isFinance = ($actor['role'] ?? '') === 'finance';
if (!$isFinance && empty($actor['employee_id'])) { http_response_code(403); exit('无权限'); }
$error = ''; $success = ''; $failed = [];
$month = (string)($_GET['month'] ?? $_POST['month'] ?? date('Y-m', strtotime('first day of last month')));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = date('Y-m');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    try {
        $do = (string)($_POST['do'] ?? '');
        if ($do === 'submit') { pbr_submit($actor, $month, (string)($_POST['note'] ?? '')); $success = '申请已提交，财务处理后会发站内信通知你。'; }
        elseif ($do === 'approve') {
            list($n, $failed) = pbr_approve($actor, (int)($_POST['id'] ?? 0), (array)($_POST['ids'] ?? []));
            $success = '已审核通过 ' . $n . ' 单' . ($failed ? '；下面这些需要单独处理' : '');
        } elseif ($do === 'reject') { pbr_reject($actor, (int)($_POST['id'] ?? 0), (string)($_POST['note'] ?? '')); $success = '已退回，对方会收到站内信。'; }
        else throw new RuntimeException('操作无效');
    } catch (Throwable $e) { $error = $e instanceof RuntimeException ? $e->getMessage() : '操作失败，请稍后再试'; }
}

$reqId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$detail = null; $detailOrders = [];
if ($isFinance && $reqId) {
    $q = db()->prepare('SELECT r.*,e.name emp_name FROM project_batch_reviews r LEFT JOIN employees e ON e.id=r.employee_id WHERE r.id=?'); $q->execute([$reqId]); $detail = $q->fetch() ?: null;
    if ($detail) { $detailOrders = pbr_orders((int)$detail['employee_id'], $detail['month']); $month = $detail['month']; }
}
$mine = $isFinance ? [] : pbr_orders((int)$actor['employee_id'], $month);
$list = [];
if ($isFinance) $list = db()->query("SELECT r.*,e.name emp_name FROM project_batch_reviews r LEFT JOIN employees e ON e.id=r.employee_id ORDER BY FIELD(r.status,'pending','rejected','done'), r.id DESC LIMIT 100")->fetchAll();
else { $q = db()->prepare('SELECT * FROM project_batch_reviews WHERE employee_id=? ORDER BY id DESC LIMIT 12'); $q->execute([(int)$actor['employee_id']]); $list = $q->fetchAll(); }
$myOpen = null; foreach ($list as $r) if (!$isFinance && $r['month'] === $month && $r['status'] === 'pending') $myOpen = $r;
$st = ['pending' => ['待财务处理', 'warning'], 'done' => ['已处理', 'success'], 'rejected' => ['已退回', 'danger']];
$page_title = '订单审核申请';
include __DIR__ . '/../includes/header.php';
function pbr_state_badge($row) { [$label, $tone] = pa_state_meta($row['review_state'] ?: 'queued'); return '<span class="badge badge-' . e($tone) . '">' . e($label) . '</span>'; }
?>
<div class="container-fluid" style="max-width:1180px">
  <div style="background:linear-gradient(135deg,#f3f6f4,#fff);border:1px solid #dfe7e2;border-radius:18px;padding:20px 26px;margin:14px 0">
    <h2 style="margin:0 0 6px;font-size:1.35rem;font-weight:700;color:#1f3a2c"><i class="fas fa-clipboard-check mr-2"></i>订单审核申请</h2>
    <p style="margin:0;color:#5d6f64;font-size:.9rem;line-height:1.7"><?php echo $isFinance ? '合作人员提交的批量审核申请在这里。点进去逐单抽查，没问题就“全部通过”：系统会按售价登记收款、标记交付完成并生成分成，全部记审计。有异常的订单默认不勾选，需要单独处理。' : '工资里的营业额、分成只算<b>已审核通过</b>的订单。下面列出你这个月还没通过的订单和各自卡在哪；确认没问题后点“申请财务批量审核”，财务抽查后会一次性通过。'; ?></p>
  </div>
  <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
  <?php if ($failed): ?><div class="alert alert-warning"><b>这些没能自动通过：</b><ul class="mb-0"><?php foreach ($failed as $f): ?><li><?php echo e($f); ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<?php if ($isFinance && $detail): ?>
  <div class="card mb-4"><div class="card-header d-flex justify-content-between flex-wrap"><span><?php echo e($detail['emp_name']); ?> · <?php echo e($detail['month']); ?> · 申请 <?php echo (int)$detail['order_count']; ?> 单 ¥<?php echo e(number_format((float)$detail['amount'], 2)); ?></span><a href="<?php echo BASE_URL; ?>/project/batch_review.php">← 返回列表</a></div>
    <?php if ($detail['note'] !== ''): ?><div class="card-body pb-0 small"><b>申请说明：</b><?php echo e($detail['note']); ?></div><?php endif; ?>
    <form method="post"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="id" value="<?php echo (int)$detail['id']; ?>"><input type="hidden" name="month" value="<?php echo e($detail['month']); ?>">
    <div class="table-responsive"><table class="table table-hover mb-0"><thead class="thead-light"><tr><th style="width:36px"><input type="checkbox" id="pbrAll" checked aria-label="全选"></th><th>订单号 / 付款昵称</th><th>日期</th><th>业务</th><th class="text-right">售价</th><th>系统核对</th><th>卡在哪</th><th></th></tr></thead><tbody>
    <?php foreach ($detailOrders as $o): ?><tr class="<?php echo $o['exception'] ? 'table-warning' : ''; ?>">
      <td><input type="checkbox" name="ids[]" value="<?php echo (int)$o['id']; ?>" class="pbr-pick" <?php echo $o['exception'] ? '' : 'checked'; ?>></td>
      <td><b><?php echo e($o['order_no']); ?></b><div class="small text-muted"><?php echo e($o['nick'] ?: '—'); ?></div></td><td class="text-nowrap"><?php echo e($o['order_date']); ?></td>
      <td class="small"><?php echo e($o['project_type']); ?><div class="text-muted"><?php echo e($o['order_kind']); ?></div></td><td class="text-right">¥<?php echo e(number_format((float)$o['contract_amount'], 2)); ?></td>
      <td><?php echo pbr_state_badge($o); ?></td><td class="small"><?php echo $o['why'] ? e(implode('；', $o['why'])) : '—'; ?><?php echo $o['exception'] ? ' <b class="text-danger">需单独处理</b>' : ''; ?></td>
      <td class="text-nowrap"><a class="btn btn-sm btn-outline-secondary" target="_blank" href="<?php echo BASE_URL; ?>/project/order.php?id=<?php echo (int)$o['id']; ?>">打开</a></td></tr><?php endforeach; ?>
    <?php if (!$detailOrders): ?><tr><td colspan="8" class="text-center text-muted py-4">这个月的订单已经全部审核通过了</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php if ($detailOrders && $detail['status'] === 'pending'): ?><div class="card-body">
      <button name="do" value="approve" class="btn btn-success" onclick="return confirm('对勾选的订单：按售价登记收款、标记交付完成并审核生成分成。确认已抽查？')"><i class="fas fa-check-double mr-1"></i>勾选的全部通过</button>
      <span class="small text-muted ml-2">默认勾选没有异常的订单；不想通过的取消勾选即可。</span>
      <hr><div class="form-inline"><input name="note" class="form-control mr-2" style="min-width:260px" placeholder="退回原因（需要对方补充什么）"><button name="do" value="reject" class="btn btn-outline-danger">退回申请</button></div>
    </div><?php endif; ?></form></div>
  <script>document.getElementById('pbrAll')&&document.getElementById('pbrAll').addEventListener('change',function(){var on=this.checked;document.querySelectorAll('.pbr-pick').forEach(function(c){c.checked=on;});});</script>
<?php endif; ?>

<?php if ($isFinance): ?>
  <div class="card mb-4"><div class="card-header">全部申请</div><div class="table-responsive"><table class="table table-hover mb-0"><thead class="thead-light"><tr><th>申请人</th><th>月份</th><th>订单</th><th>说明</th><th>状态</th><th></th></tr></thead><tbody>
  <?php foreach ($list as $r): [$lt, $lc] = $st[$r['status']]; ?><tr><td><b><?php echo e($r['emp_name']); ?></b><div class="small text-muted"><?php echo e(substr($r['updated_at'], 0, 16)); ?></div></td><td><?php echo e($r['month']); ?></td>
    <td><?php echo (int)$r['order_count']; ?> 单 · ¥<?php echo e(number_format((float)$r['amount'], 2)); ?></td><td class="small"><?php echo e($r['note'] ?: '—'); ?></td>
    <td><span class="badge badge-<?php echo $lc; ?>"><?php echo $lt; ?></span><?php if ($r['handled_note']): ?><div class="small text-muted"><?php echo e($r['handled_note']); ?></div><?php endif; ?></td>
    <td class="text-right"><a class="btn btn-sm btn-<?php echo $r['status'] === 'pending' ? 'primary' : 'outline-secondary'; ?>" href="?id=<?php echo (int)$r['id']; ?>"><?php echo $r['status'] === 'pending' ? '去抽查审核' : '查看'; ?></a></td></tr><?php endforeach; ?>
  <?php if (!$list): ?><tr><td colspan="6" class="text-center text-muted py-5">还没有申请</td></tr><?php endif; ?></tbody></table></div></div>
<?php else: ?>
  <form method="get" class="form-inline mb-3"><label class="mr-2 font-weight-bold text-muted">月份</label><input type="month" name="month" value="<?php echo e($month); ?>" class="form-control mr-2"><button class="btn btn-outline-secondary">查看</button></form>
  <div class="card mb-4"><div class="card-header"><?php echo e($month); ?> 还没通过审核的订单：<?php echo count($mine); ?> 单 · 售价合计 ¥<?php echo e(number_format(array_sum(array_column($mine, 'contract_amount')), 2)); ?></div>
    <div class="table-responsive"><table class="table mb-0"><thead class="thead-light"><tr><th>订单号 / 付款昵称</th><th>日期</th><th class="text-right">售价</th><th>系统核对</th><th>卡在哪 / 怎么解决</th></tr></thead><tbody>
    <?php foreach ($mine as $o): ?><tr><td><b><?php echo e($o['order_no']); ?></b><div class="small text-muted"><?php echo e($o['nick'] ?: '—'); ?></div></td><td class="text-nowrap"><?php echo e($o['order_date']); ?></td>
      <td class="text-right">¥<?php echo e(number_format((float)$o['contract_amount'], 2)); ?></td><td><?php echo pbr_state_badge($o); ?></td>
      <td class="small"><?php echo $o['why'] ? e(implode('；', $o['why'])) : '—'; ?><?php if ($o['exception']): ?><div class="text-danger">这单有异常，财务会单独找你核对</div><?php endif; ?></td></tr><?php endforeach; ?>
    <?php if (!$mine): ?><tr><td colspan="5" class="text-center text-muted py-5"><i class="fas fa-check-circle text-success mr-1"></i>这个月的订单都已经审核通过了</td></tr><?php endif; ?></tbody></table></div>
    <?php if ($mine): ?><div class="card-body border-top"><?php if ($myOpen): ?><span class="badge badge-warning mr-2">已申请，待财务处理</span><span class="small text-muted"><?php echo e(substr($myOpen['updated_at'], 0, 16)); ?> · 订单有变化可以再点一次更新申请</span><?php endif; ?>
      <form method="post" class="form-inline mt-2"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="do" value="submit"><input type="hidden" name="month" value="<?php echo e($month); ?>">
        <input name="note" class="form-control mr-2" style="min-width:300px" maxlength="500" placeholder="给财务的说明（选填），如：这些都是已到账的订单"><button class="btn btn-success"><i class="fas fa-paper-plane mr-1"></i>申请财务批量审核</button></form>
      <p class="small text-muted mt-2 mb-0">提交后财务会在看板看到申请，抽查后一次性通过；通过后工资里的营业额和分成会自动重新计算。</p></div><?php endif; ?></div>
  <?php if ($list): ?><div class="card mb-4"><div class="card-header">我的申请记录</div><div class="table-responsive"><table class="table table-sm mb-0"><tbody><?php foreach ($list as $r): [$lt, $lc] = $st[$r['status']]; ?><tr><td><?php echo e($r['month']); ?></td><td><?php echo (int)$r['order_count']; ?> 单 · ¥<?php echo e(number_format((float)$r['amount'], 2)); ?></td><td><span class="badge badge-<?php echo $lc; ?>"><?php echo $lt; ?></span></td><td class="small text-muted"><?php echo e($r['handled_note'] ?: ''); ?></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>
<?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
