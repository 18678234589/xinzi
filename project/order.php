<?php
require_once __DIR__ . '/../includes/ProjectBusiness.php';
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectOrderSource.php';
$actor = ps_require_actor();
$id = (int)($_GET['id'] ?? 0);
$order = ps_order($id, $actor);
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    try {
        $action = (string)($_POST['action'] ?? '');
        $finance = $actor['role'] === 'finance';
        if (!in_array($action, ['approve_order', 'post_adjustment', 'set_order_kind'], true)) {
            db()->beginTransaction();
            $lock = db()->prepare('SELECT settlement_status FROM project_orders WHERE id=? FOR UPDATE');
            $lock->execute([$id]);
            $currentStatus = $lock->fetchColumn();
            if ($currentStatus === false || in_array($currentStatus, ['approved','locked'], true)) throw new RuntimeException('订单已审核，修改须走调整流程');
            if (!$finance) {
                $access = db()->prepare('SELECT 1 FROM project_participants WHERE order_id=? AND employee_id=? LIMIT 1');
                $access->execute([$id, $actor['employee_id']]);
                if (!$access->fetchColumn()) throw new RuntimeException('已不再参与此订单，请刷新页面');
            }
        }
        if ($action === 'save_customer_intake') {
            $changed = ps_save_customer_intake($id, $_POST, $actor);
            ps_audit('order', $id, 'customer_intake', $actor, ['fields' => $changed]);
        } elseif ($action === 'set_order_kind') {
            $kind = ps_order_kind_valid(ps_business_normalize($order['project_type']), $_POST['order_kind'] ?? '');
            if (!$finance && trim((string)$order['order_kind']) !== '') throw new RuntimeException('订单类型已填写，如需更改请联系财务');
            if ($finance) ps_reclassify_order_kind($id, $kind, $actor, (string)($_POST['adjust_month'] ?? date('Y-m')), !empty($_POST['apply_future']));
            else {
                db()->prepare("UPDATE project_orders SET order_kind=?,row_version=row_version+1 WHERE id=? AND order_kind=''")->execute([$kind, $id]);
                ps_audit('order', $id, 'order_kind', $actor, ['order_kind' => $kind]);
            }
        } elseif ($action === 'add_counterpart') {
            if (!ps_business_requires_technical($order['project_type'])) throw new RuntimeException('此业务暂不支持快捷关联');
            $group = $actor['role'] === 'customer_service' ? 'technical' : 'customer_service';
            if ($finance || !in_array($actor['role'], ['customer_service', 'technical'], true)) throw new RuntimeException('请由本单客服或技术关联协作人员');
            $employeeId = (int)($_POST['employee_id'] ?? 0);
            if (!ps_active_employee_for_business($employeeId, $group, ps_business_normalize($order['project_type']))) throw new RuntimeException('所选合作人员未开通此业务的有效账号');
            $count = db()->prepare('SELECT COUNT(*) FROM project_participants WHERE order_id=? AND commission_group=?');
            $count->execute([$id, $group]);
            if ((int)$count->fetchColumn() > 0) throw new RuntimeException('此组已有关联人员；多人分配请由财务调整权重');
            $person = db()->prepare('SELECT name FROM employees WHERE id=?');
            $person->execute([$employeeId]);
            $defaultRole = ps_employee_default_role($employeeId, $order['project_type'], $group) ?? ($group === 'technical' ? ps_business_people_labels(ps_business_normalize($order['project_type']))['frontend'] : '客服');
            db()->prepare('INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,?,?,1)')
                ->execute([$id, $employeeId, $group, $defaultRole]);
            ps_audit('order', $id, 'link_counterpart', $actor, ['employee_id' => $employeeId, 'group' => $group]);
        } elseif ($action === 'save_technical_details') {
            if (!$finance && $actor['role'] !== 'technical') throw new RuntimeException('只有技术或财务可补交技术资料');
            if ($order['project_type'] !== '网站模板') throw new RuntimeException('此订单不是网站模板业务');
            $details = ps_business_details('网站模板', $_POST['details'] ?? []);
            $q = db()->prepare('SELECT details_json FROM project_order_details WHERE order_id=? FOR UPDATE');
            $q->execute([$id]);
            $existing = json_decode((string)($q->fetchColumn() ?: '{}'), true) ?: [];
            foreach ($details as $key => $value) if ($value !== '') $existing[$key] = $value;
            db()->prepare('INSERT INTO project_order_details (order_id,business_name,details_json) VALUES (?,?,?) ON DUPLICATE KEY UPDATE details_json=VALUES(details_json)')
                ->execute([$id, '网站模板', json_encode($existing, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            ps_audit('order', $id, 'technical_details', $actor, ['fields' => array_keys(array_filter($details, 'strlen'))]);
        } elseif ($action === 'update_order') {
            if (!$finance) throw new RuntimeException('无权限');
            $contract = (string)($_POST['contract_amount'] ?? '');
            if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $contract) || (float)$contract > 999999999999.99) throw new RuntimeException('成交金额须为非负数，最多两位小数');
            $q = db()->prepare('UPDATE project_orders SET customer_name=?,shop=?,contract_amount=?,delivery_status=?,row_version=row_version+1 WHERE id=? AND row_version=?');
            $q->execute([trim((string)($_POST['customer_name'] ?? '')), trim((string)($_POST['shop'] ?? '')), round((float)$contract, 2), ($_POST['delivery_status'] ?? '') === 'finished' ? 'finished' : 'unfinished', $id, (int)($_POST['row_version'] ?? 0)]);
            if (!$q->rowCount()) throw new RuntimeException('订单已被其他人修改，请刷新后重试');
            db()->prepare("INSERT INTO project_order_sources (order_id,price_source) VALUES (?,'manual') ON DUPLICATE KEY UPDATE price_source='manual'")->execute([$id]);
            ps_audit('order', $id, 'update', $actor, ['contract' => $contract]);
        } elseif ($action === 'confirm_resources') {
            if (!$finance && $actor['role'] !== 'technical') throw new RuntimeException('只有技术或财务可以确认资源');
            $mode = (string)($_POST['domain_mode'] ?? '');
            $confirmed = ps_intake_confirm_resources($id, $mode, $_POST['domain_template_id'] ?? 0, $_POST['server_template_id'] ?? 0, $actor, $_POST['program_template_id'] ?? 0);
            ps_audit('order', $id, 'confirm_resources', $actor, ['domain_mode' => $mode] + $confirmed);
        } elseif ($action === 'add_cash') {
            if (!$finance && $actor['role'] !== 'customer_service') throw new RuntimeException('只有客服或财务可提交收款与退款');
            $kind = (string)($_POST['movement_type'] ?? '');
            $amount = (string)($_POST['amount'] ?? '');
            $note = trim((string)($_POST['note'] ?? ''));
            if (!in_array($kind, ['receipt','refund'], true) || !preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/', $amount) || (float)$amount <= 0 || (float)$amount > 999999999999.99 || strlen($note) > 500 || ($kind === 'refund' && $note === '')) throw new RuntimeException('请填写有效金额（最多两位小数）；退款须说明原因');
            $status = $finance ? 'approved' : 'pending';
            $q = db()->prepare('INSERT INTO project_cash_movements (order_id,movement_type,amount,note,review_status,submitted_by_type,submitted_by_id,reviewed_by_admin,reviewed_at) VALUES (?,?,?,?,?,?,?,?,?)');
            $q->execute([$id, $kind, round((float)$amount, 2), $note, $status, $actor['type'], $actor['id'], $finance ? $actor['id'] : null, $finance ? date('Y-m-d H:i:s') : null]);
            $cashId = (int)db()->lastInsertId();
            if ($finance) ps_recalculate_cash($id);
            ps_audit('cash', $cashId, 'create', $actor, ['order_id' => $id, 'type' => $kind, 'amount' => $amount, 'status' => $status]);
        } elseif ($action === 'review_cash') {
            if (!$finance) throw new RuntimeException('无权限');
            $cashId = (int)($_POST['cash_id'] ?? 0);
            $decision = (string)($_POST['decision'] ?? '');
            if (!in_array($decision, ['approved','rejected'], true)) throw new RuntimeException('审核结果无效');
            $q = db()->prepare("UPDATE project_cash_movements SET review_status=?,reviewed_by_admin=?,reviewed_at=NOW() WHERE id=? AND order_id=? AND review_status='pending'");
            $q->execute([$decision, $actor['id'], $cashId, $id]);
            if (!$q->rowCount()) throw new RuntimeException('收退款记录已处理，请刷新页面');
            if ($decision === 'approved') ps_recalculate_cash($id);
            ps_audit('cash', $cashId, 'review', $actor, ['decision' => $decision]);
        } elseif ($action === 'add_participant') {
            if (!$finance) throw new RuntimeException('无权限');
            $employeeId = (int)($_POST['employee_id'] ?? 0);
            $group = (string)($_POST['commission_group'] ?? '');
            $weight = (string)($_POST['group_weight'] ?? '');
            if (!in_array($group, ['technical','customer_service'], true) || !is_numeric($weight) || (float)$weight <= 0 || (float)$weight > 100) throw new RuntimeException('请选择组别和有效权重');
            $check = db()->prepare('SELECT id FROM employees WHERE id=?'); $check->execute([$employeeId]);
            if (!$check->fetchColumn()) throw new RuntimeException('合作人员不存在');
            $roleName = trim((string)($_POST['role_name'] ?? ''));
            if ($roleName === '') $roleName = ps_employee_default_role($employeeId, $order['project_type'], $group) ?? '';
            if (mb_strlen($roleName) > 80) throw new RuntimeException('岗位名称过长');
            $q = db()->prepare('INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE role_name=VALUES(role_name),group_weight=VALUES(group_weight)');
            $q->execute([$id, $employeeId, $group, $roleName, round((float)$weight / 100, 6)]);
            ps_audit('order', $id, 'participant', $actor, ['employee_id' => $employeeId, 'group' => $group, 'weight_percent' => $weight]);
        } elseif ($action === 'remove_participant') {
            if (!$finance) throw new RuntimeException('无权限');
            $participantId = (int)($_POST['participant_id'] ?? 0);
            $q = db()->prepare('DELETE FROM project_participants WHERE id=? AND order_id=?');
            $q->execute([$participantId, $id]);
            if (!$q->rowCount()) throw new RuntimeException('参与人不存在');
            ps_audit('order', $id, 'remove_participant', $actor, ['participant_id' => $participantId]);
        } elseif ($action === 'add_cost') {
            if (!$finance && $actor['role'] !== 'technical') throw new RuntimeException('只有技术或财务可以录入成本');
            $templateId = (int)($_POST['template_id'] ?? 0);
            $quantity = (string)($_POST['quantity'] ?? '1');
            if (!is_numeric($quantity) || (float)$quantity <= 0 || (float)$quantity > 10000) throw new RuntimeException('数量必须大于0');
            $proof = null;
            if ($templateId > 0) {
                $q = db()->prepare('SELECT * FROM project_cost_templates WHERE id=? AND is_active=1'); $q->execute([$templateId]);
                $template = $q->fetch();
                if (!$template) throw new RuntimeException('成本模板不可用');
                [$unitPrice, $amount, $supplierAmount] = ps_template_cost_amount($template, $order['contract_amount'], $quantity);
                if ((int)$template['requires_proof'] === 1) $proof = ps_upload_proof('proof');
                $status = ps_template_cost_status($template, $amount);
                $values = [$id, $templateId, $template['version'], $template['category'], $template['name'] . ($template['specification'] ? ' · ' . $template['specification'] : ''), (float)$quantity, $template['unit'], $unitPrice, $amount, $supplierAmount, $template['cost_kind'], 0, '', $proof, $status, $actor['employee_id']];
            } else {
                $name = trim((string)($_POST['item_name'] ?? ''));
                $price = (string)($_POST['unit_price'] ?? '');
                $reason = trim((string)($_POST['reason'] ?? ''));
                $customCategory = (string)($_POST['custom_category'] ?? 'other');
                if (!in_array($customCategory, ['domain','server','certificate','certification','api','plugin','outsourcing','other'], true)) throw new RuntimeException('自定义成本类别无效');
                if ($name === '' || $reason === '' || !is_numeric($price) || (float)$price < 0) throw new RuntimeException('自定义成本须填写名称、单价和原因');
                $proof = ps_upload_proof('proof');
                $amount = round((float)$price * (float)$quantity, 2);
                $kind = in_array($_POST['cost_kind'] ?? '', ['one_time','annual','monthly'], true) ? $_POST['cost_kind'] : 'one_time';
                $values = [$id, null, null, $customCategory, $name, (float)$quantity, '项', round((float)$price, 2), $amount, null, $kind, 1, $reason, $proof, 'pending', $actor['employee_id']];
            }
            $q = db()->prepare('INSERT INTO project_costs (order_id,template_id,template_version,category,item_name,quantity,unit,unit_price,amount,supplier_amount,cost_kind,is_custom,reason,proof_path,review_status,submitted_by_employee) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $q->execute($values);
            ps_audit('cost', (int)db()->lastInsertId(), 'create', $actor, ['order_id' => $id, 'amount' => $amount, 'status' => $status ?? 'pending']);
        } elseif ($action === 'review_cost') {
            if (!$finance) throw new RuntimeException('无权限');
            $costId = (int)($_POST['cost_id'] ?? 0);
            $decision = (string)($_POST['decision'] ?? '');
            if (!in_array($decision, ['approved','rejected'], true)) throw new RuntimeException('审核结果无效');
            $q = db()->prepare('UPDATE project_costs SET review_status=?,reviewed_by_admin=?,review_note=? WHERE id=? AND order_id=? AND review_status=\'pending\'');
            $q->execute([$decision, $actor['id'], trim((string)($_POST['review_note'] ?? '')), $costId, $id]);
            if (!$q->rowCount()) throw new RuntimeException('成本已处理，请刷新页面');
            ps_audit('cost', $costId, 'review', $actor, ['decision' => $decision]);
        } elseif ($action === 'void_cost') {
            if (!$finance) throw new RuntimeException('无权限');
            $costId = (int)($_POST['cost_id'] ?? 0);
            $reason = trim((string)($_POST['review_note'] ?? ''));
            if ($reason === '') throw new RuntimeException('请填写作废原因');
            $q = db()->prepare("UPDATE project_costs SET review_status='rejected',reviewed_by_admin=?,review_note=? WHERE id=? AND order_id=? AND review_status IN ('approved','pending')");
            $q->execute([$actor['id'], $reason, $costId, $id]);
            if (!$q->rowCount()) throw new RuntimeException('成本已处理，请刷新页面');
            ps_audit('cost', $costId, 'void', $actor, ['reason' => $reason]);
        } elseif ($action === 'post_adjustment') {
            if (!$finance) throw new RuntimeException('无权限');
            $refundText = trim((string)($_POST['refund_amount'] ?? ''));
            $costText = trim((string)($_POST['cost_delta'] ?? ''));
            if (($refundText !== '' && !preg_match('/^\d+(?:\.\d{1,2})?$/', $refundText)) || ($costText !== '' && !preg_match('/^-?\d+(?:\.\d{1,2})?$/', $costText))) throw new RuntimeException('金额最多两位小数；成本调整可为负数（冲减）');
            $created = ps_post_adjustment($id, $actor, $refundText === '' ? 0 : $refundText, $costText === '' ? 0 : $costText, (string)($_POST['reason'] ?? ''), (string)($_POST['adjust_month'] ?? ''));
            header('Location: ' . BASE_URL . '/project/order.php?id=' . $id . '&adjusted=' . count($created)); exit;
        } elseif ($action === 'approve_order') {
            if (!$finance) throw new RuntimeException('无权限');
            ps_approve_order($id, $actor, (string)($_POST['payroll_month'] ?? ''));
        } else throw new RuntimeException('操作无效');
        if (db()->inTransaction()) db()->commit();
        header('Location: ' . BASE_URL . '/project/order.php?id=' . $id . '&saved=1'); exit;
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); $error = $e->getMessage(); }
}

$order = ps_order($id, $actor);
$sourceQuery = db()->prepare('SELECT * FROM project_order_sources WHERE order_id=?');
$sourceQuery->execute([$id]);
$orderSource = $sourceQuery->fetch() ?: ['payment_nickname' => '', 'trade_status' => '', 'price_source' => (float)$order['contract_amount'] > 0 ? 'manual' : 'missing'];
$resourceQuery = db()->prepare('SELECT * FROM project_order_resources WHERE order_id=?');
$resourceQuery->execute([$id]);
$orderResource = $resourceQuery->fetch();
$detailsQuery = db()->prepare('SELECT details_json FROM project_order_details WHERE order_id=?');
$detailsQuery->execute([$id]);
$businessDetails = json_decode((string)($detailsQuery->fetchColumn() ?: '{}'), true) ?: [];
$businessDefinition = ps_business_catalog()[$order['project_type']] ?? null;
$costs = ps_costs($id);
$cashMovements = ps_cash_movements($id);
$people = ps_participants($id);
$sum = ps_summary($order, $costs, $people);
$canEdit = !in_array($order['settlement_status'], ['approved','locked'], true);
$approvedSnapshots = [];
$snapshotByPerson = [];
$snapshotPool = ['technical' => 0.0, 'customer_service' => 0.0];
if (!$canEdit) {
    $snapshotQuery = db()->prepare('SELECT * FROM project_commission_snapshots WHERE order_id=? ORDER BY id');
    $snapshotQuery->execute([$id]);
    $approvedSnapshots = $snapshotQuery->fetchAll();
    foreach ($approvedSnapshots as $snapshot) {
        $snapshotByPerson[$snapshot['commission_group'] . ':' . $snapshot['employee_id']] = $snapshot;
        $snapshotPool[$snapshot['commission_group']] += (float)$snapshot['commission_amount'];
    }
}
$ownCommission = 0.0;
$ownCommissionConfigured = true;
if ($actor['role'] !== 'finance') {
    if ($canEdit) {
        foreach ($people as $person) if ((int)$person['employee_id'] === $actor['employee_id']) {
            $calcPerson = $personLookup[$person['commission_group'] . ':' . $person['employee_id']] ?? null;
            if (!$calcPerson || !$calcPerson['estimated_calc']) $ownCommissionConfigured = false;
            else $ownCommission += round($calcPerson['estimated_calc']['share'] + $calcPerson['estimated_calc']['subsidy'], 2);
        }
    } else {
        foreach ($approvedSnapshots as $snapshot) if ((int)$snapshot['employee_id'] === $actor['employee_id']) $ownCommission += (float)$snapshot['commission_amount'];
    }
}
$templateQuery = db()->prepare("SELECT * FROM project_cost_templates WHERE is_active=1 AND (business_scope='' OR business_scope=?) ORDER BY FIELD(category,'program','domain','server','certificate','plugin','certification','api','outsourcing','other'),name,specification");
$templateQuery->execute([ps_business_normalize($order['project_type'])]);
$templates = $templateQuery->fetchAll();
$shopMatches = ps_shop_order_lookup($order['order_no']);
$orderKinds = ps_business_order_kinds($order['project_type']);
$todoRow = $order + ['price_source' => $orderSource['price_source'], 'domain_mode' => $orderResource['domain_mode'] ?? '', 'pending_costs' => count(array_filter($costs, function ($c) { return $c['review_status'] === 'pending'; })), 'pending_cash' => count(array_filter($cashMovements, function ($m) { return $m['review_status'] === 'pending'; })), 'tech_count' => count(array_filter($people, function ($p) { return $p['commission_group'] === 'technical'; }))];
$todos = ps_order_todos($todoRow);
$personLookup = [];
foreach (['technical', 'customer_service'] as $groupKey) foreach ($sum['groups'][$groupKey]['people'] as $calcPerson) $personLookup[$groupKey . ':' . $calcPerson['employee_id']] = $calcPerson;
$employees = $actor['role'] === 'finance' ? db()->query('SELECT id,name,department FROM employees ORDER BY department,name,id')->fetchAll() : [];
$shops = db()->query('SELECT name FROM shops ORDER BY sort,id')->fetchAll(PDO::FETCH_COLUMN);
$websiteOrder = ps_is_website_order($order['project_type']);
$collabOrder = ps_business_requires_technical($order['project_type']);
$counterpartGroup = $actor['role'] === 'customer_service' ? 'technical' : 'customer_service';
$counterpartCount = 0;
foreach ($people as $person) if ($person['commission_group'] === $counterpartGroup) $counterpartCount++;
$counterpartChoices = [];
if ($collabOrder && $canEdit && !$counterpartCount && in_array($actor['role'], ['customer_service','technical'], true)) {
    $q = db()->prepare('SELECT e.id,e.name,e.department FROM employees e JOIN project_users u ON u.employee_id=e.id WHERE u.role=? AND u.is_active=1 ORDER BY e.name,e.id');
    $q->execute([$counterpartGroup]);
    foreach ($q->fetchAll() as $person) if (ps_active_employee_for_business($person['id'], $counterpartGroup, ps_business_normalize($order['project_type']))) $counterpartChoices[] = $person;
}
$page_title = '订单结算单 ' . $order['order_no'];
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3"><h4 class="mb-0">订单结算单 <small class="text-muted"><?php echo e($order['order_no']); ?></small></h4><a class="btn btn-outline-secondary btn-sm" href="<?php echo BASE_URL; ?>/project/index.php">返回订单</a></div>
<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
<?php if (isset($_GET['saved'])): ?><div class="alert alert-success">已保存</div><?php endif; ?>
<?php if (isset($_GET['adjusted'])): ?><div class="alert alert-success">售后调整已登记，生成 <?php echo (int)$_GET['adjusted']; ?> 条分成调整（金额无变化的人员不生成）。</div><?php endif; ?>
<?php if ($todos): ?><div class="project-todo-bar mb-3"><strong><i class="fas fa-list-check mr-1"></i>待办</strong><?php foreach ($todos as [$todoText, $todoLevel]): ?><span class="badge badge-<?php echo e($todoLevel); ?>"><?php echo e($todoText); ?></span><?php endforeach; ?></div><?php endif; ?>
<?php foreach ($shopMatches as $shopMatch): if (!$shopMatch['refund'] && ($order['shop'] === '' || $shopMatch['shop'] === $order['shop']) && ($shopMatch['price'] === null || $orderSource['price_source'] === 'missing' || abs((float)$shopMatch['price'] - (float)$order['contract_amount']) < 0.005)) continue; if ($order['shop'] !== '' && $shopMatch['shop'] !== $order['shop']) continue; ?>
<div class="alert alert-warning project-shop-alert small"><i class="fas fa-store mr-1"></i> <?php echo e($shopMatch['source']); ?>流水（<?php echo e($shopMatch['shop']); ?>）<?php if ($shopMatch['refund']): ?>显示此订单号有退款 / 交易关闭<?php echo $shopMatch['refund_amount'] > 0 ? '（¥' . money($shopMatch['refund_amount']) . '）' : ''; ?><?php echo $shopMatch['status'] !== '' ? '，状态：' . e($shopMatch['status']) : ''; ?>。<?php echo $actor['role'] === 'finance' ? '请核对后在下方登记退款。' : '请告知财务核对退款。'; ?><?php else: ?>售价 ¥<?php echo money($shopMatch['price']); ?> 与结算单售价 ¥<?php echo money($order['contract_amount']); ?> 不一致，请财务核对。<?php endif; ?></div>
<?php endforeach; ?>
<div class="card mb-3"><div class="card-body">
  <div class="row"><div class="col-md-3"><small class="text-muted">客户</small><div><?php echo e($order['customer_name'] ?: '待补充'); ?></div></div><div class="col-md-3"><small class="text-muted">业务 / 订单类型</small><div><?php echo e($order['project_type']); ?><?php if ($orderKinds): ?> · <?php if ($canEdit && ($actor['role'] === 'finance' || trim((string)$order['order_kind']) === '')): ?><form method="post" class="d-inline-flex align-items-center"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="set_order_kind"><select name="order_kind" class="form-control form-control-sm mr-1" aria-label="订单类型"><option value="">选择订单类型</option><?php foreach ($orderKinds as $kindName): ?><option value="<?php echo e($kindName); ?>" <?php echo $order['order_kind'] === $kindName ? 'selected' : ''; ?>><?php echo e($kindName); ?></option><?php endforeach; ?></select><?php if ($actor['role'] === 'finance'): ?><label class="small mb-0 mr-1"><input type="checkbox" name="apply_future" value="1" checked> 今后同类上传也按此类</label><?php endif; ?><button class="btn btn-sm btn-outline-primary">保存类型</button></form><?php else: ?><?php echo e($order['order_kind'] ?: '未填'); ?><?php endif; ?><?php endif; ?></div></div><div class="col-md-3"><small class="text-muted">状态</small><div><?php echo e(ps_label('settlement', $order['settlement_status'])); ?> / <?php echo $order['delivery_status'] === 'finished' ? '已完成' : '未完成'; ?></div></div><div class="col-md-3"><small class="text-muted">订单日期</small><div><?php echo e($order['order_date']); ?></div></div></div>
  <?php if ($actor['role'] === 'finance' && !$canEdit && $orderKinds): ?><form method="post" class="form-inline mt-3 pt-3 border-top" onsubmit="return confirm('确认纠正订单类型？原审核快照保留，分成差额将计入所选未锁定月份。')"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="set_order_kind"><label class="mr-2">纠正已审核订单类型</label><select name="order_kind" class="form-control form-control-sm mr-2" required><?php foreach ($orderKinds as $kindName): ?><option value="<?php echo e($kindName); ?>" <?php echo $order['order_kind'] === $kindName ? 'selected' : ''; ?>><?php echo e($kindName); ?></option><?php endforeach; ?></select><input type="month" name="adjust_month" class="form-control form-control-sm mr-2" value="<?php echo e(ps_next_open_month(date('Y-m'))); ?>" required><label class="small mr-2 mb-0"><input type="checkbox" name="apply_future" value="1" checked> 此人以后同类上传默认此类</label><button class="btn btn-sm btn-outline-primary">更换类目并重算差额</button></form><?php endif; ?>
  <div class="row mt-3 pt-3 border-top"><div class="col-md-3"><small class="text-muted">付款昵称</small><div><?php echo e($orderSource['payment_nickname'] ?: '待上传补全'); ?></div></div><div class="col-md-3"><small class="text-muted">售价</small><div><?php echo $orderSource['price_source'] === 'missing' ? '待上传补全' : '¥' . money($order['contract_amount']); ?></div></div><div class="col-md-3"><small class="text-muted">店铺交易状态</small><div><?php echo e($orderSource['trade_status'] ?: '待上传补全'); ?></div></div><div class="col-md-3"><small class="text-muted">数据来源</small><div><?php echo $orderSource['price_source'] === 'shop_upload' ? '店铺订单上传' : ($orderSource['price_source'] === 'manual' ? '人工录入' : '待匹配'); ?></div></div></div>
  <?php if ($businessDefinition && $businessDefinition['fields']): ?><div class="row mt-3 pt-3 border-top"><?php foreach ($businessDefinition['fields'] as $key => $label): ?><div class="col-md-6 mb-2"><small class="text-muted"><?php echo e($label); ?></small><div><?php echo e(ps_contact_for($actor, ($businessDetails[$key] ?? '') ?: '—', $key === 'customer_wechat')); ?></div></div><?php endforeach; ?></div><?php endif; ?>
  <?php if (trim((string)$order['note']) !== ''): ?><div class="alert alert-light border small mt-3 mb-0"><strong>订单录入信息<?php echo $businessDefinition && $businessDefinition['resources'] ? '与资源提示' : ''; ?>：</strong><?php echo nl2br(e(ps_contact_for($actor, $order['note']))); ?><?php if ($businessDefinition && $businessDefinition['resources']): ?><div class="text-muted">已选择的标准域名/服务器会显示在下方成本明细；SSL 等非标准成本仍需补录并上传凭证。</div><?php endif; ?></div><?php endif; ?>
  <hr><div class="row text-center"><div class="col-md-3"><small>可结算收入</small><h4>¥<?php echo money($sum['income']); ?></h4></div><div class="col-md-3"><small><?php echo $sum['service_fee'] > 0 ? '直接成本（含服务费）' : '已审核直接成本'; ?></small><h4>¥<?php echo money($sum['approved_cost']); ?></h4></div><div class="col-md-3"><small>项目贡献利润</small><h4 class="text-success">¥<?php echo money($sum['profit']); ?></h4></div><div class="col-md-3"><small>待审成本 / 审核后预计利润</small><h4>¥<?php echo money($sum['pending_cost']); ?> / ¥<?php echo money($sum['estimated_profit']); ?></h4></div></div>
  <?php if ($sum['service_fee'] > 0): ?><div class="text-muted text-center small mt-2">直接成本已含店铺服务费：售价 ¥<?php echo money($order['contract_amount']); ?> × <?php echo round($sum['service_fee_rate'] * 100, 2); ?>% = ¥<?php echo money($sum['service_fee']); ?>；程序套餐、域名与服务器成本在下方逐项显示。个人分成按各自规则的服务费率计算，见“参与人与分成计算”。</div><?php endif; ?>
  <?php if ($actor['role'] === 'finance'): ?><div class="row text-center mt-2"><div class="col-md-6">技术项目分成池：<strong><?php echo ($canEdit && $sum['groups']['technical']['pool'] === null) ? '待配置' : '¥' . money($canEdit ? $sum['groups']['technical']['pool'] : $snapshotPool['technical']); ?></strong>（<?php echo $canEdit ? ($sum['groups']['technical']['rate'] === null ? '无规则' : money($sum['groups']['technical']['rate'] * 100) . '%') : '已审核快照'; ?>）</div><div class="col-md-6">客服项目分成池：<strong><?php echo ($canEdit && $sum['groups']['customer_service']['pool'] === null) ? '待配置' : '¥' . money($canEdit ? $sum['groups']['customer_service']['pool'] : $snapshotPool['customer_service']); ?></strong>（<?php echo $canEdit ? ($sum['groups']['customer_service']['rate'] === null ? '无规则' : money($sum['groups']['customer_service']['rate'] * 100) . '%') : '已审核快照'; ?>）</div></div><?php else: ?><div class="text-center mt-2"><?php echo $canEdit ? '本人预计项目分成' : '本人已审核项目分成'; ?>：<strong><?php echo $ownCommissionConfigured ? '¥' . money($ownCommission) : '待配置'; ?></strong></div><?php endif; ?>
</div></div>

<?php if ($actor['role'] === 'finance' && $canEdit): ?>
<div class="card mb-3"><div class="card-header">销售/财务信息</div><div class="card-body"><form method="post" class="form-row align-items-end">
<input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="update_order"><input type="hidden" name="row_version" value="<?php echo (int)$order['row_version']; ?>">
<div class="form-group col-md-2"><label>客户</label><input class="form-control" name="customer_name" value="<?php echo e($order['customer_name']); ?>"></div><div class="form-group col-md-2"><label>店铺</label><input class="form-control" name="shop" value="<?php echo e($order['shop']); ?>"></div>
<div class="form-group col-md-2"><label>成交价</label><input type="number" step="0.01" min="0" class="form-control" name="contract_amount" value="<?php echo e($order['contract_amount']); ?>"></div><div class="form-group col-md-2"><label>交付</label><select class="form-control" name="delivery_status"><option value="unfinished">未完成</option><option value="finished" <?php echo $order['delivery_status'] === 'finished' ? 'selected' : ''; ?>>已完成</option></select></div><div class="form-group col-md-2"><button class="btn btn-primary btn-block">保存</button></div>
</form></div></div>
<?php endif; ?>

<?php if ($collabOrder && $canEdit && in_array($actor['role'], ['customer_service','finance'], true)): ?>
<div class="card mb-3 project-form-card"><div class="card-body"><h5><i class="fas fa-heart mr-2 text-danger"></i>客服提交 · 买家与交易信息</h5><p class="text-muted small">与技术共用订单号 <?php echo e($order['order_no']); ?>。可补空字段；已有资料请由财务核对更正。售价不等于已审核实收。</p>
<form method="post" class="form-row align-items-end"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="save_customer_intake">
<div class="form-group col-md-3"><label>客户 / 公司</label><input class="form-control" name="customer_name" maxlength="200" value="<?php echo e($order['customer_name']); ?>" <?php echo $actor['role'] !== 'finance' && $order['customer_name'] !== '' ? 'readonly' : ''; ?>></div>
<div class="form-group col-md-3"><label>店铺</label><select class="form-control" name="shop" <?php echo $actor['role'] !== 'finance' && $order['shop'] !== '' ? 'disabled' : ''; ?>><option value="">待补充</option><?php foreach ($shops as $shopName): ?><option value="<?php echo e($shopName); ?>" <?php echo $order['shop'] === $shopName ? 'selected' : ''; ?>><?php echo e($shopName); ?></option><?php endforeach; ?></select></div>
<div class="form-group col-md-3"><label>买家付款昵称</label><input class="form-control" name="payment_nickname" maxlength="200" value="<?php echo e($orderSource['payment_nickname']); ?>" <?php echo $actor['role'] !== 'finance' && $orderSource['nickname_source'] !== 'missing' ? 'readonly' : ''; ?>></div>
<div class="form-group col-md-3"><label>售价 ¥</label><input class="form-control" type="number" min="0" step="0.01" name="contract_amount" value="<?php echo $orderSource['price_source'] === 'missing' ? '' : e($order['contract_amount']); ?>" <?php echo $actor['role'] !== 'finance' && $orderSource['price_source'] !== 'missing' ? 'readonly' : ''; ?>></div>
<div class="form-group col-md-9"><label>店铺交易状态</label><input class="form-control" name="trade_status" maxlength="100" value="<?php echo e($orderSource['trade_status']); ?>" placeholder="不填可由店铺订单上传补全" <?php echo $actor['role'] !== 'finance' && $orderSource['status_source'] !== 'missing' ? 'readonly' : ''; ?>></div><div class="form-group col-md-3"><button class="btn btn-success btn-block">保存客服资料</button></div>
</form></div></div>
<?php endif; ?>

<?php if ($collabOrder && $canEdit && !$counterpartCount && in_array($actor['role'], ['customer_service','technical'], true)): ?>
<div class="card mb-3 project-form-card"><div class="card-body"><h5><i class="fas fa-user-friends mr-2 text-primary"></i>关联同单<?php echo $counterpartGroup === 'technical' ? '技术' : '客服'; ?></h5><p class="text-muted small">关联后，对方登录即可在自己的项目订单中看到这张结算单；不会创建第二个订单号。多人协作权重由财务调整。</p>
<?php if ($counterpartChoices): ?><form method="post" class="form-row align-items-end"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="add_counterpart"><div class="form-group col-md-9"><label>选择已开通<?php echo e(ps_business_normalize($order['project_type'])); ?>业务的<?php echo $counterpartGroup === 'technical' ? '技术' : '客服'; ?></label><select class="form-control" name="employee_id" required><option value="">请选择</option><?php foreach ($counterpartChoices as $person): ?><option value="<?php echo (int)$person['id']; ?>"><?php echo e($person['name'] . ' · ' . $person['department']); ?></option><?php endforeach; ?></select></div><div class="form-group col-md-3"><button class="btn btn-outline-primary btn-block">关联到此订单</button></div></form><?php else: ?><div class="alert alert-warning mb-0">暂无可选账号，请联系财务开通对应业务。</div><?php endif; ?></div></div>
<?php endif; ?>

<?php if ($order['project_type'] === '网站模板' && $canEdit && in_array($actor['role'], ['technical','finance'], true)): ?>
<div class="card mb-3 project-form-card"><div class="card-body"><h5><i class="fas fa-laptop-code mr-2 text-primary"></i>模板技术提交 · 交付资料</h5><p class="text-muted small">客服已录入的买家资料保留在同一订单；这里只补技术交付信息，资源成本在下方确认。</p><form method="post" class="form-row align-items-end"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="save_technical_details"><?php foreach (ps_business_catalog()['网站模板']['fields'] as $key => $label): ?><div class="form-group col-md-4"><label><?php echo e($label); ?></label><input class="form-control" name="details[<?php echo e($key); ?>]" maxlength="300" value="<?php echo e($businessDetails[$key] ?? ''); ?>"></div><?php endforeach; ?><div class="col-12 text-right"><button class="btn btn-outline-primary">保存技术资料</button></div></form></div></div>
<?php endif; ?>

<?php if ($canEdit && $businessDefinition && $businessDefinition['resources'] && $orderResource && $orderResource['domain_mode'] === 'pending'): ?>
<div class="card mb-3 project-resource-pending"><div class="card-body"><h5><i class="fas fa-seedling mr-2"></i>技术提交 · 确认域名与服务器</h5><p class="text-muted mb-3">客服与技术共用此订单。技术确认后，选中的标准域名和服务器分别自动计入成本；未确认前不能审核分成。</p>
<?php if (in_array($actor['role'], ['technical','finance'], true)): ?><form method="post" class="form-row align-items-end"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="confirm_resources"><?php if (!empty($businessDefinition['program'])): $programOptions = ps_intake_templates('program', $order['project_type']); ?><div class="form-group col-md-12"><label>程序套餐（含空间 / 域名 / 商投，选中即按成本中心价入账）</label><select class="form-control" name="program_template_id" id="confirmProgram"><option value="0">不使用程序套餐</option><?php $lastProgram = ''; foreach ($programOptions as $programOption): if ($programOption['name'] !== $lastProgram): if ($lastProgram !== ''): ?></optgroup><?php endif; $lastProgram = $programOption['name']; ?><optgroup label="<?php echo e($programOption['name']); ?>"><?php endif; ?><option value="<?php echo (int)$programOption['id']; ?>"><?php echo e($programOption['name'] . ' · ' . $programOption['specification'] . ' · ¥' . money($programOption['price'])); ?></option><?php endforeach; if ($lastProgram !== ''): ?></optgroup><?php endif; ?></select><?php if (!$programOptions): ?><small class="text-warning">成本中心尚无程序套餐，请财务先导入《程序表记录》。</small><?php endif; ?></div><?php endif; ?><div class="form-group col-md-3"><label>域名使用</label><select class="form-control" name="domain_mode" id="confirmDomainMode" <?php echo empty($businessDefinition['program']) ? 'required' : ''; ?>><option value=""><?php echo empty($businessDefinition['program']) ? '请选择' : '套餐已含 / 请选择'; ?></option><option value="none">无需域名</option><option value="template">使用标准域名</option></select></div><div class="form-group col-md-3" id="confirmDomainTemplateWrap" hidden><label>域名标准成本</label><select class="form-control" name="domain_template_id" id="confirmDomainTemplate"><option value="">选择域名和周期</option><?php foreach (ps_intake_templates('domain') as $domainOption): ?><option value="<?php echo (int)$domainOption['id']; ?>"><?php echo e($domainOption['name'] . ' ' . $domainOption['specification'] . ' · ¥' . money($domainOption['price'])); ?></option><?php endforeach; ?></select></div><div class="form-group col-md-3"><label>服务器 / 空间</label><select class="form-control" name="server_template_id"><option value="0">无需标准服务器</option><?php foreach (ps_intake_templates('server') as $serverOption): ?><option value="<?php echo (int)$serverOption['id']; ?>"><?php echo e($serverOption['name'] . ' ' . $serverOption['specification'] . ' · ¥' . money($serverOption['price'])); ?></option><?php endforeach; ?></select></div><div class="form-group col-md-3"><button class="btn btn-success btn-block">确认并带入成本</button></div></form><?php endif; ?></div></div>
<script>(function(){var mode=document.getElementById('confirmDomainMode'),wrap=document.getElementById('confirmDomainTemplateWrap'),template=document.getElementById('confirmDomainTemplate');if(!mode)return;mode.addEventListener('change',function(){var use=mode.value==='template';wrap.hidden=!use;template.required=use;});})();</script>
<?php endif; ?>

<div class="card mb-3"><div class="card-header d-flex justify-content-between"><span>收款与退款记录</span><span class="text-muted">已审核实收 ¥<?php echo money($order['receipt_amount']); ?> · 已审核退款 ¥<?php echo money($order['refund_amount']); ?></span></div>
<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>时间</th><th>类型</th><th class="text-right">金额</th><th>说明</th><th>状态</th><th>操作</th></tr></thead><tbody>
<?php foreach ($cashMovements as $movement): ?><tr><td><?php echo e($movement['created_at']); ?></td><td><?php echo $movement['movement_type'] === 'receipt' ? '收款' : '退款'; ?></td><td class="text-right">¥<?php echo money($movement['amount']); ?></td><td><?php echo e(ps_contact_for($actor, $movement['note'])); ?></td><td><?php echo ['pending' => '待财务审核', 'approved' => '已审核', 'rejected' => '已驳回'][$movement['review_status']] ?? e($movement['review_status']); ?></td><td><?php if ($actor['role'] === 'finance' && $canEdit && $movement['review_status'] === 'pending'): ?><form method="post" class="form-inline"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="review_cash"><input type="hidden" name="cash_id" value="<?php echo (int)$movement['id']; ?>"><button class="btn btn-success btn-sm mr-1" name="decision" value="approved">通过</button><button class="btn btn-outline-danger btn-sm" name="decision" value="rejected">驳回</button></form><?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$cashMovements): ?><tr><td colspan="6" class="text-center text-muted">暂无记录</td></tr><?php endif; ?></tbody></table></div>
<?php if ($canEdit && in_array($actor['role'], ['finance','customer_service'], true)): ?><div class="card-body border-top"><form method="post" class="form-row align-items-end"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="add_cash"><div class="form-group col-md-2"><label>类型</label><select name="movement_type" class="form-control"><option value="receipt">收款</option><option value="refund">退款</option></select></div><div class="form-group col-md-2"><label>金额</label><input name="amount" type="number" min="0.01" step="0.01" class="form-control" required></div><div class="form-group col-md-6"><label>说明（退款必填）</label><input name="note" maxlength="500" class="form-control" placeholder="付款批次、退款原因等"></div><div class="form-group col-md-2"><button class="btn btn-outline-primary btn-block"><?php echo $actor['role'] === 'finance' ? '记录并确认' : '提交财务审核'; ?></button></div></form></div><?php endif; ?></div>

<div class="card mb-3"><div class="card-header d-flex justify-content-between"><span>项目直接成本</span><span class="text-muted">技术可录入，财务审核特殊成本</span></div><div class="table-responsive"><table class="table mb-0"><thead><tr><th>类型</th><th>项目</th><th>数量</th><th class="text-right">单价</th><th class="text-right">小计</th><th>周期</th><th>凭证</th><th>审核</th></tr></thead><tbody>
<?php if ($sum['service_fee'] > 0): ?><tr class="table-light"><td>服务费</td><td>店铺服务费（售价 × <?php echo round($sum['service_fee_rate'] * 100, 2); ?>%）</td><td>1 项</td><td class="text-right">¥<?php echo money($sum['service_fee']); ?></td><td class="text-right">¥<?php echo money($sum['service_fee']); ?></td><td>一次性</td><td>订单售价</td><td>系统计算</td></tr><?php endif; ?>
<?php foreach ($costs as $cost): ?><tr class="<?php echo $cost['review_status'] === 'rejected' ? 'text-muted' : ''; ?>"><td><?php echo e(ps_label('category', $cost['category'])); ?></td><td><?php echo e($cost['item_name']); ?><?php if ($cost['reason']): ?><div class="small text-muted"><?php echo e($cost['reason']); ?></div><?php endif; ?></td><td><?php echo e($cost['quantity'] . ' ' . $cost['unit']); ?></td><td class="text-right">¥<?php echo money($cost['unit_price']); ?></td><td class="text-right">¥<?php echo money($cost['amount']); ?><?php if ($actor['role'] === 'finance' && $cost['supplier_amount'] !== null): ?><div class="small text-muted">采购 ¥<?php echo money($cost['supplier_amount']); ?></div><?php endif; ?></td><td><?php echo e(ps_label('cost_kind', $cost['cost_kind'])); ?></td><td><?php if ($cost['proof_path']): ?><a href="<?php echo BASE_URL; ?>/project/proof.php?id=<?php echo (int)$cost['id']; ?>">查看凭证</a><?php endif; ?></td><td><?php echo e(ps_label('review', $cost['review_status'])); ?><?php if ($cost['review_note'] !== ''): ?><div class="small text-muted"><?php echo e($cost['review_note']); ?></div><?php endif; ?>
<?php if ($actor['role'] === 'finance' && $canEdit && $cost['review_status'] === 'pending'): ?><form method="post" class="form-inline mt-1"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="review_cost"><input type="hidden" name="cost_id" value="<?php echo (int)$cost['id']; ?>"><input class="form-control form-control-sm mr-1" name="review_note" placeholder="审核意见"><button class="btn btn-success btn-sm mr-1" name="decision" value="approved">通过</button><button class="btn btn-outline-danger btn-sm" name="decision" value="rejected">驳回</button></form><?php endif; ?><?php if ($actor['role'] === 'finance' && $canEdit && $cost['review_status'] === 'approved'): ?><form method="post" class="form-inline mt-1"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="void_cost"><input type="hidden" name="cost_id" value="<?php echo (int)$cost['id']; ?>"><input class="form-control form-control-sm mr-1" name="review_note" placeholder="作废原因" required><button class="btn btn-outline-danger btn-sm">作废</button></form><?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$costs): ?><tr><td colspan="8" class="text-center text-muted">暂无成本</td></tr><?php endif; ?></tbody></table></div></div>

<?php if ($canEdit && ($actor['role'] === 'technical' || $actor['role'] === 'finance')): ?>
<div class="card mb-3"><div class="card-header">＋添加项目成本</div><div class="card-body"><form method="post" enctype="multipart/form-data" class="form-row align-items-end">
<input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="add_cost">
<div class="form-group col-md-4"><label>选择成本项目</label><select class="form-control" name="template_id" id="projectTemplate"><option value="0">其他 / 自定义成本（需凭证审核）</option><?php foreach ($templates as $t): ?><option value="<?php echo (int)$t['id']; ?>" data-price="<?php echo e(($t['price_mode'] ?? 'fixed') === 'percent' ? round((float)$order['contract_amount'] * (float)$t['price'] / 100, 2) : $t['price']); ?>" data-proof="<?php echo (int)$t['requires_proof']; ?>"><?php echo e(ps_label('category', $t['category']) . ' / ' . $t['name'] . ($t['specification'] !== '' ? ' / ' . $t['specification'] : '') . ' · ' . (($t['price_mode'] ?? 'fixed') === 'percent' ? '售价 × ' . rtrim(rtrim($t['price'], '0'), '.') . '%' : '¥' . money($t['price']) . '/' . $t['unit'])); ?></option><?php endforeach; ?></select></div>
<div class="form-group col-md-2"><label>数量</label><input id="projectQuantity" class="form-control" type="number" step="0.01" min="0.01" name="quantity" value="1" required></div><div class="form-group col-md-2 project-custom-field"><label>自定义类别</label><select class="form-control" name="custom_category"><option value="other">其他</option><option value="certificate">SSL 证书</option><option value="domain">域名</option><option value="server">服务器</option><option value="certification">认证</option><option value="api">API</option><option value="plugin">插件</option><option value="outsourcing">外包</option></select></div><div class="form-group col-md-2 project-custom-field"><label>自定义名称</label><input class="form-control" name="item_name"></div><div class="form-group col-md-2 project-custom-field"><label>自定义单价</label><input id="projectCustomPrice" class="form-control" type="number" step="0.01" min="0" name="unit_price"></div><div class="form-group col-md-2 project-custom-field"><label>成本周期</label><select class="form-control" name="cost_kind"><option value="one_time">一次性</option><option value="annual">年度</option><option value="monthly">月度</option></select></div>
<div class="form-group col-md-4 project-custom-field"><label>自定义原因</label><input class="form-control" name="reason"></div><div class="form-group col-md-5" id="projectProofField"><label>凭证（JPG/PNG/PDF ≤5MB）</label><input id="projectProof" class="form-control-file" type="file" name="proof" accept=".jpg,.jpeg,.png,.pdf"></div><div class="form-group col-md-3"><button class="btn btn-success btn-block">添加成本</button></div>
</form><small class="text-muted">预计小计：<strong id="projectCostPreview">—</strong>。标准单价自动带入；超过 ¥500 或需凭证的项目进入财务审核。</small></div></div>
<script>
(function () {
  var template = document.getElementById('projectTemplate');
  var quantity = document.getElementById('projectQuantity');
  var customPrice = document.getElementById('projectCustomPrice');
  var proof = document.getElementById('projectProof');
  var proofField = document.getElementById('projectProofField');
  function refreshCostForm() {
    var selected = template.options[template.selectedIndex];
    var custom = template.value === '0';
    document.querySelectorAll('.project-custom-field').forEach(function (field) { field.classList.toggle('d-none', !custom); });
    var requiresProof = custom || selected.getAttribute('data-proof') === '1';
    proofField.classList.toggle('d-none', !requiresProof);
    proof.required = requiresProof;
    var price = custom ? parseFloat(customPrice.value) : parseFloat(selected.getAttribute('data-price'));
    var count = parseFloat(quantity.value);
    document.getElementById('projectCostPreview').textContent = Number.isFinite(price) && Number.isFinite(count) ? '¥' + (price * count).toFixed(2) : '—';
  }
  [template, quantity, customPrice].forEach(function (field) { field.addEventListener('input', refreshCostForm); field.addEventListener('change', refreshCostForm); });
  refreshCostForm();
})();
</script>
<?php endif; ?>

<div class="card mb-3"><div class="card-header d-flex justify-content-between flex-wrap"><span>参与人与分成计算</span><span class="text-muted small">按“业务 › 岗位 › 订单类型”匹配成本中心的分成规则；组池模式按组内权重分摊，独立模式按权重分摊直接成本</span></div><div class="table-responsive"><table class="table mb-0"><thead><tr><th>分成组</th><th>合作人员</th><th>岗位</th><th>组内权重</th><th>匹配规则</th><th class="text-right"><?php echo $canEdit ? '预计分成（含补助）' : '已审核分成（含补助）'; ?></th><?php if ($actor['role'] === 'finance'): ?><th>操作</th><?php endif; ?></tr></thead><tbody>
<?php foreach ($people as $person): if ($actor['role'] !== 'finance' && (int)$person['employee_id'] !== $actor['employee_id']) continue; $snapshotKey = $person['commission_group'] . ':' . $person['employee_id']; $calcPerson = $personLookup[$snapshotKey] ?? null; $calc = $calcPerson['calc'] ?? null; $snapshot = $snapshotByPerson[$snapshotKey] ?? null; ?><tr><td><?php echo e(ps_label('group', $person['commission_group'])); ?></td><td><?php echo e($person['name'] . ' · ' . $person['department']); ?></td><td><?php echo e($person['role_name'] ?: '—'); ?></td><td><?php echo money($person['group_weight'] * 100); ?>%</td>
<td class="small"><?php if (!$canEdit && $snapshot): ?><?php echo e(ps_label('mode', $snapshot['calc_mode'])); ?> · <?php echo money($snapshot['rate'] * 100); ?>%<div class="project-calc-note"><?php echo e($snapshot['calc_note']); ?></div><?php elseif ($calc): ?><?php echo e(ps_label('mode', $calc['mode'])); ?> · <?php echo round($calc['rate'] * 100, 4); ?>% · 服务费 <?php echo round($calc['fee_rate'] * 100, 2); ?>%<?php if ($calcPerson['rule']['note'] ?? ''): ?><div class="text-muted"><?php echo e($calcPerson['rule']['note']); ?></div><?php endif; ?><div class="project-calc-note"><?php echo e($calc['note']); ?></div><?php else: ?><span class="text-danger">未匹配到规则</span><?php if ($actor['role'] === 'finance'): ?> · <a href="<?php echo BASE_URL; ?>/project/settings.php#rules">去配置</a><?php endif; ?><?php endif; ?></td>
<td class="text-right text-nowrap"><?php if (!$canEdit): ?>¥<?php echo money($snapshot['commission_amount'] ?? 0); ?><?php if ((float)($snapshot['subsidy_amount'] ?? 0) > 0): ?><div class="small text-muted">含补助 ¥<?php echo money($snapshot['subsidy_amount']); ?></div><?php endif; ?><?php elseif ($calc): $estimated = $calcPerson['estimated_calc']; ?>¥<?php echo money(round($calc['share'] + $calc['subsidy'], 2)); ?><?php if ($calc['subsidy'] > 0): ?><div class="small text-muted">含补助 ¥<?php echo money($calc['subsidy']); ?></div><?php endif; ?><?php if (abs($estimated['share'] - $calc['share']) > 0.004): ?><div class="small text-muted">待审成本通过后 ¥<?php echo money(round($estimated['share'] + $estimated['subsidy'], 2)); ?></div><?php endif; ?><?php else: ?>待配置<?php endif; ?></td>
<?php if ($actor['role'] === 'finance'): ?><td><?php if ($canEdit): ?><form method="post"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="remove_participant"><input type="hidden" name="participant_id" value="<?php echo (int)$person['id']; ?>"><button class="btn btn-outline-danger btn-sm">移除</button></form><?php endif; ?></td><?php endif; ?></tr><?php endforeach; ?>
<?php if (!$people): ?><tr><td colspan="7" class="text-center text-muted">尚无参与人</td></tr><?php endif; ?></tbody></table></div>
<?php if ($actor['role'] === 'finance' && $canEdit): ?><div class="card-body border-top"><form method="post" class="form-row align-items-end"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="add_participant"><div class="form-group col-md-4"><label>合作人员</label><select name="employee_id" class="form-control" required><option value="">选择合作人员</option><?php foreach ($employees as $emp): ?><option value="<?php echo (int)$emp['id']; ?>"><?php echo e($emp['name'] . ' · ' . $emp['department'] . ' · ID ' . $emp['id']); ?></option><?php endforeach; ?></select></div><div class="form-group col-md-2"><label>组别</label><select name="commission_group" class="form-control"><option value="technical">技术</option><option value="customer_service">客服</option></select></div><div class="form-group col-md-2"><label>岗位</label><input name="role_name" class="form-control" list="projectRoleNames" placeholder="留空用默认岗位"><datalist id="projectRoleNames"><?php foreach (['前端','外包前端','后端','售后','模板技术','资料员','技术','客服','定制客服','定制技术'] as $roleOption): ?><option value="<?php echo e($roleOption); ?>"><?php endforeach; ?></datalist></div><div class="form-group col-md-2"><label>组内权重 %</label><input name="group_weight" class="form-control" type="number" min="0.0001" max="100" step="0.0001" value="100"></div><div class="form-group col-md-2"><button class="btn btn-outline-primary btn-block">添加/更新</button></div></form><small class="text-muted">技术组、客服组各自合计 100%；重复添加同一合作人员可更新其权重。</small></div><?php endif; ?>
</div>
<?php
$adjustQuery = db()->prepare('SELECT a.*,e.name FROM project_commission_adjustments a JOIN employees e ON e.id=a.employee_id WHERE a.order_id=? ORDER BY a.id');
$adjustQuery->execute([$id]);
$adjustments = $adjustQuery->fetchAll();
if ($actor['role'] !== 'finance') $adjustments = array_values(array_filter($adjustments, function ($a) use ($actor) { return (int)$a['employee_id'] === (int)$actor['employee_id']; }));
?>
<?php if (!$canEdit && ($actor['role'] === 'finance' || $adjustments)): ?>
<div class="card mb-3"><div class="card-header d-flex justify-content-between flex-wrap"><span>售后退款 / 成本调整</span><span class="text-muted small">订单已审核锁定：退款或补成本不改原结算，按差额生成调整，计入指定月份</span></div>
<?php if ($adjustments): ?><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>计入月份</th><th>合作人员</th><th>分成组</th><th class="text-right">调整金额</th><th>原因 / 计算</th><th>登记时间</th></tr></thead><tbody><?php foreach ($adjustments as $adj): ?><tr><td><?php echo e($adj['payroll_month']); ?></td><td><?php echo e($adj['name']); ?></td><td><?php echo e(ps_label('group', $adj['commission_group'])); ?></td><td class="text-right font-weight-bold <?php echo (float)$adj['amount'] < 0 ? 'text-danger' : 'text-success'; ?>"><?php echo ((float)$adj['amount'] > 0 ? '+' : '') . '¥' . money($adj['amount']); ?></td><td class="small"><?php echo e(ps_contact_for($actor, $adj['reason'])); ?><div class="project-calc-note"><?php echo e($adj['calc_note']); ?></div></td><td class="small text-muted"><?php echo e($adj['created_at']); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<?php if ($actor['role'] === 'finance'): ?><div class="card-body<?php echo $adjustments ? ' border-top' : ''; ?>"><form method="post" class="form-row align-items-end" onsubmit="return confirm('确认登记售后调整？退款将直接记为已审核，并按差额扣回或补发分成。')"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="post_adjustment">
<div class="form-group col-md-2"><label>退款金额 ¥</label><input class="form-control" type="number" min="0" step="0.01" name="refund_amount" placeholder="如 678"></div>
<div class="form-group col-md-2"><label>成本调整 ¥</label><input class="form-control" type="number" step="0.01" name="cost_delta" placeholder="冲减填负数，如 -180"></div>
<div class="form-group col-md-4"><label>原因</label><input class="form-control" name="reason" maxlength="300" required placeholder="如 客户退款，保留域名注册 120 元"></div>
<div class="form-group col-md-2"><label>计入月份</label><input class="form-control" type="month" name="adjust_month" value="<?php echo e(ps_next_open_month()); ?>" required></div>
<div class="form-group col-md-2"><button class="btn btn-outline-danger btn-block">登记调整</button></div>
</form><small class="text-muted">系统按审核时的比例重算：全额退款会收回分成和每单补助；部分退款或成本变化只调整差额。默认计入下一个未锁定月份。</small></div><?php endif; ?>
</div>
<?php endif; ?>
<?php if ($actor['role'] === 'finance' && $canEdit): ?><form method="post" class="form-inline justify-content-end mb-4"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="approve_order"><label class="mr-2" for="payrollMonth">项目分成归属月份</label><input id="payrollMonth" type="month" name="payroll_month" class="form-control mr-2" value="<?php echo date('Y-m'); ?>" required><button class="btn btn-primary" onclick="return confirm('确认收入、成本、参与人和归属月份均已核对？审核后本订单将锁定编辑。')">审核并生成项目分成</button></form><?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
