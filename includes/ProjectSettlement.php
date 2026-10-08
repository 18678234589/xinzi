<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/ProjectBusiness.php';
require_once __DIR__ . '/ProjectSystem.php';
require_once __DIR__ . '/ProjectDepartmentImport.php';

function ps_actor()
{
    if (isset($_SESSION['admin_id'])) return ['type' => 'admin', 'id' => (int)$_SESSION['admin_id'], 'employee_id' => null, 'role' => 'finance'];
    if (!isset($_SESSION['project_user_id'])) return null;
    $q = db()->prepare('SELECT * FROM project_users WHERE id=? AND is_active=1');
    $q->execute([(int)$_SESSION['project_user_id']]);
    $user = $q->fetch();
    return $user ? ['type' => 'employee', 'id' => (int)$user['id'], 'employee_id' => (int)$user['employee_id'], 'role' => $user['role'], 'username' => $user['username'], 'phone' => $user['phone'] ?? null, 'password_changed_at' => $user['password_changed_at'] ?? null] : null;
}

/** 管理层账号是否被财务分配了业务（有业务才开放订单入口，只能在分配的业务里录单）。 */
function ps_governance_has_business($actor)
{
    static $cache = [];
    $id = (int)($actor['id'] ?? 0);
    if (!$id) return false;
    if (!isset($cache[$id])) {
        try { $q = db()->prepare('SELECT 1 FROM project_user_businesses WHERE user_id=? LIMIT 1'); $q->execute([$id]); $cache[$id] = (bool)$q->fetchColumn(); }
        catch (Throwable $e) { $cache[$id] = false; }
    }
    return $cache[$id];
}

function ps_require_actor()
{
    $actor = ps_actor();
    if (!$actor) { header('Location: ' . BASE_URL . '/login.php'); exit; }
    if ($actor['type'] === 'employee' && $actor['role'] === 'governance') {
        $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
        $allowed = ['profile.php', 'governance.php', 'governance_ideas.php', 'governance_election.php', 'governance_rules.php', 'governance_evidence.php', 'payroll.php', 'welfare.php', 'contributions.php', 'messages.php', 'holidays.php', 'vault.php', 'knowledge.php', 'knowledge_article.php', 'knowledge_links.php', 'knowledge_categories.php', 'knowledge_rules.php', 'knowledge_keywords.php', 'knowledge_integrations.php', 'knowledge_skills.php', 'knowledge_skill.php', 'knowledge_skill_import.php', 'knowledge_skills_export.php', 'knowledge_costs.php'];
        if ($script === 'rules.php' && ($_GET['domain'] ?? $_POST['domain'] ?? '') === 'governance') $allowed[] = 'rules.php';
        if ($script === 'rules.php' && ($_GET['domain'] ?? $_POST['domain'] ?? '') === 'welfare') $allowed[] = 'rules.php';
        // 被分配了业务的管理层账号（如负责备案的董事长）：开放订单入口，页面内按“技术”身份录单，只能在分配的业务里建单、只能看到自己参与的订单。
        if (in_array($script, ['index.php', 'order.php', 'lookup.php', 'proof.php', 'credentials.php', 'ai.php', 'rule_request_api.php', 'import.php', 'files.php', 'import_undo_api.php', 'file_sheet_api.php', 'renewals.php', 'renewal_gaps.php'], true) && ps_governance_has_business($actor)) {
            $allowed[] = $script;
            $actor['role'] = 'technical'; $actor['governance_orders'] = true;
        }
        if (!in_array($script, $allowed, true)) { http_response_code(403); exit('此账号仅可访问管理层事项与本人结算'); }
        if ($script !== 'profile.php' && empty($actor['password_changed_at']) && PHP_SAPI !== 'cli') {
            header('Location: ' . BASE_URL . '/project/profile.php?password=1'); exit;
        }
    }
    // 平台信息专用账号：只能进入平台信息、我的账号、站内信；初始密码须先修改
    if ($actor['type'] === 'employee' && $actor['role'] === 'vault') {
        $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
        $knowledgeRuleRead = (($script === 'rules.php' && in_array($_GET['domain'] ?? '', ['welfare','governance'],true)) || $script === 'governance_rules.php') && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET';
        if (!$knowledgeRuleRead && !in_array($script, ['profile.php', 'vault.php', 'messages.php', 'dup_feedback.php', 'knowledge.php', 'knowledge_article.php', 'knowledge_links.php', 'knowledge_categories.php', 'knowledge_rules.php', 'knowledge_keywords.php', 'knowledge_integrations.php', 'knowledge_skills.php', 'knowledge_skill.php', 'knowledge_skill_import.php', 'knowledge_skills_export.php', 'knowledge_costs.php'], true)) { header('Location: ' . BASE_URL . '/project/vault.php'); exit; }
        if ($script !== 'profile.php' && empty($actor['password_changed_at']) && PHP_SAPI !== 'cli') { header('Location: ' . BASE_URL . '/project/profile.php?password=1'); exit; }
    }
    // 尚未绑定手机号时仍可阅读共享知识；提交和其他业务操作继续要求先完成绑定。
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $knowledgeReadOnly = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && in_array($script, [
        'knowledge.php', 'knowledge_article.php', 'knowledge_links.php', 'knowledge_categories.php',
        'knowledge_rules.php', 'knowledge_skills.php', 'knowledge_skill.php', 'knowledge_costs.php',
        'knowledge_keywords.php',
    ], true);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && $script === 'rules.php'
        && in_array((string)($_GET['domain'] ?? ''), ['welfare', 'governance'], true)) $knowledgeReadOnly = true;
    if ($actor['type'] === 'employee' && array_key_exists('phone', $actor) && empty($actor['phone']) && $script !== 'profile.php' && !$knowledgeReadOnly && PHP_SAPI !== 'cli') {
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
        if (!$access->fetchColumn() && !ps_department_import_uploader_access($id, (int)$actor['employee_id'])) {
            $isMissingBackendTech = false;
            if ($actor['role'] === 'technical' && in_array(ps_business_normalize($order['project_type'] ?? ''), ['AI网站定制', '网站定制'], true)) {
                $checkHasBackend = db()->prepare("SELECT 1 FROM project_participants WHERE order_id=? AND commission_group='technical' AND role_name LIKE '%后端%' LIMIT 1");
                $checkHasBackend->execute([(int)$id]);
                if (!$checkHasBackend->fetchColumn() && ps_active_employee_for_business($actor['employee_id'], 'technical', ps_business_normalize($order['project_type']))) {
                    $isMissingBackendTech = true;
                }
            }
            if (!$isMissingBackendTech) { http_response_code(403); exit('无权限查看此订单'); }
        }
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
    // 尚未录入收款的订单（目前大多数）：退款按售价预估可退金额，不超过售价即可；财务登记实收后再按实收复核。
    $cs = db()->prepare('SELECT contract_amount FROM project_orders WHERE id=?'); $cs->execute([(int)$orderId]);
    $refundCap = (float)$totals['receipt'] > 0 ? (float)$totals['receipt'] : (float)$cs->fetchColumn();
    if ((float)$totals['refund'] > 0 && (float)$totals['refund'] > $refundCap + 0.004) throw new RuntimeException('累计退款不能超过已审核实收（尚未录入收款时不能超过售价）');
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
    $orderKind = ps_role_rule_order_kind($projectType, $group, $role, $orderKind);
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

/**
 * 为兼任多岗位的人员（如“前端/后端”、“外包前端/后端”）匹配全部适用的独立规则。
 * 若单岗位或未能匹配多规则，则返回单条规则组成的数组。
 */
function ps_rules_for_person($group, $projectType, $orderDate, $role = '', $orderKind = '')
{
    $roles = ps_role_keys($role);
    if (count($roles) <= 1) {
        $single = ps_rule_for($group, $projectType, $orderDate, $role, $orderKind);
        return $single ? [$single] : [];
    }
    $matched = [];
    $seenIds = [];
    foreach ($roles as $rKey) {
        $r = ps_rule_for($group, $projectType, $orderDate, $rKey, $orderKind);
        if ($r && !isset($seenIds[$r['id']])) {
            $seenIds[$r['id']] = true;
            $matched[] = $r;
        }
    }
    if ($matched) return $matched;
    $fallback = ps_rule_for($group, $projectType, $orderDate, $role, $orderKind);
    return $fallback ? [$fallback] : [];
}

/** 定制岗位由已分配的岗位确定算法，不能因 AI 猜成“技术服务/新订单”掉到通用 5% 档。 */
function ps_role_rule_order_kind($projectType, $group, $role, $orderKind)
{
    if ($projectType !== '小程序开发' || $orderKind === '续费') return $orderKind;
    if (in_array($orderKind, ['模板', '模板订单', '新建站'], true)) return '新订单';
    $roles = ps_role_keys($role);
    if ($group === 'technical' && array_intersect($roles, ['定制技术15', '定制技术', '定制技术30'])) return '定制';
    if ($group === 'customer_service' && in_array('定制客服', $roles, true)) return '定制';
    return $orderKind;
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
function ps_calc_person($rule, $income, $directCost, $contract, $weight, $businessFeeRate, $costNote = '', $feeBase = null)
{
    $mode = ($rule['calc_mode'] ?? 'pool') === 'individual' ? 'individual' : 'pool';
    $feeRate = isset($rule['service_fee_rate']) && $rule['service_fee_rate'] !== null && $rule['service_fee_rate'] !== '' ? (float)$rule['service_fee_rate'] : (float)$businessFeeRate;
    // 服务费基数：订单有退款时按“售价 − 退款”后的净额计（平台按实际成交额结算），其余仍按售价；起算售价等门槛仍看原售价。
    $feeBaseAmount = $feeBase === null ? (float)$contract : (float)$feeBase;
    $fee = round($feeBaseAmount * $feeRate, 2);
    $minCostRate = isset($rule['min_cost_rate']) && $rule['min_cost_rate'] !== null && $rule['min_cost_rate'] !== '' ? (float)$rule['min_cost_rate'] : 0.0;
    $floorApplied = $minCostRate > 0 && (float)$contract * $minCostRate > (float)$directCost;
    $cost = $floorApplied ? round((float)$contract * $minCostRate, 2) : (float)$directCost;
    $costBasis = $mode === 'individual' ? round($cost * (float)$weight, 2) : round($cost, 2);
    // 默认组池先扣整单服务费再分成。网站模板核算表另有“主次客服服务费 1.5%”约定，保留该业务明确口径。
    $feeWeight = $mode === 'pool' && ($rule['project_type'] ?? '') === '网站模板' && ($rule['commission_group'] ?? '') === 'customer_service' ? (float)$weight : 1.0;
    $feePart = round($fee * $feeWeight, 2);
    $base = round((float)$income - $costBasis - $feePart, 2);
    // 提成按未取整的服务费计算（核算表按月售价合计 × 费率），展示仍用到分的服务费；差额进“分成尾差”。
    $feeExact = $feeBaseAmount * $feeRate * $feeWeight;
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
    $subsidyPool = $subsidy;
    $subsidy = round($subsidyPool * ($mode === 'pool' ? (float)$weight : 1), 2);
    $note = '(收入 ' . money_plain($income) . ' − 成本 ' . money_plain($costBasis) . ($costNote !== '' ? '〔' . $costNote . '〕' : '') . ($floorApplied ? '〔售价×' . round($minCostRate * 100, 2) . '%〕' : '') . ($mode === 'individual' && (float)$weight < 1 ? '〔分摊 ' . round((float)$weight * 100, 2) . '%〕' : '') . ' − 服务费 ' . money_plain($feePart) . ') × ' . round($rate * 100, 4) . '%' . ($mode === 'pool' && (float)$weight < 1 ? ' × 权重 ' . round((float)$weight * 100, 2) . '%' : '');
    if ($subsidy > 0) $note .= ' + 每单补助 ' . money_plain($subsidy) . ($mode === 'pool' && (float)$weight < 1 ? '（整单补助 ' . money_plain($subsidyPool) . ' × 权重 ' . round((float)$weight * 100, 2) . '%）' : '') . ($lowApplied ? '（售价 − 成本 ' . money_plain($orderProfit) . ' 低于 ' . money_plain($lowThreshold) . '）' : '');
    if ($blocked) $note = '售价低于 ¥' . money_plain($min) . '，本单不计分成';
    return ['mode' => $mode, 'fee_rate' => $feeRate, 'fee' => $fee, 'fee_part' => $feePart, 'cost_basis' => $costBasis, 'base' => $base, 'rate' => $rate, 'weight' => (float)$weight, 'share' => $share, 'subsidy' => $subsidy, 'blocked' => $blocked, 'note' => $note,
        // 计算过程弹窗用：把每一步的输入原样带出
        'income' => round((float)$income, 2), 'contract' => round((float)$contract, 2), 'raw_cost' => round((float)$directCost, 2), 'min_cost_rate' => $minCostRate, 'floor_applied' => $floorApplied,
        'min_contract' => $min, 'allow_negative' => $allowNegative, 'low_applied' => $lowApplied, 'low_threshold' => $lowThreshold, 'order_profit' => $orderProfit, 'income_estimated' => false, 'subsidy_pool' => $subsidyPool];
}

/**
 * 商标资料专员 / 提交专员按件计：每单补助（规则里的每件单价）× 商标个数。
 * 原表“商标个数”留空的行（如 10 元小额单）不计件，与部门核算表合计口径一致。
 */
function ps_trademark_piece_calc($calc, $count)
{
    if (!$calc || $calc['blocked'] || $calc['subsidy'] <= 0) return $calc;
    $unit = $calc['subsidy'];
    $pieces = $count === null ? 0.0 : (float)$count;
    $calc['subsidy'] = round($unit * $pieces, 2);
    $calc['subsidy_pool'] = round((float)($calc['subsidy_pool'] ?? $unit) * $pieces, 2);
    $calc['note'] .= $pieces > 0 ? '（每件 × 商标 ' . rtrim(rtrim(number_format($pieces, 2, '.', ''), '0'), '.') . ' 件 = ' . money_plain($calc['subsidy']) . '）' : '（未填商标个数，不计件）';
    return $calc;
}

function ps_summary($order, $costs, $participants)
{
    $order = ps_order_asof($order);
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
    // 预计分成：尚未录入任何收款时，收入按售价（扣退款）预估，便于财务提前看到大概金额；已核算的分成仍按实收。
    $estimatedByContract = (float)$order['receipt_amount'] <= 0 && $contract > 0;
    $estIncome = $estimatedByContract ? max(round($contract - (float)$order['refund_amount'], 2), 0.0) : $income;
    $estMark = function ($calc) use ($estimatedByContract) {
        if ($calc && $estimatedByContract) { $calc['note'] .= '〔收入按售价预估，尚未录入收款〕'; $calc['income_estimated'] = true; }
        return $calc;
    };
    $orderKind = (string)($order['order_kind'] ?? '');
    // 业务默认店铺服务费按售价计（网站模板/环境配置/小程序 3%），AI 定制默认不扣；分成规则可按组或岗位覆盖。
    $businessFeeRate = ps_business_service_fee_rate($order['project_type']);
    $feeBase = (float)$order['refund_amount'] > 0 ? max(round($contract - (float)$order['refund_amount'], 2), 0.0) : $contract; // 有退款时服务费按退款后的净额计
    $serviceFee = round($feeBase * $businessFeeRate, 2);
    $trademarkCount = null;
    if ($order['project_type'] === '商标') {
        static $orderDetailStmt = null;
        if ($orderDetailStmt === null) {
            $orderDetailStmt = db()->prepare('SELECT details_json FROM project_order_details WHERE order_id=?');
        }
        $orderDetailStmt->execute([(int)$order['id']]);
        $detailsRaw = $orderDetailStmt->fetchColumn();
        if ($detailsRaw) {
            $detailsJson = json_decode($detailsRaw, true);
            if (isset($detailsJson['trademark_count']) && is_numeric($detailsJson['trademark_count']) && (float)$detailsJson['trademark_count'] >= 0) {
                $trademarkCount = (float)$detailsJson['trademark_count'];
            }
        }
    }
    $groups = [];
    foreach (['technical', 'customer_service'] as $group) {
        $people = array_values(array_filter($participants, function ($p) use ($group) { return $p['commission_group'] === $group; }));
        $defaultRule = ps_rule_for($group, $order['project_type'], $order['order_date'], '', $orderKind);
        $weight = array_sum(array_map(function ($p) { return (float)$p['group_weight']; }, $people));
        $pool = 0.0; $estimatedPool = 0.0; $subsidy = 0.0; $missing = false; $subsidyRule = false;
        foreach ($people as $i => $person) {
            $rules = ps_rules_for_person($group, $order['project_type'], $order['order_date'], $person['role_name'] ?? '', $orderKind);
            $rule = $rules ? $rules[0] : null;
            $people[$i]['rule'] = $rule;
            $people[$i]['rules'] = $rules;
            if ($rule && (float)$rule['per_order_subsidy'] > 0) $subsidyRule = true; // 规则本身配置了每单补助（与限定名单是否实际发放无关）

            if (count($rules) > 1) {
                // 兼任多岗位（如前端/后端、外包前端/后端、模板技术/资料员）：按子岗位分别计算并汇总
                $subWeight = (float)$person['group_weight'] / count($rules);
                $subCalcs = [];
                $subCalcsEst = [];
                $subShareSum = 0.0;
                $subSubsidySum = 0.0;
                $subEstShareSum = 0.0;
                $subEstSubsidySum = 0.0;
                $feeSum = 0.0;
                $feePartSum = 0.0;
                $costBasisSum = 0.0;
                $baseSum = 0.0;
                $rateSum = 0.0;
                $subNotes = [];
                $subEstNotes = [];

                foreach ($rules as $mRule) {
                    $mRole = $mRule['role_name'] ?: '协作';
                    [$cNow, $nNow] = $personCost($group, $mRole, false);
                    [$cEst, $nEst] = $personCost($group, $mRole, true);
                    $sc = ps_calc_person($mRule, $income, $cNow, $contract, $subWeight, $businessFeeRate, $nNow, $feeBase);
                    $scEst = $estMark(ps_calc_person($mRule, $estIncome, $cEst, $contract, $subWeight, $businessFeeRate, $nEst, $feeBase));

                    $mSubsidyOnly = array_filter(array_map('intval', preg_split('/[^\d]+/', (string)($mRule['subsidy_employee_ids'] ?? ''))));
                    if ($mSubsidyOnly && !in_array((int)($person['employee_id'] ?? 0), $mSubsidyOnly, true)) {
                        $sc['subsidy'] = 0.0; $sc['subsidy_pool'] = 0.0; $sc['note'] = preg_replace('/\s*\+ 每单补助.*$/u', '', (string)$sc['note']);
                        $scEst['subsidy'] = 0.0; $scEst['subsidy_pool'] = 0.0; $scEst['note'] = preg_replace('/\s*\+ 每单补助.*$/u', '', (string)$scEst['note']);
                    }

                    $subCalcs[] = ['role' => $mRole, 'rule' => $mRule, 'calc' => $sc];
                    $subCalcsEst[] = ['role' => $mRole, 'rule' => $mRule, 'calc' => $scEst];

                    $subShareSum += $sc['share'];
                    $subSubsidySum += $sc['subsidy'];
                    $subEstShareSum += $scEst['share'];
                    $subEstSubsidySum += $scEst['subsidy'];

                    $feeSum += $sc['fee'];
                    $feePartSum += $sc['fee_part'];
                    $costBasisSum += $sc['cost_basis'];
                    $baseSum += $sc['base'];
                    $rateSum += (float)$mRule['rate'];

                    $subNotes[] = $mRole . '提成 ¥' . money_plain($sc['share']) . ' (' . round((float)$mRule['rate'] * 100, 2) . '%)';
                    $subEstNotes[] = $mRole . '预估 ¥' . money_plain($scEst['share']) . ' (' . round((float)$mRule['rate'] * 100, 2) . '%)';
                }

                $people[$i]['calc'] = array_merge($subCalcs[0]['calc'], [
                    'mode' => 'individual',
                    'fee' => round($feeSum, 2),
                    'fee_part' => round($feePartSum, 2),
                    'cost_basis' => round($costBasisSum, 2),
                    'base' => round($baseSum, 2),
                    'rate' => $rateSum,
                    'share' => round($subShareSum, 2),
                    'subsidy' => round($subSubsidySum, 2),
                    'blocked' => false,
                    'note' => implode(' + ', $subNotes) . '：合计提成 ¥' . money_plain($subShareSum) . ($subSubsidySum > 0 ? '（含补助 ¥' . money_plain($subSubsidySum) . '）' : ''),
                    'sub_calcs' => $subCalcs
                ]);

                $people[$i]['estimated_calc'] = array_merge($subCalcsEst[0]['calc'], [
                    'mode' => 'individual',
                    'fee' => round($feeSum, 2),
                    'fee_part' => round($feePartSum, 2),
                    'cost_basis' => round($costBasisSum, 2),
                    'base' => round($baseSum, 2),
                    'rate' => $rateSum,
                    'share' => round($subEstShareSum, 2),
                    'subsidy' => round($subEstSubsidySum, 2),
                    'blocked' => false,
                    'note' => implode(' + ', $subEstNotes) . '：合计预估 ¥' . money_plain($subEstShareSum) . ($subEstSubsidySum > 0 ? '（含补助 ¥' . money_plain($subEstSubsidySum) . '）' : ''),
                    'sub_calcs' => $subCalcsEst
                ]);
            } else {
                [$costNow, $noteNow] = $personCost($group, $person['role_name'] ?? '', false);
                [$costEst, $noteEst] = $personCost($group, $person['role_name'] ?? '', true);
                $people[$i]['calc'] = $rule ? ps_calc_person($rule, $income, $costNow, $contract, $person['group_weight'], $businessFeeRate, $noteNow, $feeBase) : null;
                $people[$i]['estimated_calc'] = $rule ? $estMark(ps_calc_person($rule, $estIncome, $costEst, $contract, $person['group_weight'], $businessFeeRate, $noteEst, $feeBase)) : null;

                // 规则限定“每单补助只发给指定员工”（subsidy_employee_ids，逗号分隔，留空 = 所有参与人）：不在名单内则取消补助。
                $subsidyOnly = array_filter(array_map('intval', preg_split('/[^\d]+/', (string)($rule['subsidy_employee_ids'] ?? ''))));
                if ($rule && $subsidyOnly && !in_array((int)($person['employee_id'] ?? 0), $subsidyOnly, true)) {
                    foreach (['calc', 'estimated_calc'] as $ck) {
                        if (!$people[$i][$ck]) continue;
                        $people[$i][$ck]['subsidy'] = 0.0;
                        $people[$i][$ck]['subsidy_pool'] = 0.0;
                        $people[$i][$ck]['note'] = preg_replace('/\s*\+ 每单补助.*$/u', '', (string)$people[$i][$ck]['note']);
                    }
                }
            }
            if ($order['project_type'] === '商标' && $group === 'technical') {
                $people[$i]['calc'] = ps_trademark_piece_calc($people[$i]['calc'], $trademarkCount);
                $people[$i]['estimated_calc'] = ps_trademark_piece_calc($people[$i]['estimated_calc'], $trademarkCount);
            }
            if (!$rule) { $missing = true; continue; }
            $pool += $people[$i]['calc']['share'];
            $estimatedPool += $people[$i]['estimated_calc']['share'];
            $subsidy += $people[$i]['calc']['subsidy'];
        }
        // 补助也是组池的一部分；用分为单位分摊尾差，人数增加不能重复发整单补助。
        foreach (['calc', 'estimated_calc'] as $ck) {
            $subsidyCents = ps_group_subsidy_cents($people, $ck);
            $forAllocation = array_map(function ($p) use ($ck) { $p['calc'] = $p[$ck]; return $p; }, $people);
            $shareCents = ps_group_share_cents($forAllocation);
            foreach ($people as $i => $person) if ($people[$i][$ck]) {
                $people[$i][$ck]['share_exact'] = $people[$i][$ck]['share'];
                $people[$i][$ck]['share'] = $shareCents[$i] / 100;
                $people[$i][$ck]['subsidy'] = $subsidyCents[$i] / 100;
            }
        }
        $pool = array_sum(array_map(function ($p) { return (float)($p['calc']['share'] ?? 0); }, $people));
        $estimatedPool = array_sum(array_map(function ($p) { return (float)($p['estimated_calc']['share'] ?? 0); }, $people));
        $subsidy = array_sum(array_map(function ($p) { return (float)($p['calc']['subsidy'] ?? 0); }, $people));
        if (!$people) {
            // 无参与人时仍显示该组按默认规则可形成的分成池，供财务预估。
            [$costNow, $noteNow] = $personCost($group, '', false);
            [$costEst, $noteEst] = $personCost($group, '', true);
            $calc = $defaultRule ? ps_calc_person($defaultRule, $income, $costNow, $contract, 1, $businessFeeRate, $noteNow, $feeBase) : null;
            $estimated = $defaultRule ? $estMark(ps_calc_person($defaultRule, $estIncome, $costEst, $contract, 1, $businessFeeRate, $noteEst, $feeBase)) : null;
            if ($order['project_type'] === '商标' && $group === 'technical') {
                $calc = ps_trademark_piece_calc($calc, $trademarkCount);
                $estimated = ps_trademark_piece_calc($estimated, $trademarkCount);
            }
            $pool = $calc ? $calc['share'] : null;
            $estimatedPool = $estimated ? $estimated['share'] : null;
        }
        $rule = $missing ? null : ($defaultRule ?: ($people[0]['rule'] ?? null));
        if ($defaultRule && (float)$defaultRule['per_order_subsidy'] > 0) $subsidyRule = true;
        $groups[$group] = ['people' => $people, 'rule' => $rule, 'weight' => $weight, 'rate' => $rule ? (float)$rule['rate'] : null, 'missing_rule' => $missing, 'subsidy' => round($subsidy, 2), 'subsidy_rule' => $subsidyRule ? 1 : 0,
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
        // 计提基数和比例相同的组池整体分摊；岗位别名命中不同但等价的规则时也只计一个池。
        if ($person['calc']['mode'] === 'pool' && !$person['calc']['blocked']) $poolGroups[$person['calc']['rate'] . '|' . (int)!empty($person['rule']['allow_negative']) . '|' . money_plain($person['calc']['base'])][$i] = $person;
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

/** 同一组池规则的按单补助仅发一池，独立岗位规则保持原口径。 */
function ps_group_subsidy_cents($people, $calcKey = 'calc')
{
    $cents = []; $pools = [];
    foreach ($people as $i => $p) {
        $c = $p[$calcKey] ?? null;
        $cents[$i] = (int)round((float)($c['subsidy'] ?? 0) * 100);
        if (!$c || $c['mode'] !== 'pool' || $c['blocked'] || empty($c['subsidy_pool'])) continue;
        $pools[money_plain($c['subsidy_pool'])][$i] = $p;
    }
    foreach ($pools as $members) {
        $first = reset($members); $weight = array_sum(array_column($members, 'group_weight'));
        $total = (int)round($first[$calcKey]['subsidy_pool'] * $weight * 100);
        $normalized = array_map(function ($p) use ($weight) { return ['group_weight' => $weight > 0 ? $p['group_weight'] / $weight : 0]; }, array_values($members));
        $shares = ps_allocate_pool_cents($total, $normalized);
        foreach (array_keys($members) as $n => $i) $cents[$i] = $shares[$n];
    }
    return $cents;
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

/*
 * 私有文件（付款凭证、原始上传表格）：存放在站点目录内 storage/private/<类别>/。
 * 线上 PHP 的 open_basedir 只允许站点目录和 /tmp，不能写到站点目录外；为防止被直接下载，
 * 每个文件都存成 .php、以“返回 404 并退出”的 PHP 代码开头——即使路径被猜到，Web 服务器也只会执行它返回 404。
 */
const PS_PRIVATE_GUARD = "<?php http_response_code(404); exit; ?>\n"; // 用转义写，不受源码换行符（LF / CRLF）影响

function ps_private_dir($kind)
{
    $dir = dirname(__DIR__) . '/storage/private/' . preg_replace('/[^a-z_]/', '', $kind);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('无法创建私有文件目录，请联系管理员检查 storage 目录权限');
    if (!is_file($dir . '/index.php')) @file_put_contents($dir . '/index.php', PS_PRIVATE_GUARD);
    return $dir;
}

/** 保存私有文件，返回存储名（不含 .php 后缀）。 */
function ps_private_store($kind, $sourcePath, $name)
{
    $data = @file_get_contents($sourcePath);
    if ($data === false) throw new RuntimeException('上传文件读取失败，请重新上传');
    $name = basename($name);
    if (@file_put_contents(ps_private_dir($kind) . '/' . $name . '.php', PS_PRIVATE_GUARD . $data, LOCK_EX) === false) throw new RuntimeException('文件保存失败，请联系管理员检查 storage 目录权限');
    return $name;
}

/** 读取私有文件内容；不存在时返回 null。 */
function ps_private_read($kind, $name)
{
    $path = ps_private_dir($kind) . '/' . basename($name) . '.php';
    if (!is_file($path)) return null;
    $data = file_get_contents($path);
    // 兼容早期以 CRLF 源码写入的防护头（末尾多一个回车符），否则这些文件会被误判为“已不存在”
    return preg_match('/^<\?php http_response_code\(404\); exit; \?>\r?\n/', $data, $m) ? substr($data, strlen($m[0])) : null;
}

/** 删除私有文件；文件不存在时静默跳过。 */
function ps_private_delete($kind, $name)
{
    $name = (string)$name;
    if ($name === '' || strpos($name, '/') !== false) return;
    $path = ps_private_dir($kind) . '/' . basename($name) . '.php';
    if (is_file($path)) @unlink($path);
}

/** 私有文件复制到临时文件（供需要真实路径的解析器使用，如 xlsx 的 zip 读取），调用方负责删除。 */
function ps_private_temp_copy($kind, $name)
{
    $data = ps_private_read($kind, $name);
    if ($data === null) return null;
    $temp = tempnam(sys_get_temp_dir(), 'ps_');
    file_put_contents($temp, $data);
    return $temp;
}

/**
 * 按文件头识别图片 / PDF 类型；线上 PHP 7.4 未装 fileinfo 扩展，不能依赖 finfo。
 * 只识别 JPEG、PNG、WebP、PDF，其余返回 application/octet-stream。
 */
function ps_detect_mime($data)
{
    $head = substr((string)$data, 0, 16);
    if (strncmp($head, "\x89PNG\r\n\x1a\n", 8) === 0) return 'image/png';
    if (strncmp($head, "\xFF\xD8\xFF", 3) === 0) return 'image/jpeg';
    if (strncmp($head, 'RIFF', 4) === 0 && substr($head, 8, 4) === 'WEBP') return 'image/webp';
    if (strncmp($head, '%PDF-', 5) === 0) return 'application/pdf';
    return 'application/octet-stream';
}

function ps_detect_file_mime($path)
{
    $fp = @fopen($path, 'rb');
    if (!$fp) return 'application/octet-stream';
    $head = (string)fread($fp, 16);
    fclose($fp);
    return ps_detect_mime($head);
}

function ps_upload_proof($field)
{
    if (empty($_FILES[$field]['name']) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('请上传付款凭证');
    if ($_FILES[$field]['size'] > 5 * 1024 * 1024) throw new RuntimeException('凭证不能超过 5MB');
    $mime = ps_detect_file_mime($_FILES[$field]['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'][$mime] ?? null;
    if (!$ext) throw new RuntimeException('凭证仅支持 JPG、PNG 或 PDF');
    if (!is_uploaded_file($_FILES[$field]['tmp_name'])) throw new RuntimeException('凭证上传无效，请重新上传');
    return ps_private_store('proofs', $_FILES[$field]['tmp_name'], bin2hex(random_bytes(16)) . '.' . $ext);
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
        require_once __DIR__ . '/ProjectOrderItems.php';
        foreach (poi_items($orderId) as $item) if (!$item['cost_id'] || $item['cost_status'] !== 'approved') throw new RuntimeException('商品“' . $item['item_name'] . '”成本未完整核对，不能生成项目分成');
        $sslExpected = (float)($resource['ssl_expected_amount'] ?? 0);
        if ($sslExpected > 0) {
            $sslApprovedCents = 0;
            foreach ($costs as $cost) if ($cost['category'] === 'certificate' && $cost['review_status'] === 'approved') $sslApprovedCents += (int)round((float)$cost['amount'] * 100);
            if ($sslApprovedCents < (int)round($sslExpected * 100)) throw new RuntimeException('SSL 报备成本尚未按凭证补录并审核，不能生成项目分成');
        }
        if ($order['delivery_status'] !== 'finished') throw new RuntimeException('项目尚未完成');
        if (!empty(ps_business_catalog()[ps_business_normalize($order['project_type'])]['kind_required']) && trim((string)($order['order_kind'] ?? '')) === '') throw new RuntimeException('请先选择订单类型（新订单 / 定制 / 续费），它决定分成比例和每单补助');
        // 补助规则存在但被“补助限定员工”取消发放时仍视为可结算：网站续费等部门单实收可为 0（无流水单），靠补助规则维持可审核。
        $hasSubsidy = $sum['groups']['technical']['subsidy'] > 0 || $sum['groups']['customer_service']['subsidy'] > 0
            || !empty($sum['groups']['technical']['subsidy_rule']) || !empty($sum['groups']['customer_service']['subsidy_rule']);
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
                $insert->execute([$orderId, $person['employee_id'], $label, mb_substr((string)$person['role_name'], 0, 80), $sum['income'], $calc['cost_basis'], $calc['fee'], $calc['base'], $person['rule']['id'], $calc['mode'], $calc['rate'], $person['group_weight'], ($shares[$i] + $subsidyCents) / 100, round(($calc['share_exact'] ?? $calc['share']) + $calc['subsidy'], 6), $subsidyCents / 100, mb_substr($calc['note'], 0, 500), $payrollMonth]);
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
    if (in_array(ps_business_normalize($row['project_type'] ?? ''), ['AI网站定制', '网站定制'], true) && isset($row['backend_tech_count']) && (int)$row['backend_tech_count'] === 0 && (int)($row['tech_count'] ?? 0) > 0) {
        $todos[] = ['待指定后端', 'warning'];
    }
    if ((int)($row['pending_costs'] ?? 0) > 0) $todos[] = ['成本待审 ' . (int)$row['pending_costs'], 'info'];
    if ((int)($row['pending_cash'] ?? 0) > 0) $todos[] = ['收退款待审 ' . (int)$row['pending_cash'], 'info'];
    if ((int)($row['pending_delivery_requests'] ?? 0) > 0) $todos[] = ['交付待审', 'warning'];
    if ((int)($row['pending_upgrade_requests'] ?? 0) > 0) $todos[] = ['升级待审', 'primary'];
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

/** 财务纠正已审核订单类型：保留原快照，差额计入指定未锁定月份。 */
function ps_reclassify_order_kind($orderId, $kind, $actor, $payrollMonth, $applyFuture = false)
{
    if (($actor['role'] ?? '') !== 'finance') throw new RuntimeException('仅财务可纠正订单类型');
    $pdo = db(); $nested = $pdo->inTransaction();
    if ($nested) $pdo->exec('SAVEPOINT project_reclassify_kind'); else $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT * FROM project_orders WHERE id=? FOR UPDATE');
        $q->execute([(int)$orderId]); $order = $q->fetch();
        if (!$order) throw new RuntimeException('订单不存在');
        $kind = ps_order_kind_valid(ps_business_normalize($order['project_type']), $kind);
        if ($kind === '') throw new RuntimeException('请选择正确订单类型');
        $before = (string)$order['order_kind'];
        if ($before === $kind) throw new RuntimeException('订单类型没有变化');
        $approved = in_array($order['settlement_status'], ['approved', 'locked'], true);
        if ($approved) {
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$payrollMonth)) throw new RuntimeException('请选择调整计入月份');
            $pdo->prepare('INSERT INTO project_payroll_periods (period) VALUES (?) ON DUPLICATE KEY UPDATE period=period')->execute([$payrollMonth]);
            $period = $pdo->prepare('SELECT status FROM project_payroll_periods WHERE period=? FOR UPDATE');
            $period->execute([$payrollMonth]);
            if ($period->fetchColumn() === 'locked') throw new RuntimeException($payrollMonth . ' 已锁定，请选择未锁定月份');
            $order['order_kind'] = $kind;
            $summary = ps_summary($order, ps_costs($orderId), ps_participants($orderId));
            $snap = $pdo->prepare('SELECT employee_id,commission_group,SUM(commission_amount) amount FROM project_commission_snapshots WHERE order_id=? GROUP BY employee_id,commission_group');
            $snap->execute([(int)$orderId]); $old = [];
            foreach ($snap->fetchAll() as $s) $old[$s['commission_group'] . ':' . $s['employee_id']] = (float)$s['amount'];
            if (!$old) throw new RuntimeException('原审核快照不存在，不能自动重算，请财务核对');
            $prior = $pdo->prepare('SELECT commission_group,employee_id,SUM(amount) amount FROM project_commission_adjustments WHERE order_id=? GROUP BY commission_group,employee_id');
            $prior->execute([(int)$orderId]);
            foreach ($prior->fetchAll() as $p) $old[$p['commission_group'] . ':' . $p['employee_id']] = ($old[$p['commission_group'] . ':' . $p['employee_id']] ?? 0) + (float)$p['amount'];
            $ins = $pdo->prepare('INSERT INTO project_commission_adjustments (order_id,employee_id,commission_group,amount,payroll_month,reason,calc_note,created_by_admin) VALUES (?,?,?,?,?,?,?,?)');
            $reason = '财务纠正订单类型：' . ($before ?: '未分类') . ' → ' . $kind;
            foreach ($summary['groups'] as $group => $data) {
                if (!$data['people']) continue;
                if ($data['missing_rule'] || abs($data['weight'] - 1.0) > 0.000001) throw new RuntimeException('新类型的分成规则或参与人权重未配置完整，不能自动重算');
                $shares = ps_group_share_cents($data['people']);
                foreach ($data['people'] as $i => $person) {
                    $key = $group . ':' . $person['employee_id'];
                    $newCents = $summary['income'] <= 0 ? 0 : $shares[$i] + (int)round($person['calc']['subsidy'] * 100);
                    $oldCents = (int)round(($old[$key] ?? 0) * 100);
                    $delta = $newCents - $oldCents;
                    if ($delta) $ins->execute([(int)$orderId, $person['employee_id'], $group, $delta / 100, $payrollMonth, $reason, '按“' . $kind . '”重算 ¥' . money_plain($newCents / 100) . '，已计 ¥' . money_plain($oldCents / 100), $actor['id']]);
                    unset($old[$key]);
                }
            }
            if ($old) throw new RuntimeException('原快照参与人与当前不一致，请财务核对后再调整');
        }
        $pdo->prepare('UPDATE project_orders SET order_kind=?,row_version=row_version+1 WHERE id=?')->execute([$kind, (int)$orderId]);
        if ($applyFuture) {
            $people = $pdo->prepare('SELECT DISTINCT employee_id FROM project_participants WHERE order_id=?');
            $people->execute([(int)$orderId]);
            $default = $pdo->prepare("INSERT INTO project_import_kind_preferences (employee_id,business_name,layout_signature,order_kind,source) VALUES (?,?,'*',?,'finance') ON DUPLICATE KEY UPDATE order_kind=VALUES(order_kind),source='finance',confirmed_count=confirmed_count+1,updated_at=NOW()");
            foreach ($people->fetchAll(PDO::FETCH_COLUMN) as $employeeId) $default->execute([(int)$employeeId, ps_business_normalize($order['project_type']), $kind]);
        }
        ps_audit('order', (int)$orderId, 'reclassify_kind', $actor, ['before' => $before, 'after' => $kind, 'month' => $approved ? $payrollMonth : null, 'future_default' => (bool)$applyFuture]);
        if ($nested) $pdo->exec('RELEASE SAVEPOINT project_reclassify_kind'); else $pdo->commit();
    } catch (Throwable $e) {
        if ($nested) $pdo->exec('ROLLBACK TO SAVEPOINT project_reclassify_kind');
        elseif ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/* ---------- 订单交付凭证申请与产品升级补差申请 ---------- */

function ps_order_requests($orderId)
{
    $sql = "SELECT r.*, a.username AS reviewer_username,
            COALESCE(e.name, a.username) AS reviewer_name
            FROM project_order_requests r
            LEFT JOIN admins a ON a.id=r.reviewer_id
            LEFT JOIN employees e ON (a.username='songwenna' AND e.name='宋文娜')
                                  OR (a.username='liuqun' AND e.name='刘群')
                                  OR (a.username='sunman' AND e.name='孙曼')
                                  OR (a.username='yaolin' AND e.name='姚琳')
                                  OR (a.username='wangfang' AND e.name='王芳')
                                  OR (a.username='weihuizi' AND e.name='魏慧子')
                                  OR (a.username='wangguimei' AND e.name='王桂美')
            WHERE r.order_id=? ORDER BY r.id DESC";
    $q = db()->prepare($sql);
    $q->execute([(int)$orderId]);
    $list = $q->fetchAll();
    foreach ($list as $i => $row) {
        $list[$i]['data'] = json_decode((string)$row['data_json'], true) ?: [];
    }
    return $list;
}

function ps_order_pending_request($orderId, $type = null)
{
    if ($type) {
        $q = db()->prepare("SELECT * FROM project_order_requests WHERE order_id=? AND request_type=? AND status='pending' ORDER BY id DESC LIMIT 1");
        $q->execute([(int)$orderId, $type]);
    } else {
        $q = db()->prepare("SELECT * FROM project_order_requests WHERE order_id=? AND status='pending' ORDER BY id DESC LIMIT 1");
        $q->execute([(int)$orderId]);
    }
    $row = $q->fetch();
    if ($row) {
        $row['data'] = json_decode((string)$row['data_json'], true) ?: [];
        return $row;
    }
    return null;
}

function ps_create_order_request($orderId, $type, $actor, array $data)
{
    if (!in_array($type, ['delivery_completion', 'product_upgrade'], true)) {
        throw new RuntimeException('申请类型无效');
    }
    $existing = ps_order_pending_request($orderId, $type);
    if ($existing) {
        throw new RuntimeException('该订单已有待审核的同类申请，请勿重复提交');
    }

    $applicantType = $actor['type'];
    $applicantId = (int)($actor['employee_id'] ?? $actor['id']);
    $applicantName = (string)($actor['username'] ?? '');
    if (!empty($actor['employee_id'])) {
        $nameQuery = db()->prepare('SELECT name FROM employees WHERE id=?');
        $nameQuery->execute([(int)$actor['employee_id']]);
        $applicantName = (string)($nameQuery->fetchColumn() ?: $applicantName);
    }

    $q = db()->prepare("INSERT INTO project_order_requests (order_id, request_type, status, applicant_type, applicant_id, applicant_name, data_json) VALUES (?, ?, 'pending', ?, ?, ?, ?)");
    $q->execute([
        (int)$orderId,
        $type,
        $applicantType,
        $applicantId,
        $applicantName,
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    ]);
    $requestId = (int)db()->lastInsertId();
    ps_audit('order', (int)$orderId, 'apply_' . $type, $actor, ['request_id' => $requestId] + $data);
    return $requestId;
}

function ps_review_order_request($requestId, $decision, $actor, $reviewNote, array $extraData = [])
{
    if (($actor['role'] ?? '') !== 'finance') throw new RuntimeException('仅财务或审核人可审核');
    if (!in_array($decision, ['approved', 'rejected'], true)) throw new RuntimeException('审核决定无效');

    $pdo = db();
    $nested = $pdo->inTransaction();
    if ($nested) $pdo->exec('SAVEPOINT project_review_order_req');
    else $pdo->beginTransaction();

    try {
        $q = $pdo->prepare('SELECT * FROM project_order_requests WHERE id=? FOR UPDATE');
        $q->execute([(int)$requestId]);
        $req = $q->fetch();
        if (!$req || $req['status'] !== 'pending') throw new RuntimeException('申请不存在或已被处理');

        $orderId = (int)$req['order_id'];
        $orderQuery = $pdo->prepare('SELECT * FROM project_orders WHERE id=? FOR UPDATE');
        $orderQuery->execute([$orderId]);
        $order = $orderQuery->fetch();
        if (!$order) throw new RuntimeException('对应订单不存在');

        $reqData = json_decode((string)$req['data_json'], true) ?: [];

        if ($decision === 'approved') {
            if ($req['request_type'] === 'delivery_completion') {
                $pdo->prepare("UPDATE project_orders SET delivery_status='finished', row_version=row_version+1 WHERE id=?")->execute([$orderId]);
                ps_audit('order', $orderId, 'approve_delivery_completion', $actor, ['request_id' => $requestId, 'note' => $reviewNote]);
            } elseif ($req['request_type'] === 'product_upgrade') {
                $diffAmount = isset($extraData['diff_amount']) ? round((float)$extraData['diff_amount'], 2) : 0.0;
                if ($diffAmount < 0) throw new RuntimeException('补差金额不能为负数');

                $toTemplateId = (int)($reqData['to_template_id'] ?? 0);
                $fromName = (string)($reqData['from_name'] ?? '原程序');
                $toName = (string)($reqData['to_name'] ?? '新程序');

                $costName = '产品升级补差成本（' . $fromName . ' → ' . $toName . '）';
                $costReason = '后台查验实付补差成本' . ($reviewNote ? '：' . $reviewNote : '');
                $proofPath = !empty($extraData['proof_path']) ? (string)$extraData['proof_path'] : null;

                $costStmt = $pdo->prepare("INSERT INTO project_costs (order_id, template_id, template_version, category, item_name, quantity, unit, unit_price, amount, supplier_amount, cost_kind, is_custom, reason, proof_path, review_status, submitted_by_employee, reviewed_by_admin, review_note) VALUES (?, ?, 1, 'program', ?, 1, '项', ?, ?, ?, 'one_time', 1, ?, ?, 'approved', ?, ?, ?)");
                $costStmt->execute([
                    $orderId,
                    $toTemplateId ?: null,
                    $costName,
                    $diffAmount,
                    $diffAmount,
                    $diffAmount,
                    $costReason,
                    $proofPath,
                    $actor['employee_id'] ?? null,
                    $actor['id'],
                    '产品升级自动审核入账'
                ]);

                if ($toTemplateId > 0) {
                    $pdo->prepare("UPDATE project_order_resources SET program_template_id=? WHERE order_id=?")->execute([$toTemplateId, $orderId]);
                }

                ps_audit('order', $orderId, 'approve_product_upgrade', $actor, [
                    'request_id' => $requestId,
                    'diff_amount' => $diffAmount,
                    'from_name' => $fromName,
                    'to_name' => $toName,
                    'note' => $reviewNote
                ]);
            }
        } else {
            ps_audit('order', $orderId, 'reject_' . $req['request_type'], $actor, ['request_id' => $requestId, 'note' => $reviewNote]);
        }

        $upd = $pdo->prepare("UPDATE project_order_requests SET status=?, reviewer_id=?, reviewed_at=NOW(), review_note=? WHERE id=?");
        $upd->execute([$decision, $actor['id'], $reviewNote, (int)$requestId]);

        if ($nested) $pdo->exec('RELEASE SAVEPOINT project_review_order_req');
        else $pdo->commit();

        return true;
    } catch (Throwable $e) {
        if ($nested) $pdo->exec('ROLLBACK TO SAVEPOINT project_review_order_req');
        elseif ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * 旧任务兼容入口：改用证据自动核对，绝不按天数推断交付或按售价登记实收。
 * @param int|null $days 保留旧调用签名，不再作为审核依据
 * @return array ['finished' => int, 'approved' => int, 'orders' => array]
 */
function ps_auto_finish_trade_success_orders($days = null)
{
    // Compatibility for existing jobs. Age or sale amount is never payment/delivery evidence.
    require_once __DIR__ . '/ProjectAutoReview.php';
    if (!pa_storage_available() || !ps_setting_get('auto_review_enabled', false)) return ['finished' => 0, 'approved' => 0, 'orders' => []];
    $result = pa_batch(200, true, true);
    return ['finished' => 0, 'approved' => $result['applied'], 'orders' => [], 'review' => $result];
}

/** 退款发生在订单所属月份之后的金额（按退款生效月份判断）；这部分在退款月补扣，不进入订单所属月份的分成。 */
function ps_refund_later($orderId, $orderDate)
{
    static $q = null;
    try {
        if ($q === null) $q = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM project_cash_movements WHERE order_id=? AND movement_type='refund' AND review_status='approved' AND effective_month IS NOT NULL AND effective_month>?");
        $q->execute([(int)$orderId, substr((string)$orderDate, 0, 7)]);
        return round((float)$q->fetchColumn(), 2);
    } catch (Throwable $e) {
        return 0.0; // 字段尚未迁移时按原口径
    }
}

/** 订单“所属月份当时”的口径：去掉后月退款。$order['_all_refunds'] 为真时保持全部退款（补扣计算用）。 */
function ps_order_asof($order)
{
    if (!empty($order['_all_refunds']) || empty($order['id']) || (float)($order['refund_amount'] ?? 0) <= 0) return $order;
    $later = ps_refund_later($order['id'], $order['order_date'] ?? '');
    if ($later > 0) $order['refund_amount'] = max(round((float)$order['refund_amount'] - $later, 2), 0.0);
    return $order;
}

/**
 * 为订单分配或更新后端技术人员。
 * 支持：
 * - 'colleague': 指定一名后端技术同事（双方权重各 50%）
 * - 'self_fullstack': 技术人员本人兼任前后端（角色更新为 前端/后端，权重 100%）
 */
function ps_order_assign_backend($orderId, $backendEmployeeId, $mode, $actor)
{
    $pdo = db();
    $order = ps_order($orderId, $actor);
    if (in_array($order['settlement_status'], ['approved', 'locked'], true)) {
        throw new RuntimeException('订单已审核，修改须走调整流程');
    }
    $isFinance = $actor['role'] === 'finance';
    $isCS = $actor['role'] === 'customer_service';
    $isTech = $actor['role'] === 'technical';
    $actorEid = (int)($actor['employee_id'] ?? 0);

    if (!$isFinance && !$isCS) {
        $chk = $pdo->prepare('SELECT 1 FROM project_participants WHERE order_id=? AND employee_id=? LIMIT 1');
        $chk->execute([(int)$orderId, $actorEid]);
        $isParticipant = (bool)$chk->fetchColumn();
        if (!$isParticipant && $mode !== 'colleague') {
            throw new RuntimeException('您未参与此订单，无权分配后端技术');
        }
    }

    if ($mode === 'self_fullstack') {
        $targetTechId = $isTech ? $actorEid : (int)$backendEmployeeId;
        if (!$targetTechId) throw new RuntimeException('未指定技术人员');
        $qRole = $pdo->prepare('SELECT id, role_name FROM project_participants WHERE order_id=? AND employee_id=? AND commission_group="technical" LIMIT 1');
        $qRole->execute([(int)$orderId, $targetTechId]);
        $row = $qRole->fetch();
        if (!$row) throw new RuntimeException('该员工尚未在技术组，请先添加');
        $currRole = (string)$row['role_name'];
        $isOutsourced = mb_strpos($currRole, '外包') !== false;
        $newRole = $isOutsourced ? '外包前端/后端' : '前端/后端';
        
        $pdo->prepare('UPDATE project_participants SET role_name=?, group_weight=1.0 WHERE id=?')
            ->execute([$newRole, (int)$row['id']]);
        
        $pdo->prepare('DELETE FROM project_participants WHERE order_id=? AND commission_group="technical" AND employee_id<>?')
            ->execute([(int)$orderId, $targetTechId]);
        
        ps_audit('order', (int)$orderId, 'assign_backend_fullstack', $actor, ['employee_id' => $targetTechId, 'role' => $newRole]);
        return ['ok' => true, 'mode' => 'self_fullstack', 'role' => $newRole];
    } else {
        $backendId = (int)$backendEmployeeId;
        if (!$backendId) throw new RuntimeException('请选择后端技术人员');
        if (!ps_active_employee_for_business($backendId, 'technical', ps_business_normalize($order['project_type']))) {
            throw new RuntimeException('所选后端技术人员未开通此业务的有效账号');
        }
        $techs = $pdo->prepare('SELECT id, employee_id, role_name FROM project_participants WHERE order_id=? AND commission_group="technical"');
        $techs->execute([(int)$orderId]);
        $existing = $techs->fetchAll();

        foreach ($existing as $t) {
            if ((int)$t['employee_id'] !== $backendId) {
                $fRole = $t['role_name'];
                if (mb_strpos($fRole, '后端') !== false) {
                    $fRole = (mb_strpos($fRole, '外包') !== false) ? '外包前端' : '前端';
                }
                $pdo->prepare('UPDATE project_participants SET role_name=?, group_weight=0.5 WHERE id=?')
                    ->execute([$fRole, (int)$t['id']]);
            }
        }
        $pdo->prepare('INSERT INTO project_participants (order_id, employee_id, commission_group, role_name, group_weight) VALUES (?, ?, "technical", "后端", 0.5) ON DUPLICATE KEY UPDATE role_name="后端", group_weight=0.5')
            ->execute([(int)$orderId, $backendId]);

        ps_audit('order', (int)$orderId, 'assign_backend_colleague', $actor, ['backend_id' => $backendId]);
        return ['ok' => true, 'mode' => 'colleague', 'backend_id' => $backendId];
    }
}
