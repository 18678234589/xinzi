<?php
require_once __DIR__ . '/../includes/ProjectSettlement.php';
$actor = ps_require_actor();
$costId = (int)($_GET['id'] ?? 0);
$requestId = (int)($_GET['request_id'] ?? 0);
$proofPath = null;
$orderId = 0;
$displayName = 'proof';

if ($requestId > 0) {
    $q = db()->prepare('SELECT order_id, data_json FROM project_order_requests WHERE id=?');
    $q->execute([$requestId]);
    $req = $q->fetch();
    if ($req) {
        $data = json_decode((string)$req['data_json'], true) ?: [];
        $proofPath = $data['proof_path'] ?? null;
        $orderId = (int)$req['order_id'];
        $displayName = 'delivery-proof-' . $requestId;
    }
} elseif ($costId > 0) {
    $q = db()->prepare('SELECT order_id,proof_path FROM project_costs WHERE id=?');
    $q->execute([$costId]);
    $cost = $q->fetch();
    if ($cost) {
        $proofPath = $cost['proof_path'];
        $orderId = (int)$cost['order_id'];
        $displayName = 'cost-proof-' . $costId;
    }
}

if (!$proofPath || !$orderId) { http_response_code(404); exit('凭证不存在'); }
ps_order($orderId, $actor);
$name = basename($proofPath);
$data = ps_private_read('proofs', $name);
if ($data === null) { http_response_code(404); exit('凭证文件不存在'); }
$mime = ps_detect_mime($data);
if (!in_array($mime, ['image/jpeg','image/png','application/pdf'], true)) { http_response_code(404); exit('文件类型无效'); }
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . $displayName . '"');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . strlen($data));
echo $data;
