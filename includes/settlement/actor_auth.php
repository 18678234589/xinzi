<?php
require_once __DIR__ . '/../ProjectAccountRoles.php';
require_once __DIR__ . '/../AuthStaff.php';

function ps_actor()
{
    if (isset($_SESSION['admin_id'])) return ['type' => 'admin', 'id' => (int)$_SESSION['admin_id'], 'employee_id' => null, 'role' => 'finance'];
    if (!isset($_SESSION['project_user_id'])) return null;
    return ps_project_actor((int)$_SESSION['project_user_id']);
}

function ps_project_actor($id)
{
    $q = db()->prepare('SELECT * FROM project_users WHERE id=? AND is_active=1');
    $q->execute([(int)$id]);
    $user = $q->fetch();
    $management = $user && $user['role'] === 'management' ? ps_management_profile($user['id']) : [];
    return $user ? ['type' => 'employee', 'id' => (int)$user['id'], 'employee_id' => (int)$user['employee_id'], 'role' => ps_account_order_role($user['role']), 'account_role' => $user['role'], 'management_scope' => $management['scope'] ?? null, 'management_title' => $management['title'] ?? null, 'username' => $user['username'], 'phone' =>
    $user['phone'] ?? null, 'password_changed_at' => $user['password_changed_at'] ?? null] : null;
}

/** Server-authenticated delegation; never creates or modifies a browser session. */
function ps_actor_from_subject($subject)
{
    if (!preg_match('/^(admin|project):([1-9][0-9]{0,17})$/D', (string)$subject, $m)) return null;
    if ($m[1] === 'project') return ps_project_actor((int)$m[2]);
    $q = db()->prepare('SELECT id FROM admins WHERE id=?');
    $q->execute([(int)$m[2]]);
    return $q->fetchColumn() ? ['type'=>'admin', 'id'=>(int)$m[2], 'employee_id'=>null, 'role'=>'finance'] : null;
}

/** 管理层账号是否被财务分配了业务或授权审核业务（有业务才开放订单入口）。 */
function ps_governance_has_business($actor)
{
    static $cache = [];
    $id = (int)($actor['id'] ?? 0);
    $empId = (int)($actor['employee_id'] ?? 0);
    if (!$id && !$empId) return false;
    $cacheKey = $id . ':' . $empId;
    if (!isset($cache[$cacheKey])) {
        try {
            $q = db()->prepare('SELECT 1 FROM project_user_businesses WHERE user_id=? LIMIT 1');
            $q->execute([$id]);
            $has = (bool)$q->fetchColumn();
            if (!$has && $empId > 0) {
                $q2 = db()->prepare('SELECT 1 FROM project_batch_review_delegates WHERE employee_id=? LIMIT 1');
                $q2->execute([$empId]);
                $has = (bool)$q2->fetchColumn();
            }
            $cache[$cacheKey] = $has;
        }
        catch (Throwable $e) { $cache[$cacheKey] = false; }
    }
    return $cache[$cacheKey];
}

function ps_require_actor()
{
    $actor = ps_actor();
    if (!$actor) { header('Location: ' . BASE_URL . '/login.php'); exit; }
    // Known initial passwords must be changed before any business page or API can be used.
    if ($actor['type'] === 'employee' && auth_staff_needs_password_change($actor) && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'profile.php' && PHP_SAPI !== 'cli') {
        header('Location: ' . BASE_URL . auth_staff_destination($actor)); exit;
    }
    if ($actor['type'] === 'employee' && $actor['role'] === 'governance') {
        $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
        $allowed = ['profile.php', 'dashboard.php', 'governance.php', 'governance_ideas.php', 'governance_election.php', 'governance_rules.php', 'governance_evidence.php', 'payroll.php', 'welfare.php'
    , 'contributions.php', 'messages.php', 'holidays.php', 'vault.php', 'domains.php', 'dns.php', 'cdn.php', 'knowledge.php', 'knowledge_article.php', 'knowledge_links.php', 'knowledge_categories.php', 'knowledge_rules.php'
    , 'knowledge_keywords.php', 'knowledge_integrations.php', 'knowledge_skills.php', 'knowledge_skill.php', 'knowledge_skill_import.php', 'knowledge_skills_export.php', 'knowledge_costs.php'
    ];
        if ($script === 'rules.php' && ($_GET['domain'] ?? $_POST['domain'] ?? '') === 'governance') $allowed[] = 'rules.php';
        if ($script === 'rules.php' && ($_GET['domain'] ?? $_POST['domain'] ?? '') === 'welfare') $allowed[] = 'rules.php';
        // 被分配了业务或授权审核业务的管理层账号（如负责备案的董事长、授权审核网站业务的网站人事冯超）：开放订单入口
        if (in_array($script, ['index.php', 'order.php', 'lookup.php', 'proof.php', 'credentials.php', 'ai.php', 'rule_request_api.php', 'import.php', 'files.php', 'import_undo_api.php'
    , 'file_sheet_api.php', 'renewals.php', 'renewal_gaps.php'], true) && ps_governance_has_business($actor)) {
            $allowed[] = $script;
            require_once __DIR__ . '/../ProjectBatchReview.php';
            if (!empty($actor['employee_id']) && function_exists('pbr_scope') && pbr_scope($actor)) {
                $actor['role'] = 'customer_service';
            } else {
                $actor['role'] = 'technical';
            }
            $actor['governance_orders'] = true;
        }
        if ($script === 'batch_review.php' && function_exists('pbr_scope') && pbr_scope($actor)) $allowed[] = $script; // 被授权审核订单的管理层人员（如网站人事）
        if ($script === 'completion_sync.php' && function_exists('pcs_can_access') && pcs_can_access($actor)) $allowed[] = $script;
        if (!in_array($script, $allowed, true)) { http_response_code(403); exit('此账号仅可访问管理层事项与本人结算'); }
        if ($script !== 'profile.php' && empty($actor['password_changed_at']) && PHP_SAPI !== 'cli') {
            header('Location: ' . BASE_URL . '/project/profile.php?password=1'); exit;
        }
    }
    // 平台信息专用账号：只能进入平台信息、我的账号、站内信；初始密码须先修改
    if ($actor['type'] === 'employee' && $actor['role'] === 'vault') {
        $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
        $knowledgeRuleRead = (($script === 'rules.php' && in_array($_GET['domain'] ?? '', ['welfare','governance'],true)) || $script === 'governance_rules.php') && ($_SERVER['REQUEST_METHOD'
    ] ?? 'GET') === 'GET';
        if (!$knowledgeRuleRead && !in_array($script, ['profile.php', 'dashboard.php', 'vault.php', 'domains.php', 'dns.php', 'cdn.php', 'messages.php', 'dup_feedback.php', 'knowledge.php', 'knowledge_article.php', 'knowledge_links.php'
    , 'knowledge_categories.php', 'knowledge_rules.php', 'knowledge_keywords.php', 'knowledge_integrations.php', 'knowledge_skills.php', 'knowledge_skill.php', 'knowledge_skill_import.php'
    , 'knowledge_skills_export.php', 'knowledge_costs.php'], true)) { header('Location: ' . BASE_URL . '/project/vault.php'); exit; }
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
    if ($actor['role'] !== 'finance' && !ps_management_can_business($actor, $order['project_type'])) {
        require_once __DIR__ . '/../ProjectDeptHead.php';
        require_once __DIR__ . '/../ProjectBatchReview.php';
        $delScope = function_exists('pbr_scope') ? pbr_scope($actor) : null;
        $isDelegated = $delScope && (!empty($delScope['all']) || in_array(ps_business_normalize($order['project_type']), $delScope['businesses'] ?? [], true));
        $access = db()->prepare('SELECT 1 FROM project_participants WHERE order_id=? AND employee_id=? LIMIT 1');
        $access->execute([(int)$id, $actor['employee_id']]);
        if (!$access->fetchColumn() && !ps_department_import_uploader_access($id, (int)$actor['employee_id']) && !pdh_can_view_order($id, $actor) && !$isDelegated) {
            $isMissingBackendTech = false;
            if ($actor['role'] === 'technical' && in_array(ps_business_normalize($order['project_type'] ?? ''), ['AI网站定制', '网站定制'], true)) {
                $checkHasBackend = db()->prepare("SELECT 1 FROM project_participants WHERE order_id=? AND commission_group='technical' AND role_name LIKE '%后端%' LIMIT 1");
                $checkHasBackend->execute([(int)$id]);
                if (!$checkHasBackend->fetchColumn() && ps_active_employee_for_business($actor['employee_id'], 'technical', ps_business_normalize($order['project_type']))) {
                    $isMissingBackendTech = true;
                }
            }
            // 软件测试员可查看并自行加入 AI网站定制 / 小程序开发 订单
            if (!$isMissingBackendTech && ps_software_testing_pick_business($order['project_type'] ?? '') && ps_software_tester_can_join($actor['employee_id'])) $isMissingBackendTech = true;
            if (!$isMissingBackendTech) { http_response_code(403); exit('无权限查看此订单'); }
        }
    }
    return $order;
}
