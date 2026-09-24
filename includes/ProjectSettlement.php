<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/ProjectBusiness.php';
require_once __DIR__ . '/ProjectSystem.php';

function ps_actor()
{
    if (isset($_SESSION['admin_id'])) return ['type' => 'admin', 'id' => (int)$_SESSION['admin_id'], 'employee_id' => null, 'role' => 'finance'];
    if (!isset($_SESSION['project_user_id'])) return null;
    $q = db()->prepare('SELECT * FROM project_users WHERE id=? AND is_active=1');
    $q->execute([(int)$_SESSION['project_user_id']]);
    $user = $q->fetch();
    return $user ? ['type' => 'employee', 'id' => (int)$user['id'], 'employee_id' => (int)$user['employee_id'], 'role' => $user['role'], 'username' => $user['username'], 'phone' => $user['phone'] ?? null, 'password_changed_at' => $user['password_changed_at'] ?? null] : null;
}

function ps_require_actor()
{
    $actor = ps_actor();
    if (!$actor) { header('Location: ' . BASE_URL . '/login.php'); exit; }
    // 合作人员首次登录须先绑定手机号（之后可用手机号登录），绑定前只能进入“我的账号”。
    if ($actor['type'] === 'employee' && array_key_exists('phone', $actor) && empty($actor['phone']) && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'profile.php' && PHP_SAPI !== 'cli') {
        header('Location: ' . BASE_URL . '/project/profile.php?first=1'); exit;
    }
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
    // 退款冲减单（代写换写手、上月退款）以负数实收登记，不受“退款不超过实收”限制。
    if ((float)$totals['refund'] > 0 && (float)$totals['refund'] > (float)$totals['receipt']) throw new RuntimeException('累计退款不能超过已审核实收');
    $update = db()->prepare('UPDATE project_orders SET receipt_amount=?,refund_amount=?,row_version=row_version+1 WHERE id=?');
    $update->execute([$totals['receipt'], $totals['refund'], (int)$orderId]);
}

function ps_participants($orderId)
{
    $q = db()->prepare('SELECT p.*, e.name, e.department FROM project_participants p JOIN employees e ON e.id=p.employee_id WHERE p.order_id=? ORDER BY p.commission_group,p.id');
    $q->execute([(int)$orderId]);
    return $q->fetchAll();
}

/** 岗位名去掉括号说明后拆分，如“前端（技术）/后端” => ['前端','后端']。 */
function ps_role_keys($role)
{
    $keys = [];
    foreach (preg_split('/[\/、,，]+/u', (string)$role) as $part) {
        $part = trim(preg_replace('/[（(][^）)]*[）)]/u', '', $part));
        if ($part !== '') $keys[] = $part;
    }
    return $keys;
}

/**
 * 按“业务 > 岗位 > 订单类型 > 生效日期”匹配最具体的有效规则。
 * 历史“网站定制”与 AI 网站定制共用同一套版本化规则；同一请求内缓存候选规则。
 */
function ps_rule_for($group, $projectType, $orderDate, $role = '', $orderKind = '')
{
    static $cache = [];
    if ($projectType === '网站定制') $projectType = 'AI网站定制';
    $key = $group . '|' . $projectType . '|' . $orderDate;
    if (!isset($cache[$key])) {
        $q = db()->prepare("SELECT * FROM project_commission_rules WHERE commission_group=? AND project_type IN (?, '*') AND effective_from<=? AND is_active=1 ORDER BY effective_from DESC, id DESC");
        $q->execute([$group, $projectType, $orderDate]);
        $cache[$key] = $q->fetchAll();
    }
    $roles = ps_role_keys($role);
    $best = null;
    $bestScore = -1;
    foreach ($cache[$key] as $rule) {
        $ruleRole = (string)($rule['role_name'] ?? '*');
        $ruleKind = (string)($rule['order_kind'] ?? '*');
        $anyRole = in_array($ruleRole, ['*', ''], true);
        $anyKind = in_array($ruleKind, ['*', ''], true);
        if (!$anyRole && !in_array($ruleRole, $roles, true)) continue;
        if (!$anyKind && $ruleKind !== (string)$orderKind) continue;
        $score = ($rule['project_type'] === $projectType ? 4 : 0) + ($anyRole ? 0 : 2) + ($anyKind ? 0 : 1);
        if ($score > $bestScore) { $best = $rule; $bestScore = $score; } // 候选按生效日期倒序，同分取最新版本。
    }
    return $best;
}

function ps_rule($group, $projectType, $orderDate)
{
    return ps_rule_for($group, $projectType, $orderDate);
}

function money_plain($value)
{
    return number_format((float)$value, 2, '.', '');
}

/**
 * 单人分成计算（纯函数，与部门核算表口径一一对应）：
 * - pool 组池：max(收入 − 直接成本 − 服务费 × 组内权重, 0) × 比例 × 组内权重。
 *   单人时即 (售价 − 成本 − 3%) × 8%；两名客服合接（主次）时与核算表一致为 (售价 − 成本 − 1.5%) × 4%。
 * - individual 独立：max(收入 − 直接成本 × 分摊比例 − 服务费, 0) × 比例（定制前后端各按自己的比例算全单利润，域名/SSL 按权重分摊）。
 * 服务费 = 售价 × 规则服务费率（未设置时用业务默认）。规则设了“成本下限”时，直接成本取 max(实际成本, 售价 × 下限比例)，
 * 如客服核算博山定制单按售价 65% 计成本、华梦外包按实际 80%。售价低于规则最低售价时不计分成与补助；补助按人每单固定。
 */
function ps_calc_person($rule, $income, $directCost, $contract, $weight, $businessFeeRate, $costNote = '')
{
    $mode = ($rule['calc_mode'] ?? 'pool') === 'individual' ? 'individual' : 'pool';
    $feeRate = isset($rule['service_fee_rate']) && $rule['service_fee_rate'] !== null && $rule['service_fee_rate'] !== '' ? (float)$rule['service_fee_rate'] : (float)$businessFeeRate;
    $fee = round((float)$contract * $feeRate, 2);
    $minCostRate = isset($rule['min_cost_rate']) && $rule['min_cost_rate'] !== null && $rule['min_cost_rate'] !== '' ? (float)$rule['min_cost_rate'] : 0.0;
    $floorApplied = $minCostRate > 0 && (float)$contract * $minCostRate > (float)$directCost;
    $cost = $floorApplied ? round((float)$contract * $minCostRate, 2) : (float)$directCost;
    $costBasis = $mode === 'individual' ? round($cost * (float)$weight, 2) : round($cost, 2);
    $feePart = $mode === 'pool' ? round($fee * (float)$weight, 2) : $fee;
    $base = round((float)$income - $costBasis - $feePart, 2);
    // 提成按未取整的服务费计算（核算表按月售价合计 × 费率），展示仍用到分的服务费；差额进“分成尾差”。
    $feeExact = (float)$contract * $feeRate * ($mode === 'pool' ? (float)$weight : 1);
    $baseExact = (float)$income - $costBasis - $feeExact;
    $rate = (float)$rule['rate'];
    $min = (float)($rule['min_contract_amount'] ?? 0);
    $blocked = $min > 0 && (float)$contract < $min;
    // allow_negative：退款冲减、亏损单按负数计入（代写/期刊按月合计口径）；否则单笔最低为 0。
    $allowNegative = !empty($rule['allow_negative']);
    $share = $blocked ? 0.0 : ($allowNegative ? $baseExact : max($baseExact, 0)) * $rate * ($mode === 'pool' ? (float)$weight : 1);
    $subsidy = $blocked ? 0.0 : round((float)($rule['per_order_subsidy'] ?? 0), 2);
    // 低利润单补助：整单利润（收入 − 成本，不扣服务费，与代写结算表一致）低于门槛时改按低档补助（如代写利润 5 元以下 1.5 元/单）。
    $lowThreshold = isset($rule['low_profit_threshold']) && $rule['low_profit_threshold'] !== null && $rule['low_profit_threshold'] !== '' ? (float)$rule['low_profit_threshold'] : null;
    $orderProfit = round((float)$income - (float)$cost, 2);
    $lowApplied = !$blocked && $lowThreshold !== null && $subsidy > 0 && $orderProfit < $lowThreshold;
    if ($lowApplied) $subsidy = round((float)($rule['low_profit_subsidy'] ?? 0), 2);
    $note = '(收入 ' . money_plain($income) . ' − 成本 ' . money_plain($costBasis) . ($costNote !== '' ? '〔' . $costNote . '〕' : '') . ($floorApplied ? '〔售价×' . round($minCostRate * 100, 2) . '%〕' : '') . ($mode === 'individual' && (float)$weight < 1 ? '〔分摊 ' . round((float)$weight * 100, 2) . '%〕' : '') . ' − 服务费 ' . money_plain($feePart) . ') × ' . round($rate * 100, 4) . '%' . ($mode === 'pool' && (float)$weight < 1 ? ' × 权重 ' . round((float)$weight * 100, 2) . '%' : '');
    if ($subsidy > 0) $note .= ' + 每单补助 ' . money_plain($subsidy) . ($lowApplied ? '（售价 − 成本 ' . money_plain($orderProfit) . ' 低于 ' . money_plain($lowThreshold) . '）' : '');
    if ($blocked) $note = '售价低于 ¥' . money_plain($min) . '，本单不计分成';
    return ['mode' => $mode, 'fee_rate' => $feeRate, 'fee' => $fee, 'fee_part' => $feePart, 'cost_basis' => $costBasis, 'base' => $base, 'rate' => $rate, 'weight' => (float)$weight, 'share' => $share, 'subsidy' => $subsidy, 'blocked' => $blocked, 'note' => $note];
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
    $approvedCost = round($approvedCost, 2);
    $pendingCost = round($pendingCost, 2);
    // PHPweb 程序：个人提成的成本按《PHPweb程序成本区间表》随售价与岗位调整（订单毛利仍按实际成本）。
    $phpApproved = 0.0; $phpAll = 0.0;
    foreach ($costs as $cost) {
        if (!ps_is_php_cost($cost)) continue;
        if ($cost['review_status'] === 'approved') $phpApproved += (float)$cost['amount'];
        if (in_array($cost['review_status'], ['approved', 'pending'], true)) $phpAll += (float)$cost['amount'];
    }
    $personCost = function ($group, $role, $withPending) use ($phpApproved, $phpAll, $approvedCost, $pendingCost, $order) {
        $base = $withPending ? $approvedCost + $pendingCost : $approvedCost;
        $php = $withPending ? $phpAll : $phpApproved;
        if ($php <= 0) return [$base, ''];
        [$adjusted, $note] = ps_php_cost_for((float)($order['contract_amount'] ?? 0), $group, $role, $php);
        return [round($base - $php + $adjusted, 2), $note];
    };
    $contract = (float)($order['contract_amount'] ?? 0);
    $orderKind = (string)($order['order_kind'] ?? '');
    // 业务默认店铺服务费按售价计（网站模板/环境配置/小程序 3%），AI 定制默认不扣；分成规则可按组或岗位覆盖。
    $businessFeeRate = ps_business_service_fee_rate($order['project_type']);
    $serviceFee = round($contract * $businessFeeRate, 2);
    $groups = [];
    foreach (['technical', 'customer_service'] as $group) {
        $people = array_values(array_filter($participants, function ($p) use ($group) { return $p['commission_group'] === $group; }));
        $defaultRule = ps_rule_for($group, $order['project_type'], $order['order_date'], '', $orderKind);
        $weight = array_sum(array_map(function ($p) { return (float)$p['group_weight']; }, $people));
        $pool = 0.0; $estimatedPool = 0.0; $subsidy = 0.0; $missing = false;
        foreach ($people as $i => $person) {
            $rule = ps_rule_for($group, $order['project_type'], $order['order_date'], $person['role_name'] ?? '', $orderKind);
            $people[$i]['rule'] = $rule;
            [$costNow, $noteNow] = $personCost($group, $person['role_name'] ?? '', false);
            [$costEst, $noteEst] = $personCost($group, $person['role_name'] ?? '', true);
            $people[$i]['calc'] = $rule ? ps_calc_person($rule, $income, $costNow, $contract, $person['group_weight'], $businessFeeRate, $noteNow) : null;
            $people[$i]['estimated_calc'] = $rule ? ps_calc_person($rule, $income, $costEst, $contract, $person['group_weight'], $businessFeeRate, $noteEst) : null;
            if (!$rule) { $missing = true; continue; }
            $pool += $people[$i]['calc']['share'];
            $estimatedPool += $people[$i]['estimated_calc']['share'];
            $subsidy += $people[$i]['calc']['subsidy'];
        }
        if (!$people) {
            // 无参与人时仍显示该组按默认规则可形成的分成池，供财务预估。
            [$costNow, $noteNow] = $personCost($group, '', false);
            [$costEst, $noteEst] = $personCost($group, '', true);
            $calc = $defaultRule ? ps_calc_person($defaultRule, $income, $costNow, $contract, 1, $businessFeeRate, $noteNow) : null;
            $estimated = $defaultRule ? ps_calc_person($defaultRule, $income, $costEst, $contract, 1, $businessFeeRate, $noteEst) : null;
            $pool = $calc ? $calc['share'] : null;
            $estimatedPool = $estimated ? $estimated['share'] : null;
        }
        $rule = $missing ? null : ($defaultRule ?: ($people[0]['rule'] ?? null));
        $groups[$group] = ['people' => $people, 'rule' => $rule, 'weight' => $weight, 'rate' => $rule ? (float)$rule['rate'] : null, 'missing_rule' => $missing, 'subsidy' => round($subsidy, 2),
            'pool' => $missing || $pool === null ? null : round($pool, 2), 'estimated_pool' => $missing || $estimatedPool === null ? null : round($estimatedPool, 2)];
    }
    return ['income' => $income, 'direct_cost' => $approvedCost, 'approved_cost' => round($approvedCost + $serviceFee, 2), 'service_fee' => $serviceFee, 'service_fee_rate' => $businessFeeRate, 'pending_cost' => $pendingCost,
        'profit' => round($income - $approvedCost - $serviceFee, 2), 'estimated_profit' => round($income - $approvedCost - $serviceFee - $pendingCost, 2), 'groups' => $groups];
}

/**
 * 一组参与人的分成（不含补助）换算为分：组池模式同一规则的子组整体四舍五入后按权重分摊，尾差归最高权重者；
 * 独立模式逐人四舍五入。返回与 $people 下标一致的分值。
 */
function ps_group_share_cents($people)
{
    $cents = [];
    $poolGroups = [];
    foreach ($people as $i => $person) {
        $cents[$i] = 0;
        if (empty($person['calc'])) continue;
        // 同一规则、计提基数相同的组池成员整体分摊（尾差归最高权重者）；基数不同（服务费按权重拆分）时逐人四舍五入。
        if ($person['calc']['mode'] === 'pool' && !$person['calc']['blocked']) $poolGroups[(int)$person['rule']['id'] . '|' . money_plain($person['calc']['base'])][$i] = $person;
        else $cents[$i] = (int)round($person['calc']['share'] * 100);
    }
    foreach ($poolGroups as $members) {
        $first = reset($members);
        $weightSum = array_sum(array_map(function ($p) { return (float)$p['group_weight']; }, $members));
        $subPoolCents = (int)round((!empty($first['rule']['allow_negative']) ? $first['calc']['base'] : max($first['calc']['base'], 0)) * $first['calc']['rate'] * $weightSum * 100);
        $normalized = array_map(function ($p) use ($weightSum) { return ['group_weight' => $weightSum > 0 ? (float)$p['group_weight'] / $weightSum : 0]; }, array_values($members));
        $shares = ps_allocate_pool_cents($subPoolCents, $normalized);
        foreach (array_keys($members) as $n => $i) $cents[$i] = $shares[$n];
    }
    return $cents;
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
        $needsResources = !empty(ps_business_catalog()[ps_business_normalize($order['project_type'])]['resources']);
        if ($needsResources && (!$resource || $resource['domain_mode'] === 'pending')) throw new RuntimeException('资源使用尚未由技术确认，不能生成项目分成');
        $sslExpected = (float)($resource['ssl_expected_amount'] ?? 0);
        if ($sslExpected > 0) {
            $sslApprovedCents = 0;
            foreach ($costs as $cost) if ($cost['category'] === 'certificate' && $cost['review_status'] === 'approved') $sslApprovedCents += (int)round((float)$cost['amount'] * 100);
            if ($sslApprovedCents < (int)round($sslExpected * 100)) throw new RuntimeException('SSL 报备成本尚未按凭证补录并审核，不能生成项目分成');
        }
        if ($order['delivery_status'] !== 'finished') throw new RuntimeException('项目尚未完成');
        if (!empty(ps_business_catalog()[ps_business_normalize($order['project_type'])]['kind_required']) && trim((string)($order['order_kind'] ?? '')) === '') throw new RuntimeException('请先选择订单类型（新订单 / 定制 / 续费），它决定分成比例和每单补助');
        $hasSubsidy = $sum['groups']['technical']['subsidy'] > 0 || $sum['groups']['customer_service']['subsidy'] > 0;
        $allowsNegative = false;
        foreach ($sum['groups'] as $group) foreach ($group['people'] as $person) if (!empty($person['rule']['allow_negative'])) $allowsNegative = true;
        if ($sum['income'] <= 0 && !$hasSubsidy && !($allowsNegative && $sum['income'] < 0)) throw new RuntimeException('没有可结算的实收收入');
        if ($sum['service_fee_rate'] > 0 && (float)$order['contract_amount'] <= 0 && $sum['income'] > 0) throw new RuntimeException($order['project_type'] . '订单须先核对售价，才能计算 ' . round($sum['service_fee_rate'] * 100, 2) . '% 店铺服务费');
        $cashPending = $pdo->prepare("SELECT COUNT(*) FROM project_cash_movements WHERE order_id=? AND review_status='pending'");
        $cashPending->execute([$orderId]);
        if ((int)$cashPending->fetchColumn() > 0) throw new RuntimeException('还有待审核的收款或退款');
        foreach ($costs as $cost) if ($cost['review_status'] === 'pending') throw new RuntimeException('还有待审核成本');
        $hasGroup = false;
        foreach ($sum['groups'] as $label => $group) {
            if (!$group['people']) continue;
            $hasGroup = true;
            if ($group['missing_rule']) throw new RuntimeException(($label === 'technical' ? '技术' : '客服') . '组有参与人未匹配到项目分成规则（按业务/岗位/订单类型），请先在成本中心配置');
            if (abs($group['weight'] - 1.0) > 0.000001) throw new RuntimeException(($label === 'technical' ? '技术' : '客服') . '组权重合计必须为100%');
        }
        if (!$hasGroup) throw new RuntimeException('请先添加技术或客服参与人');
        $insert = $pdo->prepare('INSERT INTO project_commission_snapshots (order_id,employee_id,commission_group,role_name,income_amount,direct_cost,service_fee,contribution_profit,rule_id,calc_mode,rate,group_weight,commission_amount,commission_exact,subsidy_amount,calc_note,payroll_month) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($sum['groups'] as $label => $group) {
            if (!$group['people']) continue;
            $shares = ps_group_share_cents($group['people']);
            foreach ($group['people'] as $i => $person) {
                $calc = $person['calc'];
                $subsidyCents = (int)round($calc['subsidy'] * 100);
                $insert->execute([$orderId, $person['employee_id'], $label, mb_substr((string)$person['role_name'], 0, 80), $sum['income'], $calc['cost_basis'], $calc['fee'], $calc['base'], $person['rule']['id'], $calc['mode'], $calc['rate'], $person['group_weight'], ($shares[$i] + $subsidyCents) / 100, round($calc['share'] + $calc['subsidy'], 6), $subsidyCents / 100, mb_substr($calc['note'], 0, 500), $payrollMonth]);
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

/** 界面统一中文标签；数据库仍保存英文枚举。 */
function ps_label($type, $value)
{
    $labels = [
        'category' => ['domain' => '域名', 'server' => '服务器', 'program' => '程序套餐', 'certificate' => 'SSL证书', 'certification' => '认证', 'api' => 'API', 'plugin' => '功能插件', 'outsourcing' => '外包', 'other' => '其他'],
        'cost_kind' => ['one_time' => '一次性', 'annual' => '年度', 'monthly' => '月度'],
        'review' => ['approved' => '已通过', 'pending' => '待财务审核', 'rejected' => '已驳回/作废'],
        'settlement' => ['draft' => '录入中', 'review' => '待审核', 'approved' => '已审核', 'locked' => '已锁定'],
        'group' => ['technical' => '技术', 'customer_service' => '客服'],
        'mode' => ['pool' => '组池分摊', 'individual' => '个人独立计提'],
    ];
    return $labels[$type][$value] ?? (string)$value;
}

/**
 * 订单待办（列表与结算单共用）。$row 需包含 order 字段及 price_source、domain_mode、pending_costs、pending_cash、tech_count、cs_count。
 * 返回 [[文本, 级别(warning/info/danger)], ...]；已审核订单无待办。
 */
function ps_order_todos($row)
{
    if (in_array($row['settlement_status'] ?? '', ['approved', 'locked'], true)) return [];
    $todos = [];
    if (($row['price_source'] ?? 'missing') === 'missing') $todos[] = ['售价待补', 'warning'];
    if (($row['domain_mode'] ?? '') === 'pending' && !empty(ps_business_catalog()[ps_business_normalize($row['project_type'])]['resources'])) $todos[] = ['资源待技术确认', 'warning'];
    if (ps_business_requires_technical($row['project_type']) && (int)($row['tech_count'] ?? 1) === 0) $todos[] = ['未指定技术', 'danger'];
    if ((int)($row['pending_costs'] ?? 0) > 0) $todos[] = ['成本待审 ' . (int)$row['pending_costs'], 'info'];
    if ((int)($row['pending_cash'] ?? 0) > 0) $todos[] = ['收退款待审 ' . (int)$row['pending_cash'], 'info'];
    if ((float)($row['receipt_amount'] ?? 0) <= 0) $todos[] = ['实收未确认', 'secondary'];
    if (($row['delivery_status'] ?? '') !== 'finished') $todos[] = ['交付未完成', 'secondary'];
    return $todos;
}

/**
 * 客户联系方式打码：手机号 158***3252；邮箱 ab***@qq.com；“微信 / VX / QQ”后面的账号保留首尾两位。
 * 财务 / 管理员始终看完整信息；技术、客服按“系统设置 › 客户联系方式权限”：默认本单参与人看完整，不在本单的人看打码。
 */
function ps_mask_contact($text)
{
    $text = (string)$text;
    if ($text === '') return $text;
    $text = preg_replace_callback('/([A-Za-z0-9._%+-]{1,2})[A-Za-z0-9._%+-]*(@[A-Za-z0-9.-]+\.[A-Za-z]{2,})/', function ($m) { return $m[1] . '***' . $m[2]; }, $text);
    $text = preg_replace_callback('/(?<!\d)(1[3-9]\d)(\d{4})(\d{4})(?!\d)/', function ($m) { return $m[1] . '***' . $m[3]; }, $text);
    $text = preg_replace_callback('/((?:微信号?|wx|vx|v信|qq)\s*[:：]?\s*)(?![0-9]{3}\*)([A-Za-z0-9_-]{5,30})/iu', function ($m) { return $m[1] . ps_mask_id($m[2]); }, $text);
    return $text;
}

/** 单独的微信号 / 账号字段：保留首尾两位，中间打码；手机号按手机号规则。 */
function ps_mask_id($value)
{
    $value = trim((string)$value);
    if ($value === '' || strpos($value, '***') !== false) return $value;
    if (preg_match('/^1[3-9]\d{9}$/', $value)) return substr($value, 0, 3) . '***' . substr($value, -4);
    $length = mb_strlen($value);
    if ($length <= 4) return mb_substr($value, 0, 1) . '***';
    return mb_substr($value, 0, 2) . '***' . mb_substr($value, -2);
}

/** 按查看人与是否参与本单决定是否打码（数据库保存原文）。 */
function ps_contact_for($actor, $text, $isIdField = false, $isParticipant = true)
{
    if (ps_contact_visible($actor, $isParticipant)) return (string)$text;
    return $isIdField ? ps_mask_contact(ps_mask_id($text)) : ps_mask_contact($text);
}

/** 从指定月份（默认本月）起第一个未锁定的项目分成月份。 */
function ps_next_open_month($from = null)
{
    $month = $from ?: date('Y-m');
    $q = db()->prepare("SELECT status FROM project_payroll_periods WHERE period=?");
    for ($i = 0; $i < 36; $i++) {
        $q->execute([$month]);
        if ($q->fetchColumn() !== 'locked') return $month;
        $month = date('Y-m', strtotime($month . '-01 +1 month'));
    }
    throw new RuntimeException('未来 36 个月均已锁定，请检查结算月份');
}

/**
 * 已审核订单发生售后退款或成本增减：登记退款（财务确认）与成本调整，按审核时的规则参数重算每人应得分成，
 * 与“原快照 + 以往调整”的差额写入调整单，计入指定月份（默认下一个未锁定月）。原快照保持不变。
 * 全额退款（可结算收入 ≤ 0）时每单补助一并收回，与小程序结算表“-350 / -20”口径一致。
 */
function ps_post_adjustment($orderId, $actor, $refundAmount, $costDelta, $reason, $payrollMonth)
{
    if (($actor['role'] ?? '') !== 'finance') throw new RuntimeException('只有财务可以登记售后调整');
    $refundAmount = round((float)$refundAmount, 2);
    $costDelta = round((float)$costDelta, 2);
    $reason = trim((string)$reason);
    if ($refundAmount < 0) throw new RuntimeException('退款金额不能为负数');
    if ($refundAmount == 0 && $costDelta == 0) throw new RuntimeException('请填写退款金额或成本调整');
    if ($reason === '' || mb_strlen($reason) > 300) throw new RuntimeException('请填写调整原因（300 字以内）');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$payrollMonth)) throw new RuntimeException('调整计入月份无效');
    $pdo = db();
    $nested = $pdo->inTransaction();
    if ($nested) $pdo->exec('SAVEPOINT project_adjustment'); else $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT * FROM project_orders WHERE id=? FOR UPDATE');
        $q->execute([(int)$orderId]);
        $order = $q->fetch();
        if (!$order || !in_array($order['settlement_status'], ['approved', 'locked'], true)) throw new RuntimeException('只有已审核的订单需要走售后调整；未审核订单请直接修改');
        $pdo->prepare('INSERT INTO project_payroll_periods (period) VALUES (?) ON DUPLICATE KEY UPDATE period=period')->execute([$payrollMonth]);
        $period = $pdo->prepare('SELECT status FROM project_payroll_periods WHERE period=? FOR UPDATE');
        $period->execute([$payrollMonth]);
        if ($period->fetchColumn() === 'locked') throw new RuntimeException($payrollMonth . ' 已锁定，请选择未锁定的月份');
        $cashId = null;
        if ($refundAmount > 0) {
            $pdo->prepare("INSERT INTO project_cash_movements (order_id,movement_type,amount,note,review_status,submitted_by_type,submitted_by_id,reviewed_by_admin,reviewed_at) VALUES (?,'refund',?,?,'approved',?,?,?,NOW())")
                ->execute([(int)$orderId, $refundAmount, '售后调整：' . $reason, $actor['type'], $actor['id'], $actor['id']]);
            $cashId = (int)$pdo->lastInsertId();
            ps_recalculate_cash((int)$orderId);
        }
        if ($costDelta != 0) {
            $pdo->prepare("INSERT INTO project_costs (order_id,category,item_name,quantity,unit,unit_price,amount,cost_kind,is_custom,reason,review_status,reviewed_by_admin,review_note) VALUES (?,'other',?,1,'项',?,?,'one_time',1,?,'approved',?,?)")
                ->execute([(int)$orderId, $costDelta > 0 ? '售后补录成本' : '售后冲减成本', $costDelta, $costDelta, $reason, $actor['id'], '售后调整']);
        }
        $q->execute([(int)$orderId]);
        $order = $q->fetch();
        $income = round((float)$order['receipt_amount'] - (float)$order['refund_amount'], 2);
        $directCost = 0.0;
        $phpCost = 0.0;
        foreach (ps_costs((int)$orderId) as $cost) if ($cost['review_status'] === 'approved') { $directCost += (float)$cost['amount']; if (ps_is_php_cost($cost)) $phpCost += (float)$cost['amount']; }
        $businessFeeRate = ps_business_service_fee_rate($order['project_type']);
        $snapshots = $pdo->prepare('SELECT s.*,r.service_fee_rate AS r_fee,r.min_contract_amount AS r_min,r.min_cost_rate AS r_min_cost,r.allow_negative AS r_allow_negative,r.id AS live_rule_id FROM project_commission_snapshots s LEFT JOIN project_commission_rules r ON r.id=s.rule_id WHERE s.order_id=? ORDER BY s.commission_group,s.id');
        $snapshots->execute([(int)$orderId]);
        $prior = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM project_commission_adjustments WHERE order_id=? AND employee_id=? AND commission_group=?');
        $insert = $pdo->prepare('INSERT INTO project_commission_adjustments (order_id,employee_id,commission_group,amount,payroll_month,reason,calc_note,cash_movement_id,created_by_admin) VALUES (?,?,?,?,?,?,?,?,?)');
        $groups = [];
        foreach ($snapshots->fetchAll() as $snap) {
            // 比例、方式、补助、服务费全部取自审核快照（服务费率 = 快照服务费 ÷ 售价），规则之后被修改不影响已审核订单口径。
            $feeRate = (float)$order['contract_amount'] > 0 ? round((float)$snap['service_fee'] / (float)$order['contract_amount'], 6) : 0;
            $rule = ['id' => (int)$snap['rule_id'], 'rate' => $snap['rate'], 'calc_mode' => $snap['calc_mode'], 'service_fee_rate' => $feeRate, 'per_order_subsidy' => $snap['subsidy_amount'], 'min_contract_amount' => $snap['r_min'] ?? 0, 'min_cost_rate' => $snap['r_min_cost'] ?? null, 'allow_negative' => $snap['r_allow_negative'] ?? 0];
            [$phpAdjusted, $phpNote] = $phpCost > 0 ? ps_php_cost_for((float)$order['contract_amount'], $snap['commission_group'], (string)$snap['role_name'], $phpCost) : [0.0, ''];
            $calc = ps_calc_person($rule, $income, round($directCost - $phpCost + $phpAdjusted, 2), $order['contract_amount'], $snap['group_weight'], $businessFeeRate, $phpNote);
            if ($income <= 0) { $calc['share'] = 0.0; $calc['subsidy'] = 0.0; }
            $groups[$snap['commission_group']][] = ['employee_id' => (int)$snap['employee_id'], 'group_weight' => $snap['group_weight'], 'rule' => $rule, 'calc' => $calc, 'snapshot' => $snap];
        }
        $created = [];
        foreach ($groups as $group => $people) {
            $shares = ps_group_share_cents($people);
            foreach ($people as $i => $person) {
                $newCents = $shares[$i] + (int)round($person['calc']['subsidy'] * 100);
                $prior->execute([(int)$orderId, $person['employee_id'], $group]);
                $oldCents = (int)round(((float)$person['snapshot']['commission_amount'] + (float)$prior->fetchColumn()) * 100);
                $delta = $newCents - $oldCents;
                if ($delta === 0) continue;
                $note = '重算后 ¥' . money_plain($newCents / 100) . '，原 ¥' . money_plain($oldCents / 100) . ($refundAmount > 0 ? '；退款 ¥' . money_plain($refundAmount) : '') . ($costDelta != 0 ? '；成本 ' . ($costDelta > 0 ? '+' : '') . money_plain($costDelta) : '');
                $insert->execute([(int)$orderId, $person['employee_id'], $group, $delta / 100, $payrollMonth, $reason, mb_substr($note, 0, 500), $cashId, $actor['id']]);
                $created[] = ['employee_id' => $person['employee_id'], 'group' => $group, 'amount' => $delta / 100];
            }
        }
        ps_audit('order', (int)$orderId, 'adjustment', $actor, ['refund' => $refundAmount, 'cost_delta' => $costDelta, 'month' => $payrollMonth, 'reason' => $reason, 'adjustments' => $created]);
        if ($nested) $pdo->exec('RELEASE SAVEPOINT project_adjustment'); else $pdo->commit();
        return $created;
    } catch (Throwable $e) {
        if ($nested) $pdo->exec('ROLLBACK TO SAVEPOINT project_adjustment'); else $pdo->rollBack();
        throw $e;
    }
}
