<?php
function ps_role_keys($role)
{
    $keys = [];
    foreach (preg_split('/[\/、,，]+/u', (string)$role) as $part) {
        $part = trim(preg_replace('/[（(][^）)]*[）)]/u', '', $part));
        if ($part !== '') $keys[] = $part;
    }
    return $keys;
}

function ps_software_testing_role($role)
{
    return ps_role_keys($role) === ['软件测试'];
}

function ps_group_weight_valid(array $group)
{
    $poolPeople = array_filter($group['people'], function ($p) { return !ps_software_testing_role($p['role_name'] ?? ''); });
    return !$poolPeople || abs((float)$group['weight'] - 1.0) <= 0.000001;
}

/** 技术/客服可自行添加软件测试员的业务（可选）；含旧名“网站定制”。 */
function ps_software_testing_pick_business($business)
{
    return in_array((string)$business, ['AI网站定制', '网站定制', '小程序开发'], true);
}

/** 该员工是否开通了「可自行加入这两类业务订单」的软件测试角色。 */
function ps_software_tester_can_join($employeeId)
{
    $q = db()->prepare("SELECT 1 FROM project_employee_roles WHERE employee_id=? AND commission_group='technical' AND role_name='软件测试' AND business_name IN ('AI网站定制','小程序开发','*') LIMIT 1");
    $q->execute([(int)$employeeId]);
    return (bool)$q->fetchColumn();
}
