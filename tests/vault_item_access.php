<?php
// 只能在专用隔离数据库运行；不读取或修改真实服务器资料。
if (PHP_SAPI !== 'cli') exit(1);
$testRoot = isset($vault_test_root) ? $vault_test_root : dirname(__DIR__);
require_once $testRoot . '/includes/ProjectVault.php';
require_once $testRoot . '/tests/require_isolated_database.php';
$pdo = db();
require_isolated_test_database($pdo);
$checks = 0;
function va_check($condition, $label) {
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
}
function va_denied($callback, $label) {
    try { $callback(); } catch (RuntimeException $e) { va_check($e->getCode() === 404 || $e->getCode() === 403, $label); return; }
    va_check(false, $label);
}
$pdo->exec("CREATE TABLE admins (id INT PRIMARY KEY,username VARCHAR(100))");
$pdo->exec("INSERT INTO admins VALUES (1,'admin'),(2,'other_admin')");
$pdo->exec("CREATE TABLE employees (id INT PRIMARY KEY,name VARCHAR(100),department VARCHAR(100))");
$pdo->exec("INSERT INTO employees VALUES (19,'于洋','测试'),(20,'孙杰','测试'),(55,'栾鑫','测试'),(101,'张光萍','测试'),(102,'其他人员','测试')");
$pdo->exec("CREATE TABLE project_users (id INT PRIMARY KEY,employee_id INT,is_active INT,role VARCHAR(30),username VARCHAR(100))");
$pdo->exec("CREATE TABLE project_settings (setting_key VARCHAR(100) PRIMARY KEY,setting_value TEXT)");
$pdo->prepare('INSERT INTO project_settings VALUES (?,?)')->execute(['vault_access', json_encode(['admins'=>['admin','other_admin'],'employees'=>[19,20,55,101,102]])]);
$pdo->exec(file_get_contents($testRoot . '/migrations/20260929_vault.sql'));
$pdo->exec('ALTER TABLE project_vault_items ADD COLUMN access_json TEXT NULL');
$pdo->exec("CREATE TABLE project_audit_logs (id INT AUTO_INCREMENT PRIMARY KEY,entity_type VARCHAR(30),entity_id INT,action VARCHAR(30),actor_type VARCHAR(20),actor_id INT,details_json TEXT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
$admin = ['type'=>'admin','id'=>1,'employee_id'=>null];
$otherAdmin = ['type'=>'admin','id'=>2,'employee_id'=>null];
$allowed = [];
foreach ([19,20,55,101] as $id) $allowed[] = ['type'=>'employee','id'=>$id,'employee_id'=>$id];
$other = ['type'=>'employee','id'=>102,'employee_id'=>102];
$outside = ['type'=>'employee','id'=>777,'employee_id'=>777];
$fixture = ['category'=>'服务器','name'=>'Restricted fixture','host'=>'fixture.invalid','account'=>'test','password'=>'fixture-password','notes'=>'fixture-notes'];
$privateId = pv_item_save($fixture, $admin);
$pdo->prepare('UPDATE project_vault_items SET access_json=? WHERE id=?')->execute([json_encode(['admins'=>['admin'],'employees'=>[19,20,55,101]]), $privateId]);
$publicId = pv_item_save(['category'=>'服务器','name'=>'Inherited fixture','host'=>'inherited.invalid'], $admin);
$private = $pdo->query('SELECT * FROM project_vault_items WHERE id=' . $privateId)->fetch();
va_check(strpos($private['password_enc'], 'v1:') === 0 && strpos($private['notes_enc'], 'fixture-notes') === false, 'credentials encrypted');
foreach (array_merge([$admin],$allowed) as $actor) {
    va_check(pv_item_can_access($private,$actor), 'specified identity allowed');
    va_check(count(pv_items('', '', $actor)) === 2, 'allowed list includes both');
    $secret = pv_item_reveal($privateId,$actor);
    va_check($secret === ['password'=>'fixture-password','notes'=>'fixture-notes'], 'authorized reveal');
}
foreach ([$otherAdmin,$other,$outside,null,['type'=>'unknown','id'=>1,'employee_id'=>19]] as $actor) {
    va_check(!pv_item_can_access($private,$actor), 'other identity denied');
    va_denied(function() use($privateId,$actor) { pv_item_reveal($privateId,$actor); }, 'forged reveal denied');
    va_denied(function() use($privateId,$fixture,$actor) { pv_item_save($fixture,$actor,$privateId); }, 'forged edit denied');
    va_denied(function() use($privateId,$actor) { pv_item_delete($privateId,$actor); }, 'forged delete denied');
}
foreach ([$otherAdmin,$other] as $actor) {
    va_check(array_column(pv_items('', '', $actor),'id') == [$publicId], 'private metadata and category count excluded');
    va_check(pv_items('Restricted', '', $actor) === [], 'search cannot disclose private record');
    va_check(!in_array($privateId,array_column(pv_recent_logs($actor),'entity_id')), 'private audit rows hidden');
}
va_check(pv_items('', '', $outside) === [] && pv_recent_logs($outside) === [], 'no global access no list or audit');
foreach (['','bad json','{}','[]','{"admins":[],"employees":[]}','{"admins":"admin","employees":[19]}'] as $invalid) {
    $row = $private; $row['access_json'] = $invalid;
    va_check(!pv_item_can_access($row,$admin) && !pv_item_can_access($row,$allowed[0]), 'invalid ACL fails closed');
}
$row = $private; $row['access_json']=null;
va_check(pv_item_can_access($row,$other), 'NULL inherits existing whitelist');
unset($row['access_json']);
va_check(pv_item_can_access($row,$other), 'old schema compatible before migration');
$fixture['access_json']=null; $fixture['notes']='updated fixture';
pv_item_save($fixture,$allowed[0],$privateId);
$private = pv_item_require($privateId,$admin);
va_check(pv_item_is_restricted($private) && !pv_item_can_access($private,$other), 'edit cannot erase restriction');
$audit = $pdo->query("SELECT details_json FROM project_audit_logs WHERE entity_id=$privateId AND action='update' ORDER BY id DESC LIMIT 1")->fetchColumn();
va_check(json_decode($audit,true) === ['restricted'=>true], 'shared audit contains no connection information');
pv_item_delete($privateId,$admin);
va_check(count(pv_items('', '', $admin)) === 1, 'soft delete works for authorized actor');
va_check(!in_array($privateId,array_column(pv_recent_logs($other),'entity_id')), 'deleted private audit remains restricted');
va_denied(function() use($privateId,$admin) { pv_item_reveal($privateId,$admin); }, 'deleted record cannot reveal');
va_denied(function() use($privateId,$fixture,$admin) { pv_item_save($fixture,$admin,$privateId); }, 'deleted record cannot edit');
$strict = ['admins'=>['admin'],'employees'=>[19,20,55,101]];
$pdo->prepare('UPDATE project_settings SET setting_value=? WHERE setting_key=?')->execute([json_encode($strict),'vault_access']);
ps_setting_get('vault_access',null,true);
foreach (array_merge([$admin],$allowed) as $actor) va_check(pv_can_access($actor), 'specified identity can view whole page');
foreach ([$otherAdmin,$other,$outside] as $actor) va_check(!pv_can_access($actor) && pv_items('', '', $actor) === [], 'other identity cannot view whole page');
$pdo->exec("DELETE FROM project_settings WHERE setting_key='vault_access'");
ps_setting_get('vault_access',null,true);
foreach (array_merge([$admin],$allowed) as $actor) va_check(pv_can_access($actor), 'safe default includes only specified identity');
foreach ([$otherAdmin,$other,$outside] as $actor) va_check(!pv_can_access($actor), 'safe default excludes other identity');
echo json_encode(['checks'=>$checks,'passed'=>true], JSON_UNESCAPED_UNICODE) . "\n";
