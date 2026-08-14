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
            db()->exec("ALTER TABLE `orders` ADD COLUMN `order_no` VARCHAR(255) DEFAULT '' COMMENT '订单号(从raw_data提取)' AFTER `shop`");
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
 * 从单元格值安全提取金额数字。
 *
 * 支持多种录入习惯：
 *  - 纯数字："4500" / "-1200"
 *  - 货币/千分位："¥1,200.50" / "1,200"
 *  - 成本等写成算式的："999+999+150+900"（=3048）、"(100+50)*2"
 *  - 只有 + 减 乘 除 与括号、小数点，其余字符一律剔除
 *
 * 无法解析时返回 0.0。避免把"成本=999+999+150+900"剥符号拼成天文数字。
 *
 * @param mixed $v
 * @return float
 */
function extract_amount($v)
{
    $s = trim((string)$v);
    if ($s === '') return 0.0;

    // 仅保留数字和四则运算符号、括号、小数点、空格
    $expr = trim(preg_replace('~[^0-9+\-*/(). ]~', '', $s));
    if ($expr === '' || $expr === '-' || $expr === '+' || $expr === '.') return 0.0;

    // 纯数字（含负数/小数）：直接返回
    if (preg_match('/^-?\d+(\.\d+)?$/', $expr)) {
        return (float)$expr;
    }

    // 其余按四则运算求值（安全：输入已被过滤为仅含数字与运算符）
    try {
        $val = eval_amount_expr($expr);
        return is_finite($val) ? $val : 0.0;
    } catch (\Throwable $e) {
        return 0.0;
    }
}

/**
 * 解析 SSL 证书使用金额（根据业务新规则）
 *
 * 规则（优先级从高到低）：
 *  1. 值含"通配符"字眼 → 250 元（通配符证书）
 *  2. 有明确数字 → 该数字（如 "30" → 30，"真实成本250元" → 250）
 *  3. 模糊"已购买"标记（是/一年/1/true 等）→ 30 元
 *  4. 空或无 SSL 信息 → null（表示未使用）
 *
 * @param mixed $v
 * @return float|null 金额；未使用/无法识别时返回 null
 */
function parse_ssl_amount($v)
{
    $sv = trim((string)$v);
    if ($sv === '') return null;

    // 通配符证书统一 250
    if (mb_strpos($sv, '通配符') !== false) return 250.0;

    // 有明确数字按数字（如 "30"、"真实成本250元"）
    $num = extract_amount($sv);
    if ($num > 0) return $num;

    // 模糊"已购买"标记 → 30
    if ($sv === '1' || $sv === 'true'
        || mb_strpos($sv, '是') !== false || mb_strpos($sv, '一年') !== false) {
        return 30.0;
    }

    return null;
}

/**
 * 解析域名使用年限：返回 0 = 未使用域名；否则返回年限（>=1）
 *
 * 识别规则：
 *  - 否 / 空 → 0（未使用域名）
 *  - "是"/"1"/"true" → 1 年
 *  - "是*4年" / "4年" / "四年" → 4 年（域名成本按 4 倍计）
 *  - 其它含"是"但无明确年限 → 默认 1 年
 *
 * @param mixed $val
 * @return int 年限；0 表示未使用
 */
function domain_years($val)
{
    $s = trim((string)$val);
    if ($s === '') return 0;
    if (mb_strpos($s, '否') !== false) return 0;   // 否 = 未使用域名

    // 阿拉伯数字年限："4年"、"是*4年"
    if (preg_match('/(\d+)\s*年/', $s, $m)) return (int)$m[1];

    // 中文数字年限："四年"、"两年" …
    $cn = ['一' => 1, '两' => 2, '二' => 2, '三' => 3, '四' => 4,
           '五' => 5, '六' => 6, '七' => 7, '八' => 8, '九' => 9, '十' => 10];
    foreach ($cn as $c => $n) {
        if (mb_strpos($s, $c . '年') !== false) return $n;
    }

    // 明确"是"而未写年限 → 1 年
    if (mb_strpos($s, '是') !== false || $s === '1' || $s === 'true') return 1;

    return 0;
}

/**
 * 求值单值金额表达式（仅支持 + - * / 与括号、小数、一元正负号）。
 * 输入必须是已经 extract_amount 过滤、仅含数字和运算符的字符串。
 *
 * @param string $expr
 * @return float
 * @throws \RuntimeException
 */
function eval_amount_expr($expr)
{
    $expr = str_replace(['(', ')', '+', '-', '*', '/'],
                        [' ( ', ' ) ', ' + ', ' - ', ' * ', ' / '], $expr);
    $tokens = array_values(array_filter(preg_split('/\s+/', trim($expr)), fn($t) => $t !== ''));

    $ops      = ['+' => 1, '-' => 1, '*' => 2, '/' => 2];
    $numStack = [];
    $opStack  = [];
    $needOperand = true; // 期待操作数（用于一元正负号）

    $applyTop = function () use (&$numStack, &$opStack, $ops) {
        $op = array_pop($opStack);
        $b  = array_pop($numStack);
        $a  = array_pop($numStack);
        if ($op === '+')      $r = $a + $b;
        elseif ($op === '-')  $r = $a - $b;
        elseif ($op === '*')  $r = $a * $b;
        else { if ($b == 0) throw new \RuntimeException('division by zero'); $r = $a / $b; }
        $numStack[] = $r;
    };

    foreach ($tokens as $t) {
        if ($t === '(') {
            $opStack[] = '(';
            $needOperand = true;
        } elseif ($t === ')') {
            while ($opStack && end($opStack) !== '(') $applyTop();
            if (!$opStack) throw new \RuntimeException('unmatched )');
            array_pop($opStack);
            $needOperand = false;
        } elseif (isset($ops[$t])) {
            // 一元正负号：期待操作数时，把 -x 当作 0-x，+x 忽略
            if ($needOperand) {
                if ($t === '-') $numStack[] = 0.0;
                $needOperand = false;
            }
            while ($opStack && end($opStack) !== '(' && $ops[end($opStack)] >= $ops[$t]) $applyTop();
            $opStack[] = $t;
            $needOperand = true;
        } else {
            if (!preg_match('/^-?\d+(\.\d+)?$/', $t)) throw new \RuntimeException('bad token ' . $t);
            $numStack[] = (float)$t;
            $needOperand = false;
        }
    }
    while ($opStack) {
        if (end($opStack) === '(') { array_pop($opStack); continue; }
        $applyTop();
    }
    if (count($numStack) !== 1) throw new \RuntimeException('expression parse failed');
    return $numStack[0];
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

    // 2b. 回退到 dept_config.php / dept_fee.php（仅当模块未匹配且为部门订单时）
    if (!$moduleMatched && $feeRate === 0 && $deptName !== '') {
        // 优先查 dept_config.php（网站售后部独立配置，隔离其他分支修改）
        static $deptConfigMap = null;
        if ($deptConfigMap === null) {
            $deptConfigFile = __DIR__ . '/../config/dept_config.php';
            if (file_exists($deptConfigFile)) {
                $dc = include $deptConfigFile;
                if (is_array($dc) && isset($dc['dept_name'], $dc['service_fee_rate'])) {
                    $deptConfigMap = [$dc['dept_name'] => (float)$dc['service_fee_rate']];
                } else {
                    $deptConfigMap = [];
                }
            } else {
                $deptConfigMap = [];
            }
        }
        if (isset($deptConfigMap[$deptName])) {
            $feeRate = $deptConfigMap[$deptName];
        }

        // 再回退到 dept_fee.php（通用部门费率配置）
        if ($feeRate === 0) {
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
    }

    if ($feeRate > 0) {
        // 从 raw_data 里找售价（价格列）
        $price = 0;
        foreach ($rawData as $k => $v) {
            if (mb_strpos($k, '价格') !== false || mb_strpos($k, '售价') !== false) {
                $price = extract_amount($v);
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
function get_abnormal_orders($shopName = '', $month = '', $employeeName = '')
{
    $pdo = db();
    $where = [];
    $params = [];

    // 文件缓存（5分钟有效期），避免每次刷新都全量重算
    // 缓存键纳入本源码文件修改时间：只要核验逻辑被改动，旧 JSON 结果立即失效，
    // 避免线上仍读到改动前缓存出的结果（无需手动去猜当前页面对应哪个 MD5 文件）。
    $cacheDir = __DIR__ . '/../storage/abnormal_cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
    $cacheKey = md5(__FILE__ . '@' . @filemtime(__FILE__) . '|' . $shopName . '|' . $month . '|' . $employeeName);
    $cacheFile = $cacheDir . '/' . $cacheKey . '.json';
    $cacheTTL = 300; // 5分钟
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTTL) {
        $cached = @file_get_contents($cacheFile);
        if ($cached !== false) {
            $data = json_decode($cached, true);
            if (is_array($data) && isset($data['items'])) {
                return $data;
            }
        }
    }

    // 确保 orders 表有所需字段（order_scope / shop / order_no 等）
    // 用持久化标记文件避免每次请求都做 SHOW COLUMNS / ALTER TABLE（省6次远程查询）
    $schemaFlag = __DIR__ . '/../storage/.schema_checked';
    if (!file_exists($schemaFlag)) {
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
        try { $pdo->exec("ALTER TABLE `orders` ADD INDEX `idx_scope_no_del` (order_scope, order_no, is_deleted)"); } catch (\Throwable $e) {}
        try { $pdo->exec("ALTER TABLE `orders` ADD INDEX `idx_emp_name` (name)"); } catch (\Throwable $e) {}
        try { $pdo->exec("ALTER TABLE `employees` ADD INDEX `idx_emp_name` (name)"); } catch (\Throwable $e) {}
        @file_put_contents($schemaFlag, date('Y-m-d H:i:s'));
    }

    // 取所有店铺名（用于概览按店铺逐个比对，以及缺失订单归属到正确店铺）
    $allShops = [];
    try {
        $allShops = $pdo->query("SELECT id, name FROM shops ORDER BY sort ASC, id ASC")->fetchAll();
    } catch (\Throwable $e) {}

    // 月份范围（避免 DATE_FORMAT 杀索引，改用 >= / < 范围）
    $monthStart = ''; $monthEnd = '';
    if ($month !== '') {
        $monthStart = $month . '-01 00:00:00';
        $monthEnd = date('Y-m-d 00:00:00', strtotime($month . '-01 +1 month'));
    }

    // 员工上传订单（personal，排除从部门派生的）——与店铺无关，只按月份过滤
    $empWhere = " WHERE e.order_scope = 'personal' AND e.order_no <> '' AND (e.is_deleted = 0 OR e.is_deleted IS NULL) ";
    $empParams = [];
    if ($month !== '') {
        $empWhere .= " AND e.order_date >= ? AND e.order_date < ? ";
        $empParams[] = $monthStart;
        $empParams[] = $monthEnd;
    }
    if ($employeeName !== '') {
        $empWhere .= " AND emp.name = ? ";
        $empParams[] = $employeeName;
    }

    // 拉取员工订单（不拉 raw_data 大文本，避免慢）
    // 用 JSON_EXTRACT 在DB端提取 __original_price__ 和店铺名（COALESCE取第一个非空）
    $empSql = "SELECT e.id, e.employee_id, e.order_no, e.order_amount, e.order_date, emp.name AS emp_name,"
            . " JSON_UNQUOTE(JSON_EXTRACT(e.raw_data, '$.\"__original_price__\"')) AS emp_orig_price,"
            . " COALESCE("
            . "   JSON_UNQUOTE(JSON_EXTRACT(e.raw_data, '$.\"店铺\"')),"
            . "   JSON_UNQUOTE(JSON_EXTRACT(e.raw_data, '$.\"店铺名称\"')),"
            . "   JSON_UNQUOTE(JSON_EXTRACT(e.raw_data, '$.\"店铺名\"')),"
            . "   JSON_UNQUOTE(JSON_EXTRACT(e.raw_data, '$.\"店名\"')),"
            . "   JSON_UNQUOTE(JSON_EXTRACT(e.raw_data, '$.shop'))"
            . " ) AS emp_shop_raw"
            . " FROM orders e LEFT JOIN employees emp ON emp.id = e.employee_id " . $empWhere
            . " ORDER BY e.order_date DESC, e.id DESC";
    $empStmt = $pdo->prepare($empSql);
    foreach ($empParams as $k => $p) { $empStmt->bindValue($k + 1, $p); }
    $empStmt->execute();
    $empOrders = $empStmt->fetchAll();

    // 拉取店铺订单（department），分两批：
    // 批次1：当月 department 订单，构建 shopMap（按 shop+order_no 索引，主匹配用）
    // 批次2：全量 department 订单（不限月份），构建 deptByNo（按 order_no 索引，跨月回退匹配用）
    // 只拉轻量字段（不拉raw_data），配合索引，全量拉取可接受

    // ---- 批次1：当月 department 订单 ----
    $shopWhere = " WHERE o.order_scope = 'department' AND o.order_no <> '' AND (o.is_deleted = 0 OR o.is_deleted IS NULL) ";
    $shopParams = [];
    if ($month !== '') {
        $shopWhere .= " AND o.order_date >= ? AND o.order_date < ? ";
        $shopParams[] = $monthStart;
        $shopParams[] = $monthEnd;
    }
    $shopSql = "SELECT o.id, o.shop, o.order_no, o.order_amount, o.order_date,"
             . " JSON_UNQUOTE(JSON_EXTRACT(o.raw_data, '$.\"__original_price__\"')) AS shop_orig_price"
             . " FROM orders o " . $shopWhere
             . " ORDER BY o.id ASC";
    $shopStmt = $pdo->prepare($shopSql);
    foreach ($shopParams as $k => $p) { $shopStmt->bindValue($k + 1, $p); }
    $shopStmt->execute();
    $shopOrders = $shopStmt->fetchAll();

    // ---- 批次2：全量 department 订单（跨月回退用）----
    $allDeptSql = "SELECT o.id, o.shop, o.order_no, o.order_amount, o.order_date,"
                 . " JSON_UNQUOTE(JSON_EXTRACT(o.raw_data, '$.\"__original_price__\"')) AS shop_orig_price"
                 . " FROM orders o WHERE o.order_scope = 'department' AND o.order_no <> ''"
                 . " AND (o.is_deleted = 0 OR o.is_deleted IS NULL)"
                 . " ORDER BY o.id ASC";
    $allDeptStmt = $pdo->prepare($allDeptSql);
    $allDeptStmt->execute();
    $allDeptOrders = $allDeptStmt->fetchAll();

    // 店铺订单按 (shop, order_no) 索引；同一店铺同一订单号取第一条（当月）
    $shopMap = [];
    $shopNameMap = []; // shop name => shop id
    // 全局订单号索引，order_no => department 订单记录（不限月份，用于跨月回退匹配）
    // 订单号统一 trim + 字符串化后建索引，避免导入表格里带不可见空格导致同一订单号对不上
    $deptByNo = [];
    foreach ($shopOrders as $so) {
        $sn = $so['shop'] !== '' ? $so['shop'] : '未归属店铺';
        $okey = trim((string)$so['order_no']);
        if ($okey === '') continue;
        if (!isset($shopMap[$sn][$okey])) {
            $shopMap[$sn][$okey] = $so;
        }
    }
    // deptByNo 用全量数据构建，确保跨月订单也能反查到
    foreach ($allDeptOrders as $so) {
        $okey = trim((string)$so['order_no']);
        if ($okey === '') continue;
        if (!isset($deptByNo[$okey])) {
            $deptByNo[$okey] = $so;
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

    // 第一步：员工订单按订单号求和分组。
    // 一单可能被拆成多行（主款 + SSL/HTTPS 证书 + 备案等），先按订单号聚合，
    // 用求和合计与店铺订单比对，避免“拆多行被逐条误报为金额不一致”。
    $groups = [];
    foreach ($empOrders as $eo) {
        // 订单号同样 trim + 字符串化，与上面索引保持一致
        $ono = trim((string)$eo['order_no']);
        if ($ono === '') continue;

        // 从 SQL 提取的店铺名（COALESCE已取第一个非空）
        $empShopRaw = trim($eo['emp_shop_raw'] ?? '');

        // 提取该行的原始售价（SQL已提取，无需PHP解析JSON）
        $empOriginalPrice = $eo['emp_orig_price'] !== null && (float)$eo['emp_orig_price'] > 0
            ? (float)$eo['emp_orig_price'] : (float)$eo['order_amount'];

        if (!isset($groups[$ono])) {
            $groups[$ono] = [
                'sum'          => 0.0,
                'rows'         => 0,
                'emp_shop_raw' => $empShopRaw,
                'first'        => $eo,
            ];
        }
        $groups[$ono]['sum'] += $empOriginalPrice;
        $groups[$ono]['rows']++;
    }

    // 第二步：逐订单号做两步比对（先匹配订单号，再匹配金额，任一不符都标异常）
    foreach ($groups as $ono => $g) {
        $eo = $g['first'];
        $empShopRaw = $g['emp_shop_raw'];

        // 将员工填写的店铺名匹配到标准店铺名（如"清风易"→"清风易软件专营店"）
        $empShop = $empShopRaw !== '' ? match_shop_name($empShopRaw, $knownShopNames) : '';

        // 该订单号的售价合计（求和）
        $empOriginalPrice = round($g['sum'], 2);

        // 确定归属店铺：优先用匹配到的标准店铺名，否则用订单号反查
        if ($empShop === '') {
            // 员工没填店铺名，拿订单号去全量 department 订单里反查
            if (isset($deptByNo[$ono])) {
                $empShop = $deptByNo[$ono]['shop'];
            } else {
                // 全量 department 订单里也查不到此订单号 → 真孤儿缺失
                $items[] = [
                    'shop_name'     => $orphanKey,
                    'shop_id'        => 0,
                    'order_no'       => $ono,
                    'emp_amount'     => $empOriginalPrice,
                    'emp_rows'       => $g['rows'],
                    'emp_date'       => $eo['order_date'],
                    'emp_order_id'   => $eo['id'],
                    'emp_name'       => $eo['emp_name'],
                    'employee_id'    => $eo['employee_id'],
                    'shop_amount'    => null,
                    'shop_date'      => null,
                    'shop_order_id'  => null,
                    'diff_type'      => 'missing',
                    'diff_amount'    => $empOriginalPrice,
                ];
                $shopStats[$orphanKey]['missing']++;
                $shopStats[$orphanKey]['total']++;
                continue;
            }
        }

        // 第 1 步·订单号匹配：先按 (shop, order_no) 在当前月查，再按 order_no 全量回退（跨月/空 shop）
        $so = null;
        $sOrders = $shopMap[$empShop] ?? [];
        if (isset($sOrders[$ono])) {
            $so = $sOrders[$ono];
        } elseif (isset($deptByNo[$ono])) {
            $so = $deptByNo[$ono];
        }

        if ($so === null) {
            // 第 1 步失败：该店铺 department 表里查不到此订单号 → 店铺缺失
            $items[] = [
                'shop_name'     => $empShop,
                'shop_id'        => $shopNameMap[$empShop] ?? 0,
                'order_no'       => $ono,
                'emp_amount'     => $empOriginalPrice,
                'emp_rows'       => $g['rows'],
                'emp_date'       => $eo['order_date'],
                'emp_order_id'   => $eo['id'],
                'emp_name'       => $eo['emp_name'],
                'employee_id'    => $eo['employee_id'],
                'shop_amount'    => null,
                'shop_date'      => null,
                'shop_order_id'  => null,
                'diff_type'      => 'missing',
                'diff_amount'    => $empOriginalPrice,
            ];
            if (!isset($shopStats[$empShop])) {
                $shopStats[$empShop] = ['shop_name' => $empShop, 'shop_id' => $shopNameMap[$empShop] ?? 0, 'missing' => 0, 'mismatch' => 0, 'total' => 0];
            }
            $shopStats[$empShop]['missing']++;
            $shopStats[$empShop]['total']++;
            continue;
        }

        // 第 2 步·金额匹配：用求和合计与该店铺订单金额（售价）对比
        $shopOriginalPrice = $so['shop_orig_price'] !== null && (float)$so['shop_orig_price'] > 0
            ? (float)$so['shop_orig_price'] : (float)$so['order_amount'];
        $diff = round($empOriginalPrice - $shopOriginalPrice, 2);

        if (abs($diff) <= 0.001) {
            // 金额一致 = match，不记录
            continue;
        }

        // 金额不一致（按售价对比）
        $items[] = [
            'shop_name'     => $empShop,
            'shop_id'       => $shopNameMap[$empShop] ?? 0,
            'order_no'      => $ono,
            'emp_amount'    => $empOriginalPrice,
            'emp_rows'      => $g['rows'],
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

    $result = ['items' => $items, 'shops' => $shopStats];

    // 写入缓存
    if (is_dir($cacheDir)) {
        @file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_UNICODE));
    }

    return $result;
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
        $stmt->closeCursor();
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

/* ==================== 客服绩效（cs_perf） ==================== */

/**
 * 确保客服绩效相关表与员工旺旺字段存在（运行时自动建表/补列）。
 * 客服绩效采集工具上传的数据与绩效底薪模块共用这些表。
 */
function ensureCsPerfSchema()
{
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();

    // 1. employees 增加旺旺账号列（用于采集文件匹配员工）
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `employees` LIKE 'wangwang'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE `employees` ADD COLUMN `wangwang` VARCHAR(100) DEFAULT '' COMMENT '旺旺账号(客服绩效采集用)' AFTER `department`");
        }
    } catch (\Throwable $e) {}

    // 2. 客服绩效主表（每人每月一条，UNIQUE 覆盖式导入）
    try {
        $pdo->query("SELECT 1 FROM `customer_service_performance` LIMIT 1");
    } catch (\Throwable $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `customer_service_performance` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `employee_id` INT NOT NULL COMMENT '员工ID',
            `year` SMALLINT NOT NULL,
            `month` TINYINT NOT NULL,
            `reply_speed` DECIMAL(8,1) NOT NULL DEFAULT 0 COMMENT '平均回复速度(秒)',
            `incoming_count` INT NOT NULL DEFAULT 0 COMMENT '进线会话数',
            `deal_count` INT NULL DEFAULT NULL COMMENT '成交数(空=按订单自动,非空=手动覆盖)',
            `remark` VARCHAR(500) DEFAULT '' COMMENT '备注',
            `source_file` VARCHAR(255) DEFAULT '' COMMENT '最近来源文件',
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_emp_month` (`employee_id`, `year`, `month`),
            INDEX `idx_ym` (`year`, `month`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT '客服绩效采集数据'");
    }

    // 3. 未匹配员工暂存表（等待在管理页补录归属员工）
    try {
        $pdo->query("SELECT 1 FROM `cs_perf_pending` LIMIT 1");
    } catch (\Throwable $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `cs_perf_pending` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `wangwang` VARCHAR(100) DEFAULT '' COMMENT '文件中旺旺账号',
            `name` VARCHAR(100) DEFAULT '' COMMENT '文件中姓名',
            `year` SMALLINT NOT NULL,
            `month` TINYINT NOT NULL,
            `incoming_count` INT NOT NULL DEFAULT 0,
            `total_reply_seconds` DECIMAL(12,1) NOT NULL DEFAULT 0,
            `source_file` VARCHAR(255) DEFAULT '',
            `raw_json` TEXT NULL COMMENT '原始行',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_ym` (`year`, `month`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT '客服绩效未匹配暂存'");
    }

    // 4. 同步日志
    try {
        $pdo->query("SELECT 1 FROM `cs_perf_sync_log` LIMIT 1");
    } catch (\Throwable $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `cs_perf_sync_log` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `source_file` VARCHAR(255) DEFAULT '',
            `matched` INT NOT NULL DEFAULT 0,
            `pending` INT NOT NULL DEFAULT 0,
            `errors` INT NOT NULL DEFAULT 0,
            `detail` TEXT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT '客服绩效同步日志'");
    }

    // 5. 绩效人员名单：绩效底薪只统计名单内员工；页面可自定义增删
    $membersExists = true;
    try {
        $pdo->query("SELECT 1 FROM `cs_perf_members` LIMIT 1");
    } catch (\Throwable $e) {
        $membersExists = false;
        $pdo->exec("CREATE TABLE IF NOT EXISTS `cs_perf_members` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `employee_id` INT NOT NULL COMMENT '员工ID',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_emp` (`employee_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT '客服绩效名单(仅此名单内员工参与绩效底薪)'");
    }
    // 仅首次建表时自动纳入：网站客服/设计客服 部门 + 郭文娟、刘媛媛；之后全部手动增删，不会重新自动添加
    if (!$membersExists) {
        $pdo->exec("INSERT IGNORE INTO `cs_perf_members` (employee_id)
            SELECT id FROM `employees`
            WHERE department IN ('网站客服', '设计客服') OR name IN ('郭文娟', '刘媛媛')");
    }
}

/**
 * 绩效名单内的员工（仅这些人参与客服绩效底薪；页面可自定义增删）
 * @return array
 */
function get_cs_perf_members()
{
    try {
        return db()->query("SELECT e.* FROM employees e INNER JOIN cs_perf_members m ON m.employee_id = e.id ORDER BY e.department, e.id")->fetchAll();
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * 加入绩效名单（去重）
 */
function add_cs_perf_member($employeeId)
{
    try {
        return (bool)db()->prepare("INSERT IGNORE INTO cs_perf_members (employee_id) VALUES (?)")->execute([(int)$employeeId]);
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * 从绩效名单移除
 */
function remove_cs_perf_member($employeeId)
{
    try {
        return (bool)db()->prepare("DELETE FROM cs_perf_members WHERE employee_id=?")->execute([(int)$employeeId]);
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * 是否在绩效名单内（绩效底薪仅名单内员工参与）
 */
function is_cs_perf_member($employeeId)
{
    try {
        $st = db()->prepare("SELECT 1 FROM cs_perf_members WHERE employee_id=? LIMIT 1");
        $st->execute([(int)$employeeId]);
        return (bool)$st->fetchColumn();
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * 读取某员工某月客服绩效
 */
function get_cs_performance($employeeId, $year, $month)
{
    try {
        $stmt = db()->prepare("SELECT * FROM customer_service_performance WHERE employee_id=? AND year=? AND month=?");
        $stmt->execute([(int)$employeeId, (int)$year, (int)$month]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * 实时统计某客服员工当月成交订单数（与薪资结算的核验月/未核验规则一致）。
 * 成交 = 该员工名下当月、已核验、非异常、非软删除、金额>=0 且非退款的订单数。
 * @return int
 */
function get_employee_deal_count($employeeId, $year, $month)
{
    try {
        $monthStr = sprintf('%04d-%02d', (int)$year, (int)$month);
        $credit = "(JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.__verified_month__')) = ?"
            . " OR ((raw_data IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.__verified_month__')) IS NULL"
            . "   OR JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.__verified_month__')) = '')"
            . "  AND (raw_data IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.__order_status__')) IS NULL"
            . "   OR JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.__order_status__')) = ''"
            . "   OR JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.__order_status__')) <> '未核验')"
            . "  AND DATE_FORMAT(order_date, '%Y-%m') = ?))";
        $stmt = db()->prepare("SELECT o.order_amount, o.raw_data FROM orders o"
            . " WHERE o.employee_id=? AND $credit AND COALESCE(o.is_abnormal,0)=0"
            . " AND COALESCE(o.is_deleted,0)=0"
            . " AND (o.raw_data IS NULL OR o.raw_data NOT LIKE '%\"__from_dept__\"%')");
        $stmt->execute([(int)$employeeId, $monthStr, $monthStr]);
        $rows = $stmt->fetchAll();
        $count = 0;
        foreach ($rows as $r) {
            if ((float)$r['order_amount'] < 0) continue; // 退款(负数)不计
            $rd = is_string($r['raw_data'] ?? '') ? json_decode($r['raw_data'], true) : ($r['raw_data'] ?? []);
            if (is_array($rd) && isset($rd['__is_refund__']) && $rd['__is_refund__'] === '1') continue;
            $count++;
        }
        return $count;
    } catch (\Throwable $e) {
        return 0;
    }
}

/**
 * 从 CSV 文本识别列位置（模糊匹配）
 * @param array $header 表头行（已转UTF-8）
 * @return array 标准列名 => 列索引（找不到则为 null）
 */
function detect_cs_perf_columns($header)
{
    $map = ['name'=>null,'wangwang'=>null,'date'=>null,'year'=>null,'month'=>null,
            'incoming'=>null,'total_sec'=>null,'reply_speed'=>null,'reply_count'=>null];
    foreach ($header as $i => $cell) {
        $c = mb_strtolower(trim((string)$cell));
        $c = str_replace([' ', "\xEF\xBB\xBF"], '', $c);
        $hasWW  = (strpos($c, '旺旺') !== false || $c === 'wangwang' || $c === 'wangwangno');
        $hasKefu = (strpos($c, '客服') !== false || strpos($c, '姓名') !== false || strpos($c, '员工') !== false || $c === 'name' || $c === 'employee');
        if ($map['wangwang'] === null && $hasWW) $map['wangwang'] = $i;
        if ($map['name'] === null && $hasKefu && !$hasWW) $map['name'] = $i;
        if ($map['date'] === null && (strpos($c, '日期') !== false || strpos($c, 'date') !== false)) $map['date'] = $i;
        if ($map['year'] === null && (strpos($c, '年份') !== false || $c === 'year')) $map['year'] = $i;
        if ($map['month'] === null && (strpos($c, '月份') !== false || $c === 'month')) $map['month'] = $i;
        // 接待/进线人数
        if ($map['incoming'] === null && (strpos($c, '接待') !== false || strpos($c, '进线') !== false || strpos($c, '会话') !== false || strpos($c, '咨询') !== false || strpos($c, '人数') !== false || strpos($c, '买家数') !== false)) $map['incoming'] = $i;
        // 总回复时长（秒）
        if ($map['total_sec'] === null && (strpos($c, '总秒') !== false || strpos($c, '总回复') !== false || strpos($c, '回复秒数') !== false || strpos($c, '回复总时长') !== false)) $map['total_sec'] = $i;
        if ($map['reply_count'] === null && (strpos($c, '回复次数') !== false || strpos($c, '回复条数') !== false || $c === 'replycount' || $c === 'replycounts')) $map['reply_count'] = $i;
        // 平均回复/响应时长（千牛官方导出常用「平均响应时长」「平均首次响应时长」）
        if ($map['reply_speed'] === null && (strpos($c, '响应时长') !== false || strpos($c, '响应时间') !== false || strpos($c, '回复时长') !== false || strpos($c, '平均回复') !== false || strpos($c, '回复速度') !== false || strpos($c, '首次响应') !== false)) $map['reply_speed'] = $i;
    }
    return $map;
}

/**
 * 把导出的时长文本转成秒。兼容千牛/官方导出常见格式：
 *   "00:01:30" / "3:25"（时:分:秒 或 分:秒）
 *   "1分30秒" / "1分钟30秒" / "90秒" / "90"
 *   "1分 30秒" 等含空白/中文
 * @param mixed $raw
 * @return float 秒；无法解析返回 0
 */
function parse_duration_to_seconds($raw)
{
    $s = trim((string)$raw);
    if ($s === '' || $s === '-') return 0.0;
    // 冒号格式：HH:MM:SS 或 MM:SS
    if (strpos($s, ':') !== false) {
        $parts = array_map('intval', explode(':', $s));
        $n = count($parts);
        if ($n === 3) return (float)($parts[0]*3600 + $parts[1]*60 + $parts[2]);
        if ($n === 2) return (float)($parts[0]*60 + $parts[1]);
    }
    // 中文：X分Y秒 / X分钟Y秒 / X秒 / X时...
    if (mb_strpos($s, '秒') !== false || mb_strpos($s, '分') !== false || mb_strpos($s, '时') !== false) {
        $h = 0; $m = 0; $sec = 0;
        if (preg_match('#(\d+(?:\.\d+)?)\s*秒#u', $s, $mm)) $sec = (float)$mm[1];
        if (preg_match('#(\d+(?:\.\d+)?)\s*分#u', $s, $mm)) $m = (float)$mm[1];
        if (preg_match('#(\d+(?:\.\d+)?)\s*时#u', $s, $mm)) $h = (float)$mm[1];
        if ($h > 0 || $m > 0 || $sec > 0) return (float)($h*3600 + $m*60 + $sec);
    }
    // 纯数字 → 秒
    if (preg_match('#^\d+(\.\d+)?$#', str_replace(',', '', $s))) return (float)str_replace(',', '', $s);
    return 0.0;
}

/**
 * 逐行解析 CSV（兼容 PHP 7.4 str_getcsv/fgetcsv 对 UTF-8 多字节中文字段的解析 bug）。
 * 支持标准双引号包裹字段与 "" 转义引号；未闭合引号按普通字符处理。
 * @param string $line
 * @return array
 */
function csv_parse_line($line)
{
    $fields = [];
    $cur = '';
    $len = strlen($line);
    $inQuotes = false;
    $i = 0;
    while ($i < $len) {
        $ch = $line[$i];
        if ($inQuotes) {
            if ($ch === '"') {
                if ($i + 1 < $len && $line[$i+1] === '"') { $cur .= '"'; $i += 2; continue; }
                $inQuotes = false; $i++; continue;
            }
            $cur .= $ch; $i++; continue;
        }
        if ($ch === '"') { $inQuotes = true; $i++; continue; }
        if ($ch === ',') { $fields[] = $cur; $cur = ''; $i++; continue; }
        $cur .= $ch; $i++;
    }
    $fields[] = $cur; // 最后一段（含可能存在的空尾段）
    return $fields;
}

/**
 * 归一化单个旺旺账号：兼容「店铺名:账号」或「纯账号」写法，取冒号后的账号（如 美呀美旗舰店:依蝶雅涵涵 → 依蝶雅涵涵）。
 */
function normalize_cs_wangwang($s)
{
    $s = trim((string)$s);
    if ($s === '') return '';
    if (strpos($s, ':') !== false || strpos($s, '：') !== false) {
        $parts = explode(':', str_replace('：', ':', $s));
        if (count($parts) > 1) $s = trim((string)end($parts));
    }
    return $s;
}

/**
 * 导入一份客服绩效数据文件（采集工具上传 / 千牛官网导出 / 计划任务扫描共用）。
 *
 * 自动识别列名（含千牛/官方导出：客服、接待人数、平均响应时长等），
 * 时长支持 "HH:MM:SS" / "X分X秒" / 纯秒 等格式。
 * 文件里没有日期列的月度汇总表，可传入 $defaultYear/$defaultMonth 归到指定月份。
 * 未匹配到员工的行暂存 cs_perf_pending。
 *
 * @param string $filePath 文件绝对路径
 * @param string $source   来源标识（文件名/说明）
 * @param int    $defaultYear  文件无日期时使用的年份（0=必须从文件取）
 * @param int    $defaultMonth 文件无日期时使用的月份（0=必须从文件取）
 * @return array ['matched'=>n,'pending'=>n,'errors'=>n,'detail'=>[每员工写入说明]]
 */
function import_cs_perf_file($filePath, $source = '', $defaultYear = 0, $defaultMonth = 0)
{
    if ($source === '') $source = basename($filePath);

    // 读文件并转为 UTF-8（兼容 GBK）
    $content = @file_get_contents($filePath);
    if ($content === false || trim($content) === '') {
        return ['matched'=>0,'pending'=>0,'errors'=>1,'detail'=>['文件为空或不可读']];
    }
    if (substr($content, 0, 3) === "\xEF\xBB\xBF") $content = substr($content, 3);
    if (!mb_check_encoding($content, 'UTF-8')) {
        $converted = @mb_convert_encoding($content, 'UTF-8', 'GBK');
        if ($converted !== false) $content = $converted;
    }

    $lines = [];
    foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
        if (trim($line) === '') continue;
        $lines[] = csv_parse_line(rtrim($line, "\r"));
    }
    if (count($lines) < 2) {
        return ['matched'=>0,'pending'=>0,'errors'=>1,'detail'=>['无数据行']];
    }

    $header = array_shift($lines);
    $cols = detect_cs_perf_columns($header);

    ensureCsPerfSchema();
    $pdo = db();

    // 员工映射：姓名精确、旺旺账号(大小写不敏感；一人可同时登录多个账号，用逗号/空格分隔，兼容「店铺名:账号」写法)
    // 注意：PHP7.4 的 preg_split 不带 /u 会破坏 UTF-8 中文，这里用字节安全的 str_replace+explode 拆分
    $byName = [];
    $byWang = [];
    foreach (get_employees() as $e) {
        if (trim($e['name']) !== '') $byName[trim($e['name'])] = $e;
        $wws = str_replace(['，','；',';',',',' ','　',"\t","\r","\n"], ',', (string)($e['wangwang'] ?? ''));
        foreach (explode(',', $wws) as $ww) {
            $ww = normalize_cs_wangwang($ww);
            if ($ww !== '') $byWang[mb_strtolower($ww)] = $e;
        }
    }

    // 聚合：monthAgg[key] = [emp, year, month, incoming, totalSec(sum模式), replySum/replyCnt(avg模式)]
    $monthAgg = [];
    $pendingKey = []; // 未匹配：key(wangwang|name+ym) => [aggregated]
    $errors = 0;

    foreach ($lines as $row) {
        $vals = array_pad($row, count($header), '');
        $name = isset($cols['name']) && $cols['name'] !== null && isset($vals[$cols['name']]) ? trim((string)$vals[$cols['name']]) : '';
        $wang = isset($cols['wangwang']) && $cols['wangwang'] !== null && isset($vals[$cols['wangwang']]) ? trim((string)$vals[$cols['wangwang']]) : '';
        $incoming = 0;
        $totalSec = 0.0;
        $replyCount = 0;
        $replySpeed = 0.0;
        if ($cols['incoming'] !== null && isset($vals[$cols['incoming']])) $incoming = (int)extract_amount($vals[$cols['incoming']]);
        if ($cols['total_sec'] !== null && isset($vals[$cols['total_sec']])) $totalSec = (float)extract_amount($vals[$cols['total_sec']]);
        if ($cols['reply_count'] !== null && isset($vals[$cols['reply_count']])) $replyCount = (int)extract_amount($vals[$cols['reply_count']]);
        // 平均回复/响应时长：可能是 HH:MM:SS、X分X秒、纯秒，统一转成秒
        if ($cols['reply_speed'] !== null && isset($vals[$cols['reply_speed']])) $replySpeed = (float)parse_duration_to_seconds($vals[$cols['reply_speed']]);

        // 日期 → 年月（文件无日期时用调用方给定的默认年月）
        $year = 0; $month = 0;
        if ($cols['date'] !== null && isset($vals[$cols['date']]) && trim((string)$vals[$cols['date']]) !== '') {
            $d = trim((string)$vals[$cols['date']]);
            if (preg_match('#(\d{4})[-/.年](\d{1,2})#', $d, $m)) { $year = (int)$m[1]; $month = (int)$m[2]; }
        }
        if ($year <= 0 && $cols['year'] !== null && isset($vals[$cols['year']])) $year = (int)extract_amount($vals[$cols['year']]);
        if ($month <= 0 && $cols['month'] !== null && isset($vals[$cols['month']])) $month = (int)extract_amount($vals[$cols['month']]);
        if ($year <= 0 && $defaultYear > 0) $year = (int)$defaultYear;
        if ($month <= 0 && $defaultMonth > 0) $month = (int)$defaultMonth;
        if ($year <= 0 || $month < 1 || $month > 12) { $errors++; continue; }

        // 匹配员工：优先旺旺账号(归一化)，其次姓名；导出表把账号填在「客服」列等情形再做兜底
        $emp = null;
        $wangKey = mb_strtolower(normalize_cs_wangwang($wang));
        if ($wangKey !== '' && isset($byWang[$wangKey])) $emp = $byWang[$wangKey];
        if ($emp === null && $name !== '' && isset($byName[$name])) $emp = $byName[$name];
        if ($emp === null && $name !== '' && isset($byWang[mb_strtolower(normalize_cs_wangwang($name))])) $emp = $byWang[mb_strtolower(normalize_cs_wangwang($name))];

        if ($emp === null) {
            // 未匹配 → 暂存
            $pk = ($wang !== '' ? 'w:' . $wangKey : 'n:' . $name) . "|$year-$month";
            if (!isset($pendingKey[$pk])) {
                $pendingKey[$pk] = ['wangwang'=>$wang,'name'=>$name,'year'=>$year,'month'=>$month,'incoming'=>0,'total'=>0.0,'replyCnt'=>0,'avgSum'=>0.0,'avgN'=>0];
            }
            $pendingKey[$pk]['incoming'] += $incoming;
            $pendingKey[$pk]['total'] += $totalSec;
            $pendingKey[$pk]['replyCnt'] += $replyCount;
            if ($replySpeed > 0) { $pendingKey[$pk]['avgSum'] += $replySpeed; $pendingKey[$pk]['avgN']++; }
            continue;
        }

        $ek = (int)$emp['id'] . "|$year-$month";
        if (!isset($monthAgg[$ek])) {
            $monthAgg[$ek] = ['emp'=>$emp,'year'=>$year,'month'=>$month,'incoming'=>0,'total'=>0.0,'replyCnt'=>0,'avgSum'=>0.0,'avgN'=>0];
        }
        $monthAgg[$ek]['incoming'] += $incoming;
        $monthAgg[$ek]['total'] += $totalSec;
        $monthAgg[$ek]['replyCnt'] += $replyCount;
        if ($replySpeed > 0) { $monthAgg[$ek]['avgSum'] += $replySpeed; $monthAgg[$ek]['avgN']++; }
    }

    $detail = [];

    // 已匹配 → 覆盖式写入主表（保留 remark / deal_count 手动值）
    $upsert = $pdo->prepare("INSERT INTO customer_service_performance
        (employee_id, year, month, reply_speed, incoming_count, source_file)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
        reply_speed=VALUES(reply_speed), incoming_count=VALUES(incoming_count), source_file=VALUES(source_file)");
    foreach ($monthAgg as $agg) {
        $incoming = $agg['incoming'];
        $replySpeed = 0.0;
        if ($agg['avgN'] > 0) {
            $replySpeed = round($agg['avgSum'] / $agg['avgN'], 1); // 优先直接用导出的平均回复/响应时长
        } elseif ($incoming > 0 && $agg['replyCnt'] > 0 && $agg['total'] > 0) {
            $replySpeed = round($agg['total'] / $agg['replyCnt'], 1); // 总回复秒 ÷ 回复条数 = 平均回复秒
        } elseif ($incoming > 0 && $agg['total'] > 0) {
            $replySpeed = round($agg['total'] / $incoming, 1); // 兼容无回复次数列的文件
        } elseif ($agg['avgN'] > 0) {
            $replySpeed = round($agg['avgSum'] / $agg['avgN'], 1); // 第三方平均回复速度列回退
        }
        $upsert->execute([(int)$agg['emp']['id'], $agg['year'], $agg['month'], $replySpeed, $incoming, $source]);
        $detail[] = sprintf('%s %04d-%02d 进线%d 回复%.1fs', $agg['emp']['name'], $agg['year'], $agg['month'], $incoming, $replySpeed);
    }

    // 未匹配 → 先清该 key 旧暂存再写入（避免重复上传叠加）
    $delPending = $pdo->prepare("DELETE FROM cs_perf_pending WHERE wangwang=? AND name=? AND year=? AND month=?");
    $insPending = $pdo->prepare("INSERT INTO cs_perf_pending
        (wangwang, name, year, month, incoming_count, total_reply_seconds, source_file, raw_json)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $pendingCount = 0;
    foreach ($pendingKey as $pk => $p) {
        $delPending->execute([$p['wangwang'], $p['name'], $p['year'], $p['month']]);
        $raw = json_encode(['wangwang'=>$p['wangwang'],'name'=>$p['name'],'year'=>$p['year'],'month'=>$p['month'],'incoming'=>$p['incoming'],'total_reply_seconds'=>$p['total']], JSON_UNESCAPED_UNICODE);
        $insPending->execute([$p['wangwang'], $p['name'], $p['year'], $p['month'], $p['incoming'], $p['total'], $source, $raw]);
        $pendingCount++;
        $detail[] = sprintf('未匹配暂存：%s %04d-%02d', $p['name'] !== '' ? $p['name'] : $p['wangwang'], $p['year'], $p['month']);
    }

    // 写同步日志
    $stmt = $pdo->prepare("INSERT INTO cs_perf_sync_log (source_file, matched, pending, errors, detail) VALUES (?,?,?,?,?)");
    $stmt->execute([$source, count($monthAgg), $pendingCount, $errors, json_encode($detail, JSON_UNESCAPED_UNICODE)]);

    return ['matched'=>count($monthAgg), 'pending'=>$pendingCount, 'errors'=>$errors, 'detail'=>$detail];
}

