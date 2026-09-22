<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../config/database.php';
foreach (['20260922_project_settlement.sql', '20260922_project_cashflow.sql', '20260922_project_payroll_periods.sql', '20260922_project_technical_reconciliation.sql', '20260922_project_order_resources.sql', '20260922_project_businesses.sql', '20260922_project_order_source.sql'] as $migration) {
    $sql = file_get_contents(__DIR__ . '/' . $migration);
    if ($sql === false) { fwrite(STDERR, "无法读取迁移文件：$migration\n"); exit(1); }
    $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql);
    foreach ($statements as $statement) {
        if (trim($statement) === '') continue;
        db()->exec($statement);
    }
}
echo "项目结算表已就绪\n";
