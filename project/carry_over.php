<?php
require_once __DIR__ . '/../includes/ProjectSettlement.php';
require_once __DIR__ . '/../includes/ProjectPayrollSheet.php';
// 财务：往月订单改记入本月。往月订单当时被批量审核通过、实际没做完 / 没到账，本月才完成，工资表按本月算：勾选这些订单，把这个人的分成从往月挪到本月。
$actor = ps_require_actor();
if (($actor['role'] ?? '') !== 'finance') { http_response_code(403); exit('仅财务可操作'); }
$to = (string)($_GET['to'] ?? $_POST['to'] ?? pps_default_month());
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $to)) $to = pps_default_month();
$from = (string)($_GET['from'] ?? $_POST['from'] ?? date('Y-m', strtotime($to . '-01 -1 month')));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $from) || $from >= $to) $from = date('Y-m', strtotime($to . '-01 -1 month'));
$empId = (int)($_GET['emp'] ?? $_POST['emp'] ?? 0);
$error = ''; $success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    try {
        $ids = array_values(array_unique(array_map('intval', (array)($_POST['ids'] ?? []))));
        if ($empId <= 0 || !$ids) throw new RuntimeException('请先选人并勾选订单');
        $pdo = db(); $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT status FROM project_payroll_periods WHERE period=? FOR UPDATE'); $st->execute([$to]);
        if ($st->fetchColumn() === 'locked') throw new RuntimeException($to . ' 已锁定，不能改记入');
        $pdo->prepare('INSERT INTO project_payroll_periods (period) VALUES (?) ON DUPLICATE KEY UPDATE period=period')->execute([$to]);
        $in = implode(',', $ids);
        $n1 = $pdo->exec("UPDATE project_commission_snapshots SET payroll_month=" . $pdo->quote($to) . " WHERE employee_id=$empId AND payroll_month=" . $pdo->quote($from) . " AND order_id IN ($in)");
        $n2 = $pdo->exec("UPDATE project_commission_adjustments SET payroll_month=" . $pdo->quote($to) . " WHERE employee_id=$empId AND payroll_month=" . $pdo->quote($from) . " AND order_id IN ($in)");
        ps_audit('payroll_month_move', $empId, 'carry_over', $actor, ['from' => $from, 'to' => $to, 'orders' => $ids, 'snapshots' => $n1, 'adjustments' => $n2]);
        $pdo->commit();
        $success = '已把 ' . count($ids) . ' 张订单（分成 ' . $n1 . ' 条' . ($n2 ? '、调整 ' . $n2 . ' 条' : '') . '）从 ' . $from . ' 改记入 ' . $to . '。';
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); $error = $e instanceof RuntimeException ? $e->getMessage() : '操作失败，请稍后再试'; }
}
$emps = db()->query("SELECT e.id,e.name,e.department FROM employees e WHERE EXISTS (SELECT 1 FROM project_commission_snapshots s WHERE s.employee_id=e.id AND s.payroll_month=" . db()->quote($from) . ") ORDER BY e.name")->fetchAll();
$list = [];
if ($empId > 0) {
    $q = db()->prepare("SELECT o.id,o.order_no,o.order_date,o.project_type,o.order_kind,o.contract_amount,o.receipt_amount,MAX(s.created_at) approved_at,SUM(s.commission_amount) comm,GROUP_CONCAT(DISTINCT s.role_name) roles FROM project_commission_snapshots s JOIN project_orders o ON o.id=s.order_id WHERE s.employee_id=? AND s.payroll_month=? GROUP BY o.id ORDER BY o.contract_amount DESC,o.id");
    $q->execute([$empId, $from]); $list = $q->fetchAll();
    $audit = db()->prepare("SELECT details_json FROM project_audit_logs WHERE entity_type='order' AND entity_id=? AND action='auto_review_check' AND created_at<? ORDER BY id DESC LIMIT 1");
    foreach ($list as &$r) {
        $audit->execute([(int)$r['id'], $r['approved_at']]); $d = json_decode((string)$audit->fetchColumn(), true) ?: []; $r['flag'] = '';
        foreach (($d['reasons'] ?? []) as $x) { if (($x['code'] ?? '') === 'delivery') $r['flag'] = '审核前系统显示还没确认完成'; elseif (($x['code'] ?? '') === 'order_unshipped' && $r['flag'] === '') $r['flag'] = '审核前系统显示未发货 / 未到账'; elseif (($x['code'] ?? '') === 'receipt_missing' && $r['flag'] === '') $r['flag'] = '审核前还没有收款记录'; }
        $r['no_receipt'] = (float)$r['receipt_amount'] <= 0;
    }
    unset($r);
}
$page_title = '往月订单改记入本月';
include __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid" style="max-width:1180px">
  <div style="background:linear-gradient(135deg,#f3f6f4,#fff);border:1px solid #dfe7e2;border-radius:18px;padding:18px 24px;margin:14px 0">
    <h2 style="margin:0 0 6px;font-size:1.3rem;font-weight:700;color:#1f3a2c"><i class="fas fa-exchange-alt mr-2"></i>往月订单改记入本月</h2>
    <p style="margin:0;color:#5d6f64;font-size:.9rem;line-height:1.7">往月订单当时被批量审核通过，但实际没做完 / 没到账，<b>本月才完成</b>，工资表要按本月算：选人和月份，勾选这些订单，把这个人的这几张订单分成从往月挪到本月（只挪分成，不改订单本身）。系统会标出“审核前仍未确认完成 / 未发货 / 没有收款”的订单，帮你快速圈。</p>
  </div>
  <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
  <form method="get" class="form-inline mb-3"><label class="mr-2 small text-muted">从</label><input type="month" name="from" value="<?php echo e($from); ?>" class="form-control form-control-sm mr-2"><label class="mr-2 small text-muted">改记入</label><input type="month" name="to" value="<?php echo e($to); ?>" class="form-control form-control-sm mr-2">
    <select name="emp" class="form-control form-control-sm mr-2" onchange="this.form.submit()"><option value="">选择人员（<?php echo count($emps); ?> 人在 <?php echo e($from); ?> 有分成）</option><?php foreach ($emps as $em): ?><option value="<?php echo (int)$em['id']; ?>" <?php echo $empId === (int)$em['id'] ? 'selected' : ''; ?>><?php echo e($em['name'] . '（' . $em['department'] . '）'); ?></option><?php endforeach; ?></select><button class="btn btn-sm btn-outline-secondary">查看</button></form>
  <?php if ($empId > 0): ?>
  <form method="post" class="card mb-4"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="emp" value="<?php echo $empId; ?>"><input type="hidden" name="from" value="<?php echo e($from); ?>"><input type="hidden" name="to" value="<?php echo e($to); ?>">
    <div class="card-header"><?php echo count($list); ?> 张订单 · 分成合计 ¥<?php echo e(number_format(array_sum(array_column($list, 'comm')), 2)); ?> <span class="small text-muted">（勾选的会改记入 <?php echo e($to); ?>，已选 <b id="cpN">0</b> 张 · 分成 ¥<b id="cpS">0.00</b> · 售价 ¥<b id="cpP">0</b>）</span></div>
    <div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead class="thead-light"><tr><th style="width:34px"><input type="checkbox" id="cpAll" aria-label="全选"></th><th>订单</th><th>业务 · 类型 · 岗位</th><th class="text-right">售价</th><th class="text-right">实收</th><th class="text-right">他的分成</th><th>审核时间</th><th>系统提示</th></tr></thead><tbody>
    <?php foreach ($list as $r): ?><tr class="<?php echo $r['flag'] !== '' ? 'table-warning' : ''; ?>"><td><input type="checkbox" name="ids[]" value="<?php echo (int)$r['id']; ?>" class="cp-pick" data-comm="<?php echo e(round((float)$r['comm'], 2)); ?>" data-price="<?php echo e((float)$r['contract_amount']); ?>"></td>
      <td><a target="_blank" href="<?php echo BASE_URL; ?>/project/order.php?id=<?php echo (int)$r['id']; ?>"><?php echo e($r['order_no']); ?></a><div class="small text-muted"><?php echo e($r['order_date']); ?></div></td><td class="small"><?php echo e($r['project_type']); ?> · <?php echo e($r['order_kind'] ?: '未选类型'); ?><div class="text-muted"><?php echo e($r['roles']); ?></div></td>
      <td class="text-right">¥<?php echo e(number_format((float)$r['contract_amount'], 2)); ?></td><td class="text-right <?php echo $r['no_receipt'] ? 'text-danger' : ''; ?>">¥<?php echo e(number_format((float)$r['receipt_amount'], 2)); ?></td><td class="text-right font-weight-bold">¥<?php echo e(number_format((float)$r['comm'], 2)); ?></td>
      <td class="small text-nowrap"><?php echo e(substr((string)$r['approved_at'], 0, 16)); ?></td><td class="small"><?php echo e($r['flag'] ?: '—'); ?></td></tr><?php endforeach; ?>
    <?php if (!$list): ?><tr><td colspan="8" class="text-center text-muted py-4"><?php echo e($from); ?> 这个人没有分成</td></tr><?php endif; ?></tbody></table></div>
    <?php if ($list): ?><div class="card-body border-top"><button class="btn btn-success" onclick="return confirm('确认把勾选订单的分成改记入 <?php echo e($to); ?>？这个人 <?php echo e($from); ?> 的工资会相应减少，<?php echo e($to); ?> 增加。')"><i class="fas fa-exchange-alt mr-1"></i>勾选的订单改记入 <?php echo e($to); ?></button> <span class="small text-muted ml-2">只改分成记在哪个月；订单状态、金额都不变。已发放的月份请先和人确认。</span></div><?php endif; ?></form>
  <script>(function(){var all=document.getElementById('cpAll'),pk=[].slice.call(document.querySelectorAll('.cp-pick'));function sum(){var n=0,c=0,p=0;pk.forEach(function(x){if(x.checked){n++;c+=parseFloat(x.dataset.comm||0);p+=parseFloat(x.dataset.price||0);}});document.getElementById('cpN').textContent=n;document.getElementById('cpS').textContent=c.toFixed(2);document.getElementById('cpP').textContent=Math.round(p);}pk.forEach(function(x){x.addEventListener('change',sum);});if(all)all.addEventListener('change',function(){pk.forEach(function(x){x.checked=all.checked;});sum();});})();</script>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
