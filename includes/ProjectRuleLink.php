<?php
/**
 * 订单列表“待配置”快捷对应：参与人在这张订单上的“业务 + 岗位 + 订单类型”没有匹配到启用中的分成规则时，
 * 在列表页直接列出规则中心里同业务同组的规则，一键对应：
 * - 规则已启用但订单类型 / 岗位对不上 → 把订单类型（或参与人岗位）改成规则要求的；
 * - 规则被停用了 → 启用它（影响同业务全部订单，只有财务和参与人所在部门的主管能启用）。
 * 参与人本人 / 代录人可以改自己订单的类型和岗位；财务、部门主管（project_dept_heads）可对订单上的任何参与人操作。已审核 / 锁定的订单不在这里改。
 */

/** 业务 / 订单是否能用这个功能，以及当前人能做什么：['edit'=>bool,'enable'=>bool,'why'=>'不能的原因']。 */
function prl_scope($order, $actor)
{
    $none = ['edit' => false, 'enable' => false, 'why' => ''];
    if (in_array($order['settlement_status'], ['approved', 'locked'], true)) return ['why' => '订单已审核，分成已锁定，请联系财务'] + $none;
    if (($actor['role'] ?? '') === 'finance') return ['edit' => true, 'enable' => true, 'why' => ''];
    $emp = (int)($actor['employee_id'] ?? 0);
    if ($emp <= 0) return ['why' => '没有权限'] + $none;
    $pdo = db();
    $q = $pdo->prepare('SELECT 1 FROM project_participants WHERE order_id=? AND employee_id=? UNION SELECT 1 FROM project_department_uploaders WHERE order_id=? AND employee_id=? LIMIT 1');
    $q->execute([(int)$order['id'], $emp, (int)$order['id'], $emp]);
    $own = (bool)$q->fetchColumn();
    $head = false;
    try {
        $h = $pdo->prepare('SELECT 1 FROM project_dept_heads h JOIN employees e ON e.department=h.department JOIN project_participants p ON p.employee_id=e.id WHERE h.employee_id=? AND p.order_id=? LIMIT 1');
        $h->execute([$emp, (int)$order['id']]);
        $head = (bool)$h->fetchColumn();
    } catch (Throwable $e) {}
    if (!$own && !$head) return ['why' => '只能处理本人参与或录入的订单（或本部门成员的订单）'] + $none;
    return ['edit' => true, 'enable' => $head, 'why' => ''];
}

/** 规则的一句话说明。 */
function prl_rule_text(array $r)
{
    $parts = [($r['calc_mode'] ?? 'pool') === 'individual' ? '独立计算' : '组池分成'];
    if ((float)$r['rate'] > 0) $parts[] = '提成 ' . rtrim(rtrim(number_format((float)$r['rate'] * 100, 2, '.', ''), '0'), '.') . '%';
    if ((float)$r['per_order_subsidy'] > 0) $parts[] = '每单补助 ¥' . rtrim(rtrim(number_format((float)$r['per_order_subsidy'], 2, '.', ''), '0'), '.');
    if (trim((string)$r['note']) !== '') $parts[] = trim((string)$r['note']);
    return implode(' · ', $parts);
}

/** 这张订单上哪些参与人没匹配到有效规则，以及能对应的候选规则。 */
function prl_info($orderId, $actor)
{
    $q = db()->prepare('SELECT * FROM project_orders WHERE id=?');
    $q->execute([(int)$orderId]);
    $order = $q->fetch();
    if (!$order) throw new RuntimeException('订单不存在');
    $scope = prl_scope($order, $actor);
    $business = (string)$order['project_type'];
    $kind = (string)$order['order_kind'];
    $out = ['order' => ['id' => (int)$order['id'], 'order_no' => $order['order_no'], 'business' => $business, 'kind' => $kind, 'kind_label' => $kind === '' ? '未设置' : pkl_label($business, $kind)],
        'can_edit' => $scope['edit'], 'can_enable' => $scope['enable'], 'why' => $scope['why'], 'people' => []];
    foreach (ps_participants((int)$order['id']) as $p) {
        $role = (string)($p['role_name'] ?? '');
        if (ps_rules_for_person($p['commission_group'], $business, $order['order_date'], $role, $kind)) continue;
        $keys = ps_role_keys($role);
        $effKind = ps_role_rule_order_kind($business, $p['commission_group'], $role, $kind);
        $st = db()->prepare("SELECT * FROM project_commission_rules WHERE commission_group=? AND project_type IN (?, '*') AND effective_from<=? ORDER BY is_active DESC, effective_from DESC, id DESC");
        $st->execute([$p['commission_group'], $business === '网站定制' ? 'AI网站定制' : $business, $order['order_date']]);
        $cands = []; $seen = []; $anyActive = false;
        foreach ($st->fetchAll() as $r) {
            $rRole = (string)$r['role_name']; $rKind = (string)$r['order_kind'];
            $key = $rRole . '|' . $rKind . '|' . $r['project_type'];
            if (isset($seen[$key])) continue; // 同岗位同类型取最新一版
            $seen[$key] = true;
            $roleOk = in_array($rRole, ['*', ''], true) || in_array($rRole, $keys, true);
            $kindOk = in_array($rKind, ['*', ''], true) || $rKind === $effKind;
            $active = (int)$r['is_active'] === 1;
            if ($active) $anyActive = true;
            $steps = [];
            if (!$roleOk) $steps[] = '岗位改为「' . $rRole . '」';
            if (!$kindOk) $steps[] = '订单类型改为「' . pkl_label($business, $rKind) . '」';
            if (!$active) $steps[] = '启用这条规则';
            $cands[] = ['rule_id' => (int)$r['id'], 'active' => $active, 'role' => $rRole === '' ? '*' : $rRole, 'kind' => $rKind === '' ? '*' : $rKind,
                'kind_label' => in_array($rKind, ['*', ''], true) ? '全部类型' : pkl_label($business, $rKind), 'text' => prl_rule_text($r),
                'action' => $steps ? implode('，并', $steps) : '', 'needs_enable' => !$active, 'self_edit_only' => $active && !$steps];
        }
        $out['people'][] = ['employee_id' => (int)$p['employee_id'], 'name' => $p['name'], 'group' => $p['commission_group'], 'role' => $role,
            'reason' => $cands ? ($anyActive ? '订单类型「' . ($kind === '' ? '未设置' : pkl_label($business, $kind)) . '」或岗位「' . $role . '」和规则中心里启用的规则对不上' : '规则中心里这个业务的规则都已停用') : '规则中心里还没有这个业务对应的规则（需财务新增）',
            'rules' => $cands];
    }
    return $out;
}

/** 按选中的规则对应：改订单类型 / 岗位，必要时启用规则。返回提示文字。 */
function prl_apply($orderId, $ruleId, $employeeId, $actor)
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT * FROM project_orders WHERE id=? FOR UPDATE');
        $q->execute([(int)$orderId]);
        $order = $q->fetch();
        if (!$order) throw new RuntimeException('订单不存在');
        $scope = prl_scope($order, $actor);
        if (!$scope['edit']) throw new RuntimeException($scope['why'] ?: '没有权限');
        $r = $pdo->prepare('SELECT * FROM project_commission_rules WHERE id=?');
        $r->execute([(int)$ruleId]);
        $rule = $r->fetch();
        if (!$rule) throw new RuntimeException('规则不存在，请刷新页面');
        $business = (string)$order['project_type'];
        if (!in_array($rule['project_type'], [$business, '*', $business === '网站定制' ? 'AI网站定制' : $business], true)) throw new RuntimeException('这条规则不属于本订单的业务');
        $person = null;
        foreach (ps_participants((int)$order['id']) as $p) if ((int)$p['employee_id'] === (int)$employeeId && $p['commission_group'] === $rule['commission_group']) $person = $p;
        if (!$person) throw new RuntimeException('订单上没有对应这条规则的参与人');
        $done = [];
        if ((int)$rule['is_active'] !== 1) {
            if (!$scope['enable']) throw new RuntimeException('这条规则已停用，需要财务或部门主管启用');
            $pdo->prepare('UPDATE project_commission_rules SET is_active=1 WHERE id=?')->execute([(int)$rule['id']]);
            ps_audit('rule', (int)$rule['id'], 'toggle', $actor, ['via' => 'order_list', 'order_id' => (int)$order['id'], 'enabled' => 1]);
            $done[] = '已启用规则';
        }
        $rKind = (string)$rule['order_kind'];
        if (!in_array($rKind, ['*', ''], true)) {
            $effKind = ps_role_rule_order_kind($business, $rule['commission_group'], (string)$person['role_name'], (string)$order['order_kind']);
            if ($effKind !== $rKind) {
                $valid = ps_order_kind_valid($business, $rKind);
                $pdo->prepare('UPDATE project_orders SET order_kind=?, row_version=row_version+1 WHERE id=?')->execute([$valid, (int)$order['id']]);
                ps_audit('order', (int)$order['id'], 'rule_link_kind', $actor, ['before' => $order['order_kind'], 'order_kind' => $valid, 'rule_id' => (int)$rule['id']]);
                $done[] = '订单类型改为「' . pkl_label($business, $valid) . '」';
            }
        }
        $rRole = (string)$rule['role_name'];
        if (!in_array($rRole, ['*', ''], true) && !in_array($rRole, ps_role_keys($person['role_name']), true)) {
            $pdo->prepare('UPDATE project_participants SET role_name=? WHERE id=?')->execute([$rRole, (int)$person['id']]);
            ps_audit('order', (int)$order['id'], 'rule_link_role', $actor, ['employee_id' => (int)$person['employee_id'], 'before' => $person['role_name'], 'role' => $rRole, 'rule_id' => (int)$rule['id']]);
            $done[] = $person['name'] . ' 的岗位改为「' . $rRole . '」';
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    require_once __DIR__ . '/ProjectAutoReview.php';
    pa_after_save([(int)$orderId]);
    return $done ? implode('，', $done) : '这条规则已适用，无需修改';
}
