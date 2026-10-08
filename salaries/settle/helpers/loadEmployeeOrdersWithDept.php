<?php
function loadEmployeeOrdersWithDept($employeeId, $month, $deptName, $deptShare = true, $backendName = '') {
    // 计薪月份 = 核验月：未核验订单不计入项目报酬；___verified_month__ = 当月 或 遗留(无标记且已核验)按 order_date 归月。
    // 未核验订单核验通过后才计入，归属核验时写入的 __verified_month__（核验当月），实现跨月晚核验计入当月的规则。
    $creditSql = "(JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.__verified_month__')) = ?"
        . " OR ("
        . "  (raw_data IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.__verified_month__')) IS NULL"
        . "   OR JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.__verified_month__')) = '')"
        . "  AND (raw_data IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.__order_status__')) IS NULL"
        . "   OR JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.__order_status__')) = ''"
        . "   OR JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.__order_status__')) <> '未核验')"
        . "  AND DATE_FORMAT(order_date, '%Y-%m') = ?"
        . " ))";

    // 1. 个人订单（排除旧的物理拆分行，避免与虚拟拆分重复；排除已软删除的）
    $ostmt = db()->prepare(
        "SELECT *, order_amount, order_date, project FROM orders
         WHERE employee_id = ? AND $creditSql
         AND COALESCE(is_abnormal, 0) = 0
         AND COALESCE(is_deleted, 0) = 0
         AND (raw_data IS NULL OR raw_data NOT LIKE '%\"__from_dept__\"%')
         ORDER BY order_date"
    );
    $ostmt->execute([$employeeId, $month, $month]);
    $personalOrders = $ostmt->fetchAll();

    $orders = $personalOrders;

    // 2. 部门订单虚拟拆分
    if ($deptShare && $deptName !== '') {
        // 用 JSON_EXTRACT 精确匹配部门名
        // 注意：部门订单不按 is_abnormal 过滤——部门汇总订单是项目分成基数来源，必须参与计算
        // （异常检测标记为"金额不符"是部门汇总的固有特征）。但按核验月/未核验过滤，未核验不计薪
        $dstmt = db()->prepare(
            "SELECT *, order_amount, order_date, project, raw_data FROM orders
             WHERE employee_id = 0 AND order_scope = 'department'
             AND $creditSql
             AND COALESCE(is_deleted, 0) = 0
             AND JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.__dept__')) = ?"
        );
        $dstmt->execute([$month, $month, $deptName]);
        $deptOrders = $dstmt->fetchAll();

        foreach ($deptOrders as $do) {
            $rd = is_string($do['raw_data'] ?? '') ? json_decode($do['raw_data'], true) : ($do['raw_data'] ?? []);
            if (!is_array($rd)) $rd = [];
            $modules = $rd['__dept_modules__'] ?? null;
            if (!is_array($modules)) continue;

            // 找到该合作人员的模块
            $myModule = null;
            foreach ($modules as $m) {
                if ((int)($m['employee_id'] ?? 0) === (int)$employeeId) {
                    $myModule = trim($m['module'] ?? '');
                    break;
                }
            }
            if ($myModule === null) continue; // 该合作人员不参与此部门订单

            // 虚拟生成拆分行
            $vRaw = $rd;
            $vRaw['__from_dept__'] = $deptName;
            $virtualRow = $do;
            $virtualRow['employee_id'] = $employeeId;
            $virtualRow['project']     = $myModule;
            $virtualRow['raw_data']    = json_encode($vRaw, JSON_UNESCAPED_UNICODE);
            $orders[] = $virtualRow;
        }
    }

    // 3. 后端/技术合作人员：追加 raw_data.后端 或（环境配置项目的）raw_data.技术 = 合作人员名 的订单，
    //    本人名下可能无订单行，按其姓名参与汇总，按 id 去重避免重复计入
    if ($backendName !== '') {
        $bstmt = db()->prepare(
            "SELECT *, order_amount, order_date, project FROM orders
             WHERE $creditSql
             AND COALESCE(is_deleted, 0) = 0
             AND ( JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.\"后端\"')) = ?
                   OR (project LIKE '%环境配置%' AND JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.\"技术\"')) = ?) )
             ORDER BY order_date"
        );
        $bstmt->execute([$month, $month, $backendName, $backendName]);
        $seen = [];
        foreach ($orders as $o) $seen[(int)$o['id']] = true;
        foreach ($bstmt->fetchAll() as $bo) {
            $bid = (int)$bo['id'];
            if (isset($seen[$bid])) continue;
            // 退款订单同样保留在列表中：销售金额不计，但域名/SSL 等已发生的成本仍计入项目分成扣减
            $seen[$bid] = true;
            $orders[] = $bo;
        }
        $bstmt->closeCursor();
    }

    // 4. 计算总金额
    $orderTotal = 0;
    foreach ($orders as $o) {
        $orderTotal += (float)($o['order_amount'] ?? 0);
    }

    return ['orders' => $orders, 'order_total' => round($orderTotal, 2)];
}
