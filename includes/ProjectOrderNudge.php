<?php
/**
 * 每月自动提醒：上月订单的参与人（客服 / 技术）在上月截止日前核对自己名下的订单，不是自己的单请在订单页点“把自己从这张订单移除”，
 * 系统自动扣回分成并通知财务（ps_leave_order），不用等财务对账时再人工删。每人每月只发一次（dedupe_key），由自动核对任务每 5 分钟顺带调用。
 */
function pon_send($today = null)
{
    $now = $today ? strtotime($today) : time();
    $closeDay = (int)ps_setting_get('payroll_close_day', 15); if ($closeDay < 1 || $closeDay > 28) $closeDay = 15;
    $day = (int)date('j', $now);
    if ($day < $closeDay - 5 || $day > $closeDay) return 0; // 截止日前 5 天起提醒
    $month = date('Y-m', strtotime('first day of last month', $now));
    $from = $month . '-01'; $until = date('Y-m-d', strtotime($from . ' +1 month'));
    $q = db()->prepare("SELECT p.employee_id,COUNT(DISTINCT o.id) n FROM project_participants p JOIN project_orders o ON o.id=p.order_id JOIN employees e ON e.id=p.employee_id
        WHERE o.order_date>=? AND o.order_date<? AND o.settlement_status IN ('draft','review','approved') GROUP BY p.employee_id");
    $q->execute([$from, $until]);
    $ins = db()->prepare('INSERT IGNORE INTO project_messages (employee_id,category,title,body,link,dedupe_key) VALUES (?,?,?,?,?,?)');
    $sent = 0;
    foreach ($q->fetchAll() as $r) {
        $ins->execute([(int)$r['employee_id'], 'payroll_check', $month . ' 订单请核对（' . $closeDay . ' 日截止）',
            '你上月（' . $month . '）有 ' . (int)$r['n'] . ' 张订单。请在 ' . $closeDay . ' 日前核对：不是你的单（别人录错了人），打开订单点“把自己从这张订单移除”，系统会自动扣回分成并通知财务；没发货 / 没到账的订单，发货到账后自动算进当月。' . "\n" . $closeDay . ' 日后上月不再补入。',
            '/project/index.php?pm=' . $month, 'ordcheck:' . (int)$r['employee_id'] . ':' . $month]);
        $sent += $ins->rowCount();
    }
    return $sent;
}
