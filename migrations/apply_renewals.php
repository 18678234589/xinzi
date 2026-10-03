<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../config/database.php';
$sql=file_get_contents(__DIR__ . '/20261002_project_renewals.sql');
db()->exec($sql);
echo "Renewal tables ready; SMS disabled by default.\n";
