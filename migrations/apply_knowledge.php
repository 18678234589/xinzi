<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../config/database.php';
foreach (preg_split('/;\s*(?:\r?\n|$)/', file_get_contents(__DIR__ . '/20261002_knowledge.sql')) as $statement) {
    $statement = trim(preg_replace('/^\s*--.*$/m', '', $statement));
    if ($statement !== '') db()->exec($statement);
}
echo "knowledge ready (additive migration)\n";
