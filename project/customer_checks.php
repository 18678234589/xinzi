<?php
require_once __DIR__ . '/../includes/ProjectMonthly.php';
require_once __DIR__ . '/../includes/ProjectUniqueCustomer.php';
// 单量补助待核对：按旺旺去重的单量补助里，系统拿不准的旺旺（订单部分退款、旺旺与另一个很像）由财务标记“可以结算”或“无需结算”。
$actor = ps_require_actor();
if (($actor['role'] ?? '') !== 'finance') { http_response_code(403); exit('无权限'); }
$error = ''; $success = '';
$month = (string)($_GET['month'] ?? $_POST['month'] ?? date('Y-m', strtotime('first day of last month')));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)) $month = date('Y-m');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    try {
        puc_handle($actor, (int)($_POST['id'] ?? 0), (string)($_POST['do'] ?? ''), (string)($_POST['note'] ?? ''));
        $success = '已记录，工资页会按结果重新计算。';
    } catch (Throwable $e) { $error = $e instanceof RuntimeException ? $e->getMessage() : '操作失败，请稍后再试'; }
}
try { puc_sync($month); } catch (Throwable $e) { error_log('puc_sync ' . $e->getMessage()); }
$q = db()->prepare('SELECT c.*,e.name emp_name FROM project_unique_customer_checks c LEFT JOIN employees e ON e.id=c.employee_id WHERE c.month=? ORDER BY FIELD(c.status,\'pending\',\'pay\',\'skip\'),c.employee_id,c.id');
$q->execute([$month]);
$rows = $q->fetchAll();
$page_title = '单量补助待核对';
include __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid" style="max-width:1180px">
  <h2 class="h4 my-3"><i class="fas fa-user-check mr-2"></i>单量补助待核对</h2>
  <p class="text-muted small">单量补助按客户旺旺去重（同一旺旺下两单、三单只算 1 单）。下面是系统拿不准的旺旺：订单有部分退款，或旺旺和另一个很像、可能是同一个客户。请核对后标记：<b>可以结算</b>（计入补助）或 <b>无需结算</b>（不计）；未标记的暂不计入。</p>
  <form class="form-inline mb-3" method="get"><input type="month" name="month" value="<?php echo e($month); ?>" class="form-control form-control-sm mr-2"><button class="btn btn-sm btn-outline-secondary">查看</button></form>
  <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
  <div class="card mb-3"><div class="table-responsive"><table class="table table-sm mb-0"><thead class="thead-light"><tr><th>人员</th><th>客户旺旺</th><th>为什么要核对</th><th>状态</th><th style="min-width:340px">处理</th></tr></thead><tbody>
  <?php foreach ($rows as $r): [$label, $tone] = PUC_STATUS[$r['status']]; ?><tr>
    <td><?php echo e($r['emp_name']); ?></td><td><b><?php echo e($r['nick_text']); ?></b></td><td class="small"><?php echo e($r['reason']); ?></td>
    <td><span class="badge badge-<?php echo e($tone); ?>"><?php echo e($label); ?></span><?php if ($r['status'] !== 'pending'): ?><div class="small text-muted"><?php echo e($r['handled_by']); ?> · <?php echo e($r['handled_at']); ?><?php echo $r['note'] !== '' ? ' · ' . e($r['note']) : ''; ?></div><?php endif; ?></td>
    <td><form method="post" class="form-inline"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>"><input type="hidden" name="month" value="<?php echo e($month); ?>">
      <input name="note" class="form-control form-control-sm mr-1" placeholder="备注（选填）" maxlength="500" style="width:130px">
      <button name="do" value="pay" class="btn btn-sm btn-success mr-1"<?php echo $r['status'] === 'pay' ? ' disabled' : ''; ?>>已完成，可以结算</button><button name="do" value="skip" class="btn btn-sm btn-outline-secondary"<?php echo $r['status'] === 'skip' ? ' disabled' : ''; ?>>无需结算</button></form></td>
  </tr><?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="5" class="text-center text-muted py-4">这个月没有需要核对的旺旺。</td></tr><?php endif; ?>
  </tbody></table></div></div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
