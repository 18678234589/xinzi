<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/SalaryCalculator.php';
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
 * 从 raw_data 中提取订单状态
 * 优先读 __order_status__ 标记键（新上传已写入）；
 * 缺失时回退扫描原始列名（历史数据按原名原样存储），兼容无需同步的历史订单
 */
function extract_order_status($raw)
{
    if (!is_array($raw)) return '';
    if (isset($raw['__order_status__']) && $raw['__order_status__'] !== '') {
        return $raw['__order_status__'];
    }
    foreach ($raw as $k => $v) {
        if (strlen($k) > 4 && substr($k, 0, 2) === '__' && substr($k, -2) === '__') continue;
        if (mb_strpos($k, '订单状态') !== false) {
            $v = trim((string)$v);
            if ($v !== '') return $v;
        }
    }
    return '';
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
ensureOrderNoColumn();

// 确保有 is_deleted 字段（回收站）
$delCols = db()->query("SHOW COLUMNS FROM `orders` LIKE 'is_deleted'")->fetchAll();
if (empty($delCols)) {
    db()->exec("ALTER TABLE `orders` ADD COLUMN `is_deleted` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0=正常 1=已删除(回收站)' AFTER `order_scope`");
    db()->exec("ALTER TABLE `orders` ADD INDEX `idx_deleted` (`is_deleted`)");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 单条删除订单（仅允许删除归属本店铺的订单）
    if ($action === 'delete_order') {
        $order_id = (int)($_POST['order_id'] ?? 0);
        try {
            $stmt = db()->prepare("UPDATE orders SET is_deleted=1 WHERE id = ? AND shop = ?");
            $stmt->execute([$order_id, $shop['name']]);
            $success = '订单已删除（移入回收站）';
        } catch (PDOException $ex) {
            $error = '删除失败: ' . $ex->getMessage();
        }
    } elseif ($action === 'batch_delete') {
        // 批量删除订单
        $ids = $_POST['ids'] ?? [];
        $ids = array_filter(array_map('intval', (array)$ids));
        if (empty($ids)) {
            $error = '请勾选要删除的订单';
        } else {
            try {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $params = array_merge($ids, [$shop['name']]);
                $stmt = db()->prepare("UPDATE orders SET is_deleted=1 WHERE id IN ($placeholders) AND shop = ?");
                $stmt->execute($params);
                $success = '已批量删除 ' . $stmt->rowCount() . ' 条订单（移入回收站）';
            } catch (PDOException $ex) {
                $error = '批量删除失败: ' . $ex->getMessage();
            }
        }
    } elseif ($action === 'delete_month') {
        $del_month = trim($_POST['del_month'] ?? '');
        if ($del_month === '' || !preg_match('/^\d{4}-\d{2}$/', $del_month)) {
            $error = '无效的月份';
        } else {
            try {
                $stmt = db()->prepare("UPDATE orders SET is_deleted=1 WHERE shop = ? AND DATE_FORMAT(order_date, '%Y-%m') = ? AND COALESCE(is_deleted, 0) = 0");
                $stmt->execute([$shop['name'], $del_month]);
                $cnt = $stmt->rowCount();
                $success = "已删除 {$del_month} 的 {$cnt} 条订单（移入回收站）";
            } catch (PDOException $ex) {
                $error = '删除失败: ' . $ex->getMessage();
            }
        }
    } elseif ($action === 'manual_add') {
        $order_amount = (float)($_POST['order_amount'] ?? 0);
        $order_date   = $_POST['order_date'] ?? '';

        if ($order_amount <= 0 || $order_date === '') {
            $error = '请填写完整且有效的订单信息';
        } else {
            try {
                $stmt = db()->prepare("INSERT INTO orders (employee_id, order_amount, order_date, shop, order_no, order_scope) VALUES (?, ?, ?, ?, ?, 'department')");
                $stmt->execute([0, $order_amount, $order_date, $shop['name'], '']);
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
                    // 自动跳过标题行：如果第一行不包含已知列名，取下一行做表头
                    $headerIdx = 0;
                    $knownCols = ['金额','价格','售价','成本','检索号','交易时间','时间','订单号','订单编号','订单金额','交易金额'];
                    for ($ri = 0; $ri < min(5, count($rows)); $ri++) {
                        $rowStr = implode('', $rows[$ri]);
                        foreach ($knownCols as $kc) {
                            if (mb_strpos($rowStr, $kc) !== false) { $headerIdx = $ri; break 2; }
                        }
                    }
                    $firstRow = $rows[$headerIdx];
                    $dataRows = array_slice($rows, $headerIdx + 1);

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
                    $idxOrderNo = null; $idxTradeTime = null; $idxOrderStatus = null;
                    foreach ($colMap as $k => $idx) {
                        if ($idxAmount === null && (mb_strpos($k, '订单金额') !== false || mb_strpos($k, '交易金额') !== false || mb_strpos($k, '金额') !== false)) $idxAmount = $idx;
                        if ($idxPrice === null && (mb_strpos($k, '价格') !== false || mb_strpos($k, '售价') !== false)) $idxPrice = $idx;
                        if ($idxCost  === null && (mb_strpos($k, '成本') !== false)) $idxCost  = $idx;
                        if ($idxOrderNo === null && (mb_strpos($k, '检索号') !== false || mb_strpos($k, '订单编号') !== false)) $idxOrderNo = $idx;
                        if ($idxTradeTime === null && (mb_strpos($k, '交易时间') !== false || mb_strpos($k, '时间') !== false)) $idxTradeTime = $idx;
                        if ($idxOrderStatus === null && (mb_strpos($k, '订单状态') !== false)) $idxOrderStatus = $idx;
                    }

                    // 校验：要么有订单金额列，要么有价格和成本列
                    if ($idxAmount === null && ($idxPrice === null || $idxCost === null)) {
                        $error = '表头缺少金额字段：需要"订单金额"列，或同时有"价格/售价"和"成本/总成本"列';
                    } else {

                    // 保存表头到 upload_batches
                    $batchHeaders = json_encode(array_values($normalizedHeaders), JSON_UNESCAPED_UNICODE);
                    $batchStmt = db()->prepare("INSERT INTO upload_batches (employee_id, headers) VALUES (?, ?)");
                    $batchStmt->execute([0, $batchHeaders]);

                    $inserted = 0; $skipped = 0;
                    $stmt = db()->prepare("INSERT INTO orders (employee_id, order_amount, order_date, shop, order_no, raw_data, is_abnormal, abnormal_reason, order_scope) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'department')");

                    db()->beginTransaction();
                    try {
                    foreach ($dataRows as $row) {
                        if (count(array_filter($row, fn($v) => trim($v) !== '')) === 0) continue;

                        // 计算订单金额
                        $originalPrice = 0; // 原始售价（供异常订单对比使用）
                        if ($idxAmount !== null) {
                            $amount = (float)preg_replace('/[^\d.\-]/', '', trim($row[$idxAmount] ?? ''));
                            $originalPrice = $amount;
                        } else {
                            $price  = (float)preg_replace('/[^\d.\-]/', '', trim($row[$idxPrice] ?? ''));
                            $cost   = (float)preg_replace('/[^\d.\-]/', '', trim($row[$idxCost] ?? ''));
                            $originalPrice = $price;
                            $amount = $price - $cost;
                        }

                        // 日期：归属月份首日（用于分组），实际交易时间存入raw_data供展示
                        $parsedDate = $upload_month . '-01';
                        $tradeTimeStr = '';
                        if ($idxTradeTime !== null) {
                            $tradeTime = trim((string)($row[$idxTradeTime] ?? ''));
                            if ($tradeTime !== '') {
                                $dt = '';
                                if (is_numeric($tradeTime) && $tradeTime > 30000 && $tradeTime < 60000) {
                                    $ts = ((int)floor((float)$tradeTime) - 25569) * 86400;
                                    $frac = (float)$tradeTime - floor((float)$tradeTime);
                                    $ts += (int)round($frac * 86400);
                                    // Excel序列号是时区无关的，用gmdate避免服务器时区重复偏移
                                    $dt = gmdate('Y-m-d', $ts);
                                    $tradeTimeStr = gmdate('Y-m-d H:i:s', $ts);
                                } elseif (preg_match('/(\d{4}-\d{2}-\d{2})/', $tradeTime, $m)) {
                                    $dt = $m[1]; $tradeTimeStr = $tradeTime;
                                } elseif (preg_match('/(\d{4})\/(\d{1,2})\/(\d{1,2})/', $tradeTime, $m)) {
                                    $dt = sprintf('%s-%02d-%02d', $m[1], $m[2], $m[3]); $tradeTimeStr = $tradeTime;
                                } elseif (preg_match('/(\d{4})(\d{2})(\d{2})\s/', $tradeTime, $m)) {
                                    $dt = "{$m[1]}-{$m[2]}-{$m[3]}"; $tradeTimeStr = $tradeTime;
                                }
                            }
                        }

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
                        // 始终存储原始售价，供异常订单对比使用（售价匹配，非利润匹配）
                        if ($originalPrice > 0) {
                            $rawMap['__original_price__'] = $originalPrice;
                        }
                        if ($tradeTimeStr !== '') { $rawMap['__trade_time__'] = $tradeTimeStr; }
                        if ($isRefund) {
                            $rawMap['__is_refund__'] = '1';
                        }
                        // 提取订单状态（如"交易成功""卖家已发货""等待买家确认"等）
                        $orderStatus = '';
                        if ($idxOrderStatus !== null) {
                            $orderStatus = trim((string)($row[$idxOrderStatus] ?? ''));
                        }
                        // 科恒扫码收款、对公收款：有订单状态按订单状态，没有则默认交易成功
                        if (in_array($shop['name'], ['科恒扫码收款', '对公收款'])) {
                            if ($orderStatus === '') { $orderStatus = '交易成功'; }
                        }
                        if ($orderStatus !== '') { $rawMap['__order_status__'] = $orderStatus; }
                        // 提取订单号：优先使用检索号列，否则用通用提取
                        $orderNo = '';
                        if ($idxOrderNo !== null) {
                            $orderNo = trim($row[$idxOrderNo] ?? '');
                        }
                        if ($orderNo === '') {
                            $orderNo = extract_order_no($rawMap);
                        }

                        $stmt->execute([0, $amount, $parsedDate, $shop['name'], $orderNo, json_encode($rawMap, JSON_UNESCAPED_UNICODE), $isAbn, $abnReason]);
                        $isAbn ? $skipped++ : $inserted++;
                    }
                    db()->commit();
                    } catch (Exception $txEx) {
                        db()->rollBack();
                        throw $txEx;
                    }

                    if ($error === '') {
                        $rq = ['shop_id' => $shop_id, 'upload_ok' => '1', 'msg' => urlencode("导入完成！为【{$shop['name']}】成功导入 {$inserted} 条" . ($skipped > 0 ? "，{$skipped} 条标记为异常" : ""))];
                        header('Location: ' . BASE_URL . '/shops/upload.php?' . http_build_query($rq));
                        exit;
                    }

                    }
                }
            } catch (Exception $ex) {
                $error = '解析失败: ' . $ex->getMessage();
            }
        }
    }
}

// 查询该店铺已有订单（按月份分组汇总）
$filter_month = $_GET['month'] ?? '';
$search_no = trim($_GET['search_no'] ?? '');
$baseWhere  = " WHERE o.shop = ? AND COALESCE(o.is_deleted, 0) = 0";
$baseParams = [$shop['name']];
if ($filter_month) { $baseWhere .= " AND DATE_FORMAT(o.order_date, '%Y-%m') = ?"; $baseParams[] = $filter_month; }
if ($search_no !== '') { $baseWhere .= " AND o.order_no LIKE ?"; $baseParams[] = '%' . $search_no . '%'; }

$groupSql = "SELECT DATE_FORMAT(o.order_date, '%Y-%m') as order_month,
                    COUNT(*) as cnt,
                    COALESCE(SUM(CASE WHEN o.is_abnormal=0 THEN o.order_amount ELSE 0 END),0) as normal_amount,
                    SUM(CASE WHEN o.is_abnormal=1 THEN 1 ELSE 0 END) as abn_cnt
             FROM orders o" . $baseWhere .
             " GROUP BY order_month ORDER BY order_month DESC";
$gStmt = db()->prepare($groupSql);
$gStmt->execute($baseParams);
$monthGroups = $gStmt->fetchAll();
$gStmt->closeCursor();

$total_count  = array_sum(array_column($monthGroups, 'cnt'));
$total_amount = array_sum(array_column($monthGroups, 'normal_amount'));

// 详细订单列表（点击某个月份展开 或 全局搜索）
$detail_month = $_GET['detail'] ?? '';
$detail_search = $search_no;
$detail_orders = [];
$detail_total = 0;
$page = max(1, (int)($_GET['page'] ?? 1));
$page_size = (int)($_GET['page_size'] ?? 20);
if (!in_array($page_size, [10, 20, 50, 100, 500, 1000, 2000, 0])) $page_size = 20;

if ($detail_month !== '' || $detail_search !== '') {
    // 该月份的订单总数（或全局搜索）
    $searchWhere = " WHERE shop = ? AND COALESCE(is_deleted, 0) = 0";
    $searchParams = [$shop['name']];
    if ($detail_month !== '') { $searchWhere .= " AND DATE_FORMAT(order_date, '%Y-%m') = ?"; $searchParams[] = $detail_month; }
    if ($detail_search !== '') { $searchWhere .= " AND order_no LIKE ?"; $searchParams[] = '%' . $detail_search . '%'; }
    $cStmt = db()->prepare("SELECT COUNT(*) FROM orders" . $searchWhere);
    $cStmt->execute($searchParams);
    $detail_total = (int)$cStmt->fetchColumn();
    $cStmt->closeCursor();

    $offset = ($page - 1) * $page_size;
    $limitSql = $page_size > 0 ? " LIMIT $page_size OFFSET $offset" : "";
    $dStmt = db()->prepare("SELECT id, order_amount, order_date, order_no, is_abnormal, abnormal_reason, raw_data
                            FROM orders"
                            . $searchWhere
                            . " ORDER BY order_date DESC, id DESC"
                            . $limitSql);
    $dStmt->execute($searchParams);
    $detail_orders = $dStmt->fetchAll();
}
$detail_pages = $page_size > 0 ? (int)ceil($detail_total / $page_size) : 1;

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
                        <input type="month" name="upload_month" class="form-control" value="<?php echo e($_POST['upload_month'] ?? date('Y-m', strtotime('-1 month'))); ?>" min="2020-01" max="2030-12" required>
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
                    <?php if ($search_no !== ''): ?>
                    <!-- 全局搜索结果 -->
                    <div class="p-2 bg-light border-bottom">
                        <small class="text-muted"><i class="fas fa-search"></i> 搜索 "<b><?php echo e($search_no); ?></b>" 的结果：共 <?php echo $detail_total; ?> 条</small>
                    </div>
                    <?php if (empty($detail_orders)): ?>
                        <div class="text-center text-muted py-5"><i class="fas fa-search fa-2x mb-2 d-block"></i>未找到匹配的订单</div>
                    <?php else: ?>
                    <div class="p-2">
                        <form method="post" id="detailForm">
                            <input type="hidden" name="action" value="batch_delete">
                            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap">
                                <div class="d-flex align-items-center">
                                    <button type="submit" class="btn btn-sm btn-outline-danger mr-2" onclick="return confirm('确定删除选中的订单？')">
                                        <i class="fas fa-trash-alt"></i> 批量删除
                                    </button>
                                    <span class="text-muted small">已选 <b id="selCount">0</b> 条</span>
                                </div>
                                <div class="d-flex align-items-center">
                                    <label class="text-muted small mb-0 mr-1">每页</label>
                                    <select class="form-control form-control-sm mr-1" style="width:auto" onchange="changePageSize(this.value)">
                                        <?php foreach ([10, 20, 50, 100, 500, 1000, 2000, 0] as $ps): ?>
                                            <option value="<?php echo $ps; ?>" <?php if($page_size==$ps) echo 'selected'; ?>><?php echo $ps === 0 ? '全部' : $ps; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="text-muted small">条</span>
                                </div>
                            </div>
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
                                            </td>
                                            <td><small><?php echo e(substr($raw['__trade_time__'] ?? $o['order_date'], 0, 10)); ?></small></td>
                                            <td><small><?php echo e($orderStatus ?: '--'); ?></small></td>
                                            <td>
                                                <?php if ($isAbn): ?>
                                                    <span class="badge badge-warning" title="<?php echo e($o['abnormal_reason']); ?>">异常</span>
                                                <?php elseif ($isRefund): ?>
                                                    <span class="badge badge-info">退款</span>
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
                        </form>
                        <?php if ($detail_pages > 1): ?>
                        <nav class="d-flex justify-content-between align-items-center">
                            <ul class="pagination pagination-sm mb-0">
                                <?php
                                $baseLink = BASE_URL . '/shops/upload.php?shop_id=' . $shop_id
                                    . '&search_no=' . urlencode($search_no)
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
                    </div>
                    <?php endif; ?>
                <?php elseif (empty($monthGroups)): ?>
                    <div class="text-center text-muted py-5"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>暂无订单数据</div>
                <?php else: ?>
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
                                                        <input type="text" name="search_no" value="<?php echo e($detail_search); ?>" class="form-control" placeholder="搜索订单号…" style="width:160px" autocomplete="off">
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
                                                            </td>
                                                            <td><small><?php echo e(substr($raw['__trade_time__'] ?? $o['order_date'], 0, 10)); ?></small></td>
                                                            <td><small><?php echo e($orderStatus ?: '--'); ?></small></td>
                                                            <td>
                                                                <?php if ($isAbn): ?>
                                                                    <span class="badge badge-warning" title="<?php echo e($o['abnormal_reason']); ?>">异常</span>
                                                                <?php elseif ($isRefund): ?>
                                                                    <span class="badge badge-info">退款</span>
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

// ===== 订单明细交互 =====
// 全选/反选
function toggleAll(el){
    document.querySelectorAll('.row-chk').forEach(function(c){ c.checked = el.checked; });
    updateSelCount();
}
function updateSelCount(){
    var n = document.querySelectorAll('.row-chk:checked').length;
    var box = document.getElementById('selCount');
    if (box) box.textContent = n;
}
// 切换每页条数
function changePageSize(ps){
    var url = new URL(window.location.href);
    url.searchParams.set('page_size', ps);
    url.searchParams.set('page', '1');
    window.location = url;
}
// 单条删除
function deleteOrder(id, date){
    if (!confirm('确定删除订单 #' + id + '（' + date + '）？')) return;
    var f = document.createElement('form');
    f.method = 'post';
    f.innerHTML = '<input type="hidden" name="action" value="delete_order"><input type="hidden" name="order_id" value="' + id + '">';
    document.body.appendChild(f);
    f.submit();
}
// 一键删除整月订单
function deleteMonth(month, count){
    if (!confirm('确定删除 ' + month + ' 的全部 ' + count + ' 条订单？\n\n订单将移入回收站，可恢复。')) return;
    var f = document.createElement('form');
    f.method = 'post';
    f.innerHTML = '<input type="hidden" name="action" value="delete_month"><input type="hidden" name="del_month" value="' + month + '">';
    document.body.appendChild(f);
    f.submit();
}
// 查看订单详情
function showDetail(d){
    var raw = d.raw || {};
    var html = '<div class="mb-2"><b>订单ID：</b>' + d.id + '</div>'
             + '<div class="mb-2"><b>订单号：</b>' + (d.order_no ? '<span class="text-monospace">' + d.order_no + '</span>' : '<span class="text-muted">无</span>') + '</div>'
             + '<div class="mb-2"><b>金额：</b>¥' + d.amount + '</div>'
             + '<div class="mb-2"><b>日期：</b>' + d.date + '</div>'
             + (d.status ? '<div class="mb-2"><b>订单状态：</b>' + d.status + '</div>' : '')
             + (d.reason ? '<div class="mb-2"><b>异常原因：</b><span class="text-warning">' + d.reason + '</span></div>' : '');
    if (Object.keys(raw).length) {
        html += '<hr><h6>原始数据</h6><table class="table table-sm table-bordered"><tbody>';
        Object.keys(raw).forEach(function(k){
            html += '<tr><th style="width:40%">' + k + '</th><td>' + raw[k] + '</td></tr>';
        });
        html += '</tbody></table>';
    } else {
        html += '<hr><p class="text-muted">无原始数据</p>';
    }
    document.getElementById('detailBody').innerHTML = html;
    $('#detailModal').modal('show');
}
</script>

<!-- 订单详情弹窗 -->
<div class="modal fade" id="detailModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-eye text-info"></i> 订单详情</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body" id="detailBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">关闭</button>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
