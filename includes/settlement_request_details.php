<div class="table-responsive"><table class="table table-sm mb-2"><thead class="thead-light"><tr><th>订单号</th><th>业务</th><th>日期</th><th class="text-right">结算金额</th></tr></thead><tbody>
<?php foreach ($detailRows as $o): ?><tr><td>
<?php if (!empty($o['is_monthly']) || (int)$o['order_id'] >= 900000000): ?><span class="badge badge-info mr-1">月度</span><?php echo e($o['order_no']); ?>
<?php else: ?><a target="_blank" rel="noopener" href="<?php echo BASE_URL; ?>/project/index.php?order_id=<?php echo (int)$o['order_id']; ?>"><?php echo e($o['order_no']); ?></a><?php endif; ?>
</td><td><?php echo e($o['project_type']); ?></td><td><?php echo e($o['order_date']); ?></td><td class="text-right">¥<?php echo number_format((float)$o['amount'],2); ?></td></tr><?php endforeach; ?>
</tbody></table></div>
