<?php
// 把财务 2026-10-23 提供的《程序名称成本表》同步到成本中心（缺失新增，价格不同走改价新版本）。
// 用法：php tools/sync_program_costs_20261023.php [--apply]；默认只预览。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectPresets.php';
$apply = in_array('--apply', $argv, true);
$pdo = db();
echo '数据库：' . DB_HOST . ':' . DB_PORT . '/' . DB_NAME . "\n";
$find = $pdo->prepare("SELECT price,supplier_price FROM project_cost_templates WHERE category='program' AND name=? AND specification=? AND is_active=1 ORDER BY version DESC LIMIT 1");
$plan = ['new' => 0, 'differs' => 0, 'same' => 0];
foreach (ps_preset_cost_templates() as $row) {
    if ($row['category'] !== 'program' || $row['business_scope'] !== '网站模板' || !preg_match('/^(森动|JSP|优站|青站)/u', $row['name'])) continue;
    $find->execute([$row['name'], $row['specification']]);
    $old = $find->fetch();
    $state = !$old ? 'new' : ((abs((float)$old['price'] - $row['price']) < 0.005 && abs((float)$old['supplier_price'] - $row['supplier_price']) < 0.005) ? 'same' : 'differs');
    $plan[$state]++;
    if ($state !== 'same') echo ($state === 'new' ? '新增 ' : '改价 ') . $row['name'] . ' ' . $row['specification'] . ' 采购/成本 ' . $row['supplier_price'] . '/' . $row['price'] . ($old ? '（原 ' . $old['supplier_price'] . '/' . $old['price'] . '）' : '') . "\n";
}
echo json_encode($plan) . "\n";
if (!$apply) { echo "预览结束；加 --apply 才写入。\n"; exit; }
$admin = (int)$pdo->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetchColumn();
echo json_encode(ps_sync_program_costs(['type' => 'admin', 'id' => $admin])) . "\n";
