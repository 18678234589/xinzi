<?php
/**
 * 订单“更正业务类型”和“把自己从这单移除”：订单被录进错的业务、或把不相干的人挂了进来，参与人自己就能改，不用找财务。
 *
 * - 更正业务类型：财务可改任意业务；合作人员只能改成自己账号开通的业务，且必须是这单的参与人。
 *   未审核订单直接改业务 / 订单类型，参与人岗位名换成新业务的岗位；
 *   已审核订单保留原分成快照，按新业务重算，差额作为调整计入未锁定月份（合作人员自己操作时计入下一个未锁定月份），并给财务发站内信、留审计。
 * - 移除自己：参与人把自己从这单移除（只能移自己，且这单必须还有别人）。未审核直接移除；已审核保留原快照，
 *   她的分成按差额扣回、同组其余人的权重按比例补满后重算，差额同样作为调整计入未锁定月份。
 * - 订单已锁定、分成规则没配好、已被申请结算（移除自己时）都会拒绝并说明原因，不留半截数据。
 */
require_once __DIR__ . '/ProjectIntake.php';
require_once __DIR__ . '/ProjectBusiness.php';

/** 参与人在新业务下的岗位名：该人在新业务有指定岗位用指定的，否则按组取默认（技术 = 新业务的“制作 / 前端”岗，客服 = 客服）。 */
function pob_new_role($employeeId, $group, $oldRole, $newBusiness)
{
    if (ps_software_testing_role($oldRole)) return $oldRole; // 软件测试岗在各业务通用
    $role = ps_employee_default_role((int)$employeeId, $newBusiness, $group);
    if ($role !== null && $role !== '') return $role;
    return $group === 'technical' ? ps_business_people_labels($newBusiness)['frontend'] : '客服';
}

/** 该账号能把订单改成哪些业务：财务 = 全部（非历史）业务；合作人员 = 自己账号开通的业务。 */
function pob_actor_businesses(array $actor)
{
    if (($actor['role'] ?? '') === 'finance') {
        return array_values(array_filter(array_keys(ps_business_catalog()), function ($name) { return empty(ps_business_catalog()[$name]['legacy']); }));
    }
    return array_values(array_filter(ps_actor_businesses($actor), function ($name) { return empty(ps_business_catalog()[$name]['legacy']); }));
}

function pob_is_participant($orderId, array $actor)
{
    if (empty($actor['employee_id'])) return false;
    $q = db()->prepare('SELECT 1 FROM project_participants WHERE order_id=? AND employee_id=? LIMIT 1');
    $q->execute([(int)$orderId, (int)$actor['employee_id']]);
    return (bool)$q->fetchColumn();
}

/** 能不能更正这张订单的业务类型：财务（未锁定）；本单参与人（未锁定）。 */
function pob_can_change($order, array $actor)
{
    if (($order['settlement_status'] ?? '') === 'locked') return false;
    if (($actor['role'] ?? '') === 'finance') return true;
    return in_array($actor['role'] ?? '', ['customer_service', 'technical'], true) && pob_is_participant((int)$order['id'], $actor);
}

/** 下拉默认选哪个业务：这个人最近半年参与最多的业务（排除订单当前业务），没有就取可选的第一个。 */
function pob_common_business(array $actor, $currentBusiness, array $choices)
{
    $choices = array_values(array_filter($choices, function ($name) use ($currentBusiness) { return $name !== ps_business_normalize($currentBusiness); }));
    if (!$choices || empty($actor['employee_id'])) return $choices[0] ?? '';
    $q = db()->prepare("SELECT o.project_type,COUNT(*) n FROM project_participants p JOIN project_orders o ON o.id=p.order_id WHERE p.employee_id=? AND o.order_date>=(CURDATE() - INTERVAL 180 DAY) GROUP BY o.project_type ORDER BY n DESC");
    $q->execute([(int)$actor['employee_id']]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $type) { $type = ps_business_normalize($type); if (in_array($type, $choices, true)) return $type; }
    return $choices[0];
}

/** 每个业务下，这个人的常用订单类型（按岗位默认），页面里作为类型下拉的默认项。 */
function pob_default_kinds(array $actor, array $businesses)
{
    $out = [];
    foreach ($businesses as $b) {
        $role = !empty($actor['employee_id']) ? ps_employee_default_role((int)$actor['employee_id'], $b, ($actor['role'] ?? '') === 'technical' ? 'technical' : 'customer_service') : null;
        $out[$b] = function_exists('ps_order_kind_from_role') ? (string)ps_order_kind_from_role($b, $role ?? '') : '';
    }
    return $out;
}

/** 给财务发站内信（和“申请结算”一样发给规则中心配置的财务审核人）。失败不影响业务。 */
function pob_notify_finance($title, $body, $orderId)
{
    try {
        if (!function_exists('ps_admin_reviewers')) require_once __DIR__ . '/ProjectSystem.php';
        $names = array_values(array_unique(array_map(function ($r) { return $r['real_name']; }, ps_admin_reviewers())));
        if (!$names) return;
        $q = db()->prepare('SELECT id FROM employees WHERE name IN (' . implode(',', array_fill(0, count($names), '?')) . ')'); $q->execute($names);
        $ins = db()->prepare('INSERT IGNORE INTO project_messages (employee_id,category,title,body,link,dedupe_key) VALUES (?,?,?,?,?,?)');
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $eid) $ins->execute([(int)$eid, 'order_change', $title, $body, '/project/order.php?id=' . (int)$orderId, 'order_change:' . (int)$orderId . ':' . (int)$eid . ':' . date('YmdHis')]);
    } catch (Throwable $e) { /* 站内信失败不影响业务 */ }
}

/** 已审核订单：按当前订单 / 参与人重算，和原快照（含此前调整）的差额作为调整计入 $payrollMonth。$removed 里的人不再参与，其分成按差额扣到 0。返回每人新旧金额。 */
function pob_post_deltas($order, array $old, $payrollMonth, $reason, array $actor, array $removed = [])
{
    $pdo = db();
    $summary = ps_summary($order, ps_costs((int)$order['id']), ps_participants((int)$order['id']));
    $ins = $pdo->prepare('INSERT INTO project_commission_adjustments (order_id,employee_id,commission_group,amount,payroll_month,reason,calc_note,created_by_admin) VALUES (?,?,?,?,?,?,?,?)');
    $deltas = [];
    foreach ($summary['groups'] as $group => $data) {
        if (!$data['people']) continue;
        if ($data['missing_rule'] || abs($data['weight'] - 1.0) > 0.000001) throw new RuntimeException('这张订单按新情况计算时，分成规则或参与人权重没配置完整，不能自动重算；请联系财务处理');
        $shares = ps_group_share_cents($data['people']);
        foreach ($data['people'] as $i => $person) {
            $key = $group . ':' . $person['employee_id'];
            $newCents = $summary['income'] <= 0 ? 0 : $shares[$i] + (int)round($person['calc']['subsidy'] * 100);
            $oldCents = (int)round(($old[$key] ?? 0) * 100);
            $delta = $newCents - $oldCents;
            $deltas[] = ['employee_id' => (int)$person['employee_id'], 'group' => $group, 'old' => $oldCents / 100, 'new' => $newCents / 100];
            if ($delta) $ins->execute([(int)$order['id'], $person['employee_id'], $group, $delta / 100, $payrollMonth, $reason, '重算 ¥' . money_plain($newCents / 100) . '，已计 ¥' . money_plain($oldCents / 100), (int)(($actor['type'] ?? '') === 'admin' ? $actor['id'] : 0)]);
            unset($old[$key]);
        }
    }
    foreach ($old as $key => $amount) {
        [$group, $employeeId] = explode(':', $key);
        if (!in_array((int)$employeeId, $removed, true)) throw new RuntimeException('原分成快照里的参与人和现在不一致，请联系财务核对后再操作');
        $oldCents = (int)round($amount * 100);
        if ($oldCents) $ins->execute([(int)$order['id'], (int)$employeeId, $group, -$oldCents / 100, $payrollMonth, $reason, '移出后不再分成，扣回已计 ¥' . money_plain($oldCents / 100), (int)(($actor['type'] ?? '') === 'admin' ? $actor['id'] : 0)]);
        $deltas[] = ['employee_id' => (int)$employeeId, 'group' => $group, 'old' => $oldCents / 100, 'new' => 0.0];
    }
    return $deltas;
}

/** 已审核订单的“旧分成”：原快照 + 此前的调整，按 组:人员 汇总。 */
function pob_old_amounts($orderId)
{
    $pdo = db(); $old = [];
    $snap = $pdo->prepare('SELECT employee_id,commission_group,SUM(commission_amount) amount FROM project_commission_snapshots WHERE order_id=? GROUP BY employee_id,commission_group'); $snap->execute([(int)$orderId]);
    foreach ($snap->fetchAll() as $s) $old[$s['commission_group'] . ':' . $s['employee_id']] = (float)$s['amount'];
    if (!$old) throw new RuntimeException('原审核快照不存在，不能自动重算，请联系财务核对');
    $prior = $pdo->prepare('SELECT commission_group,employee_id,SUM(amount) amount FROM project_commission_adjustments WHERE order_id=? GROUP BY commission_group,employee_id'); $prior->execute([(int)$orderId]);
    foreach ($prior->fetchAll() as $p) $old[$p['commission_group'] . ':' . $p['employee_id']] = ($old[$p['commission_group'] . ':' . $p['employee_id']] ?? 0) + (float)$p['amount'];
    return $old;
}

function pob_open_month($payrollMonth)
{
    $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$payrollMonth) ? (string)$payrollMonth : ps_next_open_month(date('Y-m'));
    $pdo = db();
    $pdo->prepare('INSERT INTO project_payroll_periods (period) VALUES (?) ON DUPLICATE KEY UPDATE period=period')->execute([$month]);
    $q = $pdo->prepare('SELECT status FROM project_payroll_periods WHERE period=? FOR UPDATE'); $q->execute([$month]);
    if ($q->fetchColumn() === 'locked') throw new RuntimeException($month . ' 已锁定，请选择未锁定月份');
    return $month;
}

function ps_change_order_business($orderId, $newBusiness, $newKind, array $actor, $payrollMonth = '')
{
    $newBusiness = ps_business_normalize((string)$newBusiness);
    $catalog = ps_business_catalog();
    if (!isset($catalog[$newBusiness]) || !empty($catalog[$newBusiness]['legacy'])) throw new RuntimeException('请选择正确的业务类型');
    $pdo = db(); $nested = $pdo->inTransaction();
    if ($nested) $pdo->exec('SAVEPOINT project_change_business'); else $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT * FROM project_orders WHERE id=? FOR UPDATE'); $q->execute([(int)$orderId]); $order = $q->fetch();
        if (!$order) throw new RuntimeException('订单不存在');
        $isFinance = ($actor['role'] ?? '') === 'finance';
        if (!$isFinance && !pob_can_change($order, $actor)) throw new RuntimeException('只有这张订单的参与人或财务可以更正业务类型');
        if (!in_array($newBusiness, pob_actor_businesses($actor), true)) throw new RuntimeException('你的账号没有开通“' . $newBusiness . '”业务，只能改成你自己的业务；其他业务请联系财务');
        $oldBusiness = ps_business_normalize($order['project_type']);
        if ($oldBusiness === $newBusiness) throw new RuntimeException('业务类型没有变化');
        if ($order['settlement_status'] === 'locked') throw new RuntimeException('订单已锁定，不能更正业务类型');
        $kinds = ps_business_order_kinds($newBusiness);
        $newKind = $kinds ? ps_order_kind_valid($newBusiness, $newKind) : '';
        if ($kinds && $newKind === '' && !empty($catalog[$newBusiness]['kind_required'])) throw new RuntimeException('请选择订单类型');
        $approved = $order['settlement_status'] === 'approved';
        $old = []; $month = null;
        if ($approved) { $month = pob_open_month($isFinance ? $payrollMonth : ''); $old = pob_old_amounts($orderId); }
        // 参与人岗位名换成新业务的岗位
        $roleMap = [];
        $people = $pdo->prepare('SELECT id,employee_id,commission_group,role_name FROM project_participants WHERE order_id=?'); $people->execute([(int)$orderId]);
        $setRole = $pdo->prepare('UPDATE project_participants SET role_name=? WHERE id=?');
        foreach ($people->fetchAll() as $pp) {
            $role = pob_new_role((int)$pp['employee_id'], $pp['commission_group'], (string)$pp['role_name'], $newBusiness);
            $roleMap[] = ['employee_id' => (int)$pp['employee_id'], 'group' => $pp['commission_group'], 'from' => $pp['role_name'], 'to' => $role];
            if ($role !== $pp['role_name']) $setRole->execute([$role, (int)$pp['id']]);
        }
        $pdo->prepare('UPDATE project_orders SET project_type=?,order_kind=?,row_version=row_version+1 WHERE id=?')->execute([$newBusiness, $newKind, (int)$orderId]);
        try { $pdo->prepare('UPDATE project_order_details SET business_name=? WHERE order_id=?')->execute([$newBusiness, (int)$orderId]); } catch (Throwable $e) { /* 没有专属资料表时忽略 */ }
        $deltas = [];
        if ($approved) {
            $order['project_type'] = $newBusiness; $order['order_kind'] = $newKind;
            $deltas = pob_post_deltas($order, $old, $month, '更正业务类型：' . $oldBusiness . ' → ' . $newBusiness . ($newKind !== '' ? '（' . $newKind . '）' : '') . ($isFinance ? '' : '（参与人自行更正）'), $actor);
        }
        ps_audit('order', (int)$orderId, 'change_business', $actor, ['before' => $oldBusiness, 'after' => $newBusiness, 'kind' => $newKind, 'month' => $month, 'by' => $isFinance ? 'finance' : 'participant', 'roles' => $roleMap, 'deltas' => $deltas]);
        if ($nested) $pdo->exec('RELEASE SAVEPOINT project_change_business'); else $pdo->commit();
        if (!$isFinance) pob_notify_finance('订单 ' . $order['order_no'] . ' 的业务类型被更正：' . $oldBusiness . ' → ' . $newBusiness, '由订单参与人自行更正' . ($approved ? '；订单已审核，原分成快照保留，差额已作为调整计入 ' . $month . '，请核对' : '，订单未审核') . '。', $orderId);
        return ['before' => $oldBusiness, 'after' => $newBusiness, 'deltas' => $deltas, 'approved' => $approved, 'month' => $month];
    } catch (Throwable $e) {
        if ($nested) $pdo->exec('ROLLBACK TO SAVEPOINT project_change_business'); elseif ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** 订单参与人把自己从这单移除（只能移自己）。 */
function ps_leave_order($orderId, array $actor)
{
    if (!in_array($actor['role'] ?? '', ['customer_service', 'technical'], true) || empty($actor['employee_id'])) throw new RuntimeException('只能移除你自己');
    $me = (int)$actor['employee_id'];
    $pdo = db(); $nested = $pdo->inTransaction();
    if ($nested) $pdo->exec('SAVEPOINT project_leave_order'); else $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT * FROM project_orders WHERE id=? FOR UPDATE'); $q->execute([(int)$orderId]); $order = $q->fetch();
        if (!$order) throw new RuntimeException('订单不存在');
        if ($order['settlement_status'] === 'locked') throw new RuntimeException('订单已锁定，不能移除');
        $mine = $pdo->prepare('SELECT id,commission_group,role_name,group_weight FROM project_participants WHERE order_id=? AND employee_id=?'); $mine->execute([(int)$orderId, $me]); $myRows = $mine->fetchAll();
        if (!$myRows) throw new RuntimeException('你不是这张订单的参与人');
        $others = $pdo->prepare('SELECT COUNT(DISTINCT employee_id) FROM project_participants WHERE order_id=? AND employee_id<>?'); $others->execute([(int)$orderId, $me]);
        if ((int)$others->fetchColumn() === 0) throw new RuntimeException('你是这张订单唯一的参与人，不能把自己移除（订单会没人负责）；订单录错了请改“业务类型”，或联系财务处理');
        $approved = $order['settlement_status'] === 'approved';
        $month = null; $old = [];
        if ($approved) {
            $month = pob_open_month('');
            $old = pob_old_amounts($orderId);
            try {
                $inReq = $pdo->prepare("SELECT COUNT(*) FROM project_settlement_request_items i JOIN project_settlement_requests r ON r.id=i.request_id WHERE i.order_id=? AND i.employee_id=? AND r.status<>'rejected'"); $inReq->execute([(int)$orderId, $me]);
                if ((int)$inReq->fetchColumn() > 0) throw new RuntimeException('这张订单已在你的结算申请里，不能直接移除；请先撤回申请，或联系财务');
            } catch (PDOException $e) { /* 没有结算申请表：没有申请 */ }
        }
        $del = $pdo->prepare('DELETE FROM project_participants WHERE id=?');
        foreach ($myRows as $r) $del->execute([(int)$r['id']]);
        // 同组其余人的权重按比例补满（原来这一组合计 1，她走后补回 1），不让这组分成凭空少掉一块
        foreach (array_unique(array_column($myRows, 'commission_group')) as $group) {
            $rest = $pdo->prepare("SELECT id,group_weight,role_name FROM project_participants WHERE order_id=? AND commission_group=?"); $rest->execute([(int)$orderId, $group]); $restRows = $rest->fetchAll();
            $restRows = array_values(array_filter($restRows, function ($r) { return !ps_software_testing_role($r['role_name']); }));
            $sum = array_sum(array_map(function ($r) { return (float)$r['group_weight']; }, $restRows));
            if (!$restRows || $sum <= 0 || abs($sum - 1.0) < 0.000001) continue;
            $upd = $pdo->prepare('UPDATE project_participants SET group_weight=? WHERE id=?'); $acc = 0.0;
            foreach ($restRows as $i => $r) { $w = $i === count($restRows) - 1 ? round(1.0 - $acc, 6) : round((float)$r['group_weight'] / $sum, 6); $acc += $w; $upd->execute([$w, (int)$r['id']]); }
        }
        $pdo->prepare('UPDATE project_orders SET row_version=row_version+1 WHERE id=?')->execute([(int)$orderId]);
        $deltas = [];
        if ($approved) $deltas = pob_post_deltas($order, $old, $month, '参与人把自己从订单移除', $actor, [$me]);
        ps_audit('order', (int)$orderId, 'leave_order', $actor, ['employee_id' => $me, 'groups' => array_column($myRows, 'commission_group'), 'month' => $month, 'deltas' => $deltas]);
        if ($nested) $pdo->exec('RELEASE SAVEPOINT project_leave_order'); else $pdo->commit();
        $name = $pdo->prepare('SELECT name FROM employees WHERE id=?'); $name->execute([$me]);
        pob_notify_finance((string)$name->fetchColumn() . ' 把自己从订单 ' . $order['order_no'] . ' 移除了', '订单参与人自行移除' . ($approved ? '；订单已审核，原分成快照保留，差额已作为调整计入 ' . $month . '，请核对' : '，订单未审核') . '。', $orderId);
        return ['approved' => $approved, 'month' => $month, 'deltas' => $deltas];
    } catch (Throwable $e) {
        if ($nested) $pdo->exec('ROLLBACK TO SAVEPOINT project_leave_order'); elseif ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
