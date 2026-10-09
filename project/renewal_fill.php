<?php
require_once __DIR__ . '/../includes/ProjectRenewalFill.php';
// 续费资料就地补录接口：弹窗 / 待补资料页 / 订单页的“保存”按钮用（POST，返回 JSON）。
header('Content-Type: application/json; charset=UTF-8');
$actor = ps_actor();
if (!$actor) { http_response_code(401); echo json_encode(['ok' => false, 'message' => '登录已过期，请重新登录'], JSON_UNESCAPED_UNICODE); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'message' => '请求方式无效'], JSON_UNESCAPED_UNICODE); exit; }
if (!hash_equals(ps_csrf_token(), (string)($_POST['csrf'] ?? ''))) { http_response_code(403); echo json_encode(['ok' => false, 'message' => '页面已过期，请刷新后重试'], JSON_UNESCAPED_UNICODE); exit; }
try {
    pr_require($actor);
    if (pr_scope($actor) === 'none') throw new RuntimeException('当前账户没有续费资料权限');
    $orderId = (int)($_POST['order_id'] ?? 0);
    $message = prf_save_order($actor, $orderId, [
        'domain' => $_POST['domain'] ?? '', 'contact' => $_POST['contact'] ?? '', 'server' => $_POST['server'] ?? '',
        'owner' => $_POST['owner'] ?? '', 'owner_note' => $_POST['owner_note'] ?? '',
    ]);
    if ($message === false) { echo json_encode(['ok' => false, 'message' => '还没有填写内容'], JSON_UNESCAPED_UNICODE); exit; }
    $left = prf_missing($actor, 1, 120, '', '', $orderId);
    $still = [];
    if ($left) {
        if (!empty($left[0]['need_domain'])) $still[] = '域名';
        if (!empty($left[0]['need_phone'])) $still[] = '联系方式';
        if (!empty($left[0]['need_server'])) $still[] = '服务器到期日';
    }
    echo json_encode(['ok' => true, 'message' => $message, 'complete' => !$left, 'still' => $still], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'message' => $e instanceof RuntimeException ? $e->getMessage() : '保存失败，请稍后再试'], JSON_UNESCAPED_UNICODE);
}
