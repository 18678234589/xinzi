<?php
/**
 * 上传后待补全弹窗：本人上传的表格里缺订单号 / 日期的真实订单，任意页面自动弹出，直接在弹窗里补填提交导入。
 * 由 header.php 引入；需要 $project_staff 与会话中的 project_user_id。表未迁移时不显示。
 */
if (empty($project_staff) || empty($_SESSION['project_user_id']) || !function_exists('ps_csrf_token')) return;
try {
    $followupQuery = db()->prepare("SELECT f.*,i.original_name FROM project_import_followups f JOIN project_import_files i ON i.id=f.file_id WHERE f.user_id=? AND f.status='open' ORDER BY f.id LIMIT 5");
    $followupQuery->execute([(int)$_SESSION['project_user_id']]);
    $openFollowups = $followupQuery->fetchAll();
} catch (PDOException $e) {
    return;
}
if (!$openFollowups) return;
$followupTotal = 0;
foreach ($openFollowups as &$openFollowup) { $openFollowup['rows'] = json_decode($openFollowup['rows_json'], true) ?: []; $followupTotal += count($openFollowup['rows']); }
unset($openFollowup);
$forceFollowup = isset($_GET['followup']);
$followupKey = 'import-followup-' . implode('-', array_map(function ($f) { return $f['id'] . '.' . strtotime($f['updated_at']); }, $openFollowups));
?>
<div class="alert alert-warning mb-3 d-flex flex-wrap align-items-center justify-content-between" style="gap:8px"><span><i class="fas fa-pen mr-1"></i>你上传的表格还有 <strong><?php echo $followupTotal; ?></strong> 行缺订单号或日期，没有入账。</span><button type="button" class="btn btn-sm btn-warning" data-toggle="modal" data-target="#importFollowupModal">现在补全</button></div>
<div class="modal fade" id="importFollowupModal" tabindex="-1" role="dialog" aria-labelledby="importFollowupTitle" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-scrollable" role="document"><div class="modal-content">
<div class="modal-header"><h5 class="modal-title" id="importFollowupTitle"><i class="fas fa-pen text-warning mr-1"></i>还有 <?php echo $followupTotal; ?> 行订单缺订单号或日期</h5><button type="button" class="close" data-dismiss="modal" aria-label="关闭"><span aria-hidden="true">&times;</span></button></div>
<div class="modal-body">
<p class="mb-2">表格里其余正确的订单<strong>已经导入</strong>。下面这些行有金额、是真实订单，但缺订单号或日期，暂时没有入账。<strong>直接在这里补上，点“提交并导入”就完成了</strong>，不用改原表、不用重新上传。</p>
<div class="small text-muted mb-3">
  <div><i class="fab fa-weixin text-success mr-1"></i>微信收款没有店铺订单号的，填<strong>微信交易单号</strong>：微信 → 我 → 服务 → 钱包 → 账单 → 点开这笔收款 → “交易单号”（长按复制）。</div>
  <div><i class="fas fa-store mr-1"></i>店铺订单：填淘宝 / 店铺后台的订单编号，如 <code>3316440471002001958</code>。</div>
  <div><i class="fas fa-ban mr-1"></i>不是订单的行（如测试、重复、备注）勾选“不是订单”，以后不再提醒。</div>
</div>
<?php foreach ($openFollowups as $followupItem): ?>
<form method="post" action="<?php echo BASE_URL; ?>/project/import.php?business=<?php echo rawurlencode($followupItem['business_name']); ?>&scope=<?php echo e($followupItem['scope']); ?>" class="mb-3">
<input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="followup"><input type="hidden" name="followup_id" value="<?php echo (int)$followupItem['id']; ?>"><input type="hidden" name="business" value="<?php echo e($followupItem['business_name']); ?>"><input type="hidden" name="scope" value="<?php echo e($followupItem['scope']); ?>">
<div class="d-flex flex-wrap justify-content-between align-items-center mb-1" style="gap:6px"><strong><i class="fas fa-file-excel text-success mr-1"></i><?php echo e($followupItem['original_name']); ?> <small class="text-muted">· <?php echo e($followupItem['business_name']); ?> · <?php echo count($followupItem['rows']); ?> 行</small></strong><a class="small" href="<?php echo BASE_URL; ?>/project/files.php?view=<?php echo (int)$followupItem['file_id']; ?>" target="_blank" rel="noopener">查看原始表格</a></div>
<div class="table-responsive"><table class="table table-sm table-bordered mb-2" style="font-size:.9rem"><thead class="thead-light"><tr><th style="width:70px">行</th><th>表格里写的</th><th style="min-width:240px">订单号（二选一）</th><th style="width:160px">日期</th><th style="width:80px">不是订单</th></tr></thead><tbody>
<?php foreach ($followupItem['rows'] as $followupRow): $followupLine = (int)$followupRow['line']; ?><tr>
<td><?php echo $followupRow['sheet'] !== '' && count(array_unique(array_column($followupItem['rows'], 'sheet'))) > 1 ? '<small class="text-muted d-block">' . e($followupRow['sheet']) . '</small>' : ''; ?>第 <?php echo $followupLine % 10000; ?> 行</td>
<td><small><?php echo e(implode(' · ', array_filter([$followupRow['order_date'] ?: '', $followupRow['nickname'] ?? '', ($followupRow['amount'] ?? '') !== '' ? '¥' . $followupRow['amount'] : '', $followupRow['text'] ?? '']))); ?></small><?php if (!$followupRow['need_order'] && !$followupRow['need_date'] && $followupRow['error'] !== ''): ?><div class="small text-danger"><?php echo e($followupRow['error']); ?></div><?php endif; ?></td>
<td><?php if ($followupRow['need_order']): ?><input class="form-control form-control-sm mb-1" name="fix_order_no[<?php echo $followupLine; ?>]" maxlength="100" placeholder="店铺订单号"><input class="form-control form-control-sm" name="fix_payment_reference[<?php echo $followupLine; ?>]" maxlength="200" placeholder="或 微信交易单号"><?php else: ?><span class="text-muted small"><?php echo e($followupRow['order_no']); ?></span><?php endif; ?></td>
<td><?php if ($followupRow['need_date']): ?><input type="date" class="form-control form-control-sm" name="fix_date[<?php echo $followupLine; ?>]" value="<?php echo e($followupRow['suggested_date'] ?? ''); ?>"><?php if (!empty($followupRow['suggested_date'])): ?><small class="text-muted">已按上一行预填，请核对</small><?php endif; ?><?php else: ?><span class="text-muted small"><?php echo e($followupRow['order_date']); ?></span><?php endif; ?></td>
<td class="text-center"><input type="checkbox" name="ignore[<?php echo $followupLine; ?>]" value="1" aria-label="第<?php echo $followupLine % 10000; ?>行不是订单"></td>
</tr><?php endforeach; ?>
</tbody></table></div>
<div class="text-right"><button class="btn btn-success"><i class="fas fa-check mr-1"></i>提交并导入</button></div>
</form>
<?php endforeach; ?>
</div>
<div class="modal-footer"><small class="text-muted mr-auto">只填有的就行，没填的行会继续留在这里。</small><button type="button" class="btn btn-outline-secondary" data-dismiss="modal" id="importFollowupLater">稍后处理</button></div>
</div></div></div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (!window.jQuery || !jQuery.fn.modal) return;
  var key = <?php echo json_encode($followupKey); ?>, force = <?php echo $forceFollowup ? 'true' : 'false'; ?>, dismissed = false;
  try { dismissed = sessionStorage.getItem(key) === '1'; } catch (e) {}
  if (force || !dismissed) jQuery('#importFollowupModal').modal('show');
  jQuery('#importFollowupModal').on('hidden.bs.modal', function () { try { sessionStorage.setItem(key, '1'); } catch (e) {} });
});
</script>
