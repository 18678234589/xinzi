<?php
/** 按月生效的车位费；0 元表示取消，锁月后以固化扣款为准。 */
function ps_parking_ensure()
{
    static $done = false;
    if ($done) return;
    db()->exec("CREATE TABLE IF NOT EXISTS project_payroll_parking (
        employee_id INT NOT NULL, effective_month CHAR(7) NOT NULL, amount DECIMAL(12,2) NOT NULL,
        updated_by_admin INT NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (employee_id,effective_month)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function ps_parking_settings($month)
{
    ps_parking_ensure();
    $q = db()->prepare('SELECT p.* FROM project_payroll_parking p WHERE p.effective_month<=? AND NOT EXISTS
        (SELECT 1 FROM project_payroll_parking n WHERE n.employee_id=p.employee_id AND n.effective_month>p.effective_month AND n.effective_month<=?)');
    $q->execute([$month, $month]);
    $out = [];
    foreach ($q->fetchAll() as $r) $out[(int)$r['employee_id']] = ['amount' => (float)$r['amount'], 'effective_month' => $r['effective_month']];
    return $out;
}

function ps_parking_items($month, $items)
{
    $q = db()->prepare('SELECT status FROM project_payroll_periods WHERE period=?');
    $q->execute([$month]);
    if ($q->fetchColumn() === 'locked') return $items;
    foreach (ps_parking_settings($month) as $eid => $setting) {
        if ($setting['amount'] <= 0) continue;
        $manual = false;
        foreach ($items[$eid] ?? [] as $item) if ((float)$item['amount'] < 0 && strpos((string)$item['label'], '车位') !== false) { $manual = true; break; }
        if ($manual) continue; // 已录入本月车位费时只扣一次。
        $items[$eid][] = ['id' => 0, 'employee_id' => $eid, 'payroll_month' => $month, 'kind' => 'other', 'label' => '车位费',
            'amount' => -$setting['amount'], 'note' => '每月自动扣减；自' . $setting['effective_month'] . '起，财务可在首页车位费列修改或取消', 'automatic' => true];
    }
    return $items;
}

/** 调用方在事务外初始化表、校验 CSRF，并在事务内锁定所选月份。 */
function ps_parking_save($employeeId, $month, $amount, $actor)
{
    if (!ppp_can($actor)) throw new RuntimeException('仅财务和管理员可以修改车位费');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$month)) throw new RuntimeException('月份无效');
    $text = trim((string)$amount);
    if (!preg_match('/^\d{1,7}(?:\.\d{1,2})?$/', $text)) throw new RuntimeException('车位费须为非负金额，最多两位小数；填0取消扣费');
    $pdo = db();
    if (!$pdo->inTransaction()) throw new RuntimeException('车位费修改须在事务中保存');
    $q = $pdo->prepare('SELECT id FROM employees WHERE id=? FOR UPDATE');
    $q->execute([(int)$employeeId]);
    if (!$q->fetchColumn()) throw new RuntimeException('合作人员不存在');
    $q = $pdo->prepare('SELECT period,status FROM project_payroll_periods WHERE period>=? ORDER BY period FOR UPDATE');
    $q->execute([$month]);
    foreach ($q->fetchAll() as $period) if ($period['period'] === $month && $period['status'] === 'locked') throw new RuntimeException('该月已锁定，请选择未锁定月份调整车位费');
    $q = $pdo->prepare('SELECT * FROM project_payroll_parking WHERE employee_id=? ORDER BY effective_month FOR UPDATE');
    $q->execute([(int)$employeeId]);
    $before = $q->fetchAll();
    $q = $pdo->prepare("SELECT m.* FROM project_payroll_manual_items m LEFT JOIN project_payroll_periods p ON p.period=m.payroll_month
        WHERE m.employee_id=? AND m.payroll_month>=? AND m.kind='other' AND m.amount<0 AND m.label LIKE '%车位%' AND COALESCE(p.status,'draft')<>'locked' FOR UPDATE");
    $q->execute([(int)$employeeId, $month]);
    $manual = $q->fetchAll();
    // 从生效月起替换未锁定扣款；锁月固化记录保留。
    $del = $pdo->prepare('DELETE FROM project_payroll_manual_items WHERE id=?');
    foreach ($manual as $item) $del->execute([(int)$item['id']]);
    $pdo->prepare('DELETE FROM project_payroll_parking WHERE employee_id=? AND effective_month>=?')->execute([(int)$employeeId, $month]);
    $pdo->prepare('INSERT INTO project_payroll_parking (employee_id,effective_month,amount,updated_by_admin) VALUES (?,?,?,?)')->execute([(int)$employeeId, $month, $text, $actor['id']]);
    ps_audit('payroll_parking', (int)$employeeId, 'save', $actor, ['effective_month' => $month, 'amount' => $text, 'previous_settings' => $before, 'replaced_manual_items' => $manual]);
}

function ps_parking_freeze($month, $extras, $actor)
{
    $q = db()->prepare("INSERT INTO project_payroll_manual_items (employee_id,payroll_month,kind,label,amount,note,created_by_admin) VALUES (?,?,'other','车位费',?,'锁月固化：每月自动车位费',?)");
    foreach ($extras as $eid => $extra) foreach ($extra['items'] as $item) {
        if (!empty($item['automatic'])) $q->execute([(int)$eid, $month, $item['amount'], $actor['id']]);
    }
}
