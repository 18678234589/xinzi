import paramiko, os, pathlib

c = paramiko.SSHClient()
c.load_system_host_keys()
key = pathlib.Path(os.environ.get('HEZUOSHANG_SSH_KEY', str(pathlib.Path.home()/'.ssh/id_ed25519_tfdev')))
c.connect('58.58.97.174', port=5024, username='root', key_filename=str(key))

php = """<?php
require_once '/myprograms/hezuoshang/config/database.php';
require_once '/myprograms/hezuoshang/includes/settlement/private_files.php';
require_once '/myprograms/hezuoshang/includes/ProjectBusiness.php';
require_once '/myprograms/hezuoshang/includes/ProjectSheetEdit.php';
require_once '/myprograms/hezuoshang/includes/settlement/approval.php';

$actor = ['role' => 'finance', 'id' => 1, 'type' => 'user'];
$file = ps_import_file_get(499, $actor);
$sheets = pse_import_sheets($file, $actor);
$sheet = reset($sheets);
$head = array_shift($sheet);

$pdo = db();

$repairedCount = 0;
$costAddedCount = 0;
$detailsUpdatedCount = 0;
$approvedCount = 0;

echo "Processing File 499 (" . count($sheet) . " rows)...\\n";

foreach ($sheet as $idx => $r) {
    $orderNo = trim($r[4] ?? '');
    if (!$orderNo) continue;

    // Find in project_orders
    $st = $pdo->prepare('SELECT id, order_no, contract_amount, receipt_amount, customer_name, delivery_status, settlement_status FROM project_orders WHERE order_no=?');
    $st->execute([$orderNo]);
    $order = $st->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        continue;
    }

    $orderId = (int)$order['id'];
    $wangwang = trim($r[3] ?? '');
    $writerCode = trim($r[7] ?? '');
    $words = trim($r[11] ?? '');
    $price = (float)str_replace([',', '¥', '￥', ' '], '', trim($r[12] ?? '0'));
    $cost = (float)str_replace([',', '¥', '￥', ' '], '', trim($r[13] ?? '0'));

    $orderChanged = false;
    // 1. Check and update contract_amount & receipt_amount
    if (abs((float)$order['contract_amount'] - $price) > 0.001 || abs((float)$order['receipt_amount'] - $price) > 0.001 || ($wangwang && empty($order['customer_name']))) {
        $upd = $pdo->prepare('UPDATE project_orders SET contract_amount=?, receipt_amount=?, customer_name=IF(customer_name="" OR customer_name IS NULL, ?, customer_name) WHERE id=?');
        $upd->execute([$price, $price, $wangwang, $orderId]);
        $orderChanged = true;
        $repairedCount++;
    }

    // 2. Order details (writer_code & writing_volume)
    $detSt = $pdo->prepare('SELECT details_json FROM project_order_details WHERE order_id=?');
    $detSt->execute([$orderId]);
    $existingDet = $detSt->fetchColumn();
    $detArr = $existingDet ? json_decode($existingDet, true) : [];
    if (!is_array($detArr)) $detArr = [];
    $detChanged = false;
    if ($writerCode && empty($detArr['writer_code'])) {
        $detArr['writer_code'] = $writerCode;
        $detChanged = true;
    }
    if ($words && empty($detArr['writing_volume'])) {
        $detArr['writing_volume'] = (is_numeric($words) ? ($words . '字') : $words);
        $detChanged = true;
    }
    if ($detChanged) {
        $newJson = json_encode($detArr, JSON_UNESCAPED_UNICODE);
        $pdo->prepare('INSERT INTO project_order_details (order_id, business_name, details_json) VALUES (?, "文章代写", ?) ON DUPLICATE KEY UPDATE details_json=?')->execute([$orderId, $newJson, $newJson]);
        $detailsUpdatedCount++;
    }

    // 3. Costs (writer cost)
    $costSt = $pdo->prepare('SELECT id, amount, review_status FROM project_costs WHERE order_id=? AND category="outsourcing"');
    $costSt->execute([$orderId]);
    $costRow = $costSt->fetch(PDO::FETCH_ASSOC);
    $costChanged = false;
    if ($cost > 0) {
        if (!$costRow) {
            $ins = $pdo->prepare('INSERT INTO project_costs (order_id, category, item_name, quantity, unit, unit_price, amount, cost_kind, is_custom, reason, review_status, submitted_by_employee, created_at) VALUES (?, "outsourcing", "写手稿费", 1.00, "笔", ?, ?, "one_time", 1, "Excel 导入成本", "approved", 25, NOW())');
            $ins->execute([$orderId, $cost, $cost]);
            $costAddedCount++;
            $costChanged = true;
        } elseif (abs((float)$costRow['amount'] - $cost) > 0.001 || $costRow['review_status'] !== 'approved') {
            $pdo->prepare('UPDATE project_costs SET amount=?, unit_price=?, review_status="approved" WHERE id=?')->execute([$cost, $cost, $costRow['id']]);
            $costChanged = true;
        }
    }

    // Check if snapshot needs recalculation
    $snapSt = $pdo->prepare('SELECT income_amount, direct_cost FROM project_commission_snapshots WHERE order_id=?');
    $snapSt->execute([$orderId]);
    $snap = $snapSt->fetch(PDO::FETCH_ASSOC);
    $snapNeedsUpdate = false;
    if (!$snap || abs((float)$snap['income_amount'] - $price) > 0.001 || abs((float)$snap['direct_cost'] - $cost) > 0.001) {
        $snapNeedsUpdate = true;
    }

    // 4. Re-calculate snapshot if needed
    if ($snapNeedsUpdate || $orderChanged || $costChanged || $detChanged || $order['settlement_status'] !== 'approved') {
        if ($order['delivery_status'] === 'finished') {
            try {
                $pdo->prepare('UPDATE project_orders SET settlement_status="review" WHERE id=?')->execute([$orderId]);
                $pdo->prepare('DELETE FROM project_commission_snapshots WHERE order_id=?')->execute([$orderId]);
                ps_approve_order($orderId, $actor, '2026-09');
                $approvedCount++;
            } catch (Throwable $e) {
                echo "Order {$orderId} approve skipped: " . $e->getMessage() . "\\n";
            }
        }
    }
}

echo "Done!\\n";
echo "Amount/Customer repaired orders: $repairedCount\\n";
echo "Details updated orders: $detailsUpdatedCount\\n";
echo "Cost added/updated orders: $costAddedCount\\n";
echo "Commissions re-calculated and approved: $approvedCount\\n";

$approvedStmt = $pdo->query("SELECT COUNT(*) FROM project_orders WHERE id BETWEEN 54585 AND 54681 AND settlement_status='approved'");
echo "Orders in 54585-54681 with settlement_status=approved: " . $approvedStmt->fetchColumn() . "\\n";

$snapStmt = $pdo->query("SELECT COUNT(*) FROM project_commission_snapshots WHERE order_id BETWEEN 54585 AND 54681");
echo "Snapshots in 54585-54681: " . $snapStmt->fetchColumn() . "\\n";
"""

cmd = "docker exec -i hezuoshang-php php << 'EOF'\n" + php + "\nEOF\n"
_, out, err = c.exec_command(cmd)
raw = out.read().decode('utf-8', errors='replace')
print("OUT:\n" + raw.encode('gbk', errors='replace').decode('gbk'))
err_msg = err.read().decode('utf-8', errors='replace')
if err_msg:
    print("ERR:\n" + err_msg.encode('gbk', errors='replace').decode('gbk'))
c.close()
