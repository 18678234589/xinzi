<?php
/**
 * 订单资料更正：上传表格与原单的售价 / 店铺 / 付款昵称不一致时，上传人在预览里直接选择“按表格更正原单”。
 * 财务 / 管理员上传：立即更正；其他人：提交申请，财务在“分成更正申请 → 订单资料更正”里一键确认。
 * 只允许改草稿 / 待审订单；已审核、已锁定的订单不动（避免影响已结算的数据）。
 */
require_once __DIR__ . '/ProjectSettlement.php';
require_once __DIR__ . '/ProjectOrderSource.php';

const POF_FIELDS = ['contract_amount' => '售价', 'shop' => '店铺', 'payment_nickname' => '付款昵称'];

function pof_ensure()
{
    static $done = false;
    if ($done) return;
    db()->exec("CREATE TABLE IF NOT EXISTS project_order_fix_requests (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      order_id BIGINT UNSIGNED NOT NULL,
      order_no VARCHAR(100) NOT NULL,
      changes_json TEXT NOT NULL,
      old_json TEXT NOT NULL,
      note VARCHAR(500) NOT NULL DEFAULT '',
      applicant_type VARCHAR(20) NOT NULL,
      applicant_id INT NOT NULL,
      applicant_employee_id INT NULL,
      applicant_name VARCHAR(80) NOT NULL DEFAULT '',
      status ENUM('pending','resolved','rejected') NOT NULL DEFAULT 'pending',
      handler_name VARCHAR(80) NULL,
      handle_note TEXT NULL,
      handled_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_fix_status (status, created_at),
      KEY idx_fix_order (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function pof_money($v) { return '¥' . number_format((float)$v, 2, '.', ''); }

/** 预览里的冲突字段明细：[ [key,label,old,new,display_old,display_new,checked] ] */
function pof_fields(array $existing, array $row, array $conflicts)
{
    $out = [];
    foreach ($conflicts as $c) {
        if ($c === '售价') $out[] = ['contract_amount', '售价', (string)$existing['contract_amount'], trim((string)$row['contract_amount']), pof_money($existing['contract_amount']), pof_money($row['contract_amount']), true];
        elseif ($c === '店铺') $out[] = ['shop', '店铺', (string)($existing['shop'] ?? ''), trim((string)($row['shop'] ?? '')), (string)($existing['shop'] ?? ''), trim((string)($row['shop'] ?? '')), false];
        elseif ($c === '付款昵称') $out[] = ['payment_nickname', '付款昵称', (string)($existing['payment_nickname'] ?? ''), trim((string)($row['payment_nickname'] ?? '')), (string)($existing['payment_nickname'] ?? ''), trim((string)($row['payment_nickname'] ?? '')), false];
    }
    return $out;
}

/**
 * “合并录入后拆分”提示：原单售价 = 本行售价 + 本次上传里另一行（或系统里同客户另一单）的售价。
 * 例如原单 888 = 本行 800 + 另一行 88（订单 19821787667N）。
 */
function pof_split_hint(array $existing, array $row, array $allRows)
{
    $old = round((float)($existing['contract_amount'] ?? 0), 2); $new = round((float)($row['contract_amount'] ?? 0), 2);
    $diff = round($old - $new, 2);
    if ($new <= 0 || $diff <= 0) return '';
    foreach ($allRows as $other) {
        if (($other['order_no'] ?? '') === ($row['order_no'] ?? '') || ($other['order_no'] ?? '') === '') continue;
        if (abs(round((float)($other['contract_amount'] ?? 0), 2) - $diff) < 0.005) {
            return '原单 ' . pof_money($old) . ' = 本表 ' . pof_money($new) . '（本行）+ ' . pof_money($diff) . '（订单 ' . $other['order_no'] . '），疑似之前合并录成了一单，现在表格拆开了';
        }
    }
    return '';
}

function pof_actor_name(array $actor)
{
    if (!empty($actor['employee_id'])) { $q = db()->prepare('SELECT name FROM employees WHERE id=?'); $q->execute([(int)$actor['employee_id']]); $n = (string)$q->fetchColumn(); if ($n !== '') return $n; }
    return (string)($actor['username'] ?? '管理员');
}

/** 把更正写进原单（仅财务身份调用）。调用方负责事务。 */
function pof_apply_changes($orderId, array $changes, array $financeActor, $reason)
{
    $q = db()->prepare('SELECT id,order_no,settlement_status FROM project_orders WHERE id=? FOR UPDATE');
    $q->execute([(int)$orderId]);
    $order = $q->fetch();
    if (!$order) throw new RuntimeException('订单不存在。');
    if (!in_array($order['settlement_status'], ['draft', 'review'], true)) throw new RuntimeException('订单 ' . $order['order_no'] . ' 已审核 / 锁定，不能直接更正，请到订单里走售后调整。');
    $input = [];
    foreach ($changes as $k => $v) if (isset(POF_FIELDS[$k])) $input[$k] = $v;
    if (!$input) throw new RuntimeException('没有要更正的内容。');
    $fin = ['type' => 'admin', 'id' => (int)($financeActor['id'] ?? 0), 'employee_id' => null, 'role' => 'finance'];
    $changed = ps_save_customer_intake((int)$orderId, $input, $fin, true, false);
    ps_audit('order', (int)$orderId, 'intake_fix', $financeActor, ['changes' => $input, 'applied' => $changed, 'reason' => $reason]);
    return $changed;
}

/** 提交更正：财务 / 管理员直接生效，其他人进入待确认。返回 ['mode'=>'applied'|'pending','id'=>…]。 */
function pof_submit(array $actor, $orderId, array $changes, $note)
{
    pof_ensure();
    $note = mb_substr(trim((string)$note), 0, 500);
    $clean = [];
    foreach ($changes as $k => $v) {
        if (!isset(POF_FIELDS[$k])) continue;
        $v = trim((string)$v);
        if ($v === '') continue;
        if ($k === 'contract_amount' && !preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/', $v)) throw new RuntimeException('售价须为非负数，最多两位小数。');
        if ($k !== 'contract_amount' && mb_strlen($v) > 200) throw new RuntimeException(POF_FIELDS[$k] . '过长。');
        $clean[$k] = $v;
    }
    if (!$clean) throw new RuntimeException('请至少勾选一项要更正的内容。');
    $order = ps_order((int)$orderId, $actor); // 非财务须是该订单参与人，否则 403
    $oq = db()->prepare('SELECT o.order_no,o.shop,o.contract_amount,o.settlement_status,s.payment_nickname FROM project_orders o LEFT JOIN project_order_sources s ON s.order_id=o.id WHERE o.id=?');
    $oq->execute([(int)$orderId]); $cur = $oq->fetch();
    if (!in_array($cur['settlement_status'], ['draft', 'review'], true)) throw new RuntimeException('这个订单已审核 / 锁定，不能直接更正，请联系财务走售后调整。');
    $old = [];
    foreach ($clean as $k => $v) $old[$k] = (string)($cur[$k] ?? '');
    if (($actor['role'] ?? '') === 'finance') {
        $pdo = db(); $pdo->beginTransaction();
        try { pof_apply_changes((int)$orderId, $clean, $actor, $note !== '' ? $note : '上传表格预览中按表格更正'); $pdo->commit(); }
        catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        return ['mode' => 'applied', 'id' => 0, 'order_no' => $cur['order_no']];
    }
    $dup = db()->prepare("SELECT 1 FROM project_order_fix_requests WHERE order_id=? AND applicant_type=? AND applicant_id=? AND status='pending'");
    $dup->execute([(int)$orderId, $actor['type'], (int)$actor['id']]);
    if ($dup->fetchColumn()) throw new RuntimeException('这个订单你已提交过更正，请等财务确认后点“重新核对”。');
    db()->prepare('INSERT INTO project_order_fix_requests (order_id,order_no,changes_json,old_json,note,applicant_type,applicant_id,applicant_employee_id,applicant_name) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([(int)$orderId, $cur['order_no'], json_encode($clean, JSON_UNESCAPED_UNICODE), json_encode($old, JSON_UNESCAPED_UNICODE), $note, $actor['type'], (int)$actor['id'], $actor['employee_id'] ?? null, pof_actor_name($actor)]);
    $id = (int)db()->lastInsertId();
    ps_audit('order', (int)$orderId, 'intake_fix_request', $actor, ['request_id' => $id, 'changes' => $clean]);
    return ['mode' => 'pending', 'id' => $id, 'order_no' => $cur['order_no']];
}

function pof_requests($status)
{
    pof_ensure();
    $where = in_array($status, ['pending', 'resolved', 'rejected'], true) ? 'status=' . db()->quote($status) : '1=1';
    return db()->query("SELECT * FROM project_order_fix_requests WHERE $where ORDER BY (status='pending') DESC, id DESC LIMIT 200")->fetchAll();
}

function pof_pending_count()
{
    try { pof_ensure(); return (int)db()->query("SELECT COUNT(*) FROM project_order_fix_requests WHERE status='pending'")->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}

function pof_handle($id, $decision, $note, array $actor)
{
    if (($actor['role'] ?? '') !== 'finance') throw new RuntimeException('仅管理员或财务可以处理。');
    pof_ensure();
    if (!in_array($decision, ['resolved', 'rejected'], true)) throw new RuntimeException('处理结果无效。');
    $note = trim((string)$note);
    if ($decision === 'rejected' && $note === '') throw new RuntimeException('不采纳时请写明原因。');
    $pdo = db(); $pdo->beginTransaction();
    try {
        $q = $pdo->prepare("SELECT * FROM project_order_fix_requests WHERE id=? AND status='pending' FOR UPDATE"); $q->execute([(int)$id]); $row = $q->fetch();
        if (!$row) throw new RuntimeException('申请不存在或已处理。');
        if ($decision === 'resolved') pof_apply_changes((int)$row['order_id'], json_decode($row['changes_json'], true) ?: [], $actor, '采纳申请 #' . (int)$id . ($note !== '' ? '：' . $note : ''));
        $handler = pof_actor_name($actor);
        $pdo->prepare("UPDATE project_order_fix_requests SET status=?,handler_name=?,handle_note=?,handled_at=NOW() WHERE id=?")->execute([$decision, $handler, $note, (int)$id]);
        ps_audit('order', (int)$row['order_id'], 'intake_fix_' . $decision, $actor, ['request_id' => (int)$id, 'note' => $note]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    if (!empty($row['applicant_employee_id'])) {
        try {
            db()->prepare('INSERT IGNORE INTO project_messages (employee_id,category,title,body,link,dedupe_key) VALUES (?,?,?,?,?,?)')->execute([
                (int)$row['applicant_employee_id'], 'order_fix', '订单 ' . $row['order_no'] . ' 的资料更正已' . ($decision === 'resolved' ? '确认' : '回复（未采纳）'),
                ($decision === 'resolved' ? '原单已按表格更正，请回到上传页点“重新核对”，这一行就可以正常导入了。' : '处理回复：' . $note) . '（处理人：' . $handler . '）', '/project/import.php', 'order_fix_done:' . (int)$id]);
        } catch (Throwable $e) { /* 站内信表异常不影响处理 */ }
    }
}
