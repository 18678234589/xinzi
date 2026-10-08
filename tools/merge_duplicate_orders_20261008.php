<?php
// 一次性：合并 2026-10-08 商标客服重复上传造成的 3 对同号订单（订单号写法不同）。
// 用法：php tools/merge_duplicate_orders_20261008.php [--commit]；不带 --commit 只演练并回滚。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';

$commit = in_array('--commit', $argv, true);
$pairs = [[56434, 56516], [56464, 56519], [56456, 56517]];
$actor = ['type' => 'system', 'id' => 0, 'role' => 'finance'];
$pdo = db();
$backup = [];
$pdo->beginTransaction();
try {
    foreach ($pairs as [$keepId, $dupId]) {
        $get = $pdo->prepare('SELECT * FROM project_orders WHERE id=? FOR UPDATE');
        $get->execute([$keepId]); $keep = $get->fetch();
        $get->execute([$dupId]); $dup = $get->fetch();
        if (!$keep || !$dup) throw new RuntimeException("订单不存在：$keepId / $dupId");
        if ($keep['settlement_status'] !== 'draft' || $dup['settlement_status'] !== 'draft') throw new RuntimeException("订单已不是草稿：$keepId / $dupId");
        $canon = ps_order_no_canonical($keep['order_no']);
        if ($canon !== ps_order_no_canonical($dup['order_no']) || $canon === '') throw new RuntimeException("订单号不是同一单：$keepId / $dupId");
        if ((float)$keep['contract_amount'] !== (float)$dup['contract_amount']) throw new RuntimeException("售价不一致：$keepId / $dupId");
        foreach (['project_costs', 'project_cash_movements', 'project_commission_snapshots', 'project_order_requests', 'project_refund_import_rows'] as $table) {
            $c = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE order_id=?"); $c->execute([$dupId]);
            if ((int)$c->fetchColumn() !== 0) throw new RuntimeException("多余订单 $dupId 在 $table 里有数据，不能自动删除");
        }
        $pc = $pdo->prepare('SELECT employee_id,commission_group FROM project_participants WHERE order_id=?');
        $pc->execute([$dupId]);
        $keepPeople = $pdo->prepare('SELECT 1 FROM project_participants WHERE order_id=? AND employee_id=? AND commission_group=?');
        foreach ($pc->fetchAll() as $p) {
            $keepPeople->execute([$keepId, $p['employee_id'], $p['commission_group']]);
            if (!$keepPeople->fetchColumn()) throw new RuntimeException("多余订单 $dupId 的参与人不在原单 $keepId 上");
        }

        $src = $pdo->prepare('SELECT * FROM project_order_sources WHERE order_id=?');
        $src->execute([$dupId]); $dupSrc = $src->fetch() ?: [];
        $det = $pdo->prepare('SELECT details_json FROM project_order_details WHERE order_id=?');
        $det->execute([$dupId]); $dupDetails = json_decode((string)$det->fetchColumn(), true) ?: [];
        $det->execute([$keepId]); $keepDetails = json_decode((string)$det->fetchColumn(), true) ?: [];
        $backup[] = ['keep' => $keep, 'dup' => $dup, 'dup_source' => $dupSrc, 'dup_details' => $dupDetails, 'keep_details' => $keepDetails];

        // 先删多余订单（释放规范订单号），再把更完整的资料并入原单
        $pdo->prepare('DELETE FROM project_auto_reviews WHERE order_id=?')->execute([$dupId]);
        foreach (['project_participants', 'project_order_sources', 'project_order_resources', 'project_order_details'] as $table) $pdo->prepare("DELETE FROM $table WHERE order_id=?")->execute([$dupId]);
        $pdo->prepare('DELETE FROM project_orders WHERE id=?')->execute([$dupId]);

        $kind = ($keep['order_kind'] === '普通订单' && in_array($dup['order_kind'], ['新客户', '同客户', '小额返款'], true)) ? $dup['order_kind'] : $keep['order_kind'];
        $note = trim(implode('；', array_filter([(string)$keep['note'], (string)$dup['note'], $keep['order_no'] !== $canon ? '原订单号写法：' . $keep['order_no'] : ''])), '；');
        $pdo->prepare('UPDATE project_orders SET order_no=?,customer_name=IF(customer_name=\'\',?,customer_name),shop=?,order_kind=?,note=?,row_version=row_version+1 WHERE id=?')
            ->execute([$canon, $dup['customer_name'], $dup['shop'] !== '' ? $dup['shop'] : $keep['shop'], $kind, $note, $keepId]);
        $pdo->prepare("UPDATE project_order_sources SET payment_nickname=IF(payment_nickname='',?,payment_nickname),nickname_source=IF(payment_nickname=?,'manual',nickname_source),payment_reference=IF(payment_reference='',?,payment_reference) WHERE order_id=?")
            ->execute([$dupSrc['payment_nickname'] ?? '', $dupSrc['payment_nickname'] ?? '', $dupSrc['payment_reference'] ?? '', $keepId]);
        foreach (['trademark_count', 'service_type'] as $key) if (trim((string)($keepDetails[$key] ?? '')) === '' && trim((string)($dupDetails[$key] ?? '')) !== '') $keepDetails[$key] = $dupDetails[$key];
        $pdo->prepare('UPDATE project_order_details SET details_json=? WHERE order_id=?')->execute([json_encode($keepDetails, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $keepId]);
        ps_audit('order', $keepId, 'merge_duplicate_order', $actor, ['removed_order_id' => $dupId, 'removed_order_no' => $dup['order_no'], 'order_no' => $canon, 'reason' => '订单号写法不同导致重复建单，已并入原单']);

        $after = $pdo->prepare('SELECT o.order_no,o.customer_name,o.shop,o.order_kind,o.contract_amount,s.payment_nickname,s.payment_reference,d.details_json FROM project_orders o LEFT JOIN project_order_sources s ON s.order_id=o.id LEFT JOIN project_order_details d ON d.order_id=o.id WHERE o.id=?');
        $after->execute([$keepId]);
        echo "[$keepId] ", json_encode($after->fetch(), JSON_UNESCAPED_UNICODE), "\n";
    }
    if ($commit) {
        $dir = __DIR__ . '/../.deploy';
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        file_put_contents($dir . '/dup_backup_20261008.json', json_encode($backup, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $pdo->commit();
        echo "已提交；删除前的数据备份在 .deploy/dup_backup_20261008.json\n";
    } else {
        $pdo->rollBack();
        echo "演练完成，已回滚（加 --commit 才会真正写入）\n";
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, '失败，已回滚：' . $e->getMessage() . "\n");
    exit(1);
}
