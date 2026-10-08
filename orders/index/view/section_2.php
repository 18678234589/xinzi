                        <small class="text-muted" style="font-size:.8em">共 <?php echo $total_count; ?> 条 / ¥<?php echo money($total_amount); ?></small>
                    </h5>
                    <form method="get" class="form-inline" id="filterForm">
                        <input type="hidden" name="page" value="1">
                        <?php if ($locked_employee): ?>
                            <input type="hidden" name="employee_id" value="<?php echo $locked_employee['id']; ?>">
                        <?php endif; ?>
                        <?php if ($filter_dept): ?>
                            <input type="hidden" name="department" value="<?php echo e($filter_dept); ?>">
                        <?php endif; ?>
                        <?php if ($filter_dept_orders): ?>
                            <input type="hidden" name="dept_orders" value="1">
                        <?php endif; ?>
                        <input type="month" name="month" class="form-control form-control-sm mr-1" value="<?php echo e($filter_month); ?>" onchange="this.form.submit()">
                        <?php if ($filter_month): ?>
                            <button type="submit" name="month" value="" class="btn btn-sm btn-outline-secondary mr-1" title="显示全部月份">全部</button>
                        <?php endif; ?>
                    </form>
                </div>
                
                <!-- 面包屑导航 -->
                <?php if ($filter_dept || $filter_employee || $filter_dept_orders): ?>
                <div class="mt-2">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0 bg-light" style="padding:0.5rem 1rem">
                            <li class="breadcrumb-item"><a href="?<?php echo $filter_month ? 'month='.$filter_month : ''; ?>">全部部门</a></li>
                            <?php if ($filter_dept): ?>
                                <li class="breadcrumb-item <?php echo ($filter_employee || $filter_dept_orders) ? '' : 'active'; ?>">
                                    <?php if ($filter_employee || $filter_dept_orders): ?>
                                        <a href="?department=<?php echo urlencode($filter_dept); ?><?php echo $filter_month ? '&month='.$filter_month : ''; ?>"><?php echo e($filter_dept); ?></a>
                                    <?php else: ?>
                                        <?php echo e($filter_dept); ?>
                                    <?php endif; ?>
                                </li>
                            <?php endif; ?>
                            <?php if ($filter_dept_orders): ?>
                                <li class="breadcrumb-item active"><i class="fas fa-users text-warning"></i> 部门订单</li>
                            <?php endif; ?>
                            <?php if ($filter_employee): ?>
                                <?php $emp = get_employee($filter_employee); ?>
                                <li class="breadcrumb-item active"><?php echo $emp ? e($emp['name']) : '合作人员'.$filter_employee; ?></li>
                            <?php endif; ?>
                        </ol>
                    </nav>
                </div>
                <?php endif; ?>
            </div>
            <div class="card-body p-2">

                <?php if (empty($projectGroups)): ?>
                    <div class="text-center text-muted py-5"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>暂无订单数据</div>
                <?php else: ?>

                <!-- 第一级：部门卡片（未选择部门时显示） -->
                <?php if (!$filter_dept && !$filter_employee && !$locked_employee): ?>
                    <div class="row">
                        <?php foreach ($deptGroups as $dept): ?>
                        <div class="col-md-6 mb-3">
                            <a href="?department=<?php echo urlencode($dept['dept_name']); ?><?php echo $filter_month ? '&month='.$filter_month : ''; ?>" 
                               class="text-decoration-none">
                                <div class="card border-primary" style="transition:all 0.2s;cursor:pointer" onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 4px 12px rgba(0,0,0,0.15)'" onmouseout="this.style.transform='';this.style.boxShadow=''">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h5 class="mb-1">
                                                    <i class="fas fa-building text-primary"></i> 
                                                    <?php echo e($dept['dept_name']); ?>
                                                </h5>
                                                <small class="text-muted"><?php echo $dept['cnt']; ?> 笔订单</small>
                                            </div>
                                            <div class="text-right">
                                                <div class="h4 mb-0 text-success">¥<?php echo money($dept['normal_amount']); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                
                <!-- 第二级：合作人员卡片（选择了部门但未选择合作人员且非部门订单视图时显示） -->
                <?php elseif ($filter_dept && !$filter_employee && !$locked_employee && !$filter_dept_orders): ?>
                    <div class="row">
                        <?php foreach ($empGroups as $emp):
                            $isDeptOrderCard = ((int)$emp['emp_id'] === 0);
                            $cardLink = $isDeptOrderCard
                                ? "?department=" . urlencode($filter_dept) . "&dept_orders=1" . ($filter_month ? '&month=' . $filter_month : '')
                                : "?department=" . urlencode($filter_dept) . "&employee_id=" . $emp['emp_id'] . ($filter_month ? '&month=' . $filter_month : '');
                        ?>
                        <div class="col-md-6 mb-3">
                            <a href="<?php echo $cardLink; ?>"
                               class="text-decoration-none">
                                <div class="card <?php echo $isDeptOrderCard ? 'border-warning' : 'border-info'; ?>" style="transition:all 0.2s;cursor:pointer" onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 4px 12px rgba(0,0,0,0.15)'" onmouseout="this.style.transform='';this.style.boxShadow=''">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h5 class="mb-1">
                                                    <i class="fas <?php echo $isDeptOrderCard ? 'fa-users text-warning' : 'fa-user text-info'; ?>"></i>
                                                    <?php echo e($emp['emp_name']); ?>
                                                </h5>
                                                <small class="text-muted"><?php echo $emp['cnt']; ?> 笔订单</small>
                                            </div>
                                            <div class="text-right">
                                                <div class="h4 mb-0 <?php echo $isDeptOrderCard ? 'text-warning' : 'text-success'; ?>">¥<?php echo money($emp['normal_amount']); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                
                <!-- 第三级：按年份-月份-项目分成模块三级分组（选择了合作人员/锁定合作人员/部门订单时显示） -->
                <?php elseif ($filter_employee || $locked_employee || $filter_dept_orders): ?>
                <!-- 按月份批量删除（勾选下方各月份前的复选框，可单选/多选后一次性删除） -->
                <form method="post" id="monthDeleteForm" onsubmit="return confirm('确定删除所选月份的【全部】订单？此操作不可恢复！')">
                    <input type="hidden" name="action" value="delete_months">
                    <?php if ($locked_employee): ?><input type="hidden" name="employee_id" value="<?php echo $locked_employee['id']; ?>"><?php endif; ?>
                    <?php if ($filter_employee): ?><input type="hidden" name="employee_id" value="<?php echo $filter_employee; ?>"><?php endif; ?>
                    <?php if ($filter_dept): ?><input type="hidden" name="department" value="<?php echo e($filter_dept); ?>"><?php endif; ?>
                    <?php if ($filter_dept_orders): ?><input type="hidden" name="dept_orders" value="1"><?php endif; ?>
                    <div class="d-flex align-items-center mb-2 p-2 bg-light border rounded">
                        <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash-alt"></i> 删除所选月份订单（<span id="monthSelCount">0</span>）</button>
                        <small class="text-muted ml-2">勾选下方各月份前的复选框，可单选或多选（如 6月 + 4月）后一次性删除该月全部订单</small>
                    </div>
                <?php foreach ($yearGroups as $yearData): ?>
                <!-- 年份卡片 -->
                <div class="card mb-3 border-primary">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-calendar-alt"></i> <?php echo $yearData['year']; ?>年
                            <span class="badge badge-light text-primary ml-2"><?php echo $yearData['total_cnt']; ?> 笔</span>
                            <span class="float-right">¥<?php echo money($yearData['total_amount']); ?></span>
                        </h5>
                    </div>
                    <div class="card-body p-2">
                        <?php foreach ($yearData['months'] as $monthData): ?>
                        <!-- 月份折叠卡片 -->
                        <div class="card mb-2 border-info">
                            <div class="card-header bg-info text-white py-2">
                                <h6 class="mb-0 d-flex align-items-center justify-content-between">
                                    <span>
                                        <input type="checkbox" name="months[]" value="<?php echo $monthData['month']; ?>" class="month-check mr-2 align-middle" title="勾选后可批量删除该月订单">
                                        <i class="fas fa-calendar"></i> <?php echo date('Y年m月', strtotime($monthData['month'] . '-01')); ?>
                                        <span class="badge badge-light text-info ml-2"><?php echo $monthData['total_cnt']; ?> 笔</span>
                                    </span>
                                    <span class="d-flex align-items-center">
                                        <span class="text-white mr-3">¥<?php echo money($monthData['total_amount']); ?></span>
                                        <form method="post" action="" class="d-inline" onsubmit="return confirm('确定删除 <?php echo date('Y年m月', strtotime($monthData['month'] . '-01')); ?> 的全部 <?php echo $monthData['total_cnt']; ?> 条订单？此操作不可恢复！');">
                                            <input type="hidden" name="action" value="delete_group">
                                            <input type="hidden" name="del_employee_id" value="<?php echo (int)$filter_employee; ?>">
                                            <input type="hidden" name="del_month" value="<?php echo e($monthData['month']); ?>">
                                            <input type="hidden" name="del_project" value="">
                                            <button type="submit" class="btn btn-sm btn-outline-light py-0 px-2" title="删除该月全部订单"><i class="fas fa-trash-alt"></i> 删除整月</button>
                                        </form>
                                    </span>
                                </h6>
                            </div>
                            <div class="card-body p-2">
                                <?php foreach ($monthData['projects'] as $grp): ?>
                                <?php
                                $grpName   = $grp['grp_name'];
                                $isExpand  = ($expand_project === $grpName);
                                $grpQ      = array_merge($baseQ, ['project' => $grpName, 'page' => 1]);
                                $abnQ      = array_merge($grpQ, ['abnormal' => '1']);
                                $collapseQ = $baseQ; // 点击已展开的分组则收起（不带 project 参数）
                                ?>
                                <div class="order-group mb-2">
                                    <!-- 分组标题行：点击展开/收起 -->
                                    <div class="d-flex align-items-stretch">
                                    <a href="<?php echo '?' . http_build_query($isExpand ? $collapseQ : $grpQ); ?>"
                                       class="order-group-header d-flex align-items-center justify-content-between flex-grow-1 px-3 py-2 text-decoration-none <?php echo $isExpand ? 'expanded' : ''; ?>"
                                       <?php if ($isExpand): ?>data-toggle="modal" data-target="#orderDetailModal"<?php endif; ?>>
                                        <span>
                                            <i class="fas fa-<?php echo $isExpand ? 'folder-open' : 'folder'; ?> mr-2 text-warning"></i>
                                            <strong><?php echo e($grpName); ?></strong>
                                            <span class="badge badge-secondary ml-2"><?php echo $grp['cnt']; ?> 条</span>
                                            <?php if ($grp['personal_cnt'] > 0): ?>
                                                <span class="badge badge-primary ml-1" title="个人订单"><i class="fas fa-user"></i> <?php echo $grp['personal_cnt']; ?></span>
                                            <?php endif; ?>
                                            <?php if ($grp['dept_cnt'] > 0): ?>
                                                <span class="badge badge-success ml-1" title="部门订单"><i class="fas fa-users"></i> <?php echo $grp['dept_cnt']; ?></span>
                                            <?php endif; ?>
                                            <?php if ($grp['abn_cnt'] > 0): ?>
                                                <span class="badge badge-danger ml-1 abnormal-filter-badge" title="只看异常订单" onclick="event.preventDefault();event.stopPropagation();location.href='<?php echo '?' . http_build_query($abnQ); ?>';"><i class="fas fa-exclamation-triangle"></i> <?php echo $grp['abn_cnt']; ?></span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="text-success font-weight-bold d-flex align-items-center">
                                            ¥<?php echo money($grp['normal_amount']); ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger py-0 ml-2" style="font-size:.7em" title="删除该模块全部订单" onclick="event.preventDefault();event.stopPropagation();deleteProject('<?php echo e($grpName); ?>', <?php echo $grp['cnt']; ?>, <?php echo $filter_employee; ?>, '<?php echo e($filter_dept); ?>', <?php echo $filter_dept_orders ? 'true' : 'false'; ?>);"><i class="fas fa-trash-alt"></i></button>
                                            <i class="fas fa-chevron-<?php echo $isExpand ? 'up' : 'down'; ?> ml-2 text-muted" style="font-size:.8em"></i>
                                        </span>
                                    </a>
                                    </div>

                                    <?php if ($isExpand): ?>
                                        <div class="small text-muted px-3 py-1 bg-light border-left border-right border-bottom rounded-bottom">
                                            明细已在弹窗中打开。
                                        </div>
                                    <?php endif; ?>


                </div><!-- /order-group -->
                <?php endforeach; // projects in month ?>
                
                            </div><!-- /card-body for month -->
                        </div><!-- /card for month -->
                        <?php endforeach; // months in year ?>
                    </div><!-- /card-body for year -->
                </div><!-- /card for year -->
                <?php endforeach; // years ?>
                </form><!-- /monthDeleteForm -->
                
                <?php endif; ?> <!-- 结束三级判断 -->

                <?php endif; // empty projectGroups ?>

            </div>
        </div>
    </div>
</div>

