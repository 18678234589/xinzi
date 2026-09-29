<?php
// php migrations/apply_import_followups.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../config/database.php';
db()->exec(file_get_contents(__DIR__ . '/20260929_import_followups.sql'));
echo "project_import_followups ready\n";
