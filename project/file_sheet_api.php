<?php
require_once __DIR__ . '/../includes/ProjectSheetEdit.php';
// 原始表格在线编辑接口：load（读）/ save（自动保存）/ preview（提交前预览）/ submit（提交更正）。JSON + CSRF。
$actor = ps_require_actor();
$reply = function ($data, $status = 200) { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; };
try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (($_GET['action'] ?? '') !== 'load') $reply(['error' => '未知操作'], 400);
        $reply(['ok' => true, 'sheet' => pse_load((int)($_GET['file'] ?? 0), (string)($_GET['sheet'] ?? ''), $actor)]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) $reply(['error' => '请求格式不正确，请刷新页面后重试'], 400);
    if (!hash_equals(ps_csrf_token(), (string)($in['csrf'] ?? ''))) $reply(['error' => '页面已过期，请刷新后重试'], 403);
    $file = (int)($in['file'] ?? 0); $sheet = (string)($in['sheet'] ?? '');
    $action = (string)($in['action'] ?? '');
    if ($action === 'save') {
        $saved = pse_save($file, $sheet, (array)($in['edits'] ?? []), $actor);
        $q = db()->prepare('SELECT COUNT(*) FROM project_import_edits WHERE file_id=? AND sheet=? AND NOT (BINARY applied_value <=> BINARY value)');
        $q->execute([$file, $sheet]);
        $reply(['ok' => true, 'saved' => $saved, 'pending' => (int)$q->fetchColumn(), 'at' => date('H:i:s')]);
    }
    if ($action === 'preview') $reply(['ok' => true, 'results' => pse_submit($file, $sheet, $actor, true)]);
    if ($action === 'submit') $reply(['ok' => true, 'results' => pse_submit($file, $sheet, $actor, false)]);
    $reply(['error' => '未知操作'], 400);
} catch (RuntimeException $e) {
    $reply(['error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('file_sheet_api: ' . $e->getMessage());
    $reply(['error' => '操作失败，请稍后重试'], 500);
}
