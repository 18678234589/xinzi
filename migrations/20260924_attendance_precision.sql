-- 考勤请假小时保留两位小数：上传按 (满勤天数 − 实际出勤天数) × 8 计算，如 (26 − 25.63) × 8 = 2.96 小时；
-- 原 DECIMAL(6,1) 会存成 3.0，折算请假 0.38 天，与收入表 0.37 天差约 1 元。只放宽精度，已有数据不变。
ALTER TABLE attendances MODIFY work_hours DECIMAL(6,2) NOT NULL DEFAULT 0 COMMENT '应出勤小时数', MODIFY absent_hours DECIMAL(6,2) NOT NULL DEFAULT 0 COMMENT '请假小时数';
ALTER TABLE attendance_pending MODIFY work_hours DECIMAL(6,2) NOT NULL DEFAULT 0, MODIFY absent_hours DECIMAL(6,2) NOT NULL DEFAULT 0;
