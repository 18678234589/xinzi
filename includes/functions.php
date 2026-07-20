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
 * 从 raw_data 里提取员工上传表格中填写的店铺名
 * 员工 personal 订单的 shop 字段在插入时为空，但表格里写了店铺名（如"清风易"）
 * 此函数用于异常订单比对时确定员工订单真正归属的店铺
 */
function extract_shop_from_raw($rawMap)
{
    if (!is_array($rawMap)) return '';
    // 候选列名（按优先级排序）
    $candidates = [
        '店铺', '店铺名称', '店铺名', '店名', '门店', '门店名称',
        'shop', 'shop_name', 'Shop', 'ShopName', 'store', 'store_name',
    ];
    foreach ($candidates as $key) {
        if (isset($rawMap[$key]) && is_string($rawMap[$key]) && trim($rawMap[$key]) !== '') {
            return trim($rawMap[$key]);
        }
    }
    // 模糊匹配，含"店铺"/"门店"/"shop"的列
    foreach ($rawMap as $k => $v) {
        if (strpos($k, '__') === 0) continue;
        if (!is_string($v)) continue;
        if (trim($v) === '') continue;
        if (mb_strpos($k, '店铺') !== false || mb_strpos($k, '门店') !== false
            || stripos($k, 'shop') !== false || stripos($k, 'store') !== false) {
            return trim($v);
        }
    }
    return '';
}

/**
 * 将员工表格里填写的店铺名（可能是简称）匹配到 shops 表/department 订单中的标准店铺名
 * 例：员工填"清风易" → 匹配"清风易软件专营店"
 *
 * @param string $empShop 员工填写的店铺名
 * @param array  $knownShops 所有已知标准店铺名（shops 表 + department 订单中出现的 shop）
 * @return string 匹配到的标准店铺名，未匹配返回空字符串
 */
function match_shop_name($empShop, $knownShops)
{
    $empShop = trim($empShop);
    if ($empShop === '') return '';

    // 0. 别名映射：员工表格里填的收款方式/业务分类别名 → 标准店铺名
    //    数据来源于实际业务数据分析，避免这些订单被误判为"未归属"
    static $aliases = [
        // 扫码/微信收款类 → 科恒扫码收款
        '微信'     => '科恒扫码收款',
        '微信订单' => '科恒扫码收款',
        '二维码'   => '科恒扫码收款',
        // 对公转账类 → 对公收款
        '对公转账' => '对公收款',
        '对公订单' => '对公收款',
        '科对公'   => '对公收款',
        '对公'     => '对公收款',
    ];
    if (isset($aliases[$empShop])) {
        $target = $aliases[$empShop];
        // 确认目标标准名存在于已知店铺列表中
        foreach ($knownShops as $std) {
            if ($std === $target) return $std;
        }
    }

    // 1. 精确匹配（含去除首尾空白后）
    foreach ($knownShops as $std) {
        if ($empShop === $std) return $std;
    }

    // 2. 忽略大小写精确匹配
    $empLower = mb_strtolower($empShop);
    foreach ($knownShops as $std) {
        if (mb_strtolower($std) === $empLower) return $std;
    }

    // 3. 包含关系：员工填的简称是标准名的子串，或标准名是员工填的子串
    //    例：员工"清风易" ⊂ 标准"清风易软件专营店"
    //    只取唯一匹配，多个匹配则跳过（避免歧义）
    $matches = [];
    foreach ($knownShops as $std) {
        if ($std === '') continue;
        if (mb_strpos($std, $empShop) !== false || mb_strpos($empShop, $std) !== false) {
            $matches[] = $std;
        }
    }
    if (count($matches) === 1) {
        return $matches[0];
    }

    // 4. 多个匹配时，优先选长度最接近的（最短标准名，差异最小）
    if (count($matches) > 1) {
        usort($matches, fn($a, $b) => abs(mb_strlen($a) - mb_strlen($empShop)) - abs(mb_strlen($b) - mb_strlen($empShop)));
        return $matches[0];
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
 * 获取订单的手续费信息（用于前端展示）
 *
 * 优先级：
 * 1. raw_data 里存的 __fee_rate__（新上传的订单）
 * 2. 员工算法配置里对应模块的 service_fee_rate
 * 3. config/dept_fee.php 按部门名查
 *
 * @param array $rawData  订单的 raw_data 解码后的数组
 * @param array $order    订单记录（含 order_amount, employee_id, project, order_scope 等）
 * @return array ['rate'=>费率, 'amount'=>手续费, 'original_price'=>原售价, 'net'=>净额]
 */
function get_order_fee_info($rawData, $order)
{
    // 1. 优先用 raw_data 里存的费率
    $feeRate = (float)($rawData['__fee_rate__'] ?? 0);
    $feeAmount = (float)($rawData['__fee_amount__'] ?? 0);
    $origPrice = (float)($rawData['__original_price__'] ?? 0);

    if ($feeRate > 0) {
        return [
            'rate'          => $feeRate,
            'amount'        => $feeAmount,
            'original_price' => $origPrice,
            'net'           => (float)$order['order_amount'],
        ];
    }

    // 2. 回退：从算法配置或 dept_fee.php 查费率
    $deptName = $rawData['__dept__'] ?? '';
    $employeeId = (int)($order['employee_id'] ?? 0);
    $project = $order['project'] ?? '';
    $scope = $order['order_scope'] ?? 'personal';

    $feeRate = 0;
    $moduleMatched = false; // 是否按 project 名匹配到了模块

    // 2a. 查员工算法配置里的 service_fee_rate（按 project 名精确匹配模块）
    if ($employeeId > 0 && class_exists('SalaryCalculator', false)) {
        $modCfg = SalaryCalculator::readModulesConfig($employeeId);
        if ($modCfg && !empty($modCfg['modules'])) {
            // 优先按 project 名匹配模块，匹配到就用该模块的费率（即使为 0）
            if ($project !== '') {
                foreach ($modCfg['modules'] as $m) {
                    if (($m['enabled'] ?? true) && $m['name'] === $project) {
                        $feeRate = (float)($m['config']['service_fee_rate'] ?? 0);
                        $moduleMatched = true;
                        break;
                    }
                }
            }
            // 没匹配到 project，取第一个有费率的模块
            if (!$moduleMatched) {
                foreach ($modCfg['modules'] as $m) {
                    if ($m['enabled'] ?? true) {
                        $sfr = (float)($m['config']['service_fee_rate'] ?? 0);
                        if ($sfr > 0) { $feeRate = $sfr; break; }
                    }
                }
            }
        }
    }

    // 2b. 回退到 dept_fee.php（仅当模块未匹配且为部门订单时）
    if (!$moduleMatched && $feeRate === 0 && $deptName !== '') {
        static $deptFeeMap = null;
        if ($deptFeeMap === null) {
            $deptFeeFile = __DIR__ . '/../config/dept_fee.php';
            if (file_exists($deptFeeFile)) {
                $deptFeeMap = include $deptFeeFile;
                if (!is_array($deptFeeMap)) $deptFeeMap = [];
            } else {
                $deptFeeMap = [];
            }
        }
        $feeRate = isset($deptFeeMap[$deptName]) ? (float)$deptFeeMap[$deptName] : 0;
    }

    if ($feeRate > 0) {
        // 从 raw_data 里找售价（价格列）
        $price = 0;
        foreach ($rawData as $k => $v) {
            if (mb_strpos($k, '价格') !== false || mb_strpos($k, '售价') !== false) {
                $price = (float)preg_replace('/[^\d.\-]/', '', trim($v));
                if ($price > 0) break;
            }
        }
        // 找不到售价，用 order_amount + 手续费反推
        if ($price <= 0) {
            $price = round((float)$order['order_amount'] / (1 - $feeRate), 2);
        }
        $feeAmount = round($price * $feeRate, 2);
        return [
            'rate'          => $feeRate,
            'amount'        => $feeAmount,
            'original_price' => $price,
            'net'           => (float)$order['order_amount'],
        ];
    }

    return ['rate' => 0, 'amount' => 0, 'original_price' => 0, 'net' => (float)$order['order_amount']];
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
    static $colsChecked = false;
    if (!$colsChecked) {
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
        // 关键索引，加速异常订单对比查询
        try {
            $pdo->exec("ALTER TABLE `orders` ADD INDEX `idx_scope_no_del` (order_scope, order_no, is_deleted)");
        } catch (\Throwable $e) {}
        $colsChecked = true;
    }

    // 取所有店铺名（用于概览按店铺逐个比对，以及缺失订单归属到正确店铺）
    $allShops = [];
    try {
        $allShops = $pdo->query("SELECT id, name FROM shops ORDER BY sort ASC, id ASC")->fetchAll();
    } catch (\Throwable $e) {}

    // 员工上传订单（personal，排除从部门派生的）——与店铺无关，只按月份过滤
    $empWhere = " WHERE e.order_scope = 'personal' AND e.order_no <> '' AND COALESCE(e.is_deleted, 0) = 0 ";
    $empParams = [];
    if ($month !== '') {
        $empWhere .= " AND DATE_FORMAT(e.order_date, '%Y-%m') = ? ";
        $empParams[] = $month;
    }

    // 拉取员工订单（不拉 raw_data 大文本，避免慢）
    // 直接用 JSON_EXTRACT 在DB端提取 __original_price__ 和店铺名
    $empSql = "SELECT e.id, e.employee_id, e.order_no, e.order_amount, e.order_date, emp.name AS emp_name,"
            . " JSON_UNQUOTE(JSON_EXTRACT(e.raw_data, '$.__original_price__')) AS emp_orig_price,"
            . " JSON_UNQUOTE(JSON_EXTRACT(e.raw_data, '$.店铺')) AS rd_shop1,"
            . " JSON_UNQUOTE(JSON_EXTRACT(e.raw_data, '$.店铺名称')) AS rd_shop2,"
            . " JSON_UNQUOTE(JSON_EXTRACT(e.raw_data, '$.店铺名')) AS rd_shop3,"
            . " JSON_UNQUOTE(JSON_EXTRACT(e.raw_data, '$.店名')) AS rd_shop4,"
            . " JSON_UNQUOTE(JSON_EXTRACT(e.raw_data, '$.shop')) AS rd_shop5"
            . " FROM orders e LEFT JOIN employees emp ON emp.id = e.employee_id " . $empWhere
            . " ORDER BY e.order_date DESC, e.id DESC";
    $empStmt = $pdo->prepare($empSql);
    foreach ($empParams as $k => $p) { $empStmt->bindValue($k + 1, $p); }
    $empStmt->execute();
    $empOrders = $empStmt->fetchAll();

    // 拉取店铺订单（department），始终拉取所有店铺
    // （员工 personal 订单的 shop 字段为空，需从 raw_data 提取店铺名后定位对应店铺订单）
    // 注意：不在此处拉 raw_data（大文本），避免内存溢出；按需在比对到 mismatch 时单独查询
    $shopWhere = " WHERE o.order_scope = 'department' AND o.order_no <> '' AND COALESCE(o.is_deleted, 0) = 0 ";
    $shopParams = [];
    if ($month !== '') {
        $shopWhere .= " AND DATE_FORMAT(o.order_date, '%Y-%m') = ? ";
        $shopParams[] = $month;
    }
    $shopSql = "SELECT o.id, o.shop, o.order_no, o.order_amount, o.order_date,"
             . " JSON_UNQUOTE(JSON_EXTRACT(o.raw_data, '$.__original_price__')) AS shop_orig_price"
             . " FROM orders o " . $shopWhere
             . " ORDER BY o.id ASC";
    $shopStmt = $pdo->prepare($shopSql);
    foreach ($shopParams as $k => $p) { $shopStmt->bindValue($k + 1, $p); }
    $shopStmt->execute();
    $shopOrders = $shopStmt->fetchAll();

    // 店铺订单按 (shop, order_no) 索引；同一店铺同一订单号取第一条
    $shopMap = [];
    $shopNameMap = []; // shop name => shop id
    // 全局订单号索引：order_no => department 订单记录（员工没填店铺名时，用订单号反查归属店铺）
    $deptByNo = [];
    foreach ($shopOrders as $so) {
        $sn = $so['shop'] !== '' ? $so['shop'] : '未归属店铺';
        if (!isset($shopMap[$sn][$so['order_no']])) {
            $shopMap[$sn][$so['order_no']] = $so;
        }
        if (!isset($deptByNo[$so['order_no']])) {
            $deptByNo[$so['order_no']] = $so;
        }
    }
    foreach ($allShops as $sh) {
        $shopNameMap[$sh['name']] = (int)$sh['id'];
    }

    // 所有已知标准店铺名（用于将员工表格里的简称匹配到标准名，如"清风易"→"清风易软件专营店"）
    $knownShopNames = array_unique(array_merge(array_keys($shopMap), array_column($allShops, 'name')));

    // 确定要比对的店铺列表
    if ($shopName !== '') {
        $targetShops = [$shopName];
    } else {
        // 概览：所有有店铺订单的店铺 + 数据库里的店铺
        $targetShops = array_unique(array_merge(array_keys($shopMap), array_column($allShops, 'name')));
    }

    $items = [];
    $shopStats = [];

    // 初始化每个目标店铺的统计
    foreach ($targetShops as $sn) {
        $sid = $shopNameMap[$sn] ?? 0;
        $shopStats[$sn] = ['shop_name' => $sn, 'shop_id' => $sid, 'missing' => 0, 'mismatch' => 0, 'total' => 0];
    }

    // "未归属"虚拟店铺：员工上传了但所有店铺 department 表都查不到的孤儿订单
    $orphanKey = '未归属';
    $shopStats[$orphanKey] = ['shop_name' => $orphanKey, 'shop_id' => 0, 'missing' => 0, 'mismatch' => 0, 'total' => 0];

    // 遍历员工订单
    foreach ($empOrders as $eo) {
        $ono = $eo['order_no'];

        // 从 SQL 提取的店铺名列中取第一个非空的
        $empShopRaw = '';
        foreach (['rd_shop1','rd_shop2','rd_shop3','rd_shop4','rd_shop5'] as $sf) {
            if (!empty($eo[$sf])) { $empShopRaw = trim($eo[$sf]); break; }
        }
        // 模糊匹配其他 shop 类列名已在SQL层提取，5个列名覆盖绝大多数情况
        // 若都为空，后续会用 deptByNo 按订单号反查归属店铺

        // 将员工填写的店铺名匹配到标准店铺名（如"清风易"→"清风易软件专营店"）
        $empShop = $empShopRaw !== '' ? match_shop_name($empShopRaw, $knownShopNames) : '';

        // 提取员工的原始售价（SQL已提取，无需PHP解析JSON）
        $empOriginalPrice = $eo['emp_orig_price'] !== null && (float)$eo['emp_orig_price'] > 0
            ? (float)$eo['emp_orig_price'] : (float)$eo['order_amount'];

        // 确定该员工订单归属的店铺，优先用匹配到的标准店铺名，否则用订单号反查
        if ($empShop === '') {
            // 员工表格里没填店铺名，拿订单号去全量 department 订单里反查
            if (isset($deptByNo[$ono])) {
                $so = $deptByNo[$ono];
                $empShop = $so['shop']; // 归属到 department 订单里的店铺
                // 继续走下方的金额比对逻辑
            } else {
                // 全量 department 订单里也查不到此订单号 → 真孤儿缺失
                $items[] = [
                    'shop_name'     => $orphanKey,
                    'shop_id'        => 0,
                    'order_no'       => $ono,
                    'emp_amount'     => $eo['order_amount'],
                    'emp_date'      => $eo['order_date'],
                    'emp_order_id'   => $eo['id'],
                    'emp_name'      => $eo['emp_name'],
                    'employee_id'    => $eo['employee_id'],
                    'shop_amount'    => null,
                    'shop_date'      => null,
                    'shop_order_id'  => null,
                    'diff_type'      => 'missing',
                    'diff_amount'    => round($empOriginalPrice, 2),
                ];
                $shopStats[$orphanKey]['missing']++;
                $shopStats[$orphanKey]['total']++;
                continue;
            }
        }

        // 只跟该员工订单归属的店铺做比对
        $sOrders = $shopMap[$empShop] ?? [];
        if (isset($sOrders[$ono])) {
            // 该店铺有此订单号 → 比对金额（用售价对比，非利润）
            $so = $sOrders[$ono];

            // 从SQL提取的 __original_price__ 直接取
            $shopOriginalPrice = $so['shop_orig_price'] !== null && (float)$so['shop_orig_price'] > 0
                ? (float)$so['shop_orig_price'] : (float)$so['order_amount'];

            $diff = round($empOriginalPrice - $shopOriginalPrice, 2);
            if (abs($diff) > 0.001) {
                // 金额不一致（按售价对比）
                $items[] = [
                    'shop_name'     => $empShop,
                    'shop_id'       => $shopNameMap[$empShop] ?? 0,
                    'order_no'      => $ono,
                    'emp_amount'    => $empOriginalPrice,
                    'emp_date'      => $eo['order_date'],
                    'emp_order_id'  => $eo['id'],
                    'emp_name'      => $eo['emp_name'],
                    'employee_id'   => $eo['employee_id'],
                    'shop_amount'   => $shopOriginalPrice,
                    'shop_date'     => $so['order_date'],
                    'shop_order_id' => $so['id'],
                    'diff_type'     => 'mismatch',
                    'diff_amount'   => $diff,
                ];
                if (!isset($shopStats[$empShop])) {
                    $shopStats[$empShop] = ['shop_name' => $empShop, 'shop_id' => $shopNameMap[$empShop] ?? 0, 'missing' => 0, 'mismatch' => 0, 'total' => 0];
                }
                $shopStats[$empShop]['mismatch']++;
                $shopStats[$empShop]['total']++;
            }
            // 金额一致 = match，不记录
        } else {
            // 该店铺的 department 表里查不到此订单号 → 店铺缺失
            $items[] = [
                'shop_name'     => $empShop,
                'shop_id'        => $shopNameMap[$empShop] ?? 0,
                'order_no'       => $ono,
                'emp_amount'     => $empOriginalPrice,
                'emp_date'      => $eo['order_date'],
                'emp_order_id'   => $eo['id'],
                'emp_name'      => $eo['emp_name'],
                'employee_id'    => $eo['employee_id'],
                'shop_amount'    => null,
                'shop_date'      => null,
                'shop_order_id'  => null,
                'diff_type'      => 'missing',
                'diff_amount'    => round($empOriginalPrice, 2),
            ];
            if (!isset($shopStats[$empShop])) {
                $shopStats[$empShop] = ['shop_name' => $empShop, 'shop_id' => $shopNameMap[$empShop] ?? 0, 'missing' => 0, 'mismatch' => 0, 'total' => 0];
            }
            $shopStats[$empShop]['missing']++;
            $shopStats[$empShop]['total']++;
        }
    }

    // 排序，先缺失后不一致，再按日期倒序
    usort($items, function ($a, $b) {
        if ($a['diff_type'] !== $b['diff_type']) {
            return $a['diff_type'] === 'missing' ? -1 : 1;
        }
        return strcmp($b['emp_date'] ?? '', $a['emp_date'] ?? '');
    });

    // 去掉 total=0 的店铺
    $shopStats = array_filter($shopStats, fn($s) => $s['total'] > 0);
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
