<?php

/** 登录身份与逐单分成组分开；主管授权仍由 project_dept_heads 管理。 */
function ps_account_roles()
{
    return ['technical' => '技术', 'customer_service' => '客服', 'management' => '管理'];
}

/** 管理账户沿用客服填报流程，不能把 management 写进两组分成枚举。 */
function ps_account_order_role($role)
{
    return $role === 'management' ? 'customer_service' : $role;
}

function ps_management_profile($userId)
{
    try {
        $q = db()->prepare('SELECT scope,title,fixed_pay_only FROM project_account_management WHERE user_id=?');
        $q->execute([(int)$userId]);
        return $q->fetch() ?: ['scope'=>'assigned','title'=>'管理','fixed_pay_only'=>0];
    } catch (PDOException $e) {
        if ($e->getCode() !== '42S02') throw $e;
        return ['scope'=>'assigned','title'=>'管理','fixed_pay_only'=>0];
    }
}

function ps_is_management($actor)
{
    return ($actor['type'] ?? 'employee') === 'employee' && ($actor['account_role'] ?? $actor['role'] ?? '') === 'management';
}

function ps_management_company($actor)
{
    return ps_is_management($actor) && ($actor['management_scope'] ?? '') === 'company';
}

function ps_management_can_business($actor, $business)
{
    if (!ps_is_management($actor)) return false;
    if (ps_management_company($actor)) return true;
    $business = ps_business_normalize($business);
    return in_array($business, ps_actor_businesses($actor), true);
}

/** 可插入订单列表的受控范围，别名只允许 SQL 标识符。 */
function ps_management_order_condition($actor, $alias = 'o')
{
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $alias)) throw new InvalidArgumentException('订单表别名无效');
    if (!ps_is_management($actor)) return '1=0';
    if (ps_management_company($actor)) return '1=1';
    $allowed = ps_actor_businesses($actor);
    // 历史业务名称同样按规范化后的业务授权。
    foreach (db()->query('SELECT DISTINCT project_type FROM project_orders')->fetchAll(PDO::FETCH_COLUMN) as $name) {
        if (in_array(ps_business_normalize($name), $allowed, true)) $allowed[] = $name;
    }
    return $allowed ? $alias . '.project_type IN (' . implode(',', array_map(function ($name) { return db()->quote($name); }, array_unique($allowed))) . ')' : '1=0';
}

function ps_management_company_employee_ids()
{
    try {
        return array_map('intval', db()->query("SELECT u.employee_id FROM project_account_management m JOIN project_users u ON u.id=m.user_id WHERE m.scope='company' AND u.role='management' AND u.is_active=1")->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) {
        if ($e->getCode() !== '42S02') throw $e;
        return [];
    }
}

/** 财务的账户设置事务中调用；普通角色不会取得全公司管理范围。 */
function ps_account_management_save($userId, $role, array $source)
{
    if ($role !== 'management') {
        db()->prepare('DELETE FROM project_account_management WHERE user_id=?')->execute([(int)$userId]);
        ps_management_fixed_pay_ids(true);
        return;
    }
    $old = ps_management_profile($userId);
    $scope = (string)($source['management_scope'] ?? $old['scope']);
    $title = trim((string)($source['management_title'] ?? $old['title']));
    $fixed = (int)($source['fixed_pay_only'] ?? $old['fixed_pay_only']) === 1 ? 1 : 0;
    if (!in_array($scope, ['assigned','company'], true) || mb_strlen($title) > 80) throw new RuntimeException('请选择有效管理范围，职务最多80字');
    db()->prepare('INSERT INTO project_account_management (user_id,scope,title,fixed_pay_only) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE scope=VALUES(scope),title=VALUES(title),fixed_pay_only=VALUES(fixed_pay_only)')->execute([(int)$userId,$scope,$title !== '' ? $title : '管理',$fixed]);
    ps_management_fixed_pay_ids(true);
}

function ps_management_fixed_pay_ids($reset = false)
{
    static $ids = null;
    if ($reset) { $ids = null; return []; }
    if ($ids === null) {
        try {
            $ids = array_map('intval',db()->query("SELECT u.employee_id FROM project_account_management m JOIN project_users u ON u.id=m.user_id WHERE m.fixed_pay_only=1 AND u.role='management'")->fetchAll(PDO::FETCH_COLUMN));
        } catch (PDOException $e) {
            if ($e->getCode() !== '42S02') throw $e;
            $ids = [];
        }
    }
    return $ids;
}
