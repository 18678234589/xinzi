<?php
/**
 * 合作人员退款率排行：按订单日期所在月份统计，参与人（客服 / 技术）名下的订单。
 * 退款不限发生月份：9 月的订单，10 月、11 月才退的款也计入（取订单上累计的已确认退款）；还在等财务核实的退款单独列出（pending），不计入退款率。
 * 订单退款率 = 有退款的订单数 ÷ 订单数；金额退款率 = 退款金额 ÷ 售价合计（单单封顶不超过售价）。排名按金额退款率从高到低，单量不足 PRR_MIN_ORDERS 的排在后面。
 */
require_once __DIR__ . '/ProjectSettlement.php';
require_once __DIR__ . '/ProjectRefundTrash.php';

const PRR_MIN_ORDERS = 3;
const PRR_DEV_GROUP = '开发定制类';

/** 开发定制类 = 小程序开发 / AI网站定制 / 网站定制（含历史别名）。 */
function prr_dev_businesses()
{
    return ['小程序开发', 'AI网站定制', '网站定制'];
}

/** 退款有滞后：默认显示两个月前（11 月看 9 月）。 */
function prr_default_month($today = null)
{
    return date('Y-m', strtotime('first day of -2 months', $today ? strtotime($today) : time()));
}

/** 返回已排序的 [['employee_id','name','department','orders','refund_orders','amount','refund','order_rate','amount_rate','small']…]。 */
function prr_rank($month, $business = PRR_DEV_GROUP)
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$month)) throw new InvalidArgumentException('月份格式不对');
    $from = $month . '-01'; $until = date('Y-m-d', strtotime($from . ' +1 month'));
    $q = db()->prepare("SELECT p.employee_id,e.name,e.department,o.id,o.project_type,o.contract_amount,o.refund_amount,
        (SELECT COALESCE(SUM(r.amount),0) FROM project_refund_import_rows r WHERE r.order_id=o.id AND r.review_status='pending'" . prt_active_sql('r.') . ") AS pending_refund
        FROM project_orders o JOIN (SELECT DISTINCT order_id,employee_id FROM project_participants) p ON p.order_id=o.id JOIN employees e ON e.id=p.employee_id
        WHERE o.order_date>=? AND o.order_date<? AND o.contract_amount>0");
    $q->execute([$from, $until]);
    $want = $business === '' ? null : ($business === PRR_DEV_GROUP ? prr_dev_businesses() : [ps_business_normalize($business)]);
    $by = [];
    foreach ($q->fetchAll() as $r) {
        if ($want !== null && !in_array(ps_business_normalize((string)$r['project_type']), $want, true)) continue;
        $k = (int)$r['employee_id'];
        $by[$k] = $by[$k] ?? ['employee_id' => $k, 'name' => $r['name'], 'department' => $r['department'], 'orders' => 0, 'refund_orders' => 0, 'amount' => 0.0, 'refund' => 0.0, 'pending' => 0.0, 'pending_orders' => 0];
        $c = (float)$r['contract_amount']; $f = min((float)$r['refund_amount'], $c);
        $by[$k]['orders']++; $by[$k]['amount'] += $c; $by[$k]['refund'] += $f;
        if ($f > 0.004) $by[$k]['refund_orders']++;
        if ((float)$r['pending_refund'] > 0.004) { $by[$k]['pending'] += (float)$r['pending_refund']; $by[$k]['pending_orders']++; }
    }
    foreach ($by as &$x) {
        $x['order_rate'] = $x['orders'] ? $x['refund_orders'] / $x['orders'] : 0;
        $x['amount_rate'] = $x['amount'] > 0 ? $x['refund'] / $x['amount'] : 0;
        $x['small'] = $x['orders'] < PRR_MIN_ORDERS;
    }
    unset($x);
    $rows = array_values($by);
    usort($rows, function ($a, $b) { return [$a['small'], -$a['amount_rate'], -$a['order_rate'], -$a['orders']] <=> [$b['small'], -$b['amount_rate'], -$b['order_rate'], -$b['orders']]; });
    return $rows;
}
