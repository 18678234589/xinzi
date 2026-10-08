<?php

require_once (dirname(__DIR__, 1)) . '/../includes/auth.php';
require_once (dirname(__DIR__, 1)) . '/../includes/SalaryCalculator.php';
require_login();
require_once (dirname(__DIR__, 1)) . '/../classes/SimpleXLSX.php';

$page_title = '平台订单导入';
$success = '';
$error = '';

// 是否通过合作人员管理跳转过来，锁定某合作人员
$locked_employee_id = (int)($_GET['employee_id'] ?? 0);
$locked_employee = null;
if ($locked_employee_id > 0) {
    $locked_employee = get_employee($locked_employee_id);
    if (!$locked_employee) {
        $locked_employee_id = 0;
    }
}

// AJAX接口：获取合作人员的项目分成模块列表
$ajax_employee_id = (int)($_GET['employee_id'] ?? 0);
if (($_GET['ajax'] ?? '') === 'modules' && $ajax_employee_id > 0) {
    header('Content-Type: application/json; charset=utf-8');
    $modCfg = SalaryCalculator::readModulesConfig($ajax_employee_id);
    $result = [];
    if ($modCfg && !empty($modCfg['modules'])) {
        foreach ($modCfg['modules'] as $m) {
            if (in_array($m['type'], ['standard','tiered','per_order','profit_commission','trademark_commission','trademark_cashback','referral_order','customer_reward','miniprogram_commission'
    ,'fixed_subsidy']) && ($m['enabled'] ?? true)) {
                $extra = '';
                if ($m['type'] === 'standard' && isset($m['config']['rate']) && $m['config']['rate'] !== '') {
                    $rVal = (float)$m['config']['rate'];
                    $extra = ' (' . rtrim(rtrim(number_format($rVal * 100, 4, '.', ''), '0'), '.') . '%)';
                } elseif ($m['type'] === 'profit_commission' && isset($m['config']['commission_rate']) && $m['config']['commission_rate'] !== '') {
                    $cVal = (float)$m['config']['commission_rate'];
                    $extra = ' (成本项目分成' . rtrim(rtrim(number_format($cVal * 100, 4, '.', ''), '0'), '.') . '%)';
                } elseif ($m['type'] === 'trademark_commission' && isset($m['config']['commission_rate']) && $m['config']['commission_rate'] !== '') {
                    $cVal = (float)$m['config']['commission_rate'];
                    $extra = ' (商标部项目分成' . rtrim(rtrim(number_format($cVal * 100, 4, '.', ''), '0'), '.') . '%)';
                } elseif ($m['type'] === 'trademark_cashback' && isset($m['config']['per_amount'])) {
                    $extra = ' (小额返现¥' . ($m['config']['per_amount'] ?? 0) . '/单)';
                } elseif ($m['type'] === 'miniprogram_commission' && isset($m['config']['commission_rate']) && $m['config']['commission_rate'] !== '') {
                    $cVal = (float)$m['config']['commission_rate'];
                    $extra = ' (小程序项目分成' . rtrim(rtrim(number_format($cVal * 100, 4, '.', ''), '0'), '.') . '%)';
                } elseif ($m['type'] === 'tiered') {
                    $extra = ' (阶梯)';
                } elseif ($m['type'] === 'per_order') {
                    $extra = ' (¥' . ($m['config']['per_amount'] ?? 0) . '/笔)';
                } elseif ($m['type'] === 'referral_order') {
                    $extra = ' (每单补助¥' . ($m['config']['subsidy'] ?? 0) . ')';
                } elseif ($m['type'] === 'fixed_subsidy') {
                    $extra = ' (固定¥' . ($m['config']['amount'] ?? 0) . '/月)';
                } elseif ($m['type'] === 'customer_reward') {
                    $extra = ' (新客奖¥' . ($m['config']['new_customer_reward'] ?? 0) . '/老客¥' . ($m['config']['old_customer_reward'] ?? 0) . ')';
                }
                $result[] = ['name' => $m['name'], 'label' => $m['name'] . $extra];
            }
        }
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 对一组订单执行核验：按订单号匹配店铺订单的状态与金额，不符合的标记为异常；
 * 通过核验的订单清除「未核验」标记并写入 __verified_month__（核验当月），供结算按核验月归月。
 * 由 verify_status（按模块整批核验）与 verify_pending（待核验清单批量核验）复用，逻辑保持一致。
 * @param array $rows 每行需含 id/order_no/order_amount/raw_data/is_abnormal/abnormal_reason
 * @param string $verifyType  shipped=已发货判定 | success=交易成功判定
 * @param string $creditMonth 核验通过后计入的项目报酬月份（核验当月，形如 YYYY-MM）
 * @return array ['updated'=>, 'normal'=>, 'abnormal'=>, 'total'=>]
 */
/* split: orders/index/helpers/applyOrderVerification.php */ require_once (dirname(__DIR__, 1)) . '/index/helpers/applyOrderVerification.php';

/* split: orders/index/actions/dispatch.php */ include (dirname(__DIR__, 1)) . '/index/actions/dispatch.php';

// 订单列表查询：锁定合作人员时只显示该合作人员订单

/**
 * 确保 orders 表有 project 字段（自动升级，兼容旧表结构）
 */
/**
 * 将日期字符串标准化为 Y-m-d，自动处理 Excel 序列号
 * 返回 [date_string_or_false, error_reason]
 */
/* split: orders/index/helpers/parseOrderDate.php */ require_once (dirname(__DIR__, 1)) . '/index/helpers/parseOrderDate.php';

/* split: orders/index/helpers/ensureProjectColumn.php */ require_once (dirname(__DIR__, 1)) . '/index/helpers/ensureProjectColumn.php';
$filter_employee = $locked_employee_id ?: (int)($_GET['employee_id'] ?? 0);
$filter_dept = $_GET['department'] ?? '';
$filter_month = $_GET['month'] ?? date('Y-m', strtotime('-1 month'));  // 默认上个月
$filter_project = $_GET['project'] ?? ''; // 展开某个模块时使用
$filter_dept_orders = isset($_GET['dept_orders']) && $_GET['dept_orders'] === '1';
$filter_abnormal = (($_GET['abnormal'] ?? '') === '1') ? 1 : 0;
$filter_refund   = (($_GET['refund'] ?? '') === '1') ? 1 : 0;
$filter_status   = trim($_GET['status'] ?? ''); // 订单状态筛选（如"未核验"）
$filter_search  = trim($_GET['search_no'] ?? ''); // 订单号搜索
$page     = max(1, (int)($_GET['page'] ?? 1));
$allowed_per_page = [20, 50, 100, 200, 500, 1000];
$_pp = (int)($_GET['per_page'] ?? 20);
$per_page = in_array($_pp, $allowed_per_page) ? $_pp : 20;
$baseQ = [];
if ($locked_employee) $baseQ['employee_id'] = $locked_employee['id'];
elseif ($filter_employee) $baseQ['employee_id'] = $filter_employee;
if ($filter_dept) $baseQ['department'] = $filter_dept;
if ($filter_month) $baseQ['month'] = $filter_month;
if ($filter_dept_orders) $baseQ['dept_orders'] = '1';

ensureProjectColumn(); // 确保 upload_batches 等表/字段存在
ensureOrderNoColumn(); // 确保 orders.order_no 字段存在

// 基础 WHERE（不含 project 筛选，用于分组汇总）
// 排除店铺上传的订单（order_scope='department' 且 shop 非空），它们只在店铺管理页展示
// 排除已软删除的订单（回收站）
$baseWhere  = " WHERE NOT (o.order_scope = 'department' AND o.shop <> '') AND COALESCE(o.is_deleted, 0) = 0";
$baseParams = [];
if ($filter_employee) {
    // 个人订单：只显示该合作人员自己的个人拆分行，不显示部门汇总行
    // 部门订单需通过"部门订单"视图单独查看
    $baseWhere .= " AND o.employee_id = ? AND COALESCE(o.order_scope, 'personal') = 'personal'";
    $baseParams[] = $filter_employee;
}
if ($filter_dept) {
    // 按部门过滤：合作人员部门匹配 + 部门汇总行（employee_id=0，通过 __dept__ 匹配）
    $baseWhere .= " AND (e.department = ? OR (o.employee_id = 0 AND o.order_scope = 'department' AND JSON_UNQUOTE(JSON_EXTRACT(o.raw_data, '$.__dept__')) = ?))";
    $baseParams[] = $filter_dept;
    $baseParams[] = $filter_dept;
}
if ($filter_month)    { $baseWhere .= " AND DATE_FORMAT(o.order_date, '%Y-%m') = ?"; $baseParams[] = $filter_month; }
// 部门订单视图：只看 employee_id=0 的部门汇总订单（用 __dept__ 匹配，绕过 e.department 过滤）
if ($filter_dept_orders) {
    // 重建 WHERE：去掉 e.department 过滤（部门订单 JOIN 不到合作人员），改用 __dept__
    $baseWhere = " WHERE NOT (o.order_scope = 'department' AND o.shop <> '')"
        . " AND COALESCE(o.is_deleted, 0) = 0"
        . " AND o.employee_id = 0 AND o.order_scope = 'department'"
        . " AND JSON_UNQUOTE(JSON_EXTRACT(o.raw_data, '$.__dept__')) = ?";
    $baseParams = [$filter_dept];
    if ($filter_month) {
        $baseWhere .= " AND DATE_FORMAT(o.order_date, '%Y-%m') = ?";
        $baseParams[] = $filter_month;
    }
}

// 按年份-月份-project三级分组汇总
$groupSql = "SELECT DATE_FORMAT(o.order_date, '%Y') as order_year,
                    DATE_FORMAT(o.order_date, '%Y-%m') as order_month,
                    COALESCE(NULLIF(o.project,''),'订单') as grp_name,
                    COUNT(*) as cnt,
                    COALESCE(SUM(CASE WHEN o.is_abnormal=0 THEN o.order_amount ELSE 0 END),0) as normal_amount,
                    SUM(CASE WHEN o.is_abnormal=1 THEN 1 ELSE 0 END) as abn_cnt,
                    SUM(CASE WHEN o.order_scope='department' THEN 1 ELSE 0 END) as dept_cnt,
                    SUM(CASE WHEN o.order_scope='personal' OR o.order_scope IS NULL OR o.order_scope='' THEN 1 ELSE 0 END) as personal_cnt
             FROM orders o LEFT JOIN employees e ON o.employee_id = e.id" . $baseWhere .
             " GROUP BY order_year, order_month, grp_name ORDER BY order_year DESC, order_month DESC, normal_amount DESC";
$gStmt = db()->prepare($groupSql);
$gStmt->execute($baseParams);
$allGroups = $gStmt->fetchAll();
$gStmt->closeCursor();

// 按年份和月份重新组织数据结构
$projectGroups = [];
$yearGroups = [];
foreach ($allGroups as $row) {
    $year = $row['order_year'];
    $month = $row['order_month'];

    if (!isset($yearGroups[$year])) {
        $yearGroups[$year] = [
            'year' => $year,
            'total_cnt' => 0,
            'total_amount' => 0,
            'months' => []
        ];
    }

    if (!isset($yearGroups[$year]['months'][$month])) {
        $yearGroups[$year]['months'][$month] = [
            'month' => $month,
            'total_cnt' => 0,
            'total_amount' => 0,
            'projects' => []
        ];
    }

    $yearGroups[$year]['months'][$month]['projects'][] = $row;
    $yearGroups[$year]['months'][$month]['total_cnt'] += $row['cnt'];
    $yearGroups[$year]['months'][$month]['total_amount'] += $row['normal_amount'];
    $yearGroups[$year]['total_cnt'] += $row['cnt'];
    $yearGroups[$year]['total_amount'] += $row['normal_amount'];

    // 兼容原有的平铺结构（用于总计等）
    $projectGroups[] = $row;
}

// 按部门分组统计（部门订单通过 raw_data.__dept__ 命名部门，个人订单回退到合作人员部门）
$deptSql = "SELECT COALESCE(
                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(o.raw_data, '$.__dept__')), ''),
                e.department,
                '未分配'
              ) as dept_name,
                   COUNT(*) as cnt,
                   COALESCE(SUM(CASE WHEN o.is_abnormal=0 THEN o.order_amount ELSE 0 END),0) as normal_amount
            FROM orders o LEFT JOIN employees e ON o.employee_id = e.id" . $baseWhere .
            " GROUP BY dept_name ORDER BY dept_name";
$deptStmt = db()->prepare($deptSql);
$deptStmt->execute($baseParams);
$deptGroups = $deptStmt->fetchAll();
$deptStmt->closeCursor();

// 如果选择了部门，按合作人员分组统计
$empGroups = [];
if ($filter_dept) {
    $empSql = "SELECT e.id as emp_id, e.name as emp_name,
                      COUNT(*) as cnt,
                      COALESCE(SUM(CASE WHEN o.is_abnormal=0 THEN o.order_amount ELSE 0 END),0) as normal_amount
               FROM orders o LEFT JOIN employees e ON o.employee_id = e.id" . $baseWhere .
               " GROUP BY e.id, e.name ORDER BY e.name";
    $empStmt = db()->prepare($empSql);
    $empStmt->execute($baseParams);
    $empGroups = $empStmt->fetchAll();
    $empStmt->closeCursor();

    // 追加"部门订单"虚拟行（employee_id=0 的部门汇总订单）
    // 注意：部门订单 employee_id=0，JOIN 不到合作人员，不能用 e.department 过滤，
    // 要用 raw_data.__dept__ 匹配部门名。重建 WHERE，不复用含 e.department 的 baseWhere。
    $deptSumParams = [];
    $deptSumWhere = " WHERE NOT (o.order_scope = 'department' AND o.shop <> '')"
        . " AND COALESCE(o.is_deleted, 0) = 0"
        . " AND o.employee_id = 0 AND o.order_scope = 'department'"
        . " AND JSON_UNQUOTE(JSON_EXTRACT(o.raw_data, '$.__dept__')) = ?";
    $deptSumParams[] = $filter_dept;
    if ($filter_month) {
        $deptSumWhere .= " AND DATE_FORMAT(o.order_date, '%Y-%m') = ?";
        $deptSumParams[] = $filter_month;
    }
    $deptSumSql = "SELECT COUNT(*) as cnt,
        COALESCE(SUM(CASE WHEN o.is_abnormal=0 THEN o.order_amount ELSE 0 END),0) as normal_amount
        FROM orders o" . $deptSumWhere;
    $deptSumStmt = db()->prepare($deptSumSql);
    $deptSumStmt->execute($deptSumParams);
    $deptSum = $deptSumStmt->fetch();
    $deptSumStmt->closeCursor();
    if ($deptSum && (int)$deptSum['cnt'] > 0) {
        array_unshift($empGroups, [
            'emp_id'        => 0,
            'emp_name'      => '部门订单',
            'cnt'           => $deptSum['cnt'],
            'normal_amount' => $deptSum['normal_amount'],
        ]);
    }
}

// 总计
$total_count  = array_sum(array_column($projectGroups, 'cnt'));
$total_amount = array_sum(array_column($projectGroups, 'normal_amount'));

// 展开某分组时，查询该分组的订单明细
$orders = [];
$total_pages = 1;
$uploadHeaders = [];
$expand_project = $filter_project; // 当前展开的分组名（空=全部收起）
$expand_month = '';
if ($expand_project !== '') {
    foreach ($yearGroups as $yd) {
        foreach ($yd['months'] as $md) {
            foreach ($md['projects'] as $p) {
                if (($p['grp_name'] ?? '') === $expand_project) {
                    $expand_month = $md['month'];
                    break 3;
                }
            }
        }
    }
}
$detailQ = [];

if ($expand_project !== '') {
    $detailWhere  = $baseWhere;
    $detailParams = $baseParams;
    $detailQ = array_merge($baseQ, ['project' => $expand_project, 'page' => 1]);
    if ($filter_abnormal) $detailQ['abnormal'] = '1';
    if ($filter_refund) $detailQ['refund'] = '1';
    if ($filter_status !== '') $detailQ['status'] = $filter_status;
    if ($filter_search !== '') $detailQ['search_no'] = $filter_search;
    if ($expand_project === '订单') {
        $detailWhere .= " AND (o.project = '' OR o.project IS NULL)";
    } else {
        $detailWhere .= " AND o.project = ?";
        $detailParams[] = $expand_project;
    }
    if ($filter_abnormal) {
        $detailWhere .= " AND o.is_abnormal = 1";
    }
    if ($filter_refund) {
        $detailWhere .= " AND JSON_UNQUOTE(JSON_EXTRACT(o.raw_data, '$.__is_refund__')) = '1'";
    }
    if ($filter_status !== '') {
        $detailWhere .= " AND JSON_UNQUOTE(JSON_EXTRACT(o.raw_data, '$.__order_status__')) = ?";
        $detailParams[] = $filter_status;
    }
    if ($filter_search !== '') {
        $detailWhere .= " AND o.order_no LIKE ?";
        $detailParams[] = '%' . $filter_search . '%';
    }

    $cntStmt = db()->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(o.order_amount),0) as total FROM orders o LEFT JOIN employees e ON o.employee_id = e.id" . $detailWhere);
    $cntStmt->execute($detailParams);
    $cntRow = $cntStmt->fetch();
    $cntStmt->closeCursor();
    $detail_count  = (int)$cntRow['cnt'];
    $detail_amount = (float)$cntRow['total'];
    $total_pages   = max(1, ceil($detail_count / $per_page));
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $per_page;

    $detailSql = "SELECT o.*, COALESCE(e.name,'') as name, COALESCE(e.department,'') as department FROM orders o LEFT JOIN employees e ON o.employee_id = e.id" . $detailWhere . " ORDER BY o.is_abnormal ASC, o.order_date DESC, o.id DESC LIMIT {$per_page} OFFSET {$offset}"
    ;
    $dStmt = db()->prepare($detailSql);
    $dStmt->execute($detailParams);
    $orders = $dStmt->fetchAll();
    $dStmt->closeCursor();

    // 直接从第一条有 raw_data 的订单推断表头，过滤掉"列N"占位列
    foreach ($orders as $o) {
        if (!empty($o['raw_data'])) {
            $rd = json_decode($o['raw_data'], true);
            if (is_array($rd)) {
                $uploadHeaders = array_values(array_filter(array_keys($rd), function($h) {
                    return strpos($h, '__') !== 0 && !preg_match('/^列\d+$/', $h);
                }));
                break;
            }
        }
    }
} else {
    $detail_count  = 0;
    $detail_amount = 0;
}

$departments = get_departments();
$employees   = get_employees();

define('BASE_PATH', dirname((dirname(__DIR__, 1))));
