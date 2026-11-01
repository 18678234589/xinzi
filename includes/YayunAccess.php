<?php
/**
 * 服务器管理权限。
 * 查看 / 日常管理（列表、详情、开关机、VNC、监控）：有域名管理权限的人（网站 / 小程序的客服、技术、售后等）+ 可开通名单里的人。
 * 开通新服务器、续费、重装、重置密码、看登录密码、停用：超级管理员 + 「可开通名单」里的员工（系统设置 yayun_provisioners，由超级管理员在页面维护）。
 */

function ya_provisioner_ids()
{
    $v = function_exists('ps_setting_get') ? ps_setting_get('yayun_provisioners', []) : [];
    $ids = [];
    foreach ((array)($v['employees'] ?? []) as $id) if ((int)$id > 0) $ids[(int)$id] = true;
    return array_keys($ids);
}
function ya_is_super_admin($actor)
{
    return $actor && ($actor['type'] ?? '') === 'admin' && pv_admin_username($actor['id']) === 'admin';
}
function ya_can_provision($actor)
{
    if (!$actor) return false;
    if (ya_is_super_admin($actor)) return true;
    return ($actor['type'] ?? '') === 'employee' && in_array((int)($actor['employee_id'] ?? 0), ya_provisioner_ids(), true);
}
function ya_can_view($actor)
{
    return $actor && (bd_can_access($actor) || ya_can_provision($actor));
}
/** 保存可开通名单（仅超级管理员）。 */
function ya_save_provisioners($pdo, array $employeeIds, $actor)
{
    if (!ya_is_super_admin($actor)) throw new RuntimeException('仅超级管理员可以设置可开通名单');
    $ids = [];
    foreach ($employeeIds as $id) {
        $id = (int)$id;
        if ($id <= 0) continue;
        $q = $pdo->prepare('SELECT 1 FROM employees WHERE id=?');
        $q->execute([$id]);
        if (!$q->fetchColumn()) throw new InvalidArgumentException('名单里有不存在的员工');
        $ids[$id] = true;
    }
    ps_setting_set('yayun_provisioners', ['employees' => array_keys($ids)], (int)$actor['id']);
    return array_keys($ids);
}
function ya_provisioner_rows($pdo)
{
    $ids = ya_provisioner_ids();
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $q = $pdo->prepare("SELECT id,name,department FROM employees WHERE id IN ($in) ORDER BY id");
    $q->execute($ids);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}
