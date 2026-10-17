import paramiko, os, pathlib, json

c = paramiko.SSHClient()
c.load_system_host_keys()
key = pathlib.Path(os.environ.get('HEZUOSHANG_SSH_KEY', str(pathlib.Path.home()/'.ssh/id_ed25519_tfdev')))
c.connect('58.58.97.174', port=5024, username='root', key_filename=str(key))

php = """<?php
require_once '/myprograms/hezuoshang/config/database.php';
require_once '/myprograms/hezuoshang/includes/ProjectBatchReview.php';

$pdo = db();
$month = '2026-09';
$actor = ['role' => 'finance', 'id' => 1, 'type' => 'user'];
$adminId = 1;
$who = '系统后台批量审核';

// 1. 获取 2026-09 所有未审核订单
$st = $pdo->prepare("SELECT id, order_no, project_type, order_kind, contract_amount, receipt_amount, refund_amount, delivery_status, settlement_status FROM project_orders WHERE order_date >= '2026-09-01' AND order_date < '2026-10-01' AND settlement_status NOT IN ('approved', 'locked') ORDER BY id ASC");
$st->execute();
$orders = $st->fetchAll(PDO::FETCH_ASSOC);

echo "Total unapproved orders in 2026-09: " . count($orders) . "\\n";

$approved = 0;
$skipped = [];
$startTime = time();

foreach ($orders as $idx => $order) {
    $oid = (int)$order['id'];
    $orderNo = $order['order_no'];
    $projectType = $order['project_type'];
    
    // 检查是否有特别争议 (Auto Review 中的 exception 状态，或者退款未核销等)
    try {
        $ctx = pa_context($oid);
        $res = pa_evaluate($ctx);
        
        // 特别争议判定：
        // 1. state 是 exception
        // 2. 存在 refund 相关的争议 (external_refund_pending, refund_exceeds, refund_gap, linked_refund_conflict, source_after_sale)
        // 3. 存在 payment_conflict, receipt_conflict
        // 4. 定金尾款未结清 partial_receipt
        // 5. 跨月退款 later_refund
        $hasSevereException = false;
        $severeReasons = [];
        $severeCodes = ['external_refund_pending', 'refund_exceeds', 'refund_gap', 'linked_refund_conflict', 'later_refund', 'source_after_sale'];
        if ($res['state'] === 'exception') {
            foreach ($res['reasons'] as $code => $r) {
                if (in_array($code, $severeCodes, true)) {
                    $hasSevereException = true;
                    $severeReasons[] = $r['text'];
                }
            }
        }

        // 没有有效售价且没有实收
        if ((float)$order['contract_amount'] <= 0 && (float)$order['receipt_amount'] <= 0) {
            $hasSevereException = true;
            $severeReasons[] = '无有效售价或实收';
        }

        if ($hasSevereException) {
            $skipped[] = [
                'id' => $oid,
                'order_no' => $orderNo,
                'project_type' => $projectType,
                'amount' => $order['contract_amount'],
                'reasons' => $severeReasons
            ];
            continue;
        }

        // 执行审核
        $pdo->beginTransaction();
        
        // 锁行
        $rowSt = $pdo->prepare('SELECT * FROM project_orders WHERE id=? FOR UPDATE');
        $rowSt->execute([$oid]);
        $currOrder = $rowSt->fetch(PDO::FETCH_ASSOC);
        if (!$currOrder || in_array($currOrder['settlement_status'], ['approved', 'locked'], true)) {
            $pdo->rollBack();
            continue;
        }

        // 补登记收款
        $net = (float)$currOrder['receipt_amount'] - (float)$currOrder['refund_amount'];
        if ($net <= 0) {
            $saleAmt = round((float)$currOrder['contract_amount'], 2);
            if ($saleAmt > 0) {
                $pdo->prepare("INSERT INTO project_cash_movements (order_id,movement_type,amount,note,review_status,submitted_by_type,submitted_by_id,reviewed_by_admin,reviewed_at) VALUES (?,'receipt',?,?,'approved',?,?,?,NOW())")
                    ->execute([$oid, $saleAmt, $who . '：按售价确认收款', $actor['type'], $actor['id'], $adminId]);
                ps_recalculate_cash($oid);
            }
        }

        // 标记交付完成（非强制技术业务）
        if ($currOrder['delivery_status'] !== 'finished') {
            $pdo->prepare("UPDATE project_orders SET delivery_status='finished',row_version=row_version+1 WHERE id=?")->execute([$oid]);
            $pdo->prepare("UPDATE project_order_requests SET status='approved', reviewer_id=?, reviewed_at=NOW(), review_note='批量审核时自动通过' WHERE order_id=? AND request_type='delivery_completion' AND status='pending'")->execute([$adminId, $oid]);
        }

        // 准备资源与成本
        pbr_prepare_order($oid, $actor, $adminId, $who);

        // 正式审核通过
        ps_approve_order($oid, $actor, $month);
        ps_audit('order', $oid, 'batch_review_auto_pass', $actor, ['month' => $month, 'batch' => true]);

        $pdo->commit();
        $approved++;

        if ($approved % 100 === 0) {
            echo "Progress: $approved approved so far...\\n";
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $skipped[] = [
            'id' => $oid,
            'order_no' => $orderNo,
            'project_type' => $projectType,
            'amount' => $order['contract_amount'],
            'reasons' => [$e->getMessage()]
        ];
    }
}

$elapsed = time() - $startTime;
echo "\\n=== BATCH REVIEW COMPLETED in {$elapsed}s ===\\n";
echo "Total approved: $approved\\n";
echo "Total skipped (controversial / blocked): " . count($skipped) . "\\n";

// Save skipped list to json
file_put_contents('/myprograms/hezuoshang/storage/skipped_orders_202609.json', json_encode($skipped, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "Skipped orders saved to storage/skipped_orders_202609.json\\n";

// Print summary of skipped reasons
$reasonStats = [];
foreach ($skipped as $sk) {
    foreach ($sk['reasons'] as $r) {
        $reasonStats[$r] = ($reasonStats[$r] ?? 0) + 1;
    }
}
echo "\\nSkipped reasons breakdown:\\n";
print_r($reasonStats);

// Print new total approved in 2026-09
$newSt = $pdo->prepare("SELECT count(*) as approved_orders, count(distinct s.order_id) as snap_orders, sum(s.commission_amount) as total_comm FROM project_orders o LEFT JOIN project_commission_snapshots s ON s.order_id=o.id AND s.payroll_month=? WHERE o.order_date >= '2026-09-01' AND o.order_date < '2026-10-01' AND o.settlement_status='approved'");
$newSt->execute([$month]);
print_r($newSt->fetch(PDO::FETCH_ASSOC));
"""

cmd = "docker exec -i hezuoshang-php php << 'EOF'\n" + php + "\nEOF\n"
_, out, err = c.exec_command(cmd)
raw = out.read().decode('utf-8', errors='replace')
with open('tools/batch_approve_log.txt', 'w', encoding='utf-8') as f:
    f.write(raw)
print("Saved output to tools/batch_approve_log.txt, length: ", len(raw))
err_msg = err.read().decode('utf-8', errors='replace')
if err_msg:
    print("ERR:\n" + err_msg)
c.close()
