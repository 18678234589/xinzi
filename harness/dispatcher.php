<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Vary: Origin');
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '' && $origin !== 'https://agent.laibangwo.com') {
    http_response_code(403); echo json_encode(['error'=>'FORBIDDEN_ORIGIN']); exit;
}
header('Access-Control-Allow-Origin: https://agent.laibangwo.com');
header('Access-Control-Allow-Credentials: true');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405); echo json_encode(['error'=>'METHOD_NOT_ALLOWED']); exit;
}
require_once __DIR__.'/../includes/auth.php';
if (empty($_SESSION['admin_id']) && empty($_SESSION['project_user_id'])) {
    http_response_code(401); echo json_encode(['error'=>'UNAUTHORIZED']); exit;
}
session_write_close();
$file = dirname(getenv('HARNESS_SSO_CONFIG') ?: '/var/lib/laibangwo-sso/harness/sso.php').'/dispatcher-profile.json';
$template = is_file($file) ? json_decode(file_get_contents($file), true) : null;
if (!is_array($template) || empty($template['profile']['name']) || empty($template['rules'])) {
    http_response_code(503); echo json_encode(['error'=>'DISPATCHER_NOT_CONFIGURED']); exit;
}
echo json_encode($template, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
