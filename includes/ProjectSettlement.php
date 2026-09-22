<?php
require_once __DIR__ . '/auth.php';

function ps_actor()
{
    if (isset($_SESSION['admin_id'])) return ['type' => 'admin', 'id' => (int)$_SESSION['admin_id'], 'employee_id' => null, 'role' => 'finance'];
    if (!isset($_SESSION['project_user_id'])) return null;
    $q = db()->prepare('SELECT id, employee_id, role, username FROM project_users WHERE id=? AND is_active=1');
    $q->execute([(int)$_SESSION['project_user_id']]);
    $user = $q->fetch();
    return $user ? ['type' => 'employee', 'id' => (int)$user['id'], 'employee_id' => (int)$user['employee_id'], 'role' => $user['role'], 'username' => $user['username']] : null;
}

function ps_require_actor()
{
    $actor = ps_actor();
    if (!$actor) { header('Location: ' . BASE_URL . '/login.php'); exit; }
    return $actor;
}

function ps_require_finance()
{
    $actor = ps_require_actor();
    if ($actor['role'] !== 'finance') { http_response_code(403); exit('无权限'); }
    return $actor;
}

function ps_csrf_token()
{
    if (empty($_SESSION['project_csrf'])) $_SESSION['project_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['project_csrf'];
}

function ps_check_csrf()
{
    if (!hash_equals(ps_csrf_token(), (string)($_POST['csrf'] ?? ''))) { http_response_code(403); exit('页面已过期，请刷新后重试'); }
}

function ps_order($id, $actor)
{
    $q = db()->prepare('SELECT * FROM project_orders WHERE id=?');
    $q->execute([(int)$id]);
    $order = $q->fetch();
    if (!$order) { http_response_code(404); exit('订单不存在'); }
    if ($actor['role'] !== 'finance') {
        $access = db()->prepare('SELECT 1 FROM project_participants WHERE order_id=? AND employee_id=? LIMIT 1');
        $access->execute([(int)$id, $actor['employee_id']]);
        if (!$access->fetchColumn()) { http_response_code(403); exit('无权限查看此订单'); }
    }
    return $order;
}

function ps_audit($entityType, $entityId, $action, $actor, $details)
{
    $q = db()->prepare('INSERT INTO project_audit_logs (entity_type,entity_id,action,actor_type,actor_id,details_json) VALUES (?,?,?,?,?,?)');
    $q->execute([$entityType, $entityId, $action, $actor['type'], $actor['id'], json_encode($details, JSON_UNESCAPED_UNICODE)]);
}

function ps_costs($orderId)
{
    $q = db()->prepare('SELECT c.*, e.name AS submitter FROM project_costs c LEFT JOIN employees e ON e.id=c.submitted_by_employee WHERE c.order_id=? ORDER BY c.id');
    $q->execute([(int)$orderId]);
    return $q->fetchAll();
}

function ps_cash_movements($orderId)
{
    $q = db()->prepare('SELECT * FROM project_cash_movements WHERE order_id=? ORDER BY created_at,id');
    $q->execute([(int)$orderId]);
    return $q->fetchAll();
}

function ps_recalculate_cash($orderId)
{
    $q = db()->prepare("SELECT COALESCE(SUM(CASE WHEN movement_type='receipt' THEN amount ELSE 0 END),0) AS receipt, COALESCE(SUM(CASE WHEN movement_type='refund' THEN amount ELSE 0 END),0) AS refund FROM project_cash_movements WHERE order_id=? AND review_status='approved'");
    $q->execute([(int)$orderId]);
    $totals = $q->fetch();
    if ((float)$totals['refund'] > (float)$totals['receipt']) throw new RuntimeException('累计退款不能超过已审核实收');
    $update = db()->prepare('UPDATE project_orders SET receipt_amount=?,refund_amount=?,row_version=row_version+1 WHERE id=?');
    $update->execute([$totals['receipt'], $totals['refund'], (int)$orderId]);
}

function ps_participants($orderId)
{
    $q = db()->prepare('SELECT p.*, e.name, e.department FROM project_participants p JOIN employees e ON e.id=p.employee_id WHERE p.order_id=? ORDER BY p.commission_group,p.id');
    $q->execute([(int)$orderId]);
    return $q->fetchAll();
}

function ps_rule($group, $projectType, $orderDate)
{
    $q = db()->prepare("SELECT * FROM project_commission_rules WHERE commission_group=? AND project_type IN (?, '*') AND effective_from<=? AND is_active=1 ORDER BY (project_type=?) DESC, effective_from DESC, id DESC LIMIT 1");
    $q->execute([$group, $projectType, $orderDate, $projectType]);
    return $q->fetch() ?: null;
}

function ps_summary($order, $costs, $participants)
{
    $income = round((float)$order['receipt_amount'] - (float)$order['refund_amount'], 2);
    $approvedCost = 0.0;
    $pendingCost = 0.0;
    foreach ($costs as $cost) {
        if ($cost['review_status'] === 'approved') $approvedCost += (float)$cost['amount'];
        if ($cost['review_status'] === 'pending') $pendingCost += (float)$cost['amount'];
    }
    $groups = [];
    foreach (['technical', 'customer_service'] as $group) {
        $people = array_values(array_filter($participants, function ($p) use ($group) { return $p['commission_group'] === $group; }));
        $rule = ps_rule($group, $order['project_type'], $order['order_date']);
        $weight = array_sum(array_map(function ($p) { return (float)$p['group_weight']; }, $people));
        $rate = $rule ? (float)$rule['rate'] : null;
        $groups[$group] = ['people' => $people, 'rule' => $rule, 'weight' => $weight, 'rate' => $rate,
            'pool' => $rate === null ? null : round(max($income - $approvedCost, 0) * $rate, 2),
            'estimated_pool' => $rate === null ? null : round(max($income - $approvedCost - $pendingCost, 0) * $rate, 2)];
    }
    return ['income' => $income, 'approved_cost' => round($approvedCost, 2), 'pending_cost' => round($pendingCost, 2),
        'profit' => round($income - $approvedCost, 2), 'estimated_profit' => round($income - $approvedCost - $pendingCost, 2), 'groups' => $groups];
}

function ps_allocate_pool_cents($poolCents, $people)
{
    if (!$people) return [];
    $shares = [];
    $highestIndex = 0;
    foreach ($people as $i => $person) {
        $shares[$i] = (int)round($poolCents * (float)$person['group_weight']);
        if ((float)$person['group_weight'] > (float)$people[$highestIndex]['group_weight']) $highestIndex = $i;
    }
    $shares[$highestIndex] += $poolCents - array_sum($shares);
    return $shares;
}

function ps_settlement_preview($legacyNetAmount, $projectCommission, $legacyTechnicalDeduction = 0, $technicalReconciled = true)
{
    if ($legacyNetAmount === null || !$technicalReconciled) return null;
    $legacyCents = (int)round((float)$legacyNetAmount * 100);
    $projectCents = (int)round((float)$projectCommission * 100);
    $deductionCents = (int)round((float)$legacyTechnicalDeduction * 100);
    return ($legacyCents - $deductionCents + $projectCents) / 100;
}

function ps_technical_reconciliation_summary($rows)
{
    $pending = 0;
    $deductionCents = 0;
    foreach ($rows as $row) {
        if (($row['commission_group'] ?? '') !== 'technical') continue;
        if (!isset($row['legacy_amount'])) { $pending++; continue; }
        $deductionCents += (int)round((float)$row['legacy_amount'] * 100);
    }
    return ['pending' => $pending, 'deduction' => $deductionCents / 100];
}

function ps_upload_proof($field)
{
    if (empty($_FILES[$field]['name']) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('请上传付款凭证');
    if ($_FILES[$field]['size'] > 5 * 1024 * 1024) throw new RuntimeException('凭证不能超过 5MB');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES[$field]['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'][$mime] ?? null;
    if (!$ext) throw new RuntimeException('凭证仅支持 JPG、PNG 或 PDF');
    $folder = dirname(__DIR__, 2) . '/project_proofs_private';
    if (!is_dir($folder) && !mkdir($folder, 0700, true)) throw new RuntimeException('无法创建凭证目录');
    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $folder . '/' . $filename)) throw new RuntimeException('凭证保存失败');
    return $filename;
}

function ps_approve_order($orderId, $actor, $payrollMonth)
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$payrollMonth)) throw new RuntimeException('项目分成归属月份无效');
    $pdo = db();
    $nested = $pdo->inTransaction();
    if ($nested) $pdo->exec('SAVEPOINT project_order_approval');
    else $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT * FROM project_orders WHERE id=? FOR UPDATE');
        $q->execute([$orderId]);
        $order = $q->fetch();
        if (!$order || !in_array($order['settlement_status'], ['draft','review'], true)) throw new RuntimeException('当前订单不可审核');
        $pdo->prepare('INSERT INTO project_payroll_periods (period) VALUES (?) ON DUPLICATE KEY UPDATE period=period')->execute([$payrollMonth]);
        $periodQuery = $pdo->prepare('SELECT status FROM project_payroll_periods WHERE period=? FOR UPDATE');
        $periodQuery->execute([$payrollMonth]);
        if ($periodQuery->fetchColumn() === 'locked') throw new RuntimeException('该项目分成月份已锁定，请选择未锁月份');
        $costs = ps_costs($orderId);
        $participants = ps_participants($orderId);
        $sum = ps_summary($order, $costs, $participants);
        $resourceQuery = $pdo->prepare('SELECT domain_mode,ssl_expected_amount FROM project_order_resources WHERE order_id=?');
        $resourceQuery->execute([$orderId]);
        $resource = $resourceQuery->fetch();
        if (in_array($order['project_type'], ['AI网站定制','小程序开发'], true) && (!$resource || $resource['domain_mode'] === 'pending')) throw new RuntimeException('资源使用尚未由技术确认，不能生成项目分成');
        $sslExpected = (float)($resource['ssl_expected_amount'] ?? 0);
        if ($sslExpected > 0) {
            $sslApprovedCents = 0;
            foreach ($costs as $cost) if ($cost['category'] === 'certificate' && $cost['review_status'] === 'approved') $sslApprovedCents += (int)round((float)$cost['amount'] * 100);
            if ($sslApprovedCents < (int)round($sslExpected * 100)) throw new RuntimeException('SSL 报备成本尚未按凭证补录并审核，不能生成项目分成');
        }
        if ($order['delivery_status'] !== 'finished') throw new RuntimeException('项目尚未完成');
        if ($sum['income'] <= 0) throw new RuntimeException('没有可结算的实收收入');
        $cashPending = $pdo->prepare("SELECT COUNT(*) FROM project_cash_movements WHERE order_id=? AND review_status='pending'");
        $cashPending->execute([$orderId]);
        if ((int)$cashPending->fetchColumn() > 0) throw new RuntimeException('还有待审核的收款或退款');
        foreach ($costs as $cost) if ($cost['review_status'] === 'pending') throw new RuntimeException('还有待审核成本');
        $hasGroup = false;
        foreach ($sum['groups'] as $label => $group) {
            if (!$group['people']) continue;
            $hasGroup = true;
            if (!$group['rule']) throw new RuntimeException(($label === 'technical' ? '技术' : '客服') . '组缺少项目分成规则');
            if (abs($group['weight'] - 1.0) > 0.000001) throw new RuntimeException(($label === 'technical' ? '技术' : '客服') . '组权重合计必须为100%');
        }
        if (!$hasGroup) throw new RuntimeException('请先添加技术或客服参与人');
        $insert = $pdo->prepare('INSERT INTO project_commission_snapshots (order_id,employee_id,commission_group,income_amount,direct_cost,contribution_profit,rule_id,rate,group_weight,commission_amount,payroll_month) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($sum['groups'] as $label => $group) {
            if (!$group['people']) continue;
            $poolCents = (int)round($group['pool'] * 100);
            $shares = ps_allocate_pool_cents($poolCents, $group['people']);
            foreach ($group['people'] as $i => $person) {
                $insert->execute([$orderId, $person['employee_id'], $label, $sum['income'], $sum['approved_cost'], $sum['profit'], $group['rule']['id'], $group['rate'], $person['group_weight'], $shares[$i] / 100, $payrollMonth]);
            }
        }
        $pdo->prepare("UPDATE project_orders SET settlement_status='approved',row_version=row_version+1 WHERE id=?")->execute([$orderId]);
        ps_audit('order', $orderId, 'approve', $actor, ['summary' => $sum['profit'], 'payroll_month' => $payrollMonth]);
        if ($nested) $pdo->exec('RELEASE SAVEPOINT project_order_approval');
        else $pdo->commit();
    } catch (Throwable $e) {
        if ($nested) $pdo->exec('ROLLBACK TO SAVEPOINT project_order_approval');
        else $pdo->rollBack();
        throw $e;
    }
}
