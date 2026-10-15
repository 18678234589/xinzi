<?php
/** Finance's monthly roster supplements home-working markers in the attendance file. */
function attendance_home_schema()
{
    db()->exec("CREATE TABLE IF NOT EXISTS attendance_home_work (
        period CHAR(7) NOT NULL, employee_id INT NOT NULL,
        PRIMARY KEY (period, employee_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function attendance_home_ids($period)
{
    try {
        $q = db()->prepare('SELECT employee_id FROM attendance_home_work WHERE period=?');
        $q->execute([$period]);
        return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) !== 1146) throw $e;
        return [];
    }
}

function attendance_require_draft($period)
{
    $q = db()->prepare('SELECT status FROM project_payroll_periods WHERE period=? FOR UPDATE');
    $q->execute([$period]);
    if ($q->fetchColumn() === 'locked') throw new RuntimeException('本月已锁定，请先由财务解锁后修改考勤或居家名单');
}

function attendance_home_match($rows, $employees)
{
    $header = array_map(function ($v) { return trim((string)$v, "\xEF\xBB\xBF \t\r\n"); }, array_shift($rows) ?? []);
    $nameCol = array_search('姓名', $header, true);
    $deptCol = array_search('部门', $header, true);
    if ($nameCol === false || $deptCol === false) throw new RuntimeException('居家名单表头必须包含“姓名”和“部门”');
    $ids = [];
    foreach ($rows as $i => $row) {
        $name = trim((string)($row[$nameCol] ?? ''));
        $dept = trim((string)($row[$deptCol] ?? ''));
        if ($name === '' && $dept === '') continue;
        $matches = array_filter($employees, function ($e) use ($name, $dept) {
            return trim($e['name']) === $name && trim($e['department']) === $dept;
        });
        if (count($matches) !== 1) throw new RuntimeException('第' . ($i + 2) . '行姓名与部门未唯一匹配，请核对后重新上传（未保存任何名单）');
        $ids[] = (int)reset($matches)['id'];
    }
    return array_values(array_unique($ids));
}

function attendance_home_save($period, $ids, $adminId)
{
    $ownTransaction = !db()->inTransaction();
    if ($ownTransaction) db()->beginTransaction();
    try {
        attendance_require_draft($period);
        $valid = array_map('intval', db()->query('SELECT id FROM employees')->fetchAll(PDO::FETCH_COLUMN));
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (array_diff($ids, $valid)) throw new RuntimeException('名单包含无效人员，请刷新页面后重试');
        db()->prepare('DELETE FROM attendance_home_work WHERE period=?')->execute([$period]);
        $insert = db()->prepare('INSERT INTO attendance_home_work (period, employee_id) VALUES (?, ?)');
        foreach ($ids as $id) $insert->execute([$period, $id]);
        $added = ps_monthly_ensure_attendance_rules($period, $adminId);
        if ($ownTransaction) db()->commit();
        return $added;
    } catch (Throwable $e) {
        if ($ownTransaction && db()->inTransaction()) db()->rollBack();
        throw $e;
    }
}
