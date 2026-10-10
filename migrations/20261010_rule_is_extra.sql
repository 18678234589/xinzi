-- 补贴规则（叠加规则）标记：见 includes/ProjectExtraRules.php。已存在列时重复执行会报错，可忽略。
ALTER TABLE project_commission_rules ADD COLUMN is_extra TINYINT(1) NOT NULL DEFAULT 0;
