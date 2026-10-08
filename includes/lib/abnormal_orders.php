<?php

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
    $cacheDir = (dirname(__DIR__, 1)) . '/../storage/abnormal_cache';
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
    $schemaFlag = (dirname(__DIR__, 1)) . '/../storage/.schema_checked';
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
