<?php
require_once __DIR__ . '/../includes/ProjectOnlineSheets.php';
// 原始表格在线编辑接口：load（读）/ save（自动保存，可带总行数＝新增行）/ preview（提交前预览）/ submit（提交更正）/ sync（我的在线表格：同步到系统）。JSON + CSRF。
$actor = ps_require_actor();
$reply = function ($data, $status = 200) { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; };
try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (($_GET['action'] ?? '') !== 'load') $reply(['error' => '未知操作'], 400);
        $sheet = pse_load((int)($_GET['file'] ?? 0), (string)($_GET['sheet'] ?? ''), $actor);
        if (($online = pols_by_file((int)$sheet['file']['id'])) && (int)$online['employee_id'] === (int)($actor['employee_id'] ?? 0)) { /* 只有表格本人是“在线表格”模式（自动同步）；财务查看 / 更正按普通表格处理 */ $sheet['online'] = ['month' => $online['month'], 'business' => $online['business_name'], 'states' => (object)pols_states((int)$online['file_id'])]; $sheet['pending'] = 0; $sheet['changed'] = []; }
        $reply(['ok' => true, 'sheet' => $sheet]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) $reply(['error' => '请求格式不正确，请刷新页面后重试'], 400);
    if (!hash_equals(ps_csrf_token(), (string)($in['csrf'] ?? ''))) $reply(['error' => '页面已过期，请刷新后重试'], 403);
    $file = (int)($in['file'] ?? 0); $sheet = (string)($in['sheet'] ?? '');
    $action = (string)($in['action'] ?? '');
    if ($action === 'save') {
        $saved = pse_save($file, $sheet, (array)($in['edits'] ?? []), $actor, isset($in['total']) ? (int)$in['total'] : null);
        $q = db()->prepare('SELECT COUNT(*) FROM project_import_edits WHERE file_id=? AND sheet=? AND NOT (BINARY applied_value <=> BINARY value)');
        $q->execute([$file, $sheet]);
        $reply(['ok' => true, 'saved' => $saved, 'pending' => (int)$q->fetchColumn(), 'at' => date('H:i:s')]);
    }
    if ($action === 'sync') $reply(['ok' => true] + pols_sync($file, $actor));
    if ($action === 'preview') $reply(['ok' => true, 'results' => pse_submit($file, $sheet, $actor, true)]);
    if ($action === 'submit') $reply(['ok' => true, 'results' => pse_submit($file, $sheet, $actor, false)]);
    $reply(['error' => '未知操作'], 400);
} catch (RuntimeException $e) {
    $reply(['error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('file_sheet_api: ' . $e->getMessage());
    $reply(['error' => '操作失败，请稍后重试'], 500);
}
