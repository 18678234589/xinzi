<?php

        $empId = (int)($_POST['employee_id'] ?? 0);
        $fullDays = (float)($_POST['full_days'] ?? 0);
        $actualDays = (float)($_POST['actual_days'] ?? $fullDays);
        $rm = trim($_POST['remark'] ?? '');
        $otNormal = max(0, (float)($_POST['overtime_days'] ?? 0));
        $otHoliday = max(0, (float)($_POST['holiday_overtime_days'] ?? 0));
        if ($empId <= 0) {
            $error = '请选择合作人员';
        } else {
            try {
                db()->beginTransaction();
                attendance_require_draft(sprintf('%04d-%02d', $year, $month));
                // 满勤天数 × 8 = 应出勤小时；(满勤-实际出勤) × 8 = 请假小时
                $wh = $fullDays * 8;
                $ah = max(0, ($fullDays - $actualDays) * 8);
                $stmt = db()->prepare("INSERT INTO attendances (employee_id, year, month, work_hours, absent_hours, remark, overtime_days, holiday_overtime_days)
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                                       ON DUPLICATE KEY UPDATE work_hours=VALUES(work_hours), absent_hours=VALUES(absent_hours), remark=VALUES(remark), overtime_days=VALUES(overtime_days), holiday_overtime_days=VALUES(holiday_overtime_days)");
                $stmt->execute([$empId, $year, $month, $wh, $ah, $rm, $otNormal, $otHoliday]);
                ps_monthly_ensure_attendance_rules(sprintf('%04d-%02d', $year, $month), $_SESSION['admin_id']);
                db()->commit();
                $success = '考勤已保存，全员全勤奖已自动核算';
            } catch (Throwable $ex) {
                if (db()->inTransaction()) db()->rollBack();
                $error = '添加失败: ' . $ex->getMessage();
            }
        }
