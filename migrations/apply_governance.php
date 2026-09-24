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
db()->exec(file_get_contents(__DIR__ . '/20260924_governance_accounts.sql'));
echo "管理层专属登录角色已就绪\n";
foreach (preg_split('/;\s*(?:\r?\n|$)/', file_get_contents(__DIR__ . '/20260924_governance_fixed_fees.sql')) as $statement) {
    if (trim(preg_replace('/^\s*--.*$/m', '', $statement)) === '') continue;
    db()->exec($statement);
}
echo "管理层固定服务费规则已就绪\n";
foreach (preg_split('/;\s*(?:\r?\n|$)/', file_get_contents(__DIR__ . '/20260924_governance_cycles.sql')) as $statement) {
    if (trim(preg_replace('/^\s*--.*$/m', '', $statement)) === '') continue;
    db()->exec($statement);
}
echo "管理层轮值与奖金池已就绪\n";
