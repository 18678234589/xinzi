<?php
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectBusiness.php';

$dongxu = db()->query("SELECT u.id, u.username, u.role, u.employee_id, e.name FROM project_users u JOIN employees e ON e.id=u.employee_id WHERE u.username='dongxu'")->fetch();
assert($dongxu !== false, '董旭用户存在');

$actor = [
    'id' => (int)$dongxu['id'],
    'role' => $dongxu['role'],
    'employee_id' => (int)$dongxu['employee_id'],
    'type' => 'user'
];

$allowed = ps_actor_businesses($actor);
echo "董旭允许的业务: " . json_encode($allowed, JSON_UNESCAPED_UNICODE) . "\n";
assert(in_array('网站模板', $allowed, true), '应包含网站模板');
assert(in_array('AI网站定制', $allowed, true), '应包含AI网站定制');
assert(!in_array('网站客服', $allowed, true), '不应直接包含旧业务名网站客服');

// 模拟 index.php 业务筛选列表
$businessCatalog = ps_business_catalog();
$filterCatalog = [];
if ($actor['role'] !== 'finance') {
    foreach ($allowed as $bName) {
        if (isset($businessCatalog[$bName])) $filterCatalog[$bName] = $businessCatalog[$bName];
    }
}
echo "董旭在订单列表看到的业务筛选项: " . implode('、', array_keys($filterCatalog)) . "\n";
assert(!isset($filterCatalog['网站客服']), '筛选项中已无 网站客服（历史）');
assert(!isset($filterCatalog['小程序客服']), '筛选项中已无 小程序客服（历史）');

echo "✔ 董旭业务权限与前端下拉展示验证通过！\n";
