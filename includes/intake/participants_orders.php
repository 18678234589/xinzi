<?php

/** 财务配置的默认岗位（如外包前端、售后、定制客服）优先于表格/表单里的通用岗位名，以匹配对应分成规则。 */
function ps_employee_default_role($employeeId, $business, $group)
{
    $q = db()->prepare('SELECT role_name FROM project_employee_roles WHERE employee_id=? AND business_name=? AND commission_group=?');
    $q->execute([(int)$employeeId, ps_business_normalize($business), $group]);
    return $q->fetchColumn() ?: null;
}

function ps_intake_participants($orderId, $groups, $business = null)
{
    $insert = db()->prepare('INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,?,?,?)');
    $check = db()->prepare('SELECT 1 FROM employees WHERE id=?');
    foreach ($groups as $group => $people) {
        if (!$people) continue;
        $people = array_values($people);
        $count = count($people);
        $baseWeight = intdiv(1000000, $count);
        foreach ($people as $index => $person) {
            $employeeId = (int)$person['id'];
            $check->execute([$employeeId]);
            if (!$check->fetchColumn()) throw new RuntimeException('参与人不存在，请重新选择');
            $weight = ($index === $count - 1 ? 1000000 - $baseWeight * ($count - 1) : $baseWeight) / 1000000;
            $rawRole = trim((string)($person['role'] ?? ''));
            $isGeneric = ($rawRole === '' || $rawRole === '技术' || $rawRole === '客服');
            $defaultRole = $business !== null ? ps_employee_default_role($employeeId, $business, $group) : null;
            $role = !$isGeneric ? $rawRole : ($defaultRole ?? $rawRole);
            $insert->execute([(int)$orderId, $employeeId, $group, $role, $weight]);
        }
    }
}

function ps_intake_domain_suggestion($resourceNote, $templates)
{
    $resourceNote = mb_strtolower(trim((string)$resourceNote));
    if (!preg_match('/\.(com|cn|net|org|top|xyz)(?:\b|\/|：|:|，|,|\s|$)/iu', $resourceNote, $match)) return null;
    $suffix = '.' . strtolower($match[1]);
    $matches = [];
    foreach ($templates as $template) {
        $label = mb_strtolower($template['name'] . ' ' . $template['specification']);
        if (strpos($label, $suffix) === false) continue;
        if ($template['cost_kind'] !== 'annual') continue;
        if (!preg_match('/(?:1\s*年|一年|12\s*个月|12\s*月)/u', $label)) continue;
        $matches[] = $template;
    }
    return count($matches) === 1 ? $matches[0] : null;
}

/** 订单号去掉“订单编号：”标签和尾部备注（“… 和某某一起”“…科恒中信”），保留“科中2026091901”这类带前缀的内部号。 */
function ps_order_no_canonical($raw)
{
    $no = trim((string)$raw);
    $no = trim(preg_replace('/^(?:订单编号|订单号|订单|单号|编号)\s*[:：]\s*/u', '', $no));
    if (preg_match('/^[\p{Han}]{0,4}[A-Za-z0-9_-]{6,}/u', $no, $m)) return $m[0];
    return $no;
}

/** 与库里已有订单同号（忽略标签和尾部备注）时沿用库里的写法，否则用规范写法；保证同一订单不会因写法不同重复建单。 */
function ps_order_no_resolve($raw)
{
    $canonical = ps_order_no_canonical($raw);
    if ($canonical === '' || mb_strlen($canonical) < 8) return trim((string)$raw);
    $q = db()->prepare('SELECT order_no FROM project_orders WHERE order_no=? LIMIT 1');
    $q->execute([$canonical]);
    if ($exact = $q->fetchColumn()) return $exact;
    // 只用前缀匹配走 order_no 索引（%…% 会全表扫描）：库里的旧写法是“标签：号码”或“号码 备注”。
    $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $canonical);
    $patterns = [$escaped . '%'];
    foreach (['订单编号', '订单号', '订单', '单号', '编号'] as $label) foreach (['：', ':'] as $colon) foreach (['', ' '] as $space) $patterns[] = $label . $colon . $space
    . $escaped . '%';
    $like = db()->prepare('SELECT order_no FROM project_orders WHERE ' . implode(' OR ', array_fill(0, count($patterns), 'order_no LIKE ?')) . ' ORDER BY id LIMIT 20');
    $like->execute($patterns);
    foreach ($like->fetchAll(PDO::FETCH_COLUMN) as $stored) if (ps_order_no_canonical($stored) === $canonical) return $stored;
    return $canonical;
}

/** 订单某组（技术 / 客服）是否已有参与人。 */
function ps_import_group_taken($orderId, $group)
{
    $q = db()->prepare('SELECT 1 FROM project_participants WHERE order_id=? AND commission_group=? LIMIT 1');
    $q->execute([(int)$orderId, $group]);
    return (bool)$q->fetchColumn();
}

/**
 * 商标订单的资料专员、提交专员分别上传同一单：技术组按岗位区分，同岗位已有人时不再追加。
 * 返回可加入的岗位名；本人已在单上或同岗位已有人时返回 null。
 */
function ps_trademark_technical_role_open($orderId, $employeeId, $fallbackRole)
{
    $role = ps_employee_default_role($employeeId, '商标', 'technical') ?? (string)$fallbackRole;
    $q = db()->prepare("SELECT employee_id,role_name FROM project_participants WHERE order_id=? AND commission_group='technical'");
    $q->execute([(int)$orderId]);
    foreach ($q->fetchAll() as $p) if ((int)$p['employee_id'] === (int)$employeeId || $p['role_name'] === $role) return null;
    return $role;
}

/** 追加一名商标技术参与人，并把技术组权重重新均分（审核要求组内合计 100%；商标技术按件计，权重不影响金额）。调用方负责事务。 */
function ps_trademark_add_technical($orderId, $employeeId, $role)
{
    db()->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'technical',?,0)")->execute([(int)$orderId, (int)$employeeId
    , $role]);
    $q = db()->prepare("SELECT id FROM project_participants WHERE order_id=? AND commission_group='technical' ORDER BY id");
    $q->execute([(int)$orderId]);
    $ids = $q->fetchAll(PDO::FETCH_COLUMN);
    $base = intdiv(1000000, count($ids));
    $update = db()->prepare('UPDATE project_participants SET group_weight=? WHERE id=?');
    foreach ($ids as $i => $id) $update->execute([($i === count($ids) - 1 ? 1000000 - $base * (count($ids) - 1) : $base) / 1000000, (int)$id]);
}

/**
 * 商标部原表整理：日期与店铺互换（“美呀美 | 46236”）、网报加急空一格使件数落到“设计”列、网报加急列写“8.10发货”、
 * 日期带“晚 / 上午”等字样时按内容归位；合计 / 底薪 / 提成等汇总行返回 null，不当作订单。
 */
function ps_trademark_fix_row($row, $map)
{
    $get = function ($key) use (&$row, $map) { return isset($map[$key]) ? trim((string)($row[$map[$key]] ?? '')) : ''; };
    if (isset($map['order_date']) && preg_match('/^(\d{4}[.\/-]\d{1,2}[.\/-]\d{1,2})\s*(?:早上|上午|中午|下午|晚上|早|晚)$/u', $get('order_date'), $m)) $row[$map['order_date'
    ]] = $m[1];
    $orderNo = $get('order_no');
    // 订单号可带前缀（如“致2026081303”）：含 6 位以上连续数字即视为订单行
    if (!preg_match('/^[A-Za-z0-9_-]{8,}$/', $orderNo) && !preg_match('/\d{6,}/', $orderNo) && $get('payment_reference') === '') {
        if ($orderNo !== '' && !ps_import_date($get('order_date')) && !ps_import_date($get('shop'))) return null;
        if (preg_match('/合计|总计|底薪|全勤|提成|单价|出勤|请假/u', implode(' ', array_map('strval', $row)))) return null;
    }
    if (isset($map['order_date'], $map['shop']) && !ps_import_date($get('order_date')) && $get('shop') !== '' && ps_import_date($get('shop'))) {
        [$row[$map['order_date']], $row[$map['shop']]] = [$row[$map['shop']], $row[$map['order_date']]];
    }
    if (isset($map['detail:trademark_count'])) {
        $count = '';
        $texts = [];
        $shipNotes = [];
        foreach (['detail:service_type', 'detail:trademark_count', 'trademark_extra'] as $key) {
            $value = $get($key);
            if ($value === '') continue;
            if (is_numeric($value)) { if ($count === '' && $key !== 'detail:service_type') $count = $value; continue; }
            if (preg_match('/^\d{1,2}[.\/月]\d{1,2}日?\s*(?:已)?发货$/u', $value)) $shipNotes[] = $value; else $texts[] = $value;
        }
        $row[$map['detail:trademark_count']] = $count;
        if (isset($map['detail:service_type'])) $row[$map['detail:service_type']] = implode(' ', $texts ?: $shipNotes);
        if (isset($map['trademark_extra'])) $row[$map['trademark_extra']] = '';
    }
    return $row;
}
