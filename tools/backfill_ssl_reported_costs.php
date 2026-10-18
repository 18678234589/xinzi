<?php
// 回填“SSL 实际成本报备”订单的成本：草稿订单备注里有 SSL 报备金额、且恰好等于成本中心域名 SSL 证书标准成本，还没有任何成本时，带入标准成本（待确认，技术补凭证 / 财务核对后生效）。
// 报备金额与标准成本不同的订单不动。已审核 / 锁定订单不动。用法：php tools/backfill_ssl_reported_costs.php 2026-09 [--apply]；默认只预演，可重复运行。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
chdir(dirname(__DIR__));
define('BASE_URL', '');
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/ProjectIntake.php';
$month = $argv[1] ?? '';
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) { fwrite(STDERR, "用法：php tools/backfill_ssl_reported_costs.php YYYY-MM [--apply]\n"); exit(1); }
$apply = in_array('--apply', $argv, true);
$actor = ['type' => 'system', 'id' => 0, 'employee_id' => null, 'role' => 'finance'];
$tpl = db()->query("SELECT * FROM project_cost_templates WHERE is_active=1 AND requires_proof=0 AND category='certificate' AND name='域名SSL证书' AND price_mode='fixed' ORDER BY id DESC LIMIT 1")->fetch();
if (!$tpl) { fwrite(STDERR, "成本中心没有可用的“域名SSL证书”标准成本\n"); exit(1); }
$q = db()->prepare("SELECT o.id,o.order_no,o.note FROM project_orders o WHERE o.settlement_status='draft' AND o.order_date>=? AND o.order_date<? AND o.note LIKE '%SSL 实际成本报备%' AND NOT EXISTS (SELECT 1 FROM project_costs c WHERE c.order_id=o.id AND c.review_status<>'rejected')");
$q->execute([$month . '-01', date('Y-m-d', strtotime($month . '-01 +1 month'))]);
$stat = ['candidates' => 0, 'added' => 0, 'skipped_other_amount' => []];
foreach ($q->fetchAll() as $o) {
    $stat['candidates']++;
    if (!preg_match('/SSL 实际成本报备：¥(\d+(?:\.\d+)?)/u', $o['note'], $m) || abs((float)$m[1] - (float)$tpl['price']) > 0.001) { $stat['skipped_other_amount'][] = $o['order_no']; continue; }
    if ($apply) {
        db()->beginTransaction();
        try { ps_intake_add_template_cost((int)$o['id'], $tpl, $actor, 'SSL 报备金额与标准成本一致，自动带入', 1, 'pending'); db()->commit(); }
        catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); fwrite(STDERR, "订单 {$o['order_no']} 失败：" . $e->getMessage() . "\n"); continue; }
    }
    $stat['added']++;
}
echo ($apply ? '已回填 ' : '预演 ') . json_encode($stat, JSON_UNESCAPED_UNICODE) . "\n";
