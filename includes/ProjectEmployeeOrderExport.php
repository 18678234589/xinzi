<?php
/**
 * 财务核对用：某人某月参与的全部订单明细（含未审核、待发货的订单），逐单写清售价 / 实收 / 状态 / 本人岗位 / 成本 / 比例 / 提成。
 *  - 已审核订单：取审核时的分成快照（本人提成、补助、比例、成本）；
 *  - 未审核订单：按系统现行规则给出“预计提成”，并写明是否计入报酬表的“含待审预计”；
 *  - 最后附本月的分成调整（跨月退款、规则更正等）和合计，方便和财务手算的表逐行对。
 */
require_once __DIR__ . '/ProjectSettlement.php';
require_once __DIR__ . '/ProjectMiniappDelivery.php';

/** 返回 [表头, 订单行[], 调整行[], 合计[]]。 */
function peo_build($employeeId, $month)
{
    $employeeId = (int)$employeeId;
    $from = $month . '-01'; $until = date('Y-m-d', strtotime($from . ' +1 month'));
    $pdo = db();
    $q = $pdo->prepare("SELECT o.*,COALESCE(s.trade_status,'') AS trade_status,COALESCE(s.payment_nickname,'') AS nick FROM project_orders o JOIN project_participants p ON p.order_id=o.id AND p.employee_id=? LEFT JOIN project_order_sources s ON s.order_id=o.id WHERE ((o.order_date>=? AND o.order_date<?) OR (o.order_date<? AND EXISTS (SELECT 1 FROM project_commission_snapshots sx WHERE sx.order_id=o.id AND sx.employee_id=p.employee_id AND sx.payroll_month=?))) GROUP BY o.id ORDER BY o.order_date,o.id");
    $q->execute([$employeeId, $from, $until, $from, $month]); // 上月完成、顺延到本月工资里结算的订单也列出（否则明细对不上报酬表）
    $snap = $pdo->prepare("SELECT commission_group,SUM(commission_amount) amt,SUM(subsidy_amount) sub,MAX(rate) rate,SUM(direct_cost) cost,SUM(service_fee) fee,MAX(rule_id) rule_id,MAX(group_weight) w FROM project_commission_snapshots WHERE order_id=? AND employee_id=? AND (?=0 OR payroll_month=?) GROUP BY commission_group");
    $names = []; foreach ($pdo->query('SELECT id,name FROM employees') as $r) $names[(int)$r['id']] = $r['name'];
    $statusText = ['draft' => '草稿', 'review' => '待审', 'approved' => '已审核', 'locked' => '已锁定'];
    $rows = []; $tot = ['approved' => 0.0, 'estimate' => 0.0, 'excluded' => 0.0, 'orders' => 0, 'price' => 0.0, 'adjust' => 0.0];
    foreach ($q->fetchAll() as $o) {
        $oid = (int)$o['id'];
        $parts = ps_participants($oid);
        $mine = []; $others = [];
        foreach ($parts as $p) {
            if ((int)$p['employee_id'] === $employeeId) $mine[] = $p;
            else $others[] = ($names[(int)$p['employee_id']] ?? $p['employee_id']) . ':' . $p['role_name'];
        }
        $role = implode('、', array_map(function ($p) { return ($p['commission_group'] === 'technical' ? '技术-' : '客服-') . $p['role_name']; }, $mine));
        $weight = implode('、', array_map(function ($p) { return rtrim(rtrim(number_format((float)$p['group_weight'], 4, '.', ''), '0'), '.'); }, $mine));
        $costs = ps_costs($oid); $sum = ps_summary($o, $costs, $parts);
        $amount = 0.0; $subsidy = 0.0; $rate = ''; $cost = ''; $fee = ''; $basis = '';
        $isApproved = in_array($o['settlement_status'], ['approved', 'locked'], true);
        if ($isApproved) {
            $carried = $o['order_date'] < $from ? 1 : 0; $snap->execute([$oid, $employeeId, $carried, $month]); $sr = $snap->fetchAll();
            foreach ($sr as $x) { $amount += (float)$x['amt']; $subsidy += (float)$x['sub']; $rate = $x['rate']; $cost = $x['cost']; $fee = $x['fee']; }
            $basis = ($carried ? '上月订单，顺延计入本月；' : '') . ($sr ? '已审核（取审核快照）' : '已审核（无本人快照）');
            $tot['approved'] += $amount;
        } else {
            foreach ($sum['groups'] as $g) foreach ($g['people'] as $person) if ((int)$person['employee_id'] === $employeeId && !empty($person['estimated_calc'])) {
                $c = $person['estimated_calc']; $amount += (float)$c['share'] + (float)$c['subsidy']; $subsidy += (float)$c['subsidy']; $rate = $c['rate']; $cost = $c['cost_basis']; $fee = $c['fee_part'];
            }
            $amount = round($amount, 2);
            if (ps_forecast_unshipped($o, ['trade_status' => $o['trade_status']])) { $basis = '预计，但暂不计入报酬表（未发货 / 未到账 / 交易关闭）'; $tot['excluded'] += $amount; }
            else { $basis = '预计（未审核，计入报酬表“含待审预计”）'; $tot['estimate'] += $amount; }
        }
        $tot['orders']++; $tot['price'] += (float)$o['contract_amount'];
        $rows[] = [$o['order_no'], $o['order_date'], $o['shop'], $o['nick'], $o['project_type'], $o['order_kind'], (float)$o['contract_amount'], (float)$o['receipt_amount'], (float)$o['refund_amount'],
            $o['trade_status'] ?: '(无店铺状态)', $o['delivery_status'] === 'finished' ? '已完成' : '未完成', $statusText[$o['settlement_status']] ?? $o['settlement_status'], $role, $weight, implode('；', $others),
            $cost === '' ? '' : round((float)$cost, 2), $fee === '' ? '' : round((float)$fee, 2), $rate === '' ? '' : (float)$rate, round($amount, 2), round($subsidy, 2), $basis, $o['customer_name'], $o['note']];
    }
    $a = $pdo->prepare("SELECT a.amount,a.reason,a.calc_note,o.order_no,o.order_date FROM project_commission_adjustments a JOIN project_orders o ON o.id=a.order_id WHERE a.employee_id=? AND a.payroll_month=? ORDER BY a.id");
    $a->execute([$employeeId, $month]);
    $adj = [];
    foreach ($a->fetchAll() as $r) { $adj[] = [$r['order_no'], $r['order_date'], (float)$r['amount'], $r['reason'], $r['calc_note']]; $tot['adjust'] += (float)$r['amount']; }
    $head = ['订单编号', '订单日期', '店铺', '付款昵称', '业务', '订单类型', '售价', '实收', '退款', '店铺状态', '交付', '审核状态', '本人岗位', '本人权重', '同单其他人员', '成本', '服务费', '提成比例', '本人提成（含补助）', '其中补助', '口径说明', '客户', '备注'];
    return [$head, $rows, $adj, $tot];
}

/**
 * 下载订单明细：默认是 Excel 能直接打开的表格（.xls：订单号 / 昵称按文本显示，不会变成 3.31646E+18；列宽合适、表头加粗、金额右对齐）；
 * format=csv 时输出 CSV（UTF-8 带 BOM，订单号等长数字前加制表符保持文本）。
 */
function peo_download($employeeId, $month, $format = 'xls')
{
    $q = db()->prepare('SELECT name,department FROM employees WHERE id=?'); $q->execute([(int)$employeeId]); $emp = $q->fetch();
    if (!$emp) throw new RuntimeException('合作人员不存在');
    [$head, $rows, $adj, $tot] = peo_build($employeeId, $month);
    $title = $emp['name'] . ' · ' . $emp['department'] . ' · ' . $month . ' 订单明细（核对用，含未审核订单）';
    $totalRow = ['合计', '', '', '', '', '', round($tot['price'], 2), '', '', '', '', '', '', '', '', '', '', '', round($tot['approved'] + $tot['estimate'], 2), '', '订单 ' . $tot['orders'] . ' 单；已审核提成 ' . number_format($tot['approved'], 2, '.', '') . ' + 计入报酬表的预计 ' . number_format($tot['estimate'], 2, '.', '') . '；另有暂不计入的预计 ' . number_format($tot['excluded'], 2, '.', '')];
    $textCols = [0, 2, 3, 4, 5, 9, 12, 14, 20, 21, 22]; // 订单号、店铺、昵称、业务、类型、状态、岗位、同单其他人员、口径、客户、备注：始终按文本
    $fname = rawurlencode($emp['name'] . '-' . $month . '-订单明细');
    header('X-Content-Type-Options: nosniff');
    if ($format === 'csv') {
        $safe = function ($v, $text = false) { if (!is_string($v)) return $v; if (preg_match('/^[=+@\-]/u', $v)) return "'" . $v; return ($text && preg_match('/^\d{9,}$/', $v)) ? "	" . $v : $v; };
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="orders-' . (int)$employeeId . '-' . $month . '.csv"; filename*=UTF-8\'\'' . $fname . '.csv');
        $out = fopen('php://output', 'wb'); fwrite($out, "ï»¿");
        fputcsv($out, [$title]); fputcsv($out, $head);
        foreach ($rows as $r) { foreach ($r as $k => $v) $r[$k] = $safe($v, in_array($k, $textCols, true)); fputcsv($out, $r); }
        fputcsv($out, []); fputcsv($out, $totalRow);
        if ($adj) { fputcsv($out, []); fputcsv($out, ['本月分成调整（跨月退款、规则更正等）', '订单日期', '调整金额', '原因', '计算说明']); foreach ($adj as $r) fputcsv($out, array_map($safe, [$r[0], $r[1], $r[2], $r[3], $r[4]])); fputcsv($out, ['调整合计', '', round($tot['adjust'], 2)]); }
        fclose($out); return;
    }
    $widths = [22, 11, 15, 16, 12, 11, 9, 9, 8, 14, 8, 8, 16, 7, 20, 9, 9, 8, 13, 9, 34, 14, 40];
    if (class_exists('ZipArchive')) {
        // 真正的 xlsx：双击用 Excel / WPS 表格打开（原来的 HTML 伪 .xls 会被 Word 当文档打开，表格排成竖条）
        require_once __DIR__ . '/MiniXlsx.php';
        $cell = function ($v, $k) use ($textCols) { return in_array($k, $textCols, true) || !is_numeric($v) ? ['text' => (string)$v] : (float)$v; };
        $rowsX = [[$title], $head];
        foreach ($rows as $r) { $line = []; foreach ($r as $k => $v) $line[] = $v === '' || $v === null ? '' : $cell($v, $k); $rowsX[] = $line; }
        $rowsX[] = []; $line = []; foreach ($totalRow as $k => $v) $line[] = $v === '' ? '' : $cell($v, $k); $rowsX[] = $line;
        if ($adj) {
            $rowsX[] = []; $rowsX[] = ['本月分成调整（跨月退款、规则更正等）', '订单日期', '调整金额', '原因', '计算说明'];
            foreach ($adj as $r) $rowsX[] = [['text' => (string)$r[0]], ['text' => (string)$r[1]], (float)$r[2], ['text' => (string)$r[3]], ['text' => (string)$r[4]]];
            $rowsX[] = ['调整合计', '', round($tot['adjust'], 2)];
        }
        $bin = mxl_build(['订单明细' => ['rows' => $rowsX, 'widths' => $widths, 'header' => 2]]);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="orders-' . (int)$employeeId . '-' . $month . '.xlsx"; filename*=UTF-8\'\'' . $fname . '.xlsx');
        header('Content-Length: ' . strlen($bin));
        echo $bin; return;
    }
    $h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="orders-' . (int)$employeeId . '-' . $month . '.xls"; filename*=UTF-8\'\'' . $fname . '.xls');
    echo "ï»¿<html xmlns:o=\"urn:schemas-microsoft-com:office:office\" xmlns:x=\"urn:schemas-microsoft-com:office:excel\"><head><meta charset=\"UTF-8\"><style>td,th{font-family:'Microsoft YaHei',Arial;font-size:11pt;border:.5pt solid #bbb;vertical-align:middle}th{background:#2d6a4f;color:#fff;font-weight:bold;text-align:center}.t{mso-number-format:'\@'}.n{text-align:right;mso-number-format:'0.00'}.b{font-weight:bold;background:#eef6ea}.x{background:#fff3cd}</style></head><body><table>";
    foreach ($widths as $w) echo '<col style="width:' . ($w * 8) . 'px">';
    echo '<tr><td colspan="' . count($head) . '" style="font-size:13pt;font-weight:bold;border:0">' . $h($title) . '</td></tr><tr>';
    foreach ($head as $c) echo '<th>' . $h($c) . '</th>';
    echo '</tr>';
    foreach ($rows as $r) {
        $dim = strpos((string)$r[20], '暂不计入') !== false;
        echo '<tr' . ($dim ? ' class="x"' : '') . '>';
        foreach ($r as $k => $v) {
            if (in_array($k, $textCols, true) || !is_numeric($v)) echo '<td class="t">' . $h($v) . '</td>';
            else echo '<td class="n">' . $h($v) . '</td>';
        }
        echo '</tr>';
    }
    echo '<tr class="b">'; foreach ($totalRow as $k => $v) echo is_numeric($v) && $v !== '' ? '<td class="n b">' . $h($v) . '</td>' : '<td class="t b">' . $h($v) . '</td>'; echo '</tr>';
    if ($adj) {
        echo '<tr><td colspan="' . count($head) . '" style="border:0"></td></tr><tr><th>本月分成调整</th><th>订单日期</th><th>调整金额</th><th colspan="4">原因</th><th colspan="8">计算说明</th></tr>';
        foreach ($adj as $r) echo '<tr><td class="t">' . $h($r[0]) . '</td><td class="t">' . $h($r[1]) . '</td><td class="n">' . $h($r[2]) . '</td><td class="t" colspan="4">' . $h($r[3]) . '</td><td class="t" colspan="8">' . $h($r[4]) . '</td></tr>';
        echo '<tr class="b"><td class="t b">调整合计</td><td></td><td class="n b">' . $h(round($tot['adjust'], 2)) . '</td></tr>';
    }
    echo '</table></body></html>';
}
