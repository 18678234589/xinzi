<?php
require_once __DIR__ . '/../includes/ProjectRuleAlgo.php';
// 分成算法更正申请的接口：提交（JSON）、上传示例（multipart，小请求）、审核（JSON）、下载示例文件（GET）。
$actor = ps_require_actor();
pra_ensure();
$reply = function ($data, $status = 200) { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; };

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = (int)($_GET['download'] ?? 0);
    $q = db()->prepare('SELECT * FROM project_rule_requests WHERE id=?'); $q->execute([$id]); $row = $q->fetch();
    if (!$row || $row['example_stored'] === '' || !pra_request_visible($row, $actor) || !preg_match('/^[a-f0-9]{40}\.(?:png|jpg|webp|pdf|xlsx|xls|docx|doc)$/', $row['example_stored'])) { http_response_code(404); exit('文件不存在'); }
    $data = ps_private_read('rule_requests', $row['example_stored']);
    if ($data === null) { http_response_code(404); exit('文件不存在'); }
    $inline = in_array($row['example_mime'], ['image/png', 'image/jpeg', 'image/webp'], true);
    header('Content-Type: ' . $row['example_mime']);
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($row['example_name']));
    header('X-Content-Type-Options: nosniff'); header('X-Frame-Options: DENY'); header("Content-Security-Policy: default-src 'none'; sandbox"); header('Cache-Control: private, no-store'); header('Content-Length: ' . strlen($data));
    echo $data; exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$json = null;
if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === 0) {
    $json = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($json)) $reply(['error' => '请求格式不正确，请刷新页面后重试'], 400);
}
$in = $json ?? $_POST;
if (!hash_equals(ps_csrf_token(), (string)($in['csrf'] ?? ''))) $reply(['error' => '页面已过期，请刷新后重试'], 403);
try {
    $action = (string)($in['action'] ?? '');
    if ($action === 'submit') {
        $reply(['id' => pra_submit($in, $actor)]);
    } elseif ($action === 'upload') {
        pra_attach($actor, (int)($in['id'] ?? 0), $_FILES['example'] ?? []);
        $reply(['ok' => true]);
    } elseif ($action === 'handle') {
        pra_handle((int)($in['id'] ?? 0), (string)($in['decision'] ?? ''), (string)($in['note'] ?? ''), $actor);
        $reply(['ok' => true]);
    }
    $reply(['error' => '操作无效'], 400);
} catch (RuntimeException $e) {
    $reply(['error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('rule_request_api: ' . $e->getMessage());
    $reply(['error' => '处理失败，请稍后重试'], 500);
}
