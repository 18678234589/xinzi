<?php
/**
 * 网站售后部部门订单切换到新系统算法（一次性执行脚本）
 *
 * 阶段一：回填成本中心——工资表（import id=138）312 张匹配订单的总成本，
 *        按 order_no 匹配写入 project_costs（已审核，备注"工资表总成本补录"）。
 *        幂等：订单已有任意成本记录的跳过。
 * 阶段二：批量审核——449 单网站续费 delivery_status 置 finished（历史单视为已交付），
 *        逐单调 ps_approve_order 生成快照（149 张无收款单只发每单补助、不进部门毛利基数，
 *        为引擎现有行为）。已审核的跳过。
 * 阶段三：从库中真实数据输出每人结果（dept_share + 单量提成）。
 *
 * 用法：php tools/dept_orders_switch_new_algo_202608.php [月份=2026-08]
 */
require __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectSettlement.php';
require_once __DIR__ . '/../includes/ProjectMonthly.php';

$pdo = db();
$MONTH = $argv[1] ?? '2026-08';
$BUSINESS = '网站续费';
$m = function ($v) { return number_format((float)$v, 2); };

/* ---------- 阶段一：成本回填 ---------- */
echo "######## 阶段一：成本回填（工资表 id=138 → 成本中心）########\n";
$imp = $pdo->query("SELECT stored_name FROM project_import_files WHERE id=138")->fetch(PDO::FETCH_ASSOC);
if (!$imp) throw new RuntimeException('找不到导入文件 id=138');
$imp['content'] = ps_private_read('imports', $imp['stored_name']);
$sheets = ps_import_file_sheets($imp);
$rows = reset($sheets);
$head = $rows[0];
$idx = array_flip($head);
$iNo = $idx['订单编号']; $iCost = $idx['总成本'];

$costByNo = [];
foreach ($rows as $i => $cells) {
    if ($i === 0) continue;
    $no = trim((string)($cells[$iNo] ?? ''));
    if ($no === '') continue;
    $cost = (float)str_replace([',', '¥'], '', trim((string)($cells[$iCost] ?? '')));
    if ($cost != 0) $costByNo[$no] = ($costByNo[$no] ?? 0) + $cost;
}
$orders = [];
foreach ($pdo->query("SELECT id, order_no FROM project_orders WHERE project_type=" . $pdo->quote($BUSINESS))->fetchAll(PDO::FETCH_ASSOC) as $o) $orders[$o['order_no']] = (int)$o['id'];

$pdo->beginTransaction();
$hasCost = [];
foreach ($pdo->query("SELECT DISTINCT order_id FROM project_costs")->fetchAll(PDO::FETCH_ASSOC) as $c) $hasCost[(int)$c['order_id']] = 1;
$ins = $pdo->prepare("INSERT INTO project_costs (order_id,category,item_name,quantity,unit,unit_price,amount,cost_kind,is_custom,reason,review_status) VALUES (?,'outsourcing','续费成本',1,'项',?,?,'one_time',1,'工资表总成本补录（订单号匹配）','approved')");
$written = 0; $writtenSum = 0.0; $skipped = 0; $noOrder = 0;
foreach ($costByNo as $no => $cost) {
    $oid = $orders[$no] ?? 0;
    if (!$oid) { $noOrder++; continue; }
    if (isset($hasCost[$oid])) { $skipped++; continue; }
    $ins->execute([$oid, round($cost, 2), round($cost, 2)]);
    $written++; $writtenSum += $cost;
}
$pdo->commit();
printf("写入成本 %d 条（%s），订单已有成本跳过 %d 条，表内订单号未匹配 %d 条\n", $written, $m($writtenSum), $skipped, $noOrder);

/* ---------- 阶段二：批量审核 ---------- */
echo "\n######## 阶段二：批量审核生成快照 ########\n";
$actorRow = $pdo->query('SELECT actor_type, actor_id FROM project_audit_logs ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$actor = $actorRow ? ['type' => $actorRow['actor_type'], 'id' => (int)$actorRow['actor_id']] : ['type' => 'admin', 'id' => 1];
$pdo->beginTransaction();
$pdo->prepare("UPDATE project_orders SET delivery_status='finished' WHERE project_type=? AND delivery_status='unfinished'")->execute([$BUSINESS]);
$pend = $pdo->query("SELECT id, order_no, order_kind FROM project_orders WHERE project_type=" . $pdo->quote($BUSINESS) . " AND settlement_status='draft' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$ok = 0; $fail = [];
foreach ($pend as $o) {
    try { ps_approve_order((int)$o['id'], $actor, $MONTH); $ok++; }
    catch (Throwable $e) { $fail[] = ['#' . $o['id'] . ' ' . $o['order_no'], $e->getMessage()]; }
}
if ($fail) { $pdo->rollBack(); echo "有 " . count($fail) . " 单失败，全部回滚：\n"; foreach (array_slice($fail, 0, 5) as $f) echo '  ', json_encode($f, JSON_UNESCAPED_UNICODE), "\n"; exit(1); }
$pdo->commit();
printf("审核成功 %d / %d 单（delivery_status 已置 finished，本次为真实写入）\n", $ok, count($pend));

/* ---------- 阶段三：真实结果 ---------- */
echo "\n######## 阶段三：每人新系统结果（{$MONTH}，库内真实数据）########\n";
$empNames = [];
foreach ($pdo->query('SELECT id, name FROM employees')->fetchAll(PDO::FETCH_ASSOC) as $e) $empNames[(int)$e['id']] = $e['name'];

$perEmp = [];
foreach ($pdo->query("SELECT s.employee_id, s.order_id, s.commission_amount FROM project_commission_snapshots s JOIN project_orders o ON o.id=s.order_id WHERE o.project_type=" . $pdo->quote($BUSINESS))->fetchAll(PDO::FETCH_ASSOC) as $s) {
    $eid = (int)$s['employee_id'];
    $perEmp[$eid] = $perEmp[$eid] ?? ['commission' => 0.0, 'orders' => []];
    $perEmp[$eid]['commission'] += (float)$s['commission_amount'];
    $perEmp[$eid]['orders'][(int)$s['order_id']] = 1;
}
$deptRules = [];
foreach ($pdo->query("SELECT employee_id, params_json FROM project_monthly_rules WHERE rule_type='dept_share' AND scope_business LIKE " . $pdo->quote('%' . $BUSINESS . '%') . " AND is_active=1")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $deptRules[(int)$r['employee_id']] = json_decode($r['params_json'], true)['rate'] ?? 0;
}
$results = ps_monthly_results($MONTH, true);
$deptAmt = [];
foreach ($results as $row) if ($row['rule_type'] === 'dept_share') $deptAmt[(int)$row['employee_id']] = ['amount' => (float)$row['amount'], 'detail' => $row['detail']];

printf("%-8s | %9s | %12s | %12s | %12s\n", '人员', '比例', '部门提成', '单量提成', '合计');
echo str_repeat('-', 66), "\n";
$sum = 0.0;
foreach ($deptRules as $eid => $rate) {
    $d = $deptAmt[$eid] ?? null;
    $dept = $d['amount'] ?? 0.0;
    $sub = $perEmp[$eid]['commission'] ?? 0.0;
    printf("%-8s | %8.2f%% | %12s | %12s | %12s\n", $empNames[$eid] ?? $eid, $rate * 100, $m($dept), $m($sub), $m($dept + $sub));
    $sum += $dept + $sub;
}
echo str_repeat('-', 66), "\n";
printf("合计      |          | %12s | %12s | %12s\n", '', '', $m($sum));
echo "\ndept_share 明细：\n";
foreach ($deptAmt as $eid => $d) printf("  [%s] %s\n", $empNames[$eid] ?? $eid, $d['detail']);
echo "参与单量（去重）：";
foreach ($perEmp as $eid => $p) printf("%s=%d单 ", $empNames[$eid] ?? $eid, count($p['orders']));
echo "\n";
$st = $pdo->query("SELECT settlement_status, COUNT(*) c FROM project_orders WHERE project_type=" . $pdo->quote($BUSINESS) . " GROUP BY settlement_status")->fetchAll(PDO::FETCH_ASSOC);
echo '订单状态: ', json_encode($st), "\n";
