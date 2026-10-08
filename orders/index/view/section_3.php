<?php if ($expand_project !== ''): ?>
<?php $detailAllQ = $detailQ; unset($detailAllQ['abnormal'], $detailAllQ['refund']); $detailAbnormalQ = array_merge($detailQ, ['abnormal' => '1', 'page' => 1]); $detailRefundQ = array_merge
    ($detailQ, ['refund' => '1', 'page' => 1]); ?>
<div class="modal fade" id="orderDetailModal" tabindex="-1" role="dialog" aria-labelledby="orderDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl order-detail-modal" role="document">
        <div class="modal-content">
            <div class="modal-header py-2">
                <div>
                    <h5 class="modal-title mb-0" id="orderDetailModalLabel"><i class="fas fa-list text-info"></i> <?php echo e($expand_project); ?> 明细</h5>
                    <small class="text-muted">共 <?php echo $detail_count; ?> 条，¥<?php echo money($detail_amount); ?><?php if ($filter_abnormal): ?> <span class="badge badge-danger ml-1">仅异常</span><?php
    endif; ?><?php if ($filter_refund): ?> <span class="badge badge-secondary ml-1">仅退款</span><?php endif; ?></small>
                </div>
                <div class="d-flex align-items-center flex-wrap justify-content-end">
                    <form method="get" class="form-inline mr-2" id="orderSearchForm">
                        <input type="hidden" name="project" value="<?php echo e($expand_project); ?>">
                        <?php foreach ($detailQ as $k => $v): ?>
                            <?php if ($k !== 'search_no' && $k !== 'project' && $k !== 'page'): ?>
                                <input type="hidden" name="<?php echo e($k); ?>" value="<?php echo e($v); ?>">
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <div class="input-group input-group-sm">
                            <input type="text" name="search_no" value="<?php echo e($filter_search); ?>" class="form-control" placeholder="搜索订单号…" style="max-width:180px;width:100%" autocomplete="off">
                            <div class="input-group-append">
                                <button class="btn btn-outline-primary" type="submit"><i class="fas fa-search"></i></button>
                                <?php if ($filter_search !== ''): ?>
                                    <a class="btn btn-outline-secondary" href="<?php echo '?' . http_build_query(array_merge($detailQ, ['search_no' => ''])); ?>" title="清除搜索"><i class="fas fa-times"></i></a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>
                    <select class="form-control form-control-sm mr-2" style="width:auto" onchange="location.href='<?php echo '?' . http_build_query(array_merge($detailQ, ['per_page'
    => '__PP__'])); ?>'.replace('__PP__', this.value)">
                        <?php foreach ([20,50,100,200,500,1000] as $n): ?>
                            <option value="<?php echo $n; ?>" <?php echo $per_page == $n ? 'selected' : ''; ?>><?php echo $n; ?> 条/页</option>
                        <?php endforeach; ?>
                    </select>
                    <a class="btn btn-sm <?php echo $filter_abnormal ? 'btn-danger' : 'btn-outline-danger'; ?> mr-2" href="<?php echo '?' . http_build_query($detailAbnormalQ); ?>"><i class="fas fa-exclamation-triangle"></i> 只看异常</a>
                    <a class="btn btn-sm <?php echo $filter_refund ? 'btn-secondary' : 'btn-outline-secondary'; ?> mr-2" href="<?php echo '?' . http_build_query($detailRefundQ); ?>"><i class="fas fa-undo-alt"></i> 只看退款</a>
                    <?php if ($filter_abnormal || $filter_refund): ?>
                        <a class="btn btn-sm btn-outline-primary mr-2" href="<?php echo '?' . http_build_query($detailAllQ); ?>">全部订单</a>
                    <?php endif; ?>
                    <a class="btn btn-sm btn-outline-secondary mr-2" href="<?php echo '?' . http_build_query($baseQ); ?>">退出明细</a>
                    <button type="button" class="btn btn-sm btn-outline-success mr-2" onclick="showVerifyStatusModal()"><i class="fas fa-check-double"></i> 核验订单状态</button>
                    <button type="button" class="close" data-dismiss="modal" aria-label="关闭"><span aria-hidden="true">&times;</span></button>
                </div>
            </div>
            <div class="modal-body p-2">
                <form method="post" id="batchForm">
                    <input type="hidden" name="action" value="batch_delete">
                    <input type="hidden" name="_page" value="<?php echo $page; ?>">
                    <input type="hidden" name="_per_page" value="<?php echo $per_page; ?>">
                    <input type="hidden" name="_month" value="<?php echo e($filter_month); ?>">
                    <input type="hidden" name="_employee_id" value="<?php echo $filter_employee; ?>">
                    <input type="hidden" name="_project" value="<?php echo e($expand_project); ?>">
                    <div class="align-items-center mb-2" id="batchBar" style="display:none">
                        <span class="text-muted small mr-2" id="selectedCount">已选 0 条</span>
                        <button type="submit" class="btn btn-sm btn-danger" id="batchDelBtn" onclick="return confirm('确定删除所选订单？此操作不可恢复！')"><i class="fas fa-trash-alt"></i> 批量删除</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary ml-2" onclick="clearSelection()">取消选择</button>
                    </div>
                    <div class="table-responsive order-detail-table-wrap">
                        <div class="mb-2">
                            <small class="text-muted mr-3"><span class="badge badge-primary"><i class="fas fa-user"></i></span> 个人订单</small>
                            <small class="text-muted mr-3"><span class="badge badge-success"><i class="fas fa-building"></i></span> 部门汇总</small>
                            <small class="text-muted mr-3"><span class="badge badge-warning"><i class="fas fa-share-alt"></i></span> 部门拆分(合作人员项目分成来源)</small>
                            <small class="text-muted"><span class="badge badge-danger"><i class="fas fa-exclamation-triangle"></i></span> 异常</small>
                        </div>
                        <table class="table table-sm table-hover mb-0 order-detail-table" id="ordersTable">
                            <thead class="thead-light sticky-top">
                                <tr>
                                    <th style="width:32px"><input type="checkbox" id="checkAll" title="全选" onclick="var cbs=document.querySelectorAll('.row-check');cbs.forEach(function(c){c.checked=this.checked;}.bind(this));document.getElementById('batchBar').style.display=this.checked?'flex':'none';document.getElementById('batchBar').style.alignItems='center';document.getElementById('selectedCount').textContent='已选 '+(this.checked?cbs.length:0)+' 条';document.getElementById('batchDelBtn').disabled=!this.checked;"></th>
                                    <th>ID</th><th>合作人员</th>
                                    <?php foreach ($uploadHeaders as $hdr): ?>
                                        <th><?php echo e($hdr); ?></th>
                                        <?php if ($hdr === '店铺'): ?><th>订单状态</th><?php endif; ?>
                                    <?php endforeach; ?>
                                    <?php if (!in_array('店铺', $uploadHeaders)): ?><th>订单状态</th><?php endif; ?>
                                    <th>计算金额</th><th>上传日期</th><th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if ($orders): foreach ($orders as $o): ?>
                                <?php $rawData = !empty($o['raw_data']) ? (json_decode($o['raw_data'], true) ?: []) : []; ?>
                                <?php $isFromDept = isset($rawData['__from_dept__']); ?>
                                <?php $isDeptSummary = ($o['order_scope'] === 'department' && (int)$o['employee_id'] === 0); ?>
                                <tr class="<?php echo !empty($o['is_abnormal']) ? 'table-danger' : ($isFromDept ? 'table-warning' : ($isDeptSummary ? 'table-info' : '')); ?>">
                                    <td><input type="checkbox" class="row-check" name="ids[]" value="<?php echo $o['id']; ?>" onclick="var cbs=document.querySelectorAll('.row-check'),n=0;cbs.forEach(function(c){if(c.checked)n++;});var ca=document.getElementById('checkAll');if(ca){ca.checked=n===cbs.length;ca.indeterminate=n>0&&n<cbs.length;}var bb=document.getElementById('batchBar');if(bb){bb.style.display=n>0?'flex':'none';bb.style.alignItems='center';}var st=document.getElementById('selectedCount');if(st)st.textContent='已选 '+n+' 条';var bd=document.getElementById('batchDelBtn');if(bd)bd.disabled=n===0;"></td>
                                    <td><?php echo $o['id']; ?></td>
                                    <td>
                                        <?php if ($isDeptSummary): ?>
                                            <?php $dept = $rawData['__dept__'] ?? ''; ?>
                                            <span class="badge badge-success" title="部门订单汇总行"><i class="fas fa-building"></i> <?php echo e($dept ?: '部门'); ?></span>
                                        <?php elseif ($isFromDept): ?>
                                            <span class="badge badge-warning" title="部门订单拆分到合作人员（项目分成来源）"><i class="fas fa-share-alt"></i> <?php
    echo e($o['name'] ?: '--'); ?></span><small class="text-muted d-block">来自：<?php echo e($rawData['__from_dept__']); ?></small>
                                        <?php else: ?>
                                            <span class="badge badge-primary"><i class="fas fa-user"></i> <?php echo e($o['name'] ?: '--'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <?php foreach ($uploadHeaders as $hdr): ?>
                                        <td><?php echo isset($rawData[$hdr]) && $rawData[$hdr] !== '' ? e($rawData[$hdr]) : '<span class="text-muted small">--</span>'; ?></td>
                                        <?php if ($hdr === '店铺'): ?>
                                        <td>
                                            <?php
                                            $orderStatus = '';
                                            $shopStatus = $rawData['__shop_order_status__'] ?? '';
                                            if ($shopStatus !== '') {
                                                $orderStatus = $shopStatus;
                                            } elseif (isset($rawData['__order_status__']) && $rawData['__order_status__'] !== '') {
                                                $orderStatus = $rawData['__order_status__'];
                                            } else {
                                                foreach ($rawData as $k => $v) {
                                                    if (strlen($k) > 4 && substr($k, 0, 2) === '__' && substr($k, -2) === '__') continue;
                                                    if (mb_strpos($k, '订单状态') !== false && trim((string)$v) !== '') {
                                                        $orderStatus = trim((string)$v);
                                                        break;
                                                    }
                                                }
                                            }
                                            if ($orderStatus !== '') {
                                                $statusColor = 'secondary';
                                                if (mb_strpos($orderStatus, '交易成功') !== false || mb_strpos($orderStatus, '已到账') !== false) $statusColor = 'success';
                                                elseif (mb_strpos($orderStatus, '已发货') !== false) $statusColor = 'info';
                                                elseif (mb_strpos($orderStatus, '未核验') !== false) $statusColor = 'warning';
                                                elseif (mb_strpos($orderStatus, '已取消') !== false || mb_strpos($orderStatus, '退款') !== false) $statusColor = 'danger';
                                                echo '<span class="badge badge-' . $statusColor . '">' . e($orderStatus) . '</span>';
                                            } else {
                                                echo '<span class="text-muted small">--</span>';
                                            }
                                            ?>
                                        </td>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <?php if (!in_array('店铺', $uploadHeaders)): ?>
                                    <td>
                                        <?php
                                        $orderStatus = '';
                                        $shopStatus = $rawData['__shop_order_status__'] ?? '';
                                        if ($shopStatus !== '') {
                                            $orderStatus = $shopStatus;
                                        } elseif (isset($rawData['__order_status__']) && $rawData['__order_status__'] !== '') {
                                            $orderStatus = $rawData['__order_status__'];
                                        } else {
                                            foreach ($rawData as $k => $v) {
                                                if (strlen($k) > 4 && substr($k, 0, 2) === '__' && substr($k, -2) === '__') continue;
                                                if (mb_strpos($k, '订单状态') !== false && trim((string)$v) !== '') {
                                                    $orderStatus = trim((string)$v);
                                                    break;
                                                }
                                            }
                                        }
                                        if ($orderStatus !== '') {
                                            $statusColor = 'secondary';
                                            if (mb_strpos($orderStatus, '交易成功') !== false || mb_strpos($orderStatus, '已到账') !== false) $statusColor = 'success';
                                            elseif (mb_strpos($orderStatus, '已发货') !== false) $statusColor = 'info';
                                            elseif (mb_strpos($orderStatus, '未核验') !== false) $statusColor = 'warning';
                                            elseif (mb_strpos($orderStatus, '已取消') !== false || mb_strpos($orderStatus, '退款') !== false) $statusColor = 'danger';
                                            echo '<span class="badge badge-' . $statusColor . '">' . e($orderStatus) . '</span>';
                                        } else {
                                            echo '<span class="text-muted small">--</span>';
                                        }
                                        ?>
                                    </td>
                                    <?php endif; ?>
                                    <td class="<?php echo !empty($o['is_abnormal']) ? 'text-danger' : ''; ?>">
                                        <?php if (!empty($o['is_abnormal'])): ?>
                                            <i class="fas fa-exclamation-triangle"></i> ¥<?php echo money($o['order_amount']); ?><br><small><?php echo e($o['abnormal_reason'] ?? ''
    ); ?></small>
                                        <?php else:
                                            $feeInfo = get_order_fee_info($rawData, $o);
                                            if ($feeInfo['rate'] > 0 && $feeInfo['original_price'] > 0):
                                            ?>
                                                <div class="text-muted small">售价: ¥<?php echo money($feeInfo['original_price']); ?></div>
                                                <div class="text-warning small">手续费: ¥<?php echo money($feeInfo['amount']); ?> (<?php echo rtrim(rtrim(number_format($feeInfo
    ['rate'] * 100, 2, '.', ''), '0'), '.'); ?>%)</div>
                                                <div class="text-success font-weight-bold">净额: ¥<?php echo money($feeInfo['net']); ?></div>
                                            <?php else: ?>
                                                <span class="text-success font-weight-bold">¥<?php echo money($o['order_amount']); ?></span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-muted small"><?php echo !empty($o['created_at']) ? date('m-d H:i', strtotime($o['created_at'])) : '--'; ?></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-info py-0 mr-1" onclick="editOrder(<?php echo $o['id']; ?>)" title="编辑订单"><i class="fas fa-edit"></i></button>
                                        <button type="button" class="btn btn-sm btn-outline-danger py-0" onclick="deleteSingle(<?php echo $o['id']; ?>)"><i class="fas fa-times"></i></button>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="<?php echo 7 + count($uploadHeaders); ?>" class="text-center text-muted py-3">暂无数据</td></tr>
                            <?php endif; ?>
                            </tbody>
                            <?php if ($orders): ?>
                            <tfoot><tr class="table-light font-weight-bold"><td colspan="<?php echo 4 + count($uploadHeaders); ?>">本页合计</td><td class="text-success">¥<?php
    echo money(array_sum(array_column(array_filter($orders, fn($r) => empty($r['is_abnormal'])), 'order_amount'))); ?></td><td colspan="2"></td></tr></tfoot>
                            <?php endif; ?>
                        </table>
                    </div>
                </form>
                <?php if ($total_pages > 1): $detailPageQ = array_merge($detailQ, ['per_page' => $per_page]); ?>
                <nav class="mt-2"><ul class="pagination pagination-sm justify-content-center flex-wrap mb-0">
                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo '?' . http_build_query(array_merge($detailPageQ, ['page'
    => 1])); ?>">«</a></li>
                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo '?' . http_build_query(array_merge($detailPageQ, ['page'
    => $page-1])); ?>">‹</a></li>
                    <?php $start = max(1, $page - 2); $end = min($total_pages, $page + 2); if ($start > 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>'
    ; for ($i = $start; $i <= $end; $i++): ?>
                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>"><a class="page-link" href="<?php echo '?' . http_build_query(array_merge($detailPageQ, ['page'
    => $i])); ?>"><?php echo $i; ?></a></li>
                    <?php endfor; if ($end < $total_pages) echo '<li class="page-item disabled"><span class="page-link">…</span></li>'; ?>
                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo '?' . http_build_query(array_merge($detailPageQ
    , ['page' => $page+1])); ?>">›</a></li>
                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo '?' . http_build_query(array_merge($detailPageQ
    , ['page' => $total_pages])); ?>">»</a></li>
                </ul><p class="text-center text-muted small mt-1 mb-0">第 <?php echo $page; ?> / <?php echo $total_pages; ?> 页，每页 <?php echo $per_page; ?> 条</p></nav>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 单条删除专用 form（不能嵌套在 batchForm 里） -->
<form method="post" id="singleDeleteForm" style="display:none">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="singleDeleteId">
    <input type="hidden" name="_page" value="<?php echo $page; ?>">
    <input type="hidden" name="_per_page" value="<?php echo $per_page; ?>">
    <input type="hidden" name="_month" value="<?php echo e($filter_month); ?>">
    <input type="hidden" name="_employee_id" value="<?php echo $filter_employee; ?>">
    <input type="hidden" name="_project" value="<?php echo e($expand_project); ?>">
</form>

<script src="<?php echo BASE_URL; ?>/assets/lib/jquery/jquery.min.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/lib/xlsx.full.min.js"></script>
<script><?php /* split: orders/index/view/js_2.php */ include (dirname((dirname(__DIR__, 1)), 1)) . '/index/view/js_2.php'; ?></script>

<script><?php /* split: orders/index/view/js_3.php */ include (dirname((dirname(__DIR__, 1)), 1)) . '/index/view/js_3.php'; ?></script>

<style><?php /* split: assets/css/orders_index_4.css */ include (dirname((dirname(__DIR__, 1)), 1)) . '/../assets/css/orders_index_4.css'; ?></style>

<!-- 核验订单状态弹窗 -->
<div class="modal fade" id="verifyStatusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-check-double text-success"></i> 核验订单状态和金额</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info mb-3">
                    <p class="mb-1"><b>模块：</b><span id="verifyProject"></span></p>
                    <p class="mb-0"><b>月份：</b><span id="verifyMonth"></span></p>
                </div>
