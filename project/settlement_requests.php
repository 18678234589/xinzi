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
        } elseif (($_POST['do'] ?? '') === 'sync_all_payroll') {
            $synced = 0;
            $q = db()->query("SELECT r.id, r.employee_id, r.payroll_month, r.amount FROM project_settlement_requests r WHERE r.status='pending'");
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $tr) {
                $chk = psr_check($tr);
                if (!empty($chk['sync_needed']) && !empty($chk['sync_amount']) && $chk['sync_amount'] > 0) {
                    try {
                        pspr_sync_pending($actor, (int)$tr['employee_id'], $tr['payroll_month'], number_format($chk['sync_amount'], 2, '.', ''));
                        $synced++;
                    } catch (Throwable $e) {}
                }
            }
            $success = '已批量同步 ' . $synced . ' 笔待处理申请至最新整月实结算金额。';
        } elseif (($_POST['do'] ?? '') === 'bulk_green') {
            require_once __DIR__ . '/../includes/ProjectPayrollSheet.php';
            $m = $monthFilter ?: date('Y-m', strtotime('first day of last month'));
            $n = 0;
            foreach (pps_sheet($m, 0, true)['rows'] as $r) {
                if ($r['diff'] === null || abs($r['diff']) > PPF_TOLERANCE || ($r['flag']['color'] ?? '') === 'green') continue;
                ppf_save($actor, (int)$r['id'], $m, 'green', '系统金额与财务对照表差距 ' . round($r['diff']*100, 1) . '%，在 5% 以内，核对无异议，可以正常申请结算。', (string)$r['ref'], false);
                $n++;
            }
            $success = '已为 ' . $n . ' 位核对无异议的合作人员标记通过（绿标）。';
        } elseif (($_POST['do'] ?? '') === 'mark_green') {
            require_once __DIR__ . '/../includes/ProjectPayrollFlags.php';
            $rQ = db()->prepare('SELECT employee_id, payroll_month FROM project_settlement_requests WHERE id=?');
            $rQ->execute([$id]);
            $sr = $rQ->fetch(PDO::FETCH_ASSOC);
            if ($sr) {
                ppf_save($actor, (int)$sr['employee_id'], $sr['payroll_month'], 'green', '财务在结算申请页面手动复核确认通过。', null, false);
                $success = '已手动标记通过（绿标）。';
            }
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
$pageCount = max(1,(int)ceil($totalRows/75)); $page = min($pageCount,max(1,(int)($_GET['page'] ?? 1)));
$q = db()->prepare('SELECT r.*,e.name,e.department'.$from.($status==='pending' ? ' ORDER BY r.created_at,r.id' : ' ORDER BY r.id DESC').' LIMIT 75 OFFSET '.(($page-1)*75));
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
$labels = ['pending' => ['待处理', 'warning'], 'settled' => ['已结算', 'success'], 'rejected' => ['已退回', 'secondary']];
$allPending = db()->query("SELECT COUNT(*) n, COALESCE(SUM(amount),0) t FROM project_settlement_requests WHERE status='pending'")->fetch();
$paidMonth = db()->query("SELECT COUNT(*) n, COALESCE(SUM(amount),0) t FROM project_settlement_requests WHERE status='settled' AND settled_at>='" . date('Y-m-01') . "'")->fetch();
$heldCount = 0; foreach ($checks as $c) if (!$c['ok']) $heldCount++;
$srCss = __DIR__ . '/../assets/css/settlement_requests.css';
$page_title = '结算申请';
include __DIR__ . '/../includes/header.php';
$embed = !empty($_GET['embed']);
?>
<link rel="stylesheet" href="<?php echo e(BASE_URL . '/assets/css/settlement_requests.css?v=' . (is_file($srCss) ? filemtime($srCss) : 1)); ?>">
<div class="sr" style="max-width:<?php echo $embed ? '100%' : '1380px'; ?>">
  <header class="sr-hero">
    <div><div class="sr-eyebrow"><i class="fas fa-hand-holding-usd"></i> 财务打款工作台</div><h2>结算申请</h2>
      <p>按人汇总、无异议才能导出打款；打款后点「已结算」。金额以首页扣减后的整月实结算为准，已打款部分不会重复申请。</p></div>
    <div class="sr-kpis">
      <div class="sr-kpi is-main"><small>待打款</small><strong>¥<?php echo number_format((float)$allPending['t'], 2); ?></strong><em><?php echo (int)$allPending['n']; ?> 笔申请</em></div>
      <div class="sr-kpi"><small>本页无异议</small><strong><?php echo (int)$readyCount; ?><i> 笔</i></strong><em>¥<?php echo number_format($readyAmount, 2); ?></em></div>
      <div class="sr-kpi<?php echo $heldCount ? ' is-warn' : ''; ?>"><small>本页有异议</small><strong><?php echo $heldCount; ?><i> 笔</i></strong><em><?php echo $heldCount ? '需先处理' : '无'; ?></em></div>
      <div class="sr-kpi"><small>本月已打款</small><strong>¥<?php echo number_format((float)$paidMonth['t'], 2); ?></strong><em><?php echo (int)$paidMonth['n']; ?> 笔</em></div>
    </div>
  </header>

  <?php if ($success): ?><div class="sr-toast is-ok" role="status"><i class="fas fa-check-circle"></i> <?php echo e($success); ?></div><?php endif; ?>
  <?php if ($error): ?><div class="sr-toast is-bad" role="alert"><i class="fas fa-exclamation-circle"></i> <?php echo e($error); ?></div><?php endif; ?>

  <div class="sr-filter">
    <nav class="sr-tabs" aria-label="状态"><?php foreach (['pending' => '待处理', 'settled' => '已结算', 'rejected' => '已退回', 'all' => '全部'] as $k => $v): ?><a class="<?php echo $status === $k ? 'is-on' : ''; ?>" href="<?php echo e($pageUrl(['status'=>$k,'page'=>1])); ?>"><?php echo e($v); ?><?php if ($k !== 'all' && !empty($counts[$k])): ?><b><?php echo (int)$counts[$k]; ?></b><?php endif; ?></a><?php endforeach; ?></nav>
    <form method="get" class="sr-search"><?php if ($embed): ?><input type="hidden" name="embed" value="1"><?php endif; ?><input type="hidden" name="status" value="<?php echo e($status); ?>">
      <label><i class="fas fa-search"></i><input name="q" value="<?php echo e($search); ?>" placeholder="姓名 / 部门" aria-label="姓名或部门"></label>
      <input type="month" name="month" value="<?php echo e($monthFilter); ?>" aria-label="结算月份"><button>筛选</button><?php if ($search !== '' || $monthFilter !== ''): ?><a href="<?php echo e($pageUrl(['q'=>'','month'=>'','page'=>1])); ?>" class="sr-clear">清除</a><?php endif; ?>
      <span class="sr-count">共 <?php echo $totalRows; ?> 条<?php echo $status === 'pending' ? ' · 按申请先后' : ''; ?></span></form>
  </div>

  <?php if ($status === 'pending' && $rows): ?>
  <form method="post" id="batchForm" class="sr-bar"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>">
    <label class="sr-allpick"><input type="checkbox" id="srAll"> 全选无异议</label>
    <span class="sr-sum">已选 <b id="srCnt">0</b> 笔 · 打款合计 <b id="srAmt">¥0.00</b></span>
    <span class="sr-bar-act">
      <details class="sr-more"><summary><i class="fas fa-ellipsis-h"></i> 批量处理</summary><div>
        <button name="do" value="bulk_green" onclick="return confirm('为本月对照表差距在 5% 以内且无异议的人员一键标记通过（绿标）？');"><i class="fas fa-check"></i> 一键复核标绿</button>
        <button name="do" value="sync_all_payroll" onclick="return confirm('将所有金额发生变化的待处理申请批量同步至最新整月实结算金额？');"><i class="fas fa-sync"></i> 一键同步最新金额</button></div></details>
      <button name="do" value="export" class="sr-btn" id="srExport"><i class="fas fa-file-excel"></i> 导出打款表</button>
      <button name="do" value="settle_many" class="sr-btn is-go" id="srSettle" onclick="return confirm('确认已按打款表全部打款？确认后选中的申请将标注已结算，不能撤销。');"><i class="fas fa-check-double"></i> 批量已结算</button>
    </span>
  </form>
  <?php endif; ?>

  <?php if ($status==='pending' && $people): ?>
  <details class="sr-people"><summary><i class="fas fa-users"></i> 按人打款汇总 <small><?php echo count($people); ?> 人 · 仅含无异议申请</small></summary>
    <div class="sr-pgrid"><?php foreach ($people as $eid=>$person): $card=(string)($bank[$eid]??''); $amount=number_format($person['cents']/100,2,'.',''); ?>
      <div class="sr-pcard"><div><b><?php echo e($person['name']); ?></b><small><?php echo e($person['department']); ?> · <?php echo $person['requests']; ?> 笔</small></div>
        <strong>¥<?php echo number_format($person['cents']/100,2); ?></strong>
        <code class="<?php echo $card === '' ? 'is-miss' : ''; ?>"><?php echo $card!=='' ? e(trim(chunk_split($card, 4, ' '))) : '缺银行卡号'; ?></code>
        <div class="sr-pact"><button type="button" class="sr-copy" data-copy="<?php echo e($person['name']."\t".$card."\t".$amount); ?>"><i class="far fa-copy"></i> 复制收款信息</button><button type="button" class="sr-person" data-employee="<?php echo $eid; ?>">仅选此人</button></div></div>
    <?php endforeach; ?></div></details>
  <?php endif; ?>

  <?php if (!$rows): ?><div class="sr-empty"><i class="far fa-check-circle"></i><b>暂无申请</b><span><?php echo $status === 'pending' ? '所有申请都已处理完，辛苦了。' : '换个状态或条件试试。'; ?></span></div>
  <?php else: ?>
  <div class="sr-list">
  <?php foreach ($rows as $r): [$label, $tone] = $labels[$r['status']]; $ck = $checks[$r['id']] ?? null; $rid = (int)$r['id'];
        $card = (string)($bank[(int)$r['employee_id']] ?? ''); ?>
    <article class="sr-row is-<?php echo e($r['status']); ?><?php echo $ck && !$ck['ok'] ? ' is-held' : ''; ?>" id="sr<?php echo $rid; ?>">
      <div class="sr-main">
        <span class="sr-pickcell"><?php if ($ck): ?><input type="checkbox" class="sr-pick" form="batchForm" name="ids[]" value="<?php echo $rid; ?>" data-employee="<?php echo (int)$r['employee_id']; ?>" data-amt="<?php echo e($r['amount']); ?>"<?php echo $ck['ok'] ? '' : ' disabled'; ?> aria-label="选择 <?php echo e($r['name']); ?>"><?php endif; ?></span>
        <div class="sr-who"><b><?php echo e($r['name']); ?></b><small><?php echo e($r['department']); ?> · #<?php echo $rid; ?></small></div>
        <div class="sr-month"><b><?php echo e($r['payroll_month']); ?></b><small><?php echo (int)$r['order_count']; ?> 项</small></div>
        <div class="sr-amt">¥<?php echo number_format((float)$r['amount'], 2); ?></div>
        <div class="sr-state">
          <?php if ($r['status'] === 'pending'): ?>
            <?php if ($ck && $ck['ok']): ?><span class="sr-chip is-ok"><i class="fas fa-check-circle"></i> 无异议</span><?php elseif ($ck): ?><span class="sr-chip is-warn" title="<?php echo e(implode('；', $ck['issues'])); ?>"><i class="fas fa-exclamation-triangle"></i> 有异议 <?php echo count($ck['issues']); ?></span><?php endif; ?>
            <?php if ($ck && $ck['ok'] && !$ck['has_card']): ?><span class="sr-chip is-bad" title="导出的打款表里会标出，请先在首页报酬表里补充"><i class="fas fa-id-card"></i> 缺卡号</span><?php endif; ?>
          <?php else: ?><span class="sr-chip is-<?php echo $r['status'] === 'settled' ? 'ok' : 'mute'; ?>"><?php echo e($label); ?></span><?php endif; ?>
        </div>
        <div class="sr-card"><?php if ($card !== ''): ?><code><?php echo e(trim(chunk_split($card, 4, ' '))); ?></code><button type="button" class="sr-copy" data-copy="<?php echo e($r['name']."\t".$card."\t".number_format((float)$r['amount'],2,'.','')); ?>" title="复制 姓名 / 卡号 / 金额"><i class="far fa-copy"></i></button><?php else: ?><span class="sr-nocard">缺银行卡号</span><?php endif; ?></div>
        <div class="sr-time"><?php echo e(substr((string)$r['created_at'], 5, 11)); ?><small><?php echo $r['settled_at'] ? e(substr((string)$r['settled_at'], 5, 11)) . ' 结算' : '申请'; ?></small></div>
        <div class="sr-ops">
          <?php if ($r['status'] === 'pending'): ?><button type="submit" form="rf<?php echo $rid; ?>" name="do" value="settle" class="sr-btn is-go is-sm"<?php echo $ck && !$ck['ok'] ? ' disabled title="有异议，处理后才能结算"' : ''; ?> onclick="return confirm('确认已向 <?php echo e($r['name']); ?> 打款 ¥<?php echo e(number_format((float)$r['amount'],2)); ?>？确认后将标注已结算，不能撤销。');">已结算</button><?php endif; ?>
          <button type="button" class="sr-toggle" aria-expanded="false" aria-controls="srx<?php echo $rid; ?>" title="展开明细与处理"><i class="fas fa-chevron-down"></i></button>
        </div>
      </div>
      <div class="sr-x" id="srx<?php echo $rid; ?>" hidden>
        <?php if ($ck && !$ck['ok']): ?><div class="sr-hold"><i class="fas fa-exclamation-triangle"></i><div><b>有异议，暂不可导出：</b><ul><?php foreach ($ck['issues'] as $is): ?><li><?php echo e($is); ?></li><?php endforeach; ?></ul></div></div><?php endif; ?>
        <?php if ($r['status'] === 'rejected'): ?><div class="sr-note">退回原因：<?php echo e($r['note']); ?></div>
        <?php else: ?><div class="sr-detail" data-detail-url="?detail_id=<?php echo $rid; ?>"><span class="sr-muted">展开时加载结算明细…</span></div><?php endif; ?>
        <?php if ($r['settled_at']): ?><div class="sr-note">结算人：<?php echo e($r['handled_by']); ?></div><?php endif; ?>
        <?php if ($r['status'] === 'pending'): ?>
        <form method="post" class="sr-act" id="rf<?php echo $rid; ?>"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="id" value="<?php echo $rid; ?>">
          <input name="note" maxlength="200" placeholder="备注（退回时必填原因）" aria-label="备注">
          <?php if ($ck && $ck['sync_needed'] && $ck['sync_amount']>0): ?><input type="hidden" name="expected_amount" value="<?php echo e(number_format($ck['sync_amount'],2,'.','')); ?>"><button name="do" value="sync_payroll" class="sr-btn" onclick="return confirm('将本月待处理申请合并为扣减后的实结算 ¥<?php echo e(number_format($ck['sync_amount'],2,'.','')); ?>（已扣除已打款）？金额变化时需重新核对，不会确认打款。');"><i class="fas fa-sync"></i> 同步为 ¥<?php echo number_format($ck['sync_amount'],2); ?></button><?php endif; ?>
          <?php if ($ck && !$ck['ok']): ?><button name="do" value="mark_green" class="sr-btn" onclick="return confirm('确认手动复核标绿通过此人？标绿后将允许导出与打款。');"><i class="fas fa-check-circle"></i> 复核标绿</button><?php endif; ?>
          <button name="do" value="reject" class="sr-btn is-danger"><i class="fas fa-undo"></i> 退回</button>
        </form>
        <?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php if ($pageCount>1): ?><nav class="sr-pager" aria-label="结算申请分页"><span>第 <?php echo $page; ?> / <?php echo $pageCount; ?> 页 · 每页 75 条</span><span><?php if ($page>1): ?><a href="<?php echo e($pageUrl(['page'=>$page-1])); ?>">‹ 上一页</a><?php endif; ?><?php if ($page<$pageCount): ?><a href="<?php echo e($pageUrl(['page'=>$page+1])); ?>">下一页 ›</a><?php endif; ?></span></nav><?php endif; ?>
</div>
<script>
(function () {
  var loadDetail = function (box) {
    if (!box || box.dataset.loaded || box.dataset.loading) return;
    box.dataset.loading = '1'; box.innerHTML = '<span class="sr-muted">明细加载中…</span>';
    fetch(box.dataset.detailUrl, { credentials: 'same-origin' }).then(function (r) {
      if (!r.ok || r.redirected) throw new Error('加载失败，请刷新页面后重试'); return r.text();
    }).then(function (html) { box.innerHTML = html; box.dataset.loaded = '1'; }).catch(function (e) {
      box.textContent = e.message + '，收起后重新展开可重试。';
    }).finally(function () { delete box.dataset.loading; });
  };
  document.querySelectorAll('.sr-toggle').forEach(function (b) {
    var panel = document.getElementById(b.getAttribute('aria-controls')), row = b.closest('.sr-row');
    var flip = function () { var open = panel.hidden; panel.hidden = !open; b.setAttribute('aria-expanded', open ? 'true' : 'false'); row.classList.toggle('is-open', open); if (open) loadDetail(panel.querySelector('.sr-detail')); };
    b.addEventListener('click', flip);
    row.querySelector('.sr-main').addEventListener('click', function (e) { if (e.target.closest('button,a,input,code,label,summary')) return; flip(); });
  });
  document.querySelectorAll('.sr-copy').forEach(function (button) {
    button.addEventListener('click', function () {
      var done = function () { var h = button.innerHTML; button.innerHTML = '<i class="fas fa-check"></i> 已复制'; setTimeout(function () { button.innerHTML = h; }, 1200); };
      if (!navigator.clipboard) { window.prompt('请复制收款信息',button.dataset.copy); return; }
      navigator.clipboard.writeText(button.dataset.copy).then(done).catch(function () { window.prompt('请复制收款信息',button.dataset.copy); });
    });
  });
  document.querySelectorAll('form[method="post"]').forEach(function (form) { form.addEventListener('submit',function (event) {
    var button=event.submitter || document.activeElement;
    if (!button || button.value==='export') return;
    var action=document.createElement('input'); action.type='hidden'; action.name='do'; action.value=button.value; form.appendChild(action);
    setTimeout(function () { document.querySelectorAll('.sr button').forEach(function (b) { b.disabled=true; }); button.textContent='正在处理…'; }, 0);
  }); });
  var picks = [].slice.call(document.querySelectorAll('.sr-pick')), all = document.getElementById('srAll'); if (!all) return;
  var yuan = function (v) { return '¥' + v.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  function sum() {
    var on = picks.filter(function (b) { return b.checked; }), t = on.reduce(function (s, b) { return s + parseFloat(b.getAttribute('data-amt')); }, 0);
    document.getElementById('srCnt').textContent = on.length; document.getElementById('srAmt').textContent = yuan(t);
    document.getElementById('srExport').disabled = document.getElementById('srSettle').disabled = !on.length;
    picks.forEach(function (b) { b.closest('.sr-row').classList.toggle('is-picked', b.checked); });
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
