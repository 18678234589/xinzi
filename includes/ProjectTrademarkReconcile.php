<?php
/** 在调用方事务内修正自动商标成本，保留人工成本和已经审核/锁定的结算。 */
function ptc_reconcile_order_cost($orderId, $actor, $origin, $excelCost = null, $text = null)
{
    $pdo = db();
    if (!$pdo->inTransaction()) throw new RuntimeException('商标成本同步必须在事务中执行');
    $q = $pdo->prepare('SELECT project_type,settlement_status FROM project_orders WHERE id=? FOR UPDATE');
    $q->execute([(int)$orderId]);
    $order = $q->fetch();
    if (!$order || $order['project_type'] !== '商标' || in_array($order['settlement_status'], ['approved', 'locked'], true)) return 'skip';
    [$details, $context] = ptc_order_pricing_data($orderId);
    $context .= ' ' . (string)$text;
    $q = $pdo->prepare("SELECT c.*,t.business_scope AS template_scope,t.unit AS template_unit,t.price AS template_price FROM project_costs c LEFT JOIN project_cost_templates t ON t.id=c.template_id WHERE c.order_id=? AND c.review_status<>'rejected' ORDER BY c.id FOR UPDATE");
    $q->execute([(int)$orderId]);
    $rows = $q->fetchAll();
    $managed = [];
    $excel = max(0, (float)($details['trademark_excel_cost'] ?? 0));
    $excelRows = 0;
    foreach ($rows as $r) {
        $isExcel = $r['template_id'] === null && strpos((string)$r['reason'], 'Excel') === 0;
        $automatic = preg_match('/^(?:Excel|成本修复|补带|商标自动)/u', (string)$r['reason']);
        if (!$isExcel && !($automatic && $r['template_scope'] === '商标')) return 'manual';
        $managed[] = $r;
        if ($isExcel && strpos((string)$r['reason'], 'Excel 商标成本补差') !== 0) $excelRows += (float)$r['amount'];
        // 兼容旧程序将 Excel 较高成本直接覆写在标准模板上的记录。
        if ($automatic && strpos((string)$r['reason'], '较大者') !== false && (float)$r['amount'] > round((float)$r['template_price'] * (float)$r['quantity'], 2)) $excel = max($excel, (float)$r['amount']);
    }
    $excel = max($excel, round($excelRows, 2));
    if ($excelCost !== null && $excelCost !== '') {
        if (!is_numeric($excelCost) || (float)$excelCost < 0) throw new RuntimeException('商标实际成本金额无效');
        $excel = max($excel, round((float)$excelCost, 2));
    }
    $plan = ptc_cost_plan(ptc_templates(), $details, $context, $excel > 0 ? $excel : null);
    if ($plan['status'] !== 'ready') return 'unresolved';
    $oldDetails = $details;
    $details['trademark_service'] = $plan['service'];
    if ($excel > 0) $details['trademark_excel_cost'] = $excel;
    if ($details !== $oldDetails) {
        $pdo->prepare("INSERT INTO project_order_details (order_id,business_name,details_json) VALUES (?,'商标',?) ON DUPLICATE KEY UPDATE details_json=VALUES(details_json)")->execute([(int)$orderId, json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        ps_audit('order', (int)$orderId, 'confirm_trademark_service', $actor, ['from' => $oldDetails, 'to' => $details, 'origin' => $origin]);
    }
    $changed = false;
    $used = [];
    foreach ($plan['lines'] as $line) {
        $t = $line['template'];
        $old = null;
        // 同一项目优先原地更新；若业务识别纠错，则替换原自动服务项目。
        foreach ($managed as $r) if (!isset($used[$r['id']]) && (int)($r['template_id'] ?? 0) === (int)($t['id'] ?? 0)) { $old = $r; break; }
        if (!$old && $t) foreach ($managed as $r) if (!isset($used[$r['id']]) && $r['template_unit'] === $t['unit']) { $old = $r; break; }
        $quantity = $line['quantity'];
        if ($t) {
            [$unitPrice, $amount, $supplier] = ps_template_cost_amount($t, 0, $quantity);
            $itemName = $t['name'] . ($t['specification'] ? ' · ' . $t['specification'] : '');
            $reason = '商标自动：' . $origin . '；' . $itemName . ' × ' . ptc_count_label($quantity) . ' ' . $t['unit'];
        } else {
            $unitPrice = $amount = $line['amount']; $supplier = null;
            $itemName = '商标实际成本差额';
            $reason = 'Excel 商标成本补差：' . $origin . '；整单实际成本 ¥' . money_plain($excel);
        }
        $status = $line['status'];
        $same = $old && (int)($old['template_id'] ?? 0) === (int)($t['id'] ?? 0)
            && (int)($old['template_version'] ?? 0) === (int)($t['version'] ?? 0)
            && $old['item_name'] === $itemName && (float)$old['quantity'] == $quantity
            && (float)$old['unit_price'] == $unitPrice && (float)$old['amount'] == $amount;
        if ($same && $old['review_status'] === 'approved' && ($t === null || ptc_kind($t) === 'variable' || $old['reviewed_by_admin'] !== null)) $status = 'approved';
        if ($old) {
            $used[$old['id']] = true;
            if ($same && $old['review_status'] === $status) continue;
            $pdo->prepare('UPDATE project_costs SET template_id=?,template_version=?,category=?,item_name=?,quantity=?,unit=?,unit_price=?,amount=?,supplier_amount=?,cost_kind=?,is_custom=?,reason=?,review_status=?,reviewed_by_admin=NULL,review_note=? WHERE id=?')->execute([
                $t['id'] ?? null, $t['version'] ?? null, $t['category'] ?? 'other', $itemName, $quantity, $t['unit'] ?? '项', $unitPrice, $amount, $supplier, $t['cost_kind'] ?? 'one_time', $t ? 0 : 1,
                mb_substr($reason, 0, 500), $status, '商标办理事项与标准成本重新核对', (int)$old['id']
            ]);
            $costId = (int)$old['id'];
        } elseif ($t) $costId = ps_intake_add_template_cost($orderId, $t, $actor, $reason, $quantity, $status);
        else {
            $pdo->prepare("INSERT INTO project_costs (order_id,category,item_name,quantity,unit,unit_price,amount,cost_kind,is_custom,reason,review_status,submitted_by_employee) VALUES (?,'other',?,1,'项',?,?,'one_time',1,?,'pending',?)")->execute([(int)$orderId, $itemName, $amount, $amount, $reason, $actor['employee_id'] ?? null]);
            $costId = (int)$pdo->lastInsertId();
        }
        ps_audit('cost', $costId, 'reconcile_trademark_cost', $actor, ['order_id' => (int)$orderId, 'from' => $old, 'to' => ['template_id' => $t['id'] ?? null, 'quantity' => $quantity, 'unit_price' => $unitPrice, 'amount' => $amount, 'status' => $status], 'origin' => $origin]);
        $changed = true;
    }
    foreach ($managed as $r) if (!isset($used[$r['id']])) {
        $pdo->prepare("UPDATE project_costs SET review_status='rejected',review_note='商标成本重新核对，原自动成本被替换' WHERE id=?")->execute([(int)$r['id']]);
        ps_audit('cost', (int)$r['id'], 'replace_trademark_auto_cost', $actor, ['order_id' => (int)$orderId, 'from' => $r, 'origin' => $origin]);
        $changed = true;
    }
    return $changed ? ($rows ? 'corrected' : 'added') : 'ok';
}
