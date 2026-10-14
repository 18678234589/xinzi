<?php
function psw_is_editor_order($order)
{
    return ($order['project_type'] ?? '') === '微信代写' && ($order['order_kind'] ?? '') === '编辑对接';
}

/** 代写的接单方式与收款渠道是两个维度；对接编辑不能按店铺名称拿自接单提成。 */
function psw_is_member($employeeId)
{
    if ((int)$employeeId <= 0) return false;
    static $cache = [];
    if (!array_key_exists((int)$employeeId, $cache)) {
        $q = db()->prepare("SELECT 1 FROM project_users u JOIN project_user_businesses b ON b.user_id=u.id AND b.business_name='微信代写' AND b.is_default=1 WHERE u.employee_id=? AND u.is_active=1 LIMIT 1");
        $q->execute([(int)$employeeId]); $cache[(int)$employeeId] = (bool)$q->fetchColumn();
    }
    return $cache[(int)$employeeId];
}

/** 纯函数：逐行先读显式接单方式，再识别旧表的编辑员/接单客服列。金额与店铺不用于判断是否对接。 */
function psw_source_from_row($head, $row, $sheetName = '')
{
    $cells = [];
    foreach ($head as $i => $name) $cells[trim((string)$name)] = trim((string)($row[$i] ?? ''));
    $source = ''; $kind = ''; $explicitException = '';
    foreach (['接单方式', '订单来源', '参与方式', '订单类型'] as $label) {
        $value = $cells[$label] ?? '';
        if ($value === '') continue;
        if ($label === '订单类型' && in_array($value, ['合并单','退款冲减'], true)) { $explicitException = $value; continue; }
        if (in_array($value, ['编辑对接', '对接编辑', '编辑订单', '对接订单', '对接建群', '建群'], true)) { $source = 'editor'; $kind = '编辑对接'; break; }
        if (in_array($value, ['淘宝自接', '自接淘宝', '店铺订单', '淘宝订单'], true)) { $source = 'self'; $kind = '店铺订单'; break; }
        if (in_array($value, ['微信自接', '自接微信', '微信付款', '微信订单'], true)) { $source = 'self'; $kind = '微信付款'; break; }
        if (in_array($value, ['自接订单', '自接', '正常接单'], true)) { $source = 'self'; break; }
        if ($label !== '订单类型') throw new RuntimeException('接单方式请选“自接订单”或“编辑对接”，不能用店铺或付款方式代替');
    }
    if ($source === '') {
        foreach (['对接编辑', '建群编辑', '编辑员'] as $label) {
            if (!empty($cells[$label])) { $source = 'editor'; $kind = '编辑对接'; break; }
        }
        if ($source === '' && preg_match('/对接|建群|编辑订单/u', (string)$sheetName)) { $source = 'editor'; $kind = '编辑对接'; }
        if ($source === '') $source = 'self';
    }
    if ($source === 'self' && $kind === '') {
        $channel = $cells['收款方式'] ?? ($cells['付款方式'] ?? '');
        if ($channel !== '') {
            if (in_array($channel, ['淘宝', '店铺', '店铺付款'], true)) $kind = '店铺订单';
            elseif (in_array($channel, ['微信', '微信付款'], true)) $kind = '微信付款';
            else throw new RuntimeException('微信代写收款方式请选“淘宝”或“微信”');
        } else {
            $shop = $cells['店铺'] ?? ($cells['店铺名称'] ?? ($cells['店铺编码'] ?? ''));
            if ($shop !== '') $kind = mb_strpos($shop, '微信') !== false ? '微信付款' : '店铺订单';
            elseif (preg_match('/微信付款|微信订单|微信自接/u', (string)$sheetName)) $kind = '微信付款';
            elseif (preg_match('/淘宝|店铺/u', (string)$sheetName)) $kind = '店铺订单';
        }
    }
    $amount = $cells['售价'] ?? ($cells['金额'] ?? ($cells['收入'] ?? ''));
    $amount = str_replace([',', '¥', '￥', ' '], '', $amount);
    if (is_numeric($amount) && (float)$amount < 0) $kind = '退款冲减';
    elseif ($explicitException !== '') $kind = $explicitException;
    elseif (($amount === '' || (is_numeric($amount) && (float)$amount == 0)) && ($cells['提成'] ?? '') !== '' && is_numeric($cells['提成']) && (float)$cells['提成'] == 0) $kind = '合并单';
    return ['source' => $source, 'kind' => $kind];
}

/** 编辑对接独立订单只记录编辑参与；自接单里本人误填编辑列时归回客服，不重复挂两组。 */
function psw_people($people, $source, $employeeId = 0)
{
    if ($source === 'editor') {
        foreach ($people['customer_service'] as $id => $person) {
            // 原表“接单客服”是销售方，不能因为编辑上传而把销售方变成编辑。
            if ((int)$employeeId > 0 && (int)$id !== (int)$employeeId) continue;
            if ((int)$employeeId === 0 && $people['technical']) continue;
            $person['role'] = '对接编辑'; $people['technical'][$id] = $person;
        }
        $people['customer_service'] = [];
        foreach ($people['technical'] as &$person) $person['role'] = '对接编辑';
        unset($person);
    } elseif ((int)$employeeId > 0 && isset($people['technical'][(int)$employeeId])) {
        $person = $people['technical'][(int)$employeeId]; $person['role'] = '客服';
        $people['customer_service'][(int)$employeeId] = $person;
        unset($people['technical'][(int)$employeeId]);
    }
    return $people;
}

function psw_participation_label($business, $group, $kind, $role = '')
{
    if (!in_array(ps_business_normalize($business), ['微信代写', '软文代写'], true)) return '';
    if ($group === 'technical') {
        if ($business === '微信代写' && $kind !== '编辑对接' && $role !== '' && !in_array('对接编辑', ps_role_keys($role), true)) return '待核对接单身份';
        return '编辑对接';
    }
    if ($business === '微信代写') {
        if (in_array($kind, ['合并单', '退款冲减'], true)) return '自接' . $kind . '（无单量补助）';
        return $kind === '微信付款' ? '微信自接' : ($kind === '编辑对接' ? '编辑对接' : '淘宝自接');
    }
    return '软文代写接单客服';
}

/** 列表批量读取参与身份；合作人员只看本人，财务同时看接单客服和编辑。 */
function psw_list_labels($orders, $actor)
{
    $ids = [];
    foreach ($orders as $order) if (in_array($order['project_type'], ['微信代写', '软文代写'], true)) $ids[] = (int)$order['id'];
    if (!$ids) return [];
    $sql = 'SELECT p.order_id,p.commission_group,p.role_name,e.name,o.project_type,o.order_kind FROM project_participants p JOIN project_orders o ON o.id=p.order_id JOIN employees e ON e.id=p.employee_id WHERE p.order_id IN (' . implode(',', array_fill(0,count($ids),'?')) . ')';
    $params = $ids;
    if (($actor['role'] ?? '') !== 'finance') { $sql .= ' AND p.employee_id=?'; $params[] = (int)($actor['employee_id'] ?? 0); }
    $q = db()->prepare($sql); $q->execute($params); $labels = [];
    foreach ($q->fetchAll() as $person) {
        $label = psw_participation_label($person['project_type'], $person['commission_group'], $person['order_kind'], $person['role_name']);
        if (($actor['role'] ?? '') === 'finance') $label = $person['name'] . '：' . $label;
        $labels[(int)$person['order_id']][] = $label;
    }
    foreach ($labels as &$items) $items = array_values(array_unique($items)); unset($items);
    return $labels;
}

/** 更改自接/对接类型时同步参与岗位；已审核跨组改动必须走财务参与人调整，不能悄悄改历史快照。 */
function psw_change_kind($orderId, $before, $after, $actor)
{
    $pdo = db(); $nested = $pdo->inTransaction();
    if (!$nested) $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT * FROM project_orders WHERE id=? FOR UPDATE'); $q->execute([(int)$orderId]); $order = $q->fetch();
        if (!$order || (string)$order['order_kind'] !== (string)$before) throw new RuntimeException('订单类型已更新，请刷新后再修改');
        $move = null;
        if ($order['project_type'] === '微信代写') {
            if ($after === '编辑对接' && $before !== '编辑对接') $move = ['customer_service', 'technical', '对接编辑'];
            elseif ($before === '编辑对接' && in_array($after, ['店铺订单', '微信付款'], true)) $move = ['technical', 'customer_service', '客服'];
        }
        if ($move) {
            if ($order['settlement_status'] !== 'draft') throw new RuntimeException('已审核订单不能仅改类型来更换接单身份，请由财务按参与人调整流程核对');
            if ($after === '编辑对接') {
                $hasMoney = (float)$order['receipt_amount'] != 0 || (float)$order['refund_amount'] != 0;
                foreach (['project_costs','project_cash_movements'] as $table) {
                    $q=$pdo->prepare('SELECT COUNT(*) FROM '.$table.' WHERE order_id=?');$q->execute([$orderId]);$hasMoney=$hasMoney || (int)$q->fetchColumn()>0;
                }
                if ($hasMoney) throw new RuntimeException('原单已登记收款或成本，请由财务核对金额归属；对接原单应新增编辑参与，不能把整张销售单改为对接记录');
                $note = (float)$order['contract_amount'] != 0 ? '；对接原单参考金额 ¥' . money_plain($order['contract_amount']) . '（不计销售收入）' : '';
                $pdo->prepare('UPDATE project_orders SET contract_amount=0,note=CONCAT(note,?) WHERE id=?')->execute([$note,$orderId]);
                $pdo->prepare("UPDATE project_order_sources SET price_source='manual' WHERE order_id=?")->execute([$orderId]);
            } elseif ($before === '编辑对接') $pdo->prepare("UPDATE project_order_sources SET price_source='missing' WHERE order_id=?")->execute([$orderId]);
            $q = $pdo->prepare('SELECT id,employee_id,commission_group,role_name,group_weight FROM project_participants WHERE order_id=?'); $q->execute([(int)$orderId]); $people = $q->fetchAll();
            $target = [];
            foreach ($people as $person) if ($person['commission_group'] === $move[1]) $target[(int)$person['employee_id']] = $person;
            $weights = array_column($target, 'group_weight');
            if (count($weights) > 1 && max($weights) - min($weights) > 0.000002) throw new RuntimeException('参与人权重已由财务手动设置，请先由财务核对接单身份');
            foreach ($people as $person) if ($person['commission_group'] === $move[0]) {
                if (isset($target[(int)$person['employee_id']])) $pdo->prepare('DELETE FROM project_participants WHERE id=?')->execute([$person['id']]);
                else {
                    $pdo->prepare('UPDATE project_participants SET commission_group=?,role_name=? WHERE id=?')->execute([$move[1], $move[2], $person['id']]);
                    $target[(int)$person['employee_id']] = $person;
                }
            }
            if ($target) {
                $count = count($target); $n = 0; $base = intdiv(1000000, $count);
                foreach ($target as $employeeId => $person) {
                    $weight = (++$n === $count ? 1000000 - $base * ($count - 1) : $base) / 1000000;
                    $pdo->prepare('UPDATE project_participants SET role_name=?,group_weight=? WHERE order_id=? AND employee_id=? AND commission_group=?')->execute([$move[2], $weight, $orderId, $employeeId, $move[1]]);
                }
            }
            ps_audit('order', $orderId, 'writing_participation_change', $actor, ['before_kind'=>$before,'kind'=>$after,'before_people'=>$people]);
        }
        $pdo->prepare('UPDATE project_orders SET order_kind=?,row_version=row_version+1 WHERE id=?')->execute([$after, (int)$orderId]);
        if (!$nested) $pdo->commit();
    } catch (Throwable $e) { if (!$nested && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
