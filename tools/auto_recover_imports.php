<?php
// 定时补录：近 30 天上传过、当时有行被跳过（如缺日期）的表格，重新回放；
// 现在能通过的行（日期已由店铺流水补上等）才用同一套导入流程写入。无可补行则什么都不改。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
$ids = db()->query("SELECT id FROM project_import_files WHERE status='imported' AND skipped_count>0 AND created_at>=(NOW() - INTERVAL 30 DAY) ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
foreach ($ids as $id) {
    $out = shell_exec(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/recover_project_import.php') . ' ' . (int)$id . ' 2>&1');
    $j = json_decode(trim((string)$out), true);
    if (!is_array($j) || (int)($j['valid'] ?? 0) <= 0) continue;
    echo date('c'), " file $id valid {$j['valid']} -> ", trim((string)shell_exec(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/recover_project_import.php') . ' ' . (int)$id . ' --apply 2>&1')), "\n";
}
