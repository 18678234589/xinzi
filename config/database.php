<?php
/**
 * 数据库配置
 * 支持环境变量覆盖，便于本地开发（如通过SSH隧道连接远程库）：
 *   DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASS
 * 未设置时使用线上默认值，部署行为不变
 */
$isLocalDev = (PHP_OS_FAMILY === 'Windows' && file_exists((getenv('USERPROFILE') ?: '') . '\.ssh\id_ed25519_tfdev'));

define('DB_HOST', getenv('DB_HOST') ?: ($isLocalDev ? '127.0.0.1' : '192.168.1.254'));
define('DB_PORT', getenv('DB_PORT') ?: ($isLocalDev ? '13306' : '3306'));
define('DB_NAME', getenv('DB_NAME') ?: 'xinzi');
define('DB_USER', getenv('DB_USER') ?: 'xinzi');
define('DB_PASS', getenv('DB_PASS') ?: 'xinzi@123');

/**
 * 本地开发环境下自动确保 SSH 隧道运行
 */
function ensure_ssh_tunnel(): bool
{
    if (PHP_OS_FAMILY !== 'Windows') {
        return false;
    }
    // 检查 13306 端口是否已有监听
    $fp = @fsockopen('127.0.0.1', 13306, $errno, $errstr, 0.3);
    if ($fp) {
        fclose($fp);
        return true;
    }
    $batFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tunnel.bat';
    if (file_exists($batFile)) {
        pclose(popen('cmd.exe /c "' . $batFile . '"', 'r'));
    } else {
        $sshKey = (getenv('USERPROFILE') ?: '') . '\.ssh\id_ed25519_tfdev';
        if (!file_exists($sshKey)) {
            return false;
        }
        $cmd = sprintf(
            'cmd.exe /c start "dev-mysql-tunnel" /min "C:\Windows\System32\OpenSSH\ssh.exe" -i "%s" -o IdentitiesOnly=yes -o BatchMode=yes -o ServerAliveInterval=30 -o ServerAliveCountMax=3 -o ExitOnForwardFailure=yes -N -L 13306:192.168.1.254:3306 -p 20622 root@58.58.98.150',
            $sshKey
        );
        pclose(popen($cmd, 'r'));
    }
    for ($i = 0; $i < 15; $i++) {
        usleep(300000); // 300ms
        $fp = @fsockopen('127.0.0.1', 13306, $errno, $errstr, 0.3);
        if ($fp) {
            fclose($fp);
            return true;
        }
    }
    return false;
}

/**
 * 获取PDO数据库连接
 */
function db()
{
    static $pdo = null;
    if ($pdo === null) {
        $makePdo = function () {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
            return new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        };

        try {
            $pdo = $makePdo();
        } catch (PDOException $e) {
            // 本地开发环境若因隧道断开导致连接失败，自动尝试拉起隧道并重试一次
            if (DB_HOST === '127.0.0.1' && (string)DB_PORT === '13306' && ensure_ssh_tunnel()) {
                try {
                    $pdo = $makePdo();
                    return $pdo;
                } catch (PDOException $retryEx) {
                    $e = $retryEx;
                }
            }
            die('数据库连接失败: ' . $e->getMessage() . (
                (DB_HOST === '127.0.0.1' && (string)DB_PORT === '13306')
                    ? ' （本地开发模式：SSH 隧道 13306 未启动或连接 58.58.98.150:20622 失败，请检查网络或运行 dev.bat）'
                    : ''
            ));
        }
    }
    return $pdo;
}
