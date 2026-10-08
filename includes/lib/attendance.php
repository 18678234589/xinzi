<?php

/**
 * 考勤记录表辅助函数
 */
function ensureAttendanceTable()
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->query("SELECT 1 FROM `attendances` LIMIT 1");
    } catch (\Throwable $e) {
        db()->exec("CREATE TABLE IF NOT EXISTS `attendances` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `employee_id` INT NOT NULL COMMENT '员工ID',
            `year` SMALLINT NOT NULL COMMENT '年份',
            `month` TINYINT NOT NULL COMMENT '月份1-12',
            `work_hours` DECIMAL(6,1) NOT NULL DEFAULT 0 COMMENT '应出勤小时数',
            `absent_hours` DECIMAL(6,1) NOT NULL DEFAULT 0 COMMENT '请假小时数',
            `remark` VARCHAR(500) DEFAULT '' COMMENT '备注',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_emp_month` (`employee_id`, `year`, `month`),
            INDEX `idx_year_month` (`year`, `month`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT '考勤记录'");
    }
    // 考勤卡片隐藏记录表（用于"删除年份卡片"功能）
    try {
        db()->query("SELECT 1 FROM `attendance_hidden_years` LIMIT 1");
    } catch (\Throwable $e) {
        db()->exec("CREATE TABLE IF NOT EXISTS `attendance_hidden_years` (
            `year` SMALLINT PRIMARY KEY,
            `hidden_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT '考勤卡片隐藏年份'");
    }
    // 考勤卡片自定义添加年份表（用于"添加年份卡片"功能）
    try {
        db()->query("SELECT 1 FROM `attendance_custom_years` LIMIT 1");
    } catch (\Throwable $e) {
        db()->exec("CREATE TABLE IF NOT EXISTS `attendance_custom_years` (
            `year` SMALLINT PRIMARY KEY,
            `added_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT '考勤卡片自定义年份'");
    }
    // 考勤待匹配表：上传考勤时员工尚未添加的行暂存于此，员工添加后自动补录
    try {
        db()->query("SELECT 1 FROM `attendance_pending` LIMIT 1");
    } catch (\Throwable $e) {
        db()->exec("CREATE TABLE IF NOT EXISTS `attendance_pending` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `employee_name` VARCHAR(100) NOT NULL COMMENT '考勤表中的姓名',
            `year` SMALLINT NOT NULL,
            `month` TINYINT NOT NULL,
            `work_hours` DECIMAL(6,1) NOT NULL DEFAULT 0,
            `absent_hours` DECIMAL(6,1) NOT NULL DEFAULT 0,
            `remark` VARCHAR(500) DEFAULT '',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_name_ym` (`employee_name`, `year`, `month`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT '考勤待匹配记录（员工添加后自动补录）'");
    }
}

/**
 * 获取被隐藏（删除卡片）的年份列表
 */
function get_attendance_hidden_years()
{
    try {
        return db()->query("SELECT year FROM attendance_hidden_years")->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * 隐藏某年份的考勤卡片
 */
function hide_attendance_year($year)
{
    db()->prepare("INSERT IGNORE INTO attendance_hidden_years (year) VALUES (?)")->execute([$year]);
}

/**
 * 获取用户手动添加的年份列表
 */
function get_attendance_custom_years()
{
    try {
        return db()->query("SELECT year FROM attendance_custom_years ORDER BY year DESC")->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * 添加自定义年份卡片
 */
function add_attendance_custom_year($year)
{
    db()->prepare("INSERT IGNORE INTO attendance_custom_years (year) VALUES (?)")->execute([$year]);
    // 添加时若该年份曾被隐藏，则取消隐藏
    db()->prepare("DELETE FROM attendance_hidden_years WHERE year=?")->execute([$year]);
}

/**
 * 获取某员工某月考勤
 */
function get_attendance($employeeId, $year, $month)
{
    $stmt = db()->prepare("SELECT * FROM attendances WHERE employee_id=? AND year=? AND month=?");
    $stmt->execute([$employeeId, $year, $month]);
    return $stmt->fetch();
}

/**
 * 将待匹配考勤记录中姓名匹配的行补录到 attendances 表
 * 在添加/更新员工时调用，自动补回之前因员工不存在而跳过的考勤数据
 * @param int $employeeId  员工ID
 * @param string $employeeName  员工姓名
 * @return int 补录条数
 */
function backfill_pending_attendance($employeeId, $employeeName)
{
    $employeeId = (int)$employeeId;
    $employeeName = trim($employeeName);
    if ($employeeId <= 0 || $employeeName === '') return 0;

    try {
        $rows = db()->prepare("SELECT * FROM attendance_pending WHERE employee_name = ?");
        $rows->execute([$employeeName]);
        $pending = $rows->fetchAll();
        if (empty($pending)) return 0;

        $ins = db()->prepare("INSERT INTO attendances (employee_id, year, month, work_hours, absent_hours, remark)
                              VALUES (?, ?, ?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE work_hours=VALUES(work_hours), absent_hours=VALUES(absent_hours), remark=VALUES(remark)");
        $del = db()->prepare("DELETE FROM attendance_pending WHERE id = ?");
        db()->beginTransaction();
        $count = 0;
        foreach ($pending as $p) {
            $ins->execute([$employeeId, $p['year'], $p['month'], $p['work_hours'], $p['absent_hours'], $p['remark']]);
            $del->execute([$p['id']]);
            $count++;
        }
        db()->commit();
        return $count;
    } catch (\Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        error_log("backfill_pending_attendance error: " . $e->getMessage());
        return 0;
    }
}

/**
 * 获取某月所有员工考勤
 */
function get_attendances_by_month($year, $month)
{
    $stmt = db()->prepare("SELECT a.*, e.name, e.department
                           FROM attendances a
                           LEFT JOIN employees e ON a.employee_id = e.id
                           WHERE a.year=? AND a.month=?
                           ORDER BY e.department, e.name");
    $stmt->execute([$year, $month]);
    return $stmt->fetchAll();
}

/**
 * 获取有考勤记录的年份列表
 */
function get_attendance_years()
{
    try {
        return db()->query("SELECT DISTINCT year FROM attendances ORDER BY year DESC")->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * 获取某年的考勤月份列表（含员工数统计）
 */
function get_attendance_months($year)
{
    $stmt = db()->prepare("SELECT month,
                                  COUNT(*) AS emp_count,
                                  SUM(work_hours) AS total_work,
                                  SUM(absent_hours) AS total_absent
                           FROM attendances WHERE year=? GROUP BY month ORDER BY month");
    $stmt->execute([$year]);
    return $stmt->fetchAll();
}
