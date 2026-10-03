<?php
/** 规则阅读与管理层事项权限分离；公开规则不会授予评审、豁免或台账操作权。 */
function pgr_access($actor, $member, $super)
{
    $authenticated = is_array($actor) && in_array($actor['type'] ?? '',['employee','admin'],true) && (int)($actor['id'] ?? 0) > 0;
    $realMember = $authenticated && $actor['type'] === 'employee' && is_array($member)
        && (int)($member['employee_id'] ?? 0) > 0
        && (int)$member['employee_id'] === (int)($actor['employee_id'] ?? 0)
        && in_array($member['governance_role'] ?? '',['chair','committee'],true);
    return [
        'read'=>$authenticated,
        'drafts'=>$realMember || ($authenticated && $actor['type'] === 'admin' && $super),
        'edit'=>$realMember && $member['governance_role'] === 'committee',
        'member'=>$realMember,
    ];
}

function pgr_rule_visible(array $rule, array $access)
{
    return !empty($access['read']) && (($rule['rule_state'] ?? '') === 'confirmed' || !empty($access['drafts']));
}
