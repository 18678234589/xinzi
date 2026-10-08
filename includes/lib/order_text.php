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
