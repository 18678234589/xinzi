<?php
/**
 * 订单“实收”直接更正（网站客服 / 网站模板技术 / 定制技术自己改，不用再找财务逐单核对）。
 * 实收 = 已审核收款流水合计：改成新金额 = 在流水里补一笔收款，或把最近的收款流水减掉对应金额（减到 0 的标记为作废，留痕）。
 * 权限：财务可改任意未锁定订单；订单参与人（客服 / 技术）可改自己参与的、未审核的网站类订单（AI网站定制 / 网站定制 / 网站模板）。
 * 已审核的订单只有财务能改：原分成快照保留，差额作为调整计入下一个未锁定月份。
 */
require_once __DIR__ . '/ProjectOrderBusiness.php';

const PRE_BUSINESSES = ['AI网站定制', '网站定制', '网站模板'];

/** 能不能改这张订单的实收：返回 [可以, 原因]。$participant：当前人员是不是订单参与人。 */
function pre_can_edit(array $order, array $actor, $participant)
{
    $status = (string)($order['settlement_status'] ?? '');
    if ($status === 'locked') return [false, '订单所属月份已归档锁定，无法修改'];
    if (($actor['role'] ?? '') === 'finance') return [true, ''];
    if (!in_array($actor['role'] ?? '', ['customer_service', 'technical'], true)) return [false, '无权限修改实收'];
    if (!$participant) return [false, '只能修改本人参与的订单'];
    if (!in_array(ps_business_normalize((string)($order['project_type'] ?? '')), PRE_BUSINESSES, true)) return [false, '这个业务的实收请联系财务更正'];
    if (!in_array($status, ['draft', 'review'], true)) return [false, '订单已审核，实收只有财务能改'];
    return [true, ''];
}

/**
 * 把订单实收改成 $newAmount。调用方负责权限（pre_can_edit）与事务。
 * 返回 ['old' => 原实收, 'new' => 新实收, 'adjusted' => 是否记了分成调整]。
 */
function ps_set_receipt($orderId, $newAmount, array $actor, $note = '', $adjustMonth = '')
{
    $pdo = db();
    if (!is_numeric($newAmount) || (float)$newAmount < 0 || (float)$newAmount > 999999999999.99) throw new RuntimeException('请填写有效的实收金额（不小于 0）');
    $new = round((float)$newAmount, 2);
    $q = $pdo->prepare('SELECT * FROM project_orders WHERE id=? FOR UPDATE'); $q->execute([(int)$orderId]); $order = $q->fetch();
    if (!$order) throw new RuntimeException('订单不存在');
    if ($order['settlement_status'] === 'locked') throw new RuntimeException('订单所属月份已归档锁定，无法修改');
    // 实收大于售价（合并付款、补款、含别的订单的钱等）：订单参与人必须写明原因；写了原因的由系统自动审核通过，只在每月 5 单的财务抽查里事后核对
    if ($new > (float)$order['contract_amount'] + 0.005 && ($actor['role'] ?? '') !== 'finance' && mb_strlen(trim($note)) < 4) throw new RuntimeException('实收大于售价（¥' . number_format((float)$order['contract_amount'], 2, '.', '') . '），请在“更正原因”里写明原因（如：客户补款 / 合并付款）');
    $approved = $order['settlement_status'] === 'approved';
    if ($approved && ($actor['role'] ?? '') !== 'finance') throw new RuntimeException('订单已审核，实收只有财务能改');
    $mv = $pdo->prepare("SELECT id,amount,note FROM project_cash_movements WHERE order_id=? AND movement_type='receipt' AND review_status='approved' ORDER BY id DESC FOR UPDATE");
    $mv->execute([(int)$orderId]);
    $rows = $mv->fetchAll();
    $cur = 0.0; foreach ($rows as $r) $cur += (float)$r['amount'];
    $cur = round($cur, 2);
    if (abs($new - $cur) < 0.005) return ['old' => $cur, 'new' => $new, 'adjusted' => false];
    $label = trim($note) !== '' ? trim($note) : '实收更正';
    $byFinance = ($actor['role'] ?? '') === 'finance';
    if ($new > $cur) {
        $pdo->prepare("INSERT INTO project_cash_movements (order_id,movement_type,amount,note,review_status,submitted_by_type,submitted_by_id,reviewed_by_admin,reviewed_at) VALUES (?,'receipt',?,?,'approved',?,?,?,NOW())")
            ->execute([(int)$orderId, round($new - $cur, 2), mb_substr('实收更正（' . ($byFinance ? '财务' : '订单参与人') . '）：' . $label, 0, 480), $actor['type'] ?? 'employee', (int)($actor['id'] ?? 0), $byFinance && ($actor['type'] ?? '') === 'admin' ? (int)$actor['id'] : null]);
    } else {
        $cut = round($cur - $new, 2);
        $upd = $pdo->prepare('UPDATE project_cash_movements SET amount=?,review_status=?,note=? WHERE id=?');
        foreach ($rows as $r) {
            if ($cut <= 0.004) break;
            $take = min((float)$r['amount'], $cut); $left = round((float)$r['amount'] - $take, 2); $cut = round($cut - $take, 2);
            $mark = '；实收更正：原 ¥' . number_format((float)$r['amount'], 2, '.', '') . ' 改为 ¥' . number_format($left, 2, '.', '') . '（' . $label . '）';
            $upd->execute([$left, $left > 0.004 ? 'approved' : 'rejected', mb_substr((string)$r['note'] . $mark, 0, 500), (int)$r['id']]);
        }
    }
    // 已审核订单：ps_recalculate_cash 发现实收变了会自动按快照口径记分成调整（计入 $adjustMonth 或下一个未锁定月份）
    $GLOBALS['ps_receipt_adjust_month'] = $approved && $adjustMonth !== '' ? pob_open_month($adjustMonth) : '';
    try { ps_recalculate_cash((int)$orderId); } finally { unset($GLOBALS['ps_receipt_adjust_month']); }
    ps_audit('order', (int)$orderId, 'set_receipt', $actor, ['from' => $cur, 'to' => $new, 'note' => $label]);
    $adjusted = $approved;
    return ['old' => $cur, 'new' => $new, 'adjusted' => $adjusted];
}
