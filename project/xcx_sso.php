<?php
/**
 * 小程序商城单点登录跳转入口
 * 携带当前登录用户身份生成签名并跳转至 xcx.laibangwo.com
 */
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ProjectSettlement.php';

// 未登录跳转登录页
if (empty($_SESSION['admin_id']) && empty($_SESSION['project_user_id'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$cfgFile = dirname(__DIR__) . '/config/xcxsc_sso.php';
$cfg = is_file($cfgFile) ? require $cfgFile : [
    'secret' => 'laibangwo_xcxsc_sso_secret_20261124_9f8e7d6c5b4a3210',
    'sso_url' => 'https://xcx.laibangwo.com/index.php?r=admin%2Fpassport%2Fsso',
];

$secret = $cfg['secret'];
$ssoUrl = $cfg['sso_url'];

$username = '';
$passwordHash = '';
$mobile = '';
$nickname = '';

if (!empty($_SESSION['admin_id'])) {
    $adminId = (int)$_SESSION['admin_id'];
    $stmt = db()->prepare('SELECT id, username, password FROM admins WHERE id = ? LIMIT 1');
    $stmt->execute([$adminId]);
    $row = $stmt->fetch();
    if ($row) {
        $username = (string)$row['username'];
        $passwordHash = (string)$row['password'];
        $nickname = $username;
    }
} elseif (!empty($_SESSION['project_user_id'])) {
    $userId = (int)$_SESSION['project_user_id'];
    $stmt = db()->prepare('SELECT u.id, u.username, u.phone, u.password_hash, e.name as real_name FROM project_users u LEFT JOIN employees e ON e.id = u.employee_id WHERE u.id = ? AND u.is_active = 1 LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if ($row) {
        $username = (string)$row['username'];
        $passwordHash = (string)$row['password_hash'];
        $mobile = (string)($row['phone'] ?? '');
        $nickname = (string)($row['real_name'] ?? $username);
    }
}

if ($username === '') {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// 构造签名 payload
$payload = [
    'u' => $username,
    'h' => $passwordHash,
    'm' => $mobile,
    'n' => $nickname,
    't' => time(),
    'nonce' => bin2hex(random_bytes(8)),
];

$data = base64_encode(json_encode($payload));
$sign = hash_hmac('sha256', $data, $secret);

$target = $ssoUrl . '&data=' . urlencode($data) . '&sign=' . $sign;

header('Location: ' . $target, true, 302);
exit;
