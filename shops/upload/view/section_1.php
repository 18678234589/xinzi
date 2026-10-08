
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="font-weight-bold mb-0 d-inline-block"><i class="fas fa-store"></i> 店铺订单上传</h4>
        <span class="badge badge-success ml-2" style="font-size:.9em">
            <i class="fas fa-store"></i> 已锁定店铺：<?php echo e($shop['name']); ?>
        </span>
    </div>
    <a href="<?php echo BASE_URL; ?>/shops/index.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left"></i> 返回店铺管理
    </a>
</div>

<?php if ($success || isset($_GET['upload_ok'])): ?>
    <div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle"></i> <?php echo e($success ?: urldecode($_GET['msg'] ?? '导入完成')); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-circle"></i> <?php echo e($error); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>

<div class="row">
    <!-- 左侧：上传 & 手动添加 -->
    <div class="col-md-5">
        <!-- 批量上传 -->
        <div class="card mb-3">
            <div class="card-header bg-white"><h5 class="mb-0"><i class="fas fa-file-excel text-success"></i> 批量上传订单Excel</h5></div>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data" id="uploadForm">
                    <input type="hidden" name="action" value="upload">
                    <div class="form-group">
                        <label>归属店铺</label>
                        <input type="text" class="form-control" value="<?php echo e($shop['name']); ?>" disabled>
                        <small class="text-muted"><i class="fas fa-info-circle"></i> 本批订单将归属该店铺</small>
                    </div>

                    <!-- 订单日期：淘宝导出的表格本身有付款时间，每笔订单按自己的日期入账；只有表格没有日期列时才需要选月份兜底 -->
                    <div class="form-group">
                        <label><i class="fas fa-calendar text-warning"></i> 兜底月份 <span class="text-muted">（可不选）</span></label>
                        <input type="month" name="upload_month" class="form-control" value="<?php echo e($_POST['upload_month'] ?? ''); ?>" min="2020-01" max="2030-12">
                        <small class="text-muted"><i class="fas fa-info-circle"></i> 订单日期自动取表格里每笔订单的付款 / 下单时间，<strong>不用选</strong>。只有表格没有日期列（或某行日期缺失）时，才会按这里选的月份入账；不选则这些行不导入。</small>
                    </div>

                    <div class="form-group">
                        <label>选择文件 <span class="required">*</span></label>
                        <div class="upload-area" id="uploadArea">
                            <i class="fas fa-cloud-upload-alt fa-3x text-muted mb-2"></i>
                            <p class="mb-1" id="uploadTip">点击或拖拽文件到此处</p>
                            <p class="text-muted small mb-0">支持 .xlsx / .csv 格式</p>
                            <input type="file" name="excel_file" id="excelFile" class="d-none" accept=".xlsx,.csv">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-success btn-block"><i class="fas fa-upload"></i> 开始上传</button>
                </form>
            </div>
        </div>

        <!-- 手动添加 -->
        <div class="card">
            <div class="card-header bg-white"><h5 class="mb-0"><i class="fas fa-hand-pointer text-primary"></i> 手动添加单笔订单</h5></div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="manual_add">
                    <div class="form-group">
                        <label>归属店铺</label>
                        <input type="text" class="form-control" value="<?php echo e($shop['name']); ?>" disabled>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>订单金额 <span class="required">*</span></label>
                            <input type="number" name="order_amount" class="form-control" step="0.01" min="0" required>
                        </div>
                        <div class="form-group col-md-6">
                            <label>订单日期 <span class="required">*</span></label>
                            <input type="date" name="order_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            <small class="text-muted">默认为今天，可自定义修改</small>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-plus"></i> 添加订单</button>
                </form>
            </div>
        </div>
    </div>

    <!-- 右侧：订单记录 -->
    <div class="col-md-7">
        <div class="card">
            <div class="card-header bg-white">
                <div class="d-flex justify-content-between align-items-center flex-wrap">
                    <h5 class="mb-0"><i class="fas fa-list text-info"></i> 订单记录
                        <small class="text-muted" style="font-size:.8em">共 <?php echo $total_count; ?> 条 / ¥<?php echo money($total_amount); ?></small>
                    </h5>
                    <form method="get" class="form-inline" id="filterForm">
                        <input type="hidden" name="shop_id" value="<?php echo $shop_id; ?>">
                        <input type="text" name="search_no" value="<?php echo e($search_no); ?>" class="form-control form-control-sm mr-1" placeholder="搜索订单号…" style="width:160px">
                        <input type="month" name="month" class="form-control form-control-sm mr-1" value="<?php echo e($filter_month); ?>" onchange="document.getElementById('filterForm').submit()">
                        <button type="submit" class="btn btn-sm btn-outline-primary mr-1"><i class="fas fa-search"></i></button>
                        <?php if ($filter_month || $search_no): ?>
                            <a href="?shop_id=<?php echo $shop_id; ?>" class="btn btn-sm btn-outline-secondary" title="清除筛选"><i class="fas fa-times"></i></a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            <div class="card-body p-0">
