<?php
// 把森动备案的首次备案成本录入成本中心（幂等）：名称“森动备案成本”、适用业务“森动备案”、¥80 / 单；之后可在成本中心改价（生成新版本）。
// 用法：php tools/seed_filing_costs.php [价格=80] [--commit]；不带 --commit 只演练。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';

$commit = in_array('--commit', $argv, true);
$price = 80.0;
foreach ($argv as $i => $arg) if ($i > 0 && is_numeric($arg)) $price = round((float)$arg, 2);
$actor = ['type' => 'system', 'id' => 0, 'role' => 'finance'];
$pdo = db();
$pdo->beginTransaction();
try {
    $find = $pdo->prepare("SELECT id,price FROM project_cost_templates WHERE is_active=1 AND business_scope='森动备案' AND name='森动备案成本' LIMIT 1");
    $find->execute();
    if ($row = $find->fetch()) { echo "已存在：森动备案成本 ¥{$row['price']}/单（改价请在成本中心操作）\n"; $pdo->rollBack(); exit(0); }
    $pdo->prepare("INSERT INTO project_cost_templates (category,business_scope,name,specification,unit,price_mode,cost_kind,price,supplier_price,requires_proof,auto_approve,version) VALUES ('other','森动备案','森动备案成本','首次备案 · 每单','单','fixed','one_time',?,NULL,0,1,1)")->execute([$price]);
    ps_audit('template', (int)$pdo->lastInsertId(), 'create', $actor, ['name' => '森动备案成本', 'price' => $price, 'unit' => '单', 'scope' => '森动备案']);
    echo "新增：森动备案成本 ¥$price/单\n";
    if ($commit) { $pdo->commit(); echo "已提交\n"; } else { $pdo->rollBack(); echo "演练完成，未写入；加 --commit 才会写入\n"; }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, '失败，已回滚：' . $e->getMessage() . "\n");
    exit(1);
}
