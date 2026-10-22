<?php
// 首页项目报酬表卡片：财务 / 管理账号看全部人员，其他合作人员只看自己。数据逻辑见 ProjectPayrollSheet.php。
// 页面先放骨架屏，由 assets/js/payroll_sheet.js 异步取 /payroll_sheet.php（同一文件的片段模式），首页不被核算拖慢。
require_once __DIR__ . '/ProjectPayrollSheet.php';
$ppsActor = ps_actor();
if (!$ppsActor) return;
$ppsAll = pps_sees_all($ppsActor);
$ppsOwn = $ppsAll ? 0 : (int)($ppsActor['employee_id'] ?? 0);
require_once __DIR__ . '/ProjectSettlementRequest.php';
$ppsMine = pspr_self_employee($ppsActor); // 财务管理员同样能在本人行提交申请。
if (!$ppsAll && !$ppsOwn) return;
$ppsCssFile = dirname(__DIR__) . '/assets/css/payroll_sheet.css';
$ppsJsFile = dirname(__DIR__) . '/assets/js/payroll_sheet.js';
if (empty($ppsFragment)) {
    $ppsDefault = pps_default_month();
    $ppsPm = in_array((string)($_GET['pm'] ?? ''), pps_months(), true) ? (string)$_GET['pm'] : $ppsDefault;
    ?>
<link rel="stylesheet" href="<?php echo e(BASE_URL . '/assets/css/payroll_sheet.css?v=' . (is_file($ppsCssFile) ? filemtime($ppsCssFile) : 1)); ?>">
<link rel="stylesheet" href="<?php echo e(BASE_URL . '/assets/css/payroll-request.css?v=' . filemtime(dirname(__DIR__) . '/assets/css/payroll-request.css')); ?>">
<script src="<?php echo e(BASE_URL . '/assets/js/payroll_request.js?v=' . filemtime(dirname(__DIR__) . '/assets/js/payroll_request.js')); ?>" defer></script>
<div id="ppsHost" class="mb-3" data-src="<?php echo e(BASE_URL . '/payroll_sheet.php'); ?>" data-month="<?php echo e($ppsPm); ?>">
  <section class="pps-card pps-skel" aria-busy="true"><header class="pps-head"><div class="pps-title"><span class="pps-ico"><i class="fas fa-file-invoice-dollar"></i></span><div><h5><?php echo e($ppsPm); ?> 合作人员项目报酬表</h5><small>正在核算…</small></div></div></header>
    <div class="pps-tiles"><div><i></i></div><div><i></i></div><div><i></i></div><div><i></i></div></div><div class="pps-skel-rows"><i></i><i></i><i></i><i></i><i></i></div></section>
</div>
<script src="<?php echo e(BASE_URL . '/assets/js/payroll_sheet.js?v=' . (is_file($ppsJsFile) ? filemtime($ppsJsFile) : 1)); ?>" defer></script>
<?php
    return;
}
$ppsMonth = (string)($_GET['pm'] ?? pps_default_month());
if (!in_array($ppsMonth, pps_months(), true)) $ppsMonth = pps_default_month();
try {
    ps_payroll_extras_ensure();
    $pps = pps_sheet($ppsMonth, $ppsOwn, $ppsAll); // 结算资料（卡号等）只对财务 / 管理账号带出
    $ppsLock = db()->prepare('SELECT status FROM project_payroll_periods WHERE period=?');
    $ppsLock->execute([$ppsMonth]);
    $ppsLocked = $ppsLock->fetchColumn() === 'locked';
} catch (Throwable $e) { $pps = null; }
$ppsT = $pps['totals'] ?? [];
$ppsDirectors = $ppsAll && ps_director_can($ppsActor) ? ps_director_settings($ppsMonth) : [];
$ppsDepts = [];
foreach ($pps['rows'] ?? [] as $r) $ppsDepts[$r['department']] = ($ppsDepts[$r['department']] ?? 0) + 1;
ksort($ppsDepts);
$ppsHeads = pps_heads();
$ppsFixed = []; if ($ppsAll) foreach ($pps['rows'] ?? [] as $fr) $ppsFixed[(int)$fr['id']] = $fr;
$ppsProxyExpected = []; $ppsProxyNames = []; $ppsProxyTotal = 0.0;
foreach ($ppsFixed as $eid=>$fixedRow) {
    if ($eid === $ppsMine) continue;
    $fixedStatus = pspr_status($eid,$ppsMonth,$fixedRow);
    if (!$fixedStatus['can_apply'] || $fixedStatus['pending']) continue;
    $ppsProxyExpected[$eid] = number_format($fixedStatus['amount'],2,'.','');
    $ppsProxyNames[] = $fixedRow['name'].' ¥'.number_format($fixedStatus['amount'],2);
    $ppsProxyTotal += $fixedStatus['amount'];
}
?>
<section class="pps-card mb-3" id="ppsCard" data-all="<?php echo $ppsAll ? 1 : 0; ?>">
  <header class="pps-head">
    <div class="pps-title"><span class="pps-ico"><i class="fas fa-file-invoice-dollar"></i></span>
      <div><h5><?php echo e($ppsMonth); ?> 合作人员项目报酬表</h5><small><?php echo $ppsAll ? '全部合作人员 · 系统实时核算' : '仅显示你本人 · 系统实时核算'; ?></small></div></div>
    <div class="pps-tools">
      <span class="pps-state <?php echo !empty($ppsLocked) ? 'is-locked' : 'is-live'; ?>"><i class="fas <?php echo !empty($ppsLocked) ? 'fa-lock' : 'fa-bolt'; ?>"></i> <?php echo !empty($ppsLocked) ? '本月已锁定' : '实时预计，未锁月'; ?></span>
      <select class="pps-month" aria-label="选择月份"><?php foreach (pps_months() as $m): ?><option value="<?php echo e($m); ?>"<?php echo $m === $ppsMonth ? ' selected' : ''; ?>><?php echo e($m); ?></option><?php endforeach; ?></select>
      <?php if ($ppsAll): $okGreen = 0; foreach ($pps['rows'] ?? [] as $gr) if ($gr['diff'] !== null && abs($gr['diff']) <= PPF_TOLERANCE && $gr['flag']['color'] !== 'green') $okGreen++; if ($okGreen > 0): ?><button type="button" class="pps-bulk pps-bulk-green" data-month="<?php echo e($ppsMonth); ?>" data-n="<?php echo $okGreen; ?>" data-token="<?php echo e(ps_csrf_token()); ?>"><i class="fas fa-check-double"></i> 一键标绿（差距≤5%，<?php echo $okGreen; ?> 人）</button><?php endif; endif; ?>
      <?php if ($ppsMine && ($ppsPast = psr_available_past($ppsMine)) && $ppsPast['n'] > 0): ?><button type="button" class="pps-bulk pps-settle-all" data-n="<?php echo (int)$ppsPast['n']; ?>" data-amt="<?php echo e(number_format($ppsPast['amount'], 2)); ?>" data-token="<?php echo e(ps_csrf_token()); ?>" title="按首页实结算申请往月报酬，含固定工资、全勤、提成和全部扣减；自动扣除已申请或已结算金额"><i class="fas fa-bolt"></i> 一键申请往月全部（<?php echo (int)$ppsPast['n']; ?> 个月 ¥<?php echo e(number_format($ppsPast['amount'], 2)); ?>）</button><?php endif; ?>
      <?php if ($ppsProxyExpected): ?><form class="payroll-request-form pps-bulkform" action="<?php echo e(BASE_URL.'/payroll_sheet.php'); ?>" method="post" data-label="一键代申请整月实结算" data-month="<?php echo e($ppsMonth); ?>" data-amount="<?php echo e(number_format($ppsProxyTotal,2,'.','')); ?>" data-people="<?php echo e(implode("\n",$ppsProxyNames)); ?>">
        <input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="request_fixed_payroll"><input type="hidden" name="month" value="<?php echo e($ppsMonth); ?>"><input type="hidden" name="expected_requests" value="<?php echo e(json_encode($ppsProxyExpected,JSON_UNESCAPED_UNICODE)); ?>">
        <button type="submit"><i class="fas fa-paper-plane"></i> 一键代申请 · <?php echo count($ppsProxyExpected); ?> 人</button><span class="payroll-request-message" role="status" hidden></span>
      </form><?php endif; ?>
      <?php if ($ppsAll): ?><a class="pps-link" href="<?php echo BASE_URL; ?>/project/payroll.php?month=<?php echo e($ppsMonth); ?>">明细与锁月 <i class="fas fa-angle-right"></i></a><?php endif; ?>
    </div>
  </header>
<?php if ($pps === null): ?>
  <div class="pps-empty">项目报酬数据暂时无法加载，请稍后刷新。</div>
<?php elseif (!$pps['rows']): ?>
  <div class="pps-empty"><?php echo e($ppsMonth); ?> 暂无报酬数据</div>
<?php else: ?>
  <div class="pps-tiles">
    <div><small><?php echo $ppsAll ? '涉及人数' : '项目分成'; ?></small><strong data-t="count"><?php echo $ppsAll ? count($pps['rows']) : '¥' . number_format($ppsT['share'], 2); ?></strong></div>
    <div><small>应结算合计</small><strong data-t="payable">¥<?php echo number_format($ppsT['payable'], 2); ?></strong></div>
    <div><small>扣减合计</small><strong data-t="cut">¥<?php echo number_format($ppsT['payable'] - $ppsT['net'], 2); ?></strong></div>
    <div class="is-main"><small>实结算总计</small><strong data-t="net">¥<?php echo number_format($ppsT['net'], 2); ?></strong></div>
  </div>
<?php if ($ppsAll && count($ppsDepts) > 1): ?>
  <div class="pps-chips" role="tablist"><button type="button" class="is-on" data-dept="">全部 <b><?php echo count($pps['rows']); ?></b></button><?php foreach ($ppsDepts as $d => $n): ?><button type="button" data-dept="<?php echo e($d); ?>"><?php echo e($d ?: '未分部门'); ?> <b><?php echo $n; ?></b></button><?php endforeach; ?></div>
<?php endif; ?>
<?php
$ppsHide = []; // 财务多人视图里，整列全为 0 的补助列不占位置
if ($ppsAll) foreach (['mgr', 'lead', 'ins_sub'] as $hk) { $any = false; foreach ($pps['rows'] as $hr) if (abs((float)$hr[$hk]) >= 0.005) { $any = true; break; } if (!$any) $ppsHide[$hk] = true; }
$ppsCut = ['deduct', 'parking', 'reserve', 'unit_ins', 'personal_ins'];
$ppsCols = [['who', '姓名', '人员'], ['payable', '应结算', '结算'], ['net', '实结算', '结算'], ['act', '申请', '结算']];
if ($ppsAll) { $ppsCols[] = ['chk', '核对', '核对']; $ppsCols[] = ['ref', '对照表实发', '核对']; }
foreach ($ppsHeads as [$hk, $hl]) { if ($hk === 'payable' || $hk === 'net' || isset($ppsHide[$hk])) continue; $ppsCols[] = [$hk, $hl, in_array($hk, $ppsCut, true) ? '扣减' : '收入构成']; }
if ($ppsAll) foreach ([['card', '工行卡号'], ['sup', '主管'], ['sign', '签名'], ['ins', '买保险公司'], ['resv', '已扣除预留金']] as [$hk, $hl]) $ppsCols[] = [$hk, $hl, '结算资料'];
$ppsCols[] = ['note', '备注', '备注'];
$ppsGroups = []; foreach ($ppsCols as $hc) { $hg = $hc[2]; $last = count($ppsGroups) - 1; if ($last >= 0 && $ppsGroups[$last][0] === $hg) $ppsGroups[$last][1]++; else $ppsGroups[] = [$hg, 1]; }
$ppsGroupCls = ['人员' => 'g-who', '结算' => 'g-main', '核对' => 'g-chk', '收入构成' => 'g-inc', '扣减' => 'g-cut', '结算资料' => 'g-prof', '备注' => 'g-note'];
$ppsNumeric = ['payable', 'net', 'share', 'base', 'bonus', 'mgr', 'lead', 'ins_sub', 'other', 'deduct', 'parking', 'reserve', 'unit_ins', 'personal_ins', 'ref', 'resv', 'days'];
?>
  <div class="pps-wrap">
    <table class="pps-table">
      <thead>
        <tr class="pps-groups"><?php foreach ($ppsGroups as [$hg, $hn]): ?><th colspan="<?php echo $hn; ?>" class="<?php echo e($ppsGroupCls[$hg] ?? ''); ?><?php echo $hg === '人员' ? ' pps-sticky' : ''; ?>"><?php echo $hg === '人员' || $hg === '备注' ? '' : e($hg); ?></th><?php endforeach; ?></tr>
        <tr class="pps-heads"><?php foreach ($ppsCols as $hc): $ck = $hc[0]; ?><th class="<?php echo in_array($ck, $ppsNumeric, true) ? 'pps-n' : ''; ?><?php echo $ck === 'who' ? ' pps-sticky' : ''; ?><?php echo in_array($ck, ['payable', 'net'], true) ? ' pps-key' : ''; ?><?php echo $ck === 'note' ? ' pps-note' : ''; ?><?php echo $ck === 'act' ? ' pps-actcol' : ''; ?>"><?php echo e($hc[1]); ?></th><?php endforeach; ?></tr>
        <tr class="pps-total"><?php foreach ($ppsCols as $hc): $ck = $hc[0];
            if ($ck === 'who'): ?><th class="pps-sticky">合计 · <span data-t="cnt"><?php echo count($pps['rows']); ?> 人</span></th><?php
            elseif (isset($ppsT[$ck]) && in_array($ck, $ppsNumeric, true) && $ck !== 'days'): ?><th class="pps-n<?php echo in_array($ck, ['payable', 'net'], true) ? ' pps-key' : ''; ?>" data-c="<?php echo e($ck); ?>"><?php echo abs($ppsT[$ck]) < 0.005 ? '—' : number_format($ppsT[$ck], 2); ?></th><?php
            else: ?><th></th><?php endif; endforeach; ?></tr>
      </thead>
      <tbody>
<?php
$ppsClaim = $ppsMine ? pspr_month($ppsMine, $ppsMonth) : null; // 已申请 / 已结算的金额，本人行里从实结算扣除显示
foreach ($pps['rows'] as $i => $r):
    $pf = $r['profile'] ?? []; $rid = (int)$r['id']; ?>
        <tr data-dept="<?php echo e($r['department']); ?>">
<?php foreach ($ppsCols as $hc): $ck = $hc[0];
    switch ($ck):
    case 'who': ?>
          <td class="pps-sticky pps-who"><div class="pps-whobox"><span class="pps-rank<?php echo $i < 3 ? ' top' : ''; ?>"><?php echo $i + 1; ?></span><span class="pps-name"><b><?php echo e($r['name']); ?></b><small><?php echo e($r['department']); ?></small></span><?php if ($ppsAll): ?><span class="pps-rowtools"><button type="button" class="pps-edit" title="编辑结算资料" data-id="<?php echo $rid; ?>" data-name="<?php echo e($r['name']); ?>" data-profile="<?php echo e(json_encode($pf + ['items' => $r['items'], 'month' => $ppsMonth], JSON_UNESCAPED_UNICODE)); ?>"><i class="fas fa-pen"></i></button><button type="button" class="pps-addcom" title="手工添加提成 / 扣项（如留学提成、设计费）"><i class="fas fa-plus"></i> 提成</button></span><?php endif; ?></div></td>
<?php   break;
    case 'act': ?>
          <td class="pps-actcol"><?php if ($rid === $ppsMine || $ppsAll): $payrollRequestEmployee = $rid; $payrollRequestRow = $r; $payrollRequestMonth = $ppsMonth; $payrollRequestProxy = $ppsAll && $rid !== $ppsMine; $payrollRequestCompact = true; include __DIR__ . '/payroll_request_button.php'; unset($payrollRequestRow, $payrollRequestCompact); endif; ?></td>
<?php   break;
    case 'chk': $fc = $r['flag']['color']; $over = $r['diff'] !== null && abs($r['diff']) > PPF_TOLERANCE; ?>
          <td class="pps-chk"><button type="button" class="pps-flag <?php echo $fc ? 'is-' . e($fc) : ($over ? 'is-over' : ''); ?>" data-id="<?php echo $rid; ?>" data-name="<?php echo e($r['name']); ?>" data-month="<?php echo e($ppsMonth); ?>" title="<?php echo e($r['flag']['reason']); ?>"><i class="fas fa-circle"></i> <?php echo $fc ? ['green' => '绿', 'yellow' => '黄', 'red' => '红'][$fc] : ($over ? '待核对' : '核对'); ?></button><?php if ($r['diff'] !== null): ?><span class="pps-diff <?php echo $over ? 'is-over' : 'is-ok'; ?>" title="系统实结算相对对照表的差距"><?php echo ($r['diff'] >= 0 ? '+' : '') . number_format($r['diff'] * 100, 1); ?>%</span><?php endif; ?></td>
<?php   break;
    case 'ref': ?>
          <td class="pps-n"><?php echo $r['ref'] === null ? '<span class="pps-zero">—</span>' : number_format($r['ref'], 2); ?></td>
<?php   break;
    case 'card': ?>
          <td class="pps-card-no"><?php echo ($pf['bank_card'] ?? '') !== '' ? e(trim(chunk_split($pf['bank_card'], 4, ' '))) : '<span class="pps-zero">—</span>'; ?></td>
<?php   break;
    case 'sup': ?>
          <td class="pps-dept"><?php echo ($pf['supervisor'] ?? '') !== '' ? e($pf['supervisor']) : '<span class="pps-zero">—</span>'; ?></td>
<?php   break;
    case 'sign': ?>
          <td class="pps-sign"><?php echo !empty($pf['signed']) ? '<span class="pps-ok">√</span>' : '<span class="pps-zero">—</span>'; ?></td>
<?php   break;
    case 'ins': ?>
          <td><?php echo ($pf['insurance_company'] ?? '') !== '' ? e($pf['insurance_company']) : '<span class="pps-zero">—</span>'; ?></td>
<?php   break;
    case 'resv': ?>
          <td class="pps-n"><?php echo ($pf['reserve_deducted'] ?? null) === null ? '<span class="pps-zero">—</span>' : number_format((float)$pf['reserve_deducted'], 2); ?></td>
<?php   break;
    case 'note': ?>
          <td class="pps-note" title="<?php echo e($r['note']); ?>"><?php echo e($r['note']); ?></td>
<?php   break;
    case 'days': echo '          ', pps_days_cell($r), "\n"; break;
    default: $k = $ck;
        if ($k === 'other' && isset($ppsDirectors[$rid])) {
            $director = $ppsDirectors[$rid];
            $button = '<button type="button" class="pps-director" data-id="' . $rid . '" data-name="' . e($r['name']) . '" data-amount="' . e($director['amount'] === null ? '' : $director['amount']) . '" title="仅核定本月董事长、董事会补助，不沿用到下月"' . ($ppsLocked ? ' disabled' : '') . '>' . ($director['confirmed'] ? '补助已核定 · 修改' : '补助本月核定') . '</button>';
            echo '          ', str_replace('</td>', $button . '</td>', pps_cell($r['other'], '', 'other')), "\n"; break;
        }
        if ($k === 'parking' && $ppsAll) {
            $parkingButton = '<button type="button" class="pps-parking" data-id="' . $rid . '" data-name="' . e($r['name']) . '" data-amount="' . (float)$r['parking_setting']['amount'] . '" title="设置每月车位费"' . ($ppsLocked ? ' disabled' : '') . '>设置</button>';
            echo '          ', str_replace('</td>', $parkingButton . '</td>', pps_cell($r['parking'], 'pps-cut', 'parking')), "\n"; break;
        }
        if ($k === 'net' && $ppsClaim && $rid === $ppsMine && $ppsClaim['claimed'] != 0) { echo '          ', str_replace('</td>', '<small class="pps-pend" title="已申请 / 已结算的金额自动从实结算里扣除">已申请 −' . number_format($ppsClaim['claimed'], 2) . '<br>可申请 ' . number_format($ppsClaim['amount'], 2) . '</small></td>', pps_cell($r[$k], pps_cell_class($k), $k)), "\n"; break; }
        if ($k === 'share' && $r['pending_n'] > 0) { echo '          ', str_replace('</td>', '<a class="pps-pend pps-pendlink" href="' . e(BASE_URL . '/project/batch_review.php?month=' . $ppsMonth . ($ppsAll ? '&emp=' . $rid : '')) . '" title="其中 ' . (int)$r['pending_n'] . ' 单尚未审核，按系统预计分成计入。点击查看这些订单' . ($ppsAll ? '并审核' : '，卡在哪、怎么解决') . '">含待审预计 ' . number_format($r['pending'], 2) . ' ›</a></td>', pps_cell($r['share'], '', 'share')), "\n"; break; }
        echo '          ', pps_cell($r[$k], pps_cell_class($k), $k), "\n";
    endswitch;
endforeach; ?>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php if ($ppsMine): $myReqs = psr_requests($ppsMine); ?>
  <details class="pps-mine"<?php echo $myReqs ? '' : ' hidden'; ?>><summary><i class="fas fa-receipt"></i> 我的结算申请与记录 <b><?php echo count($myReqs); ?></b></summary>
    <?php foreach ($myReqs as $rq): $isDone = $rq['status'] === 'settled'; ?>
    <div class="pps-req is-<?php echo e($rq['status']); ?>"><div class="pps-req-head"><strong><?php echo e($rq['payroll_month']); ?></strong> · <?php echo (int)$rq['order_count']; ?> 项 · ¥<?php echo number_format((float)$rq['amount'], 2); ?>
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
  <?php if (ps_director_can($ppsActor)): ?>
  <dialog class="pps-dialog" id="ppsDirector"><form method="post" class="pps-directorform" autocomplete="off">
    <h6>董事长 / 董事会补助 · <span data-f="title"></span></h6>
    <input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="save_director"><input type="hidden" name="employee_id"><input type="hidden" name="month">
    <p class="pps-hint">核定月份：<strong data-f="month"></strong>。仅计入本月“其他补助”，下月须重新填写；填0表示本月不发。此栏金额还可能包含其他补助与超时补贴。</p>
    <label>本月核定金额（元）<input name="amount" type="number" min="0" max="9999999.99" step="0.01" required></label>
    <label>核定备注（选填）<input name="note" maxlength="160" placeholder="例如：本月董事会核定结果"></label>
    <p class="pps-hint">原工资表导入金额可重新核定。金额变动后需重新核对整月实结算。</p>
    <p class="pps-err" data-f="err" role="alert" hidden></p>
    <div class="pps-actions"><button type="button" data-director-cancel>取消</button><button type="submit" class="is-primary">保存本月核定金额</button></div>
  </form></dialog>
  <?php endif; ?>
  <dialog class="pps-dialog" id="ppsParking"><form method="post" class="pps-form pps-parkingform" autocomplete="off">
    <h6>每月车位费 · <span data-f="title"></span></h6>
    <input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="save_parking"><input type="hidden" name="employee_id"><input type="hidden" name="month">
    <p class="pps-hint">从 <strong data-f="month"></strong> 起每月自动扣减，修改后持续沿用。已锁定月份不变。</p>
    <label>每月扣款金额（元）<input name="amount" type="number" min="0" max="9999999.99" step="0.01" value="100" required></label>
    <p class="pps-hint">通常为100元；填0或点击“取消车位费”停止从本月起扣款。</p>
    <p class="pps-err" data-f="err" role="alert" hidden></p>
    <div class="pps-actions"><button type="button" data-f="cancel">返回</button><button type="submit" data-parking-stop formnovalidate>取消车位费</button><button type="submit" class="is-primary">保存并按月扣减</button></div>
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
      <label class="pps-only-payable"><input type="checkbox" name="payable_only" value="1"> 只调整应结算（实结算、结算申请不变）</label>
      <button type="submit">添加</button></form>
    <p class="pps-err" data-f="itemerr" hidden></p>
    <small>名称含“分成 / 提成 / 游学 / 留学”记入项目分成，含“补单”记入固定服务费，其余记入其他补助；车位、预留金写负数。锁月后不能改。</small></div></dialog>
<?php endif; ?>
</section>
