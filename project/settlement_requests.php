<?php
require_once __DIR__ . '/../includes/ProjectSettlementRequest.php';
require_once __DIR__ . '/../includes/MiniXlsx.php';
// 结算申请：合作人员在首页报酬表里选订单提交，财务打款后点“已结算”，申请里的订单在库里标注已结算，不能再重复结算。
$actor = ps_require_finance();
psr_ensure();
if (isset($_GET['detail_id'])) {
    $detailId = filter_var($_GET['detail_id'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if (!$detailId) { http_response_code(400); exit('申请单号无效'); }
    $exists = db()->prepare('SELECT id FROM project_settlement_requests WHERE id=?'); $exists->execute([$detailId]);
    if (!$exists->fetchColumn()) { http_response_code(404); exit('申请不存在'); }
    header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: private, no-store');
    $detailRows = psr_request_orders($detailId);
    include __DIR__ . '/../includes/settlement_request_details.php'; exit;
}
$error = ''; $success = (string)($_SESSION['psr_flash'] ?? ''); unset($_SESSION['psr_flash']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    try {
        $id = (int)($_POST['id'] ?? 0);
        if (($_POST['do'] ?? '') === 'export') { // 导出批量打款表：只含选中的、无异议的待打款申请
            $d = psr_export_data((array)($_POST['ids'] ?? []));
            if (!$d['people']) throw new RuntimeException('选中的申请都有异议，没有可导出的内容；请先处理异议');
            $pay = [['序号', '姓名', '部门', '工行卡号', '打款金额（元）', '结算月份', '结算项数', '申请单号', '备注']];
            foreach ($d['people'] as $i => $pp) {
                $pay[] = [$i + 1, $pp['name'], (string)$pp['department'], ['text' => $pp['card']], (float)$pp['amount'], $pp['months'], (int)$pp['orders'], '#' . implode(' #', $pp['requests']), $pp['card'] === '' ? '缺银行卡号，请补充后再打款' : ''];
            }
            $pay[] = ['', '合计', '', '', (float)$d['total'], '', array_sum(array_column($d['people'], 'orders')), '', ''];
            $det = [['姓名', '结算月份', '订单号', '业务', '订单日期', '结算金额（元）', '申请单号']];
            foreach ($d['orders'] as $o) $det[] = [$o['name'], $o['month'], ['text' => (string)$o['order_no']], (string)$o['project_type'], (string)$o['order_date'], (float)$o['amount'], '#' . $o['request_id']];
            $bin = mxl_build(['打款清单' => ['rows' => $pay, 'widths' => [6, 12, 14, 26, 16, 14, 8, 14, 28], 'header' => 1], '结算明细' => ['rows' => $det, 'widths' => [12, 12, 26, 14, 12, 16, 12], 'header' => 1]]);
            ps_audit('settlement_request', 0, 'export', $actor, ['requests' => array_merge(...array_column($d['people'], 'requests')), 'people' => count($d['people']), 'total' => $d['total'], 'held' => count($d['held'])]);
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode('批量打款表_' . date('Ymd_His') . '.xlsx'));
            header('Content-Length: ' . strlen($bin)); header('X-Content-Type-Options: nosniff');
            echo $bin; exit;
        }
        if (($_POST['do'] ?? '') === 'settle_many') {
            $res = psr_settle_many((array)($_POST['ids'] ?? []), $actor, '批量打款');
            if (!$res['ok'] && $res['fail']) throw new RuntimeException(implode('；', $res['fail']));
            $success = '已批量标注结算 ' . $res['ok'] . ' 条，这些结算项已记为已结算并通知申请人。' . ($res['fail'] ? '另有 ' . count($res['fail']) . ' 条未处理：' . implode('；', $res['fail']) : '');
        } elseif (($_POST['do'] ?? '') === 'sync_payroll') {
            $q = db()->prepare("SELECT employee_id,payroll_month FROM project_settlement_requests WHERE id=? AND status='pending'"); $q->execute([$id]); $target=$q->fetch();
            if (!$target) throw new RuntimeException('申请已处理，请刷新页面');
            pspr_sync_pending($actor,(int)$target['employee_id'],$target['payroll_month'],$_POST['expected_amount'] ?? null);
            $success = '已按首页扣减后的实结算同步该月待处理申请，已打款不会重复申请；请核对后再打款。';
        } elseif (($_POST['do'] ?? '') === 'settle') { psr_settle($id, $actor, (string)($_POST['note'] ?? '')); $success = '已标注结算：申请已记为已结算，并已通知申请人。'; }
        elseif (($_POST['do'] ?? '') === 'reject') { psr_reject($id, $actor, (string)($_POST['note'] ?? '')); $success = '已退回，结算项已释放，申请人可重新申请。'; }
        else throw new RuntimeException('操作无效');
    } catch (Throwable $e) { $error = $e instanceof RuntimeException ? $e->getMessage() : '操作失败，请稍后再试'; }
    if ($success !== '' && $error === '') {
        $_SESSION['psr_flash'] = $success;
        header('Location: '.BASE_URL.'/project/settlement_requests.php?'.http_build_query(array_intersect_key($_GET,array_flip(['status','q','month','page','embed']))),true,303); exit;
    }
}
$status = (string)($_GET['status'] ?? 'pending');
if (!in_array($status, ['pending', 'settled', 'rejected', 'all'], true)) $status = 'pending';
$search = mb_substr(trim((string)($_GET['q'] ?? '')),0,60);
$monthFilter = (string)($_GET['month'] ?? '');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$monthFilter)) $monthFilter = '';
$where = []; $params = [];
if ($status !== 'all') { $where[] = 'r.status=?'; $params[] = $status; }
if ($search !== '') { $where[] = '(e.name LIKE ? OR e.department LIKE ?)'; $params[] = '%'.$search.'%'; $params[] = '%'.$search.'%'; }
if ($monthFilter !== '') { $where[] = 'r.payroll_month=?'; $params[] = $monthFilter; }
$from = ' FROM project_settlement_requests r LEFT JOIN employees e ON e.id=r.employee_id' . ($where ? ' WHERE '.implode(' AND ',$where) : '');
$countQuery = db()->prepare('SELECT COUNT(*)'.$from); $countQuery->execute($params); $totalRows = (int)$countQuery->fetchColumn();
$pageCount = max(1,(int)ceil($totalRows/50)); $page = min($pageCount,max(1,(int)($_GET['page'] ?? 1)));
$q = db()->prepare('SELECT r.*,e.name,e.department'.$from.($status==='pending' ? ' ORDER BY r.created_at,r.id' : ' ORDER BY r.id DESC').' LIMIT 50 OFFSET '.(($page-1)*50));
$q->execute($params);
$rows = $q->fetchAll();
$counts = db()->query('SELECT status,COUNT(*) FROM project_settlement_requests GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
$bank = [];
try { foreach (db()->query('SELECT employee_id,bank_card FROM project_partner_profiles')->fetchAll() as $b) $bank[(int)$b['employee_id']] = (string)$b['bank_card']; } catch (Throwable $e) {}
$checks = []; $readyCount = 0; $readyAmount = 0.0;
foreach ($rows as $r) if ($r['status'] === 'pending') { $checks[$r['id']] = psr_check($r); if ($checks[$r['id']]['ok']) { $readyCount++; $readyAmount += (float)$r['amount']; } }
$people = [];
foreach ($rows as $r) if ($r['status']==='pending' && !empty($checks[$r['id']]['ok'])) {
    $eid=(int)$r['employee_id'];
    if (!isset($people[$eid])) $people[$eid]=['name'=>$r['name'],'department'=>$r['department'],'cents'=>0,'requests'=>0];
    $people[$eid]['cents']+=(int)round((float)$r['amount']*100); $people[$eid]['requests']++;
}
$pageUrl = function ($change) use ($status,$search,$monthFilter,$page) { $base = ['status'=>$status,'q'=>$search,'month'=>$monthFilter,'page'=>$page]; if (!empty($_GET['embed'])) $base['embed'] = 1; return '?'.http_build_query(array_merge($base,$change)); };
$labels = ['pending' => ['待打款', 'warning'], 'settled' => ['已结算', 'success'], 'rejected' => ['已退回', 'secondary']];
$page_title = '结算申请';
include __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid" style="max-width:<?php echo !empty($_GET['embed']) ? '100%' : '1100px'; ?>">
  <h2 class="h4 my-3"><i class="fas fa-hand-holding-usd mr-2"></i>结算申请</h2>
  <p class="text-muted small">待打款金额按首页扣减后的整月实结算计算，扣除该月已打款金额。旧逐单申请或金额变化时，可点击“同步整月实结算”更新，金额有变化需重新核对；未核对通过或仍含待审核金额不可导出、不可确认结算。实际打款后再点“已结算”。</p>
  <div class="btn-group btn-group-sm mb-3"><?php foreach (['pending' => '待打款', 'settled' => '已结算', 'rejected' => '已退回', 'all' => '全部'] as $k => $v): ?><a class="btn btn-outline-secondary<?php echo $status === $k ? ' active' : ''; ?>" href="<?php echo e($pageUrl(['status'=>$k,'page'=>1])); ?>"><?php echo e($v); ?><?php if ($k !== 'all' && !empty($counts[$k])): ?> <span class="badge badge-light"><?php echo (int)$counts[$k]; ?></span><?php endif; ?></a><?php endforeach; ?></div>
  <form method="get" class="form-inline mb-3"><?php if (!empty($_GET['embed'])): ?><input type="hidden" name="embed" value="1"><?php endif; ?><input type="hidden" name="status" value="<?php echo e($status); ?>"><input name="q" class="form-control form-control-sm mr-2" value="<?php echo e($search); ?>" placeholder="姓名 / 部门" aria-label="姓名或部门"><input type="month" name="month" class="form-control form-control-sm mr-2" value="<?php echo e($monthFilter); ?>" aria-label="结算月份"><button class="btn btn-sm btn-outline-primary">筛选</button><span class="small text-muted ml-2">共 <?php echo $totalRows; ?> 条，待打款按申请时间先后排列</span></form>
  <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
  <?php if ($status==='pending' && $people): ?><div class="card mb-3"><div class="card-header">按人打款汇总 <small class="text-muted">仅合并本页无异议申请</small></div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>姓名 / 部门</th><th>收款卡号</th><th>本次金额</th><th>快捷操作</th></tr></thead><tbody>
  <?php foreach ($people as $eid=>$person): $card=(string)($bank[$eid]??''); $amount=number_format($person['cents']/100,2,'.',''); ?>
    <tr><td><?php echo e($person['name']); ?><small class="text-muted d-block"><?php echo e($person['department']); ?> · <?php echo $person['requests']; ?> 笔申请</small></td><td><code><?php echo $card!=='' ? e($card) : '缺银行卡号'; ?></code></td><td><b>¥<?php echo number_format($person['cents']/100,2); ?></b></td><td><button type="button" class="btn btn-sm btn-outline-secondary sr-copy" data-copy="<?php echo e($person['name']."\t".$card."\t".$amount); ?>">复制收款信息</button><button type="button" class="btn btn-sm btn-outline-success sr-person ml-1" data-employee="<?php echo $eid; ?>">仅选择此人</button></td></tr>
  <?php endforeach; ?></tbody></table></div></div><?php endif; ?>
  <?php if ($status === 'pending' && $rows): ?>
  <form method="post" id="batchForm" class="sr-bar"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>">
    <label class="mb-0"><input type="checkbox" id="srAll"> 全选本页无异议的</label>
    <span class="sr-sum">已选 <b id="srCnt">0</b> 条 · 打款合计 <b id="srAmt">¥0.00</b>　<small class="text-muted">（本页无异议 <?php echo (int)$readyCount; ?> 条 · ¥<?php echo number_format($readyAmount, 2); ?>）</small></span>
    <span class="ml-auto"><button name="do" value="export" class="btn btn-sm btn-primary" id="srExport"><i class="fas fa-file-excel mr-1"></i>导出批量打款表</button>
    <button name="do" value="settle_many" class="btn btn-sm btn-success ml-1" id="srSettle" onclick="return confirm('确认已按打款表全部打款？确认后选中的申请将标注已结算，不能撤销。');"><i class="fas fa-check-double mr-1"></i>批量已结算</button></span>
  </form>
  <style>.sr-bar{position:sticky;top:0;z-index:5;display:flex;flex-wrap:wrap;align-items:center;gap:.8rem;padding:.6rem .9rem;margin-bottom:1rem;border:1px solid #cfe0d6;border-radius:12px;background:rgba(246,250,247,.96);backdrop-filter:blur(6px);box-shadow:0 6px 16px -10px rgba(35,75,61,.4)}.sr-sum b{color:#1f6a52}.sr-card .sr-pick{margin-right:.5rem}.sr-hold{background:#fff6dc;border:1px solid #ecd596;border-radius:8px;padding:.3rem .6rem;font-size:.82rem;color:#7a5010;margin-bottom:.4rem}</style>
  <?php endif; ?>
  <?php if (!$rows): ?><div class="text-muted text-center py-5">暂无申请</div><?php endif; ?>
  <?php foreach ($rows as $r): [$label, $tone] = $labels[$r['status']]; ?>
  <?php $ck = $checks[$r['id']] ?? null; ?>
  <div class="card mb-3 sr-card"><div class="card-header d-flex flex-wrap justify-content-between align-items-center">
    <span><?php if ($ck): ?><input type="checkbox" class="sr-pick" form="batchForm" name="ids[]" value="<?php echo (int)$r['id']; ?>" data-employee="<?php echo (int)$r['employee_id']; ?>" data-amt="<?php echo e($r['amount']); ?>"<?php echo $ck['ok'] ? '' : ' disabled'; ?>><?php endif; ?><strong><?php echo e($r['name']); ?></strong> <small class="text-muted"><?php echo e($r['department']); ?></small> · <?php echo e($r['payroll_month']); ?> · <?php echo (int)$r['order_count']; ?> 项 · <strong>¥<?php echo number_format((float)$r['amount'], 2); ?></strong></span>
    <span><span class="badge badge-<?php echo $tone; ?>"><?php echo e($label); ?></span> <small class="text-muted"><?php echo e(substr((string)$r['created_at'], 0, 16)); ?> 申请<?php echo $r['settled_at'] ? ' · ' . e(substr((string)$r['settled_at'], 0, 16)) . ' 结算（' . e($r['handled_by']) . '）' : ''; ?></small></span></div>
    <div class="card-body py-2">
      <?php if ($ck && !$ck['ok']): ?><div class="sr-hold"><i class="fas fa-exclamation-triangle mr-1"></i>有异议，暂不可导出：<?php echo e(implode('；', $ck['issues'])); ?></div><?php endif; ?>
      <?php if ($ck && $ck['ok'] && !$ck['has_card']): ?><div class="sr-hold"><i class="fas fa-id-card mr-1"></i>缺银行卡号：导出的打款表里会标出，请先在首页报酬表里补充。</div><?php endif; ?>
      <?php if (!empty($bank[(int)$r['employee_id']])): ?><div class="small mb-2">收款卡号：<code><?php echo e(trim(chunk_split($bank[(int)$r['employee_id']], 4, ' '))); ?></code></div><?php endif; ?>
      <?php if ($r['status'] === 'rejected'): ?><div class="small text-muted">退回原因：<?php echo e($r['note']); ?></div>
      <?php else: ?><details data-detail-url="?detail_id=<?php echo (int)$r['id']; ?>"><summary class="small text-muted" style="cursor:pointer">展开结算明细（<?php echo (int)$r['order_count']; ?>）</summary><div class="sr-detail-content small text-muted py-2">展开时加载明细</div></details><?php endif; ?>
      <?php if ($r['status'] === 'pending'): ?>
      <form method="post" class="form-inline mt-2"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
        <?php if ($ck && $ck['sync_needed'] && $ck['sync_amount']>0): ?><input type="hidden" name="expected_amount" value="<?php echo e(number_format($ck['sync_amount'],2,'.','')); ?>"><button name="do" value="sync_payroll" class="btn btn-sm btn-outline-primary mr-2" onclick="return confirm('将本月待处理申请合并为扣减后的实结算 ¥<?php echo e(number_format($ck['sync_amount'],2,'.','')); ?>（已扣除已打款）？金额变化时需重新核对，不会确认打款。');">同步整月实结算 ¥<?php echo number_format($ck['sync_amount'],2); ?></button><?php endif; ?>
        <input name="note" class="form-control form-control-sm mr-2" style="min-width:240px" maxlength="200" placeholder="备注（退回时必填原因）">
        <button name="do" value="settle" class="btn btn-sm btn-success mr-2"<?php echo $ck && !$ck['ok'] ? ' disabled' : ''; ?> onclick="return confirm('确认已打款？确认后申请将标注已结算，不能撤销。');"><i class="fas fa-check mr-1"></i>已结算</button>
        <button name="do" value="reject" class="btn btn-sm btn-outline-danger">退回</button></form>
      <?php endif; ?>
    </div></div>
  <?php endforeach; ?>
  <?php if ($pageCount>1): ?><nav class="d-flex justify-content-between align-items-center my-3" aria-label="结算申请分页"><span>第 <?php echo $page; ?> / <?php echo $pageCount; ?> 页，每页 50 条</span><span><?php if ($page>1): ?><a class="btn btn-sm btn-outline-secondary mr-2" href="<?php echo e($pageUrl(['page'=>$page-1])); ?>">上一页</a><?php endif; ?><?php if ($page<$pageCount): ?><a class="btn btn-sm btn-outline-secondary" href="<?php echo e($pageUrl(['page'=>$page+1])); ?>">下一页</a><?php endif; ?></span></nav><?php endif; ?>
</div>
<script>
(function () {
  document.querySelectorAll('details[data-detail-url]').forEach(function (details) {
    details.addEventListener('toggle', function () {
      if (!details.open || details.dataset.loaded || details.dataset.loading) return;
      var target = details.querySelector('.sr-detail-content'); details.dataset.loading = '1'; target.textContent = '明细加载中…';
      fetch(details.dataset.detailUrl, { credentials: 'same-origin' }).then(function (r) {
        if (!r.ok || r.redirected) throw new Error('加载失败，请刷新页面后重试'); return r.text();
      }).then(function (html) { target.innerHTML = html; details.dataset.loaded = '1'; }).catch(function (e) {
        target.textContent = e.message + '，关闭后重新展开可重试。';
      }).finally(function () { delete details.dataset.loading; });
    });
  });
  document.querySelectorAll('.sr-copy').forEach(function (button) {
    button.addEventListener('click', function () {
      if (!navigator.clipboard) { window.prompt('请复制收款信息',button.dataset.copy); return; }
      navigator.clipboard.writeText(button.dataset.copy).then(function () { button.textContent = '已复制'; }).catch(function () { window.prompt('请复制收款信息',button.dataset.copy); });
    });
  });
  document.querySelectorAll('form[method="post"]').forEach(function (form) { form.addEventListener('submit',function (event) {
    var button=event.submitter || document.activeElement;
    if (!button || button.value==='export') return;
    var action=document.createElement('input'); action.type='hidden'; action.name='do'; action.value=button.value; form.appendChild(action);
    form.querySelectorAll('button').forEach(function (b) { b.disabled=true; }); button.textContent='正在处理…';
  }); });
  var picks = [].slice.call(document.querySelectorAll('.sr-pick')), all = document.getElementById('srAll'); if (!all) return;
  var yuan = function (v) { return '\u00a5' + v.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  function sum() {
    var on = picks.filter(function (b) { return b.checked; }), t = on.reduce(function (s, b) { return s + parseFloat(b.getAttribute('data-amt')); }, 0);
    document.getElementById('srCnt').textContent = on.length; document.getElementById('srAmt').textContent = yuan(t);
    document.getElementById('srExport').disabled = document.getElementById('srSettle').disabled = !on.length;
    all.checked = on.length > 0 && on.length === picks.filter(function (b) { return !b.disabled; }).length;
  }
  all.addEventListener('change', function () { picks.forEach(function (b) { if (!b.disabled) b.checked = all.checked; }); sum(); });
  picks.forEach(function (b) { b.addEventListener('change', sum); }); sum();
  document.querySelectorAll('.sr-person').forEach(function (button) { button.addEventListener('click', function () {
    picks.forEach(function (b) { b.checked = !b.disabled && b.dataset.employee === button.dataset.employee; }); sum();
    document.getElementById('batchForm').scrollIntoView({ behavior: 'smooth', block: 'center' });
  }); });
  <?php if ($success): ?>
  if (window.parent && window.parent !== window) {
    try { window.parent.postMessage({ type: 'settlement_updated' }, '*'); } catch (e) {}
  }
  <?php endif; ?>
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
