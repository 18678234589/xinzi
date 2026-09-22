<?php
require_once __DIR__ . '/../includes/ProjectSettlement.php';
$actor = ps_require_actor();
$costId = (int)($_GET['id'] ?? 0);
$q = db()->prepare('SELECT order_id,proof_path FROM project_costs WHERE id=?'); $q->execute([$costId]);
$cost = $q->fetch();
if (!$cost || !$cost['proof_path']) { http_response_code(404); exit('凭证不存在'); }
ps_order((int)$cost['order_id'], $actor);
$name = basename($cost['proof_path']);
$file = dirname(__DIR__, 2) . '/project_proofs_private/' . $name;
if (!is_file($file)) { http_response_code(404); exit('凭证文件不存在'); }
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file);
if (!in_array($mime, ['image/jpeg','image/png','application/pdf'], true)) { http_response_code(404); exit('文件类型无效'); }
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="proof-' . $costId . '"');
header('X-Content-Type-Options: nosniff');
readfile($file);
