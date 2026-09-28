<?php
/**
 * 部门订单算法对比（只读试算，数据库零改动）
 *
 * 用法：php tests/dept_orders_compare_202608.php
 *
 * 目的：以规则中心 + 成本中心的现有数据，走新系统现有真实算法
 *      （ps_approve_order 生成快照 → ps_monthly_results 月度规则），
 *      计算网站售后部部门订单（网站续费）的报酬，并与旧系统算法口径对比。
 *
 * 做法：全程包在一个事务里（ps_approve_order 内部还会建 SAVEPOINT），
 *      结束前 ROLLBACK——生成的快照、订单状态更新、审计日志全部还原。
 *      试算中会把 delivery_status 临时置为 finished（历史订单视为已交付）。
 *
 * 口径备注：
 * - 新系统收入 = 实收(receipt_amount) − 退款；旧系统 = order_amount（售价）。
 * - 旧系统 续费x% 模块（standard，price_source=order_amount）不扣总成本：
 *   公式 = (售价 − 售价×3%) × 比例。
 * - 新系统 dept_share 基数 = 范围内每单计提基数去重（pool 快照取整单，否则取最大者），
 *   成本取成本中心 project_costs 已审核数据（本批 449 单仅 2 条 ¥440，缺口记 0，结果偏大）。
 */
require __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectSettlement.php';
require_once __DIR__ . '/../includes/ProjectMonthly.php';

$pdo = db();
$MONTH = $argv[1] ?? '2026-08';
$BUSINESS = '网站续费';
$money = function ($v) { return number_format((float)$v, 2); };

/* ---------- 一、数据事实 ---------- */
echo "######## 一、数据事实（{$BUSINESS}）########\n";
$f = $pdo->query("SELECT COUNT(*) cnt, SUM(contract_amount) contract, SUM(receipt_amount) receipt, SUM(refund_amount) refund FROM project_orders WHERE project_type=" . $pdo->quote($BUSINESS))->fetch(PDO::FETCH_ASSOC);
printf("订单 %d 单：售价合计 %s / 实收合计 %s / 退款 %s\n", $f['cnt'], $money($f['contract']), $money($f['receipt']), $money($f['refund']));
$costSum = (float)$pdo->query("SELECT COALESCE(SUM(c.amount),0) FROM project_costs c JOIN project_orders o ON o.id=c.order_id WHERE o.project_type=" . $pdo->quote($BUSINESS) . " AND c.review_status='approved'")->fetchColumn();
$noCost = $pdo->query("SELECT COUNT(*) FROM project_orders o WHERE o.project_type=" . $pdo->quote($BUSINESS) . " AND NOT EXISTS(SELECT 1 FROM project_costs c WHERE c.order_id=o.id)")->fetchColumn();
printf("成本中心已审核成本合计 %s；%d 单没有成本记录 → 试算成本按成本中心现有数据计（缺口记 0，结果偏大）\n", $money($costSum), $noCost);

/* 参与人分组（单参与人 = 原单核对组；多参与人 = 本批默认参与人组） */
$grp = $pdo->query("SELECT n, COUNT(*) cnt, SUM(contract_amount) contract, SUM(receipt_amount) receipt FROM (
    SELECT o.id, o.contract_amount, o.receipt_amount, (SELECT COUNT(*) FROM project_participants p WHERE p.order_id=o.id) n
    FROM project_orders o WHERE o.project_type=" . $pdo->quote($BUSINESS) . ") t GROUP BY n ORDER BY n")->fetchAll(PDO::FETCH_ASSOC);
foreach ($grp as $g) {
    printf("参与人=%d人 的订单 %d 单：售价 %s / 实收 %s\n", $g['n'], $g['cnt'], $money($g['contract']), $money($g['receipt']));
}

/* ---------- 二、旧系统口径 ---------- */
echo "\n######## 二、旧系统算法口径（orders 表 · 网站售后部部门行 · {$MONTH}）########\n";
$deptRows = $pdo->query("SELECT order_amount, raw_data FROM orders WHERE employee_id=0 AND order_scope='department' AND COALESCE(is_deleted,0)=0 AND DATE_FORMAT(order_date,'%Y-%m')=" . $pdo->quote($MONTH) . " AND JSON_UNQUOTE(JSON_EXTRACT(raw_data,'$.__dept__'))='网站售后部'")->fetchAll(PDO::FETCH_ASSOC);
$oldSales = 0.0; $oldCost = 0.0; $oldUnv = 0;
foreach ($deptRows as $r) {
    $oldSales += (float)$r['order_amount'];
    $raw = json_decode($r['raw_data'], true);
    if (is_array($raw)) {
        $oldCost += (float)str_replace([',', '¥'], '', (string)($raw['总成本'] ?? 0));
        if (($raw['__order_status__'] ?? '') === '未核验') $oldUnv++;
    }
}
printf("部门行 %d 行：售价合计 %s，总成本合计 %s，未核验 %d 行\n", count($deptRows), $money($oldSales), $money($oldCost), $oldUnv);
echo "旧系统 续费x% 模块（standard / price_source=order_amount）不扣总成本：公式 = (售价 − 售价×3%) × 比例\n";
if ($oldUnv == count($deptRows)) echo "全部未核验 → 旧系统当前实际计薪 = 0（未核验不计薪，本对比用“若核验通过”参考值）\n";
$oldRefBase = max($oldSales * (1 - 0.03), 0);
printf("旧口径参考基数（若核验通过）：%s × 97%% = %s\n", $money($oldSales), $money($oldRefBase));

/* ---------- 三、新系统算法试算（事务内，回滚） ---------- */
echo "\n######## 三、新系统算法试算（真实 ps_approve_order + ps_monthly_results，事务回滚，零改动）########\n";
$empNames = [];
foreach ($pdo->query('SELECT id, name FROM employees')->fetchAll(PDO::FETCH_ASSOC) as $e) $empNames[(int)$e['id']] = $e['name'];

$pdo->beginTransaction();
$approved = 0; $fail = []; $failCount = [];
$actorRow = $pdo->query('SELECT actor_type, actor_id FROM project_audit_logs ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$actor = $actorRow ? ['type' => $actorRow['actor_type'], 'id' => (int)$actorRow['actor_id']] : ['type' => 'admin', 'id' => 1];
$pdo->prepare("UPDATE project_orders SET delivery_status='finished' WHERE project_type=? AND settlement_status='draft'")->execute([$BUSINESS]);
$orders = $pdo->query("SELECT id, order_no, order_kind FROM project_orders WHERE project_type=" . $pdo->quote($BUSINESS) . " AND settlement_status='draft' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($orders as $o) {
    try {
        ps_approve_order((int)$o['id'], $actor, $MONTH);
        $approved++;
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        $failCount[$msg] = ($failCount[$msg] ?? 0) + 1;
        if (count($fail) < 5) $fail[] = ['#' . $o['id'] . ' ' . $o['order_no'] . ' (' . $o['order_kind'] . ')', $msg];
    }
}
printf("审核成功 %d / %d 单\n", $approved, count($orders));
foreach ($failCount as $msg => $c) printf("  失败 %d 单：%s\n", $c, $msg);
foreach ($fail as $i => [$oid, $msg]) printf("  样例：%s → %s\n", $oid, $msg);

/* 快照汇总（事务内可见） */
$perEmp = [];
$snapQ = $pdo->query("SELECT s.employee_id, s.order_id, s.commission_amount, s.subsidy_amount FROM project_commission_snapshots s JOIN project_orders o ON o.id=s.order_id WHERE o.project_type=" . $pdo->quote($BUSINESS));
foreach ($snapQ->fetchAll(PDO::FETCH_ASSOC) as $s) {
    $eid = (int)$s['employee_id'];
    $perEmp[$eid] = $perEmp[$eid] ?? ['commission' => 0.0, 'snaps' => 0, 'orders' => []];
    $perEmp[$eid]['commission'] += (float)$s['commission_amount'];
    $perEmp[$eid]['snaps']++;
    $perEmp[$eid]['orders'][(int)$s['order_id']] = 1;
}

/* 月度结果（真实引擎，forceLive 跳过锁月读取） */
$results = ps_monthly_results($MONTH, true);
$deptAmt = [];
foreach ($results as $row) {
    if ($row['rule_type'] !== 'dept_share') continue;
    $deptAmt[(int)$row['employee_id']] = ['amount' => (float)$row['amount'], 'detail' => $row['detail']];
}
$pdo->rollBack();
echo "（事务已回滚，数据库零改动）\n";

/* ---------- 四、对比表 ---------- */
echo "\n######## 四、每人对比（{$MONTH}）########\n";
$deptRules = [];
foreach ($pdo->query("SELECT id, name, employee_id, params_json FROM project_monthly_rules WHERE rule_type='dept_share' AND scope_business LIKE " . $pdo->quote('%' . $BUSINESS . '%') . " AND is_active=1")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $deptRules[(int)$r['employee_id']] = json_decode($r['params_json'], true)['rate'] ?? 0;
}
printf("%-8s | %9s | %12s | %12s | %12s | %12s | %s\n", '人员', '比例', '新·部门提成', '新·单量提成', '新·合计', '旧·若核验参考', '旧·当前');
echo str_repeat('-', 108), "\n";
$rowsOut = [];
foreach ($deptRules as $eid => $rate) {
    $newDept = $deptAmt[$eid]['amount'] ?? 0.0;
    $newSub = $perEmp[$eid]['commission'] ?? 0.0;
    $oldRef = $oldRefBase * (float)$rate;
    printf("%-8s | %8.2f%% | %12s | %12s | %12s | %12s | %12s\n", $empNames[$eid] ?? $eid, $rate * 100, $money($newDept), $money($newSub), $money($newDept + $newSub), $money($oldRef), $money(0));
    $rowsOut[] = ['name' => $empNames[$eid] ?? $eid, 'new_dept' => $newDept, 'new_sub' => $newSub, 'old_ref' => $oldRef];
}
echo str_repeat('-', 108), "\n";
printf("合计      |          | %12s | %12s | %12s | %12s | %12s\n",
    $money(array_sum(array_column($rowsOut, 'new_dept'))), $money(array_sum(array_column($rowsOut, 'new_sub'))),
    $money(array_sum(array_column($rowsOut, 'new_dept')) + array_sum(array_column($rowsOut, 'new_sub'))),
    $money(array_sum(array_column($rowsOut, 'old_ref'))), $money(0));

echo "\n新系统 dept_share 基数（引擎明细，按订单去重汇总）：\n";
foreach ($deptAmt as $eid => $d) printf("  [%s] %s\n", $empNames[$eid] ?? $eid, $d['detail']);
echo "参与单量（去重）：";
foreach ($perEmp as $eid => $p) printf("%s=%d单 ", $empNames[$eid] ?? $eid, count($p['orders']));
echo "\n";
printf("口径说明：新系统收入=实收（%s），旧系统=售价（%s）；两者相差 %s\n", $money($f['receipt']), $money($oldSales), $money((float)$f['receipt'] - (float)$oldSales));
printf("口径说明：新系统成本=成本中心已审核成本（%s，%d 单缺口记 0）；旧系统该模块不扣成本\n", $money($costSum), $noCost);
