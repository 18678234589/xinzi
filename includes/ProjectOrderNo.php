<?php
/**
 * 订单号补录：微信付款等没有店铺订单号的订单，上传时系统会生成内部订单号（WX- 开头）；
 * 拿到真实订单号后，由参与人或财务在订单页把内部号换成真实订单号。仅限未审核（草稿 / 审核中）订单。
 */
require_once __DIR__ . '/ProjectOrderSource.php';

function pon_is_internal($orderNo)
{
    return strpos((string)$orderNo, 'WX-') === 0;
}

/**
 * @return string 新订单号
 * @throws RuntimeException 订单已审核、新号码已被占用、没有权限等
 */
function pon_rename($orderId, $newNo, $actor)
{
    $newNo = trim((string)$newNo);
    if ($newNo === '' || mb_strlen($newNo) > 100) throw new RuntimeException('请填写订单号（100 字以内）');
    if (pon_is_internal($newNo)) throw new RuntimeException('这是系统内部号，请填写客户的真实订单号');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT * FROM project_orders WHERE id=? FOR UPDATE');
        $q->execute([(int)$orderId]); $order = $q->fetch();
        if (!$order) throw new RuntimeException('订单不存在');
        if (($actor['role'] ?? '') !== 'finance') {
            $a = $pdo->prepare('SELECT 1 FROM project_participants WHERE order_id=? AND employee_id=? LIMIT 1');
            $a->execute([(int)$orderId, (int)($actor['employee_id'] ?? 0)]);
            if (!$a->fetchColumn()) throw new RuntimeException('只有订单参与人或财务可以补录订单号');
        }
        if (!pon_is_internal($order['order_no'])) throw new RuntimeException('只有系统生成的内部订单号（WX- 开头）可以补录');
        if (!in_array($order['settlement_status'], ['draft', 'review'], true)) throw new RuntimeException('订单已审核 / 锁定，不能改订单号，请联系财务');
        $dup = $pdo->prepare('SELECT id FROM project_orders WHERE order_no=? AND id<>?');
        $dup->execute([$newNo, (int)$orderId]);
        if ($dup->fetchColumn()) throw new RuntimeException('订单号 ' . $newNo . ' 已有一张订单，请核对；如是同一笔，请联系财务合并');
        $pdo->prepare('UPDATE project_orders SET order_no=?,row_version=row_version+1 WHERE id=?')->execute([$newNo, (int)$orderId]);
        // 退款表里按订单号文字记录的，同步改成新号，免得对不上
        $pdo->prepare('UPDATE project_refund_import_rows SET order_no=? WHERE order_id=?')->execute([$newNo, (int)$orderId]);
        ps_audit('order', (int)$orderId, 'rename_order_no', $actor, ['from' => $order['order_no'], 'to' => $newNo]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    // 补上店铺流水里的售价 / 昵称（已有同号店铺流水时）
    try { ps_sync_existing_shop_order((int)$orderId, $newNo, (string)$order['shop']); } catch (Throwable $e) { /* 同步失败不影响改号 */ }
    return $newNo;
}
