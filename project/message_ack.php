<?php
require_once __DIR__ . '/../includes/ProjectAnnouncements.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => '请使用 POST 请求'], JSON_UNESCAPED_UNICODE);
    exit;
}
$actor = ps_actor();
if (!pna_employee_id($actor)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => '请使用本人的合作人员账号登录'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!hash_equals(ps_csrf_token(), (string)($_POST['csrf'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => '页面已过期，请刷新后重试'], JSON_UNESCAPED_UNICODE);
    exit;
}
try {
    pna_acknowledge($actor, (int)($_POST['message_id'] ?? 0), (string)($_POST['action'] ?? ''));
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('Announcement acknowledgement unavailable: ' . get_class($e));
    echo json_encode(['ok' => false, 'error' => '通知暂时不可用，请稍后重试'], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $e) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
