<?php

/**
 * 客服绩效「请求内缓存池」。读函数把结果缓存于此，避免同一请求内重复远程查询；
 * 任何写操作（排除/恢复、方案配置、部门配置、导入、编辑补录、删除上传）调用 cs_perf_cache_reset() 清空，
 * 防止「POST 改库后同一请求继续渲染」读到旧值。
 */
function &cs_perf_cache($reset = false)
{
    static $store = null;
    if ($store === null) $store = [];
    if ($reset) $store = [];
    return $store;
}
function cs_perf_cache_get($key)
{
    $store =& cs_perf_cache();
    if (array_key_exists($key, $store)) return ['hit' => true, 'val' => $store[$key]];
    return ['hit' => false, 'val' => null];
}
function cs_perf_cache_set($key, $val)
{
    $store =& cs_perf_cache();
    $store[$key] = $val;
}
function cs_perf_cache_reset()
{
    cs_perf_cache(true);
}

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

    // 2. 客服绩效主表（每人每店每月一条，UNIQUE 覆盖式导入；店铺用于设计客服「多店合并÷2」排名）
    try {
        $pdo->query("SELECT 1 FROM `customer_service_performance` LIMIT 1");
    } catch (\Throwable $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `customer_service_performance` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `employee_id` INT NOT NULL COMMENT '员工ID',
            `store` VARCHAR(60) NOT NULL DEFAULT '' COMMENT '店铺（上传时手动选择；空=未分店）',
            `year` SMALLINT NOT NULL,
            `month` TINYINT NOT NULL,
            `reply_speed` DECIMAL(8,1) NOT NULL DEFAULT 0 COMMENT '平均响应时长(秒)',
            `incoming_count` INT NOT NULL DEFAULT 0 COMMENT '进线会话数',
            `net_sales` DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT '净销售额(元)',
            `inquiry_conv` DECIMAL(6,2) NOT NULL DEFAULT 0 COMMENT '询单最终下单转化率(%)',
            `wangwang_reply` DECIMAL(6,2) NOT NULL DEFAULT 0 COMMENT '旺旺回复率(%)',
            `deal_count` INT NULL DEFAULT NULL COMMENT '成交数(空=按订单自动,非空=手动覆盖)',
            `remark` VARCHAR(500) DEFAULT '' COMMENT '备注',
            `source_file` VARCHAR(255) DEFAULT '' COMMENT '最近来源文件',
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_emp_store_month` (`employee_id`, `store`, `year`, `month`),
            INDEX `idx_ym` (`year`, `month`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT '客服绩效采集数据'");
    }

    // 2.1 已存在的绩效主表补齐新指标列（幂等）
    $perfCols = [];
    try { foreach ($pdo->query("SHOW COLUMNS FROM `customer_service_performance`")->fetchAll() as $c) $perfCols[$c['Field']] = true; } catch (\Throwable $e) {}
    foreach ([
        'net_sales'       => "DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT '净销售额(元)'",
        'inquiry_conv'    => "DECIMAL(6,2) NOT NULL DEFAULT 0 COMMENT '询单最终下单转化率(%)'",
        'wangwang_reply'  => "DECIMAL(6,2) NOT NULL DEFAULT 0 COMMENT '旺旺回复率(%)'",
        'order_count'     => "INT NOT NULL DEFAULT 0 COMMENT '下单人数(转化率分子)'",
    ] as $col => $def) {
        if (isset($perfCols[$col])) continue;
        try { $pdo->exec("ALTER TABLE `customer_service_performance` ADD COLUMN `$col` $def"); } catch (\Throwable $e) {}
    }
    // 2.2 主表补齐「店铺」列并把唯一键改为(员工,店铺,年月)：一人可同时上传/匹配多个店铺，合并时按店铺分开存
    if (!isset($perfCols['store'])) {
        try { $pdo->exec("ALTER TABLE `customer_service_performance` ADD COLUMN `store` VARCHAR(60) NOT NULL DEFAULT '' COMMENT '店铺（上传时手动选择；空=未分店）' AFTER `employee_id`"); } catch (\Throwable $e) {}
    }
    try {
        $hasNewKey = false;
        foreach ($pdo->query("SHOW INDEX FROM `customer_service_performance`")->fetchAll() as $ix) {
            if ($ix['Key_name'] === 'uk_emp_store_month') { $hasNewKey = true; break; }
        }
        if (!$hasNewKey) {
            $pdo->exec("ALTER TABLE `customer_service_performance` DROP INDEX `uk_emp_month`"); // 旧键（已由上面新键替换语义）
            $pdo->exec("ALTER TABLE `customer_service_performance` ADD UNIQUE KEY `uk_emp_store_month` (`employee_id`, `store`, `year`, `month`)");
        }
    } catch (\Throwable $e) {}

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
            `net_sales` DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT '净销售额(元)',
            `inquiry_conv` DECIMAL(6,2) NOT NULL DEFAULT 0 COMMENT '询单最终下单转化率(%)',
            `wangwang_reply` DECIMAL(6,2) NOT NULL DEFAULT 0 COMMENT '旺旺回复率(%)',
            `source_file` VARCHAR(255) DEFAULT '',
            `raw_json` TEXT NULL COMMENT '原始行',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_ym` (`year`, `month`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT '客服绩效未匹配暂存'");
    }
    // 3.1 已存在的待匹配表补齐新列（幂等）
    $pendCols = [];
    try { foreach ($pdo->query("SHOW COLUMNS FROM `cs_perf_pending`")->fetchAll() as $c) $pendCols[$c['Field']] = true; } catch (\Throwable $e) {}
    foreach ([
        'net_sales'      => "DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT '净销售额(元)'",
        'inquiry_conv'   => "DECIMAL(6,2) NOT NULL DEFAULT 0 COMMENT '询单最终下单转化率(%)'",
        'wangwang_reply' => "DECIMAL(6,2) NOT NULL DEFAULT 0 COMMENT '旺旺回复率(%)'",
        'order_count'    => "INT NOT NULL DEFAULT 0 COMMENT '下单人数(转化率分子)'",
    ] as $col => $def) {
        if (isset($pendCols[$col])) continue;
        try { $pdo->exec("ALTER TABLE `cs_perf_pending` ADD COLUMN `$col` $def"); } catch (\Throwable $e) {}
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

    // 5. 绩效名单（旧表）：绩效参与改由「部门配置」自动生成，此表仅用于维护「被排除」的员工
    $membersExists = true;
    try {
        $pdo->query("SELECT 1 FROM `cs_perf_members` LIMIT 1");
    } catch (\Throwable $e) {
        $membersExists = false;
        $pdo->exec("CREATE TABLE IF NOT EXISTS `cs_perf_members` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `employee_id` INT NOT NULL COMMENT '员工ID',
            `is_excluded` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否排除参与绩效(1=排除)',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_emp` (`employee_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT '客服绩效排除名单(1=排除,不再参与部门绩效底薪)'");
    }
    // 5.1 旧表补齐「排除」标记列（幂等）：历史名单记录视为参与标记，仅 is_excluded=1 才是被排除
    try {
        $memberCols = [];
        foreach ($pdo->query("SHOW COLUMNS FROM `cs_perf_members`")->fetchAll() as $c) $memberCols[$c['Field']] = true;
        if (!isset($memberCols['is_excluded'])) {
            $pdo->exec("ALTER TABLE `cs_perf_members` ADD COLUMN `is_excluded` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否排除参与绩效(1=排除)'");
        }
    } catch (\Throwable $e) {}
    // 仅首次建表时做一次兼容标记（旧的自动纳入名单概念已由部门配置取代）；之后全部手动增删
    if (!$membersExists) {
        $pdo->exec("INSERT IGNORE INTO `cs_perf_members` (employee_id, is_excluded)
            SELECT id, 0 FROM `employees`
            WHERE department IN ('网站客服', '设计客服') OR name IN ('郭文娟', '刘媛媛')");
    }

    // 6. 绩效方案（算法）：绩效基数之外的指标权重/目标/金额阶梯
    $schemesExists = true;
    try {
        $pdo->query("SELECT 1 FROM `cs_perf_schemes` LIMIT 1");
    } catch (\Throwable $e) {
        $schemesExists = false;
        $pdo->exec("CREATE TABLE IF NOT EXISTS `cs_perf_schemes` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(100) NOT NULL COMMENT '方案名称',
            `weight_reply` DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT '回复速度权重(旧版保留)',
            `target_reply_sec` DECIMAL(8,1) NOT NULL DEFAULT 0 COMMENT '目标回复速度(秒)(旧版保留)',
            `weight_incoming` DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT '接待人数权重(旧版保留)',
            `target_incoming` INT NOT NULL DEFAULT 0 COMMENT '目标进线人数(旧版保留)',
            `weight_conv` DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT '转化率权重(旧版保留)',
            `target_conversion_pct` DECIMAL(6,2) NOT NULL DEFAULT 0 COMMENT '目标转化率(%)(旧版保留)',
            `weight_amount` DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT '接单金额权重(旧版保留)',
            `amount_tiers` TEXT NULL COMMENT '金额阶梯JSON(旧版保留)',
            `w_net_sales` DECIMAL(5,2) NOT NULL DEFAULT 43 COMMENT '净销售额权重',
            `t_net_sales` DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT '目标净销售额(元)',
            `w_inquiry_conv` DECIMAL(5,2) NOT NULL DEFAULT 30 COMMENT '询单最终下单转化率权重',
            `t_inquiry_conv` DECIMAL(6,2) NOT NULL DEFAULT 0 COMMENT '目标询单转化率(%)',
            `w_wangwang_reply` DECIMAL(5,2) NOT NULL DEFAULT 17 COMMENT '旺旺回复率权重',
            `t_wangwang_reply` DECIMAL(6,2) NOT NULL DEFAULT 0 COMMENT '目标旺旺回复率(%)',
            `w_avg_response` DECIMAL(5,2) NOT NULL DEFAULT 15 COMMENT '平均响应时长权重',
            `t_avg_response` DECIMAL(8,1) NOT NULL DEFAULT 0 COMMENT '目标平均响应时长(秒)',
            `floor_pct` DECIMAL(6,2) NOT NULL DEFAULT 0 COMMENT '保底(基数的%)',
            `cap_pct` DECIMAL(6,2) NOT NULL DEFAULT 0 COMMENT '封顶(基数的%)',
            `is_default` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '默认方案',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT '客服绩效方案(算法)'");
    }
    // 6.1 已存在的方案表补齐新指标列（幂等；默认权重参考：净销售额43/询单转化率30/旺旺回复率17/平均响应15）
    $schemeCols = [];
    try { foreach ($pdo->query("SHOW COLUMNS FROM `cs_perf_schemes`")->fetchAll() as $c) $schemeCols[$c['Field']] = true; } catch (\Throwable $e) {}
    foreach ([
        'w_net_sales'      => "DECIMAL(5,2) NOT NULL DEFAULT 43 COMMENT '净销售额权重'",
        't_net_sales'      => "DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT '目标净销售额(元)'",
        'w_inquiry_conv'   => "DECIMAL(5,2) NOT NULL DEFAULT 30 COMMENT '询单最终下单转化率权重'",
        't_inquiry_conv'   => "DECIMAL(6,2) NOT NULL DEFAULT 0 COMMENT '目标询单转化率(%)'",
        'w_wangwang_reply' => "DECIMAL(5,2) NOT NULL DEFAULT 17 COMMENT '旺旺回复率权重'",
        't_wangwang_reply' => "DECIMAL(6,2) NOT NULL DEFAULT 0 COMMENT '目标旺旺回复率(%)'",
        'w_avg_response'   => "DECIMAL(5,2) NOT NULL DEFAULT 15 COMMENT '平均响应时长权重'",
        't_avg_response'   => "DECIMAL(8,1) NOT NULL DEFAULT 0 COMMENT '目标平均响应时长(秒)'",
    ] as $col => $def) {
        if (isset($schemeCols[$col])) continue;
        try { $pdo->exec("ALTER TABLE `cs_perf_schemes` ADD COLUMN `$col` $def"); } catch (\Throwable $e) {}
    }
    // 6.2 档位区间列（每个指标可配置多个「区间下限~上限 → 达成率%」，JSON 存储，空串=未配置）
    foreach ([
        'tiers_net_sales'      => "TEXT NULL COMMENT '净销售额档位JSON [{\"from\":0,\"to\":60000,\"rate\":50},...]'",
        'tiers_inquiry_conv'   => "TEXT NULL COMMENT '询单转化率档位JSON'",
        'tiers_wangwang_reply' => "TEXT NULL COMMENT '旺旺回复率档位JSON'",
        'tiers_avg_response'   => "TEXT NULL COMMENT '平均响应时长档位JSON'",
    ] as $col => $def) {
        if (isset($schemeCols[$col])) continue;
        try { $pdo->exec("ALTER TABLE `cs_perf_schemes` ADD COLUMN `$col` $def"); } catch (\Throwable $e) {}
    }
    // 仅首次建表时创建默认方案，便于直接沿用/修改
    if (!$schemesExists) {
        $pdo->exec("INSERT IGNORE INTO `cs_perf_schemes`
            (id, name, w_net_sales, w_inquiry_conv, w_wangwang_reply, w_avg_response, is_default)
            VALUES (1, '默认方案', 43, 30, 17, 15, 1)");
    }

    // 7. 部门基数配置：绩效基数按部门设置（部门可自定义添加）
    try {
        $pdo->query("SELECT 1 FROM `cs_perf_dept_config` LIMIT 1");
    } catch (\Throwable $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `cs_perf_dept_config` (
            `department` VARCHAR(100) NOT NULL PRIMARY KEY COMMENT '部门名称',
            `scheme_id` INT NOT NULL DEFAULT 1 COMMENT '绩效方案ID',
            `base` DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '绩效基数(元)',
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT '客服绩效部门基数配置'");
    }
}
