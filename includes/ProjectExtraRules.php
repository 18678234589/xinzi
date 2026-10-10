<?php
/**
 * 补贴规则（叠加规则）：把“每单补贴”从提成规则里拆出来单独配置。
 * 同一参与人在一张订单上只会匹配一条提成规则（业务 > 岗位 > 订单类型最具体者），补贴规则不参与这个竞争，
 * 而是在提成规则算出结果后，把匹配上的补贴规则的“每单补助”加进该参与人的补助里（规则中心勾选“补贴规则”创建，比例须为 0）。
 * 例：备案-提成 = 提成规则（全部类型，(售价−成本−服务费)×20%）+ 补贴规则（订单类型“拍链接”，每单 0.5 元）。
 * 依赖 project_commission_rules.is_extra 列（migrations/20261010_rule_is_extra.sql）；列还没加上时一律视为没有补贴规则，行为同以前。
 */

/** is_extra 列是否已存在（同一请求内缓存）。 */
function pxr_supported()
{
    static $ok = null;
    if ($ok === null) {
        try { $ok = (bool)db()->query("SHOW COLUMNS FROM project_commission_rules LIKE 'is_extra'")->fetch(); }
        catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

/** 补上 is_extra 列（规则中心 / 迁移脚本调用；不要在核算过程中调用，DDL 会提交当前事务）。 */
function pxr_ensure_column()
{
    if (pxr_supported()) return;
    db()->exec('ALTER TABLE project_commission_rules ADD COLUMN is_extra TINYINT(1) NOT NULL DEFAULT 0');
}

/** 某参与人在这张订单上适用的全部补贴规则（同岗位同类型取最新一版）。 */
function pxr_for_person($group, $projectType, $orderDate, $role = '', $orderKind = '')
{
    if (!pxr_supported()) return [];
    static $cache = [];
    if ($projectType === '网站定制') $projectType = 'AI网站定制';
    $key = $group . '|' . $projectType . '|' . $orderDate;
    if (!isset($cache[$key])) {
        $q = db()->prepare("SELECT * FROM project_commission_rules WHERE is_extra=1 AND commission_group=? AND project_type IN (?, '*') AND effective_from<=? AND is_active=1 ORDER BY effective_from DESC, id DESC");
        $q->execute([$group, $projectType, $orderDate]);
        $cache[$key] = $q->fetchAll();
    }
    $kind = ps_role_rule_order_kind($projectType, $group, $role, $orderKind);
    $roles = ps_role_keys($role);
    $out = []; $seen = [];
    foreach ($cache[$key] as $rule) {
        $ruleRole = (string)$rule['role_name']; $ruleKind = (string)$rule['order_kind'];
        if (!in_array($ruleRole, ['*', ''], true) && !in_array($ruleRole, $roles, true)) continue;
        if (!in_array($ruleKind, ['*', ''], true) && $ruleKind !== (string)$kind) continue;
        $k = $rule['project_type'] . '|' . $ruleRole . '|' . $ruleKind;
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = $rule;
    }
    return $out;
}
