<?php

require_once (dirname(__DIR__, 1)) . '/../includes/auth.php';
require_login();
require_once (dirname(__DIR__, 1)) . '/../includes/SalaryCalculator.php';
require_once (dirname(__DIR__, 1)) . '/../includes/ProjectOrderSource.php';
require_once (dirname(__DIR__, 1)) . '/../classes/SimpleXLSX.php';

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
/* split: shops/upload/helpers/extract_order_status.php */ require_once (dirname(__DIR__, 1)) . '/upload/helpers/extract_order_status.php';

/**
 * 确保 orders 表有 shop 字段
 */
/* split: shops/upload/helpers/ensureShopColumn.php */ require_once (dirname(__DIR__, 1)) . '/upload/helpers/ensureShopColumn.php';
ensureShopColumn();
ensureOrderNoColumn();

// 确保有 is_deleted 字段（回收站）
$delCols = db()->query("SHOW COLUMNS FROM `orders` LIKE 'is_deleted'")->fetchAll();
if (empty($delCols)) {
    db()->exec("ALTER TABLE `orders` ADD COLUMN `is_deleted` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0=正常 1=已删除(回收站)' AFTER `order_scope`");
    db()->exec("ALTER TABLE `orders` ADD INDEX `idx_deleted` (`is_deleted`)");
}

/* split: shops/upload/actions/dispatch.php */ include (dirname(__DIR__, 1)) . '/upload/actions/dispatch.php';

// 查询该店铺已有订单（按月份分组汇总）
// 默认列出全部归属月份（按月分组可展开）；只有明确选了月份才按月筛选，避免上传到别的月份后在列表里“看不见”
$filter_month = trim((string)($_GET['month'] ?? ''));
if ($filter_month !== '' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $filter_month)) $filter_month = '';
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

define('BASE_PATH', dirname((dirname(__DIR__, 1))));
