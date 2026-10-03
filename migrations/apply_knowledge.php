<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../config/database.php';
foreach (['20261002_knowledge.sql','20261002_knowledge_categories.sql'] as $migration) {
    foreach (preg_split('/;\s*(?:\r?\n|$)/', file_get_contents(__DIR__ . '/' . $migration)) as $statement) {
        $statement = trim(preg_replace('/^\s*--.*$/m', '', $statement));
        if ($statement !== '') db()->exec($statement);
    }
}
echo "knowledge ready (additive migration)\n";
