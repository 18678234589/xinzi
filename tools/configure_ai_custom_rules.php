<?php
/**
 * AI网站定制 按订单类型的分成规则（2026-10-01 起生效，只影响新订单；已审核订单快照不变）：
 *  - AI定制（前后端一个技术做）技术：(售价 − 实际成本 − 6% 服务费) × 15% ＋ 每单 20 元；写“前端/后端”的一个人只按这一条算（见 ps_rules_for_person）。
 *  - AI定制 客服：在现有定制客服规则基础上再加每单 20 元新建站奖励（补贴规则，与现有规则叠加）。
 *  - 华梦定制 客服：现有定制客服规则不变，只把成本按售价 80% 记录（成本下限 80%）。
 *  - 博山定制（传统网站定制）：不再分前端 / 后端，两个岗位统一 15%，无补助。
 * 用法：php tools/configure_ai_custom_rules.php          # 预览，不写库
 *       php tools/configure_ai_custom_rules.php --apply  # 写入（事务，可重复执行，已有的规则不会重复添加）
 */
require_once __DIR__ . '/../includes/ProjectMonthly.php';

const ACR_EFFECTIVE = '2026-10-01';

/** 返回要创建的规则（不含 id / created_at）；$base 取自库里现行规则，缺基准规则时抛错。 */
function acr_definitions()
{
    $pdo = db();
    $one = function ($sql, $params) use ($pdo) { $q = $pdo->prepare($sql); $q->execute($params); $r = $q->fetch(PDO::FETCH_ASSOC); return $r ?: null; };
    $front = $one("SELECT * FROM project_commission_rules WHERE commission_group='technical' AND project_type='AI网站定制' AND role_name='前端' AND order_kind='*' AND is_extra=0 AND is_active=1 AND effective_from<=? ORDER BY effective_from DESC,id DESC LIMIT 1", [ACR_EFFECTIVE]);
    $cs = $one("SELECT * FROM project_commission_rules WHERE commission_group='customer_service' AND project_type='AI网站定制' AND role_name='*' AND order_kind='*' AND is_extra=0 AND is_active=1 AND effective_from<=? ORDER BY effective_from DESC,id DESC LIMIT 1", [ACR_EFFECTIVE]);
    if (!$front || !$cs) throw new RuntimeException('缺少现行的 AI网站定制 前端 / 客服 基准规则，未做任何修改');
    $strip = function ($r) { unset($r['id'], $r['created_at']); return $r; };
    $feeRate = (float)$front['service_fee_rate']; // 与普通网站定制相同（现行前端规则的服务费率）
    $defs = [];
    $t = $strip($front);
    $defs[] = array_merge($t, ['order_kind' => 'AI定制', 'role_name' => '前端', 'rate' => 0.15, 'service_fee_rate' => $feeRate, 'per_order_subsidy' => 20, 'min_cost_rate' => null, 'effective_from' => ACR_EFFECTIVE,
        'note' => 'AI定制（前后端一个技术做）：(售价 − 实际成本 − 服务费) × 15% + 每单 20 元；2026-10-01 起']);
    // 博山定制（传统网站定制）：不再分前端 / 后端，两个岗位统一 15%（各自按全单利润算，域名 / SSL 仍按权重分摊）
    foreach (['前端', '后端'] as $role) $defs[] = array_merge($t, ['order_kind' => '博山定制', 'role_name' => $role, 'rate' => 0.15, 'service_fee_rate' => $feeRate, 'per_order_subsidy' => 0, 'min_cost_rate' => null, 'effective_from' => ACR_EFFECTIVE,
        'note' => '博山定制（传统网站定制）：不分前端 / 后端，统一 15%：(售价 − 成本 − 服务费) × 15%；2026-10-01 起']);
    $e = $strip($cs);
    $defs[] = array_merge($e, ['order_kind' => 'AI定制', 'is_extra' => 1, 'rate' => 0, 'per_order_subsidy' => 20, 'min_cost_rate' => null, 'effective_from' => ACR_EFFECTIVE,
        'note' => 'AI定制 新建站奖励：每单 20 元（与现有定制客服规则叠加）；2026-10-01 起']);
    $defs[] = array_merge($e, ['order_kind' => '华梦定制', 'is_extra' => 0, 'min_cost_rate' => 0.8, 'effective_from' => ACR_EFFECTIVE,
        'note' => '华梦定制客服：成本按售价 80% 记录，其余同现有定制客服规则；2026-10-01 起']);
    return $defs;
}

/** 已存在同键规则（组 / 业务 / 岗位 / 类型 / 生效日 / 是否补贴）就不重复添加。返回 [待新增, 已存在]。 */
function acr_plan(array $defs)
{
    $pdo = db(); $new = []; $exist = [];
    $q = $pdo->prepare('SELECT id FROM project_commission_rules WHERE commission_group=? AND project_type=? AND role_name=? AND order_kind=? AND effective_from=? AND is_extra=? AND is_active=1 LIMIT 1');
    foreach ($defs as $d) {
        $q->execute([$d['commission_group'], $d['project_type'], $d['role_name'], $d['order_kind'], $d['effective_from'], (int)($d['is_extra'] ?? 0)]);
        $id = $q->fetchColumn();
        if ($id) $exist[] = [$d, (int)$id]; else $new[] = $d;
    }
    return [$new, $exist];
}

function acr_apply(array $new)
{
    $pdo = db(); $pdo->beginTransaction();
    try {
        $ids = [];
        foreach ($new as $d) {
            $cols = array_keys($d);
            $pdo->prepare('INSERT INTO project_commission_rules (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($d));
            $id = (int)$pdo->lastInsertId(); $ids[] = $id;
            ps_audit('commission_rule', $id, 'configure_ai_custom', ['type' => 'admin', 'id' => (int)$pdo->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetchColumn(), 'role' => 'finance', 'employee_id' => null], ['order_kind' => $d['order_kind'], 'role' => $d['role_name'], 'group' => $d['commission_group'], 'effective_from' => $d['effective_from']]);
        }
        $pdo->commit();
        return $ids;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    [$new, $exist] = acr_plan(acr_definitions());
    foreach ($exist as [$d, $id]) echo "已存在，跳过：#$id {$d['commission_group']} {$d['role_name']} {$d['order_kind']}" . (!empty($d['is_extra']) ? '（补贴）' : '') . "\n";
    foreach ($new as $d) echo "将新增：{$d['commission_group']} / {$d['role_name']} / {$d['order_kind']}" . (!empty($d['is_extra']) ? '（补贴规则）' : '') . " 比例 {$d['rate']} 服务费 {$d['service_fee_rate']} 每单 {$d['per_order_subsidy']} 成本下限 " . ($d['min_cost_rate'] ?? '无') . "\n";
    if (!in_array('--apply', $argv, true)) { echo "\n这是预览，没有写库。确认无误后加 --apply 写入。\n"; exit(0); }
    $ids = acr_apply($new);
    echo "\n已写入 " . count($ids) . " 条：" . implode(',', $ids) . "\n";
}
