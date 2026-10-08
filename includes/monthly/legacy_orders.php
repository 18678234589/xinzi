<?php

/**
 * 原系统单量补贴（legacy_sheet）的订单范围：精确复制 salaries/settle.php loadEmployeeOrdersWithDept 的取数——
 * 1) 本人当月个人订单（按核验月归属；排除异常、已删除、旧部门物理拆分行）；
 * 2) 所在部门汇总行（employee_id=0、order_scope='department'、__dept__=部门名）：本人出现在 __dept_modules__ 时虚拟拆分一行；
 * 3)（可选）后端/技术合作人员：raw_data."后端"（或环境配置订单 raw_data."技术"）= 本人的订单，按 id 去重追加。
 * 与旧引擎 $c['orders'] 同构，供门控与计列扫描（含 raw_data、order_no、order_amount）。
 */
function ps_legacy_sheet_orders($month, $employeeId, $deptName, $backendName = '', $allowNoReceipt = false)
{
    static $cache = [];
    $key = $month . '|' . $employeeId . '|' . $deptName . '|' . $backendName . '|' . (int)$allowNoReceipt;
    if (isset($cache[$key])) return $cache[$key];
    // 计薪月份 = 核验月：未核验订单不计入；遗留数据（无核验标记且已核验）按 order_date 归月
    $creditSql = "(JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.__verified_month__')) = ?"
        . " OR ("
        . "  (raw_data IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.__verified_month__')) IS NULL"
        . "   OR JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.__verified_month__')) = '')"
        . "  AND (raw_data IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.__order_status__')) IS NULL"
        . "   OR JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.__order_status__')) = ''"
        . "   OR JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.__order_status__')) <> '未核验')"
        . "  AND DATE_FORMAT(order_date, '%Y-%m') = ?"
        . " ))";
    if ($allowNoReceipt) {
        // 月度单量不依赖交易流水；已归属其他核验月的订单不能跨月再计一次。
        $creditSql = "(JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.__verified_month__')) = ? OR ("
            . "(raw_data IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.__verified_month__')) IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.__verified_month__'))='')"
            . " AND DATE_FORMAT(order_date,'%Y-%m')=?))";
    }
    $ostmt = db()->prepare(
        "SELECT *, order_amount, order_date, project FROM orders
         WHERE employee_id = ? AND $creditSql
         AND COALESCE(is_abnormal, 0) = 0
         AND COALESCE(is_deleted, 0) = 0
         AND (raw_data IS NULL OR raw_data NOT LIKE '%\"__from_dept__\"%')
         ORDER BY order_date"
    );
    $ostmt->execute([$employeeId, $month, $month]);
    $orders = $ostmt->fetchAll();
    if ($deptName !== '') {
        // 部门订单不按 is_abnormal 过滤：部门汇总行是分成基数来源，异常标记是其固有特征
        // 部门名走 gen_dept 虚拟列索引（2026-10 性能修复：原 JSON_EXTRACT 全表扫 9.8 万行要 14s，导致报酬页 504）
        $dstmt = db()->prepare(
            "SELECT *, order_amount, order_date, project, raw_data FROM orders
             WHERE employee_id = 0 AND order_scope = 'department'
             AND $creditSql
             AND COALESCE(is_deleted, 0) = 0
             AND gen_dept = ?"
        );
        $dstmt->execute([$month, $month, $deptName]);
        foreach ($dstmt->fetchAll() as $do) {
            $rd = is_string($do['raw_data'] ?? '') ? json_decode($do['raw_data'], true) : ($do['raw_data'] ?? []);
            if (!is_array($rd)) $rd = [];
            $modules = $rd['__dept_modules__'] ?? null;
            if (!is_array($modules)) continue;
            $myModule = null;
            foreach ($modules as $m) {
                if ((int)($m['employee_id'] ?? 0) === (int)$employeeId) {
                    $myModule = trim($m['module'] ?? '');
                    break;
                }
            }
            if ($myModule === null) continue; // 本人不参与此部门订单
            $vRaw = $rd;
            $vRaw['__from_dept__'] = $deptName;
            $virtualRow = $do;
            $virtualRow['employee_id'] = $employeeId;
            $virtualRow['project'] = $myModule;
            $virtualRow['raw_data'] = json_encode($vRaw, JSON_UNESCAPED_UNICODE);
            $orders[] = $virtualRow;
        }
    }
    if ($backendName !== '') {
        // 后端/技术名走 gen_backend、gen_tech 虚拟列索引（2026-10 性能修复：原 JSON_EXTRACT 全表扫）
        $bstmt = db()->prepare(
            "SELECT *, order_amount, order_date, project FROM orders
             WHERE $creditSql
             AND COALESCE(is_deleted, 0) = 0
             AND ( gen_backend = ?
                   OR (project LIKE '%环境配置%' AND gen_tech = ?) )
             ORDER BY order_date"
        );
        $bstmt->execute([$month, $month, $backendName, $backendName]);
        $seen = [];
        foreach ($orders as $o) $seen[(int)$o['id']] = true;
        foreach ($bstmt->fetchAll() as $bo) {
            if (isset($seen[(int)$bo['id']])) continue;
            $seen[(int)$bo['id']] = true;
            $orders[] = $bo;
        }
    }
    if ($allowNoReceipt && $deptName === '网站售后部') {
        require_once (dirname(__DIR__, 1)) . '/ProjectReviewPolicy.php';
        $orders = array_merge($orders, prp_project_quantity_rows($month,(int)$employeeId,$orders));
    }
    $cache[$key] = $orders;
    return $orders;
}
