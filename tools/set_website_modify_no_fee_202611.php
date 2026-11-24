<?php
/**
 * 网站修改（网站售后部：张宁 / 戴倩 / 刘晓）自己接单的客服提成不再扣 3% 服务费，2026-11-01 起的订单生效：
 * 新增一条版本规则（(收入 − 成本) × 20%，服务费 0），2026-11-01 之前的订单和已审核快照仍按原规则（不改历史）。
 * 用法：php tools/set_website_modify_no_fee_202611.php           # 预览
 *       php tools/set_website_modify_no_fee_202611.php --apply   # 写入（可重复执行，已有就跳过）
 */
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectExtraRules.php';

const SWM_FROM = '2026-11-01';

function swm_apply($apply)
{
    pxr_ensure_column(); // is_extra 列（建列是 DDL，须在事务之外；生产已有）
    $pdo = db();
    $cur = $pdo->prepare("SELECT * FROM project_commission_rules WHERE commission_group='customer_service' AND project_type='网站修改' AND role_name='*' AND order_kind='*' AND is_extra=0 AND is_active=1 AND effective_from<? ORDER BY effective_from DESC,id DESC LIMIT 1");
    $cur->execute([SWM_FROM]);
    $base = $cur->fetch(PDO::FETCH_ASSOC);
    if (!$base) throw new RuntimeException('没有找到现行的 网站修改 客服规则，未做任何修改');
    $has = $pdo->prepare("SELECT id FROM project_commission_rules WHERE commission_group='customer_service' AND project_type='网站修改' AND role_name='*' AND order_kind='*' AND is_extra=0 AND is_active=1 AND effective_from=? LIMIT 1");
    $has->execute([SWM_FROM]);
    if ($id = $has->fetchColumn()) return ['exists' => (int)$id, 'base' => $base];
    $new = $base;
    unset($new['id'], $new['created_at']);
    $new['effective_from'] = SWM_FROM;
    $new['service_fee_rate'] = 0;
    $new['note'] = '自己接单：(收入 − 成本) × 20%，不扣服务费；2026-11-01 起（此前订单按原规则含 3% 服务费）';
    if (!$apply) return ['would_add' => $new, 'base' => $base];
    $keys = array_keys($new);
    $pdo->prepare('INSERT INTO project_commission_rules (`' . implode('`,`', $keys) . '`) VALUES (' . implode(',', array_fill(0, count($keys), '?')) . ')')->execute(array_values($new));
    $id = (int)$pdo->lastInsertId();
    ps_audit('commission_rule', $id, 'website_modify_no_fee', ['type' => 'admin', 'id' => (int)$pdo->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetchColumn(), 'role' => 'finance', 'employee_id' => null], ['previous_rule_id' => (int)$base['id'], 'effective_from' => SWM_FROM, 'service_fee_rate' => 0]);
    return ['added' => $id, 'base' => $base];
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $r = swm_apply(in_array('--apply', $argv, true));
    if (isset($r['exists'])) echo "已存在 #{$r['exists']}，跳过\n";
    elseif (isset($r['added'])) echo "已新增规则 #{$r['added']}（基于 #{$r['base']['id']}），" . SWM_FROM . " 起服务费 0\n";
    else echo "将新增：网站修改 客服 比例 {$r['would_add']['rate']} 服务费 0（现行 #{$r['base']['id']} 服务费 {$r['base']['service_fee_rate']}），" . SWM_FROM . " 起\n这是预览，加 --apply 写入。\n";
}
