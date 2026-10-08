
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="font-weight-bold mb-0"><i class="fas fa-comments-dollar"></i> 客服绩效</h4>
    <div>
        <a href="month.php?year=<?php echo $year; ?>&month=<?php echo $month; ?>" class="btn btn-outline-primary btn-sm">
            <i class="fas fa-edit"></i> 本月的补录/编辑
        </a>
        <a href="schemes.php" class="btn btn-outline-primary btn-sm">
            <i class="fas fa-tasks"></i> 绩效方案/算法
        </a>
        <a href="#perfUpload" class="btn btn-primary btn-sm">
            <i class="fas fa-cloud-upload-alt"></i> 上传绩效表
        </a>
    </div>
</div>

<?php if ($upMsg): ?>
    <div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle"></i> <?php echo e($upMsg); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>
<?php if ($upErr): ?>
    <div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-circle"></i> <?php echo e($upErr); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>

<!-- 月份筛选 -->
<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="form-inline">
            <div class="form-group mr-2">
                <label class="mr-1">月份:</label>
                <select name="year" class="form-control form-control-sm" style="width:90px">
                    <?php for ($y = date('Y'); $y >= date('Y') - 2; $y--): ?>
                        <option value="<?php echo $y; ?>" <?php echo $year === $y ? 'selected' : ''; ?>><?php echo $y; ?>年</option>
                    <?php endfor; ?>
                </select>
                <select name="month" class="form-control form-control-sm ml-1" style="width:90px">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php echo $month === $m ? 'selected' : ''; ?>><?php echo $m; ?>月</option>
                    <?php endfor; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-sm btn-info"><i class="fas fa-search"></i> 查看</button>
        </form>
    </div>
</div>

<!-- 上传绩效表（官网导出） -->
<div class="card border-primary mb-3" id="perfUpload">
    <div class="card-header bg-primary text-white"><i class="fas fa-cloud-upload-alt"></i> 上传绩效表（归入 <?php echo $upYD; ?>年<?php echo $upMD; ?>月）</div>
    <div class="card-body">
        <form method="post" enctype="multipart/form-data" class="mb-0">
            <input type="hidden" name="action" value="import">
            <div class="form-row align-items-end mb-2">
                <div class="col-auto">
                    <label class="small text-muted">归入月份（文件里没有日期时使用，默认上个月）</label>
                    <div class="form-inline">
                        <select name="upload_year" class="form-control form-control-sm mr-1" style="width:92px">
                            <?php for ($y = date('Y'); $y >= date('Y') - 2; $y--): ?>
                                <option value="<?php echo $y; ?>" <?php echo $upYD === $y ? 'selected' : ''; ?>><?php echo $y; ?>年</option>
                            <?php endfor; ?>
                        </select>
                        <select name="upload_month" class="form-control form-control-sm ml-1" style="width:86px">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo $upMD === $m ? 'selected' : ''; ?>><?php echo $m; ?>月</option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                <div class="col-auto">
                    <label class="small text-muted">店铺名称（同一合作人员允许多店；<strong>设计客服</strong>请按店铺分别上传两店并填写区分，用于「两店绩效平均」）</label>
                    <input type="text" name="store" class="form-control form-control-sm" placeholder="如：美呀美旗舰店" style="width:180px">
                </div>
            </div>
            <div class="form-row align-items-end">
                <div class="col">
                    <label class="small text-muted">选择千牛/百牛官网导出的客服绩效表（XLSX / CSV / TXT，UTF-8 或 GBK）</label>
                    <input type="file" name="file" accept=".xlsx,.csv,.txt" class="form-control" required>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-upload"></i> 上传并自动匹配</button>
                </div>
            </div>
            <small class="text-muted d-block mt-2">
                <i class="fas fa-info-circle"></i> 系统自动识别列名：客服/旺旺、净销售额、询单最终下单转化率、旺旺回复率、平均响应时长（响应时长支持 HH:MM:SS / X分X秒 / 秒）；
                <strong>转化率：</strong>上传表没有转化率列也没关系，识别到「下单人数 + 询单人数」时会自动按 <strong>转化率 = 下单人数 ÷ 询单人数</strong> 计算，并在页面显示计算过程（下单X ÷ 询单Y = Z%）；
                文件里没有日期时归入上方所选月份（默认上个月）；导入后按「旺旺账号 → 姓名」自动匹配名单内合作人员，未匹配的进入下方【待匹配清单】。
                <strong>设计客服：</strong>两店数据请分别上传（店铺名不同即可），系统会各自算出达成率并取平均值，再按部门内前三名定固定服务费 850/800/750 元。
            </small>
        </form>
    </div>
</div>

<!-- 绩效参与名单（按部门自动） -->
<div class="card mb-3">
    <div class="card-header"><i class="fas fa-user-cog"></i> 绩效参与名单（部门配置了基数+方案 → 自动参与；<strong>设计客服</strong>恒参与并按排名定固定服务费）</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0">
                <thead class="thead-light">
                    <tr><th>姓名</th><th>部门</th><th>绩效基数+方案</th><th style="width:120px">操作</th></tr>
                </thead>
                <tbody>
                <?php foreach ($participants as $emp): ?>
                    <?php $rc = $deptCfgMap[(string)$emp['department']] ?? null; ?>
                    <?php $isRankDept = ((string)$emp['department'] === CS_PERF_RANK_DEPT); ?>
                    <tr>
                        <td><?php echo e($emp['name']); ?></td>
                        <td><span class="badge badge-info"><?php echo e($emp['department']); ?></span></td>
                        <td>
                            <?php if ($isRankDept): ?>
                                <span class="badge badge-success"><i class="fas fa-trophy"></i> 按排名定固定服务费（两店绩效平均，前三名 850/800/750）</span>
                            <?php elseif ($rc): ?>
                                <strong><?php echo number_format((float)$rc['base'], 2); ?></strong>
                                <small class="text-muted">(<?php echo e($rc['scheme_name'] ?: '默认方案'); ?>)</small>
                            <?php else: ?>
                                <span class="text-danger small"><i class="fas fa-exclamation-circle"></i> 部门未配置，去<a href="schemes.php">设置</a></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="post" onsubmit="return confirm('排除后该合作人员将不再计入客服绩效，确定？')">
                                <input type="hidden" name="action" value="member_exclude">
                                <input type="hidden" name="employee_id" value="<?php echo (int)$emp['id']; ?>">
                                <button class="btn btn-sm btn-outline-danger"><i class="fas fa-times"></i> 排除</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$participants): ?>
                    <tr><td colspan="4" class="text-center text-muted py-3">暂无参与合作人员。普通部门请先在「<a href="schemes.php">绩效方案/算法</a>」页配置<strong>绩效基数</strong>与<strong>方案</strong>后自动参与；<strong>设计客服</strong>无需配置即按排名参与。</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($excluded): ?>
        <h6 class="mt-3 mb-2"><i class="fas fa-ban text-danger"></i> 已排除合作人员（不再计入）</h6>
        <table class="table table-sm table-bordered mb-0" style="max-width:560px">
            <tbody>
            <?php foreach ($excluded as $emp): ?>
                <tr>
                    <td><?php echo e($emp['name']); ?></td>
                    <td><span class="badge badge-secondary"><?php echo e($emp['department']); ?></span></td>
                    <td>
                        <form method="post" class="mb-0">
                            <input type="hidden" name="action" value="member_include">
                            <input type="hidden" name="employee_id" value="<?php echo (int)$emp['id']; ?>">
                            <button class="btn btn-sm btn-outline-success"><i class="fas fa-undo"></i> 恢复</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> 合作人员按所在部门自动参与绩效：普通部门为「基数×综合达成率」，<strong>设计客服</strong>为「两店绩效平均 → 部门内前三名定固定服务费 850/800/750」；被排除者不会出现在总览、也不计入项目报酬。导入的绩效表按「旺旺账号 → 姓名」自动匹配到对应合作人员名下。</small>
    </div>
</div>

<!-- 合作人员绩效概览 -->
<div class="card mb-3">
    <div class="card-header"><i class="fas fa-users"></i> 客服绩效总览（<?php echo $year; ?>年<?php echo $month; ?>月）</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-bordered mb-0">
                <thead class="thead-light">
                    <tr>
                        <th>合作人员</th><th>旺旺账号</th><th>部门</th>
                        <th>净销售额(元)</th><th>询单转化率(%)</th><th>旺旺回复率(%)</th><th>平均响应(秒)</th>
                        <th>绩效金额</th>
                        <th>来源</th><th>操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($rows): foreach ($rows as $r): ?>
                    <?php
                    $emp  = $r['emp'];
                    $perf = $r['perf'];
                    $netSales   = $perf ? (float)$perf['net_sales'] : 0.0;
                    $inquiryConv = $perf ? (float)$perf['inquiry_conv'] : 0.0;
                    $wangReply  = $perf ? (float)$perf['wangwang_reply'] : 0.0;
                    $avgResponse = $perf ? (float)$perf['reply_speed'] : 0.0;
                    $calc = $r['calc'];
                    $calcRank = (($emp['department'] ?? '') === CS_PERF_RANK_DEPT && isset($calc['rank']) && $calc['rank'] !== null) ? (int)$calc['rank'] : null;
                    ?>
                    <tr>
                        <td><?php echo e($emp['name']); ?></td>
                        <td><?php echo e($emp['wangwang'] ?? ''); ?></td>
                        <td><span class="badge badge-info"><?php echo e($emp['department']); ?></span></td>
                        <td><?php echo $netSales > 0 ? number_format($netSales, 2) : '<span class="text-muted">-</span>'; ?></td>
                        <td>
                            <?php if ($inquiryConv > 0): ?>
                                <?php
                                $orderCount = (int)($perf['order_count'] ?? 0);
                                $incTmp     = (int)($perf['incoming_count'] ?? 0);
                                $deriv = cs_perf_conv_derivation($inquiryConv, $orderCount, $incTmp);
                                ?>
                                <span title="<?php echo $deriv ? e($deriv) : '询单转化率'; ?>"><?php echo rtrim(rtrim(number_format($inquiryConv, 2, '.', ''), '0'), '.') . '%'
    ; ?></span>
                                <?php if ($deriv): ?><span class="text-muted" style="font-size:11px">(下单<?php echo $orderCount; ?>÷询单<?php echo $incTmp; ?>)</span><?php endif
    ; ?>
                            <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                        </td>
                        <td><?php echo $wangReply > 0 ? $wangReply . '%' : '<span class="text-muted">-</span>'; ?></td>
                        <td><?php echo $avgResponse > 0 ? $avgResponse : '<span class="text-muted">-</span>'; ?></td>
                        <td>
                            <?php if ((float)$calc['amount'] > 0): ?>
                                <a href="#" class="text-decoration-none" data-toggle="tooltip" title="<?php echo e($calc['formula']); ?>" onclick="return false;"><strong><?php echo
    number_format((float)$calc['amount'], 2); ?></strong> <i class="fas fa-info-circle text-muted"></i></a>
                                <?php if ($calcRank !== null): ?><span class="badge badge-warning ml-1">第<?php echo $calcRank; ?>名</span><?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted" title="<?php echo e($calc['formula']); ?>">0<?php echo $calcRank === null ? '' : '（第' . $calcRank . '名）'; ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $perf ? e($perf['source_file'] ?: '已录入') : '<span class="text-muted">无数据</span>'; ?></td>
                        <td>
                            <a href="month.php?employee_id=<?php echo (int)$emp['id']; ?>&year=<?php echo $year; ?>&month=<?php echo $month; ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit"></i> 编辑</a>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="10" class="text-center text-muted py-4">暂无合作人员</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($pending): ?>
<!-- 待匹配清单 -->
<div class="card mb-3">
    <div class="card-header bg-warning text-dark"><i class="fas fa-exclamation-triangle"></i> 待匹配清单（<?php echo $year; ?>年<?php echo $month; ?>月，共 <?php echo count
    ($pending); ?> 条）</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-bordered mb-0">
                <thead class="thead-light">
                    <tr><th>旺旺账号</th><th>姓名</th><th>进线</th><th>回复总秒</th><th>来源</th><th>操作</th></tr>
                </thead>
                <tbody>
                <?php foreach ($pending as $p): ?>
                    <tr>
                        <td><?php echo e($p['wangwang']); ?></td>
                        <td><?php echo e($p['name']); ?></td>
                        <td><?php echo (int)$p['incoming_count']; ?></td>
                        <td><?php echo (float)$p['total_reply_seconds']; ?></td>
                        <td><small class="text-muted"><?php echo e($p['source_file']); ?></small></td>
                        <td><a href="month.php?year=<?php echo $year; ?>&month=<?php echo $month; ?>" class="btn btn-sm btn-outline-warning"><i class="fas fa-user-cog"></i> 补录归属</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($logs): ?>
<!-- 同步日志：已上传的绩效表（可查看/删除） -->
<div class="card">
    <div class="card-header"><i class="fas fa-history"></i> 已上传的绩效表（最近导入记录，共 <?php echo count($logFiles); ?> 个文件）</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="thead-light">
                    <tr><th>时间</th><th>来源文件</th><th>匹配</th><th>未匹配</th><th>错误</th><th style="width:150px">操作</th></tr>
                </thead>
                <tbody>
                <?php foreach ($logFiles as $k => $lf): ?>
                    <?php $l = $lf['latest']; $fname = preg_replace('/^admin:/', '', (string)$k); ?>
                    <tr>
                        <td><?php echo e($l['created_at']); ?></td>
                        <td>
                            <?php echo e($fname !== '' ? $fname : $k); ?>
                            <?php if ($lf['count'] > 1): ?>
                                <span class="badge badge-warning" title="同一文件被上传了 <?php echo (int)$lf['count']; ?> 次">上传了 <?php echo (int)$lf['count']; ?> 次</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo (int)$l['matched']; ?></td>
                        <td><?php echo (int)$l['pending']; ?></td>
                        <td><?php echo (int)$l['errors']; ?></td>
                        <td class="text-nowrap">
                            <button type="button" class="btn btn-sm btn-outline-info view-upload-btn"
                                    data-source="<?php echo e($l['source_file']); ?>" data-name="<?php echo e($fname !== '' ? $fname : $k); ?>">
                                <i class="fas fa-eye"></i> 查看
                            </button>
                            <form method="post" class="d-inline" onsubmit="return confirm('确定删除该次上传的所有数据？\n将移除本次导入的匹配数据与待匹配记录，合作人员该月绩效将变为无数据。');">
                                <input type="hidden" name="action" value="upload_delete">
                                <input type="hidden" name="source_file" value="<?php echo e($l['source_file']); ?>">
                                <button class="btn btn-sm btn-outline-danger" title="删除"><i class="fas fa-trash-alt"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <small class="text-muted d-block p-2"><i class="fas fa-info-circle"></i> 「查看」展示该次上传实际导入的数据（已匹配 + 未匹配 + 错误明细）；「删除」会连同该文件导入的匹配/待匹配数据一并移除。</small>
    </div>
</div>
<?php endif; ?>

<!-- 上传明细模态框 -->
<div class="modal fade" id="uploadViewModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-table"></i> 上传明细 <small id="uvSource" class="text-muted"></small></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
            </div>
            <div class="modal-body" id="uvBody"></div>
        </div>
    </div>
</div>

<?php include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/footer.php'; ?>

<script><?php /* split: assets/js/performance_index_1.js */ include (dirname((dirname(__DIR__, 1)), 1)) . '/../assets/js/performance_index_1.js'; ?></script>

