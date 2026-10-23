<?php
require_once __DIR__ . '/../includes/ProjectGovernance.php';
$actor = ps_require_actor();
$isMember = (bool)pg_member($actor);
$isFinance = ($actor['type'] ?? '') === 'admin' || ($actor['role'] ?? '') === 'finance';
$id = (int)($_GET['id'] ?? 0);
$q = db()->prepare('SELECT v.stored_name,v.mime_type,r.record_kind,r.id AS record_id FROM project_governance_evidence v JOIN project_governance_records r ON r.id=v.record_id WHERE v.id=? LIMIT 1');
$q->execute([$id]);
$file = $q->fetch();
if (!$isMember && !$isFinance) {
    $own = db()->prepare('SELECT 1 FROM project_welfare_contributions WHERE governance_record_id=? AND (employee_id=? OR created_by_employee_id=?)');
    $own->execute([(int)($file['record_id'] ?? 0),(int)($actor['employee_id'] ?? 0),(int)($actor['employee_id'] ?? 0)]);
    if (!$own->fetchColumn()) { http_response_code(403); exit('无权限查看此举证'); }
}
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
