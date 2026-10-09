
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
    <div>
        <a href="<?php echo BASE_URL; ?>/attendance/year.php?year=<?php echo $year; ?>" class="btn btn-outline-secondary btn-sm mr-2">
            <i class="fas fa-arrow-left"></i> 返回<?php echo $year; ?>年
        </a>
        <h4 class="font-weight-bold mb-0 d-inline-block">
            <i class="fas fa-calendar-day text-primary"></i> <?php echo $year; ?>年<?php echo $month; ?>月考勤
        </h4>
        <span class="badge badge-info ml-2">已录 <?php echo $total; ?> 人</span>
        <span class="badge badge-success ml-1">满勤 <?php echo $fullCount; ?> 人</span>
        <?php if ($totalAbsent > 0): ?><span class="badge badge-warning ml-1">请假 <?php echo number_format($totalAbsent, 2); ?>h</span><?php endif; ?>
        <?php if ($pendingCount > 0): ?><span class="badge badge-secondary ml-1" title="上传考勤时这些合作人员尚未添加，已暂存；添加合作人员后自动补录">待匹配 <?php
    echo $pendingCount; ?> 人</span><?php endif; ?>
    </div>
</div>

<?php if ($pendingCount > 0): ?>
<div class="alert alert-info py-2">
    <a class="d-flex justify-content-between align-items-center text-decoration-none text-info" data-toggle="collapse" href="#pendingCollapse" role="button" aria-expanded="false" aria-controls="pendingCollapse">
        <span><i class="fas fa-info-circle"></i> <strong><?php echo $pendingCount; ?> 人</strong>的考勤已暂存（上传时合作人员尚未添加）。在<a href="<?php echo
    BASE_URL; ?>/employees/index.php" onclick="event.stopPropagation();">合作人员管理</a>中添加对应姓名的合作人员后，考勤会自动补录。</span>
        <i class="fas fa-chevron-down ml-2"></i>
    </a>
    <div class="collapse" id="pendingCollapse">
        <table class="table table-sm table-bordered mt-2 mb-0" style="max-width:600px">
            <thead><tr><th>姓名</th><th>应出勤(h)</th><th>请假(h)</th><th>备注</th></tr></thead>
            <tbody>
            <?php foreach ($pendingRows as $p): ?>
                <tr><td><?php echo e($p['employee_name']); ?></td><td><?php echo e($p['work_hours']); ?></td><td><?php echo e($p['absent_hours']); ?></td><td><?php echo e($p['remark'
    ]); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle"></i> <?php echo e($success); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-circle"></i> <?php echo e($error); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>

<div class="row">
    <!-- 左侧：上传 + 手动添加 -->
    <div class="col-md-5">
        <!-- 上传考勤表 -->
        <div class="card mb-3">
            <div class="card-header bg-white"><h5 class="mb-0"><i class="fas fa-file-excel text-success"></i> 批量上传考勤表</h5></div>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data" id="uploadForm">
                    <input type="hidden" name="action" value="upload">
                    <input type="hidden" name="year" value="<?php echo $year; ?>">
                    <input type="hidden" name="month" value="<?php echo $month; ?>">
                    <div class="form-group">
                        <label>归属月份</label>
                        <input type="text" class="form-control" value="<?php echo $year; ?>年<?php echo $month; ?>月" disabled>
                    </div>
                    <div class="form-group">
                        <label>选择文件 <span class="required">*</span></label>
                        <div class="upload-area" id="uploadArea" style="border:2px dashed #ced4da;border-radius:6px;padding:20px;text-align:center;cursor:pointer;">
                            <i class="fas fa-cloud-upload-alt fa-3x text-muted mb-2"></i>
                            <p class="mb-1" id="uploadTip">点击或拖拽文件到此处</p>
                            <p class="text-muted small mb-0">支持 .xlsx / .csv</p>
                            <input type="file" name="excel_file" id="excelFile" class="d-none" accept=".xlsx,.csv">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>或粘贴表格数据</label>
                        <textarea name="csv_data" id="csvData" class="form-control" rows="3" placeholder="姓名,满勤天数,实际出勤天数,备注&#10;张三,22,22,&#10;李四,22,20,请假2天"></textarea>
                        <small class="text-muted">用逗号分隔，第一行为表头</small>
                    </div>
                    <button type="submit" class="btn btn-success btn-block"><i class="fas fa-upload"></i> 开始上传</button>
                </form>
                <hr>
                <div class="text-muted small">
                    <b><i class="fas fa-info-circle text-info"></i> 文件格式要求：</b><br>
                    表头需包含：<code>姓名/合作人员</code> + <code>满勤天数</code> + <code>实际出勤天数</code>，可选 <code>备注</code><br>
                    系统按"满勤天数 × 8小时"计算应出勤，按"(满勤天数 - 实际出勤天数) × 8小时"计算请假<br>
                    延时服务写法：<code>26+2</code>（出勤 26 天 + 延时服务 2 天，按 1 倍）；节假日当天延时服务在加号后用括号写日期，按 1.5 倍，如 <code>26+1(10.1)</code>、<code>26+3(5.1,5.2)</code>（其中 5.1 是节假日按 1.5 倍，5.2 按 1 倍），也可写节日名 <code>26+1(国庆)</code>。<br>
                    1.5 倍节假日只有：元旦、除夕、春节初一 / 初二、清明、5.1、端午、中秋、10.1；超时补贴 = 固定服务费 ÷ 30 × 天数 × 倍率，在规则中心“超时补贴”规则里计算。延时服务不计入出勤天数，也不冲抵请假<br>
                    也兼容旧格式：<code>应出勤(小时)</code> / <code>请假(小时)</code><br>
                    合作人员姓名必须与系统合作人员名一致，否则该行跳过
                </div>
            </div>
        </div>

        <!-- 手动添加单条 -->
        <div class="card">
            <div class="card-header bg-white"><h5 class="mb-0"><i class="fas fa-hand-pointer text-primary"></i> 手动添加单条</h5></div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="manual_add">
                    <input type="hidden" name="year" value="<?php echo $year; ?>">
                    <input type="hidden" name="month" value="<?php echo $month; ?>">
                    <div class="form-group">
                        <label>合作人员 <span class="required">*</span></label>
                        <select name="employee_id" class="form-control" required>
                            <option value="">-- 选择合作人员 --</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo $emp['id']; ?>"><?php echo e($emp['name']); ?>（<?php echo e($emp['department']); ?>）</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>满勤天数</label>
                            <input type="number" name="full_days" id="fullDays" class="form-control" step="0.5" min="0" value="22">
                            <small class="text-muted">应出勤 = 满勤天数 × 8小时</small>
                        </div>
                        <div class="form-group col-md-6">
                            <label>实际出勤天数</label>
                            <input type="number" name="actual_days" id="actualDays" class="form-control" step="0.5" min="0" value="22">
                            <small class="text-muted">请假 = (满勤 - 实际出勤) × 8小时</small>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>延时服务天数（1 倍）</label>
                            <input type="number" name="overtime_days" class="form-control" step="0.5" min="0" value="0">
                        </div>
                        <div class="form-group col-md-6">
                            <label>节假日延时服务天数（1.5 倍）</label>
                            <input type="number" name="holiday_overtime_days" class="form-control" step="0.5" min="0" value="0">
                            <small class="text-muted">仅限元旦、除夕、春节初一 / 初二、清明、5.1、端午、中秋、10.1 当天</small>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>备注</label>
                        <input type="text" name="remark" class="form-control" placeholder="如：病假">
                    </div>
                    <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-plus"></i> 添加</button>
                </form>
            </div>
        </div>
    </div>

    <!-- 右侧：已上传考勤记录 -->
    <div class="col-md-7">
        <div class="card">
            <div class="card-header bg-white">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-list text-info"></i> 考勤记录</h5>
                    <?php if ($total > 0): ?>
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="batchDelete()">
                        <i class="fas fa-trash-alt"></i> 批量删除
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if (empty($records)): ?>
                    <div class="text-center text-muted py-5">
                        <i class="fas fa-inbox fa-2x mb-2 d-block"></i>暂无考勤数据，请上传或手动添加
                    </div>
                <?php else: ?>
                <form method="post" id="delForm">
                    <input type="hidden" name="action" value="batch_delete">
                    <input type="hidden" name="year" value="<?php echo $year; ?>">
                    <input type="hidden" name="month" value="<?php echo $month; ?>">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th style="width:36px"><input type="checkbox" id="chkAll" onclick="document.querySelectorAll('.row-chk').forEach(c=>c.checked=this.checked)"></th>
                                    <th>合作人员</th>
                                    <th>部门</th>
                                    <th class="text-right">满勤天数</th>
                                    <th class="text-right">实际出勤</th>
                                    <th class="text-right">请假(h)</th>
                                    <th class="text-right">延时服务(天)</th>
                                    <th>备注</th>
                                    <th style="width:70px">状态</th>
                                    <th style="width:60px">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($records as $r):
                                $isFull = (float)$r['absent_hours'] == 0;
                                $fullDays  = (float)$r['work_hours'] / 8;
                                $actDays   = ((float)$r['work_hours'] - (float)$r['absent_hours']) / 8;
                            ?>
                                <tr>
                                    <td><input type="checkbox" name="ids[]" value="<?php echo $r['id']; ?>" class="row-chk"></td>
                                    <td><strong><?php echo e($r['name']); ?></strong></td>
                                    <td><span class="badge badge-info"><?php echo e($r['department']); ?></span></td>
                                    <td class="text-right"><?php echo number_format($fullDays, 2); ?>天</td>
                                    <td class="text-right <?php echo $isFull ? 'text-success' : ''; ?>"><?php echo number_format($actDays, 2); ?>天</td>
                                    <td class="text-right <?php echo $isFull ? '' : 'text-warning font-weight-bold'; ?>"><?php echo number_format($r['absent_hours'], 2); ?>h</td>
                                    <td class="text-right"><?php
                                        $otN = (float)($r['overtime_days'] ?? 0); $otH = (float)($r['holiday_overtime_days'] ?? 0);
                                        if ($otN <= 0 && $otH <= 0) echo '<span class="text-muted">--</span>';
                                        else {
                                            if ($otH > 0) echo '<span class="badge badge-danger" title="节假日延时服务，按 1.5 倍">节假日 ' . rtrim(rtrim(number_format($otH, 2), '0'), '.') . '</span> ';
                                            if ($otN > 0) echo '<span class="badge badge-info" title="按 1 倍">' . rtrim(rtrim(number_format($otN, 2), '0'), '.') . '</span>';
                                        } ?></td>
                                    <td><small class="text-muted"><?php echo e($r['remark']); ?></small></td>
                                    <td>
                                        <?php if ($isFull): ?>
                                            <span class="badge badge-success">满勤</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning">请假</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-link p-0 text-danger" onclick="delOne(<?php echo $r['id']; ?>)" title="删除">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script><?php /* split: attendance/month/view/js_1.php */ include (dirname((dirname(__DIR__, 1)), 1)) . '/month/view/js_1.php'; ?></script>

<?php include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/footer.php'; ?>
