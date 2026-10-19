<?php
/**
 * 合作人员“申请结算”：首页一键按整月实结算提交；兼容原逐单提成申请，整月金额自动扣除旧申请，防止重复支付。
 * 财务打款后点“已结算” → 这些结算项（按人 + 项目 + 月份）在库里标注已结算，
 * 不能再次申请，并出现在本人的“已结算订单”里。申请时金额由服务器按已审核分成快照 / 售后调整重算，不信任页面传来的数字。
 * 驳回会释放订单，可重新申请；已结算的不能撤销。
 */
require_once __DIR__ . '/ProjectSettlement.php';
require_once __DIR__ . '/ProjectPayrollFlags.php';
require_once __DIR__ . '/ProjectPayrollRequest.php';

function psr_ensure()
{
    static $done = false;
    if ($done) return;
    db()->exec("CREATE TABLE IF NOT EXISTS project_settlement_requests (
        id INT AUTO_INCREMENT PRIMARY KEY, employee_id INT NOT NULL, payroll_month CHAR(7) NOT NULL,
        status ENUM('pending','settled','rejected') NOT NULL DEFAULT 'pending', amount DECIMAL(12,2) NOT NULL DEFAULT 0, order_count INT NOT NULL DEFAULT 0,
        requested_by_user INT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        settled_at DATETIME NULL, handled_by VARCHAR(60) NOT NULL DEFAULT '', note VARCHAR(200) NOT NULL DEFAULT '',
        KEY idx_status (status, created_at), KEY idx_employee (employee_id, payroll_month)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE IF NOT EXISTS project_settlement_request_items (
        id INT AUTO_INCREMENT PRIMARY KEY, request_id INT NOT NULL, employee_id INT NOT NULL, order_id BIGINT UNSIGNED NOT NULL, payroll_month CHAR(7) NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0, settled_at DATETIME NULL,
        UNIQUE KEY uk_once (employee_id, order_id, payroll_month), KEY idx_request (request_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** 某人某月的已审核订单（分成快照 + 售后调整合并到订单）及其申请 / 结算状态：state = available / pending / settled。
 * 同时汇入月度阶梯/部门提成/手工分成项，确保与首页合作商报酬表（index.php）项目分成 100% 对齐并可整体申请结算。
 */
function psr_orders($employeeId, $month)
{
    psr_ensure();
    $employeeId = (int)$employeeId;
    $out = [];
    $q = db()->prepare('SELECT c.order_id,SUM(c.commission_amount) amt FROM project_commission_snapshots c WHERE c.employee_id=? AND c.payroll_month=? GROUP BY c.order_id');
    $q->execute([$employeeId, $month]);
    foreach ($q->fetchAll() as $r) $out[(int)$r['order_id']] = (float)$r['amt'];
    $q = db()->prepare('SELECT a.order_id,SUM(a.amount) amt FROM project_commission_adjustments a WHERE a.employee_id=? AND a.payroll_month=? GROUP BY a.order_id');
    $q->execute([$employeeId, $month]);
    foreach ($q->fetchAll() as $r) $out[(int)$r['order_id']] = ($out[(int)$r['order_id']] ?? 0.0) + (float)$r['amt'];

    $info = [];
    if ($out) {
        $ids = implode(',', array_map('intval', array_keys($out)));
        foreach (db()->query("SELECT id,order_no,project_type,order_date,customer_name FROM project_orders WHERE id IN ($ids)")->fetchAll() as $o) $info[(int)$o['id']] = $o;
        // 付款昵称（旺旺 / 微信昵称），没有再用订单资料里的客户微信号，方便本人认出是哪一单
        foreach (db()->query("SELECT order_id,payment_nickname FROM project_order_sources WHERE order_id IN ($ids)")->fetchAll() as $s) if (trim((string)$s['payment_nickname']) !== '') $info[(int)$s['order_id']]['nick'] = trim($s['payment_nickname']);
        foreach (db()->query("SELECT order_id,details_json FROM project_order_details WHERE order_id IN ($ids)")->fetchAll() as $d) {
            if (!empty($info[(int)$d['order_id']]['nick'])) continue;
            $j = json_decode((string)$d['details_json'], true) ?: [];
            $w = trim((string)($j['customer_wechat'] ?? $j['contact_wechat'] ?? ''));
            if ($w !== '') $info[(int)$d['order_id']]['nick'] = $w;
        }
    }

    $claim = db()->prepare("SELECT i.order_id,i.request_id,r.status,r.settled_at FROM project_settlement_request_items i JOIN project_settlement_requests r ON r.id=i.request_id WHERE i.employee_id=? AND i.payroll_month=? AND r.status<>'rejected'");
    $claim->execute([$employeeId, $month]);
    $state = [];
    foreach ($claim->fetchAll() as $r) $state[(int)$r['order_id']] = ['state' => $r['status'], 'request_id' => (int)$r['request_id'], 'settled_at' => $r['settled_at']];

    $correcting = []; // 本人该订单已有待处理的更正申请：先等主管 / 财务回复，不能同时申请结算
    if ($out) {
        try {
            require_once __DIR__ . '/commission_explain.php'; ps_corr_ensure();
            $ids = implode(',', array_map('intval', array_keys($out)));
            $cq = db()->prepare("SELECT DISTINCT order_id FROM project_commission_corrections WHERE target_employee_id=? AND status='pending' AND order_id IN ($ids)");
            $cq->execute([$employeeId]);
            foreach ($cq->fetchAll(PDO::FETCH_COLUMN) as $oid) $correcting[(int)$oid] = true;
        } catch (Throwable $e) {}
    }

    $rows = [];
    foreach ($out as $oid => $amt) {
        $o = $info[$oid] ?? ['order_no' => '#' . $oid, 'project_type' => '', 'order_date' => '', 'customer_name' => ''];
        $rows[] = ['order_id' => $oid, 'order_no' => $o['order_no'], 'project_type' => $o['project_type'], 'order_date' => $o['order_date'], 'customer' => $o['customer_name'], 'nick' => $o['nick'] ?? '',
            'amount' => round($amt, 2), 'state' => $state[$oid]['state'] ?? (isset($correcting[$oid]) ? 'correcting' : 'available'), 'request_id' => $state[$oid]['request_id'] ?? 0, 'settled_at' => $state[$oid]['settled_at'] ?? null, 'is_monthly' => false];
    }

    // 月度规则分成（部门利润池 / 阶梯 / 超额奖 / 单量提成 / 尾差等）
    static $monthlyRowsCache = [];
    if (!isset($monthlyRowsCache[$month])) {
        $lockQ = db()->prepare('SELECT status FROM project_payroll_periods WHERE period=?');
        $lockQ->execute([$month]);
        if ($lockQ->fetchColumn() === 'locked') $monthlyRowsCache[$month] = ps_monthly_results($month);
        else {
            require_once __DIR__ . '/ProjectExpectedSettlement.php';
            $monthlyRowsCache[$month] = array_filter(ps_expected_month($month)['monthly'], function ($r) { return $r['rule_type'] !== 'adjustment'; });
        }
    }
    $monthlyRows = $monthlyRowsCache[$month];
    $lastDay = date('Y-m-t', strtotime($month . '-01'));
    foreach ($monthlyRows as $r) {
        if ((int)$r['employee_id'] !== $employeeId || !empty($r['paid_separately'])) continue;
        $amount = (float)$r['amount'];
        if (abs($amount) < 0.005) continue;
        // 固定服务费、全勤奖、补助类在工资表单列，其余（阶梯/部门提成/超额奖/尾差等）全属“项目分成”
        if (in_array($r['rule_type'], ['base_fee', 'perf_rank', 'attendance_bonus', 'fixed', 'overtime_pay', 'manual', 'extra_share'], true)) continue; // extra_share 是手工提成项，下面单独列，避免重复计
        if ($r['rule_type'] === 'sales_package' && strpos((string)$r['rule_name'], '底薪') !== false) continue;

        $vid = (int)($r['rule_id'] > 0 ? (900000000 + (int)$r['rule_id']) : (910000000 + (abs(crc32($r['rule_type'] . ':' . $r['rule_name'])) % 1000000)));
        $rows[] = [
            'order_id' => $vid,
            'order_no' => '月度提成 · ' . $r['rule_name'],
            'project_type' => $r['rule_type'] === 'dept_share' ? '部门提成' : ($r['rule_type'] === 'threshold_bonus' ? '超额奖金' : ($r['rule_type'] === 'profit_pool' ? '利润池' : '月度提成')),
            'order_date' => $lastDay,
            'customer' => $r['detail'] ?: '月度核算结算项',
            'amount' => round($amount, 2),
            'state' => $state[$vid]['state'] ?? 'available',
            'request_id' => $state[$vid]['request_id'] ?? 0,
            'settled_at' => $state[$vid]['settled_at'] ?? null,
            'is_monthly' => true,
        ];
    }

    // 手工附加提成项（如王亚 游学业务分成）
    require_once __DIR__ . '/ProjectPayrollExtras.php';
    $extras = ps_payroll_extras($month)[$employeeId]['items'] ?? [];
    foreach ($extras as $item) {
        $amount = (float)$item['amount'];
        $label = (string)$item['label'];
        if (abs($amount) < 0.005 || ps_manual_item_slot($label, $amount) !== 'share') continue; // 负数的提成项也要进来，否则扣减不生效
        $vid = 950000000 + (int)($item['id'] > 0 ? $item['id'] : (abs(crc32($label)) % 1000000));
        $rows[] = [
            'order_id' => $vid,
            'order_no' => '月度项目 · ' . $label,
            'project_type' => '手工核算',
            'order_date' => $lastDay,
            'customer' => $item['note'] ?: '手工提成项目',
            'amount' => round($amount, 2),
            'state' => $state[$vid]['state'] ?? 'available',
            'request_id' => $state[$vid]['request_id'] ?? 0,
            'settled_at' => $state[$vid]['settled_at'] ?? null,
            'is_monthly' => true,
        ];
    }

    $full = db()->prepare("SELECT r.id,r.status,r.settled_at FROM project_settlement_requests r JOIN project_settlement_request_items i ON i.request_id=r.id WHERE r.employee_id=? AND r.payroll_month=? AND i.order_id BETWEEN ? AND ? AND r.status<>'rejected'");
    $full->execute([$employeeId, $month, PSPR_NET_ITEM, PSPR_NET_LAST]);
    if ($claim = $full->fetch()) foreach ($rows as &$row) {
        if ($row['state'] !== 'available') continue;
        $row['state'] = $claim['status']; $row['request_id'] = (int)$claim['id']; $row['settled_at'] = $claim['settled_at'];
    }
    unset($row);
    usort($rows, function ($a, $b) { return [$a['order_date'], $a['order_id']] <=> [$b['order_date'], $b['order_id']]; });
    return $rows;
}

/** 本人提交申请（或财务代提交）。$orderIds 为选中的订单；返回申请 id。金额按服务器当前数据重算。当月及未来月份不可申请。 */
function psr_submit($actor, $month, array $orderIds, $targetEmployeeId = 0)
{
    $employeeId = (int)($actor['employee_id'] ?? 0);
    $isFinance = ($actor['role'] ?? '') === 'finance' || ($actor['type'] ?? '') === 'admin';
    if ($isFinance && (int)$targetEmployeeId > 0) {
        $employeeId = (int)$targetEmployeeId;
    }
    if ($employeeId <= 0 || (!$isFinance && ($actor['type'] ?? '') !== 'employee')) {
        throw new RuntimeException('只有合作人员本人可以申请结算');
    }
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$month)) throw new RuntimeException('月份无效');
    $currentMonth = date('Y-m');
    if ($month >= $currentMonth) {
        throw new RuntimeException("当月（{$month}）订单尚未结束，不可申请结算；仅可结算 {$currentMonth} 以前历史月份订单。");
    }
    if (!$isFinance && !ppf_is_green($employeeId, $month)) throw new RuntimeException('财务还没有核对确认 ' . $month . ' 的结算金额（核对通过标为绿色后才能申请结算），请联系财务');
    $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
    if (!$orderIds) throw new RuntimeException('请先选择要结算的订单');
    psr_ensure();
    $pdo = db();
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) $pdo->beginTransaction();
    try {
        $pdo->prepare('SELECT id FROM employees WHERE id=? FOR UPDATE')->execute([$employeeId]); // 同一人的申请串行，避免并发重复提交
        $full = $pdo->prepare("SELECT 1 FROM project_settlement_request_items WHERE employee_id=? AND payroll_month=? AND order_id BETWEEN ? AND ?");
        $full->execute([$employeeId, $month, PSPR_NET_ITEM, PSPR_NET_LAST]);
        if ($full->fetchColumn()) throw new RuntimeException('本月已申请整月实结算，不能再单独申请提成');
        $avail = [];
        foreach (psr_orders($employeeId, $month) as $r) if ($r['state'] === 'available') $avail[$r['order_id']] = $r;
        // 如果本月存在待扣减的负向分成/尾差项，必须一并提交结算，不可单方剔除
        foreach ($avail as $aOid => $aRow) {
            if ($aRow['amount'] < 0 && !in_array($aOid, $orderIds, true)) {
                $orderIds[] = $aOid;
            }
        }
        $total = 0.0;
        foreach ($orderIds as $oid) {
            if (!isset($avail[$oid])) throw new RuntimeException('有订单不在本月可申请的范围内，或已申请 / 已结算过，请刷新后重新选择');
            $total += $avail[$oid]['amount'];
        }
        // 逐单提成结算申请：允许合作人员申报订单分成，后续整月结算时自动扣除已申报提成；只要实结算未发生透支即可
        $home = psr_home_expected($employeeId, $month);
        if ($home['expected'] < -0.01) throw new RuntimeException('该月实结算已有超额扣减（余额 ¥' . number_format($home['expected'], 2) . '），请联系财务核对');
        $pdo->prepare("INSERT INTO project_settlement_requests (employee_id,payroll_month,status,amount,order_count,requested_by_user) VALUES (?,?,'pending',?,?,?)")
            ->execute([$employeeId, $month, round($total, 2), count($orderIds), (int)$actor['id']]);
        $rid = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO project_settlement_request_items (request_id,employee_id,order_id,payroll_month,amount) VALUES (?,?,?,?,?)');
        foreach ($orderIds as $oid) $ins->execute([$rid, $employeeId, $oid, $month, $avail[$oid]['amount']]);
        ps_audit('settlement_request', $rid, 'submit', $actor, ['month' => $month, 'orders' => count($orderIds), 'amount' => round($total, 2)]);
        if ($ownTransaction) $pdo->commit();
    } catch (Throwable $e) { if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    psr_notify_finance($rid, $employeeId, $month, round($total, 2), count($orderIds));
    return $rid;
}

/** 财务点“已结算”：申请里的订单全部标注已结算。已处理的申请不能重复处理。 */
function psr_settle($requestId, $actor, $note = '')
{
    psr_ensure();
    $pdo = db();
    $pre = $pdo->prepare('SELECT * FROM project_settlement_requests WHERE id=?'); $pre->execute([(int)$requestId]);
    if ($preRow = $pre->fetch()) psr_check($preRow); // 依赖表在事务前初始化。
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT * FROM project_settlement_requests WHERE id=? FOR UPDATE'); $q->execute([(int)$requestId]); $r = $q->fetch();
        if (!$r || $r['status'] !== 'pending') throw new RuntimeException('这条申请已处理或不存在，请刷新');
        $check = psr_check($r);
        if (!$check['ok']) throw new RuntimeException('有异议，未结算：' . implode('；', $check['issues']));
        $by = function_exists('ps_finance_username') ? (string)ps_finance_username($actor) : (string)($actor['username'] ?? '');
        $pdo->prepare("UPDATE project_settlement_requests SET status='settled',settled_at=NOW(),handled_by=?,note=? WHERE id=?")->execute([mb_substr($by, 0, 60), mb_substr(trim($note), 0, 200), (int)$requestId]);
        $pdo->prepare('UPDATE project_settlement_request_items SET settled_at=NOW() WHERE request_id=?')->execute([(int)$requestId]);
        ps_audit('settlement_request', (int)$requestId, 'settled', $actor, ['employee_id' => (int)$r['employee_id'], 'month' => $r['payroll_month'], 'amount' => $r['amount']]);
        if ($ownTransaction) $pdo->commit();
    } catch (Throwable $e) { if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    psr_notify_employee($r, '你 ' . $r['payroll_month'] . ' 的结算申请已打款', '财务已打款并确认结算：共 ' . (int)$r['order_count'] . ' 项，¥' . number_format((float)$r['amount'], 2) . '。可在首页查看结算状态。', 'settled');
}

/** 财务驳回（写明原因）：订单释放，本人可修改后重新申请。 */
function psr_reject($requestId, $actor, $reason)
{
    $reason = trim((string)$reason);
    if (mb_strlen($reason) < 2) throw new RuntimeException('驳回请写明原因');
    psr_ensure();
    $pdo = db();
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT * FROM project_settlement_requests WHERE id=? FOR UPDATE'); $q->execute([(int)$requestId]); $r = $q->fetch();
        if (!$r || $r['status'] !== 'pending') throw new RuntimeException('这条申请已处理或不存在，请刷新');
        $by = function_exists('ps_finance_username') ? (string)ps_finance_username($actor) : (string)($actor['username'] ?? '');
        $pdo->prepare("UPDATE project_settlement_requests SET status='rejected',handled_by=?,note=? WHERE id=?")->execute([mb_substr($by, 0, 60), mb_substr($reason, 0, 200), (int)$requestId]);
        $pdo->prepare('DELETE FROM project_settlement_request_items WHERE request_id=? AND settled_at IS NULL')->execute([(int)$requestId]);
        ps_audit('settlement_request', (int)$requestId, 'rejected', $actor, ['reason' => $reason]);
        if ($ownTransaction) $pdo->commit();
    } catch (Throwable $e) { if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    psr_notify_employee($r, '你 ' . $r['payroll_month'] . ' 的结算申请被退回', '原因：' . $reason . '。订单已释放，可修改后重新申请。', 'rejected');
}

/**
 * 本人发现某些订单的分成不对：多选订单一起提交更正（沿用“分成更正申请”）。申请会通知该合作人员所在部门的主管和财务，两边都能审核。
 * 返回 ['submitted' => 提交条数, 'skipped' => 已有待处理而跳过的订单数, 'notified' => [主管姓名…]]。
 */
function psr_correct($actor, $month, array $orderIds, $reason)
{
    $employeeId = (int)($actor['employee_id'] ?? 0);
    if ($employeeId <= 0 || ($actor['type'] ?? '') !== 'employee') throw new RuntimeException('只有合作人员本人可以申请更正');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$month)) throw new RuntimeException('月份无效');
    $reason = trim((string)$reason);
    if (mb_strlen($reason) < 5) throw new RuntimeException('请写清哪里不对（至少 5 个字），主管和财务才好核对');
    $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
    if (!$orderIds) throw new RuntimeException('请先勾选算得不对的订单');
    require_once __DIR__ . '/commission_explain.php';
    psr_ensure(); ps_corr_ensure();
    $mine = [];
    foreach (psr_orders($employeeId, $month) as $r) $mine[$r['order_id']] = $r;
    $submitted = 0; $skipped = 0;
    $grp = db()->prepare('SELECT commission_group,SUM(commission_amount) amt FROM project_commission_snapshots WHERE order_id=? AND employee_id=? AND payroll_month=? GROUP BY commission_group');
    $groups = db()->prepare('SELECT DISTINCT commission_group FROM project_participants WHERE order_id=? AND employee_id=?');
    foreach ($orderIds as $oid) {
        if (!isset($mine[$oid])) throw new RuntimeException('有订单不在本月范围内，请刷新后重新选择');
        if ($mine[$oid]['state'] === 'settled' || $mine[$oid]['state'] === 'pending') throw new RuntimeException('订单 ' . $mine[$oid]['order_no'] . ' 已申请结算或已结算，不能再申请更正；请联系财务');
        $grp->execute([$oid, $employeeId, $month]); $shown = $grp->fetchAll(PDO::FETCH_KEY_PAIR);
        $groups->execute([$oid, $employeeId]); $gs = $groups->fetchAll(PDO::FETCH_COLUMN);
        $did = false;
        foreach ($gs as $g) {
            try { ps_corr_submit($oid, $actor, $employeeId, $g, '【' . $month . ' 结算核对】' . $reason, '', $shown[$g] ?? $mine[$oid]['amount'], ['source' => 'settlement_request', 'month' => $month, 'amount' => $mine[$oid]['amount']]); $submitted++; $did = true; }
            catch (RuntimeException $e) { if (strpos($e->getMessage(), '待处理') === false) throw $e; }
        }
        if (!$did) $skipped++;
    }
    if (!$submitted) throw new RuntimeException('这些订单已有待处理的更正申请，请等待主管 / 财务回复');
    $notified = psr_notify_correction($employeeId, $month, $submitted, $reason);
    return ['submitted' => $submitted, 'skipped' => $skipped, 'notified' => $notified];
}

/** 通知所在部门主管 + 财务；返回被通知的主管姓名。 */
function psr_notify_correction($employeeId, $month, $count, $reason)
{
    $heads = [];
    try {
        if (!function_exists('ps_admin_reviewers')) require_once __DIR__ . '/ProjectSystem.php';
        $emp = db()->prepare('SELECT name,department FROM employees WHERE id=?'); $emp->execute([(int)$employeeId]); $e = $emp->fetch();
        $hq = db()->prepare('SELECT h.employee_id,x.name FROM project_dept_heads h JOIN employees x ON x.id=h.employee_id WHERE h.department=? AND h.employee_id<>?');
        $hq->execute([(string)$e['department'], (int)$employeeId]);
        $ids = [];
        foreach ($hq->fetchAll() as $h) { $ids[(int)$h['employee_id']] = true; $heads[] = $h['name']; }
        $names = array_values(array_unique(array_map(function ($r) { return $r['real_name']; }, ps_admin_reviewers())));
        if ($names) { $fq = db()->prepare('SELECT id FROM employees WHERE name IN (' . implode(',', array_fill(0, count($names), '?')) . ')'); $fq->execute($names); foreach ($fq->fetchAll(PDO::FETCH_COLUMN) as $fid) $ids[(int)$fid] = true; }
        $ins = db()->prepare('INSERT IGNORE INTO project_messages (employee_id,category,title,body,link,dedupe_key) VALUES (?,?,?,?,?,?)');
        $key = 'settle-corr:' . (int)$employeeId . ':' . $month . ':' . date('YmdHis');
        foreach (array_keys($ids) as $to) $ins->execute([$to, 'commission_correction', $e['name'] . ' 对 ' . $month . ' 的 ' . $count . ' 笔订单分成申请更正', $e['name'] . '（' . $e['department'] . '）在申请结算前核对发现 ' . $count . ' 笔订单的分成不对：' . "\n" . mb_substr($reason, 0, 160) . "\n请在“分成更正申请”里审核回复。", '/project/corrections.php', $key . ':' . $to]);
    } catch (Throwable $ex) { /* 站内信失败不影响申请 */ }
    return $heads;
}

/**
 * 财务看一条待打款申请有没有异议：申请后订单金额又变了 / 订单上有待处理的更正。没有则可以放心打款并导出。
 * 返回 ['ok' => bool, 'issues' => [说明…], 'has_card' => bool]；缺银行卡号不算异议，但导出时会标出来。
 */
/**
 * 首页“项目报酬表”里这个人这个月的实结算，扣掉其他申请（待打款 / 已结算，不含 $exceptRequestId 自己）后应有的金额。
 * 申请金额必须等于它，财务页里看到的就是首页显示的应结算，不会出现“申请 ¥20，首页是 ¥2,685”。
 */
function psr_home_expected($employeeId, $month, $exceptRequestId = 0)
{
    require_once __DIR__ . '/ProjectPayrollSheet.php';
    $net = round((float)(pps_sheet($month, (int)$employeeId)['rows'][0]['net'] ?? 0), 2);
    $q = db()->prepare("SELECT COALESCE(SUM(amount),0) others,COALESCE(SUM(IF(status='settled',amount,0)),0) paid,SUM(status='pending') pending_count FROM project_settlement_requests WHERE employee_id=? AND payroll_month=? AND status<>'rejected' AND id<>?");
    $q->execute([(int)$employeeId, $month, (int)$exceptRequestId]);
    $claims = $q->fetch(); $others = round((float)$claims['others'], 2);
    return ['net' => $net, 'paid' => round((float)$claims['paid'],2), 'pending_count' => (int)$claims['pending_count'], 'others' => $others, 'expected' => round($net - $others, 2)];
}

function psr_check(array $req)
{
    psr_ensure();
    require_once __DIR__ . '/commission_explain.php'; ps_corr_ensure();
    $issues = [];
    $items = db()->prepare('SELECT order_id,amount FROM project_settlement_request_items WHERE request_id=?');
    $items->execute([(int)$req['id']]);
    $itemRows = $items->fetchAll();
    $lookupItems = array_filter($itemRows, function ($it) { return !pspr_is_net_item($it['order_id']); });
    $now = [];
    if (array_filter($lookupItems, function ($it) { return (int)$it['order_id'] >= 900000000; })) {
        foreach (psr_orders((int)$req['employee_id'], $req['payroll_month']) as $o) $now[$o['order_id']] = $o;
    } elseif ($lookupItems) {
        // 普通订单只需核对分成金额，不必加载客户资料或重算整月工资。
        $amounts = db()->prepare('SELECT order_id,ROUND(SUM(amount),2) amount FROM (SELECT order_id,commission_amount amount FROM project_commission_snapshots WHERE employee_id=? AND payroll_month=? UNION ALL SELECT order_id,amount FROM project_commission_adjustments WHERE employee_id=? AND payroll_month=?) current_amounts GROUP BY order_id');
        $amounts->execute([(int)$req['employee_id'], $req['payroll_month'], (int)$req['employee_id'], $req['payroll_month']]);
        foreach ($amounts->fetchAll() as $o) $now[$o['order_id']] = $o;
    }
    $changed = 0;
    foreach ($itemRows as $it) {
        if (pspr_is_net_item($it['order_id'])) {
            $month = pspr_month((int)$req['employee_id'], $req['payroll_month']);
            $now[(int)$it['order_id']] = ['amount' => $month['amount']];
            $isGreen = ppf_is_green((int)$req['employee_id'], $req['payroll_month']);
            if (!$isGreen) {
                if ($month['pending_n'] > 0) $issues[] = '本月仍含待审核预计分成';
                $issues[] = '财务核对状态已改变，请重新核对整月实结算';
            }
            $c = db()->prepare("SELECT COUNT(*) FROM project_commission_corrections c JOIN project_orders o ON o.id=c.order_id WHERE c.target_employee_id=? AND c.status='pending' AND DATE_FORMAT(o.order_date,'%Y-%m')=?");
            $c->execute([(int)$req['employee_id'], $req['payroll_month']]);
            if ((int)$c->fetchColumn()) $issues[] = '本月有待处理的分成更正申请';
        }
        $cur = $now[(int)$it['order_id']] ?? null;
        if (!$cur || abs((float)$cur['amount'] - (float)$it['amount']) > 0.004) $changed++;
    }
    if ($changed) $issues[] = $changed . ' 项结算金额在申请后有变动，请退回让本人重新申请';
    $hasFullNet = false;
    foreach ($itemRows as $it) {
        if (pspr_is_net_item($it['order_id'])) {
            $hasFullNet = true;
            break;
        }
    }
    $home = psr_home_expected((int)$req['employee_id'], $req['payroll_month'], (int)$req['id']);
    if ($hasFullNet) {
        if (abs($home['expected'] - (float)$req['amount']) > 0.01) {
            $issues[] = '申请金额与首页扣减后实结算不一致，请先同步整月实结算申请后重新核对';
        }
    } else {
        if ($home['expected'] < -0.01) {
            $issues[] = '该月实结算已有超额扣减（余额 ¥' . number_format($home['expected'], 2) . '），请联系财务核对';
        }
    }
    try {
        require_once __DIR__ . '/commission_explain.php'; ps_corr_ensure();
        $c = db()->prepare("SELECT COUNT(DISTINCT c.order_id) FROM project_commission_corrections c JOIN project_settlement_request_items i ON i.order_id=c.order_id AND i.employee_id=c.target_employee_id WHERE i.request_id=? AND c.status='pending'");
        $c->execute([(int)$req['id']]);
        if ((int)$c->fetchColumn()) $issues[] = '有订单存在待处理的更正申请';
    } catch (Throwable $e) {}
    $card = '';
    try { $q = db()->prepare('SELECT bank_card FROM project_partner_profiles WHERE employee_id=?'); $q->execute([(int)$req['employee_id']]); $card = (string)$q->fetchColumn(); } catch (Throwable $e) {}
    $syncAmount = round($home['net']-$home['paid'],2);
    return ['ok' => !$issues, 'issues' => $issues, 'has_card' => $card !== '', 'card' => $card,
        'sync_amount' => $syncAmount, 'sync_needed' => !$hasFullNet || $home['pending_count'] > 0 || abs($syncAmount-(float)$req['amount'])>0.004];
}

/**
 * 批量打款表：把选中的、无异议的待打款申请按人汇总（同一人多条申请合并金额）。
 * 返回 ['people' => [...], 'orders' => [...], 'held' => [[申请, 原因]], 'total' => 合计]。
 */
function psr_export_data(array $requestIds)
{
    psr_ensure();
    $ids = array_values(array_unique(array_filter(array_map('intval', $requestIds))));
    if (!$ids) throw new RuntimeException('请先勾选要导出的申请');
    $q = db()->prepare("SELECT r.*,e.name,e.department FROM project_settlement_requests r LEFT JOIN employees e ON e.id=r.employee_id WHERE r.status='pending' AND r.id IN (" . implode(',', $ids) . ') ORDER BY e.name,r.id');
    $q->execute();
    $people = []; $orders = []; $held = []; $total = 0.0;
    foreach ($q->fetchAll() as $r) {
        $chk = psr_check($r);
        if (!$chk['ok']) { $held[] = [$r, implode('；', $chk['issues'])]; continue; }
        $e = (int)$r['employee_id'];
        if (!isset($people[$e])) $people[$e] = ['employee_id' => $e, 'name' => $r['name'], 'department' => $r['department'], 'card' => $chk['card'], 'amount' => 0.0, 'orders' => 0, 'months' => [], 'requests' => []];
        $people[$e]['amount'] += (float)$r['amount']; $people[$e]['orders'] += (int)$r['order_count'];
        $people[$e]['months'][$r['payroll_month']] = true; $people[$e]['requests'][] = (int)$r['id'];
        $total += (float)$r['amount'];
        foreach (psr_request_orders($r['id']) as $o) $orders[] = ['name' => $r['name'], 'request_id' => (int)$r['id'], 'month' => $r['payroll_month']] + $o;
    }
    foreach ($people as &$p) { $p['amount'] = round($p['amount'], 2); $p['months'] = implode('、', array_keys($p['months'])); }
    unset($p);
    return ['people' => array_values($people), 'orders' => $orders, 'held' => $held, 'total' => round($total, 2)];
}

/** 批量标注已结算（打款完成后）：每条申请各自一个事务；返回成功条数和失败说明。 */
function psr_settle_many(array $requestIds, $actor, $note = '')
{
    $ok = 0; $fail = [];
    foreach (array_values(array_unique(array_filter(array_map('intval', $requestIds)))) as $id) {
        try {
            psr_settle($id, $actor, $note); $ok++;
        } catch (RuntimeException $e) { $fail[] = '#' . $id . '：' . $e->getMessage(); }
    }
    return ['ok' => $ok, 'fail' => $fail];
}

function psr_pending_count()
{
    try { psr_ensure(); return (int)db()->query("SELECT COUNT(*) FROM project_settlement_requests WHERE status='pending'")->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}

/** 本人的申请记录（含已结算），最新在前。 */
function psr_requests($employeeId, $limit = 12)
{
    psr_ensure();
    $q = db()->prepare('SELECT * FROM project_settlement_requests WHERE employee_id=? ORDER BY id DESC LIMIT ' . max(1, (int)$limit));
    $q->execute([(int)$employeeId]);
    return $q->fetchAll();
}

function psr_request_orders($requestId)
{
    $q = db()->prepare('SELECT i.order_id,i.amount,i.settled_at,o.order_no,o.project_type,o.order_date,i.employee_id,i.payroll_month FROM project_settlement_request_items i LEFT JOIN project_orders o ON o.id=i.order_id WHERE i.request_id=? ORDER BY o.order_date,i.order_id');
    $q->execute([(int)$requestId]);
    $rows = $q->fetchAll();
    $cachedOrders = [];
    foreach ($rows as &$row) {
        $oid = (int)$row['order_id'];
        if (pspr_is_net_item($oid)) {
            $row['order_no'] = '整月实结算（已扣除其他申请）';
            $row['project_type'] = '固定工资、全勤、提成及补助，减车位费等全部扣减';
            $row['order_date'] = $row['payroll_month'] . '-01';
            $row['is_monthly'] = true;
            continue;
        }
        if ($oid >= 900000000) {
            $eid = (int)$row['employee_id'];
            $m = (string)$row['payroll_month'];
            if (!isset($cachedOrders[$eid][$m])) {
                $cachedOrders[$eid][$m] = [];
                foreach (psr_orders($eid, $m) as $item) {
                    $cachedOrders[$eid][$m][(int)$item['order_id']] = $item;
                }
            }
            $target = $cachedOrders[$eid][$m][$oid] ?? null;
            if ($target) {
                $row['order_no'] = $target['order_no'];
                $row['project_type'] = $target['project_type'];
                $row['order_date'] = $target['order_date'];
            } else {
                $row['order_no'] = '月度提成/项目';
                $row['project_type'] = '月度提成';
                $row['order_date'] = date('Y-m-t', strtotime($m . '-01'));
            }
            $row['is_monthly'] = true;
        }
    }
    unset($row);
    return $rows;
}

function psr_notify_finance($rid, $employeeId, $month, $amount, $count)
{
    try {
        if (!function_exists('ps_admin_reviewers')) require_once __DIR__ . '/ProjectSystem.php';
        $name = (string)db()->query('SELECT name FROM employees WHERE id=' . (int)$employeeId)->fetchColumn();
        $names = array_values(array_unique(array_map(function ($r) { return $r['real_name']; }, ps_admin_reviewers())));
        if (!$names) return;
        $q = db()->prepare('SELECT id FROM employees WHERE name IN (' . implode(',', array_fill(0, count($names), '?')) . ')');
        $q->execute($names);
        $ins = db()->prepare('INSERT IGNORE INTO project_messages (employee_id,category,title,body,link,dedupe_key) VALUES (?,?,?,?,?,?)');
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $eid) $ins->execute([(int)$eid, 'settlement', $name . ' 申请结算 ' . $month, $name . ' 申请结算 ' . $month . '：' . $count . ' 项，合计 ¥' . number_format($amount, 2) . '。打款后请在“结算申请”里点“已结算”。', '/project/settlement_requests.php', 'settle-req:' . $rid . ':' . (int)$eid]);
    } catch (Throwable $e) { /* 站内信失败不影响申请 */ }
}

function psr_notify_employee(array $r, $title, $body, $kind)
{
    try {
        db()->prepare('INSERT IGNORE INTO project_messages (employee_id,category,title,body,link,dedupe_key) VALUES (?,?,?,?,?,?)')
            ->execute([(int)$r['employee_id'], 'settlement', $title, $body, '/project/index.php', 'settle-' . $kind . ':' . (int)$r['id']]);
    } catch (Throwable $e) {}
}

/** 往月可申请的整月实结算；金额已减去旧提成申请。 */
function psr_available_past($employeeId)
{
    return pspr_past($employeeId);
}

function psr_submit_all($actor)
{
    $eid = pspr_self_employee($actor);
    if ($eid <= 0) throw new RuntimeException('当前账号未绑定本人合作人员资料，无法申请结算');
    $done = []; $skipped = [];
    foreach (pspr_past($eid)['months'] as $month => $amount) {
        try { $r = pspr_submit($actor, $month); $done[] = [$month, $r['amount']]; }
        catch (RuntimeException $e) { $skipped[] = [$month, $e->getMessage()]; }
    }
    return ['done' => $done, 'skipped' => $skipped];
}
