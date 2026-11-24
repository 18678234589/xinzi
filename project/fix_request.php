<?php
require_once __DIR__ . '/../includes/ProjectFixRequest.php';
// 合作人员：在某张订单上申请分成更正（填正确的本单分成 / 其他项 + 原因），提交后由财务在“分成更正申请”里处理。
$actor = ps_require_actor();
pfr_ensure();
$isFinance = ($actor['role'] ?? '') === 'finance';
$orderId = (int)($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
$employeeId = (int)($_GET['employee_id'] ?? $_POST['employee_id'] ?? ($actor['employee_id'] ?? 0));
$month = (string)($_GET['month'] ?? $_POST['month'] ?? date('Y-m', strtotime('first day of last month')));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = date('Y-m');
$q = db()->prepare('SELECT * FROM project_orders WHERE id=?'); $q->execute([$orderId]); $order = $q->fetch();
if (!$order || !pfr_can_submit($actor, $orderId, $employeeId)) { http_response_code(403); exit('无权限：只能为自己参与的订单申请更正'); }
$eq = db()->prepare('SELECT name,department FROM employees WHERE id=?'); $eq->execute([$employeeId]); $emp = $eq->fetch();
$error = ''; $success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    try {
        pfr_submit($actor, $orderId, $employeeId, $month, $_POST['claimed'] ?? '', $_POST['extra_label'] ?? '', $_POST['extra_amount'] ?? '', $_POST['reason'] ?? '');
        $success = '已提交，财务处理后会发站内信通知你。';
    } catch (Throwable $e) { $error = $e instanceof RuntimeException ? $e->getMessage() : '提交失败，请稍后再试'; }
}
$snaps = db()->prepare('SELECT * FROM project_commission_snapshots WHERE order_id=? AND employee_id=? ORDER BY id'); $snaps->execute([$orderId, $employeeId]); $snaps = $snaps->fetchAll();
$adjs = db()->prepare('SELECT * FROM project_commission_adjustments WHERE order_id=? AND employee_id=? ORDER BY id'); $adjs->execute([$orderId, $employeeId]); $adjs = $adjs->fetchAll();
$estimate = [];
if (!$snaps) {
    $sum = ps_summary($order, ps_costs($orderId), ps_participants($orderId));
    foreach ($sum['groups'] as $g => $grp) foreach ($grp['people'] as $p) if ((int)$p['employee_id'] === $employeeId && !empty($p['estimated_calc'])) $estimate[] = [$g, $p['role_name'], $p['estimated_calc']];
}
$history = db()->prepare('SELECT * FROM project_fix_requests WHERE order_id=? AND employee_id=? ORDER BY id DESC LIMIT 5'); $history->execute([$orderId, $employeeId]); $history = $history->fetchAll();
$stLabel = ['pending' => ['待财务处理', 'warning'], 'approved' => ['已通过', 'success'], 'rejected' => ['已驳回', 'danger']];
$page_title = '申请分成更正';
include __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid" style="max-width:980px">
  <div style="background:linear-gradient(135deg,#f3f6f4,#fff);border:1px solid #dfe7e2;border-radius:18px;padding:18px 24px;margin:14px 0">
    <h2 style="margin:0 0 6px;font-size:1.3rem;font-weight:700;color:#1f3a2c"><i class="fas fa-edit mr-2"></i>申请分成更正</h2>
    <p style="margin:0;color:#5d6f64;font-size:.9rem;line-height:1.7">觉得这张订单的分成算得不对？在下面填你认为<b>正确的本单分成</b>和 / 或<b>其他项</b>（补助、扣款），写明原因，财务核对后通过，系统会自动记一笔调整，不会直接改原来的数字。</p>
  </div>
  <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?> <a href="<?php echo BASE_URL; ?>/project/fix_requests.php">查看我的申请</a></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
  <div class="card mb-3"><div class="card-header"><b><?php echo e($order['order_no']); ?></b> · <?php echo e($emp['name'] ?? ''); ?> · <?php echo e($month); ?></div>
    <div class="card-body small">
      <div class="mb-2">订单日期 <?php echo e($order['order_date']); ?> ｜ 业务 <?php echo e($order['project_type']); ?><?php echo $order['order_kind'] !== '' ? ' / ' . e($order['order_kind']) : ''; ?> ｜ 售价 ¥<?php echo e(number_format((float)$order['contract_amount'], 2)); ?> ｜ 实收 ¥<?php echo e(number_format((float)$order['receipt_amount'], 2)); ?> ｜ 退款 ¥<?php echo e(number_format((float)$order['refund_amount'], 2)); ?> ｜ 状态 <?php echo e(['draft' => '草稿', 'review' => '待审', 'approved' => '已审核', 'locked' => '已锁定'][$order['settlement_status']] ?? $order['settlement_status']); ?></div>
      <?php if ($snaps): ?><div class="font-weight-bold mb-1">系统当前这样算的（审核时的快照）：</div>
        <div class="table-responsive"><table class="table table-sm table-bordered mb-2"><thead><tr><th>岗位</th><th class="text-right">收入</th><th class="text-right">成本</th><th class="text-right">服务费</th><th class="text-right">计提基数</th><th class="text-right">比例</th><th class="text-right">权重</th><th class="text-right">本人分成</th></tr></thead><tbody>
        <?php foreach ($snaps as $s): ?><tr><td><?php echo $s['commission_group'] === 'technical' ? '技术' : '客服'; ?> <?php echo e($s['role_name'] ?? ''); ?></td><td class="text-right">¥<?php echo e(number_format((float)$s['income_amount'], 2)); ?></td><td class="text-right">¥<?php echo e(number_format((float)$s['direct_cost'], 2)); ?></td><td class="text-right">¥<?php echo e(number_format((float)($s['service_fee'] ?? 0), 2)); ?></td><td class="text-right">¥<?php echo e(number_format((float)$s['contribution_profit'], 2)); ?></td><td class="text-right"><?php echo e(rtrim(rtrim(number_format((float)$s['rate'] * 100, 2), '0'), '.')); ?>%</td><td class="text-right"><?php echo e(rtrim(rtrim(number_format((float)$s['group_weight'] * 100, 2), '0'), '.')); ?>%</td><td class="text-right font-weight-bold">¥<?php echo e(number_format((float)$s['commission_amount'], 2)); ?><?php if ((float)($s['subsidy_amount'] ?? 0) > 0): ?><div class="text-muted font-weight-normal">含补助 ¥<?php echo e(number_format((float)$s['subsidy_amount'], 2)); ?></div><?php endif; ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php foreach ($adjs as $a): ?><div class="text-muted">已有调整：<?php echo e($a['payroll_month']); ?> ¥<?php echo e(number_format((float)$a['amount'], 2)); ?>（<?php echo e(mb_substr((string)$a['reason'], 0, 60)); ?>）</div><?php endforeach; ?>
      <?php elseif ($estimate): ?><div class="font-weight-bold mb-1">订单还没审核，下面是系统预计：</div>
        <?php foreach ($estimate as [$g, $role, $c]): ?><div><?php echo $g === 'technical' ? '技术' : '客服'; ?> <?php echo e($role); ?>：<?php echo e($c['note'] ?? ''); ?> ＝ <b>¥<?php echo e(number_format((float)$c['share'] + (float)$c['subsidy'], 2)); ?></b></div><?php endforeach; ?>
      <?php else: ?><div class="text-muted">这张订单暂时没有算出你的分成。</div><?php endif; ?>
    </div></div>
  <form method="post" class="card mb-3"><div class="card-body">
    <input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="order_id" value="<?php echo $orderId; ?>"><input type="hidden" name="employee_id" value="<?php echo $employeeId; ?>"><input type="hidden" name="month" value="<?php echo e($month); ?>">
    <div class="form-row">
      <div class="form-group col-md-4"><label class="font-weight-bold">正确的本单分成（元）</label><input name="claimed" class="form-control" inputmode="decimal" placeholder="<?php echo $snaps ? '如 10.24（含补助）' : '订单未审核，不能改金额'; ?>" <?php echo $snaps ? '' : 'disabled'; ?> value="<?php echo e($_POST['claimed'] ?? ''); ?>"><small class="text-muted">指这张订单你应得的总额；不改金额就留空。</small></div>
      <div class="form-group col-md-5"><label class="font-weight-bold">其他项名称</label><input name="extra_label" class="form-control" maxlength="60" placeholder="如：定制补助 / 补单提成 / 扣款" value="<?php echo e($_POST['extra_label'] ?? ''); ?>"></div>
      <div class="form-group col-md-3"><label class="font-weight-bold">其他项金额（元）</label><input name="extra_amount" class="form-control" inputmode="decimal" placeholder="补助填正数，扣款填负数" value="<?php echo e($_POST['extra_amount'] ?? ''); ?>"></div>
    </div>
    <div class="form-group"><label class="font-weight-bold">原因 / 怎么算的（必填）</label><textarea name="reason" class="form-control" rows="3" maxlength="600" placeholder="如：网站客服成本按售价 65%、服务费 3%，(400 − 260 − 12) × 8% = 10.24，系统按 10% 算多了"><?php echo e($_POST['reason'] ?? ''); ?></textarea></div>
    <button class="btn btn-success"><i class="fas fa-paper-plane mr-1"></i>提交给财务</button> <a class="btn btn-link" href="<?php echo BASE_URL; ?>/project/payroll.php?month=<?php echo e($month); ?>">返回</a>
  </div></form>
  <?php if ($history): ?><div class="card mb-4"><div class="card-header">这张订单的更正申请记录</div><div class="table-responsive"><table class="table table-sm mb-0"><tbody>
    <?php foreach ($history as $h): [$hl, $hc] = $stLabel[$h['status']]; ?><tr><td class="text-nowrap"><?php echo e(substr($h['created_at'], 0, 16)); ?></td><td><?php echo $h['claimed_amount'] !== null ? '本单分成 ¥' . e(number_format((float)$h['claimed_amount'], 2)) : ''; ?> <?php echo $h['extra_amount'] !== null ? e($h['extra_label']) . ' ¥' . e(number_format((float)$h['extra_amount'], 2)) : ''; ?></td><td class="small"><?php echo e($h['reason']); ?></td><td><span class="badge badge-<?php echo $hc; ?>"><?php echo $hl; ?></span><?php if ($h['handled_note']): ?><div class="small text-muted"><?php echo e($h['handled_note']); ?></div><?php endif; ?></td></tr><?php endforeach; ?>
  </tbody></table></div></div><?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
