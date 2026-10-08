    <label class="small text-muted mb-1" for="month"><?php echo $dateBasis === 'created_at' ? '录入月份' : '订单月份'; ?></label>
    <div class="input-group">
      <input class="form-control" type="month" name="month" id="month" value="<?php echo e($month); ?>">
      <div class="input-group-append">
        <a class="btn btn-outline-secondary" href="?<?php echo http_build_query(array_merge($_GET, ['month' => date('Y-m', strtotime('first day of last month'))])); ?>" title="快速筛选上月">上月</a>
        <a class="btn btn-outline-secondary" href="?<?php echo http_build_query(array_merge($_GET, ['month' => date('Y-m')])); ?>" title="快速筛选本月">本月</a>
      </div>
    </div>
  </div>
  <div class="col-md-2 mb-2"><label class="small text-muted mb-1" for="dateBasis">查看方式</label><select class="form-control" id="dateBasis" name="date_basis"><option value="order_date" <?php
    echo $dateBasis === 'order_date' ? 'selected' : ''; ?>>按订单日期</option><option value="created_at" <?php echo $dateBasis === 'created_at' ? 'selected' : ''; ?>>最近录入 / 导入</option></select></div>
  <?php
  $filterCatalog = [];
  if ($actor['role'] !== 'finance') {
      foreach ($allowedBusinesses as $bName) {
          if (isset($businessCatalog[$bName])) $filterCatalog[$bName] = $businessCatalog[$bName];
      }
  } else {
      foreach ($businessCatalog as $bName => $bDef) {
          if (empty($bDef['legacy'])) $filterCatalog[$bName] = $bDef;
      }
  }
  if ($participationOnly && $filterBusiness !== '' && !isset($filterCatalog[$filterBusiness])) $filterCatalog[$filterBusiness] = [];
  ?>
  <div class="col-md-2 mb-2"><label class="small text-muted mb-1" for="filterBusiness">业务</label><select class="form-control" name="filter_business" id="filterBusiness"><option value="">全部业务</option><?php
    foreach ($filterCatalog as $businessName => $definition): ?><option value="<?php echo e($businessName); ?>" <?php echo $filterBusiness === $businessName ? 'selected' : ''; ?>><?php
    echo e($businessName); ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2 mb-2"><label class="small text-muted mb-1" for="filterCheck">系统核对</label><select class="form-control" id="filterCheck" name="check"><option value="">全部核对状态</option><?php
    foreach(['wait_sync','wait_finance','wait_data','exception','ready','auto_passed','settled','queued'] as $checkState): $checkMeta=pa_state_meta($checkState); ?><option value="<?php
    echo e($checkState); ?>" <?php echo $filterReview===$checkState?'selected':''; ?>><?php echo e($checkMeta[0]); ?>（<?php echo (int)($reviewCounts[$checkState]??0); ?>）</option><?php
    endforeach; ?></select></div>
  <div class="col-md-2 mb-2"><label class="small text-muted mb-1" for="filterState">状态</label><select class="form-control" name="state" id="filterState"><option value="">全部</option><option value="unfinished" <?php
    echo $filterState === 'unfinished' ? 'selected' : ''; ?>>交付未完成</option><option value="finished" <?php echo $filterState === 'finished' ? 'selected' : ''; ?>>交付已完成</option><option value="pending_backend" <?php
    echo $filterState === 'pending_backend' ? 'selected' : ''; ?>>待指定后端</option><option value="pending_delivery" <?php echo $filterState === 'pending_delivery' ? 'selected'
    : ''; ?>>待交付审核</option><option value="pending_upgrade" <?php echo $filterState === 'pending_upgrade' ? 'selected' : ''; ?>>待升级审核</option><option value="todo" <?php
    echo $filterState === 'todo' ? 'selected' : ''; ?>>有待办</option><option value="open" <?php echo $filterState === 'open' ? 'selected' : ''; ?>>未审核</option><option value="approved" <?php
    echo $filterState === 'approved' ? 'selected' : ''; ?>>已审核</option></select></div>
  <div class="col-md-3 mb-2"><label class="small text-muted mb-1" for="filterQ">搜索（订单号 / 客户 / 付款昵称 / 项目账号）</label><input class="form-control" type="search" name="q" id="filterQ" value="<?php
    echo e($keyword); ?>" placeholder="订单号、客户、账号、IP、备注…"></div>
  <div class="col-md-2 mb-2"><button class="btn btn-outline-primary btn-block">筛选</button></div>
</form></div></div>
<?php $mine = $actor['role'] !== 'finance' ? '我参与的' : ''; ?><div class="project-totals mb-3"><div><small><?php echo $mine; ?>订单</small><strong><?php echo count($orders)
    ; ?></strong></div><div><small><?php echo $mine; ?>售价合计</small><strong>¥<?php echo money($totals['contract']); ?></strong></div><div><small>已确认净实收</small><strong>¥<?php
    echo money($totals['receipt']); ?></strong></div><div><small>已审核直接成本</small><strong>¥<?php echo money($totals['cost']); ?></strong></div><div class="<?php echo $totals
    ['todo'] ? 'is-alert' : ''; ?>"><small>有待办的订单</small><strong><?php echo $totals['todo']; ?></strong></div></div>
<?php $isFinance = $actor['role'] === 'finance'; ?>
<?php if ($isFinance && ($corrPendingCount = ps_corr_pending_count()) > 0): ?><div class="alert alert-warning"><i class="fas fa-flag mr-1"></i>有 <?php echo (int)$corrPendingCount
    ; ?> 条分成更正申请待处理，<a class="alert-link" href="<?php echo BASE_URL; ?>/project/corrections.php">点此查看</a>。</div><?php endif; ?>
<?php if ($canDeleteOrders): ?><form method="post" id="bulkForm"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="return_query" value="<?php
    echo e(http_build_query(array_intersect_key($_GET, array_flip(['month','date_basis','filter_business','state','q','page','employee_id','participating'])))); ?>"><?php endif; ?>
<div class="card"><div class="card-header d-flex justify-content-between align-items-center flex-wrap" style="gap:8px"><span>订单列表（共 <?php echo (int)$totalOrders; ?> 单<?php
    if ($totalPages > 1): ?>，第 <?php echo (int)$page; ?>/<?php echo (int)$totalPages; ?> 页<?php endif; ?>）</span><?php if ($canDeleteOrders && $orders): ?><div class="project-bulk-bar"><span class="small text-muted" id="bulkCount">已选 0 单</span><?php
    if ($isFinance): ?><button class="btn btn-sm btn-outline-success" name="bulk_action" value="receipt" onclick="return confirm('根据收退款证据和规则核对所选订单？只有资料齐全的未结算订单会自动处理，不会按售价登记实收。')">系统核对</button><button class="btn btn-sm btn-outline-success" name="bulk_action" value="finish">标记交付完成</button><input type="month" name="bulk_month" class="form-control form-control-sm" style="width:150px" value="<?php
    echo e($month); ?>" aria-label="分成归属月份"><button class="btn btn-sm btn-success" name="bulk_action" value="approve" onclick="return confirm('审核所选订单并生成项目分成？不满足条件的订单会跳过并列出原因。')">批量审核</button><?php
    endif; ?><button class="btn btn-sm btn-outline-danger" name="bulk_action" value="delete" onclick="return confirm('删除所选订单？将连同实收流水、成本、参与人和分成快照一并删除，不可恢复；已审核的订单会跳过并列出原因。')">批量删除</button></div><?php
    endif; ?></div><div class="table-responsive"><table class="table table-hover mb-0 project-order-table">
  <thead><tr><?php if ($canDeleteOrders): ?><th style="width:34px"><input type="checkbox" id="bulkAll" aria-label="全选"></th><?php endif; ?><th>订单号 / 付款昵称</th><th>业务</th><th>日期</th><th>参与人</th><th class="text-right">售价</th><th class="text-right">净实收</th><th class="text-right">直接成本</th><th class="text-right">预计分成</th><th>系统核对／待补事项</th><th>结算状态</th><th></th></tr></thead><tbody>
  <?php foreach ($pageOrders as $order): ?><tr>
    <?php if ($canDeleteOrders): ?><td><?php if (!in_array($order['settlement_status'], ['approved','locked'], true)): ?><input type="checkbox" name="ids[]" value="<?php echo (int)
    $order['id']; ?>" class="bulk-item" aria-label="选择 <?php echo e($order['order_no']); ?>"><?php endif; ?></td><?php endif; ?>
    <td><strong><?php echo e($order['order_no']); ?></strong><?php if (!empty($order['is_department_order'])): ?> <span class="badge badge-success">部门订单</span><?php endif;
    ?><?php if (!empty($order['split_parent_id']) || !empty($order['split_children'])): ?> <span class="badge badge-info" title="同一订单号由不同业务分别录入，各自按本人金额结算；收款需财务按订单号合并核对">分单</span><?php
    endif; ?><div class="small text-muted"><?php echo e($order['payment_nickname'] ?: ($order['customer_name'] ?: '—')); ?></div><?php if (isset($credentialHits[(int)$order['id']
    ])): ?><div class="small"><span class="badge badge-info">项目账号命中</span> <?php echo e($credentialHits[(int)$order['id']]); ?></div><?php endif; ?></td>
    <td><?php echo e($order['project_type']); ?><?php if ($order['order_kind'] !== ''): ?><div class="small text-muted"><?php echo e($order['order_kind']); ?></div><?php endif; ?><?php
    foreach (poi_items((int)$order['id']) as $oi): ?><div class="small text-muted"><?php echo e($oi['item_name']); ?><?php echo $oi['sale_amount'] === null ? '' : ' · ¥' . money(
    $oi['sale_amount']); ?></div><?php endforeach; ?></td>
    <td class="text-nowrap"><?php echo e($order['order_date']); ?></td>
    <td class="small"><?php echo e($order['people'] ?: '—'); ?></td>
    <td class="text-right"><?php echo $order['price_source'] === 'missing' ? '<span class="text-muted">待补</span>' : '¥' . money($order['contract_amount']); ?></td>
    <td class="text-right">¥<?php echo money((float)$order['receipt_amount'] - (float)$order['refund_amount']); ?></td>
    <td class="text-right">¥<?php echo money($order['approved_costs']); ?></td>
    <td class="text-right small text-nowrap"><?php foreach ($commissionCells[(int)$order['id']] ?? [] as $cell): ?><div><span class="text-muted"><?php echo e($cell['name']); ?></span> <?php
    if ($cell['amount'] === null): ?><span class="text-muted">待配置</span><?php else: ?><a href="#" class="calc-open" title="点击查看计算过程" data-order="<?php echo (int)
    $order['id']; ?>" data-employee="<?php echo (int)$cell['employee_id']; ?>" data-group="<?php echo e($cell['group']); ?>"><?php echo $cell['estimated'] ? '预计 ' : ''; ?>¥<?php
    echo money($cell['amount']); ?></a><?php endif; ?></div><?php endforeach; ?><?php if (empty($commissionCells[(int)$order['id']])): ?><span class="text-muted">—</span><?php endif
    ; ?></td>
    <td style="min-width:190px;max-width:280px"><?php if($order['monthly_allowance_verified']): ?><span class="badge badge-success mb-1">月度单量补助已核验</span><div class="small text-muted">只按月计入一次；利润分成另核实收</div><?php
    endif; ?><span class="badge badge-<?php echo e($order['auto_review']['tone']); ?> mb-1"><?php echo e($order['auto_review']['label']); ?></span><?php if($order['auto_review']['guidance'
    ]): ?><div class="small mb-1" style="color:#326d78;white-space:normal"><?php echo e($order['auto_review']['guidance']); ?></div><?php endif; ?><?php foreach (array_slice($order
    ['todos'],0,2) as [$text, $level]): ?><div class="small text-muted" style="white-space:normal"><?php echo e($text); ?></div><?php endforeach; ?><?php if (count($order['todos'])
    >2): ?><div class="small text-muted">另有 <?php echo count($order['todos'])-2; ?> 项，打开结算单查看</div><?php endif; ?></td>
    <td class="text-nowrap">
      <?php echo e(ps_label('settlement', $order['settlement_status'])); ?>
      <div><?php echo $order['delivery_status'] === 'finished' ? '<span class="badge badge-success">已交付完成</span>' : '<span class="badge badge-light border">交付未完成</span>'
    ; ?></div>
    </td>
    <td class="text-nowrap"><a class="btn btn-outline-primary btn-sm text-nowrap" href="<?php echo BASE_URL; ?>/project/order.php?id=<?php echo (int)$order['id']; ?>">打开结算单</a><?php
    if (empty($order['backend_tech_count']) && ps_is_website_order($order['project_type']) && !in_array($order['settlement_status'], ['approved','locked'], true)): ?><a class="btn btn-outline-info btn-sm text-nowrap ml-1" href="<?php
    echo BASE_URL; ?>/project/order.php?id=<?php echo (int)$order['id']; ?>" title="本单未分配后端技术"><i class="fas fa-server mr-1"></i>指定后端</a><?php endif; ?><?php
    if ($canDeleteOrders && !in_array($order['settlement_status'], ['approved','locked'], true)): ?><button type="submit" name="delete_order_id" value="<?php echo (int)$order['id']
    ; ?>" class="btn btn-outline-danger btn-sm text-nowrap ml-1" onclick="return confirm('删除订单 <?php echo e($order['order_no']); ?>？将连同实收流水、成本、参与人和分成快照一并删除，不可恢复。')">删除</button><?php
    endif; ?></td>
  </tr><?php endforeach; ?>
  <?php if (!$orders): ?><tr><td colspan="12" class="text-center text-muted py-4"><?php echo $keyword !== '' ? '没有匹配的订单' : '本月暂无可查看的项目订单'; ?></td></tr><?php
    endif; ?>
  </tbody></table></div></div>
<?php if ($totalPages > 1): $from = max(1, $page - 2); $to = min($totalPages, $page + 2); ?>
<nav class="mt-3" aria-label="订单分页"><ul class="pagination pagination-sm justify-content-center flex-wrap mb-0">
  <li class="page-item<?php echo $page <= 1 ? ' disabled' : ''; ?>"><a class="page-link" href="<?php echo e($pageQuery(max(1, $page - 1))); ?>">上一页</a></li>
  <?php if ($from > 1): ?><li class="page-item"><a class="page-link" href="<?php echo e($pageQuery(1)); ?>">1</a></li><?php if ($from > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php
    endif; ?><?php endif; ?>
  <?php for ($i = $from; $i <= $to; $i++): ?><li class="page-item<?php echo $i === $page ? ' active' : ''; ?>"><a class="page-link" href="<?php echo e($pageQuery($i)); ?>"><?php echo
    $i; ?></a></li><?php endfor; ?>
  <?php if ($to < $totalPages): ?><?php if ($to < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?><li class="page-item"><a class="page-link" href="<?php
    echo e($pageQuery($totalPages)); ?>"><?php echo $totalPages; ?></a></li><?php endif; ?>
  <li class="page-item<?php echo $page >= $totalPages ? ' disabled' : ''; ?>"><a class="page-link" href="<?php echo e($pageQuery(min($totalPages, $page + 1))); ?>">下一页</a></li>
</ul></nav>
<?php endif; ?>
<?php if ($canDeleteOrders): ?></form><?php endif; ?>
<div class="modal fade" id="calcAjaxModal" tabindex="-1" role="dialog" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable" role="document"><div class="modal-content"></div></div></div>
<script><?php /* split: project/index/view/js_2.php */ include (dirname((dirname(__DIR__, 1)), 1)) . '/index/view/js_2.php'; ?></script>
</div>
<script><?php /* split: assets/js/project_index_3.js */ include (dirname((dirname(__DIR__, 1)), 1)) . '/../assets/js/project_index_3.js'; ?></script>
<script><?php /* split: project/index/view/js_4.php */ include (dirname((dirname(__DIR__, 1)), 1)) . '/index/view/js_4.php'; ?></script>
<?php if (ps_ai_ready()): ?>
<script><?php /* split: project/index/view/js_5.php */ include (dirname((dirname(__DIR__, 1)), 1)) . '/index/view/js_5.php'; ?></script>
<?php endif; ?>
<?php include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/footer.php'; ?>
