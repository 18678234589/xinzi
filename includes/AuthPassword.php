<?php
/** Legacy MD5 is accepted only for compatibility; new passwords use password_hash. */
function auth_password_verify($plain, $stored)
{
    return preg_match('/^[a-f0-9]{32}$/Di', (string)$stored)
        ? hash_equals(strtolower((string)$stored), md5((string)$plain))
        : password_verify((string)$plain, (string)$stored);
}
function auth_password_validate($password, $confirmation, $username)
{
    if (strlen($password) < 8 || strlen($password) > 72 || in_array($password, ['12345678','123456789','88888888','11111111'], true)) throw new RuntimeException('新密码须为 8–72 字节，不能使用简单密码');
    if (strcasecmp($password, (string)$username) === 0) throw new RuntimeException('新密码不能与用户名相同');
    if ($password !== $confirmation) throw new RuntimeException('两次输入的新密码不一致');
}
function auth_session_validate()
{
    $type = isset($_SESSION['admin_id']) ? 'admin' : (isset($_SESSION['project_user_id']) ? 'employee' : null);
    if (!$type) return;
    $field = $type === 'admin' ? 'admin_id' : 'project_user_id';
    $table = $type === 'admin' ? 'admins' : 'project_users';
    $q = db()->prepare("SELECT * FROM $table WHERE id=?");
    $q->execute([(int)$_SESSION[$field]]);
    $account = $q->fetch();
    if (!$account || ($type === 'employee' && !$account['is_active']) || (int)($account['auth_version'] ?? 0) !== (int)($_SESSION['auth_version'] ?? 0)) {
        unset($_SESSION['admin_id'], $_SESSION['admin_username'], $_SESSION['project_user_id'], $_SESSION['auth_version']);
        session_regenerate_id(true);
    }
}
