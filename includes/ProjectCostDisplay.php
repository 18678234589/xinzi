<?php
require_once __DIR__ . '/ProjectTrademarkDupCost.php'; // 同时加载 ProjectTrademarkCost.php

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
    if ($order['project_type'] === '商标' && ($addon = ptc_addon_main_order((int)$order['id']))) {
        return ['show_amount' => true, 'label' => '补款单', 'message' => '这是同一客户当天订单 #' . $addon . ' 的补款 / 小类加项，不计官费和件数，无需补填', 'block_estimate' => false];
    }
    if ($order['project_type'] === '商标' && ($carrier = ptc_cost_carried_by((int)$order['id']))) {
        return ['show_amount' => true, 'label' => '同商标已计', 'message' => '该商标的官费已计入订单 #' . $carrier . '，本单不重复计成本', 'block_estimate' => false];
    }
    if ($order['project_type'] === '商标' && ($pending = ptd_order_group((int)$order['id']))) {
        // 疑似重复官费：合作人员能看到原因，不必再来问；财务 / 商标主管核对后自动恢复
        $state['show_amount'] = true; $state['label'] = '官费待核对'; $state['message'] = '同一客户的这件商标有 ' . count($pending['orders']) . ' 张订单都带了官费，已提交财务 / 商标主管核对是否同一件，核对后自动更正，无需操作。';
        return $state;
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
