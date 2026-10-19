<?php
require_once __DIR__ . '/../includes/ProjectPartnerDashboard.php';
require_once __DIR__ . '/../includes/ProjectExpectedSettlement.php';
require_once __DIR__ . '/../includes/ProjectWelfare.php';
require_once __DIR__ . '/../includes/ProjectSettlementRequest.php';
$actor = ps_require_actor();
$month = (string)($_POST['month'] ?? $_GET['month'] ?? date('Y-m', strtotime('first day of last month')));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) || $month > date('Y-m')) $month = date('Y-m', strtotime('first day of last month'));
$employeeId = $actor['role'] === 'finance' ? (int)($_POST['employee_id'] ?? $_GET['employee_id'] ?? 0) : (int)$actor['employee_id'];
$people = [];
if ($actor['role'] === 'finance') {
    $people = db()->query("SELECT e.id,e.name,e.department FROM employees e WHERE EXISTS (SELECT 1 FROM project_users u WHERE u.employee_id=e.id AND u.is_active=1) OR EXISTS (SELECT 1 FROM project_participants p WHERE p.employee_id=e.id) ORDER BY e.name,e.id")->fetchAll();
    $allowed = array_column($people, null, 'id');
    if (!isset($allowed[$employeeId])) {
        [$activeFrom, $activeUntil] = ps_partner_month_bounds($month);
        $activeQuery = db()->prepare('SELECT p.employee_id FROM project_participants p JOIN project_orders o ON o.id=p.order_id WHERE o.order_date>=? AND o.order_date<? GROUP BY p.employee_id ORDER BY COUNT(DISTINCT o.id) DESC LIMIT 1');
        $activeQuery->execute([$activeFrom, $activeUntil]);
        $activeId = (int)$activeQuery->fetchColumn();
        $employeeId = isset($allowed[$activeId]) ? $activeId : (int)($people[0]['id'] ?? 0);
    }
}
if ($employeeId < 1) { http_response_code(404); exit('暂无可查看的合作人员'); }
$personQuery = db()->prepare('SELECT id,name,department FROM employees WHERE id=?');
$personQuery->execute([$employeeId]);
$person = $personQuery->fetch();
if (!$person) { http_response_code(404); exit('合作人员不存在'); }
[$from, $until] = ps_partner_month_bounds($month);
$previousFrom = (new DateTimeImmutable($from))->modify('-1 month')->format('Y-m-d');
$allRows = ps_partner_orders($employeeId, $previousFrom, $until);
$rows = array_values(array_filter($allRows, static fn($r) => $r['order_date'] >= $from));
$previousRows = array_values(array_filter($allRows, static fn($r) => $r['order_date'] < $from));
$summary = ps_partner_summary($rows);
$previous = ps_partner_summary($previousRows);
$ordersUrl = ps_partner_orders_url($employeeId, $month);
$previousOrdersUrl = ps_partner_orders_url($employeeId, substr($previousFrom, 0, 7));
$expectedIncome = ps_partner_expected_income($employeeId, $month);
$input = ps_partner_ai_input($summary, $previous, $month);
$inputHash = hash('sha256', json_encode($input, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
$insightQuery = db()->prepare('SELECT insight_json,created_at FROM project_partner_insights WHERE employee_id=? AND period=? AND input_hash=? LIMIT 1');
$insightQuery->execute([$employeeId, $month, $inputHash]);
$stored = $insightQuery->fetch();
$insight = $stored ? json_decode($stored['insight_json'], true) : null;
$fallback = ps_partner_fallback_guidance($summary);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_insight') {
    header('Content-Type: application/json; charset=utf-8');
    ps_check_csrf();
    if ($insight && is_array($insight)) {
        echo json_encode(['ok' => true, 'source' => 'ai', 'insight' => $insight], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!$summary['orders'] || !ps_ai_ready()) {
        echo json_encode(['ok' => true, 'source' => 'rules', 'insight' => $fallback], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $insight = ps_partner_ai_generate($input, $fallback);
        db()->prepare('INSERT INTO project_partner_insights (employee_id,period,input_hash,insight_json) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE insight_json=VALUES(insight_json)')
            ->execute([$employeeId, $month, $inputHash, json_encode($insight, JSON_UNESCAPED_UNICODE)]);
        echo json_encode(['ok' => true, 'source' => 'ai', 'insight' => $insight], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $error) {
        error_log('Partner dashboard AI: ' . $error->getMessage());
        echo json_encode(['ok' => true, 'source' => 'rules', 'insight' => $fallback], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_settle') {
    header('Content-Type: application/json; charset=utf-8');
    ps_check_csrf();
    try {
        $orderIds = (array)($_POST['order_ids'] ?? []);
        $targetEid = (int)($_POST['employee_id'] ?? $employeeId);
        $reqMonth = (string)($_POST['month'] ?? $month);
        $rid = psr_submit($actor, $reqMonth, $orderIds, $targetEid);
        echo json_encode(['ok' => true, 'id' => $rid, 'message' => '结算申请已成功提交，等待财务打款'], JSON_UNESCAPED_UNICODE);
    } catch (RuntimeException $e) {
        echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => '提交失败：' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$page_title = $actor['role'] === 'finance' ? '合作方经营看板' : '我的经营看板';
include __DIR__ . '/../includes/header.php';
$rateText = $summary['refund_rate'] === null ? '—' : number_format($summary['refund_rate'] * 100, 1) . '%';
$netDelta = $summary['net'] - $previous['net'];
$welfareBalance = pw_balance();
// 结算订单：所选月份已审核订单的分成按结算状态分两栏；尚未审核的订单只计数（审核后才进入可结算）
$settleRows = psr_orders($employeeId, $month);
$settledRows = array_values(array_filter($settleRows, static fn($r) => $r['state'] === 'settled'));
$unsettledRows = array_values(array_filter($settleRows, static fn($r) => $r['state'] !== 'settled'));
$sumOf = static fn($list) => round(array_sum(array_column($list, 'amount')), 2);
require_once __DIR__ . '/../includes/ProjectPayrollSheet.php';
$pendingData = pps_pending($month, $employeeId);
$pendingAmount = (float)($pendingData[$employeeId]['amount'] ?? 0.0);
$pendingN = (int)($pendingData[$employeeId]['n'] ?? 0);
$unreviewedN = max($pendingN, count(array_filter($rows, static fn($r) => in_array($r['settlement_status'], ['draft', 'review'], true))));
$currentMonth = date('Y-m');
$isCurrentOrFuture = ($month >= $currentMonth);
$availableOrders = array_values(array_filter($unsettledRows, static fn($r) => $r['state'] === 'available'));
$canApply = !$isCurrentOrFuture && count($availableOrders) > 0 && (($actor['role'] === 'finance') || ((int)($actor['employee_id'] ?? 0) === $employeeId));
$settleBadge = ['settled' => ['已结算', 'ok'], 'pending' => ['已申请 · 待打款', 'wait'], 'correcting' => ['更正中', 'warn'], 'available' => ['未申请', 'idle']];
?>
<div class="partner-dashboard">
  <section class="pd-hero">
    <div class="pd-hero-fx" aria-hidden="true"><span class="pd-hero-nebula"></span><span class="pd-hero-comet"></span><span class="pd-hero-comet"></span><span class="pd-hero-particles"></span></div>
    <div class="pd-hero-orbit" aria-hidden="true"><i></i><i></i><i></i></div>
    <div class="pd-hero-copy"><span class="pd-eyebrow"><i class="pd-signal-dot" aria-hidden="true"></i> CO-CREATION / 月度经营洞察</span>
      <h1><?php echo e($person['name']); ?>的经营看板</h1>
      <p><?php echo e($month); ?> 订单归属月 · <?php echo e($person['department'] ?: '项目合作方'); ?>。看清进展，也找到下一步。</p>
      <span class="pd-hero-caption"><i class="fas fa-bolt" aria-hidden="true"></i> 数据为锚 · 判断为光 · 与平台一起向前</span>
    </div>
    <form method="get" class="pd-filter" aria-label="选择看板月份和合作方">
      <?php if ($actor['role'] === 'finance'): ?><label>合作人员<select name="employee_id" class="form-control" onchange="this.form.submit()" aria-label="选择合作人员"><?php foreach ($people as $p): ?><option value="<?php echo (int)$p['id']; ?>" <?php echo $employeeId === (int)$p['id'] ? 'selected' : ''; ?>><?php echo e($p['name'] . ($p['department'] ? ' · ' . $p['department'] : '')); ?></option><?php endforeach; ?></select></label><?php endif; ?>
      <label>订单月份<input type="month" name="month" class="form-control" value="<?php echo e($month); ?>" max="<?php echo date('Y-m'); ?>" onchange="this.form.submit()"></label>
      <button class="btn btn-light" type="submit">查看</button>
    </form>
  </section>

  <a class="pd-welfare-link" href="<?php echo BASE_URL; ?>/project/welfare.php"><span><i class="fas fa-heart"></i> 全员共创福利池 <small>季度结余自动汇入 · 好建议与 Bug 有回响</small></span><strong>¥<?php echo money($welfareBalance); ?></strong><i class="fas fa-arrow-right"></i></a>
  <div class="pd-note"><i class="fas fa-info-circle"></i> 以下按订单日期归属到所选月份，并按本人分单权重计算；跨岗位参与同一单最多计 100%。实收和退款为当前已审核累计金额，可能包含次月发生的收退款，不代表该月现金流水。</div>

  <div class="pd-section-label"><span>01 / 经营脉搏</span><small>清晰看见每一步</small></div>
  <section class="pd-kpis" aria-label="经营数据">
    <article class="pd-kpi"><b class="pd-kpi-id" aria-hidden="true">01 / ORDERS</b><span><a class="pd-order-count-link" href="<?php echo e($ordersUrl); ?>">参与订单 <i class="fas fa-arrow-right small" aria-hidden="true"></i></a></span><strong><a class="pd-order-count-link" href="<?php echo e($ordersUrl); ?>" aria-label="<?php echo e('查看' . $person['name'] . '在' . $month . '参与的' . (int)$summary['orders'] . '笔订单'); ?>"><?php echo (int)$summary['orders']; ?> <small>单</small></a></strong><em><a class="pd-order-count-link" href="<?php echo e($previousOrdersUrl); ?>">上月 <?php echo (int)$previous['orders']; ?> 单</a></em></article>
    <article class="pd-kpi"><b class="pd-kpi-id" aria-hidden="true">02 / VALUE</b><span>订单成交额</span><strong>¥<?php echo money($summary['contract']); ?></strong><em>按本人分单权重归属</em></article>
    <article class="pd-kpi pd-kpi-primary"><b class="pd-kpi-id" aria-hidden="true">03 / EXPECTED INCOME</b><span><?php echo e($month); ?> 预期总收入</span><strong>¥<?php echo money($expectedIncome['total']); ?></strong><em>固定服务费 ¥<?php echo money($expectedIncome['fixed_fee']); ?> + 项目分成 ¥<?php echo money($expectedIncome['commission']); ?> + 预期全勤奖 ¥<?php echo money($expectedIncome['attendance']); ?></em></article>
    <article class="pd-kpi"><b class="pd-kpi-id" aria-hidden="true">04 / EXPECTED SHARE</b><span>预期总分成</span><strong>¥<?php echo money($expectedIncome['commission']); ?></strong><em>逐单分成及补助 ¥<?php echo money($expectedIncome['order_commission']); ?> · 月度阶梯、奖励及调整 ¥<?php echo money($expectedIncome['monthly_commission']); ?></em></article>
  </section>
  <div class="pd-data-note"><i class="fas fa-calculator"></i> 按订单归属日期与规则中心计算预期，归入本月的跨月分成更正一并计入，不代表已审核或已发放。未确认收款按已填写售价预估；全勤奖最终以财务批准为准。<?php if ($expectedIncome['warnings']['cash']): ?> <?php echo (int)$expectedIncome['warnings']['cash']; ?> 单实收待确认。<?php endif; ?><?php if ($expectedIncome['warnings']['cost']): ?> <?php echo (int)$expectedIncome['warnings']['cost']; ?> 单资源/成本待补齐，目前仅按已知成本估算，补齐后金额可能降低。<?php endif; ?><?php if ($expectedIncome['warnings']['rule']): ?> <?php echo (int)$expectedIncome['warnings']['rule']; ?> 单分成规则未匹配，相关金额暂不计入。<?php endif; ?><?php if ($expectedIncome['warnings']['weight']): ?> <?php echo (int)$expectedIncome['warnings']['weight']; ?> 单分配权重待核对，相关分成暂不计入。<?php endif; ?><?php if ($expectedIncome['warnings']['refund']): ?> <?php echo (int)$expectedIncome['warnings']['refund']; ?> 单退款待核对，确认后会自动重算。<?php endif; ?></div>
  <details class="pd-data-note"><summary>查看预期收入组成与计算依据</summary><div>逐单分成及补助：¥<?php echo money($expectedIncome['order_commission']); ?></div><?php foreach ($expectedIncome['items'] as $incomeItem): ?><div><?php echo e($incomeItem['rule_name']); ?>：¥<?php echo money($incomeItem['amount']); ?> <small><?php echo e($incomeItem['detail']); ?></small></div><?php endforeach; ?></details>

  <div class="pd-section-label"><span>02 / 业务信号</span><small>把数据变成下一步的线索</small></div>
  <div class="pd-grid">
    <section class="pd-panel"><div class="pd-panel-title"><div><span class="pd-icon"><i class="fas fa-layer-group"></i></span><h2>业务构成</h2></div><small><?php echo $summary['receipts'] > 0 ? '按净实收排序' : '实收待核对 · 按订单数查看'; ?></small></div>
      <?php if (!$summary['businesses']): ?><div class="pd-empty">暂时没有关联订单。录入订单并关联参与人后，这里会自动呈现。</div><?php else: ?>
      <?php $businessScale = $summary['receipts'] > 0 ? max(array_merge([1], array_map(static fn($x) => max(0, (float)$x['net']), $summary['businesses']))) : max(array_merge([1], array_column($summary['businesses'], 'orders'))); ?>
      <div class="pd-businesses"><?php foreach ($summary['businesses'] as $name => $item): $businessValue = $summary['receipts'] > 0 ? max(0, (float)$item['net']) : (int)$item['orders']; $businessShare = min(100, round($businessValue / $businessScale * 100, 1)); ?>
        <a class="pd-business pd-business-link" href="<?php echo e(ps_partner_orders_url($employeeId, $month, $name)); ?>" aria-label="<?php echo e('查看' . $person['name'] . '在' . $month . '的' . $name . '订单，共' . (int)$item['orders'] . '单'); ?>"><div><strong><?php echo e($name); ?></strong><small><?php echo (int)$item['orders']; ?> 单 <i class="fas fa-arrow-right" aria-hidden="true"></i></small></div><span>¥<?php echo money($item['net']); ?></span><div class="pd-business-track" aria-hidden="true"><i style="--pd-fill:<?php echo $businessShare; ?>%"></i></div></a>
      <?php endforeach; ?></div><?php endif; ?>
    </section>
    <section class="pd-panel"><div class="pd-panel-title"><div><span class="pd-icon amber"><i class="fas fa-clipboard-check"></i></span><h2>待核对清单</h2></div><small><?php echo (int)$summary['alert_count']; ?> 单需关注</small></div>
      <?php if (!$summary['alerts']): ?><div class="pd-empty pd-good"><i class="fas fa-check-circle"></i> 当前月份没有触发看板规则的异常，辛苦了！</div><?php else: ?>
      <div class="pd-alerts"><?php foreach (array_slice($summary['alerts'], 0, 8) as $alert): ?><a href="<?php echo BASE_URL; ?>/project/order.php?id=<?php echo (int)$alert['order_id']; ?>" class="pd-alert"><span><strong><?php echo e($alert['order_no']); ?></strong><small><?php echo e($alert['order_date']); ?></small></span><span><?php echo e(implode(' · ', $alert['flags'])); ?></span><i class="fas fa-chevron-right"></i></a><?php endforeach; ?>
      <?php if ($summary['alert_count'] > 8): ?><small class="pd-more">另有 <?php echo (int)$summary['alert_count'] - 8; ?> 单，打开<a href="<?php echo BASE_URL; ?>/project/index.php">项目订单</a>核对。</small><?php endif; ?></div><?php endif; ?>
    </section>
  </div>

  <div class="pd-section-label"><span>03 / 结算订单</span><small>已结算的、还没结算的，一目了然</small></div>
  <section class="pd-panel pd-settle" id="pd-settle">
    <div class="pd-panel-title"><div><span class="pd-icon"><i class="fas fa-hand-holding-usd"></i></span><h2><?php echo e($month); ?> 结算订单</h2></div><small><?php echo ($actor['role'] === 'finance' ? '财务查看 · ' . e($person['name']) : ''); ?></small></div>
    <?php if ($actor['role'] !== 'finance' && !$isCurrentOrFuture): require_once __DIR__ . '/../includes/ProjectPayrollFlags.php'; $myFlag = ppf_all($month)[$employeeId] ?? null; $fc = $myFlag['color'] ?? null; ?>
    <div class="pd-settle-banner <?php echo $fc === 'green' ? 'is-ok' : 'is-warn'; ?>"><i class="fas fa-<?php echo $fc === 'green' ? 'check-circle' : 'hourglass-half'; ?>"></i> <span><?php echo $fc === 'green' ? '财务已核对 ' . e($month) . ' 的结算金额，可以勾选订单提交结算申请。' : '财务还没有核对确认 ' . e($month) . ' 的结算金额，核对通过（标为绿色）后才能提交结算申请。' . ($fc ? '财务说明：' . e((string)$myFlag['reason']) : ''); ?></span></div>
    <?php endif; ?>
    <?php if ($isCurrentOrFuture): ?>
    <div class="pd-settle-banner is-warn"><i class="fas fa-info-circle"></i> <span>当前月份（<?php echo e($month); ?>）订单正在执行期内，不可申请结算；次月核对无误后方可申请（当前仅开放 <?php echo e(date('Y-m', strtotime('first day of last month'))); ?> 及以前历史月份已审核订单结算）。</span></div>
    <?php endif; ?>
    <div class="pd-settle-kpis">
      <div class="is-ok"><span>已结算</span><strong>¥<?php echo money($sumOf($settledRows)); ?></strong><em><?php echo count($settledRows); ?> 项</em></div>
      <div class="is-idle"><span>未结算（已审核）</span><strong>¥<?php echo money($sumOf($unsettledRows)); ?></strong><em><?php echo count($unsettledRows); ?> 项<?php $wait = count(array_filter($unsettledRows, static fn($r) => $r['state'] === 'pending')); echo $wait ? ' · 其中 ' . $wait . ' 项已申请待打款' : ''; ?></em></div>
      <div class="is-warn"><span>尚未审核</span><strong><?php echo (int)$unreviewedN; ?> 单<?php if ($pendingAmount > 0): ?> <small style="font-size:.9rem;font-weight:600;">(预计 ¥<?php echo money($pendingAmount); ?>)</small><?php endif; ?></strong><em>审核后才计入可结算分成</em></div>
    </div>
    <div class="pd-tabs" role="tablist"><button type="button" class="is-on" data-tab="unsettled">未结算订单 <b><?php echo count($unsettledRows); ?></b></button><button type="button" data-tab="settled">已结算订单 <b><?php echo count($settledRows); ?></b></button></div>
    
    <div class="pd-settle-list" data-pane="unsettled">
      <?php if (!$unsettledRows): ?><div class="pd-empty pd-good"><i class="fas fa-check-circle"></i> 这个月份已审核的订单都已结算。</div>
      <?php else: ?>
      <div class="pd-settle-bar">
        <div class="pd-settle-bar-left">
          <?php if (!$isCurrentOrFuture && count($availableOrders) > 0): ?>
            <label class="pd-select-all-label"><input type="checkbox" id="pd-check-all-top"> <span>全选</span></label>
            <span class="pd-sel-summary">已选 <strong id="pd-sel-count">0</strong> / <?php echo count($availableOrders); ?> 项，共计 <strong id="pd-sel-amount">¥0.00</strong></span>
          <?php elseif ($isCurrentOrFuture): ?>
            <span class="text-muted small"><i class="fas fa-lock"></i> 当月订单不可申请结算</span>
          <?php else: ?>
            <span class="text-muted small"><i class="fas fa-check-circle text-success"></i> 所有已审核订单均已提交申请或已结算</span>
          <?php endif; ?>
        </div>
        <div class="pd-settle-bar-right">
          <?php if (!$isCurrentOrFuture && count($availableOrders) > 0): ?>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="pd-btn-select-all">全选</button>
            <button type="button" class="btn btn-sm btn-success" id="pd-btn-submit">
              <i class="fas fa-check-circle"></i> 提交结算申请
            </button>
          <?php elseif ($isCurrentOrFuture): ?>
            <button type="button" class="btn btn-sm btn-secondary" disabled title="当月订单不可提交结算申请">
              <i class="fas fa-lock"></i> 当月不可结算
            </button>
          <?php endif; ?>
        </div>
      </div>
      <div class="table-responsive"><table class="pd-settle-table">
        <thead><tr>
          <th style="width:40px;text-align:center;"><input type="checkbox" id="pd-th-check-all" title="全选" <?php echo ($isCurrentOrFuture || count($availableOrders) === 0) ? 'disabled' : ''; ?>></th>
          <th>订单号 / 结算项目</th><th>付款昵称 / 微信号</th><th>业务</th><th>日期</th><th class="r">本月分成</th><th>状态</th>
        </tr></thead>
        <tbody>
        <?php foreach ($unsettledRows as $so):
            [$bt, $bc] = $settleBadge[$so['state']];
            $canCheck = ($so['state'] === 'available' && !$isCurrentOrFuture);
        ?>
        <tr class="<?php echo $canCheck ? 'is-selectable' : 'is-disabled'; ?>">
          <td style="text-align:center;">
            <?php if ($canCheck): ?>
              <input type="checkbox" name="order_ids[]" value="<?php echo (int)$so['order_id']; ?>" data-amt="<?php echo (float)$so['amount']; ?>" class="pd-row-chk" aria-label="选择项目 <?php echo e($so['order_no']); ?>">
            <?php else: ?>
              <input type="checkbox" disabled class="pd-row-chk is-disabled" title="<?php echo $isCurrentOrFuture ? '当月订单不可申请' : ($so['state'] === 'pending' ? '已申请待打款' : '不可申请'); ?>">
            <?php endif; ?>
          </td>
          <td>
            <?php if ((int)$so['order_id'] >= 900000000): ?>
              <span class="badge badge-info mr-1">月度</span><strong><?php echo e($so['order_no']); ?></strong>
              <?php if (!empty($so['customer'])): ?><div class="small text-muted" style="max-width:320px;line-height:1.3;"><?php echo e($so['customer']); ?></div><?php endif; ?>
            <?php else: ?>
              <a href="<?php echo BASE_URL; ?>/project/order.php?id=<?php echo (int)$so['order_id']; ?>"><?php echo e($so['order_no']); ?></a>
            <?php endif; ?>
          </td>
          <td><?php echo !empty($so['nick']) ? e($so['nick']) : '<span class="text-muted">—</span>'; ?></td>
          <td><?php echo e($so['project_type']); ?></td>
          <td><?php echo e($so['order_date']); ?></td>
          <td class="r">¥<?php echo money($so['amount']); ?></td>
          <td><span class="pd-st is-<?php echo $bc; ?>"><?php echo e($bt); ?></span><?php if ($so['state'] === 'settled' && $so['settled_at']): ?> <small><?php echo e(substr((string)$so['settled_at'], 0, 10)); ?></small><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>

    <div class="pd-settle-list" data-pane="settled" hidden>
      <?php if (!$settledRows): ?><div class="pd-empty">这个月份还没有已结算的订单。</div>
      <?php else: ?>
      <div class="table-responsive"><table class="pd-settle-table">
        <thead><tr>
          <th style="width:40px;text-align:center;">#</th>
          <th>订单号 / 结算项目</th><th>付款昵称 / 微信号</th><th>业务</th><th>日期</th><th class="r">本月分成</th><th>状态</th>
        </tr></thead>
        <tbody>
        <?php foreach ($settledRows as $idx => $so): [$bt, $bc] = $settleBadge[$so['state']]; ?>
        <tr>
          <td style="text-align:center;" class="text-muted small"><?php echo $idx + 1; ?></td>
          <td>
            <?php if ((int)$so['order_id'] >= 900000000): ?>
              <span class="badge badge-info mr-1">月度</span><strong><?php echo e($so['order_no']); ?></strong>
            <?php else: ?>
              <a href="<?php echo BASE_URL; ?>/project/order.php?id=<?php echo (int)$so['order_id']; ?>"><?php echo e($so['order_no']); ?></a>
            <?php endif; ?>
          </td>
          <td><?php echo !empty($so['nick']) ? e($so['nick']) : '<span class="text-muted">—</span>'; ?></td>
          <td><?php echo e($so['project_type']); ?></td>
          <td><?php echo e($so['order_date']); ?></td>
          <td class="r">¥<?php echo money($so['amount']); ?></td>
          <td><span class="pd-st is-<?php echo $bc; ?>"><?php echo e($bt); ?></span><?php if ($so['state'] === 'settled' && $so['settled_at']): ?> <small><?php echo e(substr((string)$so['settled_at'], 0, 10)); ?></small><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>
  </section>
  <style>
  .pd-settle-banner{display:flex;align-items:center;gap:.6rem;padding:.7rem 1rem;border-radius:12px;font-size:.85rem;margin:.4rem 0 1rem;background:#fff8e6;border:1px solid #f0dca0;color:#8a580a}
  .pd-settle-banner i{font-size:1.05rem;color:#c07d10}.pd-settle-banner.is-ok{background:#e6f4ec;border-color:#b9dcc7;color:#1f6a52}.pd-settle-banner.is-ok i{color:#2fa56c}
  .pd-settle-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.7rem;margin:.4rem 0 1rem}.pd-settle-kpis>div{padding:.7rem 1rem;border-radius:14px;background:#f6faf7;border:1px solid #e1ece5}.pd-settle-kpis span{display:block;font-size:.8rem;color:#6c7a72}.pd-settle-kpis strong{font-size:1.4rem;color:#234b3d;font-variant-numeric:tabular-nums}.pd-settle-kpis em{display:block;font-style:normal;font-size:.76rem;color:#8a978f}
  .pd-settle-kpis .is-ok{background:linear-gradient(135deg,#e6f4ec,#f6faf7)}.pd-settle-kpis .is-ok strong{color:#1f6a52}.pd-settle-kpis .is-warn{background:#fff8e6;border-color:#f0dca0}.pd-settle-kpis .is-warn strong{color:#a8650a}
  .pd-tabs{display:flex;gap:.4rem;margin-bottom:.7rem}.pd-tabs button{border:1px solid #d7e0d3;background:#fff;border-radius:999px;padding:.3rem 1rem;font-size:.88rem;color:#44574d;cursor:pointer}.pd-tabs button b{margin-left:.3rem;color:#2f6a52}.pd-tabs button.is-on{background:#2f6a52;border-color:#2f6a52;color:#fff}.pd-tabs button.is-on b{color:#cfe7d8}
  .pd-settle-bar{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.6rem;padding:.5rem .8rem;background:#f3f7f4;border:1px solid #e2ebe4;border-radius:10px;margin-bottom:.7rem}
  .pd-settle-bar-left{display:flex;align-items:center;gap:.8rem;font-size:.88rem;color:#33483e}
  .pd-settle-bar-right{display:flex;align-items:center;gap:.5rem}
  .pd-select-all-label{display:inline-flex;align-items:center;gap:.35rem;cursor:pointer;margin:0;font-weight:600;font-size:.85rem;color:#2f6a52}
  .pd-select-all-label input{margin:0}
  .pd-sel-summary{color:#556b60;font-size:.85rem}
  .pd-sel-summary strong{color:#1f6a52;font-weight:700}
  .pd-settle-table{width:100%;font-size:.88rem;border-collapse:separate;border-spacing:0 .25rem}.pd-settle-table th{padding:.3rem .6rem;color:#6c7a72;font-weight:600;text-align:left;font-size:.8rem}.pd-settle-table td{padding:.5rem .6rem;background:#f9fbf8}.pd-settle-table td:first-child{border-radius:9px 0 0 9px}.pd-settle-table td:last-child{border-radius:0 9px 9px 0}.pd-settle-table .r{text-align:right;font-variant-numeric:tabular-nums}
  .pd-settle-table tr.is-selectable:hover td{background:#eef6f1}
  .pd-row-chk{cursor:pointer;width:16px;height:16px;margin:0;vertical-align:middle}
  .pd-row-chk.is-disabled{cursor:not-allowed;opacity:.35}
  .pd-st{display:inline-block;padding:.12rem .6rem;border-radius:999px;font-size:.76rem;background:#eef1ea;color:#6c7a72}.pd-st.is-ok{background:#e3f3ea;color:#1f6a52}.pd-st.is-wait{background:#fdf1dc;color:#9a6512}.pd-st.is-warn{background:#fbe8e1;color:#b4572e}
  .pd-settle-go{font-weight:600;color:#2f6a52}.pd-settle-list[hidden]{display:none}.pd-settle-list .table-responsive{max-height:440px;overflow:auto}
  </style>
  <script>
  (function(){
    var s = document.getElementById('pd-settle');
    if(!s) return;
    s.addEventListener('click', function(e){
      var b = e.target.closest('.pd-tabs button');
      if(!b) return;
      [].forEach.call(s.querySelectorAll('.pd-tabs button'), function(x){ x.classList.toggle('is-on', x === b); });
      [].forEach.call(s.querySelectorAll('.pd-settle-list'), function(p){ p.hidden = p.getAttribute('data-pane') !== b.getAttribute('data-tab'); });
    });

    var chks = [].slice.call(s.querySelectorAll('.pd-row-chk:not(:disabled)'));
    var chkTop = s.querySelector('#pd-check-all-top');
    var chkTh = s.querySelector('#pd-th-check-all');
    var btnSelectAll = s.querySelector('#pd-btn-select-all');
    var btnSubmit = s.querySelector('#pd-btn-submit');
    var cntEl = s.querySelector('#pd-sel-count');
    var amtEl = s.querySelector('#pd-sel-amount');

    function updateStats() {
      var checked = chks.filter(function(c){ return c.checked; });
      var total = checked.reduce(function(acc, c){ return acc + (parseFloat(c.getAttribute('data-amt')) || 0); }, 0);
      if(cntEl) cntEl.textContent = checked.length;
      if(amtEl) amtEl.textContent = '¥' + total.toLocaleString('zh-CN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
      var allChecked = chks.length > 0 && checked.length === chks.length;
      if(chkTop) chkTop.checked = allChecked;
      if(chkTh) chkTh.checked = allChecked;
      if(btnSelectAll) btnSelectAll.textContent = allChecked ? '取消全选' : '全选';
    }

    function toggleAll(val) {
      chks.forEach(function(c){ c.checked = val; });
      updateStats();
    }

    if(chkTop) chkTop.addEventListener('change', function(){ toggleAll(chkTop.checked); });
    if(chkTh) chkTh.addEventListener('change', function(){ toggleAll(chkTh.checked); });
    if(btnSelectAll) btnSelectAll.addEventListener('click', function(){
      var allChecked = chks.length > 0 && chks.every(function(c){ return c.checked; });
      toggleAll(!allChecked);
    });

    s.addEventListener('change', function(e){
      if(e.target.classList.contains('pd-row-chk')) updateStats();
    });

    if(btnSubmit) {
      btnSubmit.addEventListener('click', function(){
        var checked = chks.filter(function(c){ return c.checked; });
        if(!checked.length) {
          alert('请先勾选要结算的订单（也可以点“全选”按钮）');
          return;
        }
        var total = checked.reduce(function(acc, c){ return acc + (parseFloat(c.getAttribute('data-amt')) || 0); }, 0);
        var msg = '确认提交选中的 ' + checked.length + ' 笔订单（共 ¥' + total.toFixed(2) + '）进行结算申请吗？';
        if(!confirm(msg)) return;

        btnSubmit.disabled = true;
        btnSubmit.textContent = '正在提交…';
        var fd = new FormData();
        fd.set('action', 'request_settle');
        fd.set('csrf', <?php echo json_encode(ps_csrf_token()); ?>);
        fd.set('month', <?php echo json_encode($month); ?>);
        fd.set('employee_id', <?php echo (int)$employeeId; ?>);
        checked.forEach(function(c){ fd.append('order_ids[]', c.value); });

        fetch(location.pathname + location.search, { method: 'POST', body: fd, credentials: 'same-origin' })
          .then(function(r){ return r.json().catch(function(){ return {ok:false, message:'提交失败（' + r.status + '）'}; }); })
          .then(function(res){
            if(res.ok) {
              alert(res.message || '结算申请提交成功！');
              location.reload();
            } else {
              alert(res.message || '提交失败，请重试');
              btnSubmit.disabled = false;
              btnSubmit.innerHTML = '<i class="fas fa-check-circle"></i> 提交结算申请';
            }
          })
          .catch(function(err){
            alert('网络请求失败，请稍后重试');
            btnSubmit.disabled = false;
            btnSubmit.innerHTML = '<i class="fas fa-check-circle"></i> 提交结算申请';
          });
      });
    }
  })();
  </script>

  <div class="pd-section-label"><span>04 / 共创方向</span><small>把经验变成未来的底气</small></div>
  <section class="pd-panel pd-insight" id="pd-insight" data-autoload="<?php echo !$insight && $summary['orders'] && ps_ai_ready() ? '1' : '0'; ?>">
    <div class="pd-panel-title"><div><span class="pd-icon purple"><i class="fas fa-sparkles"></i></span><h2>给你的经营建议</h2></div><small id="pd-source"><?php echo $insight ? 'AI 已生成 · 数据变化后会更新' : '基于现有订单的规则建议'; ?></small></div>
    <div class="pd-insight-grid"><div><span>你做得好的地方</span><p id="pd-strength"><?php echo e(($insight ?: $fallback)['strength']); ?></p></div><div><span>一起留意</span><p id="pd-risk"><?php echo e(($insight ?: $fallback)['risk']); ?></p></div><div><span>未来机会 · 一起验证</span><p id="pd-opportunity"><?php echo e(($insight ?: $fallback)['opportunity']); ?></p></div><div class="pd-future"><span>一起成长的方向</span><p id="pd-future_path"><?php echo e(($insight ?: $fallback)['future_path']); ?></p></div></div>
    <blockquote class="pd-principle">当执行趋近于廉价，方向就变得昂贵；当智能趋近于充裕，判断就变得稀缺。<small>让工具分担重复执行，我们和平台一起把心力放在需求判断、质量把关与方向选择上。</small></blockquote>
    <div class="pd-plan"><h3>下月的 3 个小目标</h3><ol id="pd-plan-list"><?php foreach (($insight ?: $fallback)['plan'] as $step): ?><li><?php echo e($step); ?></li><?php endforeach; ?></ol></div>
    <p class="pd-encouragement" id="pd-encouragement"><?php echo e(($insight ?: $fallback)['encouragement']); ?></p>
    <p class="pd-industry"><i class="fas fa-compass"></i> 行业背景：<a href="https://www.weforum.org/publications/the-future-of-jobs-report-2025/in-full/3-skills-outlook/" target="_blank" rel="noopener noreferrer">世界经济论坛《2025 年未来就业报告》</a>将 AI 与数据、科技素养、分析与创造性思维列为值得关注的能力方向。它是全球调查背景，并非对个人岗位的预测；具体方向仍要在平台项目中验证。</p>
    <div class="pd-insight-foot"><small>建议以与平台共创为前提，仅供复盘参考；机会是待验证的假设，不代表实时行业预测或结算依据。AI 只接收匿名汇总，不接收客户资料、订单号或支付流水。</small><?php if ($summary['orders'] && ps_ai_ready() && !$insight): ?><button class="btn btn-outline-success btn-sm" type="button" id="pd-generate">生成 AI 复盘</button><?php endif; ?></div>
  </section>
</div>
<script>
(function () {
  var box = document.getElementById('pd-insight'), button = document.getElementById('pd-generate');
  if (!box || !button) return;
  var busy = false;
  function render(value) {
    ['strength','risk','opportunity','future_path','encouragement'].forEach(function (key) { document.getElementById('pd-' + key).textContent = value[key] || ''; });
    var list = document.getElementById('pd-plan-list'); list.replaceChildren();
    (value.plan || []).forEach(function (step) { var li = document.createElement('li'); li.textContent = step; list.appendChild(li); });
  }
  function generate() {
    if (busy) return; busy = true; button.disabled = true; button.textContent = '正在生成复盘…';
    var data = new FormData(); data.set('action','generate_insight'); data.set('csrf', <?php echo json_encode(ps_csrf_token()); ?>); data.set('month', <?php echo json_encode($month); ?>); data.set('employee_id', <?php echo (int)$employeeId; ?>);
    fetch(location.pathname, {method:'POST',body:data,credentials:'same-origin'}).then(function (r) { if (!r.ok) throw Error('request'); return r.json(); }).then(function (result) {
      if (!result.ok || !result.insight) throw Error('response'); render(result.insight);
      document.getElementById('pd-source').textContent = result.source === 'ai' ? 'AI 已生成 · 数据变化后会更新' : 'AI 暂不可用 · 已显示规则建议';
      button.textContent = result.source === 'ai' ? '已生成' : '稍后重试'; button.disabled = result.source === 'ai'; busy = false;
    }).catch(function () { document.getElementById('pd-source').textContent = 'AI 暂不可用 · 已显示规则建议'; button.textContent = '重试生成'; button.disabled = false; busy = false; });
  }
  button.addEventListener('click', generate);
  if (box.dataset.autoload === '1') generate();
})();
(function () {
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduceMotion) return;
  var panels = document.querySelectorAll('.pd-grid .pd-panel, .pd-insight');
  if ('IntersectionObserver' in window) {
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        entry.target.classList.add('pd-in');
        entry.target.addEventListener('animationend', function () { entry.target.classList.remove('pd-in'); }, {once: true});
        observer.unobserve(entry.target);
      });
    }, {threshold: .12});
    panels.forEach(function (panel) { observer.observe(panel); });
  }
  if (!window.matchMedia || !window.matchMedia('(pointer: fine)').matches) return;
  document.querySelectorAll('.pd-kpi, .pd-panel').forEach(function (card) {
    var frame = 0, eventPoint = null;
    card.addEventListener('pointermove', function (event) {
      eventPoint = [event.clientX, event.clientY];
      if (frame) return;
      frame = requestAnimationFrame(function () {
        frame = 0;
        if (!eventPoint) return;
        var rect = card.getBoundingClientRect();
        card.style.setProperty('--spot-x', (eventPoint[0] - rect.left) + 'px');
        card.style.setProperty('--spot-y', (eventPoint[1] - rect.top) + 'px');
      });
    }, {passive: true});
    card.addEventListener('pointerleave', function () { eventPoint = null; card.style.removeProperty('--spot-x'); card.style.removeProperty('--spot-y'); });
  });
})();
</script>
<?php include __DIR__ . '/../includes/renewal_dashboard_card.php'; ?>
<?php include __DIR__ . '/../includes/kb_chat_examples_card.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
