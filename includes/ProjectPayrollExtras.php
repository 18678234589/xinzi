<?php

/**
 * 项目报酬结算单的扣款与手工项（财务录入）。
 * - 个人保险扣除：按月 个人承担金额；服务暂停 >3 天扣（个人+单位）的一半；>6 天扣个人+单位全额。财务可手动覆盖（0 = 不扣）。
 * - 手工项：每人每月任意名称和金额（正数补助、负数扣款），如“代理董事长补助”。
 * 保险金额配置存数据库（不依赖 config/ 目录写权限）；未设置时回退旧 config/insurance.php 的 amount。
 */
function ps_payroll_extras_ensure()
{
    static $done = false;
    if ($done) return;
    db()->exec("CREATE TABLE IF NOT EXISTS project_payroll_settings (name VARCHAR(40) PRIMARY KEY, value VARCHAR(100) NOT NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE IF NOT EXISTS project_payroll_manual_items (
        id INT AUTO_INCREMENT PRIMARY KEY, employee_id INT NOT NULL, payroll_month CHAR(7) NOT NULL,
        kind ENUM('insurance','other') NOT NULL DEFAULT 'other', label VARCHAR(60) NOT NULL, amount DECIMAL(12,2) NOT NULL,
        note VARCHAR(200) NOT NULL DEFAULT '', created_by_admin INT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_employee_month (employee_id, payroll_month)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** 保险金额：personal 个人承担（每月），unit 单位承担（每月）。 */
function ps_insurance_settings()
{
    ps_payroll_extras_ensure();
    $rows = db()->query("SELECT name,value FROM project_payroll_settings WHERE name IN ('insurance_personal','insurance_unit')")->fetchAll(PDO::FETCH_KEY_PAIR);
    $legacy = @include __DIR__ . '/../config/insurance.php';
    return ['personal' => round((float)($rows['insurance_personal'] ?? ($legacy['amount'] ?? 0)), 2), 'unit' => round((float)($rows['insurance_unit'] ?? 0), 2)];
}

function ps_insurance_settings_save($personal, $unit)
{
    ps_payroll_extras_ensure();
    $q = db()->prepare('INSERT INTO project_payroll_settings (name,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)');
    $q->execute(['insurance_personal', sprintf('%.2f', $personal)]);
    $q->execute(['insurance_unit', sprintf('%.2f', $unit)]);
}

/** 按服务暂停天数给出保险扣除建议：返回 [扣除金额(正数), 说明]。 */
function ps_insurance_suggest($leaveDays, $settings)
{
    $total = round($settings['personal'] + $settings['unit'], 2);
    $days = rtrim(rtrim(number_format($leaveDays, 2, '.', ''), '0'), '.') ?: '0';
    if ($leaveDays > 6) return [$total, sprintf('服务暂停 %s 天（超过 6 天）：保险全部由个人承担，单位与个人合计 ¥%s', $days, sprintf('%.2f', $total))];
    if ($leaveDays > 3) return [round($total / 2, 2), sprintf('服务暂停 %s 天（超过 3 天）：扣单位与个人合计 ¥%s 的一半', $days, sprintf('%.2f', $total))];
    return [$settings['personal'], sprintf('服务暂停 %s 天（3 天以内）：按月扣个人承担 ¥%s', $days, sprintf('%.2f', $settings['personal']))];
}

/**
 * 某月每位合作人员的扣款与手工项：[employee_id => ['insurance' => ['amount'(负数), 'manual', 'detail', 'note'], 'items' => [...], 'items_total']]。
 * 保险只在调用方判定适用时才计入合计（原系统已结算的月份已含保险扣除，不重复扣）。
 */
function ps_payroll_extras($month)
{
    ps_payroll_extras_ensure();
    $settings = ps_insurance_settings();
    $attendance = ps_monthly_attendance($month);
    $overrides = [];
    $items = [];
    $q = db()->prepare('SELECT * FROM project_payroll_manual_items WHERE payroll_month=? ORDER BY id');
    $q->execute([$month]);
    foreach ($q->fetchAll() as $r) {
        if ($r['kind'] === 'insurance') $overrides[(int)$r['employee_id']] = $r;
        else $items[(int)$r['employee_id']][] = $r;
    }
    $out = [];
    foreach (db()->query('SELECT id FROM employees')->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $eid = (int)$id;
        $leave = round((float)($attendance[$eid]['absent'] ?? 0) / 8, 2);
        [$auto, $detail] = ps_insurance_suggest($leave, $settings);
        $override = $overrides[$eid] ?? null;
        $out[$eid] = [
            'insurance' => ['amount' => $override ? (float)$override['amount'] : -$auto, 'manual' => (bool)$override, 'auto_amount' => $auto,
                'detail' => !$override ? $detail : ($override['note'] === '锁月固化' ? '锁月时已固化：' . $detail : '财务手动录入' . ($override['note'] !== '' ? '：' . $override['note'] : '') . '（系统建议：' . $detail . '）')],
            'items' => $items[$eid] ?? [],
            'items_total' => round(array_sum(array_map(function ($r) { return (float)$r['amount']; }, $items[$eid] ?? [])), 2),
        ];
    }
    return $out;
}

/** 财务在结算页保存保险覆盖、添加或删除手工项（调用方负责事务、CSRF、锁月检查）。 */
function ps_payroll_extras_action($action, $month, $employeeId, $post, $actor)
{
    $exists = db()->prepare('SELECT COUNT(*) FROM employees WHERE id=?');
    $exists->execute([$employeeId]);
    if (!$employeeId || !(int)$exists->fetchColumn()) throw new RuntimeException('合作人员不存在');
    $note = trim((string)($post['note'] ?? ''));
    if (mb_strlen($note) > 200) throw new RuntimeException('备注不超过200字');
    if ($action === 'save_insurance') {
        db()->prepare("DELETE FROM project_payroll_manual_items WHERE employee_id=? AND payroll_month=? AND kind='insurance'")->execute([$employeeId, $month]);
        $text = trim((string)($post['amount'] ?? ''));
        if ($text !== '') { // 留空 = 恢复系统自动计算
            if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $text)) throw new RuntimeException('保险扣除金额须为非负金额，最多两位小数（填 0 表示不扣）');
            db()->prepare("INSERT INTO project_payroll_manual_items (employee_id,payroll_month,kind,label,amount,note,created_by_admin) VALUES (?,?,'insurance','个人保险扣除',?,?,?)")
                ->execute([$employeeId, $month, -(float)$text, $note, $actor['id']]);
        }
        ps_audit('payroll_manual', $employeeId, 'save_insurance', $actor, ['month' => $month, 'amount' => $text, 'note' => $note]);
    } elseif ($action === 'add_manual_item') {
        $label = trim((string)($post['label'] ?? ''));
        $text = trim((string)($post['amount'] ?? ''));
        if ($label === '' || mb_strlen($label) > 60) throw new RuntimeException('请填写不超过60字的项目名称');
        if (!preg_match('/^-?\d{1,9}(?:\.\d{1,2})?$/', $text) || (float)$text == 0.0) throw new RuntimeException('金额须为非零数字（正数补助、负数扣款），最多两位小数');
        db()->prepare("INSERT INTO project_payroll_manual_items (employee_id,payroll_month,kind,label,amount,note,created_by_admin) VALUES (?,?,'other',?,?,?,?)")
            ->execute([$employeeId, $month, $label, $text, $note, $actor['id']]);
        ps_audit('payroll_manual', (int)db()->lastInsertId(), 'add', $actor, ['employee_id' => $employeeId, 'month' => $month, 'label' => $label, 'amount' => $text, 'note' => $note]);
    } else { // delete_manual_item
        $id = (int)($post['item_id'] ?? 0);
        $del = db()->prepare("DELETE FROM project_payroll_manual_items WHERE id=? AND employee_id=? AND payroll_month=? AND kind='other'");
        $del->execute([$id, $employeeId, $month]);
        if (!$del->rowCount()) throw new RuntimeException('手工项不存在');
        ps_audit('payroll_manual', $id, 'delete', $actor, ['employee_id' => $employeeId, 'month' => $month]);
    }
}

/** 锁月时把自动算出的保险扣除固化为记录，之后改保险金额或考勤不影响本月。$insuredIds：本月适用保险扣除的合作人员。 */
function ps_payroll_extras_freeze($month, $insuredIds, $actor)
{
    $has = db()->prepare("SELECT employee_id FROM project_payroll_manual_items WHERE payroll_month=? AND kind='insurance'");
    $has->execute([$month]);
    $done = array_flip(array_map('intval', $has->fetchAll(PDO::FETCH_COLUMN)));
    $extras = ps_payroll_extras($month);
    $ins = db()->prepare("INSERT INTO project_payroll_manual_items (employee_id,payroll_month,kind,label,amount,note,created_by_admin) VALUES (?,?,'insurance','个人保险扣除',?,'锁月固化',?)");
    foreach ($insuredIds as $eid) {
        if (isset($done[(int)$eid]) || !isset($extras[(int)$eid])) continue;
        $ins->execute([(int)$eid, $month, $extras[(int)$eid]['insurance']['amount'], $actor['id']]);
    }
}
