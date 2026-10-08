<?php

        $empId = (int)($_POST['employee_id'] ?? 0);
        $fullDays = (float)($_POST['full_days'] ?? 0);
        $actualDays = (float)($_POST['actual_days'] ?? $fullDays);
        $rm = trim($_POST['remark'] ?? '');
        if ($empId <= 0) {
            $error = '请选择合作人员';
        } else {
            try {
                // 满勤天数 × 8 = 应出勤小时；(满勤-实际出勤) × 8 = 请假小时
                $wh = $fullDays * 8;
                $ah = max(0, ($fullDays - $actualDays) * 8);
                $stmt = db()->prepare("INSERT INTO attendances (employee_id, year, month, work_hours, absent_hours, remark)
                                       VALUES (?, ?, ?, ?, ?, ?)
                                       ON DUPLICATE KEY UPDATE work_hours=VALUES(work_hours), absent_hours=VALUES(absent_hours), remark=VALUES(remark)");
                $stmt->execute([$empId, $year, $month, $wh, $ah, $rm]);
                $success = '考勤已添加';
            } catch (PDOException $ex) {
                $error = '添加失败: ' . $ex->getMessage();
            }
        }
    