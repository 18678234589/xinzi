<?php
/**
 * 部门主管（project_dept_heads 登记）的订单可见范围：能看到参与人属于自己所管部门的订单。
 * 订单列表、列表里各参与人的预计分成、打开结算单、列表快捷修改共用；删除订单、审核等仍按各自原有规则。
 */

/** 当前账号担任主管的部门名列表；不是主管返回 []。 */
function pdh_departments($actor)
{
    static $cache = [];
    $emp = (int)($actor['employee_id'] ?? 0);
    if ($emp <= 0 || ($actor['role'] ?? '') === 'finance') return [];
    if (!isset($cache[$emp])) {
        try {
            $q = db()->prepare('SELECT department FROM project_dept_heads WHERE employee_id=?');
            $q->execute([$emp]);
            $cache[$emp] = array_values(array_unique(array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN))));
        } catch (Throwable $e) { $cache[$emp] = []; } // 主管表还没建
    }
    return $cache[$emp];
}

/** 订单列表用的 SQL 条件（别名 o = project_orders）：订单上有人属于主管所管部门。返回 [sql, 参数]；不是主管返回 ['', []]。 */
function pdh_order_condition($actor)
{
    $deps = pdh_departments($actor);
    if (!$deps) return ['', []];
    return ['EXISTS (SELECT 1 FROM project_participants hp JOIN employees he ON he.id=hp.employee_id WHERE hp.order_id=o.id AND he.department IN (' . implode(',', array_fill(0, count($deps), '?')) . '))', $deps];
}

/** 这张订单上有没有人属于主管所管部门。 */
function pdh_can_view_order($orderId, $actor)
{
    $deps = pdh_departments($actor);
    if (!$deps) return false;
    $q = db()->prepare('SELECT 1 FROM project_participants hp JOIN employees he ON he.id=hp.employee_id WHERE hp.order_id=? AND he.department IN (' . implode(',', array_fill(0, count($deps), '?')) . ') LIMIT 1');
    $q->execute(array_merge([(int)$orderId], $deps));
    return (bool)$q->fetchColumn();
}

/** 这位参与人（ps_participants 的一行）是不是主管所管部门的成员。 */
function pdh_manages_person($actor, array $participant)
{
    return in_array((string)($participant['department'] ?? ''), pdh_departments($actor), true);
}
