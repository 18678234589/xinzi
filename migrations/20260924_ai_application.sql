-- 区分 AI 方案在预览中提出、以及用户核对后真正用于订单导入。
ALTER TABLE project_ai_solutions ADD COLUMN applied_count INT NOT NULL DEFAULT 0;
ALTER TABLE project_ai_solutions ADD COLUMN last_applied_at DATETIME NULL;
