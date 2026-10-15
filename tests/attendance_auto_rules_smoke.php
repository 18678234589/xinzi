<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectMonthly.php';
$pdo = db();
$pdo->beginTransaction();
try {
    $month = '2099-11';
    $check = function ($ok, $label) { if (!$ok) throw new RuntimeException($label); echo "PASS $label\n"; };
    $q = $pdo->prepare('SELECT COUNT(*) FROM project_payroll_periods WHERE period=?');
    $q->execute([$month]);
    $check((int)$q->fetchColumn() === 0, '保留测试月未占用');
    $pdo->prepare("INSERT INTO project_payroll_periods (period,status) VALUES (?,'draft')")->execute([$month]);
    $adminId = (int)$pdo->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetchColumn();
    $people = [];
    foreach (['网站客服', '网站技术'] as $i => $department) {
        $tag = 'att_auto_' . bin2hex(random_bytes(5)) . $i;
        $pdo->prepare('INSERT INTO employees (name,department,password) VALUES (?,?,?)')->execute([$tag, $department, bin2hex(random_bytes(16))]);
        $eid = (int)$pdo->lastInsertId();
        $people[] = $eid;
        $pdo->prepare('INSERT INTO attendances (employee_id,year,month,work_hours,absent_hours,remark) VALUES (?,2099,11,208,0,?)')->execute([$eid, '']);
    }
    $check(ps_monthly_ensure_attendance_rules($month, $adminId) === 2 && ps_monthly_ensure_attendance_rules($month, $adminId) === 0 && $pdo->inTransaction(), '补齐两人规则、重复调用不重复、保留外层事务');
    $rules = array_values(array_filter(ps_monthly_rules_for($month), function ($r) use ($people) { return $r['rule_type'] === 'attendance_bonus' && in_array((int)$r['employee_id'], $people, true); }));
    $amounts = array_column($rules, 'params', 'employee_id');
    $check((float)$amounts[$people[0]]['amount'] === 100.0 && (float)$amounts[$people[1]]['amount'] === 200.0, '实际数据库部门标准正确');
    $pdo->prepare('UPDATE attendances SET remark=? WHERE employee_id=? AND year=2099 AND month=11')->execute(['本月申请在家上班', $people[1]]);
    $rows = ps_monthly_results($month, true, ['rules'=>$rules, 'snapshots'=>[], 'inputs'=>[], 'attendance'=>ps_monthly_attendance($month)]);
    $amounts = array_column($rows, 'amount', 'employee_id');
    $check((float)$amounts[$people[0]] === 100.0 && (float)$amounts[$people[1]] === 0.0, '已上传考勤立即自动计入，居家记录排除');
    $rule = $rules[0];
    $pdo->prepare('INSERT INTO project_monthly_results (payroll_month,rule_id,rule_name,rule_type,employee_id,amount,paid_separately,detail) VALUES (?,?,?,?,?,?,0,?)')->execute([$month, $rule['id'], $rule['name'], 'attendance_bonus', $rule['employee_id'], 73.21, '锁定快照测试']);
    $pdo->prepare("UPDATE project_payroll_periods SET status='locked' WHERE period=?")->execute([$month]);
    $pdo->prepare("DELETE FROM project_monthly_rules WHERE employee_id=? AND rule_type='attendance_bonus'")->execute([$people[1]]);
    $rows = ps_monthly_results($month);
    $check(ps_monthly_ensure_attendance_rules($month, $adminId) === 0 && count($rows) === 1 && (float)$rows[0]['amount'] === 73.21, '锁定月份不补规则，历史金额按快照保持');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
echo "测试数据已全部回滚\n";
