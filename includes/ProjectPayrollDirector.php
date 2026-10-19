<?php
require_once __DIR__ . '/ProjectPayrollFlags.php';

const PS_DIRECTOR_LABEL = '董事长/董事会补助';

function ps_director_can($actor)
{
    return ($actor['type'] ?? '') === 'admin' || ($actor['role'] ?? '') === 'finance';
}

function ps_director_settings($month)
{
    $out = [];
    foreach (db()->query('SELECT employee_id FROM project_governance_members')->fetchAll(PDO::FETCH_COLUMN) as $eid) {
        $out[(int)$eid] = ['amount' => null, 'note' => '', 'confirmed' => false];
    }
    $q = db()->prepare("SELECT employee_id,amount,note FROM project_payroll_manual_items WHERE payroll_month=? AND kind='other' AND label=? ORDER BY id");
    $q->execute([$month, PS_DIRECTOR_LABEL]);
    foreach ($q->fetchAll() as $r) {
        $eid = (int)$r['employee_id'];
        $out[$eid] = ['amount' => round((float)($out[$eid]['amount'] ?? 0) + (float)$r['amount'], 2), 'note' => (string)$r['note'], 'confirmed' => strpos((string)$r['note'], '财务本月核定') === 0];
    }
    return $out;
}

/** 调用方先初始化依赖表、校验 CSRF，并持有月份行锁及事务。 */
function ps_director_save($employeeId, $month, $amount, $note, $actor)
{
    if (!ps_director_can($actor)) throw new RuntimeException('只有财务可以核定董事长、董事会补助');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', (string)$month)) throw new RuntimeException('月份无效');
    $text = trim((string)$amount); $note = trim((string)$note);
    if (!preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D', $text)) throw new RuntimeException('请填写非负核定金额，最多两位小数；填0表示本月不发');
    if (mb_strlen($note) > 160) throw new RuntimeException('核定备注不超过160字');
    $pdo = db();
    if (!$pdo->inTransaction()) throw new RuntimeException('补助核定须在事务中保存');
    $q = $pdo->prepare('SELECT id FROM employees WHERE id=? FOR UPDATE'); $q->execute([(int)$employeeId]);
    if (!$q->fetchColumn() || !isset(ps_director_settings($month)[(int)$employeeId])) throw new RuntimeException('该人员未登记为董事长或董事会成员');
    $q = $pdo->prepare('SELECT status FROM project_payroll_periods WHERE period=? FOR UPDATE'); $q->execute([$month]);
    if ($q->fetchColumn() === 'locked') throw new RuntimeException('该月已锁定，不能修改补助');
    $q = $pdo->prepare("SELECT * FROM project_payroll_manual_items WHERE employee_id=? AND payroll_month=? AND kind='other' AND label=? FOR UPDATE");
    $q->execute([(int)$employeeId, $month, PS_DIRECTOR_LABEL]); $before = $q->fetchAll();
    $old = round(array_sum(array_column($before, 'amount')), 2);
    $pdo->prepare("DELETE FROM project_payroll_manual_items WHERE employee_id=? AND payroll_month=? AND kind='other' AND label=?")->execute([(int)$employeeId, $month, PS_DIRECTOR_LABEL]);
    $pdo->prepare("INSERT INTO project_payroll_manual_items (employee_id,payroll_month,kind,label,amount,note,created_by_admin) VALUES (?,?,'other',?,?,?,?)")
        ->execute([(int)$employeeId, $month, PS_DIRECTOR_LABEL, $text, '财务本月核定' . ($note !== '' ? '：' . $note : ''), (int)$actor['id']]);
    if (abs($old - (float)$text) > 0.004) {
        $pdo->prepare("UPDATE project_payroll_flags SET color='yellow',reason='董事长/董事会补助金额已调整，请重新核对整月实结算' WHERE employee_id=? AND payroll_month=? AND (color IS NULL OR color<>'red')")
            ->execute([(int)$employeeId, $month]);
    }
    ps_audit('payroll_director', (int)$employeeId, 'confirm_month', $actor, ['month' => $month, 'amount' => $text, 'note' => $note, 'before' => $before]);
}
