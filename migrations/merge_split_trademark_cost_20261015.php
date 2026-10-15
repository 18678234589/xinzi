<?php
/**
 * 同一客户、同一商标注册号、同一办理事项、同一天的拆单 / 补款订单只由一张订单承担官费（规则见 ptc_cost_group）。
 * 对每个承担订单执行一次成本同步，同组其余订单的自动成本被作废（保留记录和审计，不删除）。
 * 默认演练并回滚；加 --commit 才保存。已审核 / 锁定的结算不改写。
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectTrademarkCost.php';
$pdo = db();
$actor = ['type' => 'system', 'id' => 0, 'role' => 'finance', 'requested_by' => 'owner'];
$commit = in_array('--commit', $argv, true);
$active = function ($id) { return array_values(array_filter(ps_costs($id), function ($c) { return $c['review_status'] !== 'rejected'; })); };
$pdo->beginTransaction();
try {
    $rows = $pdo->query("SELECT o.id,o.order_no,o.customer_name,o.order_date FROM project_orders o WHERE o.project_type='商标' AND o.settlement_status IN ('draft','review') ORDER BY o.id")->fetchAll();
    $touched = 0;
    foreach ($rows as $o) {
        [$details] = ptc_order_pricing_data($o['id']);
        $names = ptc_service_names((string)($details['service_type'] ?? ''));
        $service = trim((string)($details['trademark_service'] ?? '')) ?: (count($names) === 1 ? $names[0] : '');
        [$carrier, $siblings] = ptc_cost_group($o['id'], $details, $service);
        if ($carrier !== (int)$o['id'] || !$siblings) continue;
        $before = array_map(function ($id) use ($active) { return [$id, array_sum(array_column($active($id), 'amount'))]; }, array_merge([$carrier], $siblings));
        $result = ptc_sync_order_cost($o['id'], $actor, '同商标成本合并');
        $after = array_map(function ($id) use ($active) { return [$id, array_sum(array_column($active($id), 'amount'))]; }, array_merge([$carrier], $siblings));
        echo "承担订单 #{$carrier} {$o['order_no']} {$o['customer_name']} {$o['order_date']} => {$result}\n";
        echo '  成本 前 ' . json_encode($before) . ' 后 ' . json_encode($after) . "\n";
        $touched++;
    }
    echo "涉及 {$touched} 组\n";
} catch (Throwable $e) {
    while ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}
if ($commit) { $pdo->commit(); echo "已保存\n"; } else { $pdo->rollBack(); echo "演练完成，未保存（加 --commit 保存）\n"; }
