<?php
// 撤销上传：把“某张上传表格新建的、还没进入结算的订单”整体撤回。
// 原则：只撤本表新建的订单（audit 里的 written_order_ids）；有收款/退款/分成快照/调整/凭证/更正记录等任何后续动作的订单一律保留并说明原因；
// 删除前把订单及其导入时生成的资料完整备份进审计日志，便于追溯。
require_once __DIR__ . '/ProjectIntake.php';
require_once __DIR__ . '/ProjectImportResult.php';

/** 这些表里只要有该订单的记录，就说明订单已经有后续业务动作，不能撤。[表, 说明] */
function pu_blocking_tables()
{
    return [
        ['project_cash_movements', '已登记收款或退款'],
        ['project_commission_snapshots', '已生成分成快照'],
        ['project_commission_adjustments', '已有分成调整'],
        ['project_commission_corrections', '已有分成更正申请'],
        ['project_order_fix_requests', '已有资料更正申请'],
        ['project_order_credentials', '已保存客户凭证'],
        ['project_order_requests', '已有订单申请记录'],
        ['project_renewal_items', '已进入续费提醒'],
        ['project_dup_feedback', '已有重复订单反馈'],
    ];
}

/** 导入时随订单自动生成、撤销时一并清理的资料表。 */
function pu_owned_tables()
{
    return ['project_order_details', 'project_order_resources', 'project_order_sources', 'project_costs', 'project_participants', 'project_department_orders', 'project_department_uploaders'];
}

function pu_file_for_actor($fileId, $actor)
{
    $q = db()->prepare('SELECT id,employee_id,uploaded_by_type,uploaded_by_id,original_name,business_name,status,imported_count,created_at FROM project_import_files WHERE id=?');
    $q->execute([(int)$fileId]);
    $file = $q->fetch();
    if (!$file) throw new RuntimeException('这张表格不存在，可能已被删除');
    if ($actor['role'] !== 'finance' && ((int)$file['employee_id'] !== (int)($actor['employee_id'] ?? 0) || $file['uploaded_by_type'] !== $actor['type'])) throw new RuntimeException('只能撤销本人上传的表格');
    return $file;
}

/** 其他表格也对应到的订单（那张表里这单“已在库”）：撤掉会让那张表对不上，保留。 */
function pu_ids_used_by_other_files($fileId)
{
    $used = [];
    $q = db()->prepare("SELECT entity_id,details_json FROM project_audit_logs WHERE entity_type='import_file' AND action='import_result' AND entity_id<>? ORDER BY id");
    $q->execute([(int)$fileId]);
    $latest = [];
    foreach ($q->fetchAll() as $r) $latest[(int)$r['entity_id']] = $r['details_json'];
    foreach ($latest as $json) foreach ((array)((json_decode($json, true) ?: [])['order_ids'] ?? []) as $id) $used[(int)$id] = true;
    return $used;
}

/** 预演：不改任何数据，返回每张订单能否撤销及原因。 */
function pu_plan($fileId, $actor)
{
    $file = pu_file_for_actor($fileId, $actor);
    $report = ps_import_result_get($fileId);
    if (!array_key_exists('written_order_ids', $report)) {
        return ['file' => $file, 'supported' => false, 'orders' => [], 'removable' => 0, 'kept' => 0,
            'message' => $file['status'] === 'preview' ? '这张表格只做了预览，没有写入订单，不需要撤销；不要的话直接“删除原件”即可。' : '这张表格是早期上传的，系统没有记录它新建了哪些订单，无法自动撤销，请联系财务处理。'];
    }
    $ids = array_values(array_unique(array_map('intval', (array)$report['written_order_ids'])));
    if (!$ids) return ['file' => $file, 'supported' => true, 'orders' => [], 'removable' => 0, 'kept' => 0, 'message' => '这张表格没有新建订单（订单在上传前就已存在），没有可撤销的内容。'];
    $in = implode(',', $ids);
    $orders = [];
    foreach (db()->query("SELECT id,order_no,customer_name,contract_amount,receipt_amount,refund_amount,settlement_status FROM project_orders WHERE id IN ($in) ORDER BY id")->fetchAll() as $o) {
        $o['id'] = (int)$o['id']; $o['reasons'] = [];
        $orders[$o['id']] = $o;
    }
    foreach ($ids as $id) if (!isset($orders[$id])) $orders[$id] = ['id' => $id, 'order_no' => '（订单已不存在）', 'customer_name' => '', 'contract_amount' => 0, 'settlement_status' => '', 'reasons' => [], 'gone' => true];
    foreach ($orders as $id => &$o) {
        if (!empty($o['gone'])) continue;
        if ($o['settlement_status'] !== 'draft') $o['reasons'][] = '已进入结算审核';
        if ((float)$o['receipt_amount'] > 0 || (float)$o['refund_amount'] > 0) $o['reasons'][] = '已有收款/退款金额';
    }
    unset($o);
    foreach (pu_blocking_tables() as [$table, $why]) {
        foreach (db()->query("SELECT DISTINCT order_id FROM $table WHERE order_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if (isset($orders[(int)$id]) && empty($orders[(int)$id]['gone'])) $orders[(int)$id]['reasons'][] = $why;
        }
    }
    foreach (db()->query("SELECT DISTINCT order_id FROM project_refund_import_rows WHERE order_id IN ($in) AND review_status<>'pending'")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        if (isset($orders[(int)$id])) $orders[(int)$id]['reasons'][] = '已有退款登记';
    }
    $used = pu_ids_used_by_other_files($fileId);
    foreach ($orders as $id => &$o) if (isset($used[$id]) && empty($o['gone'])) $o['reasons'][] = '另一张表格也对应这单';
    unset($o);
    $removable = 0; $kept = 0; $list = [];
    foreach ($orders as $o) {
        if (!empty($o['gone'])) continue;
        $o['reasons'] = array_values(array_unique($o['reasons']));
        $o['removable'] = !$o['reasons'];
        $o['removable'] ? $removable++ : $kept++;
        $list[] = ['id' => $o['id'], 'order_no' => $o['order_no'], 'customer_name' => $o['customer_name'], 'contract_amount' => (float)$o['contract_amount'], 'removable' => $o['removable'], 'reasons' => $o['reasons']];
    }
    return ['file' => $file, 'supported' => true, 'orders' => $list, 'removable' => $removable, 'kept' => $kept, 'message' => ''];
}

/** 执行：重新预演一遍（防止预演后数据变化），只撤仍然干净的订单；返回统计。 */
function pu_execute($fileId, $actor, $deleteFile = true)
{
    $plan = pu_plan($fileId, $actor);
    if (!$plan['supported'] || !$plan['removable']) throw new RuntimeException($plan['message'] ?: '没有可以撤销的订单');
    $pdo = db();
    $removed = []; $keptNow = [];
    $owned = pu_owned_tables();
    $pdo->beginTransaction();
    try {
        foreach ($plan['orders'] as $o) {
            if (!$o['removable']) continue;
            $id = (int)$o['id'];
            $pdo->exec('SAVEPOINT pu_order');
            try {
                $lock = $pdo->query("SELECT * FROM project_orders WHERE id=$id AND settlement_status='draft' FOR UPDATE")->fetch();
                if (!$lock) { $pdo->exec('RELEASE SAVEPOINT pu_order'); $keptNow[] = $o['order_no']; continue; }
                $backup = ['order' => $lock];
                foreach ($owned as $table) $backup[$table] = $pdo->query("SELECT * FROM $table WHERE order_id=$id")->fetchAll();
                // 还没生效的退款行只解除关联，退回“待对号”，不丢退款记录
                $pdo->exec("UPDATE project_refund_import_rows SET order_id=NULL WHERE order_id=$id AND review_status='pending'");
                foreach ($owned as $table) $pdo->exec("DELETE FROM $table WHERE order_id=$id");
                $pdo->exec("DELETE FROM project_orders WHERE id=$id");
                ps_audit('order', $id, 'undo_import', $actor, ['file_id' => (int)$fileId, 'file' => $plan['file']['original_name'], 'backup' => $backup]);
                $pdo->exec('RELEASE SAVEPOINT pu_order');
                $removed[] = $id;
            } catch (Throwable $e) {
                $pdo->exec('ROLLBACK TO SAVEPOINT pu_order');
                $keptNow[] = $o['order_no'];
            }
        }
        $report = ps_import_result_get($fileId);
        $gone = array_flip($removed);
        $report['order_ids'] = array_values(array_filter((array)($report['order_ids'] ?? []), function ($i) use ($gone) { return !isset($gone[(int)$i]); }));
        $report['written_order_ids'] = array_values(array_filter((array)($report['written_order_ids'] ?? []), function ($i) use ($gone) { return !isset($gone[(int)$i]); }));
        $report['written'] = count($report['written_order_ids']);
        $report['undone'] = ($report['undone'] ?? 0) + count($removed);
        ps_audit('import_file', (int)$fileId, 'import_result', $actor, $report);
        ps_audit('import_file', (int)$fileId, 'undo_import', $actor, ['removed' => count($removed), 'kept' => $plan['kept'] + count($keptNow), 'delete_file' => (bool)$deleteFile]);
        if (!$deleteFile) $pdo->prepare("UPDATE project_import_files SET status=IF(imported_count-?<=0,'preview',status), imported_count=GREATEST(imported_count-?,0) WHERE id=?")->execute([count($removed), count($removed), (int)$fileId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    $fileDeleted = false;
    // 只有所有订单都撤掉了才连原表格一起删；还有保留的订单时保留原表格，免得对不上
    if ($deleteFile && !$plan['kept'] && !$keptNow) { ps_import_file_delete((int)$fileId, $actor); $fileDeleted = true; }
    return ['removed' => count($removed), 'kept' => $plan['kept'] + count($keptNow), 'file_deleted' => $fileDeleted];
}
