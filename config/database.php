<?php
/**
 * 数据库配置
 * 支持环境变量覆盖，便于本地开发（如通过SSH隧道连接远程库）：
 *   DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASS
 * 未设置时使用线上默认值，部署行为不变
 */
define('DB_HOST', getenv('DB_HOST') ?: '192.168.1.254');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'xinzi');
define('DB_USER', getenv('DB_USER') ?: 'xinzi');
define('DB_PASS', getenv('DB_PASS') ?: 'xinzi@123');

/**
 * 获取PDO数据库连接
 */
function db()
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            die('数据库连接失败: ' . $e->getMessage());
        }
    }
    return $pdo;
}
