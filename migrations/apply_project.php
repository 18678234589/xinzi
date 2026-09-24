<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../config/database.php';
foreach (['20260922_project_settlement.sql', '20260922_project_cashflow.sql', '20260922_project_payroll_periods.sql', '20260922_project_technical_reconciliation.sql', '20260922_project_order_resources.sql', '20260922_project_businesses.sql', '20260922_project_order_source.sql', '20260923_website_commission_rules.sql', '20260923_order_algorithms.sql', '20260923_system_settings.sql', '20260923_monthly_rules.sql', '20260923_staff_login.sql', '20260924_attendance_precision.sql', '20260924_writing_rules.sql', '20260924_import_files.sql'] as $migration) {
    $sql = file_get_contents(__DIR__ . '/' . $migration);
    if ($sql === false) { fwrite(STDERR, "无法读取迁移文件：$migration\n"); exit(1); }
    $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql);
    foreach ($statements as $statement) {
        if (trim(preg_replace('/^\s*--.*$/m', '', $statement)) === '') continue;
        try {
            db()->exec($statement);
        } catch (PDOException $e) {
            // 重复执行时 ADD COLUMN / ADD KEY 已存在（1060 列重复、1061 索引重复）视为已完成。
            if (in_array((int)($e->errorInfo[1] ?? 0), [1060, 1061], true)) continue;
            // 原系统考勤表（attendances / attendance_pending）在纯项目库中可能不存在（1146），跳过精度调整。
            if ((int)($e->errorInfo[1] ?? 0) === 1146 && stripos($statement, 'ALTER TABLE attendance') !== false) continue;
            throw $e;
        }
    }
}
echo "项目结算表已就绪\n";
