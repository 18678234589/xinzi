<?php
require_once __DIR__ . '/../includes/ProjectSettlement.php';
require_once __DIR__ . '/../includes/ProjectMonthly.php';
$actor = ps_require_actor();
$month = (string)($_GET['month'] ?? date('Y-m', strtotime('first day of last month')));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = date('Y-m', strtotime('first day of last month'));
$periodError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    ps_require_finance();
    try {
        $action = (string)($_POST['action'] ?? '');
        if (!in_array($action, ['lock_period', 'save_reconciliation'], true) || ($_POST['period'] ?? '') !== $month) throw new RuntimeException('结算月份无效，请刷新页面');
        db()->beginTransaction();
        db()->prepare('INSERT INTO project_payroll_periods (period) VALUES (?) ON DUPLICATE KEY UPDATE period=period')->execute([$month]);
        $periodLock = db()->prepare('SELECT status FROM project_payroll_periods WHERE period=? FOR UPDATE');
        $periodLock->execute([$month]);
        if ($periodLock->fetchColumn() === 'locked') throw new RuntimeException('该月项目分成已锁定');
        if ($action === 'save_reconciliation') {
            $snapshotId = (int)($_POST['snapshot_id'] ?? 0);
            $amountText = trim((string)($_POST['legacy_amount'] ?? ''));
            $sourceMonth = trim((string)($_POST['legacy_salary_month'] ?? ''));
            $basisNote = trim((string)($_POST['basis_note'] ?? ''));
            if (!preg_match('/^\d{1,12}(?:\.\d{1,2})?$/', $amountText)) throw new RuntimeException('旧技术分成金额须为非负金额，最多两位小数');
            if ($basisNote === '' || mb_strlen($basisNote) > 500) throw new RuntimeException('请填写不超过500字的核对依据，包括零元原因');
            $snapshotQuery = db()->prepare("SELECT id,employee_id,order_id FROM project_commission_snapshots WHERE id=? AND payroll_month=? AND commission_group='technical' FOR UPDATE");
            $snapshotQuery->execute([$snapshotId, $month]);
            $snapshot = $snapshotQuery->fetch();
            if (!$snapshot) throw new RuntimeException('技术项目分成记录不存在');
            $salaryId = null;
            if ((float)$amountText > 0 || $sourceMonth !== '') {
                if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $sourceMonth)) throw new RuntimeException('请选择旧系统分成所在结算月份');
                $sourceQuery = db()->prepare('SELECT id FROM salaries WHERE employee_id=? AND month=? ORDER BY id DESC LIMIT 1');
                $sourceQuery->execute([(int)$snapshot['employee_id'], $sourceMonth]);
                $salaryId = $sourceQuery->fetchColumn();
                if (!$salaryId) throw new RuntimeException('找不到该合作人员在所选月份的旧系统结算记录');
            }
            db()->prepare('INSERT INTO project_technical_reconciliations (snapshot_id,legacy_salary_month,legacy_salary_id,legacy_amount,basis_note,reviewed_by_admin,reviewed_at) VALUES (?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE legacy_salary_month=VALUES(legacy_salary_month),legacy_salary_id=VALUES(legacy_salary_id),legacy_amount=VALUES(legacy_amount),basis_note=VALUES(basis_note),reviewed_by_admin=VALUES(reviewed_by_admin),reviewed_at=NOW()')
                ->execute([$snapshotId, $sourceMonth !== '' ? $sourceMonth : null, $salaryId ?: null, $amountText, $basisNote, $actor['id']]);
            ps_audit('technical_reconciliation', $snapshotId, 'save', $actor, ['order_id' => (int)$snapshot['order_id'], 'employee_id' => (int)$snapshot['employee_id'], 'source_month' => $sourceMonth, 'legacy_amount' => $amountText, 'basis' => $basisNote]);
            db()->commit();
            header('Location: ' . BASE_URL . '/project/payroll.php?' . http_build_query(['month' => $month, 'employee_id' => (int)$snapshot['employee_id'], 'reconciled' => 1])); exit;
        }
        $snapshotCount = db()->prepare('SELECT COUNT(*) FROM project_commission_snapshots WHERE payroll_month=?');
        $snapshotCount->execute([$month]);
        if (!(int)$snapshotCount->fetchColumn()) throw new RuntimeException('该月尚无已审核项目分成，无需锁定');
        $pendingQuery = db()->prepare("SELECT COUNT(*) FROM project_commission_snapshots c LEFT JOIN project_technical_reconciliations r ON r.snapshot_id=c.id WHERE c.payroll_month=? AND c.commission_group='technical' AND r.id IS NULL");
        $pendingQuery->execute([$month]);
        if ((int)$pendingQuery->fetchColumn() > 0) throw new RuntimeException('仍有技术旧分成未核对，不能锁定本月');
        $frozen = ps_monthly_freeze($month); // 锁月时冻结月度规则结果，之后改规则不影响本月
        db()->prepare("UPDATE project_payroll_periods SET status='locked',locked_by_admin=?,locked_at=NOW() WHERE period=?")->execute([$actor['id'], $month]);
        ps_audit('period', (int)str_replace('-', '', $month), 'lock', $actor, ['period' => $month, 'monthly_items' => count($frozen)]);
        db()->commit();
        header('Location: ' . BASE_URL . '/project/payroll.php?' . http_build_query(['month' => $month, 'locked' => 1])); exit;
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); $periodError = $e->getMessage(); }
}
$periodQuery = db()->prepare('SELECT status,locked_at FROM project_payroll_periods WHERE period=?');
$periodQuery->execute([$month]);
$period = $periodQuery->fetch() ?: ['status' => 'draft', 'locked_at' => null];
$employeeId = $actor['role'] === 'finance' ? (int)($_GET['employee_id'] ?? 0) : $actor['employee_id'];
$employees = $actor['role'] === 'finance' ? db()->query('SELECT id,name,department FROM employees ORDER BY department,name,id')->fetchAll() : [];
if (isset($_GET['export']) && $_GET['export'] === 'project_csv') {
    $sql = 'SELECT c.*,o.order_no,o.customer_name,e.name AS employee_name,e.department,r.id AS reconciliation_id,r.legacy_amount,r.legacy_salary_month,r.basis_note FROM project_commission_snapshots c JOIN project_orders o ON o.id=c.order_id JOIN employees e ON e.id=c.employee_id LEFT JOIN project_technical_reconciliations r ON r.snapshot_id=c.id WHERE c.payroll_month=?';
    $params = [$month];
    if ($employeeId > 0) { $sql .= ' AND c.employee_id=?'; $params[] = $employeeId; }
    $sql .= ' ORDER BY e.department,e.name,e.id,o.order_date,o.id,c.id';
    $exportQuery = db()->prepare($sql);
    $exportQuery->execute($params);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="project-commission-' . $month . '.csv"');
    header('X-Content-Type-Options: nosniff');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['结算月份','合作人员编号','合作人员','部门','订单编号','客户','项目分成组','可结算收入','直接成本','贡献利润','组返佣比例','本人权重','本人项目分成','旧技术分成核销金额','旧结算月份','核对状态','核对依据','岗位','服务费','其中每单补助','计算说明']);
    foreach ($exportQuery->fetchAll() as $r) {
        $safe = function ($value) { $text = (string)$value; return preg_match('/^[=+@\-]/u', $text) ? "'" . $text : $text; };
        fputcsv($out, [$month,(int)$r['employee_id'],$safe($r['employee_name']),$safe($r['department']),$safe($r['order_no']),$safe($r['customer_name']),$r['commission_group'] === 'technical' ? '技术' : '客服',$r['income_amount'],$r['direct_cost'],$r['contribution_profit'],round((float)$r['rate'] * 100, 4) . '%',round((float)$r['group_weight'] * 100, 4) . '%',$r['commission_amount'],$r['commission_group'] === 'technical' && $r['reconciliation_id'] ? $r['legacy_amount'] : '',$safe($r['legacy_salary_month'] ?? ''),$r['commission_group'] !== 'technical' ? '客服叠加' : ($r['reconciliation_id'] ? '已核对' : '待核对'),$safe($r['basis_note'] ?? ''),$safe($r['role_name'] ?? ''),$r['service_fee'] ?? 0,$r['subsidy_amount'] ?? 0,$safe($r['calc_note'] ?? '')]);
    }
    $adjustExport = db()->prepare('SELECT a.*,o.order_no,o.customer_name,e.name AS employee_name,e.department FROM project_commission_adjustments a JOIN project_orders o ON o.id=a.order_id JOIN employees e ON e.id=a.employee_id WHERE a.payroll_month=?' . ($employeeId > 0 ? ' AND a.employee_id=?' : '') . ' ORDER BY e.department,e.name,a.id');
    $adjustExport->execute($employeeId > 0 ? [$month, $employeeId] : [$month]);
    $safe = function ($value) { $text = (string)$value; return preg_match('/^[=+@\-]/u', $text) ? "'" . $text : $text; };
    $monthlyNames = [];
    foreach (db()->query('SELECT id,name,department FROM employees')->fetchAll() as $emp) $monthlyNames[(int)$emp['id']] = $emp;
    foreach (ps_monthly_results($month) as $r) {
        if ($employeeId > 0 && (int)$r['employee_id'] !== $employeeId) continue;
        $emp = $monthlyNames[(int)$r['employee_id']] ?? ['name' => '', 'department' => ''];
        fputcsv($out, [$month,(int)$r['employee_id'],$safe($emp['name']),$safe($emp['department']),'','','月度规则','','','','','',$r['amount'],'','','月度规则',$safe($r['rule_name']),'','','',$safe($r['detail'])]);
    }
    foreach ($adjustExport->fetchAll() as $r) {
        fputcsv($out, [$month,(int)$r['employee_id'],$safe($r['employee_name']),$safe($r['department']),$safe($r['order_no']),$safe($r['customer_name']),$r['commission_group'] === 'technical' ? '技术' : '客服','','','','','',$r['amount'],'','','售后调整',$safe($r['reason']),'','','',$safe($r['calc_note'])]);
    }
    fclose($out);
    exit;
}
$selectedEmployee = null;
if ($employeeId > 0) {
    $q = db()->prepare('SELECT id,name,department,base_salary FROM employees WHERE id=?'); $q->execute([$employeeId]);
    $selectedEmployee = $q->fetch();
    if (!$selectedEmployee) { http_response_code(404); exit('合作人员不存在'); }
}
$rows = [];
$legacySalary = null;
if ($employeeId > 0) {
    $q = db()->prepare('SELECT c.*,o.order_no,o.customer_name,o.order_date,r.id AS reconciliation_id,r.legacy_amount,r.legacy_salary_month,r.basis_note,r.reviewed_at FROM project_commission_snapshots c JOIN project_orders o ON o.id=c.order_id LEFT JOIN project_technical_reconciliations r ON r.snapshot_id=c.id WHERE c.employee_id=? AND c.payroll_month=? ORDER BY o.order_date,o.id,c.id');
    $q->execute([$employeeId, $month]); $rows = $q->fetchAll();
    $legacyQuery = db()->prepare('SELECT * FROM salaries WHERE employee_id=? AND month=? ORDER BY id DESC LIMIT 1');
    $legacyQuery->execute([$employeeId, $month]);
    $legacySalary = $legacyQuery->fetch() ?: null;
} elseif ($actor['role'] === 'finance') {
    $commissionQuery = db()->prepare("SELECT c.employee_id,COUNT(*) AS order_count,SUM(c.commission_amount) AS amount,SUM(CASE WHEN c.commission_group='technical' AND r.id IS NULL THEN 1 ELSE 0 END) AS pending_reconciliations,SUM(CASE WHEN c.commission_group='technical' THEN COALESCE(r.legacy_amount,0) ELSE 0 END) AS legacy_deduction FROM project_commission_snapshots c LEFT JOIN project_technical_reconciliations r ON r.snapshot_id=c.id WHERE c.payroll_month=? GROUP BY c.employee_id");
    $commissionQuery->execute([$month]);
    $commissionsByEmployee = [];
    foreach ($commissionQuery->fetchAll() as $row) $commissionsByEmployee[(int)$row['employee_id']] = $row;
    // 售后调整（退款扣回 / 补发）按计入月份汇总进应结算分成。
    $adjustByEmployee = [];
    $adjustSum = db()->prepare('SELECT employee_id,SUM(amount) AS amount,COUNT(*) AS cnt FROM project_commission_adjustments WHERE payroll_month=? GROUP BY employee_id');
    $adjustSum->execute([$month]);
    foreach ($adjustSum->fetchAll() as $row) $adjustByEmployee[(int)$row['employee_id']] = $row;
    $monthlyByEmployee = [];
    foreach (ps_monthly_results($month) as $row) if (empty($row['paid_separately'])) $monthlyByEmployee[(int)$row['employee_id']] = ($monthlyByEmployee[(int)$row['employee_id']] ?? 0) + (float)$row['amount'];
    $legacyQuery = db()->prepare('SELECT employee_id,net_pay FROM salaries WHERE month=? ORDER BY id DESC');
    $legacyQuery->execute([$month]);
    $legacyByEmployee = [];
    foreach ($legacyQuery->fetchAll() as $row) if (!array_key_exists((int)$row['employee_id'], $legacyByEmployee)) $legacyByEmployee[(int)$row['employee_id']] = $row['net_pay'];
    foreach ($employees as $employee) {
        $eid = (int)$employee['id'];
        $rows[] = ['employee_id' => $eid, 'name' => $employee['name'], 'department' => $employee['department'],
            'order_count' => (int)($commissionsByEmployee[$eid]['order_count'] ?? 0) + (int)($adjustByEmployee[$eid]['cnt'] ?? 0),
            'amount' => round((float)($commissionsByEmployee[$eid]['amount'] ?? 0) + (float)($adjustByEmployee[$eid]['amount'] ?? 0) + (float)($monthlyByEmployee[$eid] ?? 0), 2),
            'legacy_net_pay' => $legacyByEmployee[$eid] ?? null,
            'pending_reconciliations' => (int)($commissionsByEmployee[$eid]['pending_reconciliations'] ?? 0),
            'legacy_deduction' => (float)($commissionsByEmployee[$eid]['legacy_deduction'] ?? 0)];
    }
}
$totalCents = array_sum(array_map(function ($r) { return (int)round((float)($r['commission_amount'] ?? $r['amount']) * 100); }, $rows));
$adjustRows = [];
if ($employeeId > 0) {
    $adjustQuery = db()->prepare('SELECT a.*,o.order_no FROM project_commission_adjustments a JOIN project_orders o ON o.id=a.order_id WHERE a.employee_id=? AND a.payroll_month=? ORDER BY a.id');
    $adjustQuery->execute([$employeeId, $month]);
    $adjustRows = $adjustQuery->fetchAll();
    foreach ($adjustRows as $adj) $totalCents += (int)round((float)$adj['amount'] * 100);
}
$monthlyItems = $employeeId > 0 ? array_values(array_filter(ps_monthly_results($month), function ($row) use ($employeeId) { return (int)$row['employee_id'] === $employeeId; })) : [];
$monthlyTotal = 0.0;
$separateTotal = 0.0;
// 结算单分项：固定服务费、全勤奖、月度奖励与补助；另行支付（法人补助）单列、不计入应结算。
$slip = ['base_fee' => 0.0, 'attendance_bonus' => 0.0, 'other' => 0.0];
foreach ($monthlyItems as $item) {
    if (!empty($item['paid_separately'])) { $separateTotal += (float)$item['amount']; continue; }
    $totalCents += (int)round((float)$item['amount'] * 100);
    $monthlyTotal += (float)$item['amount'];
    $slip[isset($slip[$item['rule_type']]) ? $item['rule_type'] : 'other'] += (float)$item['amount'];
}
$hasFullSlip = $slip['base_fee'] > 0 || $slip['attendance_bonus'] > 0;
$total = $totalCents / 100;
$technicalTotal = 0.0;
$customerServiceTotal = 0.0;
if ($employeeId > 0) foreach (array_merge($rows, $adjustRows) as $row) {
    $value = (float)($row['commission_amount'] ?? $row['amount']);
    if ($row['commission_group'] === 'technical') $technicalTotal += $value;
    if ($row['commission_group'] === 'customer_service') $customerServiceTotal += $value;
}
$reconciliation = $employeeId > 0 ? ps_technical_reconciliation_summary($rows) : ['pending' => 0, 'deduction' => 0];
$settlementPreview = ps_settlement_preview($legacySalary['net_pay'] ?? null, $total, $reconciliation['deduction'], $reconciliation['pending'] === 0);
$page_title = $actor['role'] === 'finance' ? '项目报酬结算中心' : '我的项目报酬';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3"><h4 class="mb-0"><?php echo e($page_title); ?></h4><div><a class="btn btn-outline-success btn-sm mr-2" href="<?php echo BASE_URL; ?>/project/payroll.php?<?php echo e(http_build_query(['month' => $month, 'employee_id' => $employeeId, 'export' => 'project_csv'])); ?>">导出项目分成 CSV</a><a class="btn btn-outline-secondary btn-sm" href="<?php echo BASE_URL; ?>/project/index.php">返回项目订单</a></div></div>
<?php if ($periodError): ?><div class="alert alert-danger"><?php echo e($periodError); ?></div><?php endif; ?>
<?php if (isset($_GET['locked'])): ?><div class="alert alert-success">该月项目分成已锁定。</div><?php endif; ?>
<?php if (isset($_GET['reconciled'])): ?><div class="alert alert-success">技术旧分成核对记录已保存。</div><?php endif; ?>
<div class="card mb-3"><div class="card-body"><form method="get" class="form-inline"><label class="mr-2">结算月份</label><input type="month" class="form-control mr-3" name="month" value="<?php echo e($month); ?>" required>
<?php if ($actor['role'] === 'finance'): ?><label class="mr-2">合作人员</label><select class="form-control mr-3" name="employee_id"><option value="0">全部合作人员</option><?php foreach ($employees as $emp): ?><option value="<?php echo (int)$emp['id']; ?>" <?php echo (int)$emp['id'] === $employeeId ? 'selected' : ''; ?>><?php echo e($emp['name'] . ' · ' . $emp['department'] . ' · ID ' . $emp['id']); ?></option><?php endforeach; ?></select><?php endif; ?><button class="btn btn-primary">查询</button></form></div></div>
<div class="alert alert-<?php echo $period['status'] === 'locked' ? 'secondary' : 'info'; ?>">项目分成月份 <?php echo e($month); ?>：<?php echo $period['status'] === 'locked' ? '已锁定（' . e($period['locked_at']) . '）' : '未锁定'; ?>。此状态仅锁定项目分成，不代表项目报酬已发放。</div>
<?php if ($actor['role'] === 'finance' && $employeeId === 0 && $period['status'] !== 'locked'): ?><form method="post" class="text-right mb-3" onsubmit="return confirm('确认该月项目分成已核对？锁定后新订单不能计入本月。')"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="lock_period"><input type="hidden" name="period" value="<?php echo e($month); ?>"><button class="btn btn-outline-danger btn-sm">锁定本月项目分成</button></form><?php endif; ?>
<div class="card mb-3"><div class="card-body"><div class="row align-items-center"><div class="col-md-8"><div class="text-muted small"><?php echo $selectedEmployee ? e($selectedEmployee['name'] . ' · ' . $selectedEmployee['department']) : '全部合作人员'; ?> · <?php echo e($month); ?></div><h3 class="mb-0">项目分成合计 ¥<?php echo money($total); ?></h3></div><div class="col-md-4 text-md-right text-muted small">逐单分成快照 + 售后调整 + 月度规则<?php echo $period['status'] === 'locked' ? '（已冻结）' : '（月度规则实时计算）'; ?></div></div></div></div>
<?php if ($employeeId > 0): ?>
<?php if ($hasFullSlip): $orderShare = 0.0; foreach ($rows as $r) $orderShare += (float)$r['commission_amount']; $adjustShare = 0.0; foreach ($adjustRows as $a) $adjustShare += (float)$a['amount']; ?>
<div class="card mb-3 project-slip"><div class="card-header d-flex justify-content-between flex-wrap"><span>项目报酬结算单 · <?php echo e($selectedEmployee['name']); ?> · <?php echo e($month); ?></span><span class="text-muted small"><?php echo $period['status'] === 'locked' ? '已锁定' : '实时计算，锁月后冻结'; ?></span></div><div class="card-body">
<div class="project-slip-grid">
<div><small>固定服务费（按考勤）</small><strong>¥<?php echo money($slip['base_fee']); ?></strong></div>
<div><small>全勤奖</small><strong>¥<?php echo money($slip['attendance_bonus']); ?></strong></div>
<div><small>项目分成（逐单）</small><strong>¥<?php echo money($orderShare); ?></strong></div>
<div><small>月度奖励与补助</small><strong>¥<?php echo money($slip['other']); ?></strong></div>
<?php if (abs($adjustShare) > 0.004): ?><div><small>售后调整</small><strong>¥<?php echo money($adjustShare); ?></strong></div><?php endif; ?>
<div class="is-total"><small>应结算金额</small><strong>¥<?php echo money($total); ?></strong></div>
</div>
<?php if ($separateTotal > 0): ?><p class="small text-muted mt-2 mb-0">另有法人补助等另行支付 ¥<?php echo money($separateTotal); ?>，不含在应结算金额内。</p><?php endif; ?>
<p class="small text-muted mt-2 mb-0">应结算金额对应原收入表“应发工资”（扣款、代扣保险等仍按原流程处理）。<?php echo $legacySalary ? '原系统本月另有结算记录 ¥' . money($legacySalary['net_pay']) . '，请勿重复发放。' : ''; ?></p>
</div></div>
<?php elseif ($legacySalary): ?>
<div class="card mb-3"><div class="card-header">项目报酬试算（旧技术同单分成扣除；客服原绩效叠加）</div><div class="card-body">
  <div class="row text-center"><div class="col-md-3"><small class="text-muted">原系统已结算金额</small><h5>¥<?php echo money($legacySalary['net_pay']); ?></h5></div><div class="col-md-3"><small class="text-muted">旧技术同单分成核销</small><h5>− ¥<?php echo money($reconciliation['deduction']); ?></h5></div><div class="col-md-3"><small class="text-muted">新增项目分成（技术 ¥<?php echo money($technicalTotal); ?> / 客服 ¥<?php echo money($customerServiceTotal); ?>）</small><h5>+ ¥<?php echo money($total); ?></h5></div><div class="col-md-3"><small class="text-muted">预计应结算金额</small><h4 class="<?php echo $settlementPreview === null ? 'text-warning' : 'text-success'; ?>"><?php echo $settlementPreview === null ? '待技术分成核对' : '¥' . money($settlementPreview); ?></h4></div></div>
  <div class="row small border-top pt-2"><div class="col-md-3">固定服务费：¥<?php echo money($legacySalary['base_salary_amount'] ?? 0); ?></div><div class="col-md-3">原系统绩效等净额（含奖扣）：¥<?php echo money($legacySalary['commission']); ?></div><div class="col-md-3">全勤奖励参考：¥<?php echo money($legacySalary['full_attendance_bonus'] ?? 0); ?></div><div class="col-md-3">保险扣除参考：¥<?php echo money($legacySalary['insurance_amount'] ?? 0); ?></div></div>
  <p class="small text-muted mt-2 mb-0">技术同一订单只保留新项目分成：原系统净额 − 财务逐单核实的旧技术分成 + 新技术分成 + 新客服分成。客服原有绩效保留并叠加。原系统金额已含奖扣，上方分项不能再次相加；本页为试算，不改写原结算记录。<?php if ($reconciliation['pending']): ?>尚有 <?php echo (int)$reconciliation['pending']; ?> 条技术分成待核对，暂不提供预计应结算金额。<?php endif; ?></p>
</div></div>
<?php else: ?><div class="alert alert-warning">该月原系统项目报酬尚未结算，且规则中心未给此人配置固定服务费，暂不能给出完整应结算金额；项目分成已列在下方。</div><?php endif; ?>
<div class="card"><div class="card-header">逐单项目分成明细</div><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>订单</th><th>客户</th><th>组别 / 岗位</th><th class="text-right">收入</th><th class="text-right">直接成本</th><th class="text-right">服务费</th><th class="text-right">计提基数</th><th class="text-right">比例</th><th class="text-right">本人权重</th><th class="text-right">本人项目分成</th><th>旧技术分成核对</th></tr></thead><tbody>
<?php foreach ($rows as $row): ?><tr><td><a href="<?php echo BASE_URL; ?>/project/order.php?id=<?php echo (int)$row['order_id']; ?>"><?php echo e($row['order_no']); ?></a></td><td><?php echo e($row['customer_name']); ?></td><td><?php echo $row['commission_group'] === 'technical' ? '技术' : '客服'; ?><?php if (($row['role_name'] ?? '') !== ''): ?><div class="small text-muted"><?php echo e($row['role_name']); ?></div><?php endif; ?></td><td class="text-right">¥<?php echo money($row['income_amount']); ?></td><td class="text-right">¥<?php echo money($row['direct_cost']); ?></td><td class="text-right">¥<?php echo money($row['service_fee'] ?? 0); ?></td><td class="text-right">¥<?php echo money($row['contribution_profit']); ?></td><td class="text-right"><?php echo money($row['rate'] * 100); ?>%</td><td class="text-right"><?php echo money($row['group_weight'] * 100); ?>%</td><td class="text-right font-weight-bold">¥<?php echo money($row['commission_amount']); ?><?php if ((float)($row['subsidy_amount'] ?? 0) > 0): ?><div class="small text-muted font-weight-normal">含每单补助 ¥<?php echo money($row['subsidy_amount']); ?></div><?php endif; ?><?php if (($row['calc_note'] ?? '') !== ''): ?><div class="small text-muted font-weight-normal"><?php echo e($row['calc_note']); ?></div><?php endif; ?></td><td><?php if ($row['commission_group'] === 'technical'): ?><?php if ($row['reconciliation_id']): ?>旧分成 ¥<?php echo money($row['legacy_amount']); ?><?php if ($row['legacy_salary_month']): ?>（<?php echo e($row['legacy_salary_month']); ?>）<?php endif; ?><br><small class="text-muted"><?php echo e($row['basis_note']); ?></small><?php else: ?><span class="text-warning">待财务核对</span><?php endif; ?><?php else: ?>客服原绩效叠加<?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="10" class="text-center text-muted py-4">该月暂无已审核项目分成</td></tr><?php endif; ?></tbody></table></div></div>
<?php if ($monthlyItems): ?><div class="card mt-3"><div class="card-header d-flex justify-content-between"><span>月度规则（已计入上方合计 ¥<?php echo money($monthlyTotal); ?>）</span><a class="small" href="<?php echo BASE_URL; ?>/project/rules.php?month=<?php echo e($month); ?>#monthly">在规则中心调整</a></div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>规则</th><th class="text-right">金额</th><th>计算过程</th></tr></thead><tbody><?php foreach ($monthlyItems as $item): ?><tr class="<?php echo !empty($item['paid_separately']) ? 'text-muted' : ''; ?>"><td><?php echo e($item['rule_name']); ?><?php echo !empty($item['paid_separately']) ? ' <span class="badge badge-secondary">另行支付</span>' : ''; ?></td><td class="text-right font-weight-bold <?php echo (float)$item['amount'] < 0 ? 'text-danger' : ''; ?>">¥<?php echo money($item['amount']); ?></td><td class="small text-muted"><?php echo e($item['detail']); ?></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>
<?php if ($adjustRows): ?><div class="card mt-3"><div class="card-header">售后调整（退款扣回 / 补发，已计入上方合计）</div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>订单</th><th>分成组</th><th class="text-right">调整金额</th><th>原因 / 计算</th></tr></thead><tbody><?php foreach ($adjustRows as $adj): ?><tr><td><a href="<?php echo BASE_URL; ?>/project/order.php?id=<?php echo (int)$adj['order_id']; ?>"><?php echo e($adj['order_no']); ?></a></td><td><?php echo $adj['commission_group'] === 'technical' ? '技术' : '客服'; ?></td><td class="text-right font-weight-bold <?php echo (float)$adj['amount'] < 0 ? 'text-danger' : 'text-success'; ?>"><?php echo ((float)$adj['amount'] > 0 ? '+' : '') . '¥' . money($adj['amount']); ?></td><td class="small"><?php echo e($adj['reason']); ?><div class="text-muted"><?php echo e($adj['calc_note']); ?></div></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>
<?php if ($actor['role'] === 'finance' && $period['status'] !== 'locked'): foreach ($rows as $row): if ($row['commission_group'] !== 'technical') continue; ?>
<form method="post" class="card mt-2"><div class="card-body"><div class="font-weight-bold mb-2">旧技术分成核对 · <?php echo e($row['order_no']); ?> · 新分成 ¥<?php echo money($row['commission_amount']); ?></div><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="save_reconciliation"><input type="hidden" name="period" value="<?php echo e($month); ?>"><input type="hidden" name="snapshot_id" value="<?php echo (int)$row['id']; ?>"><div class="form-row"><div class="form-group col-md-2"><label>旧分成金额</label><input name="legacy_amount" type="number" min="0" step="0.01" class="form-control" value="<?php echo $row['reconciliation_id'] ? e($row['legacy_amount']) : ''; ?>" required></div><div class="form-group col-md-2"><label>旧结算月份</label><input name="legacy_salary_month" type="month" class="form-control" value="<?php echo e($row['legacy_salary_month'] ?? ''); ?>"><small class="text-muted">金额大于零时必填</small></div><div class="form-group col-md-6"><label>核对依据／零元原因</label><input name="basis_note" maxlength="500" class="form-control" value="<?php echo e($row['basis_note'] ?? ''); ?>" placeholder="如旧订单号、旧算法明细；或未计入旧系统" required></div><div class="form-group col-md-2 d-flex align-items-end"><button class="btn btn-outline-primary btn-block">保存核对</button></div></div></div></form>
<?php endforeach; endif; ?>
<p class="text-muted small mt-3">订单分成按审核快照计算；固定服务费、考勤、客服绩效、保险和其他奖扣沿用原有结算记录。</p>
<?php else: ?>
<div class="card"><div class="card-header">合作人员月度项目结算概览</div><div class="table-responsive"><table class="table mb-0"><thead><tr><th>合作人员</th><th>部门</th><th>项目分成记录数</th><th class="text-right">新增项目分成</th><th class="text-right">旧技术分成核销</th><th class="text-right">原系统已结算金额</th><th class="text-right">预计应结算金额</th><th></th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td><?php echo e($row['name']); ?> <small class="text-muted">ID <?php echo (int)$row['employee_id']; ?></small></td><td><?php echo e($row['department']); ?></td><td><?php echo (int)$row['order_count']; ?></td><td class="text-right">¥<?php echo money($row['amount']); ?></td><td class="text-right">− ¥<?php echo money($row['legacy_deduction']); ?></td><td class="text-right"><?php echo $row['legacy_net_pay'] === null ? '未结算' : '¥' . money($row['legacy_net_pay']); ?></td><td class="text-right"><?php $previewAmount = ps_settlement_preview($row['legacy_net_pay'], $row['amount'], $row['legacy_deduction'], $row['pending_reconciliations'] === 0); echo $previewAmount === null ? ($row['pending_reconciliations'] ? '待技术分成核对' : '待原系统结算') : '¥' . money($previewAmount); ?></td><td><a href="<?php echo BASE_URL; ?>/project/payroll.php?month=<?php echo urlencode($month); ?>&employee_id=<?php echo (int)$row['employee_id']; ?>">查看结算单</a></td></tr><?php endforeach; ?><?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">暂无合作人员</td></tr><?php endif; ?></tbody></table></div></div>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
