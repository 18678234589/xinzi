<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
require_once __DIR__ . '/../includes/AgentCloudTools.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo '{"ok":false,"message":"仅支持签名 POST 请求"}'; exit; }
$private = dirname(getenv('HARNESS_SSO_CONFIG') ?: '/var/lib/laibangwo-sso/harness/sso.php');
$secret = trim((string)@file_get_contents($private . '/cloud-tools.secret'));
try {
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    $request = ac_authenticate($raw, $_SERVER['HTTP_X_CLOUD_TIMESTAMP'] ?? '', $_SERVER['HTTP_X_CLOUD_SIGNATURE'] ?? '', $secret, time());
} catch (Throwable $e) { http_response_code(403); echo '{"ok":false,"message":"智能体请求鉴权失败"}'; exit; }
require_once __DIR__ . '/../includes/BizcnDomains.php';
$actor = ps_actor_from_subject($request['subject']);
if (!$actor || ($actor['type'] === 'employee' && auth_staff_needs_password_change($actor)) || !bd_can_access($actor)) {
    http_response_code(403); echo '{"ok":false,"message":"当前网站账号没有域名或CDN管理权限"}'; exit;
}
session_write_close();
try {
    if (!is_array($request['params'] ?? null)) throw new RuntimeException('操作参数必须是对象');
    $result = ac_dispatch((string)($request['action'] ?? ''), $request['params'], $actor, $secret, $private . '/cloud-operations', time());
    echo json_encode($result, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'message'=>$e instanceof RuntimeException ? $e->getMessage() : '云资源接口暂不可用，请查询状态后再处理'], JSON_UNESCAPED_UNICODE);
}
