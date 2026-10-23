<?php
/**
 * 7天内免登录（Remember Me）支持
 * 规则：
 * 1. 不使用默认密码的用户，勾选后可在 7 天内免登录；
 * 2. 使用默认密码的用户不可开启免登录，并提示修改密码后才支持。
 */

const AUTH_REMEMBER_DAYS = 7;
const AUTH_REMEMBER_LIFETIME = 604800; // 7 * 86400

function auth_remember_is_https()
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
}

function auth_remember_ensure()
{
    static $done = false;
    if ($done) return;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS auth_remember_tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            token_hash CHAR(64) NOT NULL UNIQUE,
            account_type ENUM('admin', 'employee') NOT NULL,
            account_id INT NOT NULL,
            auth_version INT NOT NULL DEFAULT 0,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_token_expires (token_hash, expires_at),
            KEY idx_account (account_type, account_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $done = true;
    } catch (PDOException $e) {
        // 数据库不可用或无DDL权限时不阻断应用
    }
}

/**
 * 判断账号或输入的密码是否为默认密码
 */
function auth_is_default_password($type, $account, $plainPassword = null)
{
    if (!$account) return false;
    $username = (string)($account['username'] ?? '');

    if ($plainPassword !== null) {
        $plain = (string)$plainPassword;
        if ($username !== '' && strcasecmp($plain, $username) === 0) {
            return true;
        }
        if (in_array(strtolower($plain), ['123456', 'admin123', '12345678', '88888888'], true)) {
            return true;
        }
    }

    if ($type === 'admin') {
        $stored = (string)($account['password'] ?? '');
        if ($username !== '' && preg_match('/^[a-f0-9]{32}$/Di', $stored)) {
            if (hash_equals(strtolower($stored), md5($username))) {
                return true;
            }
        }
        if ($username !== '' && !preg_match('/^[a-f0-9]{32}$/Di', $stored) && password_verify($username, $stored)) {
            return true;
        }
        return false;
    }

    if ($type === 'employee') {
        // 未改过密码（password_changed_at 为空）
        if (function_exists('auth_staff_needs_password_change') && auth_staff_needs_password_change($account)) {
            return true;
        }
        if (array_key_exists('password_changed_at', $account) && empty($account['password_changed_at'])) {
            return true;
        }
        // 密码哈希对应的值为用户名
        if ($username !== '' && !empty($account['password_hash']) && password_verify($username, $account['password_hash'])) {
            return true;
        }
        return false;
    }

    return false;
}

/**
 * 创建 7 天免登录 Token 并下发 Cookie
 */
function auth_remember_create($type, $accountId, $authVersion = 0)
{
    auth_remember_ensure();
    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $expires = time() + AUTH_REMEMBER_LIFETIME;

    try {
        if (mt_rand(1, 20) === 1) {
            db()->exec("DELETE FROM auth_remember_tokens WHERE expires_at < NOW()");
        }
        $stmt = db()->prepare("INSERT INTO auth_remember_tokens (token_hash, account_type, account_id, auth_version, expires_at) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$tokenHash, $type, (int)$accountId, (int)$authVersion, date('Y-m-d H:i:s', $expires)]);
    } catch (Throwable $e) {
        return false;
    }

    $secure = auth_remember_is_https();
    @setcookie('remember_token', $rawToken, [
        'expires' => $expires,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    $_COOKIE['remember_token'] = $rawToken;

    if (session_id() !== '') {
        @setcookie(session_name(), session_id(), [
            'expires' => $expires,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
    return true;
}

/**
 * 清理客户端 Cookie 及服务端 Token
 */
function auth_remember_clear()
{
    if (!empty($_COOKIE['remember_token'])) {
        $raw = (string)$_COOKIE['remember_token'];
        if (preg_match('/^[a-f0-9]{64}$/D', $raw)) {
            try {
                auth_remember_ensure();
                $stmt = db()->prepare("DELETE FROM auth_remember_tokens WHERE token_hash = ?");
                $stmt->execute([hash('sha256', $raw)]);
            } catch (Throwable $e) {}
        }
    }

    $secure = auth_remember_is_https();
    @setcookie('remember_token', '', [
        'expires' => time() - 86400,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    unset($_COOKIE['remember_token']);
}

/**
 * 撤销指定账号的所有记住登录 Token（如改密时）
 */
function auth_remember_revoke_user($type, $accountId)
{
    try {
        auth_remember_ensure();
        $stmt = db()->prepare("DELETE FROM auth_remember_tokens WHERE account_type = ? AND account_id = ?");
        $stmt->execute([$type, (int)$accountId]);
    } catch (Throwable $e) {}
}

/**
 * 检查并恢复 7 天免登录会话
 */
function auth_remember_check()
{
    if (!empty($_SESSION['admin_id']) || !empty($_SESSION['project_user_id'])) {
        return;
    }

    if (empty($_COOKIE['remember_token'])) {
        return;
    }

    $raw = (string)$_COOKIE['remember_token'];
    if (!preg_match('/^[a-f0-9]{64}$/D', $raw)) {
        auth_remember_clear();
        return;
    }

    try {
        auth_remember_ensure();
        $hash = hash('sha256', $raw);
        $stmt = db()->prepare("SELECT * FROM auth_remember_tokens WHERE token_hash = ? AND expires_at > NOW() LIMIT 1");
        $stmt->execute([$hash]);
        $tokenRow = $stmt->fetch();
        if (!$tokenRow) {
            auth_remember_clear();
            return;
        }

        $type = $tokenRow['account_type'];
        $accountId = (int)$tokenRow['account_id'];
        $tokenVersion = (int)$tokenRow['auth_version'];

        if ($type === 'admin') {
            $q = db()->prepare("SELECT * FROM admins WHERE id = ? LIMIT 1");
            $q->execute([$accountId]);
            $admin = $q->fetch();
            if (!$admin || (int)($admin['auth_version'] ?? 0) !== $tokenVersion || auth_is_default_password('admin', $admin)) {
                auth_remember_clear();
                return;
            }
            $_SESSION['admin_id'] = (int)$admin['id'];
            $_SESSION['admin_username'] = $admin['username'];
            $_SESSION['auth_version'] = (int)($admin['auth_version'] ?? 0);
            $_SESSION['vault_pw_ok'] = false;
            unset($_SESSION['weak_password']);
            @session_regenerate_id(true);
        } elseif ($type === 'employee') {
            $q = db()->prepare("SELECT * FROM project_users WHERE id = ? AND is_active = 1 LIMIT 1");
            $q->execute([$accountId]);
            $staff = $q->fetch();
            if (!$staff || (int)($staff['auth_version'] ?? 0) !== $tokenVersion || auth_is_default_password('employee', $staff)) {
                auth_remember_clear();
                return;
            }
            if (function_exists('auth_staff_login_seen')) {
                auth_staff_login_seen($staff['id']);
            }
            $_SESSION['project_user_id'] = (int)$staff['id'];
            $_SESSION['auth_version'] = (int)($staff['auth_version'] ?? 0);
            $_SESSION['vault_pw_ok'] = false;
            unset($_SESSION['weak_password']);
            @session_regenerate_id(true);
        } else {
            auth_remember_clear();
        }
    } catch (Throwable $e) {
        // 免登录恢复失败不阻断页面加载
    }
}
