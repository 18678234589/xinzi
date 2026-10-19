<?php
// 本人申请（含财务本人）；财务可将待处理申请同步为整月实结算。
$pr = pspr_status($payrollRequestEmployee, $payrollRequestMonth);
$prActor = ps_actor();
$prOwn = pspr_self_employee($prActor) === (int)$payrollRequestEmployee;
$prFinance = ($prActor['role'] ?? '') === 'finance';
$prProxy = $prFinance && !$prOwn && !empty($payrollRequestProxy);
$prSync = $prFinance && $pr['pending'] && (!$pr['request'] || $pr['request']['status'] !== 'pending' || count($pr['pending']) > 1 || abs((float)$pr['request']['amount'] - $pr['remaining']) > 0.004);
$prAmount = $pr['request'] ? (float)$pr['request']['amount'] : max(0, $pr['amount']);
if ($prSync) $prAmount = max(0, $pr['remaining']);
$prCan = ((($prOwn || $prProxy) && $pr['can_apply']) || $prSync) && $prAmount > 0;
?>
<form class="payroll-request-form" action="<?php echo e(BASE_URL . '/payroll_sheet.php'); ?>" method="post" data-month="<?php echo e($payrollRequestMonth); ?>" data-amount="<?php echo e(number_format($prAmount,2,'.','')); ?>">
  <input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="<?php echo $prSync ? 'sync_payroll' : 'request_payroll'; ?>"><input type="hidden" name="month" value="<?php echo e($payrollRequestMonth); ?>"><input type="hidden" name="employee_id" value="<?php echo (int)$payrollRequestEmployee; ?>">
  <input type="hidden" name="expected_amount" value="<?php echo e(number_format($prAmount,2,'.','')); ?>">
  <?php if ($prCan): ?><button type="submit"><?php echo e($prSync ? '同步整月实结算申请' : ($prProxy ? '代申请整月实结算' : $pr['label'])); ?> · ¥<?php echo number_format($prAmount,2); ?></button>
  <?php else: ?><strong class="payroll-request-state"><?php echo e(($prOwn || $pr['request']) ? $pr['label'] : '请由本人申请'); ?> · ¥<?php echo number_format($prAmount,2); ?></strong><?php endif; ?>
  <small>含固定服务费、全勤、提成和补助，减全部扣款<?php if ($prSync && $pr['paid'] != 0): ?>；已扣除已打款 ¥<?php echo number_format($pr['paid'],2); ?><?php elseif (!$prSync && $pr['claimed'] != 0): ?>；已扣除其他申请 ¥<?php echo number_format($pr['claimed'],2); ?><?php endif; ?>。</small>
  <?php if ($prSync): ?><small>合并该月待处理申请，按首页实结算减已打款更新；金额有变化时需重新核对，不会自动打款。</small>
  <?php elseif (!$prOwn && !$prProxy): ?><small>仅本人或财务可提交结算申请。</small><?php elseif ($pr['reason'] !== ''): ?><?php if ($prCan): ?><small>可先提交申请，财务核对及订单审核通过后方可打款。</small><?php endif; ?><details class="payroll-request-reason"><summary><?php echo $prCan ? '查看待核对事项' : '查看原因'; ?><?php echo $pr['pending_n'] > 0 ? ' · ' . $pr['pending_n'] . ' 单待审核' : ''; ?></summary><p><?php echo e($pr['reason']); ?></p></details><?php endif; ?>
  <p class="payroll-request-message" role="status" hidden></p>
</form>
