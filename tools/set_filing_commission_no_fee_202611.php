<?php
/**
 * 备案-提成（房烁自己接单的备案提成表）：提成按 (收入 − 成本) × 20% 算，不再扣 3% 服务费；拍链接每单 +0.5、提成表每单 +1 的补贴规则不变。
 * 2026-10-01 起的订单生效：新增一条版本规则（服务费 0）；2026-10 起已审核订单的差额作为调整计入下一个未锁定月份（原快照不改）。
 * 2026-09 及以前的订单不动。
 * 用法：php tools/set_filing_commission_no_fee_202611.php                      # 预览
 *       php tools/set_filing_commission_no_fee_202611.php --apply-rule          # 只新增规则（草稿订单按新规则自动重算）
 *       php tools/set_filing_commission_no_fee_202611.php --post-adjustments    # 规则已生效后，把已审核订单的差额记为调整（可重复执行，不会重复扣 / 补）
 */
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectSettlement.php';
require_once __DIR__ . '/../includes/ProjectExtraRules.php';
require_once __DIR__ . '/../includes/ProjectOrderBusiness.php';

const SFC_FROM = '2026-10-01';
const SFC_REASON = '备案提成不扣3%服务费更正：(收入−成本)×20%';

function sfc_actor()
{
    return ['type' => 'admin', 'id' => (int)db()->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetchColumn(), 'role' => 'finance', 'employee_id' => null];
}

/** 新增 2026-10-01 起的 备案-提成 通用提成规则（服务费 0）；已有就返回已有的 id。 */
function sfc_apply_rule($apply)
{
    pxr_ensure_column();
    $pdo = db();
    $has = $pdo->prepare("SELECT id FROM project_commission_rules WHERE commission_group='customer_service' AND project_type='备案-提成' AND role_name='*' AND order_kind='*' AND is_extra=0 AND is_active=1 AND effective_from=? LIMIT 1");
    $has->execute([SFC_FROM]);
    if ($id = $has->fetchColumn()) return ['exists' => (int)$id];
    $cur = $pdo->prepare("SELECT * FROM project_commission_rules WHERE commission_group='customer_service' AND project_type='备案-提成' AND role_name='*' AND order_kind='*' AND is_extra=0 AND is_active=1 AND effective_from<? AND rate>0 ORDER BY effective_from DESC,id DESC LIMIT 1");
    $cur->execute([SFC_FROM]);
    $base = $cur->fetch(PDO::FETCH_ASSOC);
    if (!$base) throw new RuntimeException('没有找到现行的 备案-提成 提成规则，未做任何修改');
    $new = $base;
    unset($new['id'], $new['created_at']);
    $new['effective_from'] = SFC_FROM;
    $new['service_fee_rate'] = 0;
    $new['note'] = '备案-提成提成：(售价−成本)×20%，不扣服务费，全部订单类型；2026-10-01 起（此前订单按原规则含 3% 服务费）';
    if (!$apply) return ['would_add' => $new, 'base' => $base];
    $keys = array_keys($new);
    $pdo->prepare('INSERT INTO project_commission_rules (`' . implode('`,`', $keys) . '`) VALUES (' . implode(',', array_fill(0, count($keys), '?')) . ')')->execute(array_values($new));
    $id = (int)$pdo->lastInsertId();
    ps_audit('commission_rule', $id, 'filing_commission_no_fee', sfc_actor(), ['previous_rule_id' => (int)$base['id'], 'effective_from' => SFC_FROM, 'service_fee_rate' => 0]);
    return ['added' => $id, 'base' => $base];
}

/** 已审核的 2026-10-01 起 备案-提成 订单：按当前规则重算，与“快照 + 已记调整”的差额。返回 [订单行, 差额明细]。 */
function sfc_plan()
{
    $pdo = db(); $out = [];
    $orders = $pdo->prepare("SELECT o.* FROM project_orders o WHERE o.project_type='备案-提成' AND o.order_date>=? AND o.settlement_status IN ('approved','locked') AND EXISTS (SELECT 1 FROM project_commission_snapshots c WHERE c.order_id=o.id) ORDER BY o.id");
    $orders->execute([SFC_FROM]);
    foreach ($orders->fetchAll() as $o) {
        $summary = ps_summary($o, ps_costs((int)$o['id']), ps_participants((int)$o['id']));
        $old = pob_old_amounts((int)$o['id']);
        foreach ($summary['groups'] as $group => $data) {
            if (!$data['people'] || $data['missing_rule'] || abs($data['weight'] - 1.0) > 0.000001) continue;
            $shares = ps_group_share_cents($data['people']);
            foreach ($data['people'] as $i => $p) {
                $key = $group . ':' . $p['employee_id'];
                $newCents = $summary['income'] <= 0 ? 0 : $shares[$i] + (int)round($p['calc']['subsidy'] * 100);
                $delta = $newCents - (int)round(($old[$key] ?? 0) * 100);
                if ($delta) $out[] = ['order' => $o, 'employee_id' => (int)$p['employee_id'], 'group' => $group, 'old' => ($old[$key] ?? 0), 'new' => $newCents / 100, 'delta' => $delta / 100];
            }
        }
    }
    return $out;
}

function sfc_post(array $plan)
{
    $pdo = db(); $month = ps_next_open_month(date('Y-m')); $act = sfc_actor();
    $ins = $pdo->prepare('INSERT INTO project_commission_adjustments (order_id,employee_id,commission_group,amount,payroll_month,reason,calc_note,created_by_admin) VALUES (?,?,?,?,?,?,?,?)');
    $pdo->beginTransaction();
    try {
        foreach ($plan as $p) {
            $ins->execute([(int)$p['order']['id'], $p['employee_id'], $p['group'], $p['delta'], $month, SFC_REASON, '重算 ¥' . number_format($p['new'], 2, '.', '') . '，已计 ¥' . number_format($p['old'], 2, '.', ''), (int)$act['id']]);
            ps_audit('order', (int)$p['order']['id'], 'filing_commission_adjustment', $act, ['employee_id' => $p['employee_id'], 'old' => $p['old'], 'new' => $p['new'], 'delta' => $p['delta'], 'payroll_month' => $month]);
        }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return $month;
}

function sfc_print(array $plan)
{
    $names = []; foreach (db()->query('SELECT id,name FROM employees') as $r) $names[(int)$r['id']] = $r['name'];
    $by = [];
    foreach ($plan as $p) { $n = $names[$p['employee_id']] ?? $p['employee_id']; $by[$n] = ($by[$n] ?? 0) + $p['delta']; echo "  订单 {$p['order']['order_no']} [{$p['order']['order_kind']}] $n 已计 {$p['old']} → 应计 {$p['new']}（{$p['delta']}）\n"; }
    echo "合计：\n"; foreach ($by as $n => $v) echo "  $n " . number_format($v, 2, '.', '') . "\n";
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    if (in_array('--apply-rule', $argv, true)) {
        $r = sfc_apply_rule(true);
        echo isset($r['exists']) ? "已存在规则 #{$r['exists']}，跳过\n" : "已新增规则 #{$r['added']}（基于 #{$r['base']['id']}），" . SFC_FROM . " 起服务费 0\n";
        exit(0);
    }
    if (in_array('--post-adjustments', $argv, true)) {
        $plan = sfc_plan(); sfc_print($plan);
        if (!$plan) { echo "没有需要调整的\n"; exit(0); }
        echo '已记入调整，计入 ' . sfc_post($plan) . "\n";
        exit(0);
    }
    $pdo = db(); $pdo->beginTransaction(); // 预览：试加规则 → 试算 → 回滚
    $r = sfc_apply_rule(true);
    echo isset($r['exists']) ? "规则已存在 #{$r['exists']}\n" : "将新增规则 #{$r['added']}（基于 #{$r['base']['id']}，服务费 {$r['base']['service_fee_rate']} → 0），" . SFC_FROM . " 起\n";
    sfc_print(sfc_plan());
    $pdo->rollBack();
    echo "\n这是预览，没有写库。\n";
}
