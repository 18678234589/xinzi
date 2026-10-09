<?php
// Install in me.laibangwo.com's root. Keep configuration and codes outside Web root.
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
function wb_fail($status) { http_response_code($status); header('Content-Type: application/json'); echo '{"error":"sso_rejected"}'; exit; }
$cfgFile = '/etc/laibangwo-workbench/sso.php';
if (!is_file($cfgFile)) wb_fail(503);
$cfg = require $cfgFile;
require_once __DIR__ . '/includes/auth.php';
function wb_identity($subject = null) {
    if ($subject === null) {
        if (!empty($_SESSION['admin_id'])) $subject = 'admin:' . (int)$_SESSION['admin_id'];
        elseif (!empty($_SESSION['project_user_id'])) $subject = 'project:' . (int)$_SESSION['project_user_id'];
        else return null;
    }
    if (!preg_match('/^(admin|project):([1-9][0-9]{0,18})$/D', $subject, $parts)) return null;
    $sql = $parts[1] === 'admin' ? 'SELECT id,username FROM admins WHERE id=?' : 'SELECT id,username FROM project_users WHERE id=? AND is_active=1';
    $q = db()->prepare($sql); $q->execute([$parts[2]]); $user = $q->fetch();
    if (!$user) return null;
    return ['issuer'=>'https://me.laibangwo.com','subject'=>$subject,'username'=>(string)$user['username'],'active'=>true];
}
$action = $_GET['action'] ?? '';
if ($action === 'authorize' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $state = $_GET['state'] ?? ''; $challenge = $_GET['code_challenge'] ?? ''; $redirect = $_GET['redirect_uri'] ?? '';
    if (!in_array($redirect,$cfg['callbacks'],true) || !preg_match('/^[A-Za-z0-9_-]{32,100}$/D', $state) || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $challenge)) wb_fail(400);
    $identity = wb_identity();
    if (!$identity) { header('Location: ' . BASE_URL . '/login.php', true, 302); exit; }
    session_write_close();
    $code = bin2hex(random_bytes(32));
    $path = $cfg['codes_dir'] . '/' . hash('sha256', $code) . '.json';
    $f = fopen($path, 'x'); if (!$f) wb_fail(503);
    chmod($path, 0600);
    fwrite($f, json_encode(['subject'=>$identity['subject'],'challenge'=>$challenge,'redirect'=>$redirect,'expires'=>time()+60], JSON_UNESCAPED_UNICODE)); fclose($f);
    // Remove expired code files without following symlinks or accepting user-supplied paths.
    foreach (glob($cfg['codes_dir'].'/*.json') ?: [] as $old) if (!is_link($old) && filemtime($old) < time()-300) @unlink($old);
    header('Location: ' . $redirect . '?' . http_build_query(['state'=>$state,'code'=>$code]), true, 302); exit;
}
if ($action !== 'exchange' || $_SERVER['REQUEST_METHOD'] !== 'POST') wb_fail(405);
session_write_close();
$raw = file_get_contents('php://input', false, null, 0, 16385); if (strlen($raw) > 16384) wb_fail(413);
$stamp = $_SERVER['HTTP_X_WORKBENCH_TIME'] ?? ''; $sig = $_SERVER['HTTP_X_WORKBENCH_SIGNATURE'] ?? '';
$secret = trim(file_get_contents($cfg['secret_file']));
if (strlen($secret)<32 || !ctype_digit($stamp) || abs(time()-(int)$stamp)>30 || !hash_equals(hash_hmac('sha256',$stamp.'.'.$raw,$secret),$sig)) wb_fail(403);
$body = json_decode($raw,true);
if (!is_array($body) || !preg_match('/^[a-f0-9]{64}$/D',$body['code'] ?? '') || !preg_match('/^[A-Za-z0-9_-]{43}$/D',$body['verifier'] ?? '') || !in_array($body['redirect_uri'] ?? '',$cfg['callbacks'],true)) wb_fail(400);
$path = $cfg['codes_dir'].'/'.hash('sha256',$body['code']).'.json';
$f = @fopen($path,'r+'); if (!$f || !flock($f,LOCK_EX)) wb_fail(403);
$record = json_decode(stream_get_contents($f),true);
$challenge = rtrim(strtr(base64_encode(hash('sha256',$body['verifier'],true)),'+/','-_'),'=');
if (!$record || $record['expires'] < time() || $record['redirect'] !== $body['redirect_uri'] || !hash_equals($record['challenge'],$challenge)) { fclose($f); wb_fail(403); }
// Consume under the file lock: a concurrent reader can only see an empty file.
ftruncate($f,0); fflush($f); @unlink($path); fclose($f);
$identity = wb_identity($record['subject']); if (!$identity) wb_fail(403);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($identity,JSON_UNESCAPED_UNICODE);
