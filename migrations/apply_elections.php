<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../config/database.php';
$sql=file_get_contents(__DIR__ . '/20260924_governance_elections.sql');
if ($sql===false) { fwrite(STDERR,"无法读取换届迁移文件\n"); exit(1); }
foreach (preg_split('/;\s*(?:\r?\n|$)/',$sql) as $statement) {
    if (trim(preg_replace('/^\s*--.*$/m','',$statement))==='') continue;
    db()->exec($statement);
}
echo "换届日程与投票表已就绪\n";
