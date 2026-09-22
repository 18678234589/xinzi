-- 只锁定项目提成快照，不改变原薪资模块的工资状态。
CREATE TABLE IF NOT EXISTS project_payroll_periods (
  period CHAR(7) PRIMARY KEY,
  status ENUM('draft','locked') NOT NULL DEFAULT 'draft',
  locked_by_admin INT NULL,
  locked_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
