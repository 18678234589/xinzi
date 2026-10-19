<?php
/**
 * 重复订单：同一业务里订单号规范化后相同（如“交易号：…”与不带前缀、“订单编号：致2026…”与“致2026…”）的多张订单，
 * 多半是同一单被录入了两次，已审核的两张还会重复生成分成。财务在“重复订单”页一键删掉多余的一张（进回收站，30 天内可还原，分成随之收回）。
 * 带 “~site-” 的是同一订单下的多个站点，不是重复。
 */
require_once __DIR__ . '/ProjectIntake.php';
require_once __DIR__ . '/ProjectOrderDelete.php';
require_once __DIR__ . '/ProjectOrderTrash.php';

/** 疑似重复订单的分组；每组含订单明细、系统建议保留的一张、是否可以一键删除。 */
function pdo_duplicate_groups()
{
    $light = db()->query("SELECT id,order_no,project_type FROM project_orders WHERE order_no NOT LIKE '%~site-%' ORDER BY id")->fetchAll();
    $by = [];
    foreach ($light as $r) {
        $c = ps_order_no_canonical($r['order_no']);
        if (mb_strlen($c) >= 8) $by[$r['project_type'] . '|' . $c][] = (int)$r['id'];
    }
    $by = array_filter($by, function ($ids) { return count($ids) > 1; });
    if (!$by) return [];
    $all = array_merge(...array_values($by));
    $in = implode(',', array_map('intval', $all));
    $rows = [];
    foreach (db()->query("SELECT o.*,(SELECT COUNT(*) FROM project_participants p WHERE p.order_id=o.id) ppl,(SELECT COUNT(*) FROM project_commission_snapshots s WHERE s.order_id=o.id) snaps,
        (SELECT COALESCE(SUM(s.commission_amount),0) FROM project_commission_snapshots s WHERE s.order_id=o.id) snap_total,
        (SELECT COUNT(*) FROM project_order_splits x WHERE x.parent_order_id=o.id OR x.child_order_id=o.id) split_links FROM project_orders o WHERE o.id IN ($in)")->fetchAll() as $r) $rows[(int)$r['id']] = $r;
    $people = [];
    foreach (db()->query("SELECT order_id,employee_id,commission_group,role_name FROM project_participants WHERE order_id IN ($in) ORDER BY order_id,id")->fetchAll() as $p) $people[(int)$p['order_id']][] = $p['employee_id'] . '|' . $p['commission_group'] . '|' . $p['role_name'];
    $out = [];
    foreach ($by as $key => $ids) {
        $g = [];
        foreach ($ids as $id) { $r = $rows[$id]; $r['people'] = $people[$id] ?? []; $g[] = $r; }
        // 建议保留：有客户昵称、参与人更多、已审核的优先，再取较晚录入的（含客服信息）
        usort($g, function ($a, $b) {
            $sa = [trim((string)$a['customer_name']) !== '' ? 1 : 0, count($a['people']), $a['settlement_status'] === 'approved' ? 1 : 0, (int)$a['id']];
            $sb = [trim((string)$b['customer_name']) !== '' ? 1 : 0, count($b['people']), $b['settlement_status'] === 'approved' ? 1 : 0, (int)$b['id']];
            return $sb <=> $sa;
        });
        $keep = $g[0];
        $amounts = array_unique(array_map('floatval', array_column($g, 'contract_amount')));
        $dates = array_unique(array_column($g, 'order_date'));
        $why = [];
        $deletable = [];
        foreach (array_slice($g, 1) as $d) {
            $problem = '';
            if ((float)$d['contract_amount'] !== (float)$keep['contract_amount'] || $d['order_date'] !== $keep['order_date']) $problem = '售价或日期与保留的那张不同，请进订单核对后手动处理';
            elseif (array_diff($d['people'], $keep['people'])) $problem = '这张有保留订单没有的参与人，直接删会让他们少拿分成，请先在保留订单补上参与人';
            elseif ((int)$d['split_links'] || (int)$keep['split_links']) $problem = '订单有关联拆单，请进订单核对拆单关系后手动处理';
            elseif (($why1 = pod_blocker($d, ['role' => 'finance'])) !== '') $problem = $why1;
            if ($problem === '') $deletable[(int)$d['id']] = true; else $why[(int)$d['id']] = $problem;
        }
        $money = 0.0; foreach (array_slice($g, 1) as $d) $money += (float)$d['snap_total'];
        $out[] = ['key' => sha1($key . '|' . json_encode($g, JSON_UNESCAPED_UNICODE)), 'project_type' => $g[0]['project_type'], 'keep' => $keep, 'dups' => array_slice($g, 1), 'deletable' => $deletable, 'blocked_why' => $why,
            'duplicated_money' => round($money, 2), 'same_amount' => count($amounts) === 1, 'same_date' => count($dates) === 1];
    }
    usort($out, function ($a, $b) { return $b['duplicated_money'] <=> $a['duplicated_money']; });
    return $out;
}

function pdo_duplicate_count()
{
    static $n = null;
    if ($n === null) { try { $n = count(pdo_duplicate_groups()); } catch (Throwable $e) { $n = 0; } }
    return $n;
}

/** 财务删除一组里多余的订单（保留 keep）。进回收站；返回结果说明。 */
function pdo_delete_duplicate($groupKey, $deleteId, array $actor)
{
    if (($actor['role'] ?? '') !== 'finance') throw new RuntimeException('只有财务可以删除重复订单');
    $group = null;
    foreach (pdo_duplicate_groups() as $g) if (hash_equals($g['key'], (string)$groupKey)) { $group = $g; break; }
    if (!$group) throw new RuntimeException('这组订单已被处理或已变化，请刷新页面');
    if (empty($group['deletable'][(int)$deleteId])) throw new RuntimeException($group['blocked_why'][(int)$deleteId] ?? '这张订单不能自动删除');
    $dup = null; foreach ($group['dups'] as $d) if ((int)$d['id'] === (int)$deleteId) $dup = $d;
    $pdo = db();
    pot_ensure(); // 建表放在事务之前（DDL 会隐式提交）
    $own = !$pdo->inTransaction(); // 调用方已开事务时由调用方负责提交 / 回滚
    if ($own) $pdo->beginTransaction(); else $pdo->exec('SAVEPOINT duplicate_order_delete');
    try {
        // 同时锁住保留单和待删单，再复核页面内容，避免并发删除或按过期资料删单。
        $ids = array_map('intval', array_merge([(int)$group['keep']['id']], array_column($group['dups'], 'id')));
        $in = implode(',', $ids);
        $pdo->query("SELECT id FROM project_orders WHERE id IN ($in) ORDER BY id FOR UPDATE")->fetchAll();
        $pdo->query("SELECT id FROM project_participants WHERE order_id IN ($in) ORDER BY id FOR UPDATE")->fetchAll();
        $pdo->query("SELECT id FROM project_commission_snapshots WHERE order_id IN ($in) ORDER BY id FOR UPDATE")->fetchAll();
        $pdo->query("SELECT id FROM project_order_splits WHERE parent_order_id IN ($in) OR child_order_id IN ($in) ORDER BY id FOR UPDATE")->fetchAll();
        $fresh = false;
        foreach (pdo_duplicate_groups() as $g) if (hash_equals($g['key'], (string)$groupKey)) { $fresh = true; break; }
        if (!$fresh) throw new RuntimeException('这组订单已被处理或已变化，请刷新页面');
        $q = $pdo->prepare('SELECT id,order_no,project_type,order_date,settlement_status FROM project_orders WHERE id=? FOR UPDATE');
        $q->execute([(int)$deleteId]);
        $row = $q->fetch();
        if (!$row) throw new RuntimeException('订单不存在（可能已被删除）');
        if (($why = pod_blocker($row, $actor)) !== '') throw new RuntimeException($why);
        $trash = pod_backup((int)$deleteId, $row['order_no'], $actor);
        pod_delete_rows((int)$deleteId);
        ps_audit('order', (int)$deleteId, 'delete_order', $actor, ['order_no' => $row['order_no'], 'was_status' => $row['settlement_status'], 'backup' => $trash, 'duplicate_of' => (int)$group['keep']['id'], 'reason' => '财务删除重复订单']);
        if ($own) $pdo->commit(); else $pdo->exec('RELEASE SAVEPOINT duplicate_order_delete');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            if ($own) $pdo->rollBack();
            else { $pdo->exec('ROLLBACK TO SAVEPOINT duplicate_order_delete'); $pdo->exec('RELEASE SAVEPOINT duplicate_order_delete'); }
        }
        throw $e;
    }
    return '已删除重复订单 ' . $dup['order_no'] . '（进回收站，30 天内可还原），保留 ' . $group['keep']['order_no'] . ($dup['snap_total'] > 0 ? '；重复生成的分成 ¥' . number_format((float)$dup['snap_total'], 2) . ' 已收回' : '');
}
