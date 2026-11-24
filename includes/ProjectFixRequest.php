<?php
/**
 * 分成更正申请：合作人员在“逐单项目分成明细”里发现某张订单算得不对，填写“他认为正确的本单分成”和 / 或“其他项”（补助、扣款等）及原因提交；
 * 财务在申请列表里核对后通过 / 驳回。通过后：
 *  - 本单分成金额：按“申请的正确金额 − 系统当前已计金额（快照 + 此前的调整）”记一笔分成调整，计入申请对应的月份（月份已锁定则顺延到最早未锁月份）；
 *  - 其他项：记为该月的手工项（报酬表里能看到、可删除），金额正数为补助、负数为扣款。
 * 驳回时写明原因并站内信通知申请人。申请不会自动改任何金额，必须财务通过。
 */
require_once __DIR__ . '/ProjectSettlement.php';

function pfr_ensure()
{
    static $done = false;
    if ($done) return;
    db()->exec("CREATE TABLE IF NOT EXISTS project_fix_requests (
        id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, employee_id INT NOT NULL, commission_group VARCHAR(30) NOT NULL DEFAULT '',
        payroll_month CHAR(7) NOT NULL, current_amount DECIMAL(12,2) NOT NULL DEFAULT 0, claimed_amount DECIMAL(12,2) NULL,
        extra_label VARCHAR(60) NOT NULL DEFAULT '', extra_amount DECIMAL(12,2) NULL, reason VARCHAR(600) NOT NULL DEFAULT '',
        status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending', handled_by VARCHAR(60) NOT NULL DEFAULT '', handled_note VARCHAR(300) NOT NULL DEFAULT '', handled_at DATETIME NULL,
        created_by_type VARCHAR(20) NOT NULL DEFAULT '', created_by_id INT NOT NULL DEFAULT 0, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_status (status, id), KEY idx_employee (employee_id, payroll_month), KEY idx_order (order_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** 本人在这张订单上“系统当前已计”的分成：快照 + 此前的调整（按组）。返回 [组 => 金额]。 */
function pfr_current($orderId, $employeeId)
{
    $out = [];
    $s = db()->prepare('SELECT commission_group,SUM(commission_amount) a FROM project_commission_snapshots WHERE order_id=? AND employee_id=? GROUP BY commission_group'); $s->execute([(int)$orderId, (int)$employeeId]);
    foreach ($s->fetchAll() as $r) $out[$r['commission_group']] = (float)$r['a'];
    $a = db()->prepare('SELECT commission_group,SUM(amount) a FROM project_commission_adjustments WHERE order_id=? AND employee_id=? GROUP BY commission_group'); $a->execute([(int)$orderId, (int)$employeeId]);
    foreach ($a->fetchAll() as $r) $out[$r['commission_group']] = ($out[$r['commission_group']] ?? 0.0) + (float)$r['a'];
    return $out;
}

function pfr_can_submit(array $actor, $orderId, $employeeId)
{
    if (($actor['role'] ?? '') === 'finance') return true;
    if ((int)($actor['employee_id'] ?? 0) !== (int)$employeeId || (int)$employeeId <= 0) return false;
    $q = db()->prepare('SELECT 1 FROM project_participants WHERE order_id=? AND employee_id=? LIMIT 1'); $q->execute([(int)$orderId, (int)$employeeId]);
    return (bool)$q->fetchColumn();
}

function pfr_submit(array $actor, $orderId, $employeeId, $month, $claimed, $extraLabel, $extraAmount, $reason)
{
    pfr_ensure();
    $orderId = (int)$orderId; $employeeId = (int)$employeeId;
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$month)) throw new RuntimeException('月份不正确');
    if (!pfr_can_submit($actor, $orderId, $employeeId)) throw new RuntimeException('只能为自己参与的订单申请更正');
    $reason = trim((string)$reason);
    if (mb_strlen($reason) < 4 || mb_strlen($reason) > 600) throw new RuntimeException('请写明原因（4～600 字），如：成本应按售价 65%、服务费 3% 计，应为 10.24');
    $claimed = trim((string)$claimed); $extraAmount = trim((string)$extraAmount); $extraLabel = trim((string)$extraLabel);
    if ($claimed !== '' && !preg_match('/^-?\d{1,9}(?:\.\d{1,2})?$/', $claimed)) throw new RuntimeException('正确的本单分成请填数字，最多两位小数');
    if ($extraAmount !== '' && (!preg_match('/^-?\d{1,9}(?:\.\d{1,2})?$/', $extraAmount) || (float)$extraAmount == 0.0)) throw new RuntimeException('其他项金额请填非零数字（正数补助、负数扣款）');
    if ($extraAmount !== '' && ($extraLabel === '' || mb_strlen($extraLabel) > 60)) throw new RuntimeException('其他项请写名称（不超过 60 字），如：定制补助');
    if ($claimed === '' && $extraAmount === '') throw new RuntimeException('请至少填写“正确的本单分成”或“其他项”');
    $cur = pfr_current($orderId, $employeeId);
    $group = '';
    if ($claimed !== '') {
        if (!$cur) throw new RuntimeException('这张订单还没有审核生成分成，不能直接改金额；请先请财务审核订单，或只填“其他项”');
        $group = array_keys($cur)[0];
    }
    $dup = db()->prepare("SELECT 1 FROM project_fix_requests WHERE order_id=? AND employee_id=? AND status='pending' LIMIT 1"); $dup->execute([$orderId, $employeeId]);
    if ($dup->fetchColumn()) throw new RuntimeException('这张订单已有一条待财务处理的更正申请，请等处理后再提交');
    db()->prepare('INSERT INTO project_fix_requests (order_id,employee_id,commission_group,payroll_month,current_amount,claimed_amount,extra_label,extra_amount,reason,created_by_type,created_by_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$orderId, $employeeId, $group, $month, $group !== '' ? round($cur[$group], 2) : 0, $claimed !== '' ? $claimed : null, $extraLabel, $extraAmount !== '' ? $extraAmount : null, $reason, $actor['type'] ?? '', (int)($actor['id'] ?? 0)]);
    $id = (int)db()->lastInsertId();
    ps_audit('fix_request', $id, 'submit', $actor, ['order_id' => $orderId, 'employee_id' => $employeeId, 'month' => $month, 'claimed' => $claimed, 'extra' => [$extraLabel, $extraAmount]]);
    return $id;
}

function pfr_message($employeeId, $title, $body, $key)
{
    try { db()->prepare('INSERT IGNORE INTO project_messages (employee_id,category,title,body,link,dedupe_key) VALUES (?,?,?,?,?,?)')->execute([(int)$employeeId, 'fix_request', mb_substr($title, 0, 160), $body, '/project/fix_requests.php', $key]); } catch (Throwable $e) { /* 站内信失败不影响处理 */ }
}

/** 财务处理：approved 时记调整 / 手工项。返回提示文字。 */
function pfr_handle(array $actor, $id, $decision, $note)
{
    if (($actor['role'] ?? '') !== 'finance') throw new RuntimeException('仅财务可处理');
    pfr_ensure();
    if (!in_array($decision, ['approved', 'rejected'], true)) throw new RuntimeException('操作无效');
    $note = trim((string)$note);
    if ($decision === 'rejected' && mb_strlen($note) < 2) throw new RuntimeException('驳回请写明原因');
    $pdo = db(); $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT r.*,o.order_no FROM project_fix_requests r JOIN project_orders o ON o.id=r.order_id WHERE r.id=? FOR UPDATE'); $q->execute([(int)$id]); $r = $q->fetch();
        if (!$r || $r['status'] !== 'pending') throw new RuntimeException('申请不存在或已处理');
        $result = '';
        if ($decision === 'approved') {
            require_once __DIR__ . '/settlement/adjustment.php';
            $month = ps_next_open_month($r['payroll_month']);
            $parts = [];
            if ($r['claimed_amount'] !== null) {
                $cur = pfr_current((int)$r['order_id'], (int)$r['employee_id']);
                $now = $cur[$r['commission_group']] ?? null;
                if ($now === null) throw new RuntimeException('这张订单已没有该人的分成记录，无法按金额调整');
                $delta = round((float)$r['claimed_amount'] - $now, 2);
                if (abs($delta) >= 0.005) {
                    $pdo->prepare('INSERT INTO project_commission_adjustments (order_id,employee_id,commission_group,amount,payroll_month,reason,calc_note,created_by_admin) VALUES (?,?,?,?,?,?,?,?)')
                        ->execute([(int)$r['order_id'], (int)$r['employee_id'], $r['commission_group'], $delta, $month, '分成更正申请 #' . (int)$r['id'] . '：' . mb_substr($r['reason'], 0, 150), '已计 ¥' . number_format($now, 2, '.', '') . '，更正为 ¥' . number_format((float)$r['claimed_amount'], 2, '.', ''), (int)$actor['id']]);
                    $parts[] = '本单分成调整 ' . ($delta > 0 ? '+' : '') . number_format($delta, 2, '.', '');
                } else $parts[] = '本单分成与申请金额一致，无需调整';
            }
            if ($r['extra_amount'] !== null) {
                require_once __DIR__ . '/ProjectPayrollExtras.php';
                ps_payroll_extras_action('add_manual_item', $month, (int)$r['employee_id'], ['label' => $r['extra_label'], 'amount' => (string)$r['extra_amount'], 'note' => '更正申请 #' . (int)$r['id'] . '（订单 ' . $r['order_no'] . '）'], $actor);
                $parts[] = '其他项“' . $r['extra_label'] . '” ' . ((float)$r['extra_amount'] > 0 ? '+' : '') . number_format((float)$r['extra_amount'], 2, '.', '');
            }
            $result = implode('；', $parts) . '（计入 ' . $month . '）';
        }
        $pdo->prepare('UPDATE project_fix_requests SET status=?,handled_by=?,handled_note=?,handled_at=NOW() WHERE id=?')->execute([$decision, ($actor['type'] ?? '') === 'admin' ? '财务' : '财务', mb_substr($note !== '' ? $note : $result, 0, 300), (int)$id]);
        ps_audit('fix_request', (int)$id, $decision, $actor, ['result' => $result, 'note' => $note]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    pfr_message((int)$r['employee_id'], '分成更正申请' . ($decision === 'approved' ? '已通过' : '被驳回') . '（订单 ' . $r['order_no'] . '）', $decision === 'approved' ? $result : ('原因：' . $note), 'fix_request:' . (int)$id . ':' . $decision);
    return $decision === 'approved' ? $result : '已驳回';
}

function pfr_pending_count()
{
    try { pfr_ensure(); return (int)db()->query("SELECT COUNT(*) FROM project_fix_requests WHERE status='pending'")->fetchColumn(); } catch (Throwable $e) { return 0; }
}
