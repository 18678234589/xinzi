<div class="card mb-3" id="home-roster">
    <div class="card-header bg-white"><h5 class="mb-0">全勤奖自动核算 · 居家办公名单</h5></div>
    <div class="card-body">
        <p>网站客服每月100元，其他部门200元；本月有申请居家办公的人员不发全勤奖。上传考勤或保存名单后，自动核算全员，无需逐人审批。请假沿用现有规则：不足4小时全额，4至不足8小时半额，8小时及以上不发。</p>
        <p><a class="btn btn-primary" href="<?php echo BASE_URL; ?>/project/payroll.php?month=<?php echo e($period); ?>">查看<?php echo e($period); ?>全员收入与全勤奖</a></p>
        <div class="row">
            <div class="col-md-7">
                <form method="post"><input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['attendance_csrf']); ?>">
                    <input type="hidden" name="action" value="home_roster">
                    <p><b>方式一：勾选本月申请居家办公的人员</b>（已保存<?php echo count($homeIds); ?>人）</p>
                    <div style="max-height:280px;overflow:auto" class="border rounded p-2">
                        <?php foreach ($employees as $person): ?>
                        <label class="d-block mb-1"><input type="checkbox" name="home_ids[]" value="<?php echo (int)$person['id']; ?>" <?php echo in_array((int)$person['id'], $homeIds, true) ? 'checked' : ''; ?>> <?php echo e($person['name'] . ' · ' . $person['department']); ?></label>
                        <?php endforeach; ?>
                    </div>
                    <button class="btn btn-primary mt-2" type="submit">保存名单并自动核算全员</button>
                </form>
            </div>
            <div class="col-md-5">
                <form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['attendance_csrf']); ?>">
                    <input type="hidden" name="action" value="home_roster">
                    <input type="hidden" name="roster_mode" value="upload">
                    <p><b>方式二：上传本月居家办公名单</b></p>
                    <p class="small text-muted">Excel（.xlsx）或CSV，第一行为“姓名”“部门”，每行一人，姓名与部门需与左侧一致。上传会替换左侧本月名单；匹配失败整份不保存。5MB以内。</p>
                    <input class="form-control-file mb-2" type="file" name="home_file" accept=".xlsx,.csv" required>
                    <button class="btn btn-primary" type="submit">上传名单并自动核算全员</button>
                </form>
                <p class="small text-muted mt-3">也可在下方上传原考勤表，增加“居家办公”列（填“是”或天数），或在备注中写“申请居家办公”。原考勤中的居家标记也会生效，取消时请同步更正原考勤。缺少有效考勤的人员显示待核对；已锁定月份须先解锁。</p>
            </div>
        </div>
    </div>
</div>
