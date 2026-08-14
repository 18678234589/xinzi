<?php
/**
 * 客服绩效采集文件上传接口（供桌面采集工具 POST 调用，token 鉴权）
 *
 * 两种提交方式：
 *  1. multipart/form-data：字段 token，文件 file
 *  2. 直接 POST 文本内容：字段 token，可选字段 filename
 *
 * 入库后统一调用 import_cs_perf_file()（与计划任务 scripts/cs_perf_sync.php 共用逻辑）。
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/cs_perf_config.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => '仅支持POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

// token 鉴权
$token = $_POST['token'] ?? '';
if ($token === '' || !hash_equals(CS_PERF_UPLOAD_TOKEN, (string)$token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'token无效'], JSON_UNESCAPED_UNICODE);
    exit;
}

$uploadDir = CS_PERF_UPLOAD_DIR;
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
if (!is_dir($uploadDir)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '上传目录不可写'], JSON_UNESCAPED_UNICODE);
    exit;
}

$savedPath = '';
$srcName = '';

if (!empty($_FILES['file']) && is_uploaded_file($_FILES['file']['tmp_name']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
    $srcName = basename($_FILES['file']['name'] ?: ('upload_' . date('Ymd_His') . '.csv'));
    $safe = preg_replace('/[^\w.\-]/u', '_', $srcName);
    $savedPath = $uploadDir . '/' . date('Ymd_His') . '_' . $safe;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $savedPath)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => '文件保存失败'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} else {
    // 兼容直接 POST 内容
    $content = file_get_contents('php://input');
    if ($content === '' || trim($content) === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => '未收到文件'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $srcName = $_POST['filename'] ?? ('upload_' . date('Ymd_His') . '.csv');
    $safe = preg_replace('/[^\w.\-]/u', '_', basename($srcName));
    $savedPath = $uploadDir . '/' . date('Ymd_His') . '_' . $safe;
    if (@file_put_contents($savedPath, $content) === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => '文件保存失败'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$result = import_cs_perf_file($savedPath, 'upload:' . $srcName);

echo json_encode([
    'ok'     => true,
    'matched' => $result['matched'],
    'pending' => $result['pending'],
    'errors'  => $result['errors'],
    'detail'  => $result['detail'],
], JSON_UNESCAPED_UNICODE);
