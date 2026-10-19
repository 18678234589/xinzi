<?php
// 首页项目报酬表卡片：财务 / 管理账号看全部人员，其他合作人员只看自己。数据逻辑见 ProjectPayrollSheet.php。
// 页面先放骨架屏，由 assets/js/payroll_sheet.js 异步取 /payroll_sheet.php（同一文件的片段模式），首页不被核算拖慢。
require_once __DIR__ . '/ProjectPayrollSheet.php';
$ppsActor = ps_actor();
if (!$ppsActor) return;
$ppsAll = pps_sees_all($ppsActor);
$ppsOwn = $ppsAll ? 0 : (int)($ppsActor['employee_id'] ?? 0);
require_once __DIR__ . '/ProjectSettlementRequest.php';
$ppsMine = ($ppsActor['type'] ?? '') === 'employee' ? (int)($ppsActor['employee_id'] ?? 0) : 0; // 本人行才有“申请结算”
if (!$ppsAll && !$ppsOwn) return;
$ppsCssFile = dirname(__DIR__) . '/assets/css/payroll_sheet.css';
$ppsJsFile = dirname(__DIR__) . '/assets/js/payroll_sheet.js';
if (empty($ppsFragment)) {
    $ppsDefault = date('Y-m', strtotime('first day of last month'));
    $ppsPm = in_array((string)($_GET['pm'] ?? ''), pps_months(), true) ? (string)$_GET['pm'] : $ppsDefault;
    ?>
<link rel="stylesheet" href="<?php echo e(BASE_URL . '/assets/css/payroll_sheet.css?v=' . (is_file($ppsCssFile) ? filemtime($ppsCssFile) : 1)); ?>">
<div id="ppsHost" class="mb-3" data-src="<?php echo e(BASE_URL . '/payroll_sheet.php'); ?>" data-month="<?php echo e($ppsPm); ?>">
  <section class="pps-card pps-skel" aria-busy="true"><header class="pps-head"><div class="pps-title"><span class="pps-ico"><i class="fas fa-file-invoice-dollar"></i></span><div><h5><?php echo e($ppsPm); ?> 合作人员项目报酬表</h5><small>正在核算…</small></div></div></header>
    <div class="pps-tiles"><div><i></i></div><div><i></i></div><div><i></i></div><div><i></i></div></div><div class="pps-skel-rows"><i></i><i></i><i></i><i></i><i></i></div></section>
</div>
<script src="<?php echo e(BASE_URL . '/assets/js/payroll_sheet.js?v=' . (is_file($ppsJsFile) ? filemtime($ppsJsFile) : 1)); ?>" defer></script>
<?php
    return;
}
$ppsMonth = (string)($_GET['pm'] ?? date('Y-m', strtotime('first day of last month')));
if (!in_array($ppsMonth, pps_months(), true)) $ppsMonth = date('Y-m', strtotime('first day of last month'));
try {
    ps_payroll_extras_ensure();
    $pps = pps_sheet($ppsMonth, $ppsOwn, $ppsAll); // 结算资料（卡号等）只对财务 / 管理账号带出
    $ppsLock = db()->prepare('SELECT status FROM project_payroll_periods WHERE period=?');
    $ppsLock->execute([$ppsMonth]);
    $ppsLocked = $ppsLock->fetchColumn() === 'locked';
} catch (Throwable $e) { $pps = null; }
$ppsT = $pps['totals'] ?? [];
$ppsDepts = [];
foreach ($pps['rows'] ?? [] as $r) $ppsDepts[$r['department']] = ($ppsDepts[$r['department']] ?? 0) + 1;
ksort($ppsDepts);
$ppsHeads = [['days', '到岗天数'], ['base', '固定服务费'], ['share', '项目分成'], ['bonus', '全勤奖'], ['mgr', '经理补助'], ['lead', '组长补助'], ['ins_sub', '保险补助'], ['other', '其他补助 / 延时服务'], ['payable', '应结算'], ['deduct', '扣款'], ['parking', '车位费'], ['reserve', '扣风险预留金'], ['unit_ins', '单位保险'], ['personal_ins', '代扣保险'], ['net', '实结算']];
?>
<section class="pps-card mb-3" id="ppsCard" data-all="<?php echo $ppsAll ? 1 : 0; ?>">
  <header class="pps-head">
    <div class="pps-title"><span class="pps-ico"><i class="fas fa-file-invoice-dollar"></i></span>
      <div><h5><?php echo e($ppsMonth); ?> 合作人员项目报酬表</h5><small><?php echo $ppsAll ? '全部合作人员 · 系统实时核算' : '仅显示你本人 · 系统实时核算'; ?></small></div></div>
    <div class="pps-tools">
      <span class="pps-state <?php echo !empty($ppsLocked) ? 'is-locked' : 'is-live'; ?>"><i class="fas <?php echo !empty($ppsLocked) ? 'fa-lock' : 'fa-bolt'; ?>"></i> <?php echo !empty($ppsLocked) ? '本月已锁定' : '实时预计，未锁月'; ?></span>
      <select class="pps-month" aria-label="选择月份"><?php foreach (pps_months() as $m): ?><option value="<?php echo e($m); ?>"<?php echo $m === $ppsMonth ? ' selected' : ''; ?>><?php echo e($m); ?></option><?php endforeach; ?></select>
      <?php if ($ppsAll): $okGreen = 0; foreach ($pps['rows'] ?? [] as $gr) if ($gr['diff'] !== null && abs($gr['diff']) <= PPF_TOLERANCE && $gr['flag']['color'] !== 'green') $okGreen++; if ($okGreen > 0): ?><button type="button" class="pps-bulk pps-bulk-green" data-month="<?php echo e($ppsMonth); ?>" data-n="<?php echo $okGreen; ?>" data-token="<?php echo e(ps_csrf_token()); ?>"><i class="fas fa-check-double"></i> 一键标绿（差距≤5%，<?php echo $okGreen; ?> 人）</button><?php endif; endif; ?>
      <?php if ($ppsMine && ($ppsPast = psr_available_past($ppsMine)) && $ppsPast['n'] > 0): ?><button type="button" class="pps-bulk pps-settle-all" data-n="<?php echo (int)$ppsPast['n']; ?>" data-amt="<?php echo e(number_format($ppsPast['amount'], 2)); ?>" data-token="<?php echo e(ps_csrf_token()); ?>" title="把往月所有已审核、还没申请的订单一次提交；财务还没核对确认的月份会自动跳过"><i class="fas fa-bolt"></i> 一键申请往月全部（<?php echo (int)$ppsPast['n']; ?> 单 ¥<?php echo e(number_format($ppsPast['amount'], 2)); ?>）</button><?php endif; ?>
      <?php if ($ppsAll): ?><a class="pps-link" href="<?php echo BASE_URL; ?>/project/payroll.php?month=<?php echo e($ppsMonth); ?>">明细与锁月 <i class="fas fa-angle-right"></i></a><?php endif; ?>
    </div>
  </header>
<?php if ($pps === null): ?>
  <div class="pps-empty">项目报酬数据暂时无法加载，请稍后刷新。</div>
<?php elseif (!$pps['rows']): ?>
  <div class="pps-empty"><?php echo e($ppsMonth); ?> 暂无报酬数据</div>
<?php else: ?>
  <div class="pps-tiles">
    <div><small><?php echo $ppsAll ? '涉及人数' : '姓名'; ?></small><strong data-t="count"><?php echo $ppsAll ? count($pps['rows']) : e($pps['rows'][0]['name']); ?></strong></div>
    <div><small>应结算合计</small><strong data-t="payable">¥<?php echo number_format($ppsT['payable'], 2); ?></strong></div>
    <div><small>扣减合计</small><strong data-t="cut">¥<?php echo number_format($ppsT['payable'] - $ppsT['net'], 2); ?></strong></div>
    <div class="is-main"><small>实结算总计</small><strong data-t="net">¥<?php echo number_format($ppsT['net'], 2); ?></strong></div>
  </div>
<?php if ($ppsAll && count($ppsDepts) > 1): ?>
  <div class="pps-chips" role="tablist"><button type="button" class="is-on" data-dept="">全部 <b><?php echo count($pps['rows']); ?></b></button><?php foreach ($ppsDepts as $d => $n): ?><button type="button" data-dept="<?php echo e($d); ?>"><?php echo e($d ?: '未分部门'); ?> <b><?php echo $n; ?></b></button><?php endforeach; ?></div>
<?php endif; ?>
  <div class="pps-wrap">
    <table class="pps-table">
      <thead>
        <tr><?php if ($ppsMine): ?><th class="pps-actcol">结算</th><?php endif; ?><?php if ($ppsAll): ?><th>主管</th><?php endif; ?><th class="pps-sticky">姓名</th><?php if ($ppsAll): ?><th>工行卡号</th><?php endif; ?><th>部门</th><?php foreach ($ppsHeads as [$k, $label]): ?><th class="pps-n<?php echo in_array($k, ['payable', 'net'], true) ? ' pps-key' : ''; ?>"><?php echo e($label); ?></th><?php endforeach; ?><?php if ($ppsAll): ?><th class="pps-n">对照表实发</th><th class="pps-n">差距</th><th>核对</th><th>签名</th><th>买保险公司</th><th class="pps-n">已扣除预留金</th><?php endif; ?><th class="pps-note">备注</th></tr>
        <tr class="pps-total"><?php if ($ppsMine): ?><th></th><?php endif; ?><?php if ($ppsAll): ?><th></th><?php endif; ?><th class="pps-sticky">合计</th><?php if ($ppsAll): ?><th></th><?php endif; ?><th data-t="cnt"><?php echo count($pps['rows']); ?> 人</th><th class="pps-n"></th><?php foreach ($ppsHeads as [$k, $label]): if ($k === 'days') continue; ?><th class="pps-n<?php echo in_array($k, ['payable', 'net'], true) ? ' pps-key' : ''; ?>" data-c="<?php echo e($k); ?>"><?php echo abs($ppsT[$k]) < 0.005 ? '—' : number_format($ppsT[$k], 2); ?></th><?php endforeach; ?><?php if ($ppsAll): ?><th></th><th></th><th></th><th></th><th></th><th></th><?php endif; ?><th class="pps-note"></th></tr>
      </thead>
      <tbody>
<?php
$ppsSt = ['available' => 0, 'pending' => 0, 'settled' => 0];
if ($ppsMine) foreach (psr_orders($ppsMine, $ppsMonth) as $so) $ppsSt[$so['state']]++;
foreach ($pps['rows'] as $i => $r): ?>
        <tr data-dept="<?php echo e($r['department']); ?>">
<?php if ($ppsMine): ?>
          <td class="pps-actcol"><?php if ((int)$r['id'] === $ppsMine):
            if ($ppsSt['available'] > 0 && !ppf_is_green((int)$r['id'], $ppsMonth)): ?><span class="pps-st is-<?php echo $r['flag']['color'] === 'red' ? 'bad' : 'pending'; ?>" title="<?php echo e($r['flag']['reason']); ?>"><i class="fas fa-hourglass-half"></i> <?php echo $r['flag']['color'] ? ppf_label($r['flag']['color']) : '待财务核对'; ?></span><?php if ($r['flag']['reason'] !== ''): ?><div class="pps-flag-why"><?php echo e($r['flag']['reason']); ?></div><?php endif; ?>
            <?php elseif ($ppsSt['available'] > 0): ?><button type="button" class="pps-settle" data-month="<?php echo e($ppsMonth); ?>"><i class="fas fa-hand-holding-usd"></i> 申请结算<small><?php echo $ppsSt['available']; ?> 单</small></button>
            <?php elseif ($ppsSt['pending'] > 0): ?><span class="pps-st is-pending">已申请 · 待打款</span>
            <?php elseif ($ppsSt['settled'] > 0): ?><span class="pps-st is-done"><i class="fas fa-check-circle"></i> 已结算</span>
            <?php else: ?><span class="pps-st">本月暂无已审核订单</span><?php endif; endif; ?></td>
<?php endif; ?>
<?php if ($ppsAll): $pf = $r['profile']; ?>
          <td class="pps-dept"><?php echo $pf['supervisor'] !== '' ? e($pf['supervisor']) : '<span class="pps-zero">—</span>'; ?></td>
          <td class="pps-sticky"><span class="pps-rank<?php echo $i < 3 ? ' top' : ''; ?>"><?php echo $i + 1; ?></span><?php echo e($r['name']); ?> <button type="button" class="pps-edit" title="编辑结算资料" data-id="<?php echo (int)$r['id']; ?>" data-name="<?php echo e($r['name']); ?>" data-profile="<?php echo e(json_encode($pf + ['items' => $r['items'], 'month' => $ppsMonth], JSON_UNESCAPED_UNICODE)); ?>"><i class="fas fa-pen"></i></button><button type="button" class="pps-addcom" title="手工添加提成 / 扣项（如留学提成、设计费）"><i class="fas fa-plus"></i> 提成</button></td>
          <td class="pps-card-no"><?php echo $pf['bank_card'] !== '' ? e(trim(chunk_split($pf['bank_card'], 4, ' '))) : '<span class="pps-zero">—</span>'; ?></td>
<?php else: ?>
          <td class="pps-sticky"><span class="pps-rank"><?php echo $i + 1; ?></span><?php echo e($r['name']); ?></td>
<?php endif; ?>
          <td class="pps-dept"><?php echo e($r['department']); ?></td>
          <td class="pps-n"><?php echo $r['days'] === null ? '<span class="pps-zero">—</span>' : rtrim(rtrim(number_format($r['days'], 2), '0'), '.') . ($r['ot_days'] > 0 ? '<small class="pps-ot"> +' . rtrim(rtrim(number_format($r['ot_days'], 2), '0'), '.') . '</small>' : ''); ?></td>
<?php foreach ($ppsHeads as [$k, $label]): if ($k === 'days') continue;
    if ($k === 'share' && $r['pending_n'] > 0) { echo '          ', str_replace('</td>', '<small class="pps-pend" title="其中 ' . (int)$r['pending_n'] . ' 单尚未审核，按系统预计分成计入">含待审预计 ' . number_format($r['pending'], 2) . '</small></td>', pps_cell($r['share'])), "
"; continue; }
    echo '          ', pps_cell($r[$k], in_array($k, ['payable'], true) ? 'pps-key' : ($k === 'net' ? 'pps-key pps-net' : (in_array($k, ['deduct', 'parking', 'reserve', 'unit_ins', 'personal_ins'], true) ? 'pps-cut' : ''))), "\n"; endforeach; ?>
<?php if ($ppsAll): $fc = $r['flag']['color']; $over = $r['diff'] !== null && abs($r['diff']) > PPF_TOLERANCE; ?>
          <td class="pps-n"><?php echo $r['ref'] === null ? '<span class="pps-zero">—</span>' : number_format($r['ref'], 2); ?></td>
          <td class="pps-n"><?php echo $r['diff'] === null ? '<span class="pps-zero">—</span>' : '<span class="pps-diff ' . ($over ? 'is-over' : 'is-ok') . '">' . ($r['diff'] >= 0 ? '+' : '') . number_format($r['diff'] * 100, 1) . '%</span>'; ?></td>
          <td><button type="button" class="pps-flag <?php echo $fc ? 'is-' . e($fc) : ($over ? 'is-over' : ''); ?>" data-id="<?php echo (int)$r['id']; ?>" data-name="<?php echo e($r['name']); ?>" data-month="<?php echo e($ppsMonth); ?>" title="<?php echo e($r['flag']['reason']); ?>"><i class="fas fa-circle"></i> <?php echo $fc ? ['green' => '绿', 'yellow' => '黄', 'red' => '红'][$fc] : ($over ? '待核对' : '核对'); ?></button></td>
          <td class="pps-sign"><?php echo $pf['signed'] ? '<span class="pps-ok">√</span>' : '<span class="pps-zero">—</span>'; ?></td>
          <td><?php echo $pf['insurance_company'] !== '' ? e($pf['insurance_company']) : '<span class="pps-zero">—</span>'; ?></td>
          <td class="pps-n"><?php echo $pf['reserve_deducted'] === null ? '<span class="pps-zero">—</span>' : number_format((float)$pf['reserve_deducted'], 2); ?></td>
<?php endif; ?>
          <td class="pps-note" title="<?php echo e($r['note']); ?>"><?php echo e($r['note']); ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php if ($ppsMine): $myReqs = psr_requests($ppsMine); ?>
  <details class="pps-mine"<?php echo $myReqs ? '' : ' hidden'; ?>><summary><i class="fas fa-receipt"></i> 我的结算申请与已结算订单 <b><?php echo count($myReqs); ?></b></summary>
    <?php foreach ($myReqs as $rq): $isDone = $rq['status'] === 'settled'; ?>
    <div class="pps-req is-<?php echo e($rq['status']); ?>"><div class="pps-req-head"><strong><?php echo e($rq['payroll_month']); ?></strong> · <?php echo (int)$rq['order_count']; ?> 单 · ¥<?php echo number_format((float)$rq['amount'], 2); ?>
      <span class="pps-st <?php echo $isDone ? 'is-done' : ($rq['status'] === 'pending' ? 'is-pending' : 'is-bad'); ?>"><?php echo ['pending' => '待财务打款', 'settled' => '已结算 ' . substr((string)$rq['settled_at'], 0, 10), 'rejected' => '已退回'][$rq['status']]; ?></span><?php if ($rq['status'] === 'rejected'): ?> <small class="text-muted">原因：<?php echo e($rq['note']); ?></small><?php endif; ?></div>
      <?php if ($rq['status'] !== 'rejected'): ?><div class="pps-req-orders"><?php foreach (psr_request_orders($rq['id']) as $ro): ?><span title="<?php echo e($ro['project_type']); ?>"><?php echo e($ro['order_no']); ?> <b><?php echo number_format((float)$ro['amount'], 2); ?></b></span><?php endforeach; ?></div><?php endif; ?></div>
    <?php endforeach; ?></details>
<?php endif; ?>
<?php if ($ppsAll): $ppsPendingReq = psr_pending_count(); if ($ppsPendingReq > 0): ?><div class="pps-alert"><i class="fas fa-bell"></i> 有 <b><?php echo $ppsPendingReq; ?></b> 条结算申请待打款 <a href="<?php echo BASE_URL; ?>/project/settlement_requests.php">去处理 <i class="fas fa-angle-right"></i></a></div><?php endif; endif; ?>
  <footer class="pps-foot">实结算 = 应结算 − 扣款 − 车位费 − 扣风险预留金 − 单位保险 − 代扣保险；到岗天数后的“+”为延时服务天数。项目分成里“含待审预计”是尚未审核订单按系统预计分成计入的部分，审核后转为正式分成。未锁月份的数字随订单审核、服务时长确认实时变化，锁月后固化。</footer>
<?php endif; ?>
<?php if ($ppsMine): ?>
  <dialog class="pps-dialog pps-wide" id="ppsSettle"><form method="post" class="pps-sform" autocomplete="off">
    <h6>申请结算 · <span data-f="stitle"></span></h6>
    <input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="request_settle"><input type="hidden" name="month" value="">
    <p class="pps-hint">下面是该月份已审核、计入你应结算金额的订单。勾选后提交，财务会收到申请；打款后财务点“已结算”，这些订单就标注为已结算，不会再重复结算。</p>
    <div class="pps-sbar"><input type="search" data-f="q" placeholder="搜索订单号 / 业务 / 客户" aria-label="搜索订单"><div class="pps-schips" data-f="chips"></div></div>
    <label class="pps-check pps-all"><input type="checkbox" data-f="all"> 全选当前列表中可申请的订单 <span data-f="count"></span></label>
    <div class="pps-olist" data-f="list"></div>
    <div class="pps-sum">已选合计：<b data-f="sum">¥0.00</b></div>
    <div class="pps-corr" data-f="corr" hidden>
      <b>订单金额不对？</b>勾选算得不对的订单，写明哪里不对，主管和财务会收到并审核；这些订单先不会被结算，回复后可再申请。
      <textarea name="reason" rows="2" maxlength="300" placeholder="例如：这单客户已退款但分成没扣 / 成本填错了应为 ¥xxx / 不是我接的单"></textarea>
      <div class="pps-corr-act"><button type="button" data-f="corrcancel">先不更正</button><button type="button" class="is-warn" data-f="corrgo">提交更正申请</button></div>
    </div>
    <p class="pps-err" data-f="serr" hidden></p>
    <div class="pps-actions"><button type="button" data-f="corrtoggle" class="pps-linkbtn"><i class="fas fa-flag"></i> 有订单不对，申请更正</button><button type="button" data-f="scancel">取消</button><button type="submit" class="is-primary">提交结算申请</button></div>
  </form></dialog>
<?php endif; ?>
<?php if ($ppsAll): ?>
  <dialog class="pps-dialog pps-wide" id="ppsFlag"><form method="post" class="pps-fform" autocomplete="off">
    <h6>核对结算金额 · <span data-f="ftitle"></span></h6>
    <input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="save_flag"><input type="hidden" name="employee_id" value=""><input type="hidden" name="month" value="">
    <div class="pps-fcmp"><div><small>系统实结算</small><b data-f="net">—</b></div><div><small>对照表实发</small><input name="ref" inputmode="decimal" placeholder="财务表里的金额"></div><div><small>差距</small><b data-f="diff">—</b></div></div>
    <div class="pps-freasons" data-f="reasons"><div class="pps-empty">正在分析差异原因…</div></div>
    <div class="pps-fcolors" role="radiogroup" aria-label="核对结果">
      <label class="c-green"><input type="radio" name="color" value="green"><i class="fas fa-circle"></i> 绿 · 无异议，可申请结算</label>
      <label class="c-yellow"><input type="radio" name="color" value="yellow"><i class="fas fa-circle"></i> 黄 · 核对中 / 需补充</label>
      <label class="c-red"><input type="radio" name="color" value="red"><i class="fas fa-circle"></i> 红 · 差异大，待处理</label>
    </div>
    <textarea name="reason" rows="3" maxlength="500" placeholder="理由 / 需要对方补充什么（黄、红必填；点上面的原因可自动填入）"></textarea>
    <label class="pps-check"><input type="checkbox" name="notify" value="1" checked> 保存后发站内信通知本人</label>
    <p class="pps-hint" data-f="fhint"></p>
    <p class="pps-err" data-f="ferr" hidden></p>
    <div class="pps-actions"><a class="pps-linkbtn" data-f="review" href="#" target="_blank" hidden><i class="fas fa-clipboard-check"></i> 去审核订单</a><button type="button" data-f="fcancel">取消</button><button type="submit" class="is-primary">保存</button></div>
  </form></dialog>
  <dialog class="pps-dialog" id="ppsEdit"><form method="post" class="pps-form" autocomplete="off">
    <h6>编辑结算资料 · <span data-f="title"></span></h6>
    <input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="save_profile"><input type="hidden" name="employee_id" value="">
    <label>主管<input name="supervisor" maxlength="40"></label>
    <label>工行卡号<input name="bank_card" inputmode="numeric" maxlength="32" placeholder="12–25 位数字"></label>
    <label>买保险公司<input name="insurance_company" maxlength="60"></label>
    <label>已扣除预留金（元）<input name="reserve_deducted" inputmode="decimal" placeholder="留空表示无"></label>
    <input type="hidden" name="ni_present" value="1"><label class="pps-check"><input type="checkbox" name="no_insurance" value="1"> 免代扣保险（已退休等，永久不扣）</label>
    <label class="pps-check"><input type="checkbox" name="signed" value="1"> 已签名确认</label>
    <p class="pps-err" data-f="err" hidden></p>
    <div class="pps-actions"><button type="button" data-f="cancel">取消</button><button type="submit" class="is-primary">保存</button></div>
    <small>仅财务和管理员可见、可改；合作人员本人看不到。</small>
  </form>
  <div class="pps-items"><h6>手工添加提成 / 扣项 <small data-f="month"></small></h6>
    <ul data-f="items"></ul>
    <form class="pps-itemform" autocomplete="off"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="add_item"><input type="hidden" name="employee_id" value=""><input type="hidden" name="month" value="">
      <input name="label" maxlength="60" placeholder="名称，如：留学提成、小程序设计费" required><input name="amount" inputmode="decimal" placeholder="金额（扣款写负数）" required><input name="note" maxlength="200" placeholder="备注（选填）">
      <button type="submit">添加</button></form>
    <p class="pps-err" data-f="itemerr" hidden></p>
    <small>名称含“分成 / 提成 / 游学 / 留学”记入项目分成，含“补单”记入固定服务费，其余记入其他补助；车位、预留金写负数。锁月后不能改。</small></div></dialog>
<?php endif; ?>
</section>
