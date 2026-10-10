<?php
require_once __DIR__ . '/../includes/ProjectSettlement.php';
require_once __DIR__ . '/../includes/ProjectRuleLink.php';
// 订单列表“待配置”快捷对应分成规则的接口：GET ?order_id= 取候选规则；POST action=apply 按选中的规则对应（JSON）。
$actor = ps_require_actor();
$reply = function ($data, $status = 200) { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; };
try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') $reply(prl_info((int)($_GET['order_id'] ?? 0), $actor));
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') $reply(['error' => '请求方式不对'], 405);
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) $reply(['error' => '请求格式不正确，请刷新页面后重试'], 400);
    if (!hash_equals(ps_csrf_token(), (string)($in['csrf'] ?? ''))) $reply(['error' => '页面已过期，请刷新后重试'], 403);
    if (($in['action'] ?? '') !== 'apply') $reply(['error' => '操作无效'], 400);
    $msg = prl_apply((int)($in['order_id'] ?? 0), (int)($in['rule_id'] ?? 0), (int)($in['employee_id'] ?? 0), $actor);
    $reply(['ok' => true, 'msg' => $msg]);
} catch (RuntimeException $e) {
    $reply(['error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('rule_link_api: ' . $e->getMessage());
    $reply(['error' => '处理失败，请稍后重试'], 500);
}
