<?php
// 默认只读回放；仅指定 --apply 才以原上传人身份执行现有核对/导入流程。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectImportResult.php';
require_once __DIR__ . '/../includes/ProjectSystem.php';
// 提醒模块懒建表必须先于事务，不能让 CREATE TABLE 隐式提交试运行。
if (is_file(__DIR__ . '/../includes/dup_feedback.php')) { require_once __DIR__ . '/../includes/dup_feedback.php'; if (function_exists('pd_ensure')) pd_ensure(); }
$GLOBALS['project_import_cli'] = true;
$ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $argv[1] ?? '')))));
if (!$ids) { fwrite(STDERR, "Usage: php tools/recover_project_import.php FILE_IDS [--apply]\n"); exit(2); }
$persist = in_array('--apply', $argv, true);
$apply = $persist || in_array('--check-apply', $argv, true);
foreach ($ids as $fileId) {
    $pdo = db();
    $q = $pdo->prepare('SELECT * FROM project_import_files WHERE id=?'); $q->execute([$fileId]); $file = $q->fetch();
    if (!$file) throw new RuntimeException('File not found: ' . $fileId);
    // 独立事务；不修改全局 AI 配置。正常规则/映射缓存优先，仍保留系统托底。
    $pdo->beginTransaction();
    $bufferLevel = ob_get_level();
    try {
        $_SESSION = ['admin_id' => (int)$pdo->query('SELECT MIN(id) FROM admins')->fetchColumn(), 'project_csrf' => 'recover-import'];
        $_SERVER['SCRIPT_NAME'] = '/project/import.php'; $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET = [];
        $_POST = ['csrf' => 'recover-import', 'action' => 'repreview', 'resume_file' => $fileId, 'file_id' => $fileId,
            'business' => $file['business_name'], 'all_sheets' => 1, 'auto_import' => $apply ? 1 : 0];
        $_FILES = [];
        ob_start();
        include __DIR__ . '/../project/import.php';
        ob_end_clean();
        if ($error !== '') throw new RuntimeException($error . (isset($e) && $e instanceof Throwable ? ' [' . get_class($e) . ': ' . $e->getMessage() . ']' : ''));
        $rows = $_SESSION['project_import_preview'] ?? [];
        $report = $apply ? ps_import_result_get($fileId) : [];
        $result = ['file' => $fileId, 'apply' => $persist, 'checked_apply' => $apply, 'business' => $selectedBusiness,
            'written' => $imported, 'matched' => count($report['order_ids'] ?? []), 'existing' => $report['existing'] ?? 0,
            'pending' => $apply ? ($report['pending'] ?? count($rows)) : count(array_filter($rows, function ($r) { return empty($r['base_valid']) && !in_array($r['status'], ['已导入过', '他人订单'], true); })),
            'valid' => count(array_filter($rows, function ($r) { return !empty($r['base_valid']); }))];
        if ($persist) $pdo->commit(); else $pdo->rollBack();
        echo json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
    } catch (Throwable $e) {
        while (ob_get_level() > $bufferLevel) ob_end_clean();
        if ($pdo->inTransaction()) $pdo->rollBack();
        fwrite(STDERR, 'File ' . $fileId . ': ' . $e->getMessage() . "\n"); exit(1);
    }
}
