<?php
require_once __DIR__ . '/../includes/ProjectGovernance.php';
pg_require_member();
$id = (int)($_GET['id'] ?? 0);
$q = db()->prepare('SELECT stored_name,mime_type FROM project_governance_evidence WHERE id=? LIMIT 1');
$q->execute([$id]);
$file = $q->fetch();
if (!$file || !preg_match('/^[a-f0-9]{40}\.(?:png|jpg|webp|pdf)$/', $file['stored_name'])) { http_response_code(404); exit('文件不存在'); }
$allowedMime = ['image/png', 'image/jpeg', 'image/webp', 'application/pdf'];
if (!in_array($file['mime_type'], $allowedMime, true)) { http_response_code(404); exit('文件不存在'); }
$path = pg_private_dir() . DIRECTORY_SEPARATOR . $file['stored_name'];
if (!is_file($path)) { http_response_code(404); exit('文件不存在'); }
header('Content-Type: ' . $file['mime_type']);
header('Content-Disposition: ' . ($file['mime_type'] === 'application/pdf' ? 'attachment' : 'inline') . '; filename="evidence"');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cache-Control: private, no-store');
header('Content-Length: ' . filesize($path));
readfile($path);
