<?php
require_once __DIR__ . '/../includes/ProjectRefundImport.php';
$actor = ps_require_finance(); $page_title = '退款回收站'; $error = ''; $success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    try {
        if (($_POST['action'] ?? '') !== 'restore') throw new RuntimeException('操作无效');
        $result = prt_change((int)($_POST['refund_id'] ?? 0), true, '', $actor);
        prt_after_change($result); $success = '已恢复。未入账退款重新等待系统匹配或财务核对；没有重复扣减。';
    } catch (Throwable $e) { $error = $e instanceof PDOException ? '恢复未完成，未改动金额，请稍后重试' : $e->getMessage(); }
}
$ready = prt_storage_available(); $rows = []; $total = 0; $page = max(1, (int)($_GET['page'] ?? 1));
if ($ready) {
    $total = (int)db()->query('SELECT COUNT(*) FROM project_refund_import_rows WHERE deleted_at IS NOT NULL')->fetchColumn();
    $page = min($page, max(1, (int)ceil($total / 30)));
    $rows = db()->query('SELECT r.*,o.project_type FROM project_refund_import_rows r LEFT JOIN project_orders o ON o.id=r.order_id WHERE r.deleted_at IS NOT NULL ORDER BY r.deleted_at DESC,r.id DESC LIMIT 30 OFFSET ' . (($page - 1) * 30))->fetchAll();
}
include __DIR__ . '/../includes/header.php';
?>
<div class="project-intake-page">
<div class="project-hero mb-3"><div><div class="project-eyebrow">财务专属 · 可恢复留档</div><h2><i class="fas fa-trash-restore mr-2" aria-hidden="true"></i>退款回收站</h2><p>误登记的退款先放在这里，原始表格、金额与操作记录都保留。回收站里的记录不会自动匹配或扣减；恢复后回到原核对流程。</p></div><div class="project-hero-actions"><a class="btn btn-light" href="<?php echo BASE_URL; ?>/project/refunds.php">返回退款与返现</a></div></div>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?php echo e($error); ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success" role="status"><?php echo e($success); ?></div><?php endif; ?>
<?php if (!$ready): ?><div class="alert alert-warning">回收站尚未安装，请管理员完成迁移；现有退款不受影响。</div><?php endif; ?>
<div class="card project-form-card"><div class="card-header d-flex justify-content-between align-items-center"><strong>已移入 <?php echo $total; ?> 笔</strong><small class="text-muted">仅财务可恢复 · 不提供永久删除</small></div>
<div class="table-responsive"><table class="table project-preview-table mb-0"><thead><tr><th>原单 / 业务</th><th>退款日期 / 金额</th><th>渠道与流水</th><th>移入时间 / 原因</th><th>操作</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr>
<td><strong><?php echo e($r['order_no'] ?: '未匹配订单'); ?></strong><div class="small text-muted"><?php echo e($r['project_type'] ?: '待确认业务'); ?></div><?php if (!empty($r['import_file_id'])): ?><a class="small" href="<?php echo BASE_URL; ?>/project/files.php?view=<?php echo (int)$r['import_file_id']; ?>" target="_blank" rel="noopener">查看原始表格</a><?php endif; ?></td>
<td><?php echo e($r['refund_date']); ?><div><strong>¥<?php echo number_format((float)$r['amount'], 2); ?></strong></div></td>
<td><?php echo e($r['payment_method']); ?><div class="small text-muted" style="overflow-wrap:anywhere"><?php echo e($r['payment_reference'] ?: '无退款流水号'); ?></div></td>
<td><?php echo e($r['deleted_at']); ?><div class="small text-muted"><?php echo e($r['deleted_note']); ?></div></td>
<td><form method="post" onsubmit="return confirm('恢复这笔退款？将恢复原核对流程，不会重复登记或直接发放报酬。')"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="restore"><input type="hidden" name="refund_id" value="<?php echo (int)$r['id']; ?>"><button class="btn btn-sm btn-success" type="submit"><i class="fas fa-undo mr-1" aria-hidden="true"></i>恢复退款</button></form></td>
</tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="5" class="text-center text-muted py-5">回收站还是空的。误登记退款可在“退款与返现”中移入这里。</td></tr><?php endif; ?>
</tbody></table></div>
<?php if ($total > 30): ?><div class="card-footer d-flex justify-content-between align-items-center"><?php if ($page > 1): ?><a class="btn btn-sm btn-light" href="?page=<?php echo $page - 1; ?>">上一页</a><?php else: ?><span></span><?php endif; ?><span class="small text-muted">第 <?php echo $page; ?> / <?php echo (int)ceil($total / 30); ?> 页</span><?php if ($page * 30 < $total): ?><a class="btn btn-sm btn-light" href="?page=<?php echo $page + 1; ?>">下一页</a><?php else: ?><span></span><?php endif; ?></div><?php endif; ?>
</div></div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
