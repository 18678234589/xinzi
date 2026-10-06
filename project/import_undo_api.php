<?php
require_once __DIR__ . '/../includes/ProjectImportUndo.php';
// 撤销上传接口：plan 只预演不改数据；run 才真正撤销（JSON + CSRF）。
$actor = ps_require_actor();
$reply = function ($data, $status = 200) { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; };
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) $reply(['error' => '请求格式不正确，请刷新页面后重试'], 400);
if (!hash_equals(ps_csrf_token(), (string)($in['csrf'] ?? ''))) $reply(['error' => '页面已过期，请刷新后重试'], 403);
try {
    $fileId = (int)($in['file_id'] ?? 0);
    $action = (string)($in['action'] ?? '');
    if ($action === 'plan') {
        $plan = pu_plan($fileId, $actor);
        $plan['file'] = ['id' => (int)$plan['file']['id'], 'name' => $plan['file']['original_name'], 'business' => $plan['file']['business_name'], 'created_at' => $plan['file']['created_at']];
        $reply(['ok' => true, 'plan' => $plan]);
    }
    if ($action === 'run') $reply(['ok' => true, 'result' => pu_execute($fileId, $actor, !empty($in['delete_file']))]);
    $reply(['error' => '未知操作'], 400);
} catch (RuntimeException $e) {
    $reply(['error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('import_undo_api: ' . $e->getMessage());
    $reply(['error' => '撤销失败，数据没有变动，请稍后重试'], 500);
}
