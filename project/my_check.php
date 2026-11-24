<?php
require_once __DIR__ . '/../includes/ProjectEmployeeOrderExport.php';
require_once __DIR__ . '/../includes/ProjectPayrollSheet.php';
// 合作人员核对某月报酬：按“业务 / 订单类型 / 岗位”汇总本人分成，逐单明细，固定项和调整一起列出，和自己的账逐项对；不对的订单直接点“申请更正”。财务可用 employee_id 看任何人。
$actor = ps_require_actor();
$isFinance = ($actor['role'] ?? '') === 'finance';
$employeeId = $isFinance ? (int)($_GET['employee_id'] ?? ($actor['employee_id'] ?? 0)) : (int)($actor['employee_id'] ?? 0);
if ($employeeId <= 0) { http_response_code(403); exit('无权限'); }
$month = (string)($_GET['month'] ?? pps_default_month());
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = pps_default_month();
$eq = db()->prepare('SELECT name,department FROM employees WHERE id=?'); $eq->execute([$employeeId]); $emp = $eq->fetch();
if (!$emp) { http_response_code(404); exit('人员不存在'); }
[$head, $rows, $adj, $tot] = peo_build($employeeId, $month);
$sheet = pps_sheet($month, $employeeId); $me = $sheet['rows'][0] ?? null;
// 汇总：业务 / 订单类型 / 岗位；只把“已审核”和“计入报酬表的预计”算进合计，暂不计入的单独列
$groups = [];
foreach ($rows as $r) {
    $kind = (string)$r[20]; $counted = strpos($kind, '暂不计入') === false;
    $key = $r[4] . ' · ' . ($r[5] !== '' ? $r[5] : '未选类型') . ' · ' . $r[12];
    $g = &$groups[$key]; if (!isset($g)) $g = ['n' => 0, 'price' => 0.0, 'approved' => 0.0, 'estimate' => 0.0, 'excluded' => 0.0, 'nex' => 0];
    $g['n']++; $g['price'] += (float)$r[6];
    if (strpos($kind, '已审核') === 0) $g['approved'] += (float)$r[18]; elseif ($counted) $g['estimate'] += (float)$r[18]; else { $g['excluded'] += (float)$r[18]; $g['nex']++; }
    unset($g);
}
ksort($groups);
$nameFor = ['draft' => '草稿', 'review' => '待审'];
$page_title = '核对我的报酬';
include __DIR__ . '/../includes/header.php';
function mc_money($v) { return '¥' . number_format((float)$v, 2); }
?>
<style>.mc td,.mc th{vertical-align:middle}.mc-diff{font-weight:700}.mc-ok{color:#1f7a4d}.mc-bad{color:#c0392b}</style>
<div class="container-fluid mc" style="max-width:1240px">
  <div style="background:linear-gradient(135deg,#e4f3e7,#fff0d8);border:1px solid #e1ebdc;border-radius:20px;padding:20px 28px;margin:14px 0">
    <h2 style="margin:0 0 6px;font-size:1.35rem;font-weight:800;color:#243a34"><i class="fas fa-balance-scale mr-2"></i>核对 <?php echo e($emp['name']); ?> 的 <?php echo e($month); ?> 报酬</h2>
    <p style="margin:0;color:#4f655c;font-size:.92rem;line-height:1.8">① 先看下面“按类型汇总”，在右边“我的账”里填你自己算的数，系统当场算出差额，哪一类不对一目了然；② 点开那一类的订单，逐单对，不对的点“申请更正”提交给财务；③ 也可以 <a href="<?php echo BASE_URL; ?>/project/employee_orders_export.php?month=<?php echo e($month); ?><?php echo $isFinance ? '&amp;employee_id=' . $employeeId : ''; ?>"><b>导出 Excel 表格</b></a> 在表格里对。</p>
    <form method="get" class="form-inline mt-2"><?php if ($isFinance): ?><input type="hidden" name="employee_id" value="<?php echo $employeeId; ?>"><?php endif; ?><label class="mr-2 small text-muted">月份</label><input type="month" name="month" value="<?php echo e($month); ?>" class="form-control form-control-sm mr-2" onchange="this.form.submit()"><a class="small" href="<?php echo BASE_URL; ?>/project/fix_requests.php">我的更正申请</a></form>
  </div>

  <div class="card mb-3"><div class="card-header"><b>一、按类型汇总</b> <span class="small text-muted">（订单分成：已审核按审核结果，未审核按系统预计；“暂不计入”的订单不进工资合计）</span></div>
    <div class="table-responsive"><table class="table table-sm mb-0"><thead class="thead-light"><tr><th>业务 · 订单类型 · 岗位</th><th class="text-right">订单数</th><th class="text-right">售价合计</th><th class="text-right">已审核分成</th><th class="text-right">待审预计</th><th class="text-right">合计</th><th class="text-right" style="width:140px">我的账（填写）</th><th class="text-right" style="width:110px">差额</th></tr></thead><tbody>
    <?php $i = 0; foreach ($groups as $k => $g): $i++; $sum = $g['approved'] + $g['estimate']; ?><tr>
      <td><?php echo e($k); ?><?php if ($g['nex']): ?><div class="small text-muted">另有 <?php echo (int)$g['nex']; ?> 单暂不计入（未发货 / 未到账，预计 <?php echo mc_money($g['excluded']); ?>）</div><?php endif; ?></td>
      <td class="text-right"><?php echo (int)$g['n']; ?></td><td class="text-right"><?php echo mc_money($g['price']); ?></td><td class="text-right"><?php echo mc_money($g['approved']); ?></td><td class="text-right"><?php echo mc_money($g['estimate']); ?></td>
      <td class="text-right font-weight-bold mc-sys" data-v="<?php echo round($sum, 2); ?>"><?php echo mc_money($sum); ?></td>
      <td><input class="form-control form-control-sm text-right mc-mine" inputmode="decimal" placeholder="我算的" aria-label="我的账"></td><td class="text-right mc-diff">—</td></tr><?php endforeach; ?>
    <?php if (!$groups): ?><tr><td colspan="8" class="text-center text-muted py-4">这个月没有你参与的订单</td></tr><?php endif; ?></tbody>
    <?php if ($groups): ?><tfoot><tr class="font-weight-bold"><td>订单分成小计</td><td class="text-right"><?php echo (int)$tot['orders']; ?></td><td class="text-right"><?php echo mc_money($tot['price']); ?></td><td class="text-right"><?php echo mc_money($tot['approved']); ?></td><td class="text-right"><?php echo mc_money($tot['estimate']); ?></td><td class="text-right"><?php echo mc_money($tot['approved'] + $tot['estimate']); ?></td><td></td><td></td></tr></tfoot><?php endif; ?></table></div></div>

  <?php if ($me): ?><div class="card mb-3"><div class="card-header"><b>二、固定项和调整</b> <span class="small text-muted">（与首页报酬表同一口径）</span></div>
    <div class="table-responsive"><table class="table table-sm mb-0"><thead class="thead-light"><tr><th>项目</th><th class="text-right">系统金额</th><th class="text-right" style="width:140px">我的账（填写）</th><th class="text-right" style="width:110px">差额</th></tr></thead><tbody>
    <?php $fixed = [['固定服务费', $me['base']], ['全勤奖', $me['bonus']], ['经理补助', $me['mgr']], ['组长补助', $me['lead']], ['保险补助', $me['ins_sub']], ['其他补助 / 超时补贴 / 提成项', $me['other']], ['项目分成合计（含月度规则、跨月调整、待审预计）', $me['share']]];
    foreach ($fixed as [$lab, $v]): if (abs((float)$v) < 0.005 && $lab !== '项目分成合计（含月度规则、跨月调整、待审预计）') continue; ?><tr><td><?php echo e($lab); ?></td><td class="text-right mc-sys" data-v="<?php echo round((float)$v, 2); ?>"><?php echo mc_money($v); ?></td><td><input class="form-control form-control-sm text-right mc-mine" inputmode="decimal" placeholder="我算的" aria-label="我的账"></td><td class="text-right mc-diff">—</td></tr><?php endforeach; ?>
    </tbody><tfoot><tr class="font-weight-bold"><td>应结算合计 <span class="small text-muted font-weight-normal">（扣款前）</span></td><td class="text-right mc-sys" data-v="<?php echo round((float)$me['payable'], 2); ?>"><?php echo mc_money($me['payable']); ?></td><td><input class="form-control form-control-sm text-right mc-mine" inputmode="decimal" placeholder="我算的总数" aria-label="我的账"></td><td class="text-right mc-diff">—</td></tr>
    <tr><td class="small text-muted">扣款：保险 / 车位 / 风险预留等 <?php echo mc_money((float)$me['deduct'] + (float)$me['parking'] + (float)$me['reserve'] + (float)$me['unit_ins'] + (float)$me['personal_ins']); ?>　→　<b>实结算 <?php echo mc_money($me['net']); ?></b></td><td></td><td></td><td></td></tr></tfoot></table></div>
    <?php if (!empty($me['note'])): ?><div class="card-body small text-muted border-top">说明：<?php echo e($me['note']); ?></div><?php endif; ?></div><?php endif; ?>

  <?php if ($adj): ?><div class="card mb-3"><div class="card-header"><b>三、本月调整</b> <span class="small text-muted">（跨月退款补扣、规则更正等，已含在上面的项目分成里）</span></div><div class="table-responsive"><table class="table table-sm mb-0"><tbody>
    <?php foreach ($adj as $a): ?><tr><td><?php echo e($a[0]); ?></td><td class="text-nowrap"><?php echo e($a[1]); ?></td><td class="text-right font-weight-bold"><?php echo ($a[2] > 0 ? '+' : '') . mc_money($a[2]); ?></td><td class="small"><?php echo e($a[3]); ?><div class="text-muted"><?php echo e($a[4]); ?></div></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>

  <div class="card mb-4"><div class="card-header"><b>四、逐单明细</b> <span class="small text-muted">（<?php echo count($rows); ?> 单；这单对不上就点右边“申请更正”）</span></div>
    <div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead class="thead-light"><tr><th>订单号 / 日期</th><th>业务 · 类型</th><th class="text-right">售价</th><th>状态</th><th>我的岗位</th><th class="text-right">成本</th><th class="text-right">比例</th><th class="text-right">我的分成</th><th>口径</th><th></th></tr></thead><tbody>
    <?php $idq = db()->prepare('SELECT id FROM project_orders WHERE order_no=? ORDER BY id DESC LIMIT 1'); foreach ($rows as $r): $idq->execute([$r[0]]); $oid = (int)$idq->fetchColumn(); $out = strpos((string)$r[20], '暂不计入') !== false; ?><tr class="<?php echo $out ? 'text-muted' : ''; ?>">
      <td><a href="<?php echo BASE_URL; ?>/project/order.php?id=<?php echo $oid; ?>" target="_blank"><?php echo e($r[0]); ?></a><div class="small text-muted"><?php echo e($r[1]); ?> · <?php echo e($r[3] ?: ''); ?></div></td>
      <td class="small"><?php echo e($r[4]); ?><div class="text-muted"><?php echo e($r[5]); ?></div></td><td class="text-right"><?php echo mc_money($r[6]); ?></td>
      <td class="small"><?php echo e($r[11]); ?> · <?php echo e($r[10]); ?><div class="text-muted"><?php echo e($r[9]); ?></div></td><td class="small"><?php echo e($r[12]); ?></td>
      <td class="text-right"><?php echo $r[15] === '' ? '—' : mc_money($r[15]); ?></td><td class="text-right"><?php echo $r[17] === '' ? '—' : e(rtrim(rtrim(number_format((float)$r[17] * 100, 2), '0'), '.')) . '%'; ?></td>
      <td class="text-right font-weight-bold"><?php echo mc_money($r[18]); ?><?php if ((float)$r[19] > 0): ?><div class="small text-muted font-weight-normal">含补助 <?php echo mc_money($r[19]); ?></div><?php endif; ?></td>
      <td class="small text-muted" style="max-width:190px"><?php echo e($r[20]); ?></td>
      <td class="text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="<?php echo BASE_URL; ?>/project/fix_request.php?order_id=<?php echo $oid; ?>&amp;employee_id=<?php echo $employeeId; ?>&amp;month=<?php echo e($month); ?>"><i class="fas fa-edit"></i> 申请更正</a></td></tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="10" class="text-center text-muted py-4">没有订单</td></tr><?php endif; ?></tbody></table></div></div>
</div>
<script>
document.querySelectorAll('.mc-mine').forEach(function (inp) {
  inp.addEventListener('input', function () {
    var tr = inp.closest('tr'), sys = parseFloat(tr.querySelector('.mc-sys').getAttribute('data-v')), d = tr.querySelector('.mc-diff'), v = parseFloat(inp.value.replace(/[,，¥￥\s]/g, ''));
    if (isNaN(v)) { d.textContent = '—'; d.className = 'text-right mc-diff'; return; }
    var diff = Math.round((v - sys) * 100) / 100;
    d.textContent = Math.abs(diff) < 0.005 ? '一致 ✓' : ((diff > 0 ? '我多 +' : '我少 ') + diff.toFixed(2));
    d.className = 'text-right mc-diff ' + (Math.abs(diff) < 0.005 ? 'mc-ok' : 'mc-bad');
  });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
