<?php
require_once __DIR__ . '/ProjectBusiness.php';

/** 部门代录只开放给网站售后部的对应业务账号，以及财务。 */
function ps_department_import_allowed($actor, $business)
{
    $business = ps_business_normalize($business);
    if (!in_array($business, ['网站续费', '网站修改'], true)) return false;
    if (($actor['role'] ?? '') === 'finance') return true;
    if (empty($actor['employee_id']) || !in_array($business, ps_actor_businesses($actor), true)) return false;
    $q = db()->prepare('SELECT department FROM employees WHERE id=?');
    $q->execute([(int)$actor['employee_id']]);
    return $q->fetchColumn() === '网站售后部';
}

function ps_department_import_people($actor, $business, array $ids)
{
    if (!ps_department_import_allowed($actor, $business)) throw new RuntimeException('当前账户没有网站售后部门订单代录权限');
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (count($ids) > 30) throw new RuntimeException('一次最多选择 30 名参与人');
    if (!$ids) return [];
    $q = db()->prepare('SELECT id,name FROM employees WHERE id=? AND department=?');
    $people = [];
    foreach ($ids as $id) {
        $q->execute([$id, '网站售后部']);
        $person = $q->fetch();
        if (!$person) throw new RuntimeException('部门参与人不属于网站售后部，请重新选择');
        $people[$id] = ['id' => $id, 'name' => $person['name'], 'role' => '售后'];
    }
    return $people;
}

function ps_department_import_is_order($orderId)
{
    $q = db()->prepare('SELECT 1 FROM project_department_orders WHERE order_id=? AND department=? LIMIT 1');
    $q->execute([(int)$orderId, '网站售后部']);
    return (bool)$q->fetchColumn();
}

function ps_department_import_record($orderId, $actor)
{
    db()->prepare('INSERT INTO project_department_orders (order_id,department,created_by_type,created_by_id) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE order_id=VALUES(order_id)')
        ->execute([(int)$orderId, '网站售后部', $actor['type'], (int)$actor['id']]);
    if (!empty($actor['employee_id'])) {
        db()->prepare('INSERT IGNORE INTO project_department_uploaders (order_id,employee_id) VALUES (?,?)')
            ->execute([(int)$orderId, (int)$actor['employee_id']]);
    }
}

function ps_department_import_uploader_access($orderId, $employeeId)
{
    $q = db()->prepare('SELECT 1 FROM project_department_uploaders WHERE order_id=? AND employee_id=? LIMIT 1');
    $q->execute([(int)$orderId, (int)$employeeId]);
    return (bool)$q->fetchColumn();
}

/** 页面只展示规则中心当月生效值；实际项目报酬仍由月结规则实时计算。 */
function ps_department_renewal_rates($month)
{
    require_once __DIR__ . '/ProjectMonthly.php';
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$month)) throw new RuntimeException('规则月份无效');
    $rates = [];
    foreach (ps_monthly_rules_for($month) as $rule) {
        if ($rule['rule_type'] !== 'dept_share' || empty($rule['employee_id'])) continue;
        $businesses = ps_monthly_scope_businesses($rule);
        if ($businesses === null || !in_array('网站续费', $businesses, true)) continue;
        $id = (int)$rule['employee_id'];
        $rates[$id] = ($rates[$id] ?? 0) + (float)($rule['params']['rate'] ?? 0) * (float)($rule['params']['share'] ?? 1);
    }
    return $rates;
}
