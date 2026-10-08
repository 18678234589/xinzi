
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="font-weight-bold mb-0"><i class="fas fa-calculator"></i> 项目结算</h4>
</div>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle"></i> <?php echo e($success); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-circle"></i> <?php echo e($error); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>

<div class="row">
    <!-- 结算表单 -->
    <div class="col-md-4">
        <div class="card">
            <div class="card-header bg-white"><h5 class="mb-0"><i class="fas fa-sliders-h text-primary"></i> 选择结算条件</h5></div>
            <div class="card-body">
                <form method="post" id="settleForm">
                    <input type="hidden" name="action" value="preview">
                    <div class="form-group">
                        <label>选择部门</label>
                        <select name="department" id="deptSel" class="form-control" onchange="loadEmp()">
                            <option value="">-- 选择部门 --</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?php echo e($d); ?>"><?php echo e($d); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>选择合作人员 <span class="required">*</span></label>
                        <input type="text" id="empSearch" class="form-control" list="empList" placeholder="输入姓名搜索选择…" autocomplete="off" onchange="syncEmpId()" required>
                        <datalist id="empList">
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo e($emp['name']); ?>（<?php echo e($emp['department'] ?? ''); ?>）" data-id="<?php echo $emp['id']; ?>"><?php echo e($emp
    ['name']); ?>（<?php echo e($emp['department'] ?? ''); ?>）</option>
                            <?php endforeach; ?>
                        </datalist>
                        <input type="hidden" name="employee_id" id="empId">
                    </div>
                    <div class="form-group">
                        <label>选择月份 <span class="required">*</span></label>
                        <input type="month" name="month" class="form-control" value="<?php echo e($preview['month'] ?? date('Y-m', strtotime('-1 month'))); ?>" required>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-hand-holding-usd text-warning"></i> 自定义额外金额</label>
                        <div id="extraItems">
                            <!-- 动态行由 JS 填充 -->
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-warning mt-1" onclick="addExtraRow(0, '')">
                            <i class="fas fa-plus"></i> 添加一项
                        </button>
                        <small class="text-muted d-block mt-1">用于无法通过订单计算的金额，正数加、负数减，结算时累加到应结算金额</small>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-award text-success"></i> 全勤项目奖励额</label>
                        <div class="input-group">
                            <div class="input-group-prepend"><span class="input-group-text">¥</span></div>
                            <input type="number" name="full_attendance_bonus" class="form-control" step="0.01" value="<?php echo e($_POST['full_attendance_bonus'] ?? ($preview['full_attendance_bonus'
    ] ?? '200')); ?>" placeholder="满勤项目奖励额，默认200">
                        </div>
                        <small class="text-muted">自动抓取考勤：请假≥4h扣一半，≥8h全扣；无考勤记录不发</small>
                    </div>
                    <div class="form-group">
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" name="deduct_insurance" value="1" class="custom-control-input" id="deductIns" checked>
                            <label class="custom-control-label" for="deductIns">
                                <i class="fas fa-shield-alt text-info"></i> 扣除保险
                                <span class="text-muted">¥<?php echo money($insuranceAmount); ?></span>
                            </label>
                        </div>
                        <small class="text-muted">默认勾选扣除，不扣保险的合作人员请手动取消；金额请在<a href="<?php echo BASE_URL; ?>/insurance/index.php">保险管理</a>中设置</small>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-money-bill-wave text-primary"></i> 固定服务费</label>
                        <div class="input-group">
                            <div class="input-group-prepend"><span class="input-group-text">¥</span></div>
                            <input type="text" class="form-control" value="<?php echo e($preview['base_info']['original'] ?? ($emp['base_salary'] ?? '')); ?>" readonly>
                        </div>
                        <small class="text-muted">自动抓取合作人员固定服务费（自定义固定服务费、阶梯固定服务费或客服绩效固定服务费），按出勤天数折算：固定服务费/30×实际出勤天数</small>
                    </div>
                    <button type="submit" class="btn btn-info btn-block"><i class="fas fa-eye"></i> 预览计算</button>
                </form>
            </div>
        </div>
    </div>

    <!-- 结算预览 -->
    <div class="col-md-8">
        <div class="card">
            <div class="card-header bg-white"><h5 class="mb-0"><i class="fas fa-receipt text-success"></i> 项目结算单</h5></div>
            <div class="card-body">
