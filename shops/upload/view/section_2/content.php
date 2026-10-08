                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="thead-light">
                                <tr><th>归属月份</th><th style="width:100px">订单数</th><th style="width:120px">异常</th><th class="text-right" style="width:120px">正常金额</th><th style="width:80px"></th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($monthGroups as $m):
                                $isExpanded = ($detail_month === $m['order_month']);
                                $monthUrl = BASE_URL . '/shops/upload.php?shop_id=' . $shop_id
                                    . ($filter_month ? '&month=' . urlencode($filter_month) : '')
                                    . ($isExpanded ? '' : '&detail=' . urlencode($m['order_month']) . '&page=1&page_size=' . $page_size);
                            ?>
                                <tr class="<?php echo $isExpanded ? 'table-active' : ''; ?>" style="cursor:pointer" onclick="window.location='<?php echo $monthUrl; ?>'">
                                    <td>
                                        <i class="fas fa-<?php echo $isExpanded ? 'caret-down' : 'caret-right'; ?> text-muted mr-1"></i>
                                        <i class="fas fa-calendar text-info mr-1"></i>
                                        <?php echo date('Y年m月', strtotime($m['order_month'] . '-01')); ?>
                                    </td>
                                    <td><span class="badge badge-secondary"><?php echo $m['cnt']; ?> 笔</span></td>
                                    <td><?php if ($m['abn_cnt'] > 0): ?><span class="badge badge-danger"><?php echo $m['abn_cnt']; ?> 条</span><?php else: ?><span class="text-muted">--</span><?php endif; ?></td>
                                    <td class="text-success font-weight-bold">¥<?php echo money($m['normal_amount']); ?></td>
                                    <td class="text-right" style="width:80px">
                                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="event.stopPropagation();deleteMonth('<?php echo e($m['order_month']); ?>', <?php echo $m['cnt']; ?>)" title="一键删除该月全部订单">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php if ($isExpanded): ?>
                                <tr><td colspan="5" class="p-0">
                                    <!-- 详细订单列表 -->
                                    <div class="p-2 bg-light border-top">
                                        <!-- 搜索表单（独立于 detailForm，避免嵌套） -->
                                        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap">
                                            <div class="d-flex align-items-center">
                                                <span class="text-muted small mr-2">共 <?php echo $detail_total; ?> 条</span>
                                            </div>
                                            <div class="d-flex align-items-center">
                                                <form method="get" class="form-inline mr-2" id="orderSearchForm">
                                                    <input type="hidden" name="shop_id" value="<?php echo $shop_id; ?>">
                                                    <input type="hidden" name="detail" value="<?php echo e($detail_month); ?>">
                                                    <input type="hidden" name="page_size" value="<?php echo $page_size; ?>">
                                                    <div class="input-group input-group-sm">
                                                        <input type="text" name="search_no" value="<?php echo e($detail_search); ?>" class="form-control" placeholder="搜索订单号…" style="max-width:160px;width:100%" autocomplete="off">
                                                        <div class="input-group-append">
                                                            <button class="btn btn-outline-primary" type="submit"><i class="fas fa-search"></i></button>
                                                            <?php if ($detail_search !== ''): ?>
                                                                <a class="btn btn-outline-secondary" href="?shop_id=<?php echo $shop_id; ?>&detail=<?php echo urlencode($detail_month); ?>&page_size=<?php echo $page_size; ?>" title="清除搜索"><i class="fas fa-times"></i></a>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </form>
                                                <label class="text-muted small mb-0 mr-1">每页</label>
                                                <select class="form-control form-control-sm mr-1" style="width:auto" onchange="changePageSize(this.value)">
                                                    <?php foreach ([10, 20, 50, 100, 500, 1000, 2000, 0] as $ps): ?>
                                                        <option value="<?php echo $ps; ?>" <?php if($page_size==$ps) echo 'selected'; ?>><?php echo $ps === 0 ? '全部' : $ps; ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <span class="text-muted small">条</span>
                                            </div>
                                        </div>

                                        <form method="post" id="detailForm">
                                            <input type="hidden" name="action" value="batch_delete">
                                            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap">
                                                <div class="d-flex align-items-center">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger mr-2" onclick="return confirm('确定删除选中的订单？')">
                                                        <i class="fas fa-trash-alt"></i> 批量删除
                                                    </button>
                                                    <span class="text-muted small">已选 <b id="selCount">0</b> 条</span>
                                                </div>
                                            </div>

                                            <?php if (empty($detail_orders)): ?>
                                                <div class="text-center text-muted py-3">该月份无订单明细</div>
                                            <?php else: ?>
                                            <div class="table-responsive">
                                                <table class="table table-sm table-bordered mb-2 bg-white">
                                                    <thead class="thead-light">
                                                        <tr>
                                                            <th style="width:36px"><input type="checkbox" id="chkAll" onclick="toggleAll(this)"></th>
                                                            <th style="width:70px">ID</th>
                                                            <th>订单号</th>
                                                            <th>订单金额</th>
                                                            <th>订单日期</th>
                                                            <th>订单状态</th>
                                                            <th>状态</th>
                                                            <th style="width:110px">操作</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                    <?php foreach ($detail_orders as $o):
                                                        $raw = $o['raw_data'] ? json_decode($o['raw_data'], true) : [];
                                                        $isAbn = (int)$o['is_abnormal'] === 1;
                                                        $isRefund = (float)$o['order_amount'] < 0;
                                                        $refundAmt = is_array($raw) ? round((float)($raw['退款金额'] ?? 0), 2) : 0;
                                                        $isPartial = !$isRefund && $refundAmt > 0;
                                                        $orderStatus = extract_order_status($raw);
                                                    ?>
                                                        <tr>
                                                            <td><input type="checkbox" name="ids[]" value="<?php echo $o['id']; ?>" class="row-chk" onclick="updateSelCount()"></td>
                                                            <td><small class="text-muted"><?php echo $o['id']; ?></small></td>
                                                            <td><span class="text-monospace small"><?php echo e($o['order_no'] ?: '--'); ?></span></td>
                                                            <td class="<?php echo $isRefund ? 'text-danger' : ''; ?>">
                                                                <?php if ($isRefund): ?>
                                                                    <span class="font-weight-bold">¥<?php echo money($o['order_amount']); ?></span>
                                                                    <small class="text-muted">(退款)</small>
                                                                <?php else:
                                                                    $feeInfo = get_order_fee_info($raw, $o);
                                                                    if ($feeInfo['rate'] > 0 && $feeInfo['original_price'] > 0):
                                                                ?>
                                                                    <div class="text-muted small">售价: ¥<?php echo money($feeInfo['original_price']); ?></div>
                                                                    <div class="text-warning small">手续费: ¥<?php echo money($feeInfo['amount']); ?> (<?php echo rtrim(rtrim(number_format($feeInfo['rate'] * 100, 2, '.', ''), '0'), '.'); ?>%)</div>
                                                                    <div class="text-success font-weight-bold">净额: ¥<?php echo money($feeInfo['net']); ?></div>
                                                                <?php else: ?>
                                                                    <span class="text-success font-weight-bold">¥<?php echo money($o['order_amount']); ?></span>
                                                                <?php endif; endif; ?>
                                                                <?php if ($isPartial): ?><small class="text-muted">(部分退款 ¥<?php echo money($refundAmt); ?>)</small><?php endif; ?>
                                                            </td>
                                                            <td><small><?php echo e(substr($raw['__trade_time__'] ?? $o['order_date'], 0, 10)); ?></small></td>
                                                            <td><small><?php echo e($orderStatus ?: '--'); ?></small></td>
                                                            <td>
                                                                <?php if ($isAbn): ?>
                                                                    <span class="badge badge-warning" title="<?php echo e($o['abnormal_reason']); ?>">异常</span>
                                                                <?php elseif ($isRefund): ?>
                                                                    <span class="badge badge-info">退款</span>
                                                                <?php elseif ($isPartial): ?>
                                                                    <span class="badge badge-info" title="已退款 ¥<?php echo money($refundAmt); ?>">部分退款</span>
                                                                <?php else: ?>
                                                                    <span class="badge badge-success">正常</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <button type="button" class="btn btn-sm btn-link p-0 text-info" data-detail='<?php echo htmlspecialchars(json_encode(["id"=>$o["id"],"order_no"=>$o["order_no"],"amount"=>$o["order_amount"],"date"=>$raw['__trade_time__'] ?? $o['order_date'],"status"=>$orderStatus,"reason"=>$o["abnormal_reason"],"raw"=>$raw], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8'); ?>' onclick="showDetail(JSON.parse(this.dataset.detail))">
                                                                    <i class="fas fa-eye"></i>
                                                                </button>
                                                                <button type="button" class="btn btn-sm btn-link p-0 text-danger" onclick="deleteOrder(<?php echo $o['id']; ?>, '<?php echo e($o['order_date']); ?>')">
                                                                    <i class="fas fa-trash-alt"></i>
                                                                </button>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>

                                            <!-- 分页 -->
                                            <?php if ($detail_pages > 1): ?>
                                            <nav class="d-flex justify-content-between align-items-center">
                                                <ul class="pagination pagination-sm mb-0">
                                                    <?php
                                                    $baseLink = BASE_URL . '/shops/upload.php?shop_id=' . $shop_id
                                                        . ($filter_month ? '&month=' . urlencode($filter_month) : '')
                                                        . '&detail=' . urlencode($detail_month)
                                                        . ($detail_search !== '' ? '&search_no=' . urlencode($detail_search) : '')
                                                        . '&page_size=' . $page_size . '&page=';
                                                    ?>
                                                    <li class="page-item <?php if($page<=1) echo 'disabled'; ?>"><a class="page-link" href="<?php echo $baseLink.($page-1); ?>">&laquo;</a></li>
                                                    <?php
                                                    $startP = max(1, $page - 2);
                                                    $endP = min($detail_pages, $page + 2);
                                                    if ($startP > 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                                                    for ($i = $startP; $i <= $endP; $i++):
                                                    ?>
                                                        <li class="page-item <?php if($i==$page) echo 'active'; ?>"><a class="page-link" href="<?php echo $baseLink.$i; ?>"><?php echo $i; ?></a></li>
                                                    <?php endfor;
                                                    if ($endP < $detail_pages) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                                                    ?>
                                                    <li class="page-item <?php if($page>=$detail_pages) echo 'disabled'; ?>"><a class="page-link" href="<?php echo $baseLink.($page+1); ?>">&raquo;</a></li>
                                                </ul>
                                                <span class="text-muted small">第 <?php echo $page; ?> / <?php echo $detail_pages; ?> 页</span>
                                            </nav>
                                            <?php endif; ?>
                                            <?php endif; ?>
                                        </form>
                                    </div>
                                </td></tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="px-3 py-2 text-muted small border-top"><i class="fas fa-info-circle"></i> 点击月份行可展开/收起该月订单明细</div>
