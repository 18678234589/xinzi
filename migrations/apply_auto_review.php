<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../config/database.php';
$sql = file_get_contents(__DIR__ . '/20261007_project_auto_review.sql');
foreach (explode(';', $sql) as $statement) if (trim($statement) !== '') db()->exec($statement);
$columns=db()->query("SHOW COLUMNS FROM project_auto_cash_evidence LIKE 'source_key'")->fetchAll();
if(!$columns)db()->exec('ALTER TABLE project_auto_cash_evidence ADD COLUMN source_key CHAR(64) NULL AFTER source_order_id');
$indexes=db()->query("SHOW INDEX FROM project_auto_cash_evidence WHERE Key_name='uk_auto_cash_source'")->fetchAll();
if(!$indexes)db()->exec('ALTER TABLE project_auto_cash_evidence ADD UNIQUE KEY uk_auto_cash_source (source_key)');
echo "Auto-review metadata tables ready; no financial data or rules changed.\n";
