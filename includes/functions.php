<?php
/**
 * 通用函数库
 */

/**
 * HTML转义输出
 */
function e($str)
{
    if (is_array($str)) {
        // 数组值（如 __dept_modules__）序列化为可读字符串
        return htmlspecialchars(json_encode($str, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
    }
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

/**
 * 从订单原始数据(raw_data)中提取订单号
 * 支持多种常见列名：订单号/订单编号/订单ID/单号/编号/order_no/orderNo/order_id 等
 * @param array $rawMap raw_data 解析后的关联数组
 * @return string 订单号（未找到返回空串）
 */
function extract_order_no($rawMap)
{
    if (!is_array($rawMap)) return '';
    // 候选列名（按优先级排序）
    $candidates = [
        '订单号', '订单编号', '订单ID', '订单id', '单号', '编号',
        '订单号码', '交易单号', '交易号', '流水号', '单据号', '单据编号',
        'order_no', 'orderNo', 'OrderNo', 'order_id', 'orderId', 'OrderId',
        'order number', 'Order Number', 'orderno', 'orderNo.',
    ];
    foreach ($candidates as $key) {
        if (isset($rawMap[$key]) && is_string($rawMap[$key]) && trim($rawMap[$key]) !== '') {
            return trim($rawMap[$key]);
        }
    }
    // 模糊匹配：含"订单号"/"单号"/"order"/"编号"/"流水"的列
    foreach ($rawMap as $k => $v) {
        if (strpos($k, '__') === 0) continue; // 跳过内部标记字段（含 __dept_modules__ 等数组值）
        if (!is_string($v)) continue;         // 跳过非字符串值（如 __dept_modules__ 数组）
        if (trim($v) !== '') {
            if (mb_strpos($k, '订单号') !== false || mb_strpos($k, '单号') !== false
                || mb_strpos($k, '流水') !== false || mb_strpos($k, '单据') !== false
                || mb_strpos($k, '编号') !== false
                || stripos($k, 'order_no') !== false || stripos($k, 'orderid') !== false
                || stripos($k, 'order no') !== false || stripos($k, 'orderno') !== false) {
                return trim($v);
            }
        }
    }
    return '';
}

/**
 * 确保 orders 表有 order_no 字段（用于订单号存储与店铺/员工订单对比）
 * 首次调用时会自动建列，并回填历史订单的 order_no（从 raw_data 提取）
 */
function ensureOrderNoColumn()
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $cols = db()->query("SHOW COLUMNS FROM `orders` LIKE 'order_no'")->fetchAll();
        if (empty($cols)) {
            db()->exec("ALTER TABLE `orders` ADD COLUMN `order_no` VARCHAR(64) DEFAULT '' COMMENT '订单号(从raw_data提取)' AFTER `shop`");
            db()->exec("ALTER TABLE `orders` ADD INDEX `idx_order_no` (`order_no`)");
        }
        // 回填历史订单：对 order_no 为空但有 raw_data 的记录，从 raw_data 提取订单号
        $rows = db()->query("SELECT id, raw_data FROM `orders` WHERE (order_no IS NULL OR order_no='') AND raw_data IS NOT NULL AND raw_data <> ''")->fetchAll();
        if (!empty($rows)) {
            $upd = db()->prepare("UPDATE `orders` SET order_no=? WHERE id=?");
            foreach ($rows as $r) {
                $raw = json_decode($r['raw_data'], true);
                if (!is_array($raw)) continue;
                $no = extract_order_no($raw);
                if ($no !== '') {
                    $upd->execute([$no, $r['id']]);
                }
            }
        }
    } catch (\Throwable $e) {}
}

/**
 * 格式化金额
 */
function money($amount)
{
    return number_format((float)$amount, 2, '.', ',');
}

/**
 * 考勤记录表辅助函数
 */
function ensureAttendanceTable()
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->query("SELECT 1 FROM `attendances` LIMIT 1");
    } catch (\Throwable $e) {
        db()->exec("CREATE TABLE IF NOT EXISTS `attendances` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `employee_id` INT NOT NULL COMMENT '员工ID',
            `year` SMALLINT NOT NULL COMMENT '年份',
            `month` TINYINT NOT NULL COMMENT '月份1-12',
            `work_hours` DECIMAL(6,1) NOT NULL DEFAULT 0 COMMENT '应出勤小时数',
            `absent_hours` DECIMAL(6,1) NOT NULL DEFAULT 0 COMMENT '请假小时数',
            `remark` VARCHAR(500) DEFAULT '' COMMENT '备注',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_emp_month` (`employee_id`, `year`, `month`),
            INDEX `idx_year_month` (`year`, `month`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT '考勤记录'");
    }
    // 考勤卡片隐藏记录表（用于"删除年份卡片"功能）
    try {
        db()->query("SELECT 1 FROM `attendance_hidden_years` LIMIT 1");
    } catch (\Throwable $e) {
        db()->exec("CREATE TABLE IF NOT EXISTS `attendance_hidden_years` (
            `year` SMALLINT PRIMARY KEY,
            `hidden_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT '考勤卡片隐藏年份'");
    }
    // 考勤卡片自定义添加年份表（用于"添加年份卡片"功能）
    try {
        db()->query("SELECT 1 FROM `attendance_custom_years` LIMIT 1");
    } catch (\Throwable $e) {
        db()->exec("CREATE TABLE IF NOT EXISTS `attendance_custom_years` (
            `year` SMALLINT PRIMARY KEY,
            `added_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT '考勤卡片自定义年份'");
    }
    // 考勤待匹配表：上传考勤时员工尚未添加的行暂存于此，员工添加后自动补录
    try {
        db()->query("SELECT 1 FROM `attendance_pending` LIMIT 1");
    } catch (\Throwable $e) {
        db()->exec("CREATE TABLE IF NOT EXISTS `attendance_pending` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `employee_name` VARCHAR(100) NOT NULL COMMENT '考勤表中的姓名',
            `year` SMALLINT NOT NULL,
            `month` TINYINT NOT NULL,
            `work_hours` DECIMAL(6,1) NOT NULL DEFAULT 0,
            `absent_hours` DECIMAL(6,1) NOT NULL DEFAULT 0,
            `remark` VARCHAR(500) DEFAULT '',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_name_ym` (`employee_name`, `year`, `month`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT '考勤待匹配记录（员工添加后自动补录）'");
    }
}

/**
 * 获取被隐藏（删除卡片）的年份列表
 */
function get_attendance_hidden_years()
{
    try {
        return db()->query("SELECT year FROM attendance_hidden_years")->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * 隐藏某年份的考勤卡片
 */
function hide_attendance_year($year)
{
    db()->prepare("INSERT IGNORE INTO attendance_hidden_years (year) VALUES (?)")->execute([$year]);
}

/**
 * 获取用户手动添加的年份列表
 */
function get_attendance_custom_years()
{
    try {
        return db()->query("SELECT year FROM attendance_custom_years ORDER BY year DESC")->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * 添加自定义年份卡片
 */
function add_attendance_custom_year($year)
{
    db()->prepare("INSERT IGNORE INTO attendance_custom_years (year) VALUES (?)")->execute([$year]);
    // 添加时若该年份曾被隐藏，则取消隐藏
    db()->prepare("DELETE FROM attendance_hidden_years WHERE year=?")->execute([$year]);
}

/**
 * 获取某员工某月考勤
 */
function get_attendance($employeeId, $year, $month)
{
    $stmt = db()->prepare("SELECT * FROM attendances WHERE employee_id=? AND year=? AND month=?");
    $stmt->execute([$employeeId, $year, $month]);
    return $stmt->fetch();
}

/**
 * 将待匹配考勤记录中姓名匹配的行补录到 attendances 表
 * 在添加/更新员工时调用，自动补回之前因员工不存在而跳过的考勤数据
 * @param int $employeeId  员工ID
 * @param string $employeeName  员工姓名
 * @return int 补录条数
 */
function backfill_pending_attendance($employeeId, $employeeName)
{
    $employeeId = (int)$employeeId;
    $employeeName = trim($employeeName);
    if ($employeeId <= 0 || $employeeName === '') return 0;

    try {
        $rows = db()->prepare("SELECT * FROM attendance_pending WHERE employee_name = ?");
        $rows->execute([$employeeName]);
        $pending = $rows->fetchAll();
        if (empty($pending)) return 0;

        $ins = db()->prepare("INSERT INTO attendances (employee_id, year, month, work_hours, absent_hours, remark)
                              VALUES (?, ?, ?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE work_hours=VALUES(work_hours), absent_hours=VALUES(absent_hours), remark=VALUES(remark)");
        $del = db()->prepare("DELETE FROM attendance_pending WHERE id = ?");
        db()->beginTransaction();
        $count = 0;
        foreach ($pending as $p) {
            $ins->execute([$employeeId, $p['year'], $p['month'], $p['work_hours'], $p['absent_hours'], $p['remark']]);
            $del->execute([$p['id']]);
            $count++;
        }
        db()->commit();
        return $count;
    } catch (\Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        error_log("backfill_pending_attendance error: " . $e->getMessage());
        return 0;
    }
}

/**
 * 获取某月所有员工考勤
 */
function get_attendances_by_month($year, $month)
{
    $stmt = db()->prepare("SELECT a.*, e.name, e.department
                           FROM attendances a
                           LEFT JOIN employees e ON a.employee_id = e.id
                           WHERE a.year=? AND a.month=?
                           ORDER BY e.department, e.name");
    $stmt->execute([$year, $month]);
    return $stmt->fetchAll();
}

/**
 * 获取有考勤记录的年份列表
 */
function get_attendance_years()
{
    try {
        return db()->query("SELECT DISTINCT year FROM attendances ORDER BY year DESC")->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * 获取某年的考勤月份列表（含员工数统计）
 */
function get_attendance_months($year)
{
    $stmt = db()->prepare("SELECT month,
                                  COUNT(*) AS emp_count,
                                  SUM(work_hours) AS total_work,
                                  SUM(absent_hours) AS total_absent
                           FROM attendances WHERE year=? GROUP BY month ORDER BY month");
    $stmt->execute([$year]);
    return $stmt->fetchAll();
}

/**
 * 异常订单对比：员工上传订单 vs 店铺订单
 * 按 order_no + 月份做对比，找出：
 *   - 缺失：员工上传了订单号，但店铺订单表里查不到
 *   - 金额不一致：两边都能查到同一订单号，但金额不同
 *
 * @param string $shopName 店铺名（空表示查所有店铺）
 * @param string $month    月份 YYYY-MM（空表示查所有月份）
 * @return array ['items' => [...], 'shops' => [...]]
 */
function get_abnormal_orders($shopName = '', $month = '')
{
    $pdo = db();
    $where = [];
    $params = [];

    // 确保 orders 表有所需字段（order_scope / shop / order_no 等）
    // 这些字段原本由 orders/index.php 的 ensureProjectColumn() 动态添加，
    // 异常订单页可能先于订单页被访问，这里主动补齐。
    foreach (['order_scope' => "VARCHAR(20) NOT NULL DEFAULT 'personal'",
              'shop'        => "VARCHAR(100) DEFAULT ''",
              'order_no'    => "VARCHAR(64) DEFAULT ''",
              'raw_data'    => "TEXT DEFAULT NULL"] as $col => $def) {
        try {
            $exists = $pdo->query("SHOW COLUMNS FROM `orders` LIKE '{$col}'")->fetchAll();
            if (empty($exists)) {
                $pdo->exec("ALTER TABLE `orders` ADD COLUMN `{$col}` {$def}");
            }
        } catch (\Throwable $e) {}
    }

    // 店铺订单（department + shop）
    $shopWhere = " WHERE o.order_scope = 'department' AND o.order_no <> '' AND COALESCE(o.is_deleted, 0) = 0 ";
    if ($shopName !== '') {
        $shopWhere .= " AND o.shop = ? ";
        $params[] = $shopName;
    }
    if ($month !== '') {
        $shopWhere .= " AND DATE_FORMAT(o.order_date, '%Y-%m') = ? ";
        $params[] = $month;
    }

    // 员工上传订单（personal，排除从部门派生的）
    $empWhere = " WHERE e.order_scope = 'personal' AND e.order_no <> '' AND COALESCE(e.is_deleted, 0) = 0 ";
    $empParams = [];
    if ($month !== '') {
        $empWhere .= " AND DATE_FORMAT(e.order_date, '%Y-%m') = ? ";
        $empParams[] = $month;
    }

    $sql = "
        SELECT
            COALESCE(s.shop, '未归属店铺') AS shop_name,
            COALESCE(sh.id, 0) AS shop_id,
            e.order_no,
            e.order_amount AS emp_amount,
            e.order_date AS emp_date,
            e.id AS emp_order_id,
            s.order_amount AS shop_amount,
            s.order_date AS shop_date,
            s.id AS shop_order_id,
            CASE
                WHEN s.id IS NULL THEN 'missing'
                WHEN ABS(e.order_amount - s.order_amount) > 0.001 THEN 'mismatch'
                ELSE 'match'
            END AS diff_type,
            ROUND(e.order_amount - COALESCE(s.order_amount, 0), 2) AS diff_amount
        FROM orders e
        LEFT JOIN (
            SELECT id, shop, order_no, order_amount, order_date FROM orders o
            " . $shopWhere . "
        ) s ON e.order_no = s.order_no
        LEFT JOIN shops sh ON sh.name = s.shop
        " . $empWhere . "
        ORDER BY diff_type DESC, e.order_date DESC, e.id DESC
    ";
    $stmt = $pdo->prepare($sql);
    $i = 0;
    // 先绑定店铺子查询参数
    foreach ($params as $p) { $stmt->bindValue(++$i, $p); }
    foreach ($empParams as $p) { $stmt->bindValue(++$i, $p); }
    $stmt->execute();
    $rows = $stmt->fetchAll();

    // 过滤掉 match 的，只留 missing/mismatch
    $items = array_filter($rows, fn($r) => $r['diff_type'] !== 'match');
    // 重新索引
    $items = array_values($items);

    // 按店铺汇总
    $shopStats = [];
    foreach ($items as $r) {
        $key = $r['shop_name'];
        if (!isset($shopStats[$key])) {
            $shopStats[$key] = [
                'shop_name' => $r['shop_name'],
                'shop_id' => $r['shop_id'],
                'missing' => 0,
                'mismatch' => 0,
                'total' => 0,
            ];
        }
        $shopStats[$key][$r['diff_type']]++;
        $shopStats[$key]['total']++;
    }
    $shopStats = array_values($shopStats);
    // 按异常总数降序
    usort($shopStats, fn($a, $b) => $b['total'] - $a['total']);

    return ['items' => $items, 'shops' => $shopStats];
}

/**
 * JSON响应并退出
 */
function json_response($data, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 获取所有部门列表（名称数组）
 * 优先从 departments 表读取；表不存在时回退到 employees 去重
 */
function get_departments()
{
    try {
        $stmt = db()->query("SELECT name FROM departments ORDER BY sort ASC, id ASC");
        $list = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($list)) return $list;
    } catch (PDOException $e) {
        // 表不存在时回退
    }
    $stmt = db()->query("SELECT DISTINCT department FROM employees WHERE department != '' ORDER BY department");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * 获取所有部门记录（含id/sort）
 */
function get_department_list()
{
    try {
        $stmt = db()->query("SELECT d.*, (SELECT COUNT(*) FROM employees e WHERE e.department = d.name) AS emp_count FROM departments d ORDER BY d.sort ASC, d.id ASC");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * 获取单个部门
 */
function get_department($id)
{
    $stmt = db()->prepare("SELECT * FROM departments WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

/**
 * 获取所有店铺列表（名称数组）
 */
function get_shops()
{
    try {
        $stmt = db()->query("SELECT name FROM shops ORDER BY sort ASC, id ASC");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * 获取所有店铺记录（含id/sort及关联订单数）
 */
function get_shop_list()
{
    try {
        $stmt = db()->query("SELECT s.*, (SELECT COUNT(*) FROM orders o WHERE o.shop = s.name AND COALESCE(o.is_deleted, 0) = 0) AS order_count FROM shops s ORDER BY s.sort ASC, s.id ASC");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * 获取单个店铺
 */
function get_shop($id)
{
    $stmt = db()->prepare("SELECT * FROM shops WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

/**
 * 获取所有员工
 */
function get_employees($department = null)
{
    if ($department) {
        $stmt = db()->prepare("SELECT * FROM employees WHERE department = ? ORDER BY name");
        $stmt->execute([$department]);
    } else {
        $stmt = db()->query("SELECT * FROM employees ORDER BY department, name");
    }
    return $stmt->fetchAll();
}

/**
 * 获取单个员工
 */
function get_employee($id)
{
    $stmt = db()->prepare("SELECT * FROM employees WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

/**
 * 生成Excel CSV下载
 * @param array $headers 表头
 * @param array $rows 数据行
 * @param string $filename 文件名
 */
function export_csv($headers, $rows, $filename)
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $fp = fopen('php://output', 'w');
    // BOM for Excel UTF-8
    fwrite($fp, "\xEF\xBB\xBF");
    fputcsv($fp, $headers);
    foreach ($rows as $row) {
        fputcsv($fp, $row);
    }
    fclose($fp);
    exit;
}

/**
 * 生成简单Excel(XML Spreadsheet)下载 - 支持中文
 */
function export_excel($headers, $rows, $filename)
{
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    echo "<?mso-application progid=\"Excel.Sheet\"?>\n";
    echo "<Workbook xmlns=\"urn:schemas-microsoft-com:office:spreadsheet\"\n";
    echo " xmlns:o=\"urn:schemas-microsoft-com:office:office\"\n";
    echo " xmlns:x=\"urn:schemas-microsoft-com:office:excel\"\n";
    echo " xmlns:ss=\"urn:schemas-microsoft-com:office:spreadsheet\">\n";
    echo "<Worksheet ss:Name=\"Sheet1\">\n<Table>\n";

    // 表头
    echo "<Row>";
    foreach ($headers as $h) {
        echo "<Cell><Data ss:Type=\"String\">" . e($h) . "</Data></Cell>";
    }
    echo "</Row>\n";

    // 数据
    foreach ($rows as $row) {
        echo "<Row>";
        foreach ($row as $val) {
            $type = is_numeric($val) ? 'Number' : 'String';
            echo "<Cell><Data ss:Type=\"{$type}\">" . e($val) . "</Data></Cell>";
        }
        echo "</Row>\n";
    }

    echo "</Table>\n</Worksheet>\n</Workbook>\n";
    exit;
}
