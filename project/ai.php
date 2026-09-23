<?php
// AI 智能识别：把粘贴的淘宝 / 微信订单或聊天记录识别成录单字段（只返回识别结果，不写库）。
require_once __DIR__ . '/../includes/ProjectBusiness.php';
$actor = ps_require_actor();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => '仅支持 POST']); exit; }
if (!hash_equals(ps_csrf_token(), (string)($_POST['csrf'] ?? ''))) { http_response_code(403); echo json_encode(['ok' => false, 'error' => '页面已过期，请刷新后重试']); exit; }
if (!ps_ai_ready()) { echo json_encode(['ok' => false, 'error' => 'AI 尚未启用，请联系管理员在“系统设置 › AI 接入”中配置']); exit; }
// 简单限流：每个会话 10 秒内最多 3 次，避免误触连续调用。
$now = time();
$_SESSION['ai_calls'] = array_values(array_filter($_SESSION['ai_calls'] ?? [], function ($t) use ($now) { return $t > $now - 10; }));
if (count($_SESSION['ai_calls']) >= 3) { echo json_encode(['ok' => false, 'error' => '操作太频繁，请稍后再试']); exit; }
$_SESSION['ai_calls'][] = $now;
session_write_close();

try {
    $businesses = ps_actor_businesses($actor);
    $shops = db()->query('SELECT name FROM shops ORDER BY sort,id')->fetchAll(PDO::FETCH_COLUMN);
    $kinds = [];
    foreach ($businesses as $business) foreach (ps_business_order_kinds($business) as $kind) $kinds[$kind] = true;
    $result = ps_ai_parse_order((string)($_POST['text'] ?? ''), $businesses, $shops, array_keys($kinds));
    ps_audit('ai', 0, 'parse_order', $actor, ['chars' => mb_strlen((string)($_POST['text'] ?? '')), 'order_no' => $result['order_no']]);
    echo json_encode(['ok' => true, 'fields' => $result], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
