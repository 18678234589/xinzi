                <?php if ($pending): foreach ($pending as $p): ?>
                    <tr>
                        <td><?php echo e($p['wangwang']); ?></td>
                        <td><?php echo e($p['name']); ?></td>
                        <td><?php echo (float)$p['net_sales'] ? number_format((float)$p['net_sales'], 2) : '0.00'; ?></td>
                        <td>
                            <?php if ((float)$p['inquiry_conv'] > 0): ?>
                                <?php $derivP = cs_perf_conv_derivation((float)$p['inquiry_conv'], (int)($p['order_count'] ?? 0), (int)$p['incoming_count']); ?>
                                <?php echo rtrim(rtrim(number_format((float)$p['inquiry_conv'], 2), '0'), '.') . '%'; ?>
                                <?php if ($derivP): ?><span class="text-muted" style="font-size:11px" title="<?php echo e($derivP); ?>">(下单<?php echo (int)$p['order_count']; ?>÷进线<?php echo (int)$p['incoming_count']; ?>)</span><?php endif; ?>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                        <td><?php echo (int)($p['order_count'] ?? 0); ?></td>
                        <td><?php echo (float)$p['wangwang_reply'] ? rtrim(rtrim(number_format((float)$p['wangwang_reply'], 2), '0'), '.') . '%' : '-'; ?></td>
                        <td><?php echo (int)$p['incoming_count']; ?></td>
                        <td><?php echo (float)$p['total_reply_seconds']; ?></td>
                        <td><small class="text-muted"><?php echo e($p['source_file']); ?></small></td>
                        <td>
                            <form method="post" class="form-inline">
                                <input type="hidden" name="action" value="assign">
                                <input type="hidden" name="pending_id" value="<?php echo (int)$p['id']; ?>">
                                <select name="employee_id" class="form-control form-control-sm mr-1" style="width:130px">
                                    <option value="">选择合作人员</option>
                                    <?php foreach ($employees as $emp): ?>
                                        <option value="<?php echo (int)$emp['id']; ?>"><?php echo e($emp['name']); ?>(<?php echo e($emp['wangwang'] ?? '无'); ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn btn-sm btn-primary"><i class="fas fa-check"></i> 归属</button>
                            </form>
                        </td>
                        <td>
                            <form method="post" onsubmit="return confirm('确定删除该待匹配记录？')">
                                <input type="hidden" name="action" value="delete_pending">
                                <input type="hidden" name="pending_id" value="<?php echo (int)$p['id']; ?>">
                                <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i> 删除</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="10" class="text-center text-muted py-3">本月没有待匹配数据</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/footer.php'; ?>
