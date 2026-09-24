<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../config/database.php';
$sql = file_get_contents(__DIR__ . '/20260924_governance_incentives.sql');
if ($sql === false) { fwrite(STDERR, "无法读取管理层考核迁移文件\n"); exit(1); }
foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) as $statement) {
    if (trim(preg_replace('/^\s*--.*$/m', '', $statement)) === '') continue;
    db()->exec($statement);
}
echo "管理层考核表已就绪\n";
