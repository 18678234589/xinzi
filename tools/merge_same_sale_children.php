<?php
// 把旧规则建出的“与原单金额完全相同”的分单子单并回原单（同一笔销售只记一次）：
// 子单的参与人加入原单（对接编辑等，按人数均分该组权重），子单及其导入资料备份后删除。
// 只处理：子单与原单都是草稿 / 未审核、子单没有收款 / 成本 / 分成 / 申请等后续动作、金额与原单完全相同、业务属于可合并对（poj_same_sale_allowed）。
// 用法：php tools/merge_same_sale_children.php [--commit]；不带 --commit 只演练并回滚。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectOrderJoin.php';
require_once __DIR__ . '/../includes/ProjectImportUndo.php';

$commit = in_array('--commit', $argv, true);
$actor = ['type' => 'system', 'id' => 0, 'role' => 'finance'];
$pdo = db();
pos_ensure(); $owned = pu_owned_tables(); // 建表语句会隐式提交事务，放在 beginTransaction 之前
$backup = []; $merged = 0; $kept = [];
$pairs = $pdo->query("SELECT sp.parent_order_id pid, sp.child_order_id cid FROM project_order_splits sp ORDER BY sp.child_order_id")->fetchAll();
$get = $pdo->prepare('SELECT * FROM project_orders WHERE id=? FOR UPDATE');
$people = $pdo->prepare('SELECT p.*,u.role user_role FROM project_participants p LEFT JOIN project_users u ON u.employee_id=p.employee_id AND u.is_active=1 WHERE p.order_id=? ORDER BY p.id');
$grand = $pdo->prepare('SELECT COUNT(*) FROM project_order_splits WHERE parent_order_id=?');
$pdo->beginTransaction();
try {
    foreach ($pairs as $pair) {
        $get->execute([$pair['pid']]); $parent = $get->fetch();
        $get->execute([$pair['cid']]); $child = $get->fetch();
        if (!$parent || !$child) continue;
        $why = [];
        if (!poj_same_sale_allowed($parent['project_type'], $child['project_type'], (float)$child['contract_amount'], $parent['order_no'], (float)$parent['contract_amount'])) $why[] = '不是同一笔销售（金额不同或业务不可合并）';
        if (strpos($child['order_no'], $parent['order_no'] . '~') !== 0 || strpos($child['order_no'], '#') !== false) $why[] = '不是普通业务分单子单';
        if ($child['settlement_status'] !== 'draft') $why[] = '子单不是草稿';
        if (in_array($parent['settlement_status'], ['approved', 'locked'], true)) $why[] = '原单已审核';
        if ((float)$child['receipt_amount'] > 0 || (float)$child['refund_amount'] > 0) $why[] = '子单有收款 / 退款金额';
        if (pos_parent_of((int)$parent['id'])) $why[] = '原单本身是子单';
        $grand->execute([$child['id']]); if ((int)$grand->fetchColumn() > 0) $why[] = '子单下还有分单';
        foreach (pu_blocking_tables() as [$table, $reason]) {
            $c = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE order_id=?"); $c->execute([$child['id']]);
            if ((int)$c->fetchColumn() > 0) $why[] = $reason;
        }
        $c = $pdo->prepare('SELECT COUNT(*) FROM project_costs WHERE order_id=?'); $c->execute([$child['id']]); if ((int)$c->fetchColumn() > 0) $why[] = '子单有成本';
        $c = $pdo->prepare('SELECT COUNT(*) FROM project_site_projects WHERE order_id=?'); $c->execute([$child['id']]); if ((int)$c->fetchColumn() > 0) $why[] = '子单是网站项目';
        if ($why) { $kept[] = [$child['order_no'], implode('、', $why)]; continue; }

        $people->execute([$child['id']]); $childPeople = $people->fetchAll();
        $snapshot = ['order' => $child, 'parent_order_no' => $parent['order_no']];
        foreach ($owned as $table) { $s = $pdo->prepare("SELECT * FROM $table WHERE order_id=?"); $s->execute([$child['id']]); $snapshot[$table] = $s->fetchAll(); }
        $snapshot['split_link'] = ['parent_order_id' => (int)$parent['id'], 'child_order_id' => (int)$child['id']];
        $backup[] = $snapshot;

        // 先让子单的参与人加入原单，再删子单
        foreach ($childPeople as $p) {
            $role = $p['user_role'] === 'technical' ? 'technical' : 'customer_service';
            $target = poj_join_target((int)$p['employee_id'], $parent['project_type'], (int)$parent['id'], $role);
            poj_join_group((int)$parent['id'], (int)$p['employee_id'], $target['group'], $target['role'], $actor, ['reason' => '并入重复分单', 'removed_order_no' => $child['order_no']]);
        }
        $pdo->prepare('UPDATE project_orders SET note=CONCAT_WS(\'；\', NULLIF(note,\'\'), ?) WHERE id=?')->execute(['同一笔销售只记一次：已并入重复分单 ' . $child['order_no'] . '（' . $child['project_type'] . ' ¥' . number_format((float)$child['contract_amount'], 2, '.', '') . '）', (int)$parent['id']]);
        $pdo->prepare('DELETE FROM project_auto_reviews WHERE order_id=?')->execute([$child['id']]);
        $pdo->prepare('DELETE FROM project_order_splits WHERE child_order_id=?')->execute([$child['id']]);
        foreach ($owned as $table) $pdo->prepare("DELETE FROM $table WHERE order_id=?")->execute([$child['id']]);
        $pdo->prepare('DELETE FROM project_orders WHERE id=?')->execute([$child['id']]);
        ps_audit('order', (int)$parent['id'], 'merge_same_sale_child', $actor, ['removed_order_id' => (int)$child['id'], 'removed_order_no' => $child['order_no'], 'business' => $child['project_type'], 'amount' => (float)$child['contract_amount']]);
        $merged++;
    }
    echo "可并回原单：$merged 张；保留不动：", count($kept), " 张\n";
    $reasons = [];
    foreach ($kept as [$no, $why]) $reasons[$why] = ($reasons[$why] ?? 0) + 1;
    foreach ($reasons as $why => $n) echo "  保留原因（$n 张）：$why\n";
    if ($commit) {
        $dir = __DIR__ . '/../.deploy';
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $file = $dir . '/same_sale_children_backup_' . date('Ymd_His') . '.json';
        file_put_contents($file, json_encode($backup, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $pdo->commit();
        echo "已提交；删除前的数据备份在 $file\n";
    } else {
        $pdo->rollBack();
        echo "演练完成，已回滚（加 --commit 才会真正写入）\n";
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, '失败，已回滚：' . $e->getMessage() . "\n");
    exit(1);
}
