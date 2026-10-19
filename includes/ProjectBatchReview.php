<?php
/**
 * 批量审核申请：合作人员某月还有没审核通过的订单时，在“订单审核申请”页看到每单卡在哪、怎么解决，并向财务提交批量审核申请；
 * 财务在同一页看到申请（侧栏 / 订单页有待办提示），抽查后一键“全部通过”：对所选订单登记收款（按售价）、标记交付完成并审核生成分成。
 * 财务的这个动作等同于在订单页手动登记收款 + 审核，全部记审计；“异常”订单默认不勾选，需要单独处理。
 */
require_once __DIR__ . '/ProjectSettlement.php';
require_once __DIR__ . '/ProjectAutoReview.php';

function pbr_ensure()
{
    static $done = false;
    if ($done) return;
    try { db()->query('SELECT 1 FROM project_batch_reviews LIMIT 0'); $done = true; return; }
    catch (PDOException $e) { if ((string)$e->getCode() !== '42S02') throw $e; }
    db()->exec("CREATE TABLE IF NOT EXISTS project_batch_reviews (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      employee_id INT NOT NULL, month CHAR(7) NOT NULL,
      note VARCHAR(500) NOT NULL DEFAULT '',
      order_count INT NOT NULL DEFAULT 0, amount DECIMAL(14,2) NOT NULL DEFAULT 0,
      status ENUM('pending','done','rejected') NOT NULL DEFAULT 'pending',
      handled_by VARCHAR(80) NULL, handled_note VARCHAR(500) NULL, handled_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_status (status, created_at), KEY idx_emp_month (employee_id, month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** 某人某月参与的、还没审核通过的订单，附自动核对状态和卡点说明。 */
/**
 * 批量审核前的准备（调用方持有事务）：财务/授权人员勾选通过即视为认可——
 * 未确认的资源按“无需另计”确认并同步商品成本、待审核成本一并通过。收款/退款仍须单独审核；SSL 凭证等仍由 ps_approve_order 把关。
 */
function pbr_prepare_order($oid, array $actor, $adminId, $who)
{
    $pdo = db();
    $row = $pdo->prepare('SELECT project_type FROM project_orders WHERE id=?'); $row->execute([$oid]);
    if (!empty(ps_business_catalog()[ps_business_normalize((string)$row->fetchColumn())]['resources'])) {
        $res = $pdo->prepare('SELECT domain_mode FROM project_order_resources WHERE order_id=?'); $res->execute([$oid]);
        $mode = $res->fetchColumn();
        if ($mode === false || $mode === 'pending') {
            require_once __DIR__ . '/ProjectIntake.php'; poi_ensure();
            ps_intake_confirm_resources($oid, 'none', 0, 0, $actor);
            ps_audit('order', $oid, 'confirm_resources', $actor, ['domain_mode' => 'none', 'via' => 'batch_review']);
        }
    }
    $pc = $pdo->prepare("SELECT id FROM project_costs WHERE order_id=? AND review_status='pending'"); $pc->execute([$oid]);
    foreach ($pc->fetchAll(PDO::FETCH_COLUMN) as $costId) {
        $pdo->prepare("UPDATE project_costs SET review_status='approved',reviewed_by_admin=?,review_note=? WHERE id=? AND review_status='pending'")->execute([$adminId, $who . '批量审核通过', (int)$costId]);
        ps_audit('cost', (int)$costId, 'review', $actor, ['decision' => 'approved', 'via' => 'batch_review']);
    }
}

/** 授权审核人员（如网站人事）只能审核指定业务、且不含本人参与的订单；财务不受限。 */
function pbr_scope($actor)
{
    if (($actor['role'] ?? '') === 'finance') return ['all' => true];
    $emp = (int)($actor['employee_id'] ?? 0);
    if ($emp <= 0) return null;
    try {
        $q = db()->prepare('SELECT business_name FROM project_batch_review_delegates WHERE employee_id=?'); $q->execute([$emp]);
        $biz = $q->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { return null; }
    return $biz ? ['businesses' => $biz, 'self' => $emp] : null;
}

function pbr_scope_sql($scope, &$params)
{
    $params = [];
    if (!$scope || !empty($scope['all'])) return '';
    $params = array_merge($scope['businesses'], [(int)$scope['self']]);
    return ' AND o.project_type IN (' . implode(',', array_fill(0, count($scope['businesses']), '?')) . ') AND NOT EXISTS (SELECT 1 FROM project_participants sp WHERE sp.order_id=o.id AND sp.employee_id=?)';
}

function pbr_orders($employeeId, $month, $scope = null)
{
    $q = db()->prepare("SELECT o.id,o.order_no,o.order_date,o.project_type,o.order_kind,o.contract_amount,o.receipt_amount,o.refund_amount,o.delivery_status,o.settlement_status,
        COALESCE(s.payment_nickname,'') nick, a.state review_state, a.reasons_json
        FROM project_orders o JOIN project_participants p ON p.order_id=o.id LEFT JOIN project_order_sources s ON s.order_id=o.id LEFT JOIN project_auto_reviews a ON a.order_id=o.id
        WHERE p.employee_id=? AND o.order_date>=? AND o.order_date<? AND o.settlement_status NOT IN ('approved','locked')" . pbr_scope_sql($scope, $extra) . " GROUP BY o.id ORDER BY o.order_date,o.id");
    $q->execute(array_merge([(int)$employeeId, $month . '-01', date('Y-m-d', strtotime($month . '-01 +1 month'))], $extra));
    $rows = $q->fetchAll();
    foreach ($rows as &$r) {
        $why = [];
        if ((float)$r['receipt_amount'] - (float)$r['refund_amount'] <= 0) $why[] = (float)$r['contract_amount'] > 0 ? '还没有确认的收款' : ($r['order_kind'] === '编辑对接' ? '' : '没有售价，需补售价（或确认这是只按每单补助结算的单）');
        if ($r['delivery_status'] !== 'finished') $why[] = '还没确认业务已完成';
        $exception = $r['review_state'] === 'exception';
        foreach (json_decode((string)$r['reasons_json'], true) ?: [] as $reason) if (($reason['kind'] ?? '') === 'exception') $why[] = (string)$reason['text'];
        $r['why'] = array_values(array_unique(array_filter($why, 'strlen')));
        $r['exception'] = $exception;
    }
    unset($r);
    return $rows;
}

function pbr_submit(array $actor, $month, $note)
{
    pbr_ensure();
    $eid = (int)($actor['employee_id'] ?? 0);
    if ($eid <= 0 || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$month)) throw new RuntimeException('请选择要申请的月份');
    $orders = pbr_orders($eid, $month);
    if (!$orders) throw new RuntimeException('这个月没有需要审核的订单');
    $sum = round(array_sum(array_column($orders, 'contract_amount')), 2);
    $note = mb_substr(trim((string)$note), 0, 500);
    $q = db()->prepare("SELECT id FROM project_batch_reviews WHERE employee_id=? AND month=? AND status='pending'"); $q->execute([$eid, $month]); $id = (int)$q->fetchColumn();
    if ($id) db()->prepare('UPDATE project_batch_reviews SET note=?,order_count=?,amount=? WHERE id=?')->execute([$note, count($orders), $sum, $id]);
    else { db()->prepare('INSERT INTO project_batch_reviews (employee_id,month,note,order_count,amount) VALUES (?,?,?,?,?)')->execute([$eid, $month, $note, count($orders), $sum]); $id = (int)db()->lastInsertId(); }
    ps_audit('batch_review', $id, 'submit', $actor, ['month' => $month, 'orders' => count($orders), 'amount' => $sum]);
    return $id;
}

function pbr_pending_count()
{
    try { pbr_ensure(); return (int)db()->query("SELECT COUNT(*) FROM project_batch_reviews WHERE status='pending'")->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}

/** 有没通过审核订单的员工（某月），供财务直接选人审核。 */
function pbr_employees($month, $scope = null)
{
    $q = db()->prepare("SELECT e.id,e.name,COUNT(DISTINCT o.id) n FROM project_orders o JOIN project_participants p ON p.order_id=o.id JOIN employees e ON e.id=p.employee_id
        WHERE o.order_date>=? AND o.order_date<? AND o.settlement_status NOT IN ('approved','locked')" . pbr_scope_sql($scope, $extra) . " GROUP BY e.id,e.name ORDER BY e.name");
    $q->execute(array_merge([$month . '-01', date('Y-m-d', strtotime($month . '-01 +1 month'))], $extra));
    return $q->fetchAll();
}

/** 财务：对所选订单登记收款（按售价）、标记交付完成、审核生成分成。返回 [通过数, [失败说明...]]。 */
function pbr_approve(array $actor, $requestId, array $orderIds, $employeeId = 0, $month = '')
{
    $scope = pbr_scope($actor);
    if (!$scope) throw new RuntimeException('只有财务或被授权人员可以审核');
    // 授权审核人员（如冯超）支持按申请或按员工月份审核，且自动按其业务范围 $scope 过滤
    $adminId = ($actor['type'] ?? '') === 'admin' ? (int)$actor['id'] : null; // 授权人员不是管理员账号，审核人记在审计里
    $who = !empty($scope['all']) ? '财务' : '授权审核人员';
    pbr_ensure();
    if ((int)$requestId > 0) {
        $q = db()->prepare("SELECT * FROM project_batch_reviews WHERE id=? AND status='pending'"); $q->execute([(int)$requestId]); $req = $q->fetch();
        if (!$req) throw new RuntimeException('申请不存在或已处理');
    } else { // 财务直接按“员工 + 月份”审核，不需要对方先提交申请
        if ((int)$employeeId <= 0 || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$month)) throw new RuntimeException('请选择员工和月份');
        $req = ['id' => 0, 'employee_id' => (int)$employeeId, 'month' => (string)$month];
    }
    $allowed = array_column(pbr_orders((int)$req['employee_id'], $req['month'], $scope), null, 'id');
    $done = 0; $failed = [];
    foreach (array_slice(array_values(array_unique(array_map('intval', $orderIds))), 0, 300) as $oid) {
        if (!isset($allowed[$oid])) continue;
        $o = $allowed[$oid];
        try {
            $pdo = db(); $pdo->beginTransaction();
            $row = $pdo->prepare('SELECT * FROM project_orders WHERE id=? FOR UPDATE'); $row->execute([$oid]); $order = $row->fetch();
            if (!$order || in_array($order['settlement_status'], ['approved', 'locked'], true)) throw new RuntimeException('已审核');
            $net = (float)$order['receipt_amount'] - (float)$order['refund_amount'];
            // 没有售价的单（如微信代写“编辑对接”：不记销售额，只按每单固定补助结算）不需要登记收款；能不能审核由 ps_approve_order 按规则判断
            if ($net <= 0 && (float)$order['contract_amount'] > 0) {
                $pdo->prepare("INSERT INTO project_cash_movements (order_id,movement_type,amount,note,review_status,submitted_by_type,submitted_by_id,reviewed_by_admin,reviewed_at) VALUES (?,'receipt',?,?,'approved',?,?,?,NOW())")
                    ->execute([$oid, round((float)$order['contract_amount'], 2), $who . '批量审核：抽查后按售价确认收款（申请 #' . (int)$req['id'] . '）', $actor['type'], $actor['id'], $adminId]);
                ps_recalculate_cash($oid);
            }
            // 财务、冯超、客服、售后及技术均有权限标注已完成；批量审核时若未完成则自动置为已完成
            if ($order['delivery_status'] !== 'finished') {
                if (!ps_can_mark_delivery_finished($actor)) {
                    throw new RuntimeException('项目尚未完成：需由技术、客服、售后或管理层先标注为“已完成”');
                }
                $pdo->prepare("UPDATE project_orders SET delivery_status='finished',row_version=row_version+1 WHERE id=?")->execute([$oid]);
                $pdo->prepare("UPDATE project_order_requests SET status='approved', reviewer_id=?, reviewed_at=NOW(), review_note='批量审核时自动通过' WHERE order_id=? AND request_type='delivery_completion' AND status='pending'")->execute([$adminId, $oid]);
            }
            pbr_prepare_order($oid, $actor, $adminId, $who);
            ps_approve_order($oid, $actor, (string)$req['month']);
            ps_audit('order', $oid, 'batch_review_approve', $actor, ['request' => (int)$req['id'], 'receipt_added' => $net <= 0, 'delegate' => empty($scope['all'])]);
            $pdo->commit(); $done++;
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            $failed[] = $o['order_no'] . '：' . ($e instanceof PDOException ? '保存失败' : $e->getMessage());
        }
    }
    if ((int)$req['id'] > 0 && !pbr_orders((int)$req['employee_id'], $req['month'])) {
        db()->prepare("UPDATE project_batch_reviews SET status='done',handled_by=?,handled_at=NOW(),handled_note=? WHERE id=?")->execute([pbr_name($actor), '已全部审核通过', (int)$req['id']]);
    }
    ps_audit('batch_review', (int)$req['id'], 'approve', $actor, ['done' => $done, 'failed' => count($failed), 'employee_id' => (int)$req['employee_id'], 'month' => $req['month']]);
    try {
        require_once __DIR__ . '/dup_feedback.php';
        pd_message((int)$req['employee_id'], $req['month'] . ' 的订单审核有进展', '财务已审核通过 ' . $done . ' 单' . ($failed ? '，另有 ' . count($failed) . ' 单需要单独处理' : '') . '。', '/project/batch_review.php?month=' . $req['month'], 'batch_review:' . (int)$req['id'] . ':' . $done . ((int)$req['id'] ? '' : ':' . date('YmdHis')));
    } catch (Throwable $e) {}
    return [$done, $failed];
}

function pbr_reject(array $actor, $requestId, $note)
{
    if (($actor['role'] ?? '') !== 'finance') throw new RuntimeException('只有财务可以处理');
    pbr_ensure();
    $note = trim((string)$note);
    if (mb_strlen($note) < 3) throw new RuntimeException('请写明需要对方补充什么');
    $q = db()->prepare("SELECT * FROM project_batch_reviews WHERE id=? AND status='pending'"); $q->execute([(int)$requestId]); $req = $q->fetch();
    if (!$req) throw new RuntimeException('申请不存在或已处理');
    db()->prepare("UPDATE project_batch_reviews SET status='rejected',handled_by=?,handled_at=NOW(),handled_note=? WHERE id=?")->execute([pbr_name($actor), mb_substr($note, 0, 500), (int)$requestId]);
    ps_audit('batch_review', (int)$requestId, 'reject', $actor, ['note' => $note]);
    try { require_once __DIR__ . '/dup_feedback.php'; pd_message((int)$req['employee_id'], $req['month'] . ' 的订单审核申请被退回', '财务说明：' . $note, '/project/batch_review.php?month=' . $req['month'], 'batch_review_rej:' . (int)$requestId); } catch (Throwable $e) {}
}

function pbr_name(array $actor)
{
    return ($actor['type'] ?? '') === 'admin' ? (string)db()->query('SELECT username FROM admins WHERE id=' . (int)$actor['id'])->fetchColumn() : 'employee#' . (int)($actor['id'] ?? 0);
}
