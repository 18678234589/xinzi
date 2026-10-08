<?php
/** Reversible financial housekeeping only. No financial ledger deletion or reversal. */
function prt_storage_available()
{
    static $ready = null;
    if ($ready === null) {
        try { db()->query('SELECT deleted_at FROM project_refund_import_rows LIMIT 1'); $ready = true; }
        catch (PDOException $e) { $ready = false; }
    }
    return $ready;
}

function prt_active_sql($prefix = '')
{
    if (!preg_match('/^(?:[a-zA-Z_][a-zA-Z0-9_]*\.)?$/D', $prefix)) throw new InvalidArgumentException('Invalid SQL alias');
    return prt_storage_available() ? ' AND ' . $prefix . 'deleted_at IS NULL' : '';
}

function prt_can_trash(array $row)
{
    return empty($row['deleted_at']) && in_array($row['review_status'] ?? '', ['pending', 'rejected'], true);
}

function prt_deleted_duplicate($fingerprint, $method, $reference, $orderId = null, $date = '', $amount = 0)
{
    if (!prt_storage_available()) return false;
    $where = 'fingerprint=?'; $args = [$fingerprint];
    if ($reference !== '') { $where .= ' OR (payment_method=? AND payment_reference=?)'; array_push($args, $method, $reference); }
    elseif ($orderId) { $where .= " OR (order_id=? AND refund_date=? AND amount=? AND payment_method=? AND payment_reference='')"; array_push($args, $orderId, $date, $amount, $method); }
    $q = db()->prepare('SELECT id FROM project_refund_import_rows WHERE deleted_at IS NOT NULL AND (' . $where . ') LIMIT 1');
    $q->execute($args); return (bool)$q->fetchColumn();
}

function prt_change($id, $restore, $note, array $actor)
{
    if (($actor['role'] ?? '') !== 'finance') throw new RuntimeException('仅财务可以移入或恢复退款回收站');
    if (!prt_storage_available()) throw new RuntimeException('退款回收站尚未安装');
    if ((int)$id <= 0) throw new RuntimeException('请选择退款记录');
    $p = db(); $nested = $p->inTransaction();
    if ($nested) $p->exec('SAVEPOINT refund_trash'); else $p->beginTransaction();
    try {
        $q = $p->prepare('SELECT * FROM project_refund_import_rows WHERE id=? FOR UPDATE'); $q->execute([(int)$id]); $row = $q->fetch();
        if (!$row) throw new RuntimeException('退款记录不存在');
        $changed = false;
        if ($restore) {
            if (!empty($row['deleted_at'])) {
                if (!in_array($row['review_status'], ['pending', 'rejected'], true)) throw new RuntimeException('已入账的退款不能直接恢复，请财务核查');
                $p->prepare('UPDATE project_refund_import_rows SET deleted_at=NULL WHERE id=?')->execute([(int)$id]); $changed = true;
            }
        } elseif (empty($row['deleted_at'])) {
            if (!prt_can_trash($row)) throw new RuntimeException('这笔退款已审核入账或结案，不可直接删除；请先按财务更正流程核实，避免影响已结算金额');
            $note = mb_substr(trim((string)$note) ?: '误登记，移入回收站', 0, 500);
            $p->prepare('UPDATE project_refund_import_rows SET deleted_at=NOW(),deleted_by_type=?,deleted_by_id=?,deleted_note=? WHERE id=?')
                ->execute([$actor['type'], (int)$actor['id'], $note, (int)$id]); $changed = true;
        }
        if ($changed) ps_audit('refund_import', (int)$id, $restore ? 'trash_restore' : 'trash_move', $actor, [
            'order_id' => $row['order_id'], 'order_no' => $row['order_no'], 'amount' => $row['amount'],
            'previous_status' => $row['review_status'], 'note' => $restore ? '恢复退款记录，重新等待原流程核对' : $note,
        ]);
        if ($nested) $p->exec('RELEASE SAVEPOINT refund_trash'); else $p->commit();
        return ['changed' => $changed, 'order_id' => (int)($row['order_id'] ?? 0), 'restored' => (bool)$restore];
    } catch (Throwable $e) {
        if ($p->inTransaction()) { if ($nested) $p->exec('ROLLBACK TO SAVEPOINT refund_trash'); else $p->rollBack(); }
        throw $e;
    }
}

/** A trash/restore operation is complete even if its order still needs supplementation. */
function prt_after_change(array $result)
{
    if (!$result['changed'] || !$result['order_id']) return;
    try { require_once __DIR__ . '/ProjectAutoReview.php'; pa_after_save([$result['order_id']]); }
    catch (Throwable $e) { error_log('refund_trash_order_review: ' . $e->getMessage()); }
}
