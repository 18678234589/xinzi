<?php
require_once __DIR__ . '/ProjectPayrollSheet.php';

// 独立于订单和月度提成的保留项目号；已支付后补差额使用该申请的独立项目号。
const PSPR_NET_ITEM = 4000000000;
const PSPR_NET_LAST = 4294967295;

function pspr_is_net_item($id) { return (int)$id >= PSPR_NET_ITEM && (int)$id <= PSPR_NET_LAST; }

/** 管理员沿用系统已有财务实名绑定，不能把当前看板选中的人员当成本人。 */
function pspr_self_employee($actor)
{
    if (($actor['type'] ?? '') === 'employee') return (int)($actor['employee_id'] ?? 0);
    if (($actor['type'] ?? '') === 'admin') foreach (ps_admin_reviewers() as $a) {
        if ((int)$a['id'] === (int)($actor['id'] ?? 0)) return (int)($a['employee_id'] ?? 0);
    }
    return 0;
}

/** 没有参与当月订单、没有订单分成的固定服务费人员，可由财务代申请。 */
function pspr_fixed_people($month, $employeeId = 0)
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',(string)$month) || $month >= date('Y-m')) return [];
    $q = db()->prepare('SELECT DISTINCT p.employee_id FROM project_participants p JOIN project_orders o ON o.id=p.order_id WHERE o.order_date>=? AND o.order_date<?');
    $q->execute([$month.'-01',date('Y-m-d',strtotime($month.'-01 +1 month'))]);
    $hasOrders = array_fill_keys(array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN)),true); $out=[];
    foreach (pps_sheet($month,(int)$employeeId)['rows'] as $r) {
        if ($r['base'] > 0 && abs($r['share']) < 0.005 && !$r['pending_n'] && empty($hasOrders[(int)$r['id']])) $out[(int)$r['id']]=$r;
    }
    return $out;
}

function pspr_month($employeeId, $month)
{
    psr_ensure();
    $q = db()->prepare("SELECT r.*,(SELECT MIN(i.order_id) FROM project_settlement_request_items i WHERE i.request_id=r.id AND i.order_id BETWEEN ? AND ?) order_id FROM project_settlement_requests r WHERE r.employee_id=? AND r.payroll_month=? AND r.status<>'rejected' ORDER BY r.id");
    $q->execute([PSPR_NET_ITEM, PSPR_NET_LAST, (int)$employeeId, $month]);
    $claimed = 0.0; $paid = 0.0; $pending = []; $request = null;
    $requests = $q->fetchAll();
    foreach ($requests as $r) {
        if ($r['order_id'] !== null && (!$request || $r['status'] === 'pending' || $request['status'] === 'settled')) $request = $r;
        if ($r['status'] === 'settled') $paid += (float)$r['amount'];
        else $pending[] = $r;
    }
    foreach ($requests as $r) if (!$request || (int)$r['id'] !== (int)$request['id']) $claimed += (float)$r['amount'];
    $row = pps_sheet($month, (int)$employeeId)['rows'][0] ?? [];
    $net = round((float)($row['net'] ?? 0), 2);
    return ['net' => $net, 'paid' => round($paid, 2), 'pending' => $pending, 'remaining' => round($net - $paid, 2), 'claimed' => round($claimed, 2), 'amount' => round($net - $claimed, 2), 'request' => $request, 'pending_n' => (int)($row['pending_n'] ?? 0)];
}

function pspr_status($employeeId, $month)
{
    $m = pspr_month($employeeId, $month);
    $m['can_apply'] = false;
    if ($m['request']) {
        $m['label'] = $m['request']['status'] === 'settled' ? '整月实结算已结算' : '已申请 · 待财务处理';
        $m['reason'] = '本月实结算已申请或已结算，请勿重复提交';
    } elseif ($month >= date('Y-m')) {
        $m['label'] = '当月暂不可申请'; $m['reason'] = '只能申请往月实结算';
    } elseif ($m['amount'] <= 0) {
        $m['label'] = '无剩余可申请金额'; $m['reason'] = '本月没有剩余可申请金额';
    } else {
        $m['can_apply'] = true; $m['label'] = '申请整月实结算'; $m['reason'] = '';
        if (!ppf_is_green($employeeId, $month)) {
            $flag = ppf_all($month)[(int)$employeeId] ?? [];
            $m['reason'] = '申请提交后由财务核对，核对通过后方可打款' . (!empty($flag['reason']) ? '：' . $flag['reason'] : '');
        }
        if ($m['pending_n'] > 0) $m['reason'] .= ($m['reason'] !== '' ? '；' : '') . '本月有 ' . $m['pending_n'] . ' 单待审核预计分成，审核完成后方可打款';
    }
    return $m;
}

function pspr_submit($actor, $month, $expectedAmount = null, $employeeId = 0)
{
    $self = pspr_self_employee($actor);
    $eid = $employeeId ? (int)$employeeId : $self;
    $proxy = $eid !== $self;
    if ($eid <= 0 || ($proxy && ($actor['type'] ?? '') !== 'admin' && ($actor['role'] ?? '') !== 'finance')) throw new RuntimeException('只能申请本人结算，请检查账号与合作人员绑定');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', (string)$month) || $month >= date('Y-m')) throw new RuntimeException('只能申请往月实结算');
    ppf_ensure();
    pspr_month($eid, $month); // 先初始化依赖表，避免 DDL 隐式提交事务。
    $pdo = db(); $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT id FROM employees WHERE id=? FOR UPDATE'); $q->execute([$eid]);
        if (!$q->fetchColumn()) throw new RuntimeException('合作人员不存在');
        if ($proxy && !isset(pspr_fixed_people($month,$eid)[$eid])) throw new RuntimeException('只能代申请当月无订单、无订单分成的固定服务费人员');
        $m = pspr_status($eid, $month);
        if (!$m['can_apply']) throw new RuntimeException($m['reason']);
        if ($proxy && $m['pending']) throw new RuntimeException('本月已有待处理申请，请勿重复代申请');
        if ($expectedAmount !== null && (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', (string)$expectedAmount) || abs((float)$expectedAmount - $m['amount']) > 0.004)) throw new RuntimeException('结算金额已变化，请刷新核对后重新申请');
        $pdo->prepare("INSERT INTO project_settlement_requests (employee_id,payroll_month,status,amount,order_count,requested_by_user) VALUES (?,?,'pending',?,1,?)")
            ->execute([$eid, $month, $m['amount'], (int)$actor['id']]);
        $rid = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO project_settlement_request_items (request_id,employee_id,order_id,payroll_month,amount) VALUES (?,?,?,?,?)')
            ->execute([$rid, $eid, PSPR_NET_ITEM, $month, $m['amount']]);
        ps_audit('settlement_request', $rid, 'submit_payroll_net', $actor, ['employee_id' => $eid, 'on_behalf' => $proxy, 'month' => $month, 'net' => $m['net'], 'previously_claimed' => $m['claimed'], 'amount' => $m['amount']]);
        psr_notify_finance($rid, $eid, $month, $m['amount'], 1);
        if ($ownTransaction) $pdo->commit();
        return ['id' => $rid, 'amount' => $m['amount']];
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function pspr_submit_fixed($actor, $month, array $expected)
{
    if (($actor['type'] ?? '') !== 'admin' && ($actor['role'] ?? '') !== 'finance') throw new RuntimeException('仅管理员和财务可以代申请');
    if (!$expected || count($expected)>200) throw new RuntimeException('没有可代申请人员，请刷新页面');
    foreach ($expected as $eid=>$amount) if (!ctype_digit((string)$eid) || (int)$eid<=0 || !is_scalar($amount) || !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D',(string)$amount)) throw new RuntimeException('代申请名单或金额无效，请刷新页面');
    $done=[]; $skipped=[]; $total=0.0;
    foreach ($expected as $eid=>$amount) {
        try {
            if (!isset(pspr_fixed_people($month,(int)$eid)[(int)$eid])) throw new RuntimeException('当月不属于无订单的固定服务费人员');
            $r=pspr_submit($actor,$month,$amount,(int)$eid); $done[]=$r; $total+=$r['amount'];
        }
        catch (RuntimeException $e) { $skipped[]='#'.(int)$eid.'：'.$e->getMessage(); }
    }
    return ['done'=>$done,'skipped'=>$skipped,'total'=>round($total,2)];
}

/** 财务将尚未打款的申请合并为整月实结算；已结算记录及其明细不动。 */
function pspr_sync_pending($actor, $employeeId, $month, $expectedAmount)
{
    if (($actor['role'] ?? '') !== 'finance') throw new RuntimeException('仅财务可以同步整月实结算');
    $eid = (int)$employeeId;
    if ($eid <= 0 || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', (string)$month) || $month >= date('Y-m')) throw new RuntimeException('请选择合作人员和往月结算月份');
    ppf_ensure(); pspr_month($eid, $month); // 初始化在事务外完成。
    $pdo = db(); $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT id FROM employees WHERE id=? FOR UPDATE'); $q->execute([$eid]);
        if (!$q->fetchColumn()) throw new RuntimeException('合作人员不存在');
        $q = $pdo->prepare("SELECT * FROM project_settlement_requests WHERE employee_id=? AND payroll_month=? AND status<>'rejected' ORDER BY id FOR UPDATE"); $q->execute([$eid,$month]);
        $requests = $q->fetchAll(); $pending = []; $paid = 0.0;
        foreach ($requests as $r) { if ($r['status'] === 'settled') $paid += (float)$r['amount']; else $pending[] = $r; }
        if (!$pending) throw new RuntimeException('没有待处理申请，请刷新后重新申请');
        foreach ($pending as $r) if ($r['settled_at'] !== null) throw new RuntimeException('申请已含支付时间，不能同步');
        $q = $pdo->prepare('SELECT * FROM project_settlement_request_items WHERE employee_id=? AND payroll_month=? ORDER BY id FOR UPDATE'); $q->execute([$eid,$month]); $allItems = $q->fetchAll();
        $ids = array_map('intval', array_column($pending,'id')); $items = []; $keep = $ids[0]; $paidNet = false;
        foreach ($allItems as $it) {
            if (!in_array((int)$it['request_id'],$ids,true)) {
                if (pspr_is_net_item($it['order_id'])) $paidNet = true;
                continue;
            }
            if ($it['settled_at'] !== null) throw new RuntimeException('申请明细已支付，不能同步');
            $items[] = $it;
            if (pspr_is_net_item($it['order_id'])) $keep = (int)$it['request_id'];
        }
        $m = pspr_month($eid,$month); $amount = round($m['net']-$paid,2);
        if ($amount <= 0) throw new RuntimeException('本月没有剩余可申请金额，请核对已打款记录');
        if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D',(string)$expectedAmount) || abs((float)$expectedAmount-$amount)>0.004) throw new RuntimeException('结算金额已变化，请刷新核对后重新申请');
        $q = $pdo->prepare('SELECT * FROM project_payroll_flags WHERE employee_id=? AND payroll_month=? FOR UPDATE'); $q->execute([$eid,$month]); $flag=$q->fetch();
        $changed = abs(array_sum(array_column($pending,'amount'))-$amount)>0.004;
        $resetReview = $changed && ppf_is_green($eid,$month);
        $in = implode(',',$ids);
        $pdo->exec('DELETE FROM project_settlement_request_items WHERE request_id IN ('.$in.')');
        $pdo->prepare("UPDATE project_settlement_requests SET status='rejected',handled_by=?,note=? WHERE id IN ($in) AND id<>?")->execute(['finance:'.(int)$actor['id'],'已合并至整月实结算申请 #'.$keep,$keep]);
        $pdo->prepare("UPDATE project_settlement_requests SET amount=?,order_count=1,note=? WHERE id=? AND status='pending'")->execute([$amount,'按首页扣减后实结算同步；已支付 ¥'.number_format($paid,2,'.','').' 不重复申请',$keep]);
        $netItem = $paidNet ? PSPR_NET_ITEM + $keep : PSPR_NET_ITEM;
        if (!pspr_is_net_item($netItem)) throw new RuntimeException('补差额申请编号超出范围，请联系管理员');
        $pdo->prepare('INSERT INTO project_settlement_request_items (request_id,employee_id,order_id,payroll_month,amount) VALUES (?,?,?,?,?)')->execute([$keep,$eid,$netItem,$month,$amount]);
        if ($resetReview) $pdo->prepare("UPDATE project_payroll_flags SET color='yellow',reason=?,set_by=? WHERE employee_id=? AND payroll_month=?")->execute(['整月申请金额已更新，请按最新实结算重新核对。原备注已存入审计记录。','finance:'.(int)$actor['id'],$eid,$month]);
        ps_audit('settlement_request',$keep,'sync_payroll_net',$actor,['employee_id'=>$eid,'month'=>$month,'before'=>['requests'=>$pending,'items'=>$items,'flag'=>$flag],'net'=>$m['net'],'paid'=>$paid,'amount'=>$amount,'review_reset'=>$resetReview]);
        if ($own) $pdo->commit();
        return ['id'=>$keep,'amount'=>$amount];
    } catch (Throwable $e) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function pspr_past($employeeId)
{
    ppf_ensure();
    $out = ['n' => 0, 'amount' => 0.0, 'months' => []];
    if ((int)$employeeId <= 0) return $out;
    // 只列财务已有工资记录的月份，不能因固定薪酬规则而凭空产生历史工资。
    $q = db()->prepare('SELECT payroll_month FROM project_payroll_flags WHERE employee_id=? AND payroll_month>=? AND payroll_month<? ORDER BY payroll_month DESC');
    $q->execute([(int)$employeeId, date('Y-m', strtotime('first day of -12 month')), date('Y-m')]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $month) {
        $m = pspr_status($employeeId, $month);
        if (!$m['can_apply']) continue;
        $out['months'][$month] = $m['amount']; $out['n']++; $out['amount'] += $m['amount'];
    }
    $out['amount'] = round($out['amount'], 2);
    return $out;
}
