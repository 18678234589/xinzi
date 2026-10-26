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

/**
 * 这些原因都是“上传后系统自动生成的”（自动核对通过、系统登记的收款、自动算出的分成快照、同一张表重传），合作人员自己撤不了，
 * 但没有任何人工操作，财务同意后可以一并撤掉（订单进回收站，30 天内可还原）。其余原因（人工收退款、结算申请、月份已核算 / 锁定、更正申请等）一律不能撤。
 */
const PU_SOFT_REASONS = ['已进入结算审核', '已有收款/退款金额', '已登记收款或退款', '已生成分成快照', '另一张表格也对应这单'];

/** 订单上有没有“即使财务同意也不能撤”的情况：返回原因数组（空 = 财务同意后可以撤）。 */
function pu_hard_reasons($orderId)
{
    $pdo = db(); $id = (int)$orderId; $why = [];
    $st = (string)$pdo->query("SELECT settlement_status FROM project_orders WHERE id=$id")->fetchColumn();
    if ($st === 'locked') $why[] = '订单已锁定';
    if ((int)$pdo->query("SELECT COUNT(*) FROM project_cash_movements WHERE order_id=$id AND (submitted_by_type<>'system' OR movement_type<>'receipt' OR review_status<>'approved')")->fetchColumn() > 0) $why[] = '有人工登记的收款或退款，请财务在订单页处理';
    if ((float)$pdo->query("SELECT refund_amount FROM project_orders WHERE id=$id")->fetchColumn() > 0) $why[] = '订单已有退款';
    $settled = (string)ps_setting_get('refund_settled_through', '');
    foreach ($pdo->query("SELECT DISTINCT payroll_month FROM project_commission_snapshots WHERE order_id=$id")->fetchAll(PDO::FETCH_COLUMN) as $m) {
        $locked = $pdo->prepare("SELECT status FROM project_payroll_periods WHERE period=?"); $locked->execute([$m]);
        if ($locked->fetchColumn() === 'locked' || ($settled !== '' && $m <= $settled)) $why[] = '分成月份（' . $m . '）已核算或锁定';
    }
    try {
        if ((int)$pdo->query("SELECT COUNT(*) FROM project_settlement_request_items i JOIN project_settlement_requests r ON r.id=i.request_id WHERE i.order_id=$id AND r.status<>'rejected'")->fetchColumn() > 0) $why[] = '合作人员已申请结算';
    } catch (Throwable $e) { /* 结算申请表还没建：没有申请 */ }
    return array_values(array_unique($why));
}

/** 导入时随订单自动生成、撤销时一并清理的资料表。 */
function pu_owned_tables()
{
    poi_ensure();
    return ['project_order_items', 'project_order_details', 'project_order_resources', 'project_order_sources', 'project_costs', 'project_participants', 'project_department_orders', 'project_department_uploaders'];
}

function pu_file_for_actor($fileId, $actor)
{
    $q = db()->prepare('SELECT id,employee_id,uploaded_by_type,uploaded_by_id,original_name,business_name,status,imported_count,created_at FROM project_import_files WHERE id=?');
    $q->execute([(int)$fileId]);
    $file = $q->fetch();
    if (!$file) {
        // 原件已被“删除原件”删掉、但订单还在库里：按审计里的上传回执和删除记录还原文件信息，仍可撤销这次上传的订单
        $del = db()->prepare("SELECT details_json,created_at FROM project_audit_logs WHERE entity_type='import_file' AND entity_id=? AND action='delete' ORDER BY id DESC LIMIT 1");
        $del->execute([(int)$fileId]); $delRow = $del->fetch();
        $rep = ps_import_result_get($fileId);
        if (!$delRow || !$rep) throw new RuntimeException('这张表格不存在，可能已被删除');
        $info = json_decode((string)$delRow['details_json'], true) ?: [];
        $op = (array)($rep['operator'] ?? []);
        $file = ['id' => (int)$fileId, 'employee_id' => (int)($op['employee_id'] ?? 0), 'uploaded_by_type' => (string)($op['type'] ?? 'employee'), 'uploaded_by_id' => (int)($op['id'] ?? 0),
            'original_name' => (string)($info['original_name'] ?? '（原件已删除）'), 'business_name' => (string)($info['business_name'] ?? ''), 'status' => 'deleted',
            'imported_count' => (int)($info['imported_count'] ?? 0), 'created_at' => (string)$delRow['created_at'], 'deleted' => true];
    }
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
            'approvable' => 0, 'message' => $file['status'] === 'preview' ? '这张表格只做了预览，没有写入订单，不需要撤销；不要的话直接“删除原件”即可。' : '这张表格是早期上传的，系统没有记录它新建了哪些订单，无法自动撤销，请联系财务处理。'];
    }
    $ids = array_values(array_unique(array_map('intval', (array)$report['written_order_ids'])));
    if (!$ids) return ['file' => $file, 'supported' => true, 'orders' => [], 'removable' => 0, 'kept' => 0, 'approvable' => 0, 'message' => '这张表格没有新建订单（订单在上传前就已存在），没有可撤销的内容。'];
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
    $removable = 0; $kept = 0; $approvable = 0; $list = [];
    foreach ($orders as $o) {
        if (!empty($o['gone'])) continue;
        $o['reasons'] = array_values(array_unique($o['reasons']));
        $o['removable'] = !$o['reasons'];
        // 只有“系统自动生成”的原因、又没有人工操作 / 月份锁定 / 结算申请：财务同意后可以撤
        $o['hard'] = [];
        if (!$o['removable']) {
            foreach ($o['reasons'] as $why) if (!in_array($why, PU_SOFT_REASONS, true)) $o['hard'][] = $why;
            $o['hard'] = array_values(array_unique(array_merge($o['hard'], pu_hard_reasons($o['id']))));
        }
        $o['approvable'] = !$o['removable'] && !$o['hard'];
        if ($o['removable']) $removable++; else $kept++;
        if ($o['approvable']) $approvable++;
        $list[] = ['id' => $o['id'], 'order_no' => $o['order_no'], 'customer_name' => $o['customer_name'], 'contract_amount' => (float)$o['contract_amount'], 'removable' => $o['removable'], 'approvable' => $o['approvable'], 'reasons' => $o['reasons'], 'hard' => $o['hard']];
    }
    return ['file' => $file, 'supported' => true, 'orders' => $list, 'removable' => $removable, 'kept' => $kept, 'approvable' => $approvable, 'message' => ''];
}

/** 执行：重新预演一遍（防止预演后数据变化），只撤仍然干净的订单；返回统计。 */
function pu_execute($fileId, $actor, $deleteFile = true, $withApproved = false)
{
    if ($withApproved && ($actor['role'] ?? '') !== 'finance') throw new RuntimeException('只有财务可以撤销已自动核对的订单');
    $plan = pu_plan($fileId, $actor);
    if (!$plan['supported'] || !($plan['removable'] + ($withApproved ? $plan['approvable'] : 0))) throw new RuntimeException($plan['message'] ?: '没有可以撤销的订单');
    $pdo = db();
    $removed = []; $keptNow = [];
    $owned = pu_owned_tables();
    $pdo->beginTransaction();
    try {
        foreach ($plan['orders'] as $o) {
            $viaApproval = !$o['removable'] && $withApproved && !empty($o['approvable']);
            if (!$o['removable'] && !$viaApproval) continue;
            $id = (int)$o['id'];
            $pdo->exec('SAVEPOINT pu_order');
            try {
                if ($viaApproval) {
                    // 财务同意撤销的“自动核对”订单：再核对一遍没有人工操作 / 锁定，放进回收站（30 天内可还原）后整单删除
                    $lock = $pdo->query("SELECT * FROM project_orders WHERE id=$id FOR UPDATE")->fetch();
                    if (!$lock || pu_hard_reasons($id)) { $pdo->exec('RELEASE SAVEPOINT pu_order'); $keptNow[] = $o['order_no']; continue; }
                    require_once __DIR__ . '/ProjectOrderDelete.php';
                    $pdo->exec("UPDATE project_refund_import_rows SET order_id=NULL WHERE order_id=$id AND review_status='pending'");
                    $trash = pod_backup($id, $lock['order_no'], $actor);
                    pod_delete_rows($id);
                    ps_audit('order', $id, 'undo_import', $actor, ['file_id' => (int)$fileId, 'file' => $plan['file']['original_name'], 'approved_by_finance' => true, 'reasons' => $o['reasons'], 'trash' => $trash]);
                    $pdo->exec('RELEASE SAVEPOINT pu_order');
                    $removed[] = $id;
                    continue;
                }
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
        $keptBase = $plan['kept'] - ($withApproved ? $plan['approvable'] : 0);
        ps_audit('import_file', (int)$fileId, 'undo_import', $actor, ['removed' => count($removed), 'kept' => $keptBase + count($keptNow), 'delete_file' => (bool)$deleteFile, 'with_approved' => (bool)$withApproved]);
        if (!$deleteFile) $pdo->prepare("UPDATE project_import_files SET status=IF(imported_count-?<=0,'preview',status), imported_count=GREATEST(imported_count-?,0) WHERE id=?")->execute([count($removed), count($removed), (int)$fileId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    $fileDeleted = false;
    // 只有所有订单都撤掉了才连原表格一起删；还有保留的订单时保留原表格，免得对不上
    $keptTotal = $keptBase + count($keptNow);
    if ($deleteFile && !$keptTotal && empty($plan['file']['deleted'])) { ps_import_file_delete((int)$fileId, $actor); $fileDeleted = true; }
    return ['removed' => count($removed), 'kept' => $keptTotal, 'file_deleted' => $fileDeleted];
}
