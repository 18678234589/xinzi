<?php
require_once __DIR__ . '/../includes/ProjectSettlement.php';
require_once __DIR__ . '/../includes/ProjectMonthly.php';
require_once __DIR__ . '/../includes/ProjectPayrollOrderSummary.php';
require_once __DIR__ . '/../includes/ProjectImportReconciliation.php';
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
    $monthlyBreak = [];
    foreach (ps_monthly_results($month) as $row) {
        $eid = (int)$row['employee_id'];
        $key = !empty($row['paid_separately']) ? 'separate' : (in_array($row['rule_type'], ['base_fee', 'attendance_bonus'], true) ? $row['rule_type'] : 'other');
        $monthlyBreak[$eid][$key] = ($monthlyBreak[$eid][$key] ?? 0) + (float)$row['amount'];
        if (empty($row['paid_separately'])) $monthlyByEmployee[$eid] = ($monthlyByEmployee[$eid] ?? 0) + (float)$row['amount'];
    }
    $usernames = [];
    foreach (db()->query('SELECT employee_id,username FROM project_users WHERE employee_id IS NOT NULL')->fetchAll() as $u) $usernames[(int)$u['employee_id']] = $u['username'];
    $legacyQuery = db()->prepare('SELECT employee_id,net_pay FROM salaries WHERE month=? ORDER BY id DESC');
    $legacyQuery->execute([$month]);
    $legacyByEmployee = [];
    foreach ($legacyQuery->fetchAll() as $row) if (!array_key_exists((int)$row['employee_id'], $legacyByEmployee)) $legacyByEmployee[(int)$row['employee_id']] = $row['net_pay'];
    // 还没审核的订单不计入应结算（分成按审核快照、月度营业额按已审核收入）：概览里单独提示，避免“有单子却显示最低档”
    $unreviewedByEmployee = [];
    $unreviewedQuery = db()->prepare("SELECT p.employee_id, COUNT(DISTINCT o.id) n, COALESCE(SUM(o.contract_amount),0) amt FROM project_orders o JOIN project_participants p ON p.order_id=o.id WHERE o.order_date>=? AND o.order_date<? AND o.settlement_status NOT IN ('approved','locked') GROUP BY p.employee_id");
    $unreviewedQuery->execute([$month . '-01', date('Y-m-d', strtotime($month . '-01 +1 month'))]);
    foreach ($unreviewedQuery->fetchAll() as $row) $unreviewedByEmployee[(int)$row['employee_id']] = $row;
    foreach ($employees as $employee) {
        $eid = (int)$employee['id'];
        $rows[] = ['employee_id' => $eid, 'unreviewed_n' => (int)($unreviewedByEmployee[$eid]['n'] ?? 0), 'unreviewed_amt' => (float)($unreviewedByEmployee[$eid]['amt'] ?? 0), 'name' => $employee['name'], 'department' => $employee['department'],
            'order_count' => (int)($commissionsByEmployee[$eid]['order_count'] ?? 0) + (int)($adjustByEmployee[$eid]['cnt'] ?? 0),
            'amount' => round((float)($commissionsByEmployee[$eid]['amount'] ?? 0) + (float)($adjustByEmployee[$eid]['amount'] ?? 0) + (float)($monthlyByEmployee[$eid] ?? 0), 2),
            'legacy_net_pay' => $legacyByEmployee[$eid] ?? null,
            'pending_reconciliations' => (int)($commissionsByEmployee[$eid]['pending_reconciliations'] ?? 0),
            'legacy_deduction' => (float)($commissionsByEmployee[$eid]['legacy_deduction'] ?? 0),
            'base_fee' => round((float)($monthlyBreak[$eid]['base_fee'] ?? 0), 2), 'attendance_bonus' => round((float)($monthlyBreak[$eid]['attendance_bonus'] ?? 0), 2),
            'share' => round((float)($commissionsByEmployee[$eid]['amount'] ?? 0) + (float)($adjustByEmployee[$eid]['amount'] ?? 0), 2), 'other' => round((float)($monthlyBreak[$eid]['other'] ?? 0), 2),
            'separate' => round((float)($monthlyBreak[$eid]['separate'] ?? 0), 2), 'username' => $usernames[$eid] ?? ''];
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
// 缺少有效考勤时显示待核对，已有考勤自动核算全勤奖。
$attendancePending = null;
foreach ($monthlyItems as $item) if ($item['rule_type'] === 'attendance_bonus' && (float)$item['amount'] == 0 && strpos((string)$item['detail'], '待核对考勤') === 0) $attendancePending = $item['detail'];
$total = $totalCents / 100;
$technicalTotal = 0.0;
$customerServiceTotal = 0.0;
if ($employeeId > 0) foreach (array_merge($rows, $adjustRows) as $row) {
    $value = (float)($row['commission_amount'] ?? $row['amount']);
    if ($row['commission_group'] === 'technical') $technicalTotal += $value;
    if ($row['commission_group'] === 'customer_service') $customerServiceTotal += $value;
}
$reconciliation = $employeeId > 0 ? ps_technical_reconciliation_summary($rows) : ['pending' => 0, 'deduction' => 0];
$monthShift = function ($m, $delta) { return date('Y-m', strtotime($m . '-01 ' . ($delta >= 0 ? '+' : '') . $delta . ' month')); };
$trend = [];
if ($employeeId > 0) {
    // 近 6 个月应结算（逐单分成 + 售后调整 + 月度规则，不含另行支付），点击可切换月份
    $snapTrend = db()->prepare('SELECT payroll_month,SUM(commission_amount) FROM project_commission_snapshots WHERE employee_id=? AND payroll_month BETWEEN ? AND ? GROUP BY payroll_month');
    $adjTrend = db()->prepare('SELECT payroll_month,SUM(amount) FROM project_commission_adjustments WHERE employee_id=? AND payroll_month BETWEEN ? AND ? GROUP BY payroll_month');
    $from = $monthShift($month, -5);
    $snapTrend->execute([$employeeId, $from, $month]); $snapByMonth = $snapTrend->fetchAll(PDO::FETCH_KEY_PAIR);
    $adjTrend->execute([$employeeId, $from, $month]); $adjByMonth = $adjTrend->fetchAll(PDO::FETCH_KEY_PAIR);
    for ($i = -5; $i <= 0; $i++) {
        $m = $monthShift($month, $i);
        $sum = (float)($snapByMonth[$m] ?? 0) + (float)($adjByMonth[$m] ?? 0);
        if ($m === $month) $sum = $total;
        else foreach (ps_monthly_results($m) as $r) if ((int)$r['employee_id'] === $employeeId && empty($r['paid_separately'])) $sum += (float)$r['amount'];
        $trend[$m] = round($sum, 2);
    }
}
$prevEmployee = $nextEmployee = null;
if ($actor['role'] === 'finance' && $employeeId > 0) foreach ($employees as $i => $emp) if ((int)$emp['id'] === $employeeId) { $prevEmployee = $employees[$i - 1] ?? null; $nextEmployee = $employees[$i + 1] ?? null; }
$settlementPreview = ps_settlement_preview($legacySalary['net_pay'] ?? null, $total, $reconciliation['deduction'], $reconciliation['pending'] === 0);
$page_title = $actor['role'] === 'finance' ? '项目报酬结算中心' : '我的项目报酬';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3"><h4 class="mb-0"><?php echo e($page_title); ?></h4><div><?php if ($actor['role'] === 'finance'): ?><a class="btn btn-primary btn-sm mr-2" href="<?php echo BASE_URL; ?>/attendance/month.php?year=<?php echo (int)substr($month, 0, 4); ?>&amp;month=<?php echo (int)substr($month, 5, 2); ?>#home-roster">考勤 / 居家名单 · 自动核算全勤</a><?php endif; ?><a class="btn btn-outline-success btn-sm mr-2" href="<?php echo BASE_URL; ?>/project/payroll.php?<?php echo e(http_build_query(['month' => $month, 'employee_id' => $employeeId, 'export' => 'project_csv'])); ?>">导出项目分成 CSV</a><a class="btn btn-outline-secondary btn-sm" href="<?php echo BASE_URL; ?>/project/index.php">返回项目订单</a></div></div>
<?php if ($periodError): ?><div class="alert alert-danger"><?php echo e($periodError); ?></div><?php endif; ?>
<?php if (isset($_GET['locked'])): ?><div class="alert alert-success">该月项目分成已锁定。</div><?php endif; ?>
<?php if (isset($_GET['reconciled'])): ?><div class="alert alert-success">技术旧分成核对记录已保存。</div><?php endif; ?>
<?php $qs = function ($over) use ($month, $employeeId) { return BASE_URL . '/project/payroll.php?' . http_build_query(array_merge(['month' => $month, 'employee_id' => $employeeId ?: null], $over)); }; ?>
<div class="card mb-3 project-payroll-bar"><div class="card-body"><form method="get" class="form-row align-items-end" id="payrollFilter">
  <div class="form-group col-12 col-md-4"><label>结算月份</label><div class="input-group"><div class="input-group-prepend"><a class="btn btn-outline-secondary" href="<?php echo e($qs(['month' => $monthShift($month, -1)])); ?>" aria-label="上个月">‹</a></div><input type="month" class="form-control" name="month" value="<?php echo e($month); ?>" required onchange="this.form.submit()"><div class="input-group-append"><a class="btn btn-outline-secondary" href="<?php echo e($qs(['month' => $monthShift($month, 1)])); ?>" aria-label="下个月">›</a></div></div></div>
  <?php if ($actor['role'] === 'finance'): ?>
  <div class="form-group col-12 col-md-5"><label>查某个人（输入姓名 / 拼音 / 部门）</label><input class="form-control" id="personPicker" list="personList" placeholder="例如 张欣、zhangxin、设计" autocomplete="off" value="<?php echo $selectedEmployee ? e($selectedEmployee['name'] . ' · ' . $selectedEmployee['department']) : ''; ?>"><input type="hidden" name="employee_id" id="personId" value="<?php echo (int)$employeeId; ?>"><datalist id="personList"><?php foreach ($employees as $emp): ?><option data-id="<?php echo (int)$emp['id']; ?>" value="<?php echo e($emp['name'] . ' · ' . $emp['department']); ?>"><?php endforeach; ?></datalist></div>
  <div class="form-group col-12 col-md-3 d-flex" style="gap:6px"><button class="btn btn-primary flex-fill">查询</button><?php if ($employeeId > 0): ?><a class="btn btn-outline-secondary flex-fill" href="<?php echo e($qs(['employee_id' => null])); ?>">全员概览</a><?php endif; ?></div>
  <?php else: ?><div class="form-group col-12 col-md-2"><button class="btn btn-primary btn-block">查询</button></div><?php endif; ?>
</form>
<?php if ($prevEmployee || $nextEmployee): ?><div class="d-flex justify-content-between small mt-1"><span><?php if ($prevEmployee): ?><a href="<?php echo e($qs(['employee_id' => (int)$prevEmployee['id']])); ?>">‹ <?php echo e($prevEmployee['name']); ?></a><?php endif; ?></span><span><?php if ($nextEmployee): ?><a href="<?php echo e($qs(['employee_id' => (int)$nextEmployee['id']])); ?>"><?php echo e($nextEmployee['name']); ?> ›</a><?php endif; ?></span></div><?php endif; ?>
</div></div>
<script>
(function () {
  var picker = document.getElementById('personPicker'); if (!picker) return;
  var hidden = document.getElementById('personId'), options = document.querySelectorAll('#personList option');
  picker.addEventListener('change', function () {
    var v = picker.value.trim(); hidden.value = '0';
    options.forEach(function (o) { if (o.value === v) hidden.value = o.dataset.id; });
    if (hidden.value !== '0') picker.form.submit();
  });
})();
</script>
<?php if ($employeeId > 0 && $trend): $maxTrend = max(array_map('abs', $trend)) ?: 1; ?>
<div class="card mb-3"><div class="card-header">近 6 个月应结算 · <?php echo e($selectedEmployee['name']); ?></div><div class="card-body"><div class="project-trend"><?php foreach ($trend as $m => $v): ?><a class="<?php echo $m === $month ? 'is-current' : ''; ?>" href="<?php echo e($qs(['month' => $m])); ?>"><span class="project-trend-bar" style="height:<?php echo max(4, round(abs($v) / $maxTrend * 100)); ?>%"></span><strong>¥<?php echo money($v); ?></strong><small><?php echo e(substr($m, 2)); ?></small></a><?php endforeach; ?></div></div></div>
<?php endif; ?>
<div class="alert alert-<?php echo $period['status'] === 'locked' ? 'secondary' : 'info'; ?>">项目分成月份 <?php echo e($month); ?>：<?php echo $period['status'] === 'locked' ? '已锁定（' . e($period['locked_at']) . '）' : '未锁定'; ?>。此状态仅锁定项目分成，不代表项目报酬已发放。</div>
<?php if ($actor['role'] === 'finance' && $employeeId === 0 && $period['status'] !== 'locked'): ?><form method="post" class="text-right mb-3" onsubmit="return confirm('确认该月项目分成已核对？锁定后新订单不能计入本月。')"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="lock_period"><input type="hidden" name="period" value="<?php echo e($month); ?>"><button class="btn btn-outline-danger btn-sm">锁定本月项目分成</button></form><?php endif; ?>
<?php
// 算法明细：拆解应结算合计的构成（另行支付不计入）
$orderRegistrationSummary = ps_payroll_order_summary($employeeId, $month);
ps_payroll_order_summary_render($orderRegistrationSummary);
ps_payroll_import_reconciliation_render(ps_payroll_import_reconciliation($employeeId, $month, $actor));
$algoLines = [];
// 待审核订单：明细里保留项目行（金额 0、不计入合计），避免未通过审核的项目在明细里消失（2026-10-10）。
$pendingBounds = [$month . '-01', date('Y-m-01', strtotime($month . '-01 +1 month'))];
// 跨月未结算单：订单日期在周期外但提交（created_at）在本周期起的，随本周期结算（如 8 月单 9 月周期提交 → 结 9 月，2026-10-12 业务口径）。
$pendingQ = db()->prepare('SELECT o.project_type, COUNT(DISTINCT o.id) n FROM project_orders o JOIN project_participants p ON p.order_id=o.id WHERE o.settlement_status=\'draft\' AND ((o.order_date>=? AND o.order_date<?) OR (o.order_date<? AND o.created_at>=? AND o.created_at<?))' . ($employeeId > 0 ? ' AND p.employee_id=?' : '') . ' GROUP BY o.project_type ORDER BY o.project_type');
$pendingParams = array_merge($pendingBounds, [$pendingBounds[0], $pendingBounds[0], $pendingBounds[1]]);
if ($employeeId > 0) $pendingQ->execute(array_merge($pendingParams, [$employeeId]));
else $pendingQ->execute($pendingParams);
$pendingRows = $pendingQ->fetchAll();
// 单量补贴规则（order_kind='*' 的 is_extra=1 每单补贴，如备案-提成提成表每单 1 元）：待审核占位行用（2026-10-12）。
$wildSub = [];
foreach (db()->query("SELECT project_type,per_order_subsidy,note FROM project_commission_rules WHERE is_extra=1 AND is_active=1 AND order_kind='*' AND per_order_subsidy>0 AND effective_from<='" . date('Y-m-t', strtotime($month . '-01')) . "'") as $wr) {
    $wildSub[$wr['project_type']]['per'] = ($wildSub[$wr['project_type']]['per'] ?? 0) + (float)$wr['per_order_subsidy'];
    $wildSub[$wr['project_type']]['note'] = trim((string)$wr['note']) !== '' ? trim((string)$wr['note']) : '单量补贴';
}
// 单量补贴走系统自动核对：核对通过的自动结算；查不到收款流水／实付异常的转人工审核（2026-10-12 口径）。
$wildAuto = [];
if ($wildSub) {
    $waIn = '(' . implode(',', array_map(function ($t) { return db()->quote($t); }, array_keys($wildSub))) . ')';
    $waQ = db()->prepare("SELECT o.project_type, a.state, COUNT(DISTINCT o.id) n FROM project_orders o LEFT JOIN project_auto_reviews a ON a.order_id=o.id JOIN project_participants p ON p.order_id=o.id WHERE o.settlement_status='draft' AND o.project_type IN " . $waIn . " AND ((o.order_date>=? AND o.order_date<?) OR (o.order_date<? AND o.created_at>=? AND o.created_at<?))" . ($employeeId > 0 ? ' AND p.employee_id=?' : '') . " GROUP BY o.project_type, a.state");
    $waParams = array_merge($pendingBounds, [$pendingBounds[0], $pendingBounds[0], $pendingBounds[1]]);
    if ($employeeId > 0) $waQ->execute(array_merge($waParams, [$employeeId]));
    else $waQ->execute($waParams);
    foreach ($waQ->fetchAll() as $wa) {
        $bucket = $wa['state'] === null ? 'queued' : (in_array((string)$wa['state'], ['auto_passed', 'spot_check', 'settled'], true) ? 'pass' : 'manual');
        $wildAuto[$wa['project_type']][$bucket] = ($wildAuto[$wa['project_type']][$bucket] ?? 0) + (int)$wa['n'];
    }
}
// 单量补贴占位行按自动核对结果拆分（单人 / 全员视图共用，2026-10-12）。
$wildSubLines = function () use ($wildSub, $wildAuto) {
    $lines = [];
    foreach ($wildSub as $type => $w) {
        $pass = (int)($wildAuto[$type]['pass'] ?? 0); $manual = (int)($wildAuto[$type]['manual'] ?? 0); $queued = (int)($wildAuto[$type]['queued'] ?? 0);
        if ($pass > 0) $lines[] = [$type . '单量补贴（自动核对通过 ' . $pass . ' 单 × ¥' . money($w['per']) . '，待结算）', 0.0, $w['note'] . '；系统自动核对已通过，结算落库后随分成快照计入；预期 ¥' . money($pass * $w['per'])];
        if ($manual > 0) $lines[] = [$type . '单量补贴（' . $manual . ' 单 × ¥' . money($w['per']) . '，待人工审核）', 0.0, $w['note'] . '；系统自动核对未通过的订单（收款流水未匹配或实付异常）转人工审核，财务核对收款后再批；预期 ¥' . money($manual * $w['per']) . '，审核通过后随分成快照计入，未审核不计入左列合计'];
        if ($queued > 0) $lines[] = [$type . '单量补贴（' . $queued . ' 单待系统自动核对）', 0.0, $w['note'] . '；系统每 5 分钟自动核对，核对通过自动结算，查不到收款流水的转人工审核；预期 ¥' . money($queued * $w['per']) . '，未审核不计入左列合计'];
    }
    return $lines;
};
// 待审核拍链接补贴：每单 0.5 元只按“建站订单”列有值的单计（拍链接类）；未审核前为 0，仅占位提示预期金额（2026-10-12）。
$linkPer = (float)(db()->query("SELECT per_order_subsidy FROM project_commission_rules WHERE is_extra=1 AND project_type='备案-提成' AND order_kind='拍链接' AND effective_from<='" . date('Y-m-t', strtotime($month . '-01')) . "' ORDER BY effective_from DESC, id DESC LIMIT 1")->fetchColumn() ?: 0);
$linkN = 0;
if ($linkPer > 0) {
    // 同上口径：跨月未结算的拍链接单也计入本周期占位行（17 单 = 16 张 9 月 + 1 张 8 月 28 日，2026-10-12）。
    $linkQ = db()->prepare('SELECT COUNT(DISTINCT o.id) FROM project_orders o JOIN project_participants p ON p.order_id=o.id WHERE o.settlement_status=\'draft\' AND o.project_type=\'备案-提成\' AND o.order_kind=\'拍链接\' AND ((o.order_date>=? AND o.order_date<?) OR (o.order_date<? AND o.created_at>=? AND o.created_at<?))' . ($employeeId > 0 ? ' AND p.employee_id=?' : ''));
    $linkParams = array_merge($pendingBounds, [$pendingBounds[0], $pendingBounds[0], $pendingBounds[1]]);
    if ($employeeId > 0) $linkQ->execute(array_merge($linkParams, [$employeeId]));
    else $linkQ->execute($linkParams);
    $linkN = (int)$linkQ->fetchColumn();
}
if ($employeeId > 0) {
    $algoShare = 0.0; $algoSubsidy = 0.0;
    foreach ($rows as $r) { $algoShare += (float)$r['commission_amount']; $algoSubsidy += (float)($r['subsidy_amount'] ?? 0); }
    $algoAdjust = 0.0;
    foreach ($adjustRows as $a) $algoAdjust += (float)$a['amount'];
    // 算法明细分列：按业务类型拆成「提成行 / 单量行 / 每单补贴行」多条，不再合并成一条「逐单分成快照」（2026-10-10）。
    $ruleIds = [];
    foreach ($rows as $r) $ruleIds[(int)$r['rule_id']] = true;
    $ruleMeta = [];
    if ($ruleIds) {
        $rq = db()->prepare('SELECT id,project_type,rate,per_order_subsidy,is_extra FROM project_commission_rules WHERE id IN (' . implode(',', array_keys($ruleIds)) . ')');
        $rq->execute();
        foreach ($rq->fetchAll() as $m) $ruleMeta[(int)$m['id']] = $m;
    }
    $shareGroups = []; $unitGroups = []; $subGroups = [];
    foreach ($rows as $r) {
        $meta = $ruleMeta[(int)$r['rule_id']] ?? null;
        $type = $meta ? (string)$meta['project_type'] : ('规则 ' . (int)$r['rule_id']);
        $comm = (float)$r['commission_amount'];
        $sub = (float)($r['subsidy_amount'] ?? 0);
        if (!$meta || (float)$meta['rate'] > 0) {
            // 按比例分成的规则：分成部分留业务类型行，每单补助拆到补贴行
            $shareGroups[$type]['n'] = ($shareGroups[$type]['n'] ?? 0) + 1;
            $shareGroups[$type]['amt'] = round(($shareGroups[$type]['amt'] ?? 0) + $comm - $sub, 2);
            if ($sub > 0) {
                $subGroups[$type]['n'] = ($subGroups[$type]['n'] ?? 0) + 1;
                $subGroups[$type]['amt'] = round(($subGroups[$type]['amt'] ?? 0) + $sub, 2);
            }
        } elseif (!empty($meta['is_extra'])) {
            // 额外补贴规则（拍链接 0.5 元等）单独挂补贴行
            $subGroups[$type]['n'] = ($subGroups[$type]['n'] ?? 0) + 1;
            $subGroups[$type]['amt'] = round(($subGroups[$type]['amt'] ?? 0) + $comm, 2);
        } else {
            // rate=0 按单量计酬（备案-单量等）：整单金额算业务类型行，不进补贴行
            $unitGroups[$type]['n'] = ($unitGroups[$type]['n'] ?? 0) + 1;
            $unitGroups[$type]['amt'] = round(($unitGroups[$type]['amt'] ?? 0) + $comm, 2);
            if ((float)$meta['per_order_subsidy'] > 0) $unitGroups[$type]['per'] = (float)$meta['per_order_subsidy'];
        }
    }
    ksort($shareGroups); ksort($unitGroups); ksort($subGroups);
    foreach ($shareGroups as $type => $g) {
        $algoLines[] = [$type . '（' . (int)$g['n'] . ' 单）', $g['amt'], '规则中心按比例分成：每单 =（可结算收入 − 直接成本 − 服务费）× 分成比例 × 本人权重，服务费为合同额 × 3%' . ($subGroups ? '；每单补助已拆到「每单补贴金额」行' : '')];
    }
    foreach ($unitGroups as $type => $g) {
        $algoLines[] = [$type . '（' . (int)$g['n'] . ' 单' . (!empty($g['per']) ? ' × ¥' . money($g['per']) : '') . '）', $g['amt'], '规则中心按单量计酬，每单固定金额，已含在左列金额'];
    }
    if ($subGroups) {
        $subParts = []; $subTotal = 0.0; $subN = 0;
        foreach ($subGroups as $type => $g) {
            $subParts[] = $type . ' ¥' . money($g['amt']) . '（' . (int)$g['n'] . ' 单）';
            $subTotal += $g['amt'];
            $subN += (int)$g['n'];
        }
        $algoLines[] = ['每单补贴金额（' . (int)$subN . ' 单）', round($subTotal, 2), '规则中心每单补助（拍链接 0.5 元、单量规则外补贴等），来源：' . implode('、', $subParts) . '；已从对应分成行拆出单列'];
    }
    foreach ($pendingRows as $pending) {
        $algoLines[] = [$pending['project_type'] . '（' . (int)$pending['n'] . ' 单待审核）', 0.0, ps_payroll_pending_reason_text($orderRegistrationSummary, $pending['project_type'])];
    }
    foreach ($wildSubLines() as $wildLine) $algoLines[] = $wildLine;
    if ($linkN > 0 && $linkPer > 0) $algoLines[] = ['拍链接补贴（' . $linkN . ' 单 × ¥' . money($linkPer) . '，待审核）', 0.0, '拍链接每单 ' . money($linkPer) . ' 元补贴只按“建站订单”列有值的单计；预期 ¥' . money($linkN * $linkPer) . '，审核通过后随分成快照计入，未审核不计入左列合计'];
    if ($adjustRows) $algoLines[] = ['售后调整（' . count($adjustRows) . ' 条）', $algoAdjust, '退款扣回 / 补发，按订单逐条调整后计入'];
    foreach ($monthlyItems as $item) if (empty($item['paid_separately'])) $algoLines[] = [$item['rule_name'], (float)$item['amount'], (string)$item['detail']];
} else {
    $algoShare = 0.0; $algoBase = 0.0; $algoAttend = 0.0; $algoOther = 0.0;
    foreach ($rows as $r) {
        $algoShare += (float)$r['share']; $algoBase += (float)$r['base_fee'];
        $algoAttend += (float)$r['attendance_bonus']; $algoOther += (float)$r['other'];
    }
    $algoLines[] = ['逐单分成 + 售后调整', $algoShare, '全员逐单分成快照（收入 − 成本 − 服务费后计提）与售后调整合计'];
    if ($pendingRows) {
        $pendingParts = [];
        foreach ($pendingRows as $pending) $pendingParts[] = $pending['project_type'] . ' ' . (int)$pending['n'] . ' 单';
        $algoLines[] = ['待审核项目（' . implode('、', $pendingParts) . '）', 0.0, ps_payroll_pending_reason_text($orderRegistrationSummary)];
    }
    foreach ($wildSubLines() as $wildLine) $algoLines[] = $wildLine;
    if ($linkN > 0 && $linkPer > 0) $algoLines[] = ['拍链接补贴（' . $linkN . ' 单 × ¥' . money($linkPer) . '，待审核）', 0.0, '拍链接每单 ' . money($linkPer) . ' 元补贴只按“建站订单”列有值的单计；预期 ¥' . money($linkN * $linkPer) . '，审核通过后随分成快照计入，未审核不计入左列合计'];
    $algoLines[] = ['固定服务费', $algoBase, '按规则中心配置 × 考勤折算'];
    $algoLines[] = ['全勤奖', $algoAttend, '按考勤，须经财务批准'];
    $algoLines[] = ['月度奖励与补助', $algoOther, '规则中心月度规则（不含另行支付）合计'];
}
?>
<div class="card mb-3"><div class="card-body"><div class="row align-items-center"><div class="col-md-8"><div class="text-muted small"><?php echo $selectedEmployee ? e($selectedEmployee['name'] . ' · ' . $selectedEmployee['department']) : '全部合作人员'; ?> · <?php echo e($month); ?></div><h3 class="mb-0">应结算合计 ¥<?php echo money($total); ?></h3></div><div class="col-md-4 text-md-right text-muted small">逐单分成快照 + 售后调整 + 月度规则<?php echo $period['status'] === 'locked' ? '（已冻结）' : '（月度规则实时计算）'; ?></div></div>
<details class="mt-2"><summary class="small text-primary" style="cursor:pointer;user-select:none">算法明细（点击展开）</summary>
<div class="table-responsive mt-2"><table class="table table-sm table-bordered mb-0 bg-white"><thead><tr><th>项目</th><th class="text-right">金额</th><th>算法</th></tr></thead><tbody>
<?php foreach ($algoLines as $algoLine): ?><tr><td><?php echo e($algoLine[0]); ?></td><td class="text-right font-weight-bold <?php echo $algoLine[1] < 0 ? 'text-danger' : ''; ?>">¥<?php echo money($algoLine[1]); ?></td><td class="small text-muted"><?php echo e($algoLine[2]); ?></td></tr><?php endforeach; ?>
<tr class="table-light"><td class="font-weight-bold">应结算合计</td><td class="text-right font-weight-bold">¥<?php echo money($total); ?></td><td class="small text-muted">以上计入项之和<?php echo $separateTotal > 0 ? '；另行支付 ¥' . money($separateTotal) . ' 单独发放、不计入' : ''; ?></td></tr>
</tbody></table></div>
</details>
</div></div>
<?php if ($employeeId > 0): ?>
<?php if ($hasFullSlip): $orderShare = 0.0; foreach ($rows as $r) $orderShare += (float)$r['commission_amount']; $adjustShare = 0.0; foreach ($adjustRows as $a) $adjustShare += (float)$a['amount']; ?>
<div class="card mb-3 project-slip"><div class="card-header d-flex justify-content-between flex-wrap"><span>项目报酬结算单 · <?php echo e($selectedEmployee['name']); ?> · <?php echo e($month); ?></span><span class="text-muted small"><?php echo $period['status'] === 'locked' ? '已锁定' : '实时计算，锁月后冻结'; ?></span></div><div class="card-body">
<div class="project-slip-grid">
<div><small>固定服务费（按考勤）</small><strong>¥<?php echo money($slip['base_fee']); ?></strong></div>
<div><small>全勤奖</small><strong>¥<?php echo money($slip['attendance_bonus']); ?></strong><?php if ($attendancePending): ?><span class="d-block small text-warning" title="<?php echo e($attendancePending); ?>">待核对考勤</span><?php endif; ?></div>
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
<div class="card"><div class="card-header">逐单项目分成明细</div><div class="table-responsive"><table class="table table-hover mb-0 project-stack-table"><thead><tr><th>订单</th><th>客户</th><th>组别 / 岗位</th><th class="text-right">收入</th><th class="text-right">直接成本</th><th class="text-right">服务费</th><th class="text-right">计提基数</th><th class="text-right">比例</th><th class="text-right">本人权重</th><th class="text-right">本人项目分成</th><th>旧技术分成核对</th></tr></thead><tbody>
<?php foreach ($rows as $row): ?><tr><td><a href="<?php echo BASE_URL; ?>/project/order.php?id=<?php echo (int)$row['order_id']; ?>"><?php echo e($row['order_no']); ?></a></td><td><?php echo e($row['customer_name']); ?></td><td><?php echo $row['commission_group'] === 'technical' ? '技术' : '客服'; ?><?php if (($row['role_name'] ?? '') !== ''): ?><div class="small text-muted"><?php echo e($row['role_name']); ?></div><?php endif; ?></td><td class="text-right">¥<?php echo money($row['income_amount']); ?></td><td class="text-right">¥<?php echo money($row['direct_cost']); ?></td><td class="text-right">¥<?php echo money($row['service_fee'] ?? 0); ?></td><td class="text-right">¥<?php echo money($row['contribution_profit']); ?></td><td class="text-right"><?php echo money($row['rate'] * 100); ?>%</td><td class="text-right"><?php echo money($row['group_weight'] * 100); ?>%</td><td class="text-right font-weight-bold">¥<?php echo money($row['commission_amount']); ?><?php if ((float)($row['subsidy_amount'] ?? 0) > 0): ?><div class="small text-muted font-weight-normal">含每单补助 ¥<?php echo money($row['subsidy_amount']); ?></div><?php endif; ?><?php if (($row['calc_note'] ?? '') !== ''): ?><div class="small text-muted font-weight-normal"><?php echo e($row['calc_note']); ?></div><?php endif; ?></td><td><?php if ($row['commission_group'] === 'technical'): ?><?php if ($row['reconciliation_id']): ?>旧分成 ¥<?php echo money($row['legacy_amount']); ?><?php if ($row['legacy_salary_month']): ?>（<?php echo e($row['legacy_salary_month']); ?>）<?php endif; ?><br><small class="text-muted"><?php echo e($row['basis_note']); ?></small><?php else: ?><span class="text-warning">待财务核对</span><?php endif; ?><?php else: ?>客服原绩效叠加<?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="10" class="text-center text-muted py-4">该月暂无已审核项目分成</td></tr><?php endif; ?></tbody></table></div></div>
<?php if ($monthlyItems): ?><div class="card mt-3"><div class="card-header d-flex justify-content-between"><span>月度规则（已计入上方合计 ¥<?php echo money($monthlyTotal); ?>）</span><a class="small" href="<?php echo BASE_URL; ?>/project/rules.php?month=<?php echo e($month); ?>#monthly">在规则中心调整</a></div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>规则</th><th class="text-right">金额</th><th>计算过程</th></tr></thead><tbody><?php foreach ($monthlyItems as $item): ?><tr class="<?php echo !empty($item['paid_separately']) ? 'text-muted' : ''; ?>"><td><?php echo e($item['rule_name']); ?><?php echo !empty($item['paid_separately']) ? ' <span class="badge badge-secondary">另行支付</span>' : ''; ?></td><td class="text-right font-weight-bold <?php echo (float)$item['amount'] < 0 ? 'text-danger' : ''; ?>">¥<?php echo money($item['amount']); ?></td><td class="small text-muted"><?php echo e($item['detail']); ?></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>
<?php if ($adjustRows): ?><div class="card mt-3"><div class="card-header">售后调整（退款扣回 / 补发，已计入上方合计）</div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>订单</th><th>分成组</th><th class="text-right">调整金额</th><th>原因 / 计算</th></tr></thead><tbody><?php foreach ($adjustRows as $adj): ?><tr><td><a href="<?php echo BASE_URL; ?>/project/order.php?id=<?php echo (int)$adj['order_id']; ?>"><?php echo e($adj['order_no']); ?></a></td><td><?php echo $adj['commission_group'] === 'technical' ? '技术' : '客服'; ?></td><td class="text-right font-weight-bold <?php echo (float)$adj['amount'] < 0 ? 'text-danger' : 'text-success'; ?>"><?php echo ((float)$adj['amount'] > 0 ? '+' : '') . '¥' . money($adj['amount']); ?></td><td class="small"><?php echo e($adj['reason']); ?><div class="text-muted"><?php echo e($adj['calc_note']); ?></div></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>
<?php if ($actor['role'] === 'finance' && $period['status'] !== 'locked'): foreach ($rows as $row): if ($row['commission_group'] !== 'technical') continue; ?>
<form method="post" class="card mt-2"><div class="card-body"><div class="font-weight-bold mb-2">旧技术分成核对 · <?php echo e($row['order_no']); ?> · 新分成 ¥<?php echo money($row['commission_amount']); ?></div><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="save_reconciliation"><input type="hidden" name="period" value="<?php echo e($month); ?>"><input type="hidden" name="snapshot_id" value="<?php echo (int)$row['id']; ?>"><div class="form-row"><div class="form-group col-md-2"><label>旧分成金额</label><input name="legacy_amount" type="number" min="0" step="0.01" class="form-control" value="<?php echo $row['reconciliation_id'] ? e($row['legacy_amount']) : ''; ?>" required></div><div class="form-group col-md-2"><label>旧结算月份</label><input name="legacy_salary_month" type="month" class="form-control" value="<?php echo e($row['legacy_salary_month'] ?? ''); ?>"><small class="text-muted">金额大于零时必填</small></div><div class="form-group col-md-6"><label>核对依据／零元原因</label><input name="basis_note" maxlength="500" class="form-control" value="<?php echo e($row['basis_note'] ?? ''); ?>" placeholder="如旧订单号、旧算法明细；或未计入旧系统" required></div><div class="form-group col-md-2 d-flex align-items-end"><button class="btn btn-outline-primary btn-block">保存核对</button></div></div></div></form>
<?php endforeach; endif; ?>
<p class="text-muted small mt-3">订单分成按审核快照计算；固定服务费、考勤、客服绩效、保险和其他奖扣沿用原有结算记录。</p>
<?php else: ?>
<?php $departments = array_values(array_unique(array_filter(array_column($rows, 'department')))); ?>
<div class="card"><div class="card-header d-flex justify-content-between align-items-center flex-wrap" style="gap:8px"><span>全员收入概览 · <?php echo e($month); ?></span><span class="small text-muted" id="overviewSummary"></span></div>
<div class="card-body pb-0"><div class="form-row">
  <div class="form-group col-12 col-md-4"><input type="search" class="form-control" id="ovSearch" placeholder="搜姓名 / 拼音登录名 / 部门" aria-label="搜索合作人员"></div>
  <div class="form-group col-6 col-md-3"><select class="form-control" id="ovDept" aria-label="部门"><option value="">全部部门</option><?php foreach ($departments as $d): ?><option value="<?php echo e($d); ?>"><?php echo e($d); ?></option><?php endforeach; ?></select></div>
  <div class="form-group col-6 col-md-3"><select class="form-control" id="ovSort" aria-label="排序"><option value="total">应结算从高到低</option><option value="share">项目分成从高到低</option><option value="dept">按部门</option><option value="name">按姓名</option></select></div>
  <div class="form-group col-12 col-md-2 d-flex align-items-center"><label class="mb-0"><input type="checkbox" id="ovNonzero" checked> 只看有收入</label></div>
</div></div>
<div class="table-responsive"><table class="table table-hover mb-0 project-stack-table" id="overviewTable"><thead><tr><th>合作人员</th><th class="text-right">固定服务费</th><th class="text-right">全勤</th><th class="text-right">项目分成</th><th class="text-right">奖励补助</th><th class="text-right">应结算</th><th class="text-right">另行支付</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $row): ?><tr data-name="<?php echo e(mb_strtolower($row['name'] . ' ' . $row['username'] . ' ' . $row['department'])); ?>" data-dept="<?php echo e($row['department']); ?>" data-total="<?php echo e($row['amount']); ?>" data-share="<?php echo e($row['share']); ?>" data-sep="<?php echo e($row['separate']); ?>" data-sortname="<?php echo e($row['name']); ?>" onclick="if(!event.target.closest('a'))location.href=this.querySelector('a').href" style="cursor:pointer">
  <td data-label="合作人员"><a href="<?php echo e($qs(['employee_id' => (int)$row['employee_id']])); ?>"><strong><?php echo e($row['name']); ?></strong></a><div class="small text-muted"><?php echo e($row['department']); ?><?php echo $row['username'] !== '' ? ' · ' . e($row['username']) : ''; ?></div></td>
  <td data-label="固定服务费" class="text-right">¥<?php echo money($row['base_fee']); ?></td>
  <td data-label="全勤" class="text-right">¥<?php echo money($row['attendance_bonus']); ?></td>
  <td data-label="项目分成" class="text-right">¥<?php echo money($row['share']); ?><?php if ((int)$row['order_count']): ?><div class="small text-muted"><?php echo (int)$row['order_count']; ?> 条</div><?php endif; ?></td>
  <td data-label="奖励补助" class="text-right">¥<?php echo money($row['other']); ?></td>
  <td data-label="应结算" class="text-right font-weight-bold">¥<?php echo money($row['amount']); ?><?php if (!empty($row['unreviewed_n'])): ?><div class="small font-weight-normal"><a class="text-warning" href="<?php echo BASE_URL; ?>/project/batch_review.php" title="没审核的订单不计入应结算；审核通过后才会计入，按营业额落档的还会重新落档。点这里去审核申请"><?php echo (int)$row['unreviewed_n']; ?> 单 ¥<?php echo money($row['unreviewed_amt']); ?> 未审核 ›</a></div><?php endif; ?></td>
  <td data-label="另行支付" class="text-right text-muted"><?php echo $row['separate'] > 0 ? '¥' . money($row['separate']) : '—'; ?></td>
  <td class="text-right"><span class="btn btn-sm btn-outline-primary">明细</span></td>
</tr><?php endforeach; ?>
</tbody></table></div>
<div class="card-body py-2 small text-muted" id="overviewEmpty" hidden>没有符合条件的合作人员</div></div>
<script>
(function () {
  var table = document.getElementById('overviewTable'); if (!table) return;
  var body = table.tBodies[0], rows = Array.prototype.slice.call(body.rows);
  var search = document.getElementById('ovSearch'), dept = document.getElementById('ovDept'), sort = document.getElementById('ovSort'), nonzero = document.getElementById('ovNonzero');
  var money = function (n) { return '¥' + n.toLocaleString('zh-CN', {minimumFractionDigits: 2, maximumFractionDigits: 2}); };
  function apply() {
    var k = search.value.trim().toLowerCase(), d = dept.value, total = 0, sep = 0, n = 0;
    rows.sort(function (a, b) {
      if (sort.value === 'dept') return a.dataset.dept.localeCompare(b.dataset.dept, 'zh') || a.dataset.sortname.localeCompare(b.dataset.sortname, 'zh');
      if (sort.value === 'name') return a.dataset.sortname.localeCompare(b.dataset.sortname, 'zh');
      return Number(b.dataset[sort.value === 'share' ? 'share' : 'total']) - Number(a.dataset[sort.value === 'share' ? 'share' : 'total']);
    }).forEach(function (tr) {
      var show = (!k || tr.dataset.name.indexOf(k) !== -1) && (!d || tr.dataset.dept === d) && (!nonzero.checked || Number(tr.dataset.total) !== 0 || Number(tr.dataset.sep) !== 0);
      tr.hidden = !show; body.appendChild(tr);
      if (show) { n++; total += Number(tr.dataset.total); sep += Number(tr.dataset.sep); }
    });
    document.getElementById('overviewSummary').textContent = n + ' 人 · 应结算合计 ' + money(total) + (sep ? ' · 另行支付 ' + money(sep) : '');
    document.getElementById('overviewEmpty').hidden = n > 0;
  }
  [search, dept, sort, nonzero].forEach(function (el) { el.addEventListener(el === search ? 'input' : 'change', apply); });
  apply();
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
