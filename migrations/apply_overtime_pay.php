<?php
// 超时补贴上线：补建考勤延时服务列、规则类型枚举，并录入全员“超时补贴”规则（幂等，可重复执行）。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
ensureAttendanceTable();
$sql = file_get_contents(__DIR__ . '/20261009_overtime_pay.sql');
if ($sql === false) { fwrite(STDERR, "无法读取超时补贴迁移文件\n"); exit(1); }
foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) as $statement) {
    if (trim(preg_replace('/^\s*--.*$/m', '', $statement)) === '') continue;
    db()->exec($statement);
}
echo "超时补贴规则已就绪：", db()->query("SELECT COUNT(*) FROM project_monthly_rules WHERE rule_type='overtime_pay' AND is_active=1")->fetchColumn(), " 条\n";
