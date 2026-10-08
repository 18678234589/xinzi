                <?php if ($preview): ?>
                <?php
                    $emp   = $preview['employee'];
                    $mods  = $preview['modules'] ?? [];
                ?>

                <table class="table table-bordered mb-2">
                    <tbody class="bg-white">
                        <tr><th colspan="4" class="py-2 bg-primary text-white"><i class="fas fa-file-invoice mr-1"></i> 基础信息</th></tr>
                        <tr><th width="20%">合作人员</th><td><?php echo e($emp['name']); ?></td><th width="20%">部门</th><td><?php echo e($emp['department']); ?></td></tr>
                        <tr><th>结算月份</th><td><?php echo e($preview['month']); ?></td><th>当月订单数</th><td><?php echo $preview['order_count']; ?> 笔</td></tr>
                        <tr><th>订单总额</th><td class="text-primary font-weight-bold" colspan="3">¥<?php echo money($preview['order_total']); ?></td></tr>
                        <?php
                        // 读取当月考勤
                        $attInfo = null;
                        $mp = explode('-', (string)$preview['month']);
                        if (count($mp) === 2) {
                            $attInfo = get_attendance((int)$emp['id'], (int)$mp[0], (int)$mp[1]);
                        }
                        $attAbsent = $attInfo ? (float)$attInfo['absent_hours'] : 0;
                        $attWork   = $attInfo ? (float)$attInfo['work_hours'] : 0;
                        $bInfo = $preview['bonus_info'] ?? null;
                        ?>
                        <tr>
                            <th>考勤（应出勤/请假）</th>
                            <td colspan="3">
                                <?php if ($attInfo): ?>
                                    <span class="text-muted">应出勤 <b><?php echo number_format($attWork, 2); ?>h</b></span>
                                    <span class="ml-3 <?php echo $attAbsent > 0 ? 'text-warning' : 'text-success'; ?>">
                                        请假 <b><?php echo number_format($attAbsent, 2); ?>h</b>
                                    </span>
                                    <?php if ($attAbsent >= 8): ?>
                                        <span class="badge badge-danger ml-2">全勤奖全部扣除</span>
                                    <?php elseif ($attAbsent >= 4): ?>
                                        <span class="badge badge-warning ml-2">全勤奖扣除一半</span>
                                    <?php elseif ($attAbsent > 0): ?>
                                        <span class="badge badge-info ml-2">全勤奖不扣</span>
                                    <?php else: ?>
                                        <span class="badge badge-success ml-2">满勤</span>
                                    <?php endif; ?>
                                    <?php if ($bInfo && $bInfo['base'] > 0): ?>
                                        <span class="ml-3 text-success">
                                            全勤奖 ¥<?php echo money($bInfo['base']); ?>
                                            <?php if ($bInfo['deduct'] > 0): ?> − 扣除 ¥<?php echo money($bInfo['deduct']); ?><?php endif; ?>
                                            = <b>净 ¥<?php echo money($bInfo['net']); ?></b>
                                        </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted"><i class="fas fa-info-circle"></i> 未录入考勤，不发全勤奖</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php $bi = $preview['base_info'] ?? null; if ($bi): ?>
                        <tr>
                            <th>固定服务费（按出勤折算）</th>
                            <td colspan="3">
                                <span class="text-muted">原固定服务费 <b>¥<?php echo money($bi['original']); ?></b></span>
                                <?php if ($bi['has_att']): ?>
                                    <span class="ml-3 text-muted">实际出勤 <b><?php echo number_format($bi['actual_days'], 2); ?>天</b></span>
                                    <span class="ml-3 text-muted">请假 <b><?php echo number_format($bi['leave_days'], 2); ?>天</b></span>
                                <?php endif; ?>
                                <span class="ml-3 text-primary font-weight-bold">折算后 ¥<?php echo money($bi['prorated']); ?></span>
                                <small class="text-muted d-block"><?php echo e($bi['status']); ?></small>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php $abnormalMods = $preview['abnormal_modules'] ?? []; if (!empty($abnormalMods)): ?>
                        <tr>
                            <th class="text-danger" width="20%"><i class="fas fa-exclamation-triangle"></i> 异常订单</th>
                            <td colspan="3">
                                <span class="text-danger font-weight-bold"><?php echo count($abnormalMods); ?> 个模块存在异常，共 <?php echo array_sum(array_column($abnormalMods, 'cnt')); ?> 笔，合计 ¥<?php echo money(array_sum(array_column($abnormalMods, 'total'))); ?>（不计入项目报酬）</span>
                                <table class="table table-sm table-bordered mb-0 mt-2" style="font-size:13px;">
                                    <thead class="thead-light"><tr><th>模块</th><th class="text-center">笔数</th><th class="text-right">金额</th><th class="text-center" style="width:80px;">操作</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($abnormalMods as $am): ?>
                                        <tr class="table-danger">
                                            <td><?php echo e($am['project'] ?: '未分类'); ?></td>
                                            <td class="text-center"><?php echo $am['cnt']; ?></td>
                                            <td class="text-right text-danger font-weight-bold">¥<?php echo money($am['total']); ?></td>
                                            <td class="text-center">
                                                <a href="<?php echo BASE_URL; ?>/orders/index.php?employee_id=<?php echo $emp['id']; ?>&project=<?php echo urlencode($am['project'] ?: '订单'); ?>&month=<?php echo urlencode($preview['month']); ?>&abnormal=1"
                                                   class="btn btn-sm btn-outline-danger py-0" title="查看异常订单明细" target="_blank">
                                                    <i class="fas fa-external-link-alt"></i> 详情
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php $unverifiedMods = $preview['unverified_modules'] ?? []; if (!empty($unverifiedMods)): ?>
                        <tr>
                            <th class="text-warning" width="20%"><i class="fas fa-question-circle"></i> 未核验订单</th>
                            <td colspan="3">
                                <span class="text-warning font-weight-bold"><?php echo count($unverifiedMods); ?> 个模块存在未核验订单，共 <?php echo array_sum(array_column($unverifiedMods, 'cnt')); ?> 笔，合计 ¥<?php echo money(array_sum(array_column($unverifiedMods, 'total'))); ?>（已计入项目报酬，待核验确认）</span>
                                <table class="table table-sm table-bordered mb-0 mt-2" style="font-size:13px;">
                                    <thead class="thead-light"><tr><th>模块</th><th class="text-center">笔数</th><th class="text-right">金额</th><th class="text-center" style="width:80px;">操作</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($unverifiedMods as $um): ?>
                                        <tr class="table-warning">
                                            <td><?php echo e($um['project'] ?: '未分类'); ?></td>
                                            <td class="text-center"><?php echo $um['cnt']; ?></td>
                                            <td class="text-right text-warning font-weight-bold">¥<?php echo money($um['total']); ?></td>
                                            <td class="text-center">
                                                <a href="<?php echo BASE_URL; ?>/orders/index.php?employee_id=<?php echo $emp['id']; ?>&project=<?php echo urlencode($um['project'] ?: '订单'); ?>&month=<?php echo urlencode($preview['month']); ?>&status=未核验"
                                                   class="btn btn-sm btn-outline-warning py-0" title="查看未核验订单明细" target="_blank">
                                                    <i class="fas fa-external-link-alt"></i> 详情
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                    
                    <!-- DEBUG 调试信息 -->
                    <?php if (isset($debug_info)): ?>
                    <tbody>
                        <tr class="bg-info text-white"><th colspan="4" class="py-1">🔍 调试信息</th></tr>
                        <tr><td colspan="4" style="font-family:monospace;font-size:12px;white-space:pre-wrap;max-height:300px;overflow-y:auto;"><?php echo htmlspecialchars($debug_info); ?></td></tr>
                    </tbody>
                    <?php endif; ?>

                    <?php if (!empty($mods)): ?>
                    <tbody>
                        <tr class="bg-light"><th colspan="4" class="py-1 text-center"><strong><i class="fas fa-layer-group mr-1"></i>项目报酬模块明细（共 <?php echo count($mods); ?> 个模块，合计 ¥<?php echo money(array_sum(array_column($mods, 'amount'))); ?>）</strong></th></tr>
                        <tr class="table-secondary"><th>#</th><th>模块名称</th><th>类型</th><th class="text-right">金额</th></tr>
                        <?php foreach ($mods as $mi => $m):
                            $cls = $m['amount'] >= 0 ? 'text-success' : 'text-danger';
                        ?>
                            <tr class="<?php echo $cls; ?>">
                                <td class="text-muted"><?php echo $mi + 1; ?></td>
                                <td><strong><?php echo e($m['name']); ?></strong>
                                    <?php if (!empty($m['formula'])): ?>
                                        <div class="text-muted" style="font-size:11px;line-height:1.2;"><?php echo e($m['formula']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge badge-<?php
                                    $typeColors = ['standard'=>'primary','tiered'=>'warning','per_order'=>'info','attendance_full'=>'success','attendance_daily'=>'teal','attendance_deduct'=>'danger','insurance'=>'dark','refund_deduction'=>'danger','extra_amount'=>'secondary'];
                                    echo $typeColors[$m['type']] ?? 'secondary';
                                ?>"><?php
                                    $typeNames = ['standard'=>'标准比例','tiered'=>'阶梯','per_order'=>'每笔奖励','attendance_full'=>'全勤奖','attendance_daily'=>'考勤日薪','attendance_deduct'=>'缺勤扣款','insurance'=>'保险','refund_deduction'=>'退款扣除','extra_amount'=>'自定义'];
                                    echo $typeNames[$m['type']] ?? $m['type'];
                                ?></span></td>
                                <td class="font-weight-bold"><?php echo $m['amount'] >= 0 ? '+' : '-'; ?>¥<?php echo money(abs($m['amount'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <?php endif; ?>

                    <tbody>
                        <?php if (!empty($preview['extra_items'])): ?>
                            <?php foreach ($preview['extra_items'] as $ei): ?>
                                <?php if (abs($ei['amount']) > 0.001): ?>
                                <tr class="table-warning">
                                    <th colspan="3" class="text-right h6 mb-0">
                                        <i class="fas fa-hand-holding-usd text-warning"></i>
                                        <?php echo e($ei['remark'] !== '' ? $ei['remark'] : '自定义额外金额'); ?>
                                    </th>
                                    <td class="h6 mb-0 <?php echo $ei['amount'] >= 0 ? 'text-success' : 'text-danger'; ?> font-weight-bold"><?php echo $ei['amount'] >= 0 ? '+' : ''; ?>¥<?php echo money(abs($ei['amount'])); ?></td>
                                </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if (count(array_filter($preview['extra_items'], fn($x) => abs($x['amount']) > 0.001)) > 1): ?>
                            <tr class="table-warning border-top">
                                <th colspan="3" class="text-right mb-0"><small class="text-muted">额外金额合计</small></th>
                                <td class="mb-0 <?php echo $preview['extra_amount'] >= 0 ? 'text-success' : 'text-danger'; ?> font-weight-bold"><?php echo $preview['extra_amount'] >= 0 ? '+' : ''; ?>¥<?php echo money(abs($preview['extra_amount'])); ?></td>
                            </tr>
                            <?php endif; ?>
                        <?php endif; ?>


                        <?php if (!empty($preview['base_info']) && abs($preview['base_info']['prorated'] - $preview['base_info']['original']) > 0.001): ?>
                        <tr class="table-light">
                            <th colspan="3" class="text-right h6 mb-0"><i class="fas fa-money-bill-wave text-primary"></i> 固定服务费折算（<?php echo e($preview['base_info']['status']); ?>）</th>
                            <td class="h6 mb-0 text-primary font-weight-bold">¥<?php echo money($preview['base_info']['prorated']); ?>
                                <small class="text-muted d-block">原 ¥<?php echo money($preview['base_info']['original']); ?></small>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr class="table-success">
                            <th colspan="3" class="text-right h5 mb-0">固定服务费 + 模块合计 <?php if (abs((float)($preview['extra_amount'] ?? 0)) > 0.001) echo '+ 自定义'; ?> <?php if (!empty($preview['bonus_info']) && $preview['bonus_info']['base'] > 0) echo '+ 全勤奖'; ?> <?php if (($preview['insurance_amount'] ?? 0) > 0) echo '− 保险'; ?> → 应结算金额</th>
                            <td class="h4 mb-0 text-success font-weight-bold">¥<?php echo money($preview['net_pay']); ?></td>
                        </tr>
                    </tbody>
                </table>

                <div class="text-muted small mb-3 mt-2 p-2 bg-light rounded border">
                    <i class="fas fa-calculator"></i>
                    <strong>计算明细：</strong>
                    <?php 
                    $baseSalaryAmount = (float)($preview['base_salary'] ?? $emp['base_salary']);
                    $detailStr = '';
                    if ($baseSalaryAmount > 0) {
                        $bi = $preview['base_info'] ?? null;
                        if ($bi && abs($bi['prorated'] - $bi['original']) > 0.001) {
                            $detailStr = '固定服务费 ¥' . money($baseSalaryAmount) . '（按出勤折算）';
                        } else {
                            $detailStr = '固定服务费 ¥' . money($baseSalaryAmount);
                        }
                    }
                    foreach ($mods as $m) {
                        if (($m['type'] ?? '') === 'base_salary') continue;
                        $op = $m['amount'] >= 0 ? ' + ' : ' − ';
                        $detailStr .= $op . money(abs($m['amount'])) . '(' . e($m['name']) . ')';
                    }
                    echo $detailStr;
                    ?>
                    = <strong>¥<?php echo money($preview['net_pay']); ?></strong>
                </div>
                <?php if ($existing): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> 该合作人员 <?php echo e($preview['month']); ?> 月项目报酬已结算过（创建于 <?php echo $existing['created_at']; ?>），再次生成将覆盖原记录。
                    </div>
                <?php endif; ?>
                <form method="post">
                    <input type="hidden" name="action" value="settle">
                    <input type="hidden" name="employee_id" value="<?php echo $emp['id']; ?>">
                    <input type="hidden" name="month" value="<?php echo e($preview['month']); ?>">
                    <?php foreach (($preview['extra_items'] ?? []) as $ei): ?>
                    <input type="hidden" name="extra_amounts[]" value="<?php echo e($ei['amount']); ?>">
                    <input type="hidden" name="extra_remarks[]" value="<?php echo e($ei['remark']); ?>">
                    <?php endforeach; ?>
                    <input type="hidden" name="full_attendance_bonus" value="<?php echo e($preview['full_attendance_bonus'] ?? 200); ?>">
                    <?php if (($preview['insurance_amount'] ?? 0) > 0): ?>
                    <input type="hidden" name="deduct_insurance" value="1">
                    <?php endif; ?>
                    <button type="submit" class="btn btn-success btn-lg btn-block"
                        onclick="return confirm('确认生成<?php echo e($emp['name']); ?> <?php echo e($preview['month']); ?>月的项目报酬记录？<?php echo $existing ? '将覆盖已有记录。' : ''; ?>')">
                        <i class="fas fa-check-double"></i> 确认生成项目报酬记录
                    </button>
                </form>
                <?php else: ?>
                    <div class="text-center text-muted py-5">
                        <i class="fas fa-calculator fa-4x mb-3"></i>
                        <p>请在左侧选择部门、合作人员和月份，点击"预览计算"查看项目报酬详情</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/footer.php'; ?>

<script><?php /* split: salaries/settle/view/js_1.php */ include (dirname((dirname(__DIR__, 1)), 1)) . '/settle/view/js_1.php'; ?></script>
