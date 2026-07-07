<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../classes/SimpleXLSX.php';

$page_title = '店铺订单上传';
$success = '';
$error = '';

// 锁定当前店铺
$shop_id = (int)($_GET['shop_id'] ?? 0);
$shop = get_shop($shop_id);
if (!$shop) {
    header('Location: ' . BASE_URL . '/shops/index.php');
    exit;
}

/**
 * 确保 orders 表有 shop 字段
 */
function ensureShopColumn()
{
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $cols = db()->query("SHOW COLUMNS FROM `orders` LIKE 'shop'")->fetchAll();
        if (empty($cols)) {
            db()->exec("ALTER TABLE `orders` ADD COLUMN `shop` VARCHAR(100) DEFAULT '' COMMENT '店铺' AFTER `project`");
        }
    } catch (\Throwable $e) {}
}
ensureShopColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'manual_add') {
        $order_amount = (float)($_POST['order_amount'] ?? 0);
        $order_date   = $_POST['order_date'] ?? '';

        if ($order_amount <= 0 || $order_date === '') {
            $error = '请填写完整且有效的订单信息';
        } else {
            try {
                $stmt = db()->prepare("INSERT INTO orders (employee_id, order_amount, order_date, shop, order_scope) VALUES (?, ?, ?, ?, 'department')");
                $stmt->execute([0, $order_amount, $order_date, $shop['name']]);
                $success = '订单添加成功';
            } catch (PDOException $ex) {
                $error = '添加失败: ' . $ex->getMessage();
            }
        }
    } elseif ($action === 'upload') {
        $csvData = trim($_POST['csv_data'] ?? '');
        $hasFile = isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === UPLOAD_ERR_OK;
        $upload_month = trim($_POST['upload_month'] ?? '');

        if ($csvData === '' && !$hasFile) {
            $error = '请选择要上传的文件';
        } elseif ($upload_month === '') {
            $error = '请选择订单归属月份';
        } else {
            try {
                $rows = [];

                if ($csvData !== '') {
                    // 前端 SheetJS 传来的 JSON 二维数组
                    $decoded = json_decode($csvData, true);
                    if (is_array($decoded)) {
                        foreach ($decoded as $row) {
                            if (is_array($row) && count(array_filter($row, fn($v) => trim($v) !== '')) > 0) {
                                $rows[] = $row;
                            }
                        }
                    } else {
                        $error = '数据格式错误，请重新上传';
                    }
                } else {
                    $file     = $_FILES['excel_file'];
                    $filename = $file['name'];
                    $ext      = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    $tmp      = $file['tmp_name'];
                    if (!in_array($ext, ['xlsx', 'csv'])) {
                        $error = '文件类型仅支持 .xlsx / .csv';
                    } else {
                        if ($ext === 'csv') {
                            $fp = fopen($tmp, 'r');
                            $bom = fread($fp, 3);
                            if ($bom !== "\xEF\xBB\xBF") { rewind($fp); }
                            while (($data = fgetcsv($fp)) !== false) { $rows[] = $data; }
                            fclose($fp);
                        } else {
                            $rows = SimpleXLSX::parse($tmp);
                        }
                    }
                }

                if ($error === '' && !empty($rows)) {
                    $firstRow = $rows[0];
                    $dataRows = array_slice($rows, 1);

                    // 原样保存表头（空列用"列N"占位）
                    $normalizedHeaders = [];
                    $colMap = [];
                    foreach ($firstRow as $ci => $cv) {
                        $cv = trim(preg_replace('/[\x00-\x1F\x80-\x9F\xEF\xBB\xBF\xC2\xA0]/u', '', $cv));
                        $normalizedHeaders[$ci] = $cv !== '' ? $cv : ('列' . ($ci + 1));
                        if ($cv !== '') $colMap[$cv] = $ci;
                    }

                    // 找金额/价格/成本列（模糊匹配，支持多种表头名称）
                    $idxPrice = null; $idxCost = null; $idxAmount = null;
                    foreach ($colMap as $k => $idx) {
                        if ($idxAmount === null && (mb_strpos($k, '订单金额') !== false || mb_strpos($k, '金额') !== false)) $idxAmount = $idx;
                        if ($idxPrice === null && (mb_strpos($k, '价格') !== false || mb_strpos($k, '售价') !== false)) $idxPrice = $idx;
                        if ($idxCost  === null && (mb_strpos($k, '成本') !== false)) $idxCost  = $idx;
                    }

                    // 校验：要么有订单金额列，要么有价格和成本列
                    if ($idxAmount === null && ($idxPrice === null || $idxCost === null)) {
                        $error = '表头缺少金额字段：需要"订单金额"列，或同时有"价格/售价"和"成本/总成本"列';
                        goto upload_done;
                    }

                    // 保存表头到 upload_batches
                    $batchHeaders = json_encode(array_values($normalizedHeaders), JSON_UNESCAPED_UNICODE);
                    $batchStmt = db()->prepare("INSERT INTO upload_batches (employee_id, headers) VALUES (?, ?)");
                    $batchStmt->execute([0, $batchHeaders]);

                    $inserted = 0; $skipped = 0;
                    $stmt = db()->prepare("INSERT INTO orders (employee_id, order_amount, order_date, shop, raw_data, is_abnormal, abnormal_reason, order_scope) VALUES (?, ?, ?, ?, ?, ?, ?, 'department')");

                    foreach ($dataRows as $row) {
                        if (count(array_filter($row, fn($v) => trim($v) !== '')) === 0) continue;

                        // 计算订单金额
                        if ($idxAmount !== null) {
                            $amount = (float)preg_replace('/[^\d.\-]/', '', trim($row[$idxAmount] ?? ''));
                        } else {
                            $price  = (float)preg_replace('/[^\d.\-]/', '', trim($row[$idxPrice] ?? ''));
                            $cost   = (float)preg_replace('/[^\d.\-]/', '', trim($row[$idxCost]  ?? ''));
                            $amount = $price - $cost;
                        }

                        // 日期：使用归属月份的第一天作为订单日期
                        $parsedDate = $upload_month . '-01';

                        // 异常标记：金额为0才标记异常，负数金额视为退款订单正常处理
                        $isAbn = 0; $abnReason = '';
                        if ($amount == 0) {
                            $isAbn = 1;
                            $abnReason = '订单金额为0';
                        }

                        $isRefund = ($amount < 0);

                        // 原样存储每列；额外存入店铺名
                        $rawMap = [];
                        foreach ($normalizedHeaders as $ci => $hdr) {
                            $rawMap[$hdr] = $row[$ci] ?? '';
                        }
                        $rawMap['__shop__'] = $shop['name'];
                        if ($isRefund) {
                            $rawMap['__is_refund__'] = '1';
                        }

                        $stmt->execute([0, $amount, $parsedDate, $shop['name'], json_encode($rawMap, JSON_UNESCAPED_UNICODE), $isAbn, $abnReason]);
                        $isAbn ? $skipped++ : $inserted++;
                    }

                    if ($error === '') {
                        $rq = ['shop_id' => $shop_id, 'upload_ok' => '1', 'msg' => urlencode("导入完成！为【{$shop['name']}】成功导入 {$inserted} 条" . ($skipped > 0 ? "，{$skipped} 条标记为异常" : ""))];
                        header('Location: ' . BASE_URL . '/shops/upload.php?' . http_build_query($rq));
                        exit;
                    }
                }
            } catch (Exception $ex) {
                $error = '解析失败: ' . $ex->getMessage();
            }
            upload_done:
        }
    }
}

// 查询该店铺已有订单（按月份分组汇总）
$filter_month = $_GET['month'] ?? '';
$baseWhere  = " WHERE o.shop = ?";
$baseParams = [$shop['name']];
if ($filter_month) { $baseWhere .= " AND DATE_FORMAT(o.order_date, '%Y-%m') = ?"; $baseParams[] = $filter_month; }

$groupSql = "SELECT DATE_FORMAT(o.order_date, '%Y-%m') as order_month,
                    COUNT(*) as cnt,
                    COALESCE(SUM(CASE WHEN o.is_abnormal=0 THEN o.order_amount ELSE 0 END),0) as normal_amount,
                    SUM(CASE WHEN o.is_abnormal=1 THEN 1 ELSE 0 END) as abn_cnt
             FROM orders o" . $baseWhere .
             " GROUP BY order_month ORDER BY order_month DESC";
$gStmt = db()->prepare($groupSql);
$gStmt->execute($baseParams);
$monthGroups = $gStmt->fetchAll();

$total_count  = array_sum(array_column($monthGroups, 'cnt'));
$total_amount = array_sum(array_column($monthGroups, 'normal_amount'));

define('BASE_PATH', dirname(__DIR__));
include __DIR__ . '/../includes/header.php';
?>

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

                    <!-- 订单归属月份选择（必填，默认当前月份，可自定义） -->
                    <div class="form-group">
                        <label><i class="fas fa-calendar text-warning"></i> 订单归属月份 <span class="required">*</span></label>
                        <input type="month" name="upload_month" class="form-control" value="<?php echo e($_POST['upload_month'] ?? date('Y-m')); ?>" min="2020-01" max="2030-12" required>
                        <small class="text-muted"><i class="fas fa-info-circle"></i> 默认为当前月份，可自定义修改；该批订单统一归属到所选月份（不受Excel中日期列影响）</small>
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
                    <form method="get" class="form-inline">
                        <input type="hidden" name="shop_id" value="<?php echo $shop_id; ?>">
                        <input type="month" name="month" class="form-control form-control-sm mr-1" value="<?php echo e($filter_month); ?>" onchange="this.form.submit()">
                        <?php if ($filter_month): ?>
                            <button type="submit" name="month" value="" class="btn btn-sm btn-outline-secondary" title="显示全部月份">全部</button>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            <div class="card-body p-2">
                <?php if (empty($monthGroups)): ?>
                    <div class="text-center text-muted py-5"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>暂无订单数据</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="thead-light">
                                <tr><th>归属月份</th><th style="width:100px">订单数</th><th style="width:120px">异常</th><th class="text-right">正常金额</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($monthGroups as $m): ?>
                                <tr>
                                    <td><i class="fas fa-calendar text-info mr-1"></i> <?php echo date('Y年m月', strtotime($m['order_month'] . '-01')); ?></td>
                                    <td><span class="badge badge-secondary"><?php echo $m['cnt']; ?> 笔</span></td>
                                    <td><?php if ($m['abn_cnt'] > 0): ?><span class="badge badge-danger"><?php echo $m['abn_cnt']; ?> 条</span><?php else: ?><span class="text-muted">--</span><?php endif; ?></td>
                                    <td class="text-success font-weight-bold">¥<?php echo money($m['normal_amount']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// 文件上传区交互
(function(){
    var area = document.getElementById('uploadArea');
    var file = document.getElementById('excelFile');
    var tip = document.getElementById('uploadTip');
    if (!area || !file) return;
    area.addEventListener('click', function(){ file.click(); });
    file.addEventListener('change', function(){
        if (file.files.length) { tip.textContent = file.files[0].name; }
    });
    ['dragover','dragenter'].forEach(function(ev){
        area.addEventListener(ev, function(e){ e.preventDefault(); area.style.borderColor='#28a745'; });
    });
    ['dragleave','drop'].forEach(function(ev){
        area.addEventListener(ev, function(e){ e.preventDefault(); area.style.borderColor=''; });
    });
    area.addEventListener('drop', function(e){
        if (e.dataTransfer.files.length) { file.files = e.dataTransfer.files; tip.textContent = file.files[0].name; }
    });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
