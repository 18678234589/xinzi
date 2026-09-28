<?php
// 为平面设计阎泸琪开通项目登录账号：登录名 yanluqi，初始密码 = 登录名（首次登录须绑定手机号，系统提醒改密码）；业务“平面设计”。
// 用法：php migrations/provision_graphic_designer.php（已开通则只补业务关联，不改密码）
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectSettlement.php';

$employee = db()->query("SELECT id FROM employees WHERE name='阎泸琪'")->fetchAll(PDO::FETCH_COLUMN);
if (count($employee) !== 1) { fwrite(STDERR, "阎泸琪不存在或重名\n"); exit(1); }
$employeeId = (int)$employee[0];
$username = 'yanluqi';
$db = db();
$db->beginTransaction();
try {
    $q = $db->prepare('SELECT id,username FROM project_users WHERE employee_id=?');
    $q->execute([$employeeId]);
    $user = $q->fetch();
    if (!$user) {
        $taken = $db->prepare('SELECT 1 FROM project_users WHERE username=?');
        $taken->execute([$username]);
        if ($taken->fetchColumn()) throw new RuntimeException('登录名 ' . $username . ' 已被占用');
        $db->prepare("INSERT INTO project_users (employee_id,username,password_hash,role,is_active) VALUES (?,?,?,'technical',1)")
            ->execute([$employeeId, $username, password_hash($username, PASSWORD_DEFAULT)]);
        $userId = (int)$db->lastInsertId();
        ps_audit('account', $userId, 'create', ['type' => 'system', 'id' => 0], ['employee_id' => $employeeId, 'role' => 'technical', 'business' => '平面设计', 'password' => 'default=username']);
        echo "已开通：登录名 {$username}，初始密码 {$username}\n";
    } else {
        $userId = (int)$user['id'];
        echo "已有账号 " . $user['username'] . "，只补业务关联\n";
    }
    $db->prepare('DELETE FROM project_user_businesses WHERE user_id=? AND business_name=?')->execute([$userId, '平面设计']);
    $db->prepare('UPDATE project_user_businesses SET is_default=0 WHERE user_id=?')->execute([$userId]);
    $db->prepare('INSERT INTO project_user_businesses (user_id,business_name,is_default) VALUES (?,?,1)')->execute([$userId, '平面设计']);
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
