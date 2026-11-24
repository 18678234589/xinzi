<?php
/**
 * 历史订单补存“上传表格原始内容”：从还留着的上传文件里，按订单号找到对应的表格行（整行所有列），存进 project_order_source_rows，
 * 订单详情页即可显示。对应关系：导入时的审计记录（import / import_supplement，带订单号）与文件的导入时间对上（文件时间比审计时间晚 8 小时）。
 * 原文件已删除、订单号是系统生成的（WX- / ~site）的订单补不了。可重复运行（同订单同文件同行只更新）。
 * 用法：php tools/backfill_source_rows_202611.php            # 预览（不写库）
 *       php tools/backfill_source_rows_202611.php --apply
 */
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectSettlement.php';
require_once __DIR__ . '/../includes/ProjectImportFiles.php';
require_once __DIR__ . '/../includes/ProjectSheetEdit.php';
require_once __DIR__ . '/../includes/ProjectOrderSourceRows.php';

function bsr_run($apply)
{
    $pdo = db();
    if ($apply) osr_ensure();
    $actor = ['type' => 'admin', 'id' => 0, 'role' => 'finance', 'employee_id' => 0];
    $stat = ['files' => 0, 'files_unreadable' => 0, 'orders_matched' => 0, 'orders_no_row' => 0, 'rows' => 0];
    $files = $pdo->query("SELECT id,imported_at FROM project_import_files WHERE status='imported' AND imported_at IS NOT NULL ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $audit = $pdo->prepare("SELECT entity_id,details_json FROM project_audit_logs WHERE entity_type='order' AND action IN ('import','import_supplement') AND created_at BETWEEN ? AND ?");
    $exists = osr_table_exists();
    $have = $exists ? $pdo->prepare('SELECT COUNT(*) FROM project_order_source_rows WHERE order_id=? AND file_id=?') : null;
    foreach ($files as $f) {
        $t = strtotime($f['imported_at']);
        $logs = [];
        foreach ([8 * 3600, 0] as $shift) { // 文件时间比审计时间晚 8 小时（新版）或同一时区（旧版）
            $audit->execute([date('Y-m-d H:i:s', $t - $shift - 120), date('Y-m-d H:i:s', $t - $shift + 120)]);
            $logs = array_merge($logs, $audit->fetchAll(PDO::FETCH_ASSOC));
        }
        if (!$logs) continue;
        $stat['files']++;
        try {
            $file = ps_import_file_get((int)$f['id'], $actor);
            $sheets = pse_import_sheets($file, $actor);
        } catch (Throwable $e) { $stat['files_unreadable']++; continue; }
        $index = [];
        foreach ($sheets as $name => $rows) foreach ($rows as $n => $row) {
            if ($n < 1 || !is_array($row)) continue;
            foreach ($row as $cell) { $v = trim((string)$cell); if (mb_strlen($v) >= 6 && mb_strlen($v) <= 60) $index[$v][$name . "\0" . $n] = true; }
        }
        foreach ($logs as $log) {
            $d = json_decode((string)$log['details_json'], true) ?: [];
            $no = trim((string)($d['order_no'] ?? ''));
            if ($no === '' || empty($index[$no])) { $stat['orders_no_row']++; continue; }
            $src = [];
            foreach (array_keys($index[$no]) as $key) {
                [$sheet, $n] = explode("\0", $key); $n = (int)$n;
                $cells = osr_cells($sheets[$sheet][0] ?? [], $sheets[$sheet][$n]);
                if ($cells) $src[] = ['sheet' => $sheet, 'line' => $n + 1, 'cells' => $cells];
            }
            // 同号合并的几行（订单备注“合并表格第 7、8 行”）：订单号只写在第一行时，其余行靠备注里的行号补上
            $noteQ = $pdo->prepare('SELECT note FROM project_orders WHERE id=?'); $noteQ->execute([(int)$log['entity_id']]);
            if ($src && preg_match('/合并表格第 ([\d、]+) 行/u', (string)$noteQ->fetchColumn(), $mm)) {
                $sheet0 = $src[0]['sheet']; $haveLines = array_column($src, 'line');
                foreach (explode('、', $mm[1]) as $ln) {
                    $ln = (int)$ln; $n = $ln - 1;
                    if ($ln < 2 || in_array($ln, $haveLines, true) || !isset($sheets[$sheet0][$n])) continue;
                    $cells = osr_cells($sheets[$sheet0][0] ?? [], $sheets[$sheet0][$n]);
                    if ($cells) $src[] = ['sheet' => $sheet0, 'line' => $ln, 'cells' => $cells];
                }
            }
            if (!$src) { $stat['orders_no_row']++; continue; }
            $stat['orders_matched']++; $stat['rows'] += count($src);
            if ($apply) osr_save((int)$log['entity_id'], (int)$f['id'], $src);
        }
    }
    return $stat;
}

function osr_table_exists()
{
    try { db()->query('SELECT 1 FROM project_order_source_rows LIMIT 1'); return true; } catch (Throwable $e) { return false; }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $apply = in_array('--apply', $argv, true);
    $s = bsr_run($apply);
    echo ($apply ? '已补存' : '预览（未写库）') . '：' . json_encode($s, JSON_UNESCAPED_UNICODE) . "\n";
}
