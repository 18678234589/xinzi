<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectMonthly.php';
require_once __DIR__ . '/../includes/AttendanceHomeWork.php';
attendance_home_schema();
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
    $staff = array_map(function ($id) use ($pdo) {
        $q = $pdo->prepare('SELECT id,name,department FROM employees WHERE id=?');
        $q->execute([$id]);
        return $q->fetch();
    }, $people);
    $matched = attendance_home_match([['姓名','部门'], [$staff[0]['name'], $staff[0]['department']]], $staff);
    attendance_home_save($month, $matched, $adminId);
    $check(ps_monthly_attendance($month)[$people[0]]['work_from_home'], '名单匹配保存后立即参与全勤计算');
    $rejected = false;
    try { attendance_home_match([['姓名','部门'], ['不存在', '错误部门']], $staff); } catch (RuntimeException $e) { $rejected = true; }
    $check($rejected && attendance_home_ids($month) === $matched, '匹配失败不覆盖已保存名单');
    attendance_home_save($month, [], $adminId);
    $check(!ps_monthly_attendance($month)[$people[0]]['work_from_home'] && ps_monthly_attendance($month)[$people[1]]['work_from_home'], '取消手工名单恢复计算，保留原考勤居家标记');
    $rule = $rules[0];
    $pdo->prepare('INSERT INTO project_monthly_results (payroll_month,rule_id,rule_name,rule_type,employee_id,amount,paid_separately,detail) VALUES (?,?,?,?,?,?,0,?)')->execute([$month, $rule['id'], $rule['name'], 'attendance_bonus', $rule['employee_id'], 73.21, '锁定快照测试']);
    $pdo->prepare("UPDATE project_payroll_periods SET status='locked' WHERE period=?")->execute([$month]);
    $rejected = false;
    try { attendance_home_save($month, [$people[0]], $adminId); } catch (RuntimeException $e) { $rejected = true; }
    $check($rejected && attendance_home_ids($month) === [], '锁定月份拒绝名单修改');
    $pdo->prepare("DELETE FROM project_monthly_rules WHERE employee_id=? AND rule_type='attendance_bonus'")->execute([$people[1]]);
    $rows = ps_monthly_results($month);
    $check(ps_monthly_ensure_attendance_rules($month, $adminId) === 0 && count($rows) === 1 && (float)$rows[0]['amount'] === 73.21, '锁定月份不补规则，历史金额按快照保持');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
echo "测试数据已全部回滚\n";
