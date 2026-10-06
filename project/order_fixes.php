<?php
require_once __DIR__ . '/../includes/ProjectOrderFix.php';
// 订单资料更正：上传表格与原单不一致时，合作人员提交的“按表格更正原单”申请。财务 / 管理员一键确认后原单立即更新。
$actor = ps_require_actor();
if (($actor['role'] ?? '') !== 'finance') { http_response_code(403); exit('仅管理员或财务可以处理订单资料更正'); }
pof_ensure();
$status = (string)($_GET['status'] ?? 'pending'); if (!in_array($status, ['pending', 'resolved', 'rejected', 'all'], true)) $status = 'pending';
$rows = pof_requests($status);
$counts = [];
foreach (db()->query('SELECT status,COUNT(*) n FROM project_order_fix_requests GROUP BY status')->fetchAll() as $c) $counts[$c['status']] = (int)$c['n'];
$label = ['pending' => '待确认', 'resolved' => '已确认', 'rejected' => '未采纳'];
$page_title = '订单资料更正';
include __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/correction_tabs.php';
$cssFile = dirname(__DIR__) . '/assets/css/rule_algo.css';
?>
<link rel="stylesheet" href="<?php echo e(BASE_URL . '/assets/css/rule_algo.css?v=' . (is_file($cssFile) ? filemtime($cssFile) : 1)); ?>">
<div class="ra-page">
  <?php pc_tabs('fix'); ?>
  <div class="ra-page-head"><div><h2>订单资料更正</h2><p>上传表格里的售价 / 店铺 / 付款昵称与原单不一致时，合作人员认为表格是对的，会在这里提交更正。确认后原单立即按表格更新，上传人回到上传页点“重新核对”即可导入。只能更正草稿 / 待审订单。</p></div>
    <nav class="ra-tabs" aria-label="状态"><?php foreach (['pending' => '待确认', 'resolved' => '已确认', 'rejected' => '未采纳', 'all' => '全部'] as $k => $t): ?><a class="ra-tab<?php echo $status === $k ? ' is-on' : ''; ?>" href="?status=<?php echo $k; ?>"><?php echo $t; ?><?php if ($k !== 'all' && !empty($counts[$k])): ?> <b><?php echo $counts[$k]; ?></b><?php endif; ?></a><?php endforeach; ?></nav></div>
  <?php if (!$rows): ?><div class="ra-empty"><i class="far fa-check-circle"></i><h3>没有<?php echo $status === 'pending' ? '待确认的' : ''; ?>更正</h3><p>上传预览里点“提交财务确认”的更正会出现在这里。</p></div><?php endif; ?>
  <?php foreach ($rows as $r): $new = json_decode($r['changes_json'], true) ?: []; $old = json_decode($r['old_json'], true) ?: []; ?>
  <article class="ra-req" data-id="<?php echo (int)$r['id']; ?>">
    <header><div><span class="ra-status s-<?php echo e($r['status']); ?>"><?php echo e($label[$r['status']]); ?></span> <strong>订单 <?php echo e($r['order_no']); ?></strong> · <a class="ra-link" href="<?php echo BASE_URL; ?>/project/order.php?id=<?php echo (int)$r['order_id']; ?>">查看订单</a></div><span class="ra-meta"><?php echo e($r['applicant_name']); ?> · <?php echo e(substr($r['created_at'], 0, 16)); ?></span></header>
    <div class="ra-diff">
      <div><h4>原单现在是</h4><dl><?php foreach ($new as $k => $v): ?><dt><?php echo e(POF_FIELDS[$k] ?? $k); ?></dt><dd><?php echo $k === 'contract_amount' ? e(pof_money($old[$k] ?? 0)) : e(($old[$k] ?? '') !== '' ? $old[$k] : '（空）'); ?></dd><?php endforeach; ?></dl></div>
      <div><h4>申请改成（表格里的）</h4><dl><?php foreach ($new as $k => $v): ?><dt><?php echo e(POF_FIELDS[$k] ?? $k); ?></dt><dd class="ra-new"><?php echo $k === 'contract_amount' ? e(pof_money($v)) : e($v); ?></dd><?php endforeach; ?></dl></div>
    </div>
    <?php if ($r['note'] !== ''): ?><div class="ra-detail"><h4>说明</h4><p><?php echo nl2br(e($r['note'])); ?></p></div><?php endif; ?>
    <?php if ($r['status'] === 'pending'): ?>
    <footer><textarea class="js-note" rows="2" placeholder="处理说明（不采纳时必填）"></textarea>
      <div class="ra-actions"><button type="button" class="ra-btn js-decide" data-d="resolved"><i class="fas fa-check"></i> 确认并更正原单</button><button type="button" class="ra-btn ghost js-decide" data-d="rejected">不采纳</button><span class="ra-msg js-msg" role="status"></span></div></footer>
    <?php else: ?><footer class="done"><span>处理人：<?php echo e($r['handler_name']); ?> · <?php echo e(substr((string)$r['handled_at'], 0, 16)); ?></span><?php if ($r['handle_note']): ?><p><?php echo nl2br(e($r['handle_note'])); ?></p><?php endif; ?></footer><?php endif; ?>
  </article>
  <?php endforeach; ?>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var csrf = <?php echo json_encode(ps_csrf_token()); ?>;
  document.querySelectorAll('.js-decide').forEach(function (b) { b.addEventListener('click', function () {
    var box = b.closest('.ra-req'), msg = box.querySelector('.js-msg'); b.disabled = true;
    fetch(<?php echo json_encode(BASE_URL . '/project/order_fix_api.php'); ?>, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({csrf: csrf, action: 'handle', id: box.dataset.id, decision: b.dataset.d, note: box.querySelector('.js-note').value})})
      .then(function (r) { return r.text().then(function (t) { var d; try { d = JSON.parse(t); } catch (e) { throw new Error('服务器返回了非预期内容，请刷新后重试'); } if (d.error) throw new Error(d.error); return d; }); })
      .then(function () { location.reload(); }).catch(function (e) { msg.textContent = e.message; b.disabled = false; });
  }); });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
