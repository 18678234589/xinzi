<?php
/**
 * 登录防暴力破解：失败次数按 IP / 账号 / 账号+IP 三个维度在 15 分钟窗口内累计，超限即锁定。
 * 失败记录只存哈希；表尚未建立时放行（不能因为迁移没跑就把所有人锁在外面）。
 */
const AUTH_THROTTLE_WINDOW_MINUTES = 15;
const AUTH_THROTTLE_LIMIT_IP = 30;     // 同一出口 IP（公司网络共用一个 IP，放宽）
const AUTH_THROTTLE_LIMIT_PAIR = 5;    // 同一 IP 反复试同一账号
const AUTH_THROTTLE_LIMIT_USER = 10;   // 同一账号被多个 IP 轮流试

/**
 * 真实访问者 IP。站点在反向代理后面：直连方是内网代理时，取 X-Forwarded-For 最右一项
 * （代理自己追加的那一项；左边的内容由访问者伪造，不可信）。
 */
function auth_client_ip()
{
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $isProxy = filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    if ($isProxy && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = array_map('trim', explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']));
        $last = end($parts);
        if (filter_var($last, FILTER_VALIDATE_IP) !== false) return $last;
    }
    return $remote;
}

function auth_throttle_keys($username, $ip = null)
{
    $ip = $ip === null ? auth_client_ip() : $ip;
    $user = mb_strtolower(trim(mb_substr((string)$username, 0, 100)), 'UTF-8');
    return [
        'ip' => hash('sha256', 'ip:' . $ip),
        'user' => hash('sha256', 'user:' . $user),
        'pair' => hash('sha256', 'pair:' . $user . '|' . $ip),
    ];
}

/** 是否已被锁定：任一维度在窗口内的失败次数达到上限。 */
function auth_login_locked($username)
{
    $keys = auth_throttle_keys($username);
    try {
        $q = db()->prepare('SELECT key_hash, COUNT(*) AS c FROM login_attempts WHERE key_hash IN (?,?,?) AND created_at > (NOW() - INTERVAL ' . AUTH_THROTTLE_WINDOW_MINUTES . ' MINUTE) GROUP BY key_hash');
        $q->execute(array_values($keys));
        $counts = $q->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) {
        return false;
    }
    return (int)($counts[$keys['ip']] ?? 0) >= AUTH_THROTTLE_LIMIT_IP
        || (int)($counts[$keys['pair']] ?? 0) >= AUTH_THROTTLE_LIMIT_PAIR
        || (int)($counts[$keys['user']] ?? 0) >= AUTH_THROTTLE_LIMIT_USER;
}

/**
 * 记一次失败。$guess=false 表示只是验证码错（没有真正试密码）：只计 IP 维度，
 * 否则任何人乱填验证码就能把别人的账号锁住。
 */
function auth_login_failed($username, $guess = true)
{
    $keys = auth_throttle_keys($username);
    $use = $guess ? array_values($keys) : [$keys['ip']];
    try {
        $ins = db()->prepare('INSERT INTO login_attempts (key_hash) VALUES (?)');
        foreach ($use as $hash) $ins->execute([$hash]);
        if (mt_rand(1, 50) === 1) db()->exec('DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)');
    } catch (PDOException $e) {
        // 记录失败不能影响登录页本身。
    }
}

/** 登录成功：清掉该账号（及该账号+该 IP）的失败记录；IP 维度保留。 */
function auth_login_succeeded($username)
{
    $keys = auth_throttle_keys($username);
    try {
        db()->prepare('DELETE FROM login_attempts WHERE key_hash IN (?,?)')->execute([$keys['user'], $keys['pair']]);
    } catch (PDOException $e) {
    }
}

/**
 * 旧版 MD5 密码在登录成功后顺手升级为 bcrypt。仍是默认密码（密码=登录名）的不升级，
 * 页头靠旧哈希识别并提醒“请修改默认密码”。
 */
function auth_password_upgrade($adminId, $plain, $stored, $username)
{
    if (!preg_match('/^[a-f0-9]{32}$/Di', (string)$stored) || (string)$plain === (string)$username) return;
    try {
        db()->prepare('UPDATE admins SET password=? WHERE id=? AND password=?')->execute([password_hash((string)$plain, PASSWORD_DEFAULT), (int)$adminId, (string)$stored]);
    } catch (PDOException $e) {
    }
}
