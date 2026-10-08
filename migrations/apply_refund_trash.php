<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../config/database.php';
$existing = array_column(db()->query('SHOW COLUMNS FROM project_refund_import_rows')->fetchAll(), 'Field');
$columns = ['deleted_at' => 'DATETIME NULL', 'deleted_by_type' => 'VARCHAR(20) NULL', 'deleted_by_id' => 'INT NULL', 'deleted_note' => "VARCHAR(500) NOT NULL DEFAULT ''"];
foreach ($columns as $name => $definition) if (!in_array($name, $existing, true)) db()->exec("ALTER TABLE project_refund_import_rows ADD COLUMN $name $definition");
if (!db()->query("SHOW INDEX FROM project_refund_import_rows WHERE Key_name='idx_refund_trash'")->fetch()) db()->exec('ALTER TABLE project_refund_import_rows ADD KEY idx_refund_trash (deleted_at,review_status)');
echo "Refund recycle metadata ready; no records deleted or financial values changed.\n";
