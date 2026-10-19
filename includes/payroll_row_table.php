<?php
// 看板“整月结算申请”下的报酬行：表头与首页“合作人员项目报酬表”完全一致（pps_heads），数字来自同一份 pps_sheet。
// 调用方提供 $payrollRow（该人的 pps_sheet 行）、$payrollRequestEmployee、$payrollRequestMonth。
// 已申请 / 已结算的金额（pspr_month 的 claimed）自动从应结算里扣除，余额 = 本次可申请，和申请按钮上的金额一致。
$prtState = pspr_status($payrollRequestEmployee, $payrollRequestMonth);
$prtClaimed = (float)$prtState['claimed'];
$prtNet = (float)$payrollRow['net'];
?>
<div class="pps-wrap pps-mini"><table class="pps-table">
  <thead><tr><th class="pps-sticky">姓名</th><th>部门</th><?php foreach (pps_heads() as [$k, $label]): ?><th class="pps-n<?php echo in_array($k, ['payable', 'net'], true) ? ' pps-key' : ''; ?>"><?php echo e($label); ?></th><?php endforeach; ?><th class="pps-note">备注</th></tr></thead>
  <tbody><tr>
    <td class="pps-sticky"><?php echo e($payrollRow['name'] ?? ''); ?></td><td class="pps-dept"><?php echo e($payrollRow['department'] ?? ''); ?></td>
    <td class="pps-n"><?php echo ($payrollRow['days'] ?? null) === null ? '<span class="pps-zero">—</span>' : rtrim(rtrim(number_format($payrollRow['days'], 2), '0'), '.') . (($payrollRow['ot_days'] ?? 0) > 0 ? '<small class="pps-ot"> +' . rtrim(rtrim(number_format($payrollRow['ot_days'], 2), '0'), '.') . '</small>' : ''); ?></td>
<?php foreach (pps_heads() as [$k, $label]): if ($k === 'days') continue;
    echo '    ', pps_cell($payrollRow[$k], $k === 'payable' ? 'pps-key' : ($k === 'net' ? 'pps-key pps-net' : (in_array($k, ['deduct', 'parking', 'reserve', 'unit_ins', 'personal_ins'], true) ? 'pps-cut' : ''))), "\n"; endforeach; ?>
    <td class="pps-note" title="<?php echo e($payrollRow['note'] ?? ''); ?>"><?php echo e($payrollRow['note'] ?? ''); ?></td>
  </tr></tbody>
</table></div>
<div class="pps-recon">
  <span>应结算 <b>¥<?php echo number_format((float)$payrollRow['payable'], 2); ?></b></span><i>−</i>
  <span>扣减 <b>¥<?php echo number_format((float)$payrollRow['payable'] - $prtNet, 2); ?></b></span><i>＝</i>
  <span>实结算 <b>¥<?php echo number_format($prtNet, 2); ?></b></span>
  <?php if ($prtClaimed != 0): ?><i>−</i><span class="is-claimed">已申请 / 已结算 <b>¥<?php echo number_format($prtClaimed, 2); ?></b></span><?php endif; ?><i>＝</i>
  <span class="is-left">本次可申请 <b>¥<?php echo number_format((float)$prtState['amount'], 2); ?></b></span>
</div>
