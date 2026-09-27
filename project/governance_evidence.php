<?php
require_once __DIR__ . '/../includes/ProjectGovernance.php';
$actor = ps_require_actor();
$isMember = (bool)pg_member($actor);
// 财务（管理员）不是管理层成员，只能查看“建议 / Bug / 主动做事”记录的举证。
if (!$isMember && ($actor['type'] ?? '') !== 'admin') { http_response_code(403); exit('无权限查看管理层激励考核'); }
$id = (int)($_GET['id'] ?? 0);
$q = db()->prepare('SELECT v.stored_name,v.mime_type,r.record_kind FROM project_governance_evidence v JOIN project_governance_records r ON r.id=v.record_id WHERE v.id=? LIMIT 1');
$q->execute([$id]);
$file = $q->fetch();
if ($file && !$isMember && $file['record_kind'] !== 'contribution') { http_response_code(403); exit('无权限查看此举证'); }
if (!$file ||!preg_match('/^[a-f0-9]{40}\.(?:png|jpg|webp|pdf)$/', $file['stored_name'])) { http_response_code(404); exit('文件不存在'); }
$allowedMime = ['image/png', 'image/jpeg', 'image/webp', 'application/pdf'];
if (!in_array($file['mime_type'], $allowedMime, true)) { http_response_code(404); exit('文件不存在'); }
$data = ps_private_read('governance', $file['stored_name']);
if ($data === null) { http_response_code(404); exit('文件不存在'); }
header('Content-Type: ' . $file['mime_type']);
header('Content-Disposition: ' . ($file['mime_type'] === 'application/pdf' ? 'attachment' : 'inline') . '; filename="evidence"');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cache-Control: private, no-store');
header('Content-Length: ' . strlen($data));
echo $data;
