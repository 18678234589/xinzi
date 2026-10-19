<?php
/**
 * 按订单汇总表标记完成情况（财务上传）。
 * 网站业务以真实交付为准：网站汇总表里“到账情况”写已完成 / 到账 = 已完成，
 * 未完成 / 未发货 / 暂不发货 / 欠尾款… = 未完成，交易关闭 = 不处理。
 * 只改订单的“完成情况”；已审核订单默认不动，财务勾选后才撤回其分成快照（备份进审计日志，可追溯）。
 */
require_once __DIR__ . '/ProjectIntake.php';

/** 一个单元格里的状态归类：fin / unf / closed。 */
function pcs_classify($text)
{
    $t = trim((string)$text);
    if ($t === '' ) return 'unf';
    if (strpos($t, '交易关闭') !== false) return 'closed';
    if ($t === '到账' || $t === '完成' || (strpos($t, '已完成') !== false && strpos($t, '未完成') === false)) return 'fin';
    return 'unf';
}

/**
 * 读 xlsx：返回 ['map' => [订单号 => fin|unf|closed], 'rows' => 行数, 'conflicts' => 同号状态不一致的订单号, 'sheets' => [表名 => 行数]]。
 * 表头需含“订单编号 / 订单号”和“到账情况 / 状态 / 完成情况”；同一订单有任一行未完成即按未完成。
 */
function pcs_parse($path)
{
    require_once __DIR__ . '/ProjectImportFiles.php';
    $kinds = [];
    $rowsTotal = 0; $sheetRows = [];
    foreach (ps_xlsx_sheets($path) as $name => $rows) {
        $head = null; $noCol = $stCol = null;
        foreach (array_slice($rows, 0, 5) as $i => $row) {
            $cells = array_map(function ($c) { return trim(preg_replace('/\s+/u', '', (string)$c)); }, $row);
            foreach ($cells as $ci => $c) {
                if ($noCol === null && in_array($c, ['订单编号', '订单号'], true)) $noCol = $ci;
                if ($stCol === null && in_array($c, ['到账情况', '完成情况', '状态', '状态(填已完成/未完成)'], true)) $stCol = $ci;
            }
            if ($noCol !== null && $stCol !== null) { $head = $i; break; }
            $noCol = $stCol = null;
        }
        if ($head === null) continue;
        $n = 0;
        foreach (array_slice($rows, $head + 1) as $row) {
            $no = ps_order_no_canonical(is_float($row[$noCol] ?? null) && $row[$noCol] == floor($row[$noCol]) ? sprintf('%.0f', $row[$noCol]) : ($row[$noCol] ?? ''));
            if ($no === '') continue;
            $kinds[$no][pcs_classify($row[$stCol] ?? '')] = true;
            $n++;
        }
        $sheetRows[$name] = $n; $rowsTotal += $n;
    }
    $map = []; $conflicts = [];
    foreach ($kinds as $no => $k) {
        $map[$no] = isset($k['unf']) ? 'unf' : (isset($k['fin']) ? 'fin' : 'closed');
        if (isset($k['unf']) && isset($k['fin'])) $conflicts[] = $no;
    }
    return ['map' => $map, 'rows' => $rowsTotal, 'conflicts' => $conflicts, 'sheets' => $sheetRows];
}

/** 能不能撤回已审核订单的分成：返回 null 表示可以，否则原因。 */
function pcs_reopen_block($orderId)
{
    $pdo = db();
    $q = $pdo->prepare("SELECT DISTINCT payroll_month FROM project_commission_snapshots WHERE order_id=?"); $q->execute([(int)$orderId]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $m) {
        $l = $pdo->prepare('SELECT status FROM project_payroll_periods WHERE period=?'); $l->execute([$m]);
        if ($l->fetchColumn() === 'locked') return $m . ' 项目分成已锁定';
    }
    $q = $pdo->prepare("SELECT COUNT(*) FROM project_settlement_request_items i JOIN project_settlement_requests r ON r.id=i.request_id WHERE i.order_id=? AND r.status<>'rejected'"); $q->execute([(int)$orderId]);
    if ((int)$q->fetchColumn()) return '已被合作人申请结算';
    $q = $pdo->prepare('SELECT COUNT(*) FROM project_commission_adjustments WHERE order_id=?'); $q->execute([(int)$orderId]);
    if ((int)$q->fetchColumn()) return '已有分成调整';
    return null;
}

/**
 * 对照系统：返回分类后的订单。$map 为 pcs_parse 的 map。
 * 每项：['id','order_no','project_type','ss'(结算状态),'ds'(完成情况),'target','comm'(已生成的分成合计)]。
 */
function pcs_plan(array $map)
{
    $plan = ['mark_finished' => [], 'mark_unfinished' => [], 'approved_unfinished' => [], 'same' => 0, 'closed' => 0, 'not_website' => 0, 'missing' => []];
    $found = [];
    foreach (array_chunk(array_keys($map), 500) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $q = db()->prepare("SELECT o.id,o.order_no,o.project_type,o.settlement_status ss,o.delivery_status ds,o.contract_amount,(SELECT COALESCE(SUM(commission_amount),0) FROM project_commission_snapshots s WHERE s.order_id=o.id) comm FROM project_orders o WHERE o.order_no IN ($in)");
        $q->execute($chunk);
        foreach ($q->fetchAll() as $o) {
            $found[$o['order_no']] = true;
            $target = $map[$o['order_no']];
            $o['target'] = $target;
            if (!ps_is_website_order($o['project_type'])) { $plan['not_website']++; continue; }
            if ($target === 'closed') { $plan['closed']++; continue; }
            $done = in_array($o['ss'], ['approved', 'locked'], true);
            if ($done) {
                if ($target === 'unf') $plan['approved_unfinished'][] = $o; else $plan['same']++;
            } elseif ($target === 'fin' && $o['ds'] !== 'finished') $plan['mark_finished'][] = $o;
            elseif ($target === 'unf' && $o['ds'] === 'finished') $plan['mark_unfinished'][] = $o;
            else $plan['same']++;
        }
    }
    foreach ($map as $no => $t) if (!isset($found[$no]) && $t !== 'closed') $plan['missing'][] = $no;
    return $plan;
}

/**
 * 执行：改未审核订单的完成情况；$reopen 为真时撤回“已审核但总表未完成”的订单（删分成快照、回到未审核）。
 * 返回统计和跳过原因。
 */
function pcs_apply(array $plan, $reopen, array $actor)
{
    $pdo = db();
    $res = ['finished' => 0, 'unfinished' => 0, 'reopened' => 0, 'skipped' => []];
    foreach ($plan['mark_finished'] as $o) {
        if (!ps_is_website_order($o['project_type'] ?? '')) { $res['skipped'][] = $o['order_no'] . '：非网站业务，已跳过'; continue; }
        $pdo->beginTransaction();
        try {
            $r = $pdo->prepare("UPDATE project_orders SET delivery_status='finished',row_version=row_version+1 WHERE id=? AND settlement_status NOT IN ('approved','locked')");
            $r->execute([(int)$o['id']]);
            if ($r->rowCount()) {
                $pdo->prepare("UPDATE project_order_requests SET status='approved', reviewer_id=?, reviewed_at=NOW(), review_note='按订单汇总表标记完成' WHERE order_id=? AND request_type='delivery_completion' AND status='pending'")->execute([$actor['id'], (int)$o['id']]);
                ps_audit('order', (int)$o['id'], 'sync_completion', $actor, ['to' => 'finished', 'source' => '订单汇总表']);
                $res['finished']++;
            }
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $res['skipped'][] = $o['order_no'] . '：' . $e->getMessage(); }
    }
    foreach ($plan['mark_unfinished'] as $o) {
        if (!ps_is_website_order($o['project_type'] ?? '')) { $res['skipped'][] = $o['order_no'] . '：非网站业务，已跳过'; continue; }
        $r = $pdo->prepare("UPDATE project_orders SET delivery_status='unfinished',row_version=row_version+1 WHERE id=? AND settlement_status NOT IN ('approved','locked')");
        $r->execute([(int)$o['id']]);
        if ($r->rowCount()) { ps_audit('order', (int)$o['id'], 'sync_completion', $actor, ['to' => 'unfinished', 'source' => '订单汇总表']); $res['unfinished']++; }
    }
    if ($reopen) foreach ($plan['approved_unfinished'] as $o) {
        if (!ps_is_website_order($o['project_type'] ?? '')) { $res['skipped'][] = $o['order_no'] . '：非网站业务，已跳过'; continue; }
        if ($why = pcs_reopen_block((int)$o['id'])) { $res['skipped'][] = $o['order_no'] . '：' . $why . '，未撤回'; continue; }
        $pdo->beginTransaction();
        try {
            $s = $pdo->prepare('SELECT * FROM project_commission_snapshots WHERE order_id=?'); $s->execute([(int)$o['id']]);
            $backup = $s->fetchAll(PDO::FETCH_ASSOC);
            $pdo->prepare('DELETE FROM project_commission_snapshots WHERE order_id=?')->execute([(int)$o['id']]);
            $pdo->prepare("UPDATE project_orders SET settlement_status='draft',delivery_status='unfinished',row_version=row_version+1 WHERE id=? AND settlement_status='approved'")->execute([(int)$o['id']]);
            ps_audit('order', (int)$o['id'], 'sync_completion_reopen', $actor, ['source' => '订单汇总表', 'snapshots_backup' => $backup]);
            $pdo->commit(); $res['reopened']++;
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $res['skipped'][] = $o['order_no'] . '：' . $e->getMessage(); }
    }
    return $res;
}
