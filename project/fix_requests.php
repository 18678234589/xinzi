<?php
require_once __DIR__ . '/../includes/ProjectFixRequest.php';
// 分成更正申请列表：财务看全部并处理；合作人员只看自己的申请和处理结果。
$actor = ps_require_actor();
pfr_ensure();
$isFinance = ($actor['role'] ?? '') === 'finance';
if (!$isFinance && empty($actor['employee_id'])) { http_response_code(403); exit('无权限'); }
$error = ''; $success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isFinance) {
    ps_check_csrf();
    try { $success = '处理完成：' . pfr_handle($actor, (int)($_POST['id'] ?? 0), (string)($_POST['do'] ?? ''), (string)($_POST['note'] ?? '')); }
    catch (Throwable $e) { $error = $e instanceof RuntimeException ? $e->getMessage() : '处理失败，请稍后再试'; }
}
$sql = 'SELECT r.*,o.order_no,o.project_type,o.order_kind,o.contract_amount,e.name emp_name FROM project_fix_requests r JOIN project_orders o ON o.id=r.order_id JOIN employees e ON e.id=r.employee_id';
$params = [];
if (!$isFinance) { $sql .= ' WHERE r.employee_id=?'; $params[] = (int)$actor['employee_id']; }
$sql .= " ORDER BY FIELD(r.status,'pending','approved','rejected'), r.id DESC LIMIT 200";
$q = db()->prepare($sql); $q->execute($params); $list = $q->fetchAll();
$stLabel = ['pending' => ['待财务处理', 'warning'], 'approved' => ['已通过', 'success'], 'rejected' => ['已驳回', 'danger']];
$page_title = '分成更正申请';
include __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid" style="max-width:1180px">
  <div style="background:linear-gradient(135deg,#f3f6f4,#fff);border:1px solid #dfe7e2;border-radius:18px;padding:18px 24px;margin:14px 0">
    <h2 style="margin:0 0 6px;font-size:1.3rem;font-weight:700;color:#1f3a2c"><i class="fas fa-edit mr-2"></i>分成更正申请</h2>
    <p style="margin:0;color:#5d6f64;font-size:.9rem;line-height:1.7"><?php echo $isFinance ? '合作人员在“逐单项目分成明细”里点“申请更正”提交的申请。核对原因和金额后通过：系统自动记一笔分成调整（本单分成）和 / 或手工项（其他项），并站内信通知申请人；不对的写明原因驳回。' : '你提交的分成更正申请和财务的处理结果。要提新的申请，在“报酬结算 → 逐单项目分成明细”里点对应订单的“申请更正”。'; ?></p>
  </div>
  <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
  <div class="card mb-4"><div class="table-responsive"><table class="table table-hover mb-0"><thead class="thead-light"><tr><th>申请人 / 时间</th><th>订单</th><th class="text-right">系统已计</th><th>申请内容</th><th>原因</th><th>状态</th><?php if ($isFinance): ?><th style="min-width:230px">处理</th><?php endif; ?></tr></thead><tbody>
  <?php foreach ($list as $r): [$sl, $sc] = $stLabel[$r['status']]; ?><tr>
    <td><b><?php echo e($r['emp_name']); ?></b><div class="small text-muted"><?php echo e(substr($r['created_at'], 0, 16)); ?> · <?php echo e($r['payroll_month']); ?></div></td>
    <td class="small"><a target="_blank" href="<?php echo BASE_URL; ?>/project/order.php?id=<?php echo (int)$r['order_id']; ?>"><?php echo e($r['order_no']); ?></a><div class="text-muted"><?php echo e($r['project_type']); ?><?php echo $r['order_kind'] !== '' ? ' / ' . e($r['order_kind']) : ''; ?> · ¥<?php echo e(number_format((float)$r['contract_amount'], 2)); ?></div></td>
    <td class="text-right"><?php echo $r['claimed_amount'] !== null ? '¥' . e(number_format((float)$r['current_amount'], 2)) : '—'; ?></td>
    <td class="small"><?php if ($r['claimed_amount'] !== null): ?>本单分成 → <b>¥<?php echo e(number_format((float)$r['claimed_amount'], 2)); ?></b><?php $d = (float)$r['claimed_amount'] - (float)$r['current_amount']; ?>（<?php echo $d > 0 ? '+' : ''; ?><?php echo e(number_format($d, 2)); ?>）<br><?php endif; ?><?php if ($r['extra_amount'] !== null): ?>其他项“<?php echo e($r['extra_label']); ?>” <b><?php echo (float)$r['extra_amount'] > 0 ? '+' : ''; ?><?php echo e(number_format((float)$r['extra_amount'], 2)); ?></b><?php endif; ?></td>
    <td class="small" style="max-width:300px"><?php echo e($r['reason']); ?></td>
    <td><span class="badge badge-<?php echo $sc; ?>"><?php echo $sl; ?></span><?php if ($r['handled_note']): ?><div class="small text-muted"><?php echo e($r['handled_note']); ?></div><?php endif; ?></td>
    <?php if ($isFinance): ?><td><?php if ($r['status'] === 'pending'): ?><form method="post" class="form-inline" style="gap:4px"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>"><input name="note" class="form-control form-control-sm mb-1" style="width:100%" placeholder="备注（驳回必填）"><button name="do" value="approved" class="btn btn-sm btn-success mr-1" onclick="return confirm('通过后会自动记调整 / 手工项，确认？')">通过</button><button name="do" value="rejected" class="btn btn-sm btn-outline-danger">驳回</button></form><?php else: ?>—<?php endif; ?></td><?php endif; ?>
  </tr><?php endforeach; ?>
  <?php if (!$list): ?><tr><td colspan="7" class="text-center text-muted py-5">还没有更正申请</td></tr><?php endif; ?></tbody></table></div></div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
