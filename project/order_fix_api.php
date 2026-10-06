<?php
require_once __DIR__ . '/../includes/ProjectOrderFix.php';
// 订单资料更正接口：上传预览里“按表格更正原单”（JSON）、财务在更正栏目里确认 / 不采纳。
$actor = ps_require_actor();
$reply = function ($data, $status = 200) { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; };
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) $reply(['error' => '请求格式不正确，请刷新页面后重试'], 400);
if (!hash_equals(ps_csrf_token(), (string)($in['csrf'] ?? ''))) $reply(['error' => '页面已过期，请刷新后重试'], 403);
try {
    $action = (string)($in['action'] ?? '');
    if ($action === 'fix') {
        $r = pof_submit($actor, (int)($in['order_id'] ?? 0), (array)($in['changes'] ?? []), (string)($in['note'] ?? ''));
        $reply($r);
    } elseif ($action === 'handle') {
        pof_handle((int)($in['id'] ?? 0), (string)($in['decision'] ?? ''), (string)($in['note'] ?? ''), $actor);
        $reply(['ok' => true]);
    }
    $reply(['error' => '操作无效'], 400);
} catch (RuntimeException $e) {
    $reply(['error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('order_fix_api: ' . $e->getMessage());
    $reply(['error' => '处理失败，请稍后重试'], 500);
}
