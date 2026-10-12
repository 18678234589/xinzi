<?php
/**
 * 解除登录锁定（命令行）：
 *   php tools/login_unlock.php <登录名>   解除某账号
 *   php tools/login_unlock.php --ip <IP>  解除某个 IP
 *   php tools/login_unlock.php --all      清空全部失败记录
 */
if (PHP_SAPI !== 'cli') exit('仅限命令行');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/AuthThrottle.php';

$args = array_slice($argv, 1);
if (!$args) exit("用法: php tools/login_unlock.php <登录名> | --ip <IP> | --all\n");
if ($args[0] === '--all') {
    $n = db()->exec('DELETE FROM login_attempts');
} elseif ($args[0] === '--ip' && isset($args[1])) {
    $n = db()->prepare('DELETE FROM login_attempts WHERE key_hash=?');
    $n->execute([hash('sha256', 'ip:' . $args[1])]);
    $n = $n->rowCount();
} else {
    // 账号维度，加上该账号在任意 IP 上的组合记录无法反推，所以只清账号维度；组合记录 15 分钟后自然失效。
    $keys = auth_throttle_keys($args[0], '');
    $n = db()->prepare('DELETE FROM login_attempts WHERE key_hash=?');
    $n->execute([$keys['user']]);
    $n = $n->rowCount();
    echo "注意：同一 IP 反复试该账号产生的组合锁定无法按账号清除，15 分钟后自动解除，或用 --ip / --all。\n";
}
echo "已清除 {$n} 条记录\n";
