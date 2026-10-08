<?php if (!empty($isFinance) && prt_storage_available() && prt_can_trash($refundTrashRow)): ?>
<form method="post" class="d-inline-block mt-1" onsubmit="return confirm('将这笔退款移入回收站？会停止自动匹配和扣减，财务可以恢复；原始表格和操作记录会保留。')">
<input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>">
<input type="hidden" name="action" value="trash_refund">
<input type="hidden" name="refund_id" value="<?php echo (int)$refundTrashRow['id']; ?>">
<button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash-alt mr-1" aria-hidden="true"></i>移入回收站</button>
</form>
<?php endif; ?>
