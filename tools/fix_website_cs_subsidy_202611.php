<?php
/**
 * 网站客服（AI网站定制 客服）每单 20 元补助更正（2026-10 订单起）：
 *  - 华梦定制：不给每单 20 元（该订单类型的客服规则补助改为 0，10% 利润分成和 80% 成本口径不变）。
 *  - 博山定制 / 未选类型：不再有每单 20 元（“所有订单类型”规则的每单补助改为 0，10% 分成和 65% 成本口径不变）。
 *  - 只有 AI定制（新建站）订单拿每单 20 元：由“AI定制 新建站奖励”补贴规则单独发放，不变。
 * 已审核订单的快照不改；差额按“新规则补助 − 快照里已计补助”作为调整，计入下一个未锁定月份。
 * 用法：php tools/fix_website_cs_subsidy_202611.php                     # 预览（事务内试算后回滚，不写库）
 *       php tools/fix_website_cs_subsidy_202611.php --apply-rules        # 只改规则（草稿订单按新规则自动重算）
 *       php tools/fix_website_cs_subsidy_202611.php --post-adjustments [--except-kind=AI定制]   # 规则已改后，把已审核订单的补助差额记为调整
 */
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectSettlement.php';
require_once __DIR__ . '/../includes/ProjectOrderBusiness.php';

const FCS_FROM = '2026-10-01';
const FCS_REASON = '网站客服每单20元更正：只有AI定制新建站订单发每单20元';

/** 要改的两条规则：[id, 现补助, 说明, 新备注]。找不到的跳过。 */
function fcs_rules()
{
    $pdo = db(); $out = [];
    $base = $pdo->prepare("SELECT id,per_order_subsidy FROM project_commission_rules WHERE commission_group='customer_service' AND project_type='AI网站定制' AND role_name='*' AND order_kind='*' AND is_extra=0 AND is_active=1 AND effective_from=? ORDER BY id DESC LIMIT 1");
    $base->execute([FCS_FROM]);
    if ($r = $base->fetch()) $out[] = [(int)$r['id'], (float)$r['per_order_subsidy'], '所有订单类型（博山定制等）',
        '定制客服利润分成 10%（两人合接各 5%）；2026-10 起不再发每单 20 元单量奖励（只有 AI定制 新建站订单有，见“AI定制 新建站奖励”）。博山定制成本按售价 65% 计，华梦外包按实际 80%。'];
    $hm = $pdo->prepare("SELECT id,per_order_subsidy FROM project_commission_rules WHERE commission_group='customer_service' AND project_type='AI网站定制' AND role_name='*' AND order_kind='华梦定制' AND is_extra=0 AND is_active=1 AND effective_from=? ORDER BY id DESC LIMIT 1");
    $hm->execute([FCS_FROM]);
    if ($r = $hm->fetch()) $out[] = [(int)$r['id'], (float)$r['per_order_subsidy'], '华梦定制',
        '华梦定制客服：成本按售价 80% 记录，其余同定制客服规则；不发每单 20 元奖励；2026-10-01 起'];
    return $out;
}

function fcs_actor()
{
    return ['type' => 'admin', 'id' => (int)db()->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetchColumn(), 'role' => 'finance', 'employee_id' => null];
}

function fcs_apply_rules()
{
    $pdo = db(); $changed = [];
    $up = $pdo->prepare('UPDATE project_commission_rules SET per_order_subsidy=0,note=? WHERE id=?');
    foreach (fcs_rules() as [$id, $old, $label, $note]) {
        if ($old <= 0) continue;
        $up->execute([$note, $id]);
        ps_audit('commission_rule', $id, 'cs_subsidy_removed', fcs_actor(), ['label' => $label, 'per_order_subsidy_from' => $old, 'per_order_subsidy_to' => 0, 'reason' => '网站客服每单 20 元只给 AI定制 新建站订单']);
        $changed[] = [$id, $label, $old];
    }
    return $changed;
}

/** 已审核的 2026-10 起 AI网站定制 订单里，客服补助与按当前规则重算不一致的：[订单, 人, 已计, 应计, 差额]。已记过本次调整的会扣掉，可重复执行。 */
function fcs_plan()
{
    $pdo = db(); $plan = [];
    $orders = $pdo->prepare("SELECT o.* FROM project_orders o WHERE o.project_type='AI网站定制' AND o.order_date>=? AND o.settlement_status IN ('approved','locked') AND EXISTS (SELECT 1 FROM project_commission_snapshots c WHERE c.order_id=o.id AND c.commission_group='customer_service' AND c.subsidy_amount<>0) ORDER BY o.id");
    $orders->execute([FCS_FROM]);
    $snap = $pdo->prepare("SELECT employee_id,SUM(subsidy_amount) sub FROM project_commission_snapshots WHERE order_id=? AND commission_group='customer_service' GROUP BY employee_id");
    $paid = $pdo->prepare("SELECT employee_id,SUM(amount) amt FROM project_commission_adjustments WHERE order_id=? AND commission_group='customer_service' AND reason=? GROUP BY employee_id");
    foreach ($orders->fetchAll() as $o) {
        $summary = ps_summary($o, ps_costs((int)$o['id']), ps_participants((int)$o['id']));
        $newSub = [];
        foreach ($summary['groups']['customer_service']['people'] ?? [] as $p) $newSub[(int)$p['employee_id']] = round((float)$p['calc']['subsidy'], 2);
        $snap->execute([(int)$o['id']]); $paid->execute([(int)$o['id'], FCS_REASON]);
        $already = $paid->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($snap->fetchAll(PDO::FETCH_KEY_PAIR) as $eid => $oldSub) {
            $delta = round(($newSub[(int)$eid] ?? 0) - (float)$oldSub - (float)($already[$eid] ?? 0), 2);
            if (abs($delta) >= 0.005) $plan[] = ['order_id' => (int)$o['id'], 'order_no' => $o['order_no'], 'kind' => $o['order_kind'], 'employee_id' => (int)$eid, 'old' => (float)$oldSub, 'new' => $newSub[(int)$eid] ?? 0.0, 'delta' => $delta];
        }
    }
    return $plan;
}

function fcs_post(array $plan)
{
    $pdo = db(); $month = ps_next_open_month(date('Y-m')); $act = fcs_actor();
    $ins = $pdo->prepare("INSERT INTO project_commission_adjustments (order_id,employee_id,commission_group,amount,payroll_month,reason,calc_note,created_by_admin) VALUES (?,?,'customer_service',?,?,?,?,?)");
    $pdo->beginTransaction();
    try {
        foreach ($plan as $p) {
            $ins->execute([$p['order_id'], $p['employee_id'], $p['delta'], $month, FCS_REASON, '补助已计 ¥' . number_format($p['old'], 2, '.', '') . '，按新规则应计 ¥' . number_format($p['new'], 2, '.', ''), (int)$act['id']]);
            ps_audit('order', $p['order_id'], 'cs_subsidy_adjustment', $act, $p + ['payroll_month' => $month]);
        }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return $month;
}

function fcs_print_plan(array $plan)
{
    $names = []; foreach (db()->query('SELECT id,name FROM employees') as $r) $names[(int)$r['id']] = $r['name'];
    $byPerson = [];
    foreach ($plan as $p) {
        $n = $names[$p['employee_id']] ?? $p['employee_id'];
        $byPerson[$n] = ($byPerson[$n] ?? 0) + $p['delta'];
        echo "  订单 {$p['order_no']} [" . ($p['kind'] ?: '未选类型') . "] $n 已计 {$p['old']} → 应计 {$p['new']}（{$p['delta']}）\n";
    }
    echo "合计：\n";
    foreach ($byPerson as $n => $v) echo "  $n " . number_format($v, 2, '.', '') . "\n";
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $pdo = db();
    if (in_array('--apply-rules', $argv, true)) {
        foreach (fcs_apply_rules() as [$id, $label, $old]) echo "规则 #{$id}（{$label}）每单补助 {$old} → 0\n";
        exit(0);
    }
    if (in_array('--post-adjustments', $argv, true)) {
        $plan = fcs_plan();
        // --except-kind=AI定制：不动指定订单类型的已审核订单（例如 AI定制 历史上叠加发了 40，是否扣回另行决定）
        foreach ($argv as $a) if (strpos($a, '--except-kind=') === 0) { $skip = substr($a, 14); $plan = array_values(array_filter($plan, function ($p) use ($skip) { return $p['kind'] !== $skip; })); }
        fcs_print_plan($plan);
        if (!$plan) { echo "没有需要调整的\n"; exit(0); }
        echo '已记入调整，计入 ' . fcs_post($plan) . "\n";
        exit(0);
    }
    $pdo->beginTransaction(); // 预览：试改规则 → 试算 → 回滚
    foreach (fcs_rules() as [$id, $old, $label]) echo "规则 #{$id}（{$label}）每单补助 {$old} → 0\n";
    fcs_apply_rules();
    fcs_print_plan(fcs_plan());
    $pdo->rollBack();
    echo "\n这是预览，没有写库。\n";
}
