<?php
// 一次性导入：把月度合作商报酬表里的 主管 / 工行卡号 / 签名 / 买保险公司 / 已扣除预留金 写进合作人员结算资料。
// 用法：php tools/import_partner_profiles.php <profiles.json> [--apply] [--force]
// JSON 为 [{name, supervisor, card, signed, insurance_company, reserve}, ...]（含银行卡号，不要提交到仓库）。
// 默认只预演；已有资料的人默认不覆盖（--force 才覆盖）；姓名对不上或重名的只报告不写入。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ProjectPartnerProfile.php';
$rows = json_decode($GLOBALS['PPP_JSON'] ?? (string)@file_get_contents($argv[1] ?? ''), true);
if (!is_array($rows)) { fwrite(STDERR, "读不到 JSON\n"); exit(1); }
$apply = in_array('--apply', $argv, true);
$force = in_array('--force', $argv, true);
$byName = [];
foreach (db()->query('SELECT id,name FROM employees')->fetchAll() as $e) $byName[trim($e['name'])][] = (int)$e['id'];
$have = ppp_all();
$activeIds = array_flip(array_map('intval', db()->query('SELECT employee_id FROM project_users WHERE is_active=1')->fetchAll(PDO::FETCH_COLUMN)));
$actor = ['type' => 'admin', 'id' => 0, 'role' => 'finance'];
$done = 0; $skipHave = 0; $miss = []; $dup = [];
foreach ($rows as $r) {
    $name = trim((string)$r['name']);
    $ids = $byName[$name] ?? [];
    if (!$ids) { $miss[] = $name; continue; }
    if (count($ids) > 1) { // 重名：只有一个有启用账号时按它，其余情况留给人工
        $active = array_values(array_filter($ids, function ($i) use ($activeIds) { return isset($activeIds[$i]); }));
        if (count($active) !== 1) { $dup[] = $name; continue; }
        $ids = $active;
    }
    $id = $ids[0];
    if (isset($have[$id]) && !$force) { $skipHave++; continue; }
    if ($apply) ppp_save($id, ['supervisor' => $r['supervisor'] ?? '', 'bank_card' => $r['card'] ?? '', 'signed' => $r['signed'] ?? 0, 'insurance_company' => $r['insurance_company'] ?? '', 'reserve_deducted' => isset($r['reserve']) && $r['reserve'] !== null ? (string)$r['reserve'] : ''], $actor);
    $done++;
}
echo ($apply ? '已写入' : '预演可写入'), " $done 人；已有资料跳过 $skipHave 人；表里有但系统里没有该姓名：", count($miss), ' ', implode('、', $miss), '；重名未处理：', count($dup), ' ', implode('、', $dup), "\n";
