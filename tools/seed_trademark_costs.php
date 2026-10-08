<?php
// 把商标成本录入成本中心（幂等：同类别 / 业务 / 名称 / 规格 / 单位已存在则跳过）。
// 用法：php tools/seed_trademark_costs.php [--commit]；不带 --commit 只列出将要新增的项目。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';

$commit = in_array('--commit', $argv, true);
$actor = ['type' => 'system', 'id' => 0, 'role' => 'finance'];
// [类别, 名称, 规格, 单位, 单价, 自动审核]
$items = [
    ['other', '商标注册', '官费 · 每类', '件', 270, 1],
    ['other', '商标转让', '官费', '件', 450, 1],
    ['other', '商标续展', '官费', '件', 450, 1],
    ['other', '商标补证', '官费', '件', 450, 1],
    ['other', '商标变更', '官费 · 无成本', '件', 0, 1],
    ['other', '商标超期续展', '官费', '件', 675, 1],
    ['other', '商标许可备案', '官费', '件', 135, 1],
    ['other', '商标注销', '官费 · 无成本', '件', 0, 1],
    ['other', '商标撤回', '官费 · 无成本', '件', 0, 1],
    ['other', '商标更正', '官费 · 无成本', '件', 0, 1],
    ['other', '商标注册多选项目加收', '每多选 1 个项目', '个', 27, 1],
    ['other', '成品商标成本', '按实际金额', '元', 1, 0],
    ['other', '国际商标成本', '按实际金额（国家 / 小类数不同）', '元', 1, 0],
    ['outsourcing', '法务外包成本', '按实际金额（向法务单独询价）', '元', 1, 0],
];
$pdo = db();
$pdo->beginTransaction();
try {
    $find = $pdo->prepare("SELECT id FROM project_cost_templates WHERE is_active=1 AND business_scope='商标' AND category=? AND name=? AND specification=? AND unit=? LIMIT 1");
    $insert = $pdo->prepare("INSERT INTO project_cost_templates (category,business_scope,name,specification,unit,price_mode,cost_kind,price,supplier_price,requires_proof,auto_approve,version) VALUES (?,'商标',?,?,?,'fixed','one_time',?,NULL,0,?,1)");
    $added = 0;
    foreach ($items as [$category, $name, $spec, $unit, $price, $auto]) {
        $find->execute([$category, $name, $spec, $unit]);
        if ($find->fetchColumn()) { echo "已存在：$name\n"; continue; }
        $insert->execute([$category, $name, $spec, $unit, $price, $auto]);
        ps_audit('template', (int)$pdo->lastInsertId(), 'create', $actor, ['name' => $name, 'price' => $price, 'unit' => $unit, 'scope' => '商标']);
        echo "新增：$name / $spec · ¥$price/$unit\n";
        $added++;
    }
    if ($commit) { $pdo->commit(); echo "已提交，新增 $added 项\n"; }
    else { $pdo->rollBack(); echo "演练完成（会新增 $added 项），未写入；加 --commit 才会写入\n"; }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, '失败，已回滚：' . $e->getMessage() . "\n");
    exit(1);
}
