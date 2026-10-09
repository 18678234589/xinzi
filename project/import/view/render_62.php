<td><small><?php echo count($previewSheets) > 1 && !empty($row['sheet']) ? '【' . e($row['sheet']) . '】' : ''; ?>第 <?php echo e(implode('、', array_map(function ($l) { return
    (int)$l % 10000; }, $row['lines'] ?? [$row['line']]))); ?> 行</small><br><?php if (empty($row['order_no'])): ?><span class="text-danger small"><?php echo !empty($row['public_transfer']) ? '对公收款：请在上方“需要处理的订单”里填对公交易号' : '缺订单号：请在上方“需要处理的订单”里补填'; ?></span>
    else: ?><strong><?php echo e($row['order_no']); ?></strong><?php if (!empty($row['payment_reference'])): ?><br><small><?php echo !empty($row['public_transfer']) ? '对公交易号' : '微信流水号'; ?>：<?php echo e($row['payment_reference']);
    ?></small><?php endif; ?><?php endif; ?><?php if (!empty($row['site_external_no'])): ?><input class="form-control form-control-sm mt-1" name="fix_site_key[<?php echo (int)$row[
    'line']; ?>]" maxlength="160" value="<?php echo e($row['site_key'] ?? ''); ?>" placeholder="网站项目标识（建议域名）" aria-label="第<?php echo (int)$row['line']; ?>行网站项目标识"><small class="text-muted">同一付款号做多个网站时，每个网站填不同标识</small><?php
    endif; ?><br><small><?php echo e($row['project_type'] ?? ''); ?></small></td>
