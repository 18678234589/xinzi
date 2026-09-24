<?php
/** 仅用于初次开通原表中尚无项目账号的三位成员；密码只在本次 CLI 输出一次。 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../config/database.php';
$people = [
    ['name' => '栾鑫', 'username' => 'luanxin'],
    ['name' => '曲俊泽', 'username' => 'qujunze'],
    ['name' => '冯超', 'username' => 'fengchao'],
];
$created = [];
db()->beginTransaction();
try {
    foreach ($people as $person) {
        $q = db()->prepare('SELECT e.id,u.id AS user_id FROM employees e LEFT JOIN project_users u ON u.employee_id=e.id WHERE e.name=?');
        $q->execute([$person['name']]);
        $matches = $q->fetchAll(PDO::FETCH_ASSOC);
        if (count($matches) !== 1) throw new RuntimeException($person['name'] . ' 的员工姓名不唯一或不存在，请人工核对');
        if ($matches[0]['user_id']) continue;
        $q = db()->prepare('SELECT (SELECT COUNT(*) FROM project_users WHERE username=?) + (SELECT COUNT(*) FROM admins WHERE username=?)');
        $q->execute([$person['username'], $person['username']]);
        if ((int)$q->fetchColumn() !== 0) throw new RuntimeException($person['username'] . ' 已被占用，请人工核对');
        $q = db()->prepare("SELECT 1 FROM project_governance_members WHERE employee_id=? AND is_active=1 LIMIT 1");
        $q->execute([(int)$matches[0]['id']]);
        if (!$q->fetchColumn()) throw new RuntimeException($person['name'] . ' 不是有效管理层成员');
        $password = bin2hex(random_bytes(12));
        db()->prepare("INSERT INTO project_users (employee_id,username,password_hash,role) VALUES (?,?,?,'governance')")
            ->execute([(int)$matches[0]['id'], $person['username'], password_hash($password, PASSWORD_DEFAULT)]);
        $created[] = ['name' => $person['name'], 'username' => $person['username'], 'initial_password' => $password];
    }
    db()->commit();
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
echo json_encode($created, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
