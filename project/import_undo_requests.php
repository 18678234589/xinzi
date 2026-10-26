<?php
require_once __DIR__ . '/../includes/ProjectImportUndoRequests.php';
// 撤销上传：合作人员传错表、订单已被系统自动核对时，在“撤销上传”窗口提交申请，财务在这里一键同意 / 不同意。
// 同意后系统立即撤销这张表格新建的订单（进回收站，30 天内可还原）；另有“原件已被删除、订单还在库里”的上传，财务也可以在这里直接撤销。
$actor = ps_require_actor();
if (($actor['role'] ?? '') !== 'finance') { http_response_code(403); exit('仅管理员或财务可以处理撤销上传申请'); }
piu_ensure();
$status = (string)($_GET['status'] ?? 'pending'); if (!in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) $status = 'pending';
$rows = piu_list($status);
$orphans = piu_deleted_uploads();
$label = ['pending' => '待同意', 'approved' => '已同意并撤销', 'rejected' => '未同意'];
$page_title = '撤销上传';
include __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/correction_tabs.php';
$cssFile = dirname(__DIR__) . '/assets/css/rule_algo.css';
?>
<link rel="stylesheet" href="<?php echo e(BASE_URL . '/assets/css/rule_algo.css?v=' . (is_file($cssFile) ? filemtime($cssFile) : 1)); ?>">
<style>
.iu-orders{border:1px solid #e3e8e4;border-radius:12px;overflow:hidden;margin:.6rem 0}
.iu-row{display:flex;justify-content:space-between;gap:12px;padding:.5rem .9rem;border-bottom:1px solid #f0f2f0;font-size:.9rem}
.iu-row:last-child{border-bottom:0}.iu-row.keep{background:#fffaf0}.iu-why{color:#9a5b00;font-size:.8rem;text-align:right}.iu-ok{color:#1f6a52;font-size:.8rem}
.iu-tip{font-size:.85rem;color:#5f6b64;line-height:1.6;margin:.4rem 0}
</style>
<div class="ra-page">
  <?php pc_tabs('undo'); ?>
  <div class="ra-page-head"><div><h2>撤销上传</h2><p>合作人员上传错表后，订单如果已被系统自动核对 / 自动登记收款（他们自己撤不了），会在这里提交撤销申请。同意后立即撤销这张表格新建的订单，订单进回收站（30 天内可还原），并给申请人发站内信。有人工登记的收退款、已申请结算、所在月份已核算 / 锁定的订单不会被撤。</p></div>
    <nav class="ra-tabs" aria-label="状态"><?php foreach (['pending' => '待同意', 'approved' => '已同意', 'rejected' => '未同意', 'all' => '全部'] as $k => $t): ?><a class="ra-tab<?php echo $status === $k ? ' is-on' : ''; ?>" href="?status=<?php echo e($k); ?>"><?php echo e($t); ?></a><?php endforeach; ?></nav></div>

  <?php if (!$rows): ?><div class="ra-empty"><i class="far fa-check-circle"></i><h3>没有<?php echo $status === 'pending' ? '待同意的' : ''; ?>撤销申请</h3><p>合作人员在“我上传的表格”里点“撤销上传 → 申请财务同意撤销”后会出现在这里。</p></div><?php endif; ?>

  <?php foreach ($rows as $r): ?>
  <article class="ra-req" data-id="<?php echo (int)$r['id']; ?>">
    <header><div><span class="ra-status s-<?php echo e($r['status'] === 'approved' ? 'resolved' : $r['status']); ?>"><?php echo e($label[$r['status']]); ?></span> <strong><?php echo e($r['applicant_name']); ?></strong> 申请撤销 · <?php echo e($r['business_name']); ?> · <?php echo e($r['file_name']); ?> <span class="text-muted small">（<?php echo e(substr((string)$r['created_at'], 0, 16)); ?>）</span></div></header>
    <?php if ($r['reason'] !== ''): ?><div class="ra-detail"><h4>申请理由</h4><p><?php echo nl2br(e($r['reason'])); ?></p></div><?php endif; ?>
    <?php if ($r['status'] === 'pending'):
        $plan = null; $planError = '';
        try { $plan = pu_plan((int)$r['file_id'], $actor); } catch (Throwable $e) { $planError = $e->getMessage(); }
    ?>
    <?php if ($plan): ?>
    <div class="iu-orders"><?php foreach ($plan['orders'] as $o): $will = $o['removable'] || $o['approvable']; ?>
      <div class="iu-row <?php echo $will ? '' : 'keep'; ?>"><div><b><?php echo e($o['order_no']); ?></b> <span class="text-muted"><?php echo e($o['customer_name']); ?> · ¥<?php echo e(number_format((float)$o['contract_amount'], 2)); ?></span></div>
        <div class="<?php echo $will ? 'iu-ok' : 'iu-why'; ?>"><?php echo $o['removable'] ? '将撤销' : ($o['approvable'] ? '将撤销（系统已自动核对：' . e(implode('、', $o['reasons'])) . '）' : '保留：' . e(implode('、', $o['hard']))); ?></div></div>
      <?php endforeach; ?></div>
    <div class="iu-tip">同意后：将撤销 <strong><?php echo (int)$plan['removable'] + (int)$plan['approvable']; ?></strong> 单<?php echo $plan['kept'] - $plan['approvable'] > 0 ? '，保留 ' . ((int)$plan['kept'] - (int)$plan['approvable']) . ' 单（上面标了原因）' : ''; ?>；订单、系统自动登记的收款和自动算出的分成快照一起进回收站，原始表格也一并删除。</div>
    <?php else: ?><div class="ra-detail"><p class="text-danger"><?php echo e($planError ?: '无法读取这张表格的订单'); ?></p></div><?php endif; ?>
    <footer><textarea class="js-note" rows="2" placeholder="处理说明（不同意时必填）"></textarea>
      <div class="ra-actions"><button type="button" class="ra-btn js-decide" data-d="approved"<?php echo $plan && ($plan['removable'] + $plan['approvable']) ? '' : ' disabled'; ?>><i class="fas fa-check"></i> 同意并撤销</button><button type="button" class="ra-btn ghost js-decide" data-d="rejected">不同意</button><span class="ra-msg js-msg" role="status"></span></div></footer>
    <?php else: $res = json_decode((string)$r['result_json'], true) ?: []; ?>
    <footer class="done"><span>处理人：<?php echo e($r['handler_name']); ?> · <?php echo e(substr((string)$r['handled_at'], 0, 16)); ?><?php echo $r['status'] === 'approved' ? ' · 已撤销 ' . (int)($res['removed'] ?? 0) . ' 单' . (!empty($res['kept']) ? '，保留 ' . (int)$res['kept'] . ' 单' : '') : ''; ?></span><?php if ($r['handle_note']): ?><p><?php echo nl2br(e($r['handle_note'])); ?></p><?php endif; ?></footer>
    <?php endif; ?>
  </article>
  <?php endforeach; ?>

  <?php if ($orphans): ?>
  <div class="ra-page-head mt-4"><div><h2>原件已删除、订单还在库里的上传</h2><p>近 3 天内有人点了“删除原件”（只删表格文件、不删订单），这些上传新建的订单还在。确认是传错的话在这里直接撤销（订单进回收站，30 天内可还原）；点“查看这些订单”会先列出每一单，确认后再撤。</p></div></div>
  <?php foreach ($orphans as $o): ?>
  <article class="ra-req js-orphan" data-file="<?php echo (int)$o['file_id']; ?>">
    <header><div><strong><?php echo e($o['uploader'] ?: '（未知上传人）'); ?></strong> · <?php echo e($o['business']); ?> · <?php echo e($o['name']); ?> <span class="text-muted small">（原件删除于 <?php echo e(substr($o['deleted_at'], 0, 16)); ?>；还有 <?php echo (int)$o['alive']; ?> 单订单在库）</span></div></header>
    <div class="js-orphan-body"></div>
    <footer><div class="ra-actions"><button type="button" class="ra-btn ghost js-orphan-plan">查看这些订单</button><button type="button" class="ra-btn js-orphan-run" style="display:none"><i class="fas fa-rotate-left"></i> 撤销这些订单</button><span class="ra-msg js-msg" role="status"></span></div></footer>
  </article>
  <?php endforeach; endif; ?>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var csrf = <?php echo json_encode(ps_csrf_token()); ?>, api = <?php echo json_encode(BASE_URL . '/project/import_undo_api.php'); ?>;
  function esc(t) { return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function call(payload) {
    payload.csrf = csrf;
    return fetch(api, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload)})
      .then(function (r) { return r.text().then(function (t) { var d; try { d = JSON.parse(t); } catch (e) { throw new Error('服务器返回了非预期内容，请刷新后重试'); } if (d.error) throw new Error(d.error); return d; }); });
  }
  document.querySelectorAll('.js-decide').forEach(function (b) { b.addEventListener('click', function () {
    var box = b.closest('.ra-req'), msg = box.querySelector('.js-msg'), note = box.querySelector('.js-note').value, d = b.getAttribute('data-d');
    if (d === 'rejected' && !note.trim()) { msg.textContent = '不同意时请写明原因'; return; }
    if (d === 'approved' && !window.confirm('确认同意并立即撤销这张表格新建的订单吗？订单会进回收站，30 天内可还原。')) return;
    box.querySelectorAll('.js-decide').forEach(function (x) { x.disabled = true; }); msg.textContent = '处理中…';
    call({action: 'handle', id: box.getAttribute('data-id'), decision: d, note: note})
      .then(function () { location.reload(); }).catch(function (e) { msg.textContent = e.message; box.querySelectorAll('.js-decide').forEach(function (x) { x.disabled = false; }); });
  }); });
  document.querySelectorAll('.js-orphan').forEach(function (box) {
    var fid = parseInt(box.getAttribute('data-file'), 10), body = box.querySelector('.js-orphan-body'), msg = box.querySelector('.js-msg'), plan = box.querySelector('.js-orphan-plan'), run = box.querySelector('.js-orphan-run');
    plan.addEventListener('click', function () {
      msg.textContent = '正在检查…';
      call({action: 'plan', file_id: fid}).then(function (d) {
        var p = d.plan, n = p.removable + p.approvable, rows = '';
        p.orders.forEach(function (o) { var will = o.removable || o.approvable; rows += '<div class="iu-row ' + (will ? '' : 'keep') + '"><div><b>' + esc(o.order_no) + '</b> <span class="text-muted">' + esc(o.customer_name) + ' · ¥' + o.contract_amount + '</span></div><div class="' + (will ? 'iu-ok' : 'iu-why') + '">' + (will ? '将撤销' + (o.approvable ? '（系统已自动核对）' : '') : '保留：' + esc((o.hard.length ? o.hard : o.reasons).join('、'))) + '</div></div>'; });
        body.innerHTML = '<div class="iu-orders">' + rows + '</div>';
        msg.textContent = n ? '' : (p.message || '这些订单都不能撤销（已有人工收退款、已申请结算或月份已核算 / 锁定）');
        if (n) { run.style.display = ''; run.textContent = '撤销这 ' + n + ' 单'; }
      }).catch(function (e) { msg.textContent = e.message; });
    });
    run.addEventListener('click', function () {
      if (!window.confirm('确认撤销这些订单吗？订单会进回收站，30 天内可还原。')) return;
      run.disabled = true; msg.textContent = '正在撤销…';
      call({action: 'run', file_id: fid, with_approved: true, delete_file: false}).then(function (d) { msg.textContent = '已撤销 ' + d.result.removed + ' 单'; setTimeout(function () { location.reload(); }, 900); })
        .catch(function (e) { msg.textContent = e.message; run.disabled = false; });
    });
  });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
