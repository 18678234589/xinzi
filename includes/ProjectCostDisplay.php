<?php
require_once __DIR__ . '/ProjectTrademarkCost.php';

/** 区分已核实的零成本、未录入成本和不能用于预计分成的商标成本。 */
function ps_cost_display_state(array $order, array $costs)
{
    $state = ['show_amount' => false, 'label' => '未录入', 'message' => '尚未录入直接成本，不表示已核实为零成本', 'block_estimate' => false];
    if (in_array($order['settlement_status'], ['approved', 'locked'], true)) {
        return ['show_amount' => true, 'label' => '', 'message' => '', 'block_estimate' => false];
    }
    foreach ($costs as $cost) {
        if ($cost['review_status'] === 'approved') {
            $state['show_amount'] = true;
            $state['label'] = '';
            $state['message'] = '';
        } elseif ($cost['review_status'] === 'pending' && !$state['show_amount']) {
            $state['label'] = '待审核';
            $state['message'] = '成本已录入，等待财务审核';
        }
    }
    if ($order['project_type'] === '商标') {
        try {
            ptc_approval_guard((int)$order['id'], $costs);
        } catch (RuntimeException $e) {
            $state['label'] = '待核对';
            $state['message'] = $e->getMessage();
            $state['block_estimate'] = true;
        }
    }
    return $state;
}
