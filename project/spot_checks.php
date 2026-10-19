<?php
require_once __DIR__ . '/../includes/ProjectSpotCheck.php';
require_once __DIR__ . '/../includes/ProjectRefundMatch.php';
require_once __DIR__ . '/../includes/ProjectOrderSource.php';
// 财务抽查任务：免审业务自动结算的订单，每个业务每月随机抽 5 单，财务核对收款 / 成本 / 交付后标记结果。
$actor = ps_require_actor();
if (($actor['role'] ?? '') !== 'finance') { http_response_code(403); exit('无权限'); }
psc_ensure();
$error = ''; $success = '';
$month = (string)($_GET['month'] ?? $_POST['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)) $month = date('Y-m');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    try {
        psc_handle($actor, (int)($_POST['id'] ?? 0), (string)($_POST['do'] ?? ''), (string)($_POST['note'] ?? ''));
        $success = '已记录抽查结果。';
    } catch (Throwable $e) { $error = $e instanceof RuntimeException ? $e->getMessage() : '操作失败，请稍后再试'; }
}
$q = db()->prepare("SELECT c.*,o.order_no,o.order_date,o.contract_amount,o.receipt_amount,o.order_kind,s.payment_nickname nick,o.shop,o.project_type,rr.order_no refund_no,rr.refund_date,rr.amount refund_amount,rr.reason refund_reason,rr.payment_method refund_method,rr.payment_reference refund_ref,rr.source_payment_reference refund_src_ref,rr.source_sheet refund_sheet,rr.source_row refund_row,
    (SELECT COUNT(*) FROM project_orders o2 JOIN project_participants p2 ON p2.order_id=o2.id WHERE o2.id<>o.id AND o2.project_type=o.project_type AND o2.order_date=o.order_date AND o2.contract_amount=o.contract_amount AND EXISTS (SELECT 1 FROM project_participants p1 WHERE p1.order_id=o.id AND p1.employee_id=p2.employee_id)) same_day_n
    FROM project_spot_checks c LEFT JOIN project_orders o ON c.kind='order' AND o.id=c.order_id LEFT JOIN project_order_sources s ON s.order_id=o.id LEFT JOIN project_refund_import_rows rr ON c.kind='refund' AND rr.id=c.order_id
    WHERE c.month=? ORDER BY c.business,FIELD(c.status,'pending','problem','ok'),c.id");
$q->execute([$month]);
$tasks = [];
foreach ($q->fetchAll() as $t) {
    $t['flow'] = $t['kind'] === 'refund' ? prm_flow_entry($t['refund_no']) : null; // 退款没有项目订单：从店铺流水查店铺 / 买家 / 商品
    $t['now_order'] = null; // 退款自动结案后项目订单是否已补录（补录了应去订单里处理这笔退款）
    if ($t['kind'] === 'refund') { if (!function_exists('ps_refund_resolve_order')) require_once __DIR__ . '/../includes/ProjectRefundImport.php'; [$t['now_order']] = ps_refund_resolve_order($t['refund_no'], (string)$t['refund_src_ref']); }
    elseif ($t['order_no'] !== '') { try { foreach (ps_shop_order_lookup($t['order_no']) as $m) { if ($t['shop'] === '' || $m['shop'] === $t['shop']) { $t['flow'] = $m; break; } } } catch (Throwable $e) {} }
    $tasks[$t['business']][] = $t;
}
$st = ['pending' => ['待抽查', 'warning'], 'ok' => ['无误', 'success'], 'problem' => ['有问题', 'danger']];
$page_title = '财务抽查任务';
include __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid" style="max-width:1180px">
  <h2 class="h4 my-3"><i class="fas fa-search-dollar mr-2"></i>财务抽查任务</h2>
  <p class="text-muted small">免财务审核的业务由系统自动结算（按店铺实付，没有就按售价登记收款），每个业务每月随机抽 <?php echo PSC_PER_BUSINESS; ?> 单交给财务事后核对；系统自动结案的退款（项目里没有对应订单）同样每月抽 <?php echo PSC_PER_BUSINESS; ?> 条。打开核对收款、成本、交付，没问题点“无误”；发现问题写明情况，并在订单里调整。</p>
  <form class="form-inline mb-3" method="get"><input type="month" name="month" value="<?php echo e($month); ?>" class="form-control form-control-sm mr-2"><button class="btn btn-sm btn-outline-secondary">查看</button></form>
  <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
  <?php if (!$tasks): ?><div class="text-muted py-4 text-center">这个月还没有抽查任务（有订单自动结算后，系统下一轮核对时会自动抽取）。</div><?php endif; ?>
  <?php foreach ($tasks as $business => $rows): ?>
  <div class="card mb-3"><div class="card-header"><?php echo e($business); ?>（<?php echo count($rows); ?> 单）</div><?php if ($business === PSC_REFUND_BUSINESS): ?>
    <div class="card-body py-2 small text-muted" style="line-height:1.7"><b class="text-dark">为什么这里看不到业务？</b>业务只记在“项目订单”上；这些退款对应的订单客服没有在 14 天内录进项目，系统自动按“未报单”结案，所以没有业务和负责人，只能看店铺流水的商品标题大致判断。<br><b class="text-dark">怎么避免：</b>①客服成交当天就把订单录入项目（报单）；②客户付款后又取消的，也要在项目里登记或在退款页标记“未报单”；③每周看一次退款页“还没录成项目订单的退款”，按店铺催对应客服补录——14 天内补录，退款会自动对上订单、按业务扣分成，就不会进到这里；④抽查时若发现该单其实有业务、应有分成，点“有问题”并写明，再到订单里补录。</div><?php endif; ?>
    <div class="table-responsive"><table class="table table-sm mb-0"><thead class="thead-light"><tr><th>订单号 / 付款昵称</th><th>日期</th><th class="text-right">售价</th><th class="text-right">已确认收款</th><th>状态</th><th style="min-width:300px">处理</th></tr></thead><tbody>
    <?php foreach ($rows as $t): [$label, $tone] = $st[$t['status']]; $isRefund = $t['kind'] === 'refund'; ?><tr>
      <?php if ($isRefund): ?>
      <td><b><?php echo e($t['refund_no']); ?></b><?php $f = $t['flow']; ?>
        <div class="small"><?php echo $f ? '店铺：' . e($f['shop']) . ($f['nickname'] !== '' ? ' · 买家：' . e($f['nickname']) : '') . ($f['title'] !== '' ? '<br>商品：' . e(mb_substr($f['title'], 0, 60)) : '') : '<span class="text-danger">店铺流水里查不到这笔订单，店铺未知</span>'; ?></div>
        <div class="small">业务：<span class="text-muted">项目里没有订单，系统未记录<?php $cat = $f ? prm_title_category($f['title']) : ''; echo $cat !== '' ? '；按商品标题推测：' . e($cat) : '；商品标题也判断不了'; ?></span></div>
        <?php if ($f): ?><div class="small text-muted">店铺流水：下单 <?php echo e($f['date']); ?> · <?php echo $f['price'] !== null ? '订单金额 ¥' . money($f['price']) : '仅有退款记录'; ?> · <?php echo e($f['status'] ?: '状态未知'); ?><?php if ($f['refund_amount'] > 0): ?> · 流水退款 ¥<?php echo money($f['refund_amount']); ?><?php endif; ?></div><?php endif; ?>
        <div class="small text-muted">退款表：<?php echo e($t['refund_method'] ?: '—'); ?> · 退款流水 <?php echo e($t['refund_ref'] ?: '无'); ?> · 原支付流水 <?php echo e($t['refund_src_ref'] ?: '无'); ?> · <?php echo e($t['refund_sheet'] ? $t['refund_sheet'] . ' 第' . (int)$t['refund_row'] . '行' : '手动录入'); ?></div>
        <?php if ($t['now_order']): ?><div class="small"><span class="badge badge-danger">项目里已有这张订单</span> <a target="_blank" href="<?php echo BASE_URL; ?>/project/order.php?id=<?php echo (int)$t['now_order']['id']; ?>">打开订单</a>，请核对这笔退款是否已扣减；有问题点“有问题”。</div><?php else: ?><div class="small text-muted">核对：项目里目前仍没有这张订单，退款未扣分成。</div><?php endif; ?>
        <div class="small text-muted" style="max-width:340px"><?php echo e(mb_substr((string)$t['refund_reason'], 0, 120)); ?></div></td>
      <td class="text-nowrap"><?php echo e($t['refund_date']); ?></td><td class="text-right">退款 ¥<?php echo money($t['refund_amount']); ?></td><td class="text-right small text-muted">系统自动结案<br>无项目订单</td>
      <?php else: ?>
      <td><a target="_blank" href="<?php echo BASE_URL; ?>/project/order.php?id=<?php echo (int)$t['order_id']; ?>"><b><?php echo e($t['order_no']); ?></b></a> <a target="_blank" href="<?php echo BASE_URL; ?>/project/order.php?id=<?php echo (int)$t['order_id']; ?>">打开</a><div class="small">店铺：<?php echo e($t['shop'] ?: '—'); ?> · 业务：<?php echo e($t['project_type'] ?: $business); ?></div><div class="small text-muted"><?php echo e($t['nick'] ?: '—'); ?></div><?php if ((int)($t['same_day_n'] ?? 0) > 0): ?><div class="small text-danger">疑似重复：同一人同日同金额还有 <?php echo (int)$t['same_day_n']; ?> 单，请核对是否重复导入</div><?php endif; ?><?php if ($t['flow']): ?><div class="small text-muted">店铺流水：<?php echo $t['flow']['price'] !== null ? '¥' . money($t['flow']['price']) : '仅退款记录'; ?> · <?php echo e($t['flow']['status'] ?: '状态未知'); ?></div><?php elseif (preg_match('/^\d{16,19}$/', (string)$t['order_no'])): ?><div class="small text-danger">店铺流水里查不到该单，请核对</div><?php else: ?><div class="small text-muted">非店铺订单，无店铺流水</div><?php endif; ?></td>
      <td class="text-nowrap"><?php echo e($t['order_date']); ?></td><td class="text-right">¥<?php echo money($t['contract_amount']); ?></td><td class="text-right">¥<?php echo money($t['receipt_amount']); ?></td>
      <?php endif; ?>
      <td><span class="badge badge-<?php echo e($tone); ?>"><?php echo e($label); ?></span><?php if ($t['note'] !== ''): ?><div class="small text-muted"><?php echo e($t['note']); ?></div><?php endif; ?></td>
      <td><?php if ($t['status'] === 'pending'): ?><form method="post" class="form-inline"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>"><input type="hidden" name="month" value="<?php echo e($month); ?>">
        <input name="note" class="form-control form-control-sm mr-1" placeholder="有问题时写明情况" maxlength="500">
        <button name="do" value="ok" class="btn btn-sm btn-success mr-1">无误</button><button name="do" value="problem" class="btn btn-sm btn-outline-danger">有问题</button></form><?php else: ?><span class="small text-muted"><?php echo e($t['handled_by']); ?> · <?php echo e($t['handled_at']); ?></span><?php endif; ?></td>
    </tr><?php endforeach; ?>
    </tbody></table></div></div>
  <?php endforeach; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
