<?php
/**
 * 数据库配置
 */
define('DB_HOST', '58.58.98.150');
define('DB_PORT', '3306');
define('DB_NAME', 'xinzi');
define('DB_USER', 'xinzi');
define('DB_PASS', 'xinzi@123');

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
