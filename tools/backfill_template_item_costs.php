<?php
// 回填网站模板订单的商品明细和成本中心标准成本：已导入的订单（草稿）没有商品明细（或有明细但没对上成本中心）时，按原始上传表里的“程序名称 / 空间+域名”重新识别，
// 生成商品明细并带入成本中心标准成本（技术确认资源前为待确认，首页预计和自动核对都会计入）。
// 用法：php tools/backfill_template_item_costs.php 2026-09 [--apply]；默认只预演。已对上的明细、已审核 / 锁定的订单不动，可重复运行。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
chdir(dirname(__DIR__));
define('BASE_URL', '');
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/ProjectIntake.php';
require_once 'includes/ProjectOrderItems.php';
require_once 'includes/ProjectImportFiles.php';
ini_set('memory_limit', '1024M'); set_time_limit(0);
$month = $argv[1] ?? '';
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) { fwrite(STDERR, "用法：php tools/backfill_template_item_costs.php YYYY-MM [--apply]\n"); exit(1); }
$apply = in_array('--apply', $argv, true);
$actor = ['type' => 'system', 'id' => 0, 'employee_id' => null, 'role' => 'finance'];
$until = date('Y-m-d', strtotime($month . '-01 +1 month'));
$q = db()->prepare("SELECT o.id,o.order_no,o.contract_amount FROM project_orders o WHERE o.project_type='网站模板' AND o.settlement_status='draft' AND o.order_date>=? AND o.order_date<? AND (NOT EXISTS (SELECT 1 FROM project_order_items i WHERE i.order_id=o.id) OR EXISTS (SELECT 1 FROM project_order_items i WHERE i.order_id=o.id AND i.template_id IS NULL AND i.cost_id IS NULL))");
$q->execute([$month . '-01', $until]);
$targets = [];
foreach ($q->fetchAll() as $o) $targets[$o['order_no']] = $o;
$templates = ps_intake_templates(null, '网站模板');
$defaultHead = ['日期', '店铺', '付款账号', '订单编号', '售价', '财务成本', '成本', '到账情况', '程序名称', '客户手机号', 'QQ', '微信', '模板编号', '域名', '空间+域名', '要求', '客服', '技术', '续费', '备注'];
$found = []; // order_no => ['file' => id, 'items' => [...]]
$files = db()->query("SELECT id,employee_id,uploaded_by_type,uploaded_by_id FROM project_import_files WHERE business_name='网站模板' AND status='imported' ORDER BY id")->fetchAll();
foreach ($files as $f) {
    try { $row = ps_import_file_get($f['id'], $actor); $sheets = ps_import_file_sheets($row); } catch (Throwable $e) { continue; }
    foreach ($sheets as $rows) {
        $head = null; $start = 0;
        foreach (array_slice($rows, 0, 3) as $i => $r) { $cells = array_map('trim', array_map('strval', $r)); if (in_array('订单编号', $cells, true)) { $head = $cells; $start = $i + 1; break; } }
        if (!$head) { if (!$rows || count($rows[0]) < 18) continue; $head = $defaultHead; } // 老表没有表头行：按固定列序
        $ix = array_flip($head);
        if (!isset($ix['订单编号'], $ix['程序名称'])) continue;
        foreach (array_slice($rows, $start, null, true) as $line => $r) {
            $no = trim(ltrim((string)($r[$ix['订单编号']] ?? ''), "'"));
            if ($no === '' || !isset($targets[$no])) continue;
            $name = trim((string)($r[$ix['程序名称']] ?? ''));
            if ($name === '') continue;
            $o = $targets[$no];
            $items = poi_from_row($name, $r[$ix['售价']] ?? $o['contract_amount'], $head, $r, $templates, $line + 1, '');
            foreach ($items as $it) $found[$no]['items'][] = $it;
            $found[$no]['file'] = (int)$f['id'];
        }
    }
}
$stat = ['orders_target' => count($targets), 'orders_found' => count($found), 'items' => 0, 'items_template' => 0, 'items_unmatched' => [], 'costs_before' => 0, 'costs_after' => 0];
foreach ($found as $no => $info) {
    foreach ($info['items'] as $it) { $stat['items']++; if ($it['template_id']) $stat['items_template']++; else $stat['items_unmatched'][$it['item_name']] = ($stat['items_unmatched'][$it['item_name']] ?? 0) + 1; }
}
arsort($stat['items_unmatched']);
if ($apply) {
    $cnt = db()->prepare("SELECT COUNT(*) FROM project_costs WHERE order_id=? AND review_status<>'rejected'");
    foreach ($found as $no => $info) {
        $oid = (int)$targets[$no]['id'];
        $cnt->execute([$oid]); $stat['costs_before'] += (int)$cnt->fetchColumn();
        db()->beginTransaction();
        try { poi_save($oid, $info['items'], $info['file'], $actor); db()->commit(); }
        catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); fwrite(STDERR, "订单 $no 失败：" . $e->getMessage() . "\n"); continue; }
        $cnt->execute([$oid]); $stat['costs_after'] += (int)$cnt->fetchColumn();
    }
}
echo ($apply ? '已回填' : '预演') . ' ' . json_encode($stat, JSON_UNESCAPED_UNICODE) . "\n";
