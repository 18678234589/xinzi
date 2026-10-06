<?php
// 后月退款补扣：订单所属月份的分成按当时口径不动；退款发生在订单所属月份之后，就在退款所在月份生成补扣记录（project_commission_adjustments），
// 记录里写明原因和算法（退款前/后的收入、成本、服务费、分成基数、比例、补助），合作商在工资明细、订单页、看板里都能看到，并收到站内信。
require_once __DIR__ . '/ProjectSettlement.php';
require_once __DIR__ . '/ProjectExpectedSettlement.php';

function prc_commission_rows($order, $costs, $participants, $refundTotal)
{
    $o = $order; $o['refund_amount'] = $refundTotal; $o['_all_refunds'] = true;
    $sum = ps_summary($o, $costs, $participants);
    $rows = [];
    foreach (ps_expected_snapshot_rows($o, $sum, $o) as $r) $rows[$r['employee_id'] . '|' . $r['commission_group']] = $r;
    return $rows;
}

function prc_money($v) { return number_format((float)$v, 2, '.', ''); }

function prc_rows_text($r)
{
    if (!$r) return '无分成';
    $mode = ps_label('mode', $r['calc_mode']);
    return '收入 ¥' . prc_money($r['income_amount']) . '、成本 ¥' . prc_money($r['direct_cost']) . '、服务费 ¥' . prc_money($r['service_fee'])
        . '，可分成基数 ¥' . prc_money($r['contribution_profit']) . ' × ' . round((float)$r['rate'] <= 1 ? (float)$r['rate'] * 100 : (float)$r['rate'], 2) . '%（' . $mode . '）'
        . ((float)$r['subsidy_amount'] != 0 ? ' + 每单补助 ¥' . prc_money($r['subsidy_amount']) : '') . ' = ¥' . prc_money($r['commission_amount']);
}

/**
 * 给“退款发生在订单所属月份之后”的未审核订单生成补扣。须在退款流水已写入并重算订单后调用。
 * @return array 每人一条：['employee_id','group','amount']；退款当月 ≤ 订单所属月份时返回空。
 */
function prc_clawback_for_refund($orderId, $amount, $effMonth, $reason, $actor, $cashId = null)
{
    $q = db()->prepare('SELECT * FROM project_orders WHERE id=?');
    $q->execute([(int)$orderId]); $order = $q->fetch();
    if (!$order || in_array($order['settlement_status'], ['approved', 'locked'], true)) return [];
    $orderMonth = substr($order['order_date'], 0, 7);
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$effMonth) || $effMonth <= $orderMonth) return [];
    $costs = ps_costs((int)$orderId); $people = ps_participants((int)$orderId);
    $total = (float)$order['refund_amount'];
    $after = prc_commission_rows($order, $costs, $people, $total);
    $before = prc_commission_rows($order, $costs, $people, max(round($total - (float)$amount, 2), 0.0));
    $why = '订单 ' . $order['order_no'] . '（' . $orderMonth . ' 月订单）在 ' . $effMonth . ' 发生退款 ¥' . prc_money($amount) . ($reason !== '' ? '：' . $reason : '')
        . '。' . $orderMonth . ' 月分成已按当时口径核算、不再改动，所以在退款当月补扣。';
    $ins = db()->prepare('INSERT INTO project_commission_adjustments (order_id,employee_id,commission_group,amount,payroll_month,reason,calc_note,cash_movement_id,created_by_admin) VALUES (?,?,?,?,?,?,?,?,?)');
    $created = [];
    foreach ($after as $key => $a) {
        $b = $before[$key] ?? null;
        $deltaCents = (int)round((float)$a['commission_amount'] * 100) - (int)round((float)($b['commission_amount'] ?? 0) * 100);
        if ($deltaCents === 0) continue;
        $note = '算法：退款前 ' . prc_rows_text($b) . '；退款后 ' . prc_rows_text($a) . '；差额 ¥' . prc_money($deltaCents / 100) . ' 计入 ' . $effMonth . '。';
        $ins->execute([(int)$orderId, (int)$a['employee_id'], $a['commission_group'], $deltaCents / 100, $effMonth, mb_substr($why, 0, 300), mb_substr($note, 0, 500), $cashId, (int)($actor['id'] ?? 0)]);
        $created[] = ['employee_id' => (int)$a['employee_id'], 'group' => $a['commission_group'], 'amount' => $deltaCents / 100];
        try {
            db()->prepare('INSERT IGNORE INTO project_messages (employee_id,category,title,body,link,dedupe_key) VALUES (?,?,?,?,?,?)')
                ->execute([(int)$a['employee_id'], 'refund_clawback', mb_substr('订单退款补扣 ' . prc_money($deltaCents / 100) . ' 元（' . $order['order_no'] . '）', 0, 160), $why . "\n" . $note, '/project/order.php?id=' . (int)$orderId, 'clawback:' . ($cashId ?: $orderId . ':' . $effMonth . ':' . prc_money($amount)) . ':' . $a['employee_id'] . ':' . $a['commission_group']]);
        } catch (Throwable $e) { /* 站内信失败不影响补扣 */ }
    }
    ps_audit('order', (int)$orderId, 'refund_clawback', $actor, ['amount' => $amount, 'month' => $effMonth, 'adjustments' => $created]);
    return $created;
}

/** 补扣历史数据：给已扣减的退款补上生效月份，并对“后月退款”生成补扣。$dryRun 只返回清单。 */
function prc_backfill($actor, $dryRun = false)
{
    $out = ['marked' => 0, 'clawbacks' => []];
    $rows = db()->query("SELECT r.id,r.order_id,r.amount,r.refund_date,r.reason FROM project_refund_import_rows r WHERE r.review_status='approved' AND r.order_id IS NOT NULL AND r.reason NOT LIKE '%重复付款退回%' ORDER BY r.id")->fetchAll();
    $find = db()->prepare("SELECT id FROM project_cash_movements WHERE order_id=? AND movement_type='refund' AND review_status='approved' AND amount=? AND effective_month IS NULL ORDER BY id LIMIT 1");
    $mark = db()->prepare('UPDATE project_cash_movements SET effective_month=? WHERE id=?');
    foreach ($rows as $r) {
        $find->execute([(int)$r['order_id'], $r['amount']]); $cashId = (int)$find->fetchColumn();
        if (!$cashId) continue;
        $eff = substr($r['refund_date'], 0, 7);
        $out['marked']++;
        if ($dryRun) { $o = db()->query('SELECT order_no,order_date,settlement_status FROM project_orders WHERE id=' . (int)$r['order_id'])->fetch(); if ($o && $eff > substr($o['order_date'], 0, 7) && !in_array($o['settlement_status'], ['approved', 'locked'], true)) $out['clawbacks'][] = ['order_no' => $o['order_no'], 'amount' => $r['amount'], 'month' => $eff]; continue; }
        $mark->execute([$eff, $cashId]);
        $res = prc_clawback_for_refund((int)$r['order_id'], (float)$r['amount'], $eff, trim((string)$r['reason']), $actor, $cashId);
        if ($res) $out['clawbacks'][] = ['order_id' => (int)$r['order_id'], 'amount' => $r['amount'], 'month' => $eff, 'adjustments' => $res];
    }
    return $out;
}
