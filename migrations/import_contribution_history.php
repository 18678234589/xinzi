<?php
// 一次性补录《董事长与监委会激励考核机制》“建议、bug、做事记录”历史 7 条及举证图片。
// 用法：php migrations/import_contribution_history.php <举证材料目录>
// 按“当事人 + 记录日期 + 事项”去重，可重复执行；先执行 apply_project.php（20260927_governance_contribution_entry.sql）。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectGovernance.php';

$evidenceDir = rtrim((string)($argv[1] ?? ''), '/\\');
if ($evidenceDir === '' || !is_dir($evidenceDir)) { fwrite(STDERR, "请提供举证材料目录\n"); exit(1); }
// [序号, 记录日期, 当事人, 建议\bug\主动做事, 具体内容描述, 截止时间, 完成状态, 奖金变动, 评判监委, 备注]
$rows = [
    [1, '2026-08-25', '王亚', 'AI 绘图余额不能用', 'AI 绘图余额不能用', '2026-08-25', '已完成', 300, '曲俊泽', '奖励已发'],
    [2, '2026-08-05', '刘玉霜', '退订赤兔名品插件', '节省成本', '2026-08-05', '已完成', 500, '刘群', '奖励已发'],
    [3, '2026-08-31', '翟建跃', '来帮我AI服务聚合平台后台慢', '来帮我AI服务聚合平台后台慢指出已修复', '2026-08-31', '已完成', 300, '孙曼', '奖励已发'],
    [4, '2026-09-07', '阎泸琪', 'AI视频市场调研', '', '2026-09-07', '', null, '张欣源', ''],
    [5, '2026-09-07', '谢文婷', '平台发现bug', '', '2026-09-07', '', null, '张欣源', ''],
    [6, '2026-09-07', '宋文娜', '平台发现bug', '', '2026-09-07', '', null, '张欣源', ''],
    [7, '2026-09-08', '栾鑫', '视频后台bug', '', '2026-09-08', '', null, '张欣源', ''],
];
$system = ['type' => 'system', 'id' => 0, 'employee_id' => null];
$find = db()->prepare('SELECT id FROM employees WHERE name=?');
$employeeId = function ($name) use ($find) {
    $find->execute([$name]);
    $ids = $find->fetchAll(PDO::FETCH_COLUMN);
    if (count($ids) !== 1) throw new RuntimeException('人员“' . $name . '”' . (count($ids) ? '重名' : '不存在'));
    return (int)$ids[0];
};
$exists = db()->prepare("SELECT id FROM project_governance_records WHERE record_kind='contribution' AND owner_employee_id=? AND record_date=? AND category=? LIMIT 1");
$insert = db()->prepare("INSERT INTO project_governance_records (record_kind,owner_employee_id,record_date,category,description,due_date,outcome_status,reviewer_employee_id,bonus_delta,flow_note,reward_status,review_state,review_note,created_by_employee_id,created_by_admin_id) VALUES ('contribution',?,?,?,?,?,?,?,?,?,?,?,?,NULL,NULL)");
$files = glob($evidenceDir . '/*.{png,jpg,jpeg,webp,pdf}', GLOB_BRACE) ?: [];
$db = db();
$db->beginTransaction();
$stored = [];
try {
    foreach ($rows as [$no, $date, $owner, $title, $description, $due, $status, $amount, $reviewer, $note]) {
        $ownerId = $employeeId($owner);
        $exists->execute([$ownerId, $date, $title]);
        if ($existing = $exists->fetchColumn()) { echo "跳过 #$no $owner $title（已存在 #$existing）\n"; continue; }
        $done = $status === '已完成';
        $paid = $note === '奖励已发';
        $insert->execute([$ownerId, $date, $title, $description !== '' ? $description : $title, $due, $done ? '已完成' : '待评判', $employeeId($reviewer), $amount, $paid ? '' : $note, $amount ? ($paid ? 'paid' : 'unpaid') : null, $done ? 'approved' : 'pending', '由原表“建议、bug、做事记录”第 ' . $no . ' 行补录']);
        $id = (int)$db->lastInsertId();
        $count = 0;
        foreach ($files as $file) if (preg_match('/^' . $no . '-/', basename($file))) { $stored[] = pg_save_evidence_file($id, $system, $file, basename($file)); $count++; }
        ps_audit('governance_record', $id, 'contribution_history_import', $system, ['row' => $no, 'owner' => $owner, 'bonus_delta' => $amount, 'files' => $count]);
        echo "补录 #$id $date $owner「$title」" . ($done ? " 已完成 ¥$amount" . ($paid ? ' 奖励已发' : '') : ' 待评判') . "，评判 $reviewer，举证 $count 个\n";
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    foreach ($stored as $path) @unlink($path);
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
