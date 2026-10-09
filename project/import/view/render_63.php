<td><?php if (empty($row['order_date'])): ?><small class="text-danger">缺订单日期：请在上方“需要处理的订单”里补填</small><?php else: ?><?php echo e($row['order_date']); ?><?php endif; ?><br><strong>¥<?php
    echo e(($row['contract_amount'] ?? '') === '' ? '待补' : $row['contract_amount']); ?></strong><?php if ($previewKinds && !empty($row['base_valid'])): $currentKind = ($row['order_kind'
    ] ?? '') !== '' ? $row['order_kind'] : ($row['kind_guess'] ?? ''); ?><br><select class="form-control form-control-sm mt-1 js-kind-choice<?php echo ($row['order_kind'] ?? '') ===
    '' ? ' is-invalid' : ''; ?>" name="kind_choice[<?php echo (int)$row['line']; ?>]" aria-label="订单类型"><option value="">选择订单类型</option><?php foreach ($previewKinds
    as $k): ?><option value="<?php echo e($k); ?>" <?php echo $currentKind === $k ? 'selected' : ''; ?>><?php echo e($k); ?></option><?php endforeach; ?></select><?php if (($row['order_kind'
    ] ?? '') === '' && !empty($row['kind_guess'])): ?><small class="text-muted"><?php echo !empty($row['kind_from_ai']) ? 'AI 建议' : '按描述猜测'; ?>，请确认</small><?php
    endif; ?><?php elseif (($row['order_kind'] ?? '') !== ''): ?><br><small class="text-muted"><?php echo e($row['order_kind']); ?></small><?php endif; ?></td>
