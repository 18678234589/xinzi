<?php
require_once __DIR__ . '/ProjectSettlement.php';
require_once __DIR__ . '/ProjectRefundTrash.php';
require_once __DIR__ . '/ProjectAutoReviewMath.php';

function pa_storage_available()
{
    static $available = null;
    if ($available === null) {
        try { db()->query('SELECT order_id FROM project_auto_reviews LIMIT 1'); $available = true; }
        catch (PDOException $e) { $available = false; }
    }
    return $available;
}

function pa_sources(array $order, array $source, $lock = false)
{
    $refs = [trim((string)$order['order_no'])];
    if (trim((string)($source['payment_reference'] ?? '')) !== '') $refs[] = trim($source['payment_reference']);
    $refs = array_values(array_unique($refs));
    $identity = 'order_no IN (' . implode(',', array_fill(0, count($refs), '?')) . ')';
    $params = $refs;
    if (!empty($source['payment_reference'])) {
        foreach (['支付宝交易号','微信交易号','支付订单号','交易流水号','商家订单号'] as $field) {
            $path = '$."' . $field . '"';
            $identity .= ' OR (CASE WHEN JSON_VALID(raw_data) THEN JSON_UNQUOTE(JSON_EXTRACT(raw_data,?)) ELSE NULL END)=?';
            array_push($params, $path, trim($source['payment_reference']));
        }
    }
    $q = db()->prepare("SELECT id,order_no,shop,raw_data FROM orders WHERE ($identity) AND employee_id=0 AND order_scope='department' AND COALESCE(is_deleted,0)=0 ORDER BY id DESC LIMIT 40" . ($lock ? ' FOR UPDATE' : ''));
    $q->execute($params);
    $rows = [];
    foreach ($q->fetchAll() as $row) {
        $row['raw'] = json_decode((string)$row['raw_data'], true) ?: [];
        $row['matched_by_reference'] = $row['order_no'] !== $order['order_no'];
        unset($row['raw_data']);
        $rows[] = $row;
    }
    $direct = array_values(array_filter($rows,function($row)use($order){return $row['order_no']===$order['order_no'];}));
    if ($direct) return $direct; // Full platform order number takes precedence over a combined payment reference.
    return $rows;
}

function pa_context($orderId, $lock = false)
{
    $p = db();
    $q = $p->prepare('SELECT * FROM project_orders WHERE id=?' . ($lock ? ' FOR UPDATE' : ''));
    $q->execute([(int)$orderId]); $order = $q->fetch();
    if (!$order) throw new RuntimeException('订单不存在');
    $q = $p->prepare('SELECT * FROM project_order_sources WHERE order_id=?' . ($lock ? ' FOR UPDATE' : ''));
    $q->execute([$orderId]); $source = $q->fetch() ?: [];
    $q = $p->prepare('SELECT * FROM project_order_resources WHERE order_id=?' . ($lock ? ' FOR UPDATE' : ''));
    $q->execute([$orderId]); $resource = $q->fetch() ?: [];
    // Lock dependent records before evaluating; recalculation and snapshot use the same transaction.
    foreach (['project_cash_movements', 'project_costs', 'project_participants', 'project_order_requests'] as $table) {
        if ($lock) { $q = $p->prepare("SELECT id FROM $table WHERE order_id=? FOR UPDATE"); $q->execute([$orderId]); $q->fetchAll(); }
    }
    $cash = ps_cash_movements($orderId); $costs = ps_costs($orderId); $people = ps_participants($orderId);
    $payment = pa_payment_evidence(pa_sources($order, $source, $lock), trim((string)$order['shop']), date('Y-m-d H:i:s'));
    foreach ($payment['references'] as $reference) {
        $q = $p->prepare('SELECT order_id FROM project_auto_cash_evidence WHERE source_key=? AND order_id<>?' . ($lock ? ' FOR UPDATE' : ''));
        $q->execute([$reference['source_key'],$orderId]);
        if ($q->fetchColumn()) $payment['warnings']['source_reused']='实付来源已关联另一订单；合并付款需财务分摊，系统不会重复登记收款';
    }
    $virtual = $order;
    if ((float)$order['receipt_amount'] === 0.0 && $payment['paid_cents'] !== null) $virtual['receipt_amount'] = $payment['paid_cents'] / 100;
    $summary = ps_summary($virtual, $costs, $people);
    $q = $p->prepare("SELECT COUNT(*) FROM project_order_requests WHERE order_id=? AND status='pending'"); $q->execute([$orderId]); $requests = (int)$q->fetchColumn();
    $refundWhere = 'order_id=? OR order_no=?'; $refundParams = [$orderId, $order['order_no']];
    if (!empty($source['payment_reference'])) { $refundWhere .= ' OR source_payment_reference=?'; $refundParams[] = $source['payment_reference']; }
    $q = $p->prepare("SELECT id FROM project_refund_import_rows WHERE review_status='pending' AND ($refundWhere)" . prt_active_sql() . ($lock ? ' FOR UPDATE' : '')); $q->execute($refundParams); $refunds = count($q->fetchAll());
    $q = $p->prepare('SELECT COUNT(*) FROM project_commission_snapshots WHERE order_id=?'); $q->execute([$orderId]); $snapshots = (int)$q->fetchColumn();
    $q = $p->prepare('SELECT status FROM project_payroll_periods WHERE period=?' . ($lock ? ' FOR UPDATE' : '')); $q->execute([substr($order['order_date'], 0, 7)]); $period = $q->fetchColumn();
    return [
        'order' => $order, 'source' => $source, 'resource' => $resource, 'cash' => $cash, 'costs' => $costs, 'participants' => $people,
        'summary' => $summary, 'payment' => $payment, 'catalog' => ps_business_catalog()[ps_business_normalize($order['project_type'])] ?? [],
        'pending_requests' => $requests, 'pending_refunds' => $refunds, 'snapshot_count' => $snapshots, 'period_status' => $period,
        'technical_count' => count(array_filter($people, function ($person) { return $person['commission_group'] === 'technical'; })),
        'later_refund' => ps_refund_later($orderId, $order['order_date']), 'today' => date('Y-m-d'),
    ];
}

function pa_evidence(array $ctx)
{
    $groups = [];
    foreach ($ctx['summary']['groups'] as $key => $group) {
        if (!$group['people']) continue;
        // Match the settlement snapshot's cent allocation, including pool rounding tails.
        $shares = ps_group_share_cents($group['people']);
        foreach ($group['people'] as $i => $person) {
            $amount = ($shares[$i] + (int)round(($person['calc']['subsidy'] ?? 0) * 100)) / 100;
            $groups[] = ['employee_id' => (int)$person['employee_id'], 'group' => $key, 'role' => $person['role_name'], 'rule_id' => (int)($person['rule']['id'] ?? 0), 'weight' => (float)$person['group_weight'], 'amount' => $amount, 'formula' => (string)($person['calc']['note'] ?? '')];
        }
    }
    return [
        'policy_version' => PA_POLICY_VERSION, 'order_id' => (int)$ctx['order']['id'], 'business' => $ctx['order']['project_type'],
        'order_month' => substr($ctx['order']['order_date'], 0, 7), 'delivery_status' => $ctx['order']['delivery_status'],
        'receipt' => (float)$ctx['order']['receipt_amount'], 'refund' => (float)$ctx['order']['refund_amount'],
        'direct_cost' => (float)$ctx['summary']['direct_cost'], 'service_fee' => (float)$ctx['summary']['service_fee'], 'income_for_calculation' => (float)$ctx['summary']['income'],
        'cash_ids' => array_map('intval', array_column($ctx['cash'], 'id')), 'cost_ids' => array_map('intval', array_column($ctx['costs'], 'id')),
        'payment' => $ctx['payment'], 'groups' => $groups, 'refund_period_note' => '沿用规则中心的跨月退款口径；跨月补扣待财务核验',
    ];
}

function pa_record(array $ctx, array $result, $applied = false)
{
    $evidence = pa_evidence($ctx);
    $json = json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $reasons = json_encode($result['reasons'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $fingerprint = hash('sha256', $json . $reasons . $result['state']);
    $q = db()->prepare('SELECT fingerprint,applied_at FROM project_auto_reviews WHERE order_id=?'); $q->execute([$ctx['order']['id']]); $old = $q->fetch();
    $state = $result['state'];
    if ($state === 'settled' && !empty($old['applied_at'])) $state = 'auto_passed';
    db()->prepare('INSERT INTO project_auto_reviews (order_id,state,policy_version,checked_row_version,checked_source_at,fingerprint,reasons_json,evidence_json,checked_at,applied_at) VALUES (?,?,?,?,?,?,?,?,NOW(),?) ON DUPLICATE KEY UPDATE state=VALUES(state),policy_version=VALUES(policy_version),checked_row_version=VALUES(checked_row_version),checked_source_at=VALUES(checked_source_at),fingerprint=VALUES(fingerprint),reasons_json=VALUES(reasons_json),evidence_json=VALUES(evidence_json),checked_at=NOW(),applied_at=COALESCE(VALUES(applied_at),applied_at)')
        ->execute([$ctx['order']['id'], $state, PA_POLICY_VERSION, $ctx['order']['row_version'], $ctx['source']['synced_at'] ?? null, $fingerprint, $reasons, $json, $applied ? date('Y-m-d H:i:s') : null]);
    if (!$old || $old['fingerprint'] !== $fingerprint) {
        ps_audit('order', $ctx['order']['id'], $applied ? 'auto_review_passed' : 'auto_review_check', ['type' => 'system', 'id' => 0, 'role' => 'finance'], ['policy_version' => PA_POLICY_VERSION, 'state' => $state, 'reasons' => $result['reasons'], 'evidence' => $evidence]);
    }
}

/** Dry checks have zero writes. Apply re-reads and locks all evidence, never overwrites financials. */
function pa_retryable_database_error(Throwable $e)
{
    return $e instanceof PDOException && ((string)$e->getCode() === '40001' || (int)($e->errorInfo[1] ?? 0) === 1213);
}

function pa_check_order($orderId, $apply = false, $persist = false)
{
    $outerTransaction = db()->inTransaction();
    for ($attempt = 0; $attempt < 3; $attempt++) {
        try { return pa_check_order_once($orderId, $apply, $persist); }
        catch (PDOException $e) {
            // A MySQL deadlock can invalidate the entire parent transaction: never replay it.
            if ($outerTransaction || $attempt === 2 || !pa_retryable_database_error($e)) throw $e;
            // Each isolated attempt rolled back completely; reload all financial facts.
            usleep(50000 * ($attempt + 1));
        }
    }
}

function pa_check_order_once($orderId, $apply = false, $persist = false)
{
    // The rollout/pause switch also gates explicit automatic processing, not just cron.
    if ($apply && !ps_setting_get('auto_review_enabled', false)) { $apply = false; $persist = true; }
    $p = db(); $nested = $p->inTransaction(); $transaction = $apply || $persist;
    if ($transaction) { if ($nested) $p->exec('SAVEPOINT auto_review_order'); else $p->beginTransaction(); }
    try {
        $ctx = pa_context($orderId, $transaction); $result = pa_evaluate($ctx); $applied = false;
        if ($apply && $result['can_apply']) {
            if ($result['receipt_to_add_cents'] > 0) {
                $p->prepare("INSERT INTO project_cash_movements (order_id,movement_type,amount,note,review_status,submitted_by_type,submitted_by_id,reviewed_at) VALUES (?,'receipt',?,'系统核对店铺实付字段确认收款（非售价推算）','approved','system',0,NOW())")
                    ->execute([$orderId, $result['receipt_to_add_cents'] / 100]);
                $cashId = (int)$p->lastInsertId();
                $p->prepare("INSERT INTO project_auto_cash_evidence (order_id,movement_type,cash_movement_id,source_order_id,source_key,amount,evidence_hash,created_at) VALUES (?,'receipt',?,?,?,?,?,NOW())")
                    ->execute([$orderId, $cashId, $ctx['payment']['references'][0]['source_order_id'], $ctx['payment']['references'][0]['source_key'], $result['receipt_to_add_cents'] / 100, hash('sha256', json_encode($ctx['payment']))]);
                ps_recalculate_cash($orderId);
            }
            // Original order month only; locked months never silently roll forward.
            ps_approve_order($orderId, ['type' => 'system', 'id' => 0, 'role' => 'finance'], substr($ctx['order']['order_date'], 0, 7));
            $ctx = pa_context($orderId, true); $result = pa_evaluate($ctx); $result['state'] = 'auto_passed'; $applied = true;
        }
        if ($transaction) pa_record($ctx, $result, $applied);
        if ($transaction) { if ($nested) $p->exec('RELEASE SAVEPOINT auto_review_order'); else $p->commit(); }
        $result['order_id'] = (int)$orderId; $result['applied'] = $applied;
        return $result;
    } catch (Throwable $e) {
        if ($transaction && $p->inTransaction()) { if ($nested) $p->exec('ROLLBACK TO SAVEPOINT auto_review_order'); else $p->rollBack(); }
        throw $e;
    }
}

function pa_batch($limit = 200, $apply = false, $persist = false, $month = '')
{
    $limit = max(1, min(10000, (int)$limit));
    $where = ''; $params = [];
    if ($month !== '') {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)) throw new RuntimeException('月份无效');
        $where = " WHERE o.order_date>=? AND o.order_date<DATE_ADD(?,INTERVAL 1 MONTH)"; $params = [$month . '-01', $month . '-01'];
    }
    $join = pa_storage_available() ? ' LEFT JOIN project_auto_reviews a ON a.order_id=o.id' : '';
    $changedChildren = " OR EXISTS(SELECT 1 FROM project_participants p WHERE p.order_id=o.id AND p.created_at>a.checked_at) OR EXISTS(SELECT 1 FROM project_costs c WHERE c.order_id=o.id AND c.created_at>a.checked_at) OR EXISTS(SELECT 1 FROM project_cash_movements m WHERE m.order_id=o.id AND m.created_at>a.checked_at) OR EXISTS(SELECT 1 FROM project_audit_logs l WHERE l.entity_type='order' AND l.entity_id=o.id AND l.created_at>a.checked_at AND l.action NOT IN('auto_review_check','auto_review_passed'))";
    $sort = $join ? "CASE WHEN a.order_id IS NULL OR a.checked_row_version<>o.row_version OR a.policy_version<>'" . PA_POLICY_VERSION . "' OR NOT(a.checked_source_at<=>s.synced_at) OR o.updated_at>a.checked_at" . $changedChildren . " THEN 0 ELSE 1 END,a.checked_at ASC," : '';
    $q = db()->prepare('SELECT o.id FROM project_orders o LEFT JOIN project_order_sources s ON s.order_id=o.id' . $join . $where . ' ORDER BY ' . $sort . 'o.id ASC LIMIT ' . $limit);
    $q->execute($params); $ids = $q->fetchAll(PDO::FETCH_COLUMN);
    $out = ['policy_version' => PA_POLICY_VERSION, 'dry_run' => !$apply && !$persist, 'checked' => 0, 'applied' => 0, 'states' => [], 'reason_counts' => [], 'errors' => []];
    foreach ($ids as $id) {
        try {
            $r = pa_check_order((int)$id, $apply, $persist); $out['checked']++; $out['applied'] += $r['applied'] ? 1 : 0;
            $out['states'][$r['state']] = ($out['states'][$r['state']] ?? 0) + 1;
            foreach ($r['reasons'] as $reason) $out['reason_counts'][$reason['code']] = ($out['reason_counts'][$reason['code']] ?? 0) + 1;
        } catch (Throwable $e) {
            $message = $e instanceof PDOException ? '数据库核对失败；本单事务已回滚，详见服务器日志' : mb_substr($e->getMessage(), 0, 200);
            $out['errors'][] = ['order_id' => (int)$id, 'reason' => $message];
            if ($apply || $persist) {
                try { pa_record(pa_context((int)$id), ['state'=>'exception','reasons'=>[['code'=>'processing_failed','kind'=>'exception','text'=>$message]]]); }
                catch (Throwable $recordError) { error_log('auto_review_error_record order=' . (int)$id . ' ' . $recordError->getMessage()); }
            }
            error_log('auto_review order=' . (int)$id . ' ' . $e->getMessage());
        }
    }
    return $out;
}

function pa_view(array $row)
{
    $state = $row['auto_review_state'] ?? 'queued';
    $stale = isset($row['auto_review_version']) && ((int)$row['auto_review_version'] !== (int)$row['row_version'] || ($row['auto_review_policy'] ?? '') !== PA_POLICY_VERSION || ($row['auto_review_source_at'] ?? null) !== ($row['source_synced_at'] ?? null));
    if ($stale) $state = 'queued';
    if ($state === 'queued' && in_array($row['settlement_status'], ['approved', 'locked'], true)) $state = 'settled';
    $reasons = $stale ? [] : (json_decode((string)($row['auto_review_reasons'] ?? ''), true) ?: []);
    [$label, $tone] = pa_state_meta($state);
    return ['state' => $state, 'label' => $label, 'tone' => $tone, 'reasons' => $reasons, 'checked_at' => $row['auto_review_checked_at'] ?? null];
}

/** Ancillary review never rolls back or blocks a successful upload/save. */
function pa_after_save(array $ids)
{
    if (!pa_storage_available()) return;
    $apply = (bool)ps_setting_get('auto_review_enabled', false);
    foreach (array_slice(array_unique(array_map('intval', $ids)), 0, 25) as $id) {
        try { pa_check_order($id, $apply, true); }
        catch (Throwable $e) { error_log('auto_review_after_save order=' . $id . ' ' . $e->getMessage()); }
    }
}
