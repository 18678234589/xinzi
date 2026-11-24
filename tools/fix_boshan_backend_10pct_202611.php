<?php
/**
 * 博山定制后端提成比例更正：2026-10 起 15% → 10%（财务确认：博山定制后端于海波、崔鑫栋等仍按 10%）。
 *  - 规则 3156（博山定制 · 后端）rate 0.15 → 0.10，草稿订单按新规则自动重算；
 *  - 已审核订单的快照不改，差额按“新规则应计 − 快照 − 此前调整”记为 2026-10 的分成调整（可重复执行，已记过的会扣掉）。
 * 用法：php tools/fix_boshan_backend_10pct_202611.php            # 预览（事务内试算后回滚，不写库）
 *       php tools/fix_boshan_backend_10pct_202611.php --apply    # 改规则并记调整
 */
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectSettlement.php';

const FBB_RULE = 3156; const FBB_MONTH = '2026-10'; const FBB_REASON = '博山定制后端比例更正：15% → 10%';

function fbb_run($apply)
{
    $pdo = db(); $pdo->beginTransaction();
    $act = ['type' => 'admin', 'id' => (int)$pdo->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetchColumn(), 'role' => 'finance', 'employee_id' => null];
    $rule = $pdo->query('SELECT id,rate,order_kind,role_name FROM project_commission_rules WHERE id=' . FBB_RULE)->fetch();
    if (!$rule || $rule['order_kind'] !== '博山定制' || $rule['role_name'] !== '后端') { $pdo->rollBack(); throw new RuntimeException('规则 ' . FBB_RULE . ' 不是博山定制后端，未做任何修改'); }
    $pdo->prepare('UPDATE project_commission_rules SET rate=0.10,note=? WHERE id=?')->execute(['博山定制后端：(售价 − 成本 − 服务费) × 10%（财务确认）；2026-10-01 起。前端另按前端规则 / 月度阶梯。', FBB_RULE]);
    ps_audit('commission_rule', FBB_RULE, 'boshan_backend_rate', $act, ['from' => (float)$rule['rate'], 'to' => 0.10]);
    $orders = $pdo->query("SELECT o.* FROM project_orders o WHERE o.project_type='AI网站定制' AND o.order_kind='博山定制' AND o.order_date>='2026-10-01' AND o.settlement_status IN ('approved','locked') AND EXISTS (SELECT 1 FROM project_commission_snapshots c WHERE c.order_id=o.id AND c.rule_id=" . FBB_RULE . ")")->fetchAll();
    $snap = $pdo->prepare("SELECT employee_id,SUM(commission_amount) amt FROM project_commission_snapshots WHERE order_id=? AND rule_id=? AND commission_group='technical' GROUP BY employee_id");
    $prior = $pdo->prepare("SELECT employee_id,SUM(amount) amt FROM project_commission_adjustments WHERE order_id=? AND commission_group='technical' GROUP BY employee_id");
    $ins = $pdo->prepare("INSERT INTO project_commission_adjustments (order_id,employee_id,commission_group,amount,payroll_month,reason,calc_note,created_by_admin) VALUES (?,?,'technical',?,?,?,?,?)");
    $names = []; foreach ($pdo->query('SELECT id,name FROM employees') as $r) $names[(int)$r['id']] = $r['name'];
    $total = []; $n = 0;
    foreach ($orders as $o) {
        $sum = ps_summary($o, ps_costs((int)$o['id']), ps_participants((int)$o['id']));
        $new = [];
        foreach ($sum['groups']['technical']['people'] ?? [] as $p) if ((int)($p['rule']['id'] ?? 0) === FBB_RULE) $new[(int)$p['employee_id']] = round((float)$p['calc']['share'] + (float)$p['calc']['subsidy'], 2);
        $snap->execute([(int)$o['id'], FBB_RULE]); $old = $snap->fetchAll(PDO::FETCH_KEY_PAIR);
        $prior->execute([(int)$o['id']]); $adj = $prior->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($old as $eid => $oldAmt) {
            if (!isset($new[(int)$eid])) continue;
            $delta = round($new[(int)$eid] - (float)$oldAmt - (float)($adj[$eid] ?? 0), 2);
            if (abs($delta) < 0.005) continue;
            $ins->execute([(int)$o['id'], (int)$eid, $delta, FBB_MONTH, FBB_REASON, '已计 ¥' . number_format((float)$oldAmt, 2, '.', '') . '，按 10% 应计 ¥' . number_format($new[(int)$eid], 2, '.', ''), (int)$act['id']]);
            $total[$names[(int)$eid] ?? $eid] = ($total[$names[(int)$eid] ?? $eid] ?? 0) + $delta; $n++;
        }
    }
    foreach ($total as $name => $v) echo "  $name " . number_format($v, 2, '.', '') . "\n";
    echo "订单 " . count($orders) . " 张，调整 $n 条；" . ($apply ? '已写入' : '预览，已回滚') . "\n";
    if ($apply) $pdo->commit(); else $pdo->rollBack();
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) fbb_run(in_array('--apply', $argv, true));
