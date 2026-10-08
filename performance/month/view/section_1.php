
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="font-weight-bold mb-0"><i class="fas fa-edit"></i> 客服绩效 - 编辑/补录（<?php echo $year; ?>年<?php echo $month; ?>月）</h4>
    <a href="index.php?year=<?php echo $year; ?>&month=<?php echo $month; ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left"></i> 返回总览</a>
</div>

<?php if ($msg): ?>
    <div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle"></i> <?php echo e($msg); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>
<?php if ($err): ?>
    <div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-circle"></i> <?php echo e($err); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>

<!-- 月份/合作人员切换 -->
<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="form-inline">
            <select name="year" class="form-control form-control-sm mr-1">
                <?php for ($y = date('Y'); $y >= date('Y') - 2; $y--): ?>
                    <option value="<?php echo $y; ?>" <?php echo $year === $y ? 'selected' : ''; ?>><?php echo $y; ?>年</option>
                <?php endfor; ?>
            </select>
            <select name="month" class="form-control form-control-sm mr-2">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?php echo $m; ?>" <?php echo $month === $m ? 'selected' : ''; ?>><?php echo $m; ?>月</option>
                <?php endfor; ?>
            </select>
            <select name="employee_id" class="form-control form-control-sm mr-2">
                <option value="0">全部合作人员</option>
                <?php foreach ($employees as $emp): ?>
                    <option value="<?php echo (int)$emp['id']; ?>" <?php echo $filterEmp === (int)$emp['id'] ? 'selected' : ''; ?>><?php echo e($emp['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm btn-info"><i class="fas fa-search"></i> 切换</button>
        </form>
    </div>
</div>

<!-- 合作人员绩效逐人编辑 -->
<div class="card mb-3">
    <div class="card-header"><i class="fas fa-users"></i> 逐人编辑（净销售额/询单转化率/旺旺回复率 = 采集上报；平均回复秒 = 采集上报；成交数留空则按订单自动统计；<strong>填了「下单人数」时转化率自动 = 下单 ÷ 询单并显示计算过程</strong>）</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered mb-0 align-middle">
                <thead class="thead-light">
                    <tr>
                        <th>合作人员</th><th>旺旺账号</th><th>净销售额(元)</th><th>询单转化率(%)</th><th>下单人数</th><th>旺旺回复率(%)</th><th>进线人数</th><th>平均回复(秒)</th>
                        <th>成交数(留空自动)</th><th>备注</th><th style="width:100px">操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($rows):
                    foreach ($rows as $r):
                        $emp = $r['emp'];
                        $perf = $r['perf'];
                        $dealDisplay = $perf && $perf['deal_count'] !== null ? (int)$perf['deal_count'] : '';
                        ?>
                        <tr>
                            <form method="post">
                                <input type="hidden" name="action" value="save">
                                <input type="hidden" name="employee_id" value="<?php echo (int)$emp['id']; ?>">
                                <input type="hidden" name="year" value="<?php echo $year; ?>">
                                <input type="hidden" name="month" value="<?php echo $month; ?>">
                                <td><?php echo e($emp['name']); ?><br><small class="text-muted"><?php echo e($emp['department']); ?></small></td>
                                <td><?php echo e($emp['wangwang'] ?? ''); ?></td>
                                <td><input type="number" name="net_sales" min="0" step="any" class="form-control form-control-sm" style="width:110px" value="<?php echo $perf ? (float)
    $perf['net_sales'] : 0; ?>"></td>
                                <td><input type="number" name="inquiry_conv" min="0" step="any" class="form-control form-control-sm" style="width:100px" value="<?php echo $perf ? (float)
    $perf['inquiry_conv'] : 0; ?>"></td>
                                <td>
                                    <input type="number" name="order_count" min="0" class="form-control form-control-sm" style="width:80px" value="<?php echo $perf ? (int)($perf['order_count'
    ] ?? 0) : 0; ?>">
                                    <div style="font-size:11px" class="text-muted">转化率=下单÷进线自动算</div>
                                </td>
                                <td><input type="number" name="wangwang_reply" min="0" step="any" class="form-control form-control-sm" style="width:100px" value="<?php echo $perf ?
    (float)$perf['wangwang_reply'] : 0; ?>"></td>
                                <td><input type="number" name="incoming_count" min="0" class="form-control form-control-sm" style="width:100px" value="<?php echo $perf ? (int)$perf
    ['incoming_count'] : 0; ?>"></td>
                                <td><input type="number" name="reply_speed" min="0" step="any" class="form-control form-control-sm" style="width:100px" value="<?php echo $perf ? (float)
    $perf['reply_speed'] : 0; ?>"></td>
                                <td>
                                    <input type="number" name="deal_count" min="0" class="form-control form-control-sm" style="width:120px" placeholder="自动:<?php echo $r['liveDeal'
    ]; ?>" value="<?php echo e($dealDisplay); ?>">
                                    <small class="text-muted">订单自动统计 <?php echo $r['liveDeal']; ?> 个</small>
                                </td>
                                <td><input type="text" name="remark" class="form-control form-control-sm" value="<?php echo e($perf['remark'] ?? ''); ?>"></td>
                                <td><button class="btn btn-sm btn-primary"><i class="fas fa-save"></i> 保存</button></td>
                            </form>
                        </tr>
                    <?php endforeach;
                else: ?>
                    <tr><td colspan="10" class="text-center text-muted py-4">暂无合作人员</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 绩效金额算法过程 -->
<div class="card mb-3">
    <div class="card-header py-2"><i class="fas fa-calculator"></i> <strong>绩效金额算法过程</strong>
        <small class="text-muted ml-2">与「客服绩效总览」「项目结算」共用同一计算函数（cs_perf_calc），金额绝对一致；点「详细过程」查看每一指标如何命中档位、加权、排名</small>
    </div>
