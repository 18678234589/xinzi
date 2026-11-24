<?php
require_once __DIR__ . '/../includes/ProjectRefundRate.php';
require_once __DIR__ . '/../includes/ProjectPartnerDashboard.php';
// 退款率排行榜：财务、管理员、管理角色可看；默认开发定制类业务、两个月前的月份。
$actor = ps_require_actor();
if (($actor['role'] ?? '') !== 'finance' && !ps_is_management($actor)) { http_response_code(403); exit('此页面仅财务和管理人员可访问'); }
$month = (string)($_GET['month'] ?? '');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = prr_default_month();
$business = isset($_GET['business']) ? (string)$_GET['business'] : PRR_DEV_GROUP;
$names = array_keys(ps_business_catalog());
$options = [PRR_DEV_GROUP => PRR_DEV_GROUP . '（小程序开发 / AI网站定制 / 网站定制）', '' => '全部业务'] + array_combine($names, $names);
if (!isset($options[$business])) $business = PRR_DEV_GROUP;
$rows = prr_rank($month, $business);
$tot = ['orders' => 0, 'refund_orders' => 0, 'amount' => 0.0, 'refund' => 0.0];
foreach ($rows as $r) { $tot['orders'] += $r['orders']; $tot['refund_orders'] += $r['refund_orders']; $tot['amount'] += $r['amount']; $tot['refund'] += $r['refund']; }
$pct = function ($v) { return number_format($v * 100, 1) . '%'; };
$prev = date('Y-m', strtotime($month . '-01 -1 month')); $next = date('Y-m', strtotime($month . '-01 +1 month'));
$self = BASE_URL . '/project/refund_rate.php';
$link = function ($m) use ($self, $business) { return $self . '?' . http_build_query(['month' => $m, 'business' => $business]); };
include __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid py-3">
  <h4 class="mb-1"><i class="fas fa-undo-alt"></i> 退款率排行榜</h4>
  <p class="text-muted small mb-3">按订单日期所在月份统计，退款有滞后，默认显示两个月前的月份。退款率 = 退款金额 ÷ 售价合计（排名依据）；订单退款率 = 有退款的订单数 ÷ 订单数。退款不限发生月份：9 月的订单，10 月、11 月才退的款也计入。“待核实退款”是已提交、财务还没核实的退款，暂不计入退款率。订单数不足 <?php echo PRR_MIN_ORDERS; ?> 单的排在最后，仅供参考。</p>
  <form method="get" class="form-inline mb-3">
    <a class="btn btn-sm btn-outline-secondary mr-2" href="<?php echo e($link($prev)); ?>">‹ 上月</a>
    <input type="month" class="form-control form-control-sm mr-2" name="month" value="<?php echo e($month); ?>">
    <select name="business" class="form-control form-control-sm mr-2"><?php foreach ($options as $k => $label): ?><option value="<?php echo e($k); ?>" <?php echo (string)$k === $business ? 'selected' : ''; ?>><?php echo e($label); ?></option><?php endforeach; ?></select>
    <button class="btn btn-sm btn-primary mr-2">查询</button>
    <a class="btn btn-sm btn-outline-secondary" href="<?php echo e($link($next)); ?>">下月 ›</a>
  </form>
  <div class="mb-3 small">合计：<strong><?php echo (int)$tot['orders']; ?></strong> 单，有退款 <strong><?php echo (int)$tot['refund_orders']; ?></strong> 单，售价合计 ¥<?php echo number_format($tot['amount'], 2); ?>，退款 ¥<?php echo number_format($tot['refund'], 2); ?>，整体退款率 <strong><?php echo $tot['amount'] > 0 ? $pct($tot['refund'] / $tot['amount']) : '—'; ?></strong>（同一张订单有多人参与时，每人各算一次，合计会重复）</div>
  <div class="table-responsive"><table class="table table-sm table-hover">
    <thead><tr><th>排名</th><th>合作人员</th><th>部门</th><th class="text-right">订单数</th><th class="text-right">有退款订单</th><th class="text-right">订单退款率</th><th class="text-right">售价合计</th><th class="text-right">退款金额</th><th class="text-right">退款率</th><th class="text-right">待核实退款</th></tr></thead>
    <tbody>
    <?php $rank = 0; foreach ($rows as $r): if (!$r['small']) $rank++; ?>
      <tr<?php echo $r['small'] ? ' class="text-muted"' : ''; ?>><td><?php echo $r['small'] ? '—' : $rank; ?></td>
        <td><a href="<?php echo e(ps_partner_orders_url((int)$r['employee_id'], $month, ($business === PRR_DEV_GROUP || $business === '') ? '' : $business)); ?>"><?php echo e($r['name']); ?></a><?php echo $r['small'] ? ' <small>单量少</small>' : ''; ?></td>
        <td><?php echo e($r['department']); ?></td><td class="text-right"><?php echo (int)$r['orders']; ?></td><td class="text-right"><?php echo (int)$r['refund_orders']; ?></td>
        <td class="text-right"><?php echo $pct($r['order_rate']); ?></td><td class="text-right">¥<?php echo number_format($r['amount'], 2); ?></td><td class="text-right">¥<?php echo number_format($r['refund'], 2); ?></td>
        <td class="text-right"><strong><?php echo $pct($r['amount_rate']); ?></strong></td><td class="text-right"><?php echo $r['pending'] > 0.004 ? '¥' . number_format($r['pending'], 2) . '（' . (int)$r['pending_orders'] . ' 单）' : '—'; ?></td></tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="10" class="text-center text-muted">这个月份没有符合条件的订单</td></tr><?php endif; ?>
    </tbody></table></div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
