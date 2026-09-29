<?php
// 平台与服务器信息栏目的查看账号：
// - 股东：王桂美（已有管理员账号 wangguimei，重设为指定密码）、张富全（新建管理员账号 zhangfuquan，指定密码）
// - 管理：张光萍、张欣源（平台信息专用账号，登录名为姓名拼音，初始密码 = 登录名，首次登录须改密码）
// 用法：php migrations/provision_vault_accounts.php '<股东管理员密码>'   （密码不写进代码仓库）
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectSettlement.php';

$adminPassword = (string)($argv[1] ?? '');
if (strlen($adminPassword) < 8) { fwrite(STDERR, "请在参数里给出股东管理员账号的密码（至少 8 位）\n"); exit(1); }
$db = db();
$db->beginTransaction();
try {
    // 管理员账号沿用原系统口令格式（md5）
    foreach (['wangguimei' => '王桂美', 'zhangfuquan' => '张富全'] as $username => $name) {
        $q = $db->prepare('SELECT id FROM admins WHERE username=?');
        $q->execute([$username]);
        $id = $q->fetchColumn();
        if ($id) {
            $db->prepare('UPDATE admins SET password=? WHERE id=?')->execute([md5($adminPassword), (int)$id]);
            echo "管理员 {$username}（{$name}）已重设密码\n";
        } else {
            $db->prepare('INSERT INTO admins (username,password) VALUES (?,?)')->execute([$username, md5($adminPassword)]);
            $id = (int)$db->lastInsertId();
            echo "已新建管理员 {$username}（{$name}）\n";
        }
        ps_audit('account', (int)$id, 'vault_admin', ['type' => 'system', 'id' => 0], ['username' => $username, 'name' => $name]);
    }
    foreach (['zhangguangping' => '张光萍', 'zhangxinyuan' => '张欣源'] as $username => $name) {
        $employee = $db->prepare('SELECT id FROM employees WHERE name=?');
        $employee->execute([$name]);
        $ids = $employee->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) !== 1) throw new RuntimeException($name . ' 不存在或重名');
        $q = $db->prepare('SELECT id,username,role FROM project_users WHERE employee_id=?');
        $q->execute([(int)$ids[0]]);
        $user = $q->fetch();
        if ($user) { echo "{$name} 已有账号 {$user['username']}（{$user['role']}），不改动\n"; continue; }
        $taken = $db->prepare('SELECT 1 FROM project_users WHERE username=?');
        $taken->execute([$username]);
        if ($taken->fetchColumn()) throw new RuntimeException('登录名 ' . $username . ' 已被占用');
        $db->prepare("INSERT INTO project_users (employee_id,username,password_hash,role,is_active) VALUES (?,?,?,'vault',1)")->execute([(int)$ids[0], $username, password_hash($username, PASSWORD_DEFAULT)]);
        ps_audit('account', (int)$db->lastInsertId(), 'create', ['type' => 'system', 'id' => 0], ['employee_id' => (int)$ids[0], 'role' => 'vault', 'password' => 'default=username']);
        echo "已开通 {$name}：登录名 {$username}，初始密码 {$username}\n";
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
