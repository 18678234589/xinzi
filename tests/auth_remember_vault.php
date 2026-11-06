<?php
/** 离线测试：7 天免登录恢复会话时，是否保留“登录密码达标”（SQLite，不联网）。php -d extension=pdo_sqlite tests/auth_remember_vault.php */
$c = 0; function at($ok, $m) { global $c; if (!$ok) throw new RuntimeException('FAIL: ' . $m); $c++; }
$pdo = new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('NOW', function () { return date('Y-m-d H:i:s'); });
function db() { global $pdo; return $pdo; }
$pdo->exec('CREATE TABLE auth_remember_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, token_hash TEXT UNIQUE, account_type TEXT, account_id INTEGER, auth_version INTEGER DEFAULT 0, pw_strong INTEGER NOT NULL DEFAULT 0, expires_at TEXT, created_at TEXT)');
$pdo->exec("CREATE TABLE project_users (id INTEGER PRIMARY KEY, username TEXT, password_hash TEXT, password_changed_at TEXT, is_active INTEGER, auth_version INTEGER DEFAULT 0)");
$pdo->exec("CREATE TABLE admins (id INTEGER PRIMARY KEY, username TEXT, password TEXT, auth_version INTEGER DEFAULT 0)");
$pdo->prepare('INSERT INTO project_users VALUES (1,?,?,?,1,3)')->execute(['luanxin', password_hash('Str0ngPassword', PASSWORD_DEFAULT), '2026-10-01 00:00:00']);
$pdo->prepare('INSERT INTO project_users VALUES (2,?,?,?,1,0)')->execute(['zhangsan', password_hash('weakpass', PASSWORD_DEFAULT), '2026-10-01 00:00:00']);
require __DIR__ . '/../includes/AuthPassword.php';
require __DIR__ . '/../includes/AuthRemember.php';
$restore = function ($rawToken) { $_SESSION = []; $_COOKIE['remember_token'] = $rawToken; auth_remember_check(); return $_SESSION; };
// 强密码登录并勾选 7 天免登录
$_SESSION = []; auth_remember_create('employee', 1, 3, auth_password_is_strong('Str0ngPassword')); $strongToken = $_COOKIE['remember_token'];
$s = $restore($strongToken);
at(($s['project_user_id'] ?? 0) === 1 && $s['vault_pw_ok'] === true, 'strong password: auto-login keeps the vault password check passed');
// 弱密码（不够 10 位或没有大小写）登录
$_SESSION = []; auth_remember_create('employee', 2, 0, auth_password_is_strong('weakpass')); $weakToken = $_COOKIE['remember_token'];
$s = $restore($weakToken);
at(($s['project_user_id'] ?? 0) === 2 && $s['vault_pw_ok'] === false, 'weak password: auto-login still blocked from the vault page');
// 旧令牌（没有标记）按未达标处理，需要重新登录一次
$pdo->exec("UPDATE auth_remember_tokens SET pw_strong=0 WHERE account_id=1"); $s = $restore($strongToken); at($s['vault_pw_ok'] === false, 'old token without the flag stays conservative');
// 改密后令牌被吊销，不能再恢复
auth_remember_revoke_user('employee', 1); $s = $restore($strongToken); at(empty($s['project_user_id']), 'revoked token cannot restore a session');
// 令牌表还没迁移 pw_strong 列时仍能创建令牌
$pdo->exec('DROP TABLE auth_remember_tokens'); $pdo->exec('CREATE TABLE auth_remember_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, token_hash TEXT UNIQUE, account_type TEXT, account_id INTEGER, auth_version INTEGER DEFAULT 0, expires_at TEXT, created_at TEXT)');
$_SESSION = []; at(auth_remember_create('employee', 1, 3, true) === true, 'creating a token works before the column migration');
$s = $restore($_COOKIE['remember_token']); at(($s['project_user_id'] ?? 0) === 1 && $s['vault_pw_ok'] === false, 'before migration: restores the session but stays conservative');
echo json_encode(['passed' => $c, 'failed' => 0]) . "\n";
