<?php
require_once __DIR__ . '/../includes/ProjectPartnerDashboard.php';
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

$page_title = $actor['role'] === 'finance' ? '合作方经营看板' : '我的经营看板';
include __DIR__ . '/../includes/header.php';
$rateText = $summary['refund_rate'] === null ? '—' : number_format($summary['refund_rate'] * 100, 1) . '%';
$netDelta = $summary['net'] - $previous['net'];
?>
<div class="partner-dashboard">
  <section class="pd-hero">
    <div><span class="pd-eyebrow"><i class="fas fa-seedling"></i> 每月复盘 · 把努力看得更清楚</span>
      <h1><?php echo e($person['name']); ?>的经营看板</h1>
      <p><?php echo e($month); ?> 订单归属月 · <?php echo e($person['department'] ?: '项目合作方'); ?>。看清进展，也找到下一步。</p>
    </div>
    <form method="get" class="pd-filter" aria-label="选择看板月份和合作方">
      <?php if ($actor['role'] === 'finance'): ?><label>合作人员<select name="employee_id" class="form-control" onchange="this.form.submit()" aria-label="选择合作人员"><?php foreach ($people as $p): ?><option value="<?php echo (int)$p['id']; ?>" <?php echo $employeeId === (int)$p['id'] ? 'selected' : ''; ?>><?php echo e($p['name'] . ($p['department'] ? ' · ' . $p['department'] : '')); ?></option><?php endforeach; ?></select></label><?php endif; ?>
      <label>订单月份<input type="month" name="month" class="form-control" value="<?php echo e($month); ?>" max="<?php echo date('Y-m'); ?>" onchange="this.form.submit()"></label>
      <button class="btn btn-light" type="submit">查看</button>
    </form>
  </section>

  <div class="pd-note"><i class="fas fa-info-circle"></i> 以下按订单日期归属到所选月份，并按本人分单权重计算；跨岗位参与同一单最多计 100%。实收和退款为当前已审核累计金额，可能包含次月发生的收退款，不代表该月现金流水。</div>

  <section class="pd-kpis" aria-label="经营数据">
    <article class="pd-kpi"><span>参与订单</span><strong><?php echo (int)$summary['orders']; ?> <small>单</small></strong><em>上月 <?php echo (int)$previous['orders']; ?> 单</em></article>
    <article class="pd-kpi"><span>订单成交额</span><strong>¥<?php echo money($summary['contract']); ?></strong><em>按本人分单权重归属</em></article>
    <article class="pd-kpi pd-kpi-primary"><span>订单净实收</span><strong>¥<?php echo money($summary['net']); ?></strong><em>较前月 <?php echo $netDelta >= 0 ? '+' : '−'; ?>¥<?php echo money(abs($netDelta)); ?></em></article>
    <article class="pd-kpi"><span>退款率</span><strong><?php echo e($rateText); ?></strong><em>退款 ¥<?php echo money($summary['refunds']); ?> / 已审核实收 ¥<?php echo money($summary['receipts']); ?></em></article>
  </section>
  <?php if ($summary['unverified_cash_orders']): ?><div class="pd-data-note"><i class="fas fa-receipt"></i> <?php echo (int)$summary['unverified_cash_orders']; ?> 单尚无已审核实收：成交额可看，净实收与退款率要等财务完成收款核对后再判断；这不计为个人异常。</div><?php endif; ?>

  <div class="pd-grid">
    <section class="pd-panel"><div class="pd-panel-title"><div><span class="pd-icon"><i class="fas fa-layer-group"></i></span><h2>业务构成</h2></div><small><?php echo $summary['receipts'] > 0 ? '按净实收排序' : '实收待核对 · 按订单数查看'; ?></small></div>
      <?php if (!$summary['businesses']): ?><div class="pd-empty">暂时没有关联订单。录入订单并关联参与人后，这里会自动呈现。</div><?php else: ?>
      <div class="pd-businesses"><?php foreach ($summary['businesses'] as $name => $item): ?>
        <div class="pd-business"><div><strong><?php echo e($name); ?></strong><small><?php echo (int)$item['orders']; ?> 单</small></div><span>¥<?php echo money($item['net']); ?></span></div>
      <?php endforeach; ?></div><?php endif; ?>
    </section>
    <section class="pd-panel"><div class="pd-panel-title"><div><span class="pd-icon amber"><i class="fas fa-clipboard-check"></i></span><h2>待核对清单</h2></div><small><?php echo (int)$summary['alert_count']; ?> 单需关注</small></div>
      <?php if (!$summary['alerts']): ?><div class="pd-empty pd-good"><i class="fas fa-check-circle"></i> 当前月份没有触发看板规则的异常，辛苦了！</div><?php else: ?>
      <div class="pd-alerts"><?php foreach (array_slice($summary['alerts'], 0, 8) as $alert): ?><a href="<?php echo BASE_URL; ?>/project/order.php?id=<?php echo (int)$alert['order_id']; ?>" class="pd-alert"><span><strong><?php echo e($alert['order_no']); ?></strong><small><?php echo e($alert['order_date']); ?></small></span><span><?php echo e(implode(' · ', $alert['flags'])); ?></span><i class="fas fa-chevron-right"></i></a><?php endforeach; ?>
      <?php if ($summary['alert_count'] > 8): ?><small class="pd-more">另有 <?php echo (int)$summary['alert_count'] - 8; ?> 单，打开<a href="<?php echo BASE_URL; ?>/project/index.php">项目订单</a>核对。</small><?php endif; ?></div><?php endif; ?>
    </section>
  </div>

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
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
