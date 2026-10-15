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
