<?php
/**
 * 同一笔订单的“加入”与“价格裁决”。
 * - 同一笔销售只记一次：不同业务的同号订单金额相同（如软文代写 ¥300 与微信代写 ¥300），后录入的人加入原订单，不再另建分单；
 * - 同业务多位客服 / 技术共同接单：加入原订单的同一分成组，按人数均分该组权重；
 * - 售价裁决：同号订单表格价与系统价不同，用店铺流水价当裁判，流水能说明谁对就自动采用，不用麻烦财务。
 */
require_once __DIR__ . '/ProjectOrderSplit.php';
require_once __DIR__ . '/ProjectBusiness.php';
require_once __DIR__ . '/ProjectOrderSource.php';

/** 售价单元格 → 金额（支持“640+260”算式、¥ 和千分位）；不是金额返回 null。 */
function poj_amount_value($raw)
{
    $text = str_replace([',', '¥', '￥', ' '], '', trim((string)$raw));
    if ($text === '') return null;
    if (preg_match('/^\d+(?:\.\d+)?(?:[+＋]\d+(?:\.\d+)?)+$/u', $text)) return round(array_sum(array_map('floatval', preg_split('/[+＋]/u', $text))), 2);
    return is_numeric($text) ? round((float)$text, 2) : null;
}

/** 同一笔销售可合并的业务对：软文代写 / 微信代写直接认定；其他业务须有店铺流水价等于本行金额佐证。商标和“沿用原单售价”的业务不适用。 */
function poj_same_sale_allowed($parentBusiness, $newBusiness, $amount, $orderNo, $parentAmount)
{
    $parentBusiness = ps_business_normalize($parentBusiness);
    $newBusiness = ps_business_normalize($newBusiness);
    if ($parentBusiness === $newBusiness || $amount === null || $amount <= 0 || abs((float)$parentAmount - $amount) >= 0.01) return false;
    $catalog = ps_business_catalog();
    foreach ([$parentBusiness, $newBusiness] as $name) {
        if ($name === '商标' || !isset($catalog[$name]) || !empty($catalog[$name]['price_from_order'])) return false;
    }
    $family = ['软文代写', '微信代写'];
    if (in_array($parentBusiness, $family, true) && in_array($newBusiness, $family, true)) return true;
    foreach (ps_shop_order_lookup($orderNo) as $flow) {
        if ($flow['price'] !== null && abs((float)$flow['price'] - $amount) < 0.01) return true;
    }
    return false;
}

/** 某分成组现有权重是否均分（财务手动调过权重的订单不自动改动）。 */
function poj_group_weights_equal($orderId, $group)
{
    $q = db()->prepare('SELECT group_weight FROM project_participants WHERE order_id=? AND commission_group=?');
    $q->execute([(int)$orderId, $group]);
    $weights = array_map('floatval', $q->fetchAll(PDO::FETCH_COLUMN));
    if (count($weights) < 2) return true;
    return max($weights) - min($weights) < 0.000002;
}

/** 上传人加入原订单时落在哪个分成组、什么岗位：优先加入还没人的组（如软文代写原单只有客服时记为对接编辑），两组都有人才按本人上传角色。 */
function poj_join_target($employeeId, $parentBusiness, $parentOrderId, $actorRole)
{
    $parentBusiness = ps_business_normalize($parentBusiness);
    $labels = ps_business_people_labels($parentBusiness);
    $taken = ['technical' => ps_import_group_taken((int)$parentOrderId, 'technical'), 'customer_service' => ps_import_group_taken((int)$parentOrderId, 'customer_service')];
    if (!$taken['technical'] && !$taken['customer_service']) $group = $actorRole === 'technical' ? 'technical' : 'customer_service';
    elseif (!$taken['technical']) $group = 'technical';
    elseif (!$taken['customer_service']) $group = 'customer_service';
    else $group = $actorRole === 'technical' ? 'technical' : 'customer_service';
    $role = ps_employee_default_role((int)$employeeId, $parentBusiness, $group);
    if ($role === null) {
        $q = db()->prepare('SELECT p.role_name FROM project_participants p JOIN project_orders o ON o.id=p.order_id WHERE p.employee_id=? AND p.commission_group=? AND o.project_type=? AND p.role_name<>\'\' GROUP BY p.role_name ORDER BY COUNT(*) DESC LIMIT 1');
        $q->execute([(int)$employeeId, $group, $parentBusiness]);
        $role = $q->fetchColumn() ?: ($group === 'technical' ? $labels['frontend'] : '客服');
    }
    return ['group' => $group, 'role' => $role];
}

/** 加入后该组的权重：人数均分（最后一人补尾差）。已在组内则不重复加入。返回是否新增。 */
function poj_join_group($orderId, $employeeId, $group, $role, $actor, $context = [])
{
    $pdo = db();
    $q = $pdo->prepare('SELECT id FROM project_participants WHERE order_id=? AND employee_id=? AND commission_group=?');
    $q->execute([(int)$orderId, (int)$employeeId, $group]);
    if ($q->fetchColumn()) return false;
    if (!poj_group_weights_equal($orderId, $group)) throw new RuntimeException('原订单该分成组的权重已由财务手动调整，不能自动加入，请财务处理');
    $pdo->prepare('INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,?,?,0)')->execute([(int)$orderId, (int)$employeeId, $group, $role]);
    $ids = $pdo->prepare('SELECT id FROM project_participants WHERE order_id=? AND commission_group=? ORDER BY id');
    $ids->execute([(int)$orderId, $group]);
    $ids = $ids->fetchAll(PDO::FETCH_COLUMN);
    $base = intdiv(1000000, count($ids));
    $update = $pdo->prepare('UPDATE project_participants SET group_weight=? WHERE id=?');
    foreach ($ids as $i => $id) $update->execute([($i === count($ids) - 1 ? 1000000 - $base * (count($ids) - 1) : $base) / 1000000, (int)$id]);
    $pdo->prepare('UPDATE project_orders SET row_version=row_version+1 WHERE id=?')->execute([(int)$orderId]);
    ps_audit('order', (int)$orderId, 'import_join_group', $actor, ['employee_id' => (int)$employeeId, 'group' => $group, 'role' => $role, 'people_in_group' => count($ids)] + $context);
    return true;
}

/** 该业务的接单技术候选（上传页报错行里在线补录用）：id => 姓名。 */
function poj_technician_choices($business)
{
    static $cache = [];
    $business = ps_business_normalize($business);
    if (isset($cache[$business])) return $cache[$business];
    $q = db()->query("SELECT DISTINCT e.id,e.name,u.id user_id,u.employee_id,u.role FROM project_users u JOIN employees e ON e.id=u.employee_id WHERE u.is_active=1 AND u.role IN ('technical','governance') ORDER BY e.name");
    $list = [];
    foreach ($q->fetchAll() as $user) {
        if (ps_active_employee_for_business((int)$user['employee_id'], 'technical', $business)) $list[(int)$user['id']] = $user['name'];
    }
    return $cache[$business] = $list;
}

/**
 * 售价裁决。$snapshot 是原单（contract_amount / price_source / id / order_no），$sheetPrice 是本行金额。
 * 返回 ['verdict' => same|adopt_sheet|keep_system|unknown, 'flow' => 流水价|null]：
 *   adopt_sheet  店铺流水价 = 表格价 ≠ 系统价：系统价应更正为表格价；
 *   keep_system  店铺流水价 = 系统价 ≠ 表格价：沿用系统价，表格价不一致只提示；
 *   unknown      没有流水或流水不支持任何一边：仍交财务核对。
 * 原单有分单 / 是分单子单时金额本就只是一部分，不裁决。
 */
function poj_price_verdict($snapshot, $sheetPrice, $orderNo = '')
{
    $sheet = poj_amount_value($sheetPrice);
    $system = round((float)($snapshot['contract_amount'] ?? 0), 2);
    if ($sheet === null || abs($sheet - $system) < 0.01) return ['verdict' => 'same', 'flow' => null];
    $orderId = (int)($snapshot['id'] ?? 0);
    if ($orderId && (pos_parent_of($orderId) || pos_children_of($orderId))) return ['verdict' => 'unknown', 'flow' => null];
    $prices = [];
    foreach (ps_shop_order_lookup($orderNo !== '' ? (string)$orderNo : (string)($snapshot['order_no'] ?? '')) as $flow) if ($flow['price'] !== null && empty($flow['refund'])) $prices[] = round((float)$flow['price'], 2);
    $prices = array_values(array_unique($prices));
    if (count($prices) !== 1) return ['verdict' => 'unknown', 'flow' => null];
    $flowPrice = $prices[0];
    if (abs($flowPrice - $sheet) < 0.01 && (($snapshot['price_source'] ?? 'manual') !== 'shop')) return ['verdict' => 'adopt_sheet', 'flow' => $flowPrice];
    if (abs($flowPrice - $system) < 0.01) return ['verdict' => 'keep_system', 'flow' => $flowPrice];
    return ['verdict' => 'unknown', 'flow' => $flowPrice];
}

/** 把售价裁决里“采用表格价”的结果写回订单（仅草稿 / 未审核、没有收款的订单）。返回是否已更正。 */
function poj_apply_price($orderId, $newPrice, $flowPrice, $actor, $context = [])
{
    $pdo = db();
    $q = $pdo->prepare('SELECT contract_amount,settlement_status,receipt_amount FROM project_orders WHERE id=? FOR UPDATE');
    $q->execute([(int)$orderId]);
    $order = $q->fetch();
    if (!$order || in_array($order['settlement_status'], ['approved', 'locked'], true)) return false;
    $pdo->prepare('UPDATE project_orders SET contract_amount=?,row_version=row_version+1 WHERE id=?')->execute([round((float)$newPrice, 2), (int)$orderId]);
    ps_audit('order', (int)$orderId, 'import_price_arbitrated', $actor, ['from' => (float)$order['contract_amount'], 'to' => round((float)$newPrice, 2), 'shop_flow_price' => $flowPrice, 'basis' => '店铺流水价与表格价一致'] + $context);
    return true;
}

/**
 * 重新上传覆盖：同号订单再次上传时，表格里填了、且与系统不同的“描述性”基础字段（店铺、订单日期、付款昵称）以本次表格为准更新。
 * 只改未审核 / 未锁定的订单；已核算月份（refund_settled_through 及以前）的日期不改；售价、成本、商标件数另有规则，不在这里改。
 * 返回实际更新了哪些字段 [字段 => [旧, 新]]。
 */
function poj_overwrite_basics($orderId, array $row, $actor)
{
    $pdo = db();
    $q = $pdo->prepare('SELECT o.shop,o.order_date,o.customer_name,o.settlement_status,s.payment_nickname FROM project_orders o LEFT JOIN project_order_sources s ON s.order_id=o.id WHERE o.id=? FOR UPDATE');
    $q->execute([(int)$orderId]);
    $o = $q->fetch();
    if (!$o || in_array($o['settlement_status'], ['approved', 'locked'], true)) return [];
    $changes = [];
    $shop = trim((string)($row['shop'] ?? ''));
    if ($shop !== '' && $shop !== (string)$o['shop']) $changes['shop'] = [(string)$o['shop'], $shop];
    $nick = trim((string)($row['payment_nickname'] ?? ''));
    if ($nick !== '' && $nick !== (string)$o['payment_nickname']) $changes['payment_nickname'] = [(string)$o['payment_nickname'], $nick];
    $date = (string)($row['order_date'] ?? '');
    if ($date !== '' && $date !== (string)$o['order_date']) {
        $settled = (string)ps_setting_get('refund_settled_through', '');
        $locked = $settled !== '' && (substr($date, 0, 7) <= $settled || substr((string)$o['order_date'], 0, 7) <= $settled);
        if (!$locked) $changes['order_date'] = [(string)$o['order_date'], $date];
    }
    if (!$changes) return [];
    if (isset($changes['shop']) || isset($changes['order_date']) || isset($changes['payment_nickname'])) {
        $newCustomer = isset($changes['payment_nickname']) && ((string)$o['customer_name'] === '' || (string)$o['customer_name'] === (string)$o['payment_nickname']) ? $changes['payment_nickname'][1] : (string)$o['customer_name'];
        $pdo->prepare('UPDATE project_orders SET shop=?,order_date=?,customer_name=?,row_version=row_version+1 WHERE id=?')
            ->execute([$changes['shop'][1] ?? (string)$o['shop'], $changes['order_date'][1] ?? $o['order_date'], $newCustomer, (int)$orderId]);
    }
    if (isset($changes['payment_nickname'])) {
        $pdo->prepare("INSERT INTO project_order_sources (order_id,payment_nickname,nickname_source) VALUES (?,?,'manual') ON DUPLICATE KEY UPDATE payment_nickname=VALUES(payment_nickname),nickname_source='manual'")
            ->execute([(int)$orderId, $changes['payment_nickname'][1]]);
    }
    ps_audit('order', (int)$orderId, 'import_overwrite_basics', $actor, ['line' => $row['line'] ?? null, 'changes' => $changes]);
    return $changes;
}
