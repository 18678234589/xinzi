<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../config/database.php';
$sql = file_get_contents(__DIR__ . '/20260924_welfare_pool.sql');
if ($sql === false) { fwrite(STDERR,"无法读取福利池迁移文件\n"); exit(1); }
foreach (preg_split('/;\s*(?:\r?\n|$)/',$sql) as $statement) {
    if (trim(preg_replace('/^\s*--.*$/m','',$statement)) === '') continue;
    db()->exec($statement);
}
echo "全员福利池表已就绪\n";
