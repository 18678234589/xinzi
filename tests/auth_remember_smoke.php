<?php
/**
 * 7天免登录与自媒体工作台新窗口打开冒烟测试
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/AuthRemember.php';

function test_assert($cond, $msg) {
    if (!$cond) {
        throw new RuntimeException("Assertion failed: $msg");
    }
    echo "  [PASS] $msg\n";
}

echo "=== 1. 验证默认密码检测逻辑 ===\n";
// 管理员默认密码测试
$adminDefault1 = ['username' => 'admin', 'password' => md5('admin'), 'auth_version' => 0];
$adminDefault2 = ['username' => 'testadmin', 'password' => password_hash('admin123', PASSWORD_DEFAULT), 'auth_version' => 0];
$adminCustom   = ['username' => 'superadmin', 'password' => password_hash('Custom#Pass2026', PASSWORD_DEFAULT), 'auth_version' => 1];

test_assert(auth_is_default_password('admin', $adminDefault1), '管理员 md5(username) 判定为默认密码');
test_assert(auth_is_default_password('admin', $adminDefault2, 'admin123'), '管理员 admin123 判定为默认密码');
test_assert(auth_is_default_password('admin', $adminDefault1, 'admin'), '管理员明文与账号相同判定为默认密码');
test_assert(!auth_is_default_password('admin', $adminCustom, 'Custom#Pass2026'), '管理员已修改自定义强密码判定为非默认密码');

// 员工默认密码测试
$staffDefault1 = ['username' => 'luanxin', 'password_hash' => password_hash('luanxin', PASSWORD_DEFAULT), 'password_changed_at' => null, 'auth_version' => 0];
$staffDefault2 = ['username' => 'zhangsan', 'password_hash' => password_hash('123456', PASSWORD_DEFAULT), 'password_changed_at' => null, 'auth_version' => 0];
$staffCustom   = ['username' => 'luanxin', 'password_hash' => password_hash('MyNewP@ssw0rd888', PASSWORD_DEFAULT), 'password_changed_at' => '2026-10-23 10:00:00', 'auth_version' => 1];

test_assert(auth_is_default_password('employee', $staffDefault1), '员工未修改密码（password_changed_at为空）判定为默认密码');
test_assert(auth_is_default_password('employee', $staffDefault1, 'luanxin'), '员工明文与账号相同判定为默认密码');
test_assert(auth_is_default_password('employee', $staffDefault2, '123456'), '员工 123456 判定为默认密码');
test_assert(!auth_is_default_password('employee', $staffCustom, 'MyNewP@ssw0rd888'), '员工已改密判定为非默认密码');

echo "\n=== 2. 验证 auth_remember_tokens 数据表建表 ===\n";
auth_remember_ensure();
$tableExists = (bool)db()->query("SHOW TABLES LIKE 'auth_remember_tokens'")->fetch();
test_assert($tableExists, 'auth_remember_tokens 数据表已正确存在');

echo "\n=== 3. 验证 7天免登录 Token 生成与会话恢复 ===\n";
// 创建临时测试账号
$testUsername = 'test_rem_user_' . mt_rand(1000, 9999);
$testPass = 'ValidCustomPass#2026';
db()->prepare("INSERT INTO admins (username, password, auth_version) VALUES (?, ?, 0)")
    ->execute([$testUsername, password_hash($testPass, PASSWORD_DEFAULT)]);
$adminId = (int)db()->lastInsertId();

try {
    // 1) 初始状态：无 cookie，未登录
    $_SESSION = [];
    unset($_COOKIE['remember_token']);
    auth_remember_check();
    test_assert(empty($_SESSION['admin_id']), '无 Cookie 时未恢复会话');

    // 2) 创建 7 天免登录
    $created = auth_remember_create('admin', $adminId, 0);
    test_assert($created, '成功为管理员生成 7 天免登录 Token');
    test_assert(!empty($_COOKIE['remember_token']), '生成后下发 remember_token Cookie');

    // 检查 DB 中是否有此 token
    $tokenHash = hash('sha256', $_COOKIE['remember_token']);
    $tokenRow = db()->query("SELECT * FROM auth_remember_tokens WHERE token_hash=" . db()->quote($tokenHash))->fetch();
    test_assert($tokenRow && (int)$tokenRow['account_id'] === $adminId, 'DB 中成功存入对应 Token 记录');

    // 3) 模拟新会话（清理 $_SESSION，仅带 $_COOKIE['remember_token']）
    $_SESSION = [];
    auth_remember_check();
    test_assert(!empty($_SESSION['admin_id']) && (int)$_SESSION['admin_id'] === $adminId, '成功通过 remember_token 恢复管理员登录态');
    test_assert($_SESSION['admin_username'] === $testUsername, '恢复的用户名正确');

    // 4) 测试默认密码拦截：若该用户是默认密码，即使有 Token 也应被拒绝并清除
    db()->prepare("UPDATE admins SET password=? WHERE id=?")->execute([md5($testUsername), $adminId]);
    $_SESSION = [];
    auth_remember_check();
    test_assert(empty($_SESSION['admin_id']), '默认密码用户无法使用 Token 恢复登录');
    test_assert(empty($_COOKIE['remember_token']), '非法/默认密码 Token 已被清理下发空 Cookie');

    // 5) 测试登出清除
    auth_remember_create('admin', $adminId, 0);
    test_assert(!empty($_COOKIE['remember_token']), '重新下发 Token 成功');
    auth_remember_clear();
    test_assert(empty($_COOKIE['remember_token']), 'auth_remember_clear 成功清除客户端 Cookie');
    $checkDeleted = db()->query("SELECT * FROM auth_remember_tokens WHERE token_hash=" . db()->quote($tokenHash))->fetch();
    test_assert(!$checkDeleted, 'auth_remember_clear 成功删除服务端 Token');

} finally {
    db()->prepare("DELETE FROM auth_remember_tokens WHERE account_type='admin' AND account_id=?")->execute([$adminId]);
    db()->prepare("DELETE FROM admins WHERE id=?")->execute([$adminId]);
}

echo "\n=== 4. 验证自媒体工作台 target=\"_blank\" ===\n";
$workbenchNav = file_get_contents(__DIR__ . '/../includes/workbench-nav.php');
test_assert(
    strpos($workbenchNav, 'href="https://media.laibangwo.com/?sso=me"') !== false &&
    strpos($workbenchNav, 'target="_blank"') !== false &&
    strpos($workbenchNav, 'rel="noopener noreferrer"') !== false,
    'includes/workbench-nav.php 中自媒体工作台链接包含 target="_blank" rel="noopener noreferrer"'
);

echo "\n=== 所有测试顺利通过！ ===\n";
