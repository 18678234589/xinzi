<?php if ($actor['role'] === 'finance' && $canEdit): ?><form method="post" class="form-inline justify-content-end mb-4"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token
    ()); ?>"><input type="hidden" name="action" value="approve_order"><label class="mr-2" for="payrollMonth">项目分成归属月份</label><input id="payrollMonth" type="month" name="payroll_month" class="form-control mr-2" value="<?php
    echo date('Y-m'); ?>" required><button class="btn btn-primary" onclick="return confirm('确认收入、成本、参与人和归属月份均已核对？审核后本订单将锁定编辑。')">审核并生成项目分成</button></form><?php
    endif; ?>
<?php include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/footer.php'; ?>
