<?php
require_once __DIR__ . '/../ProjectAccountRoles.php';

function ps_actor()
{
    if (isset($_SESSION['admin_id'])) return ['type' => 'admin', 'id' => (int)$_SESSION['admin_id'], 'employee_id' => null, 'role' => 'finance'];
    if (!isset($_SESSION['project_user_id'])) return null;
    $q = db()->prepare('SELECT * FROM project_users WHERE id=? AND is_active=1');
    $q->execute([(int)$_SESSION['project_user_id']]);
    $user = $q->fetch();
    $management = $user && $user['role'] === 'management' ? ps_management_profile($user['id']) : [];
    return $user ? ['type' => 'employee', 'id' => (int)$user['id'], 'employee_id' => (int)$user['employee_id'], 'role' => ps_account_order_role($user['role']), 'account_role' => $user['role'], 'management_scope' => $management['scope'] ?? null, 'management_title' => $management['title'] ?? null, 'username' => $user['username'], 'phone' =>
    $user['phone'] ?? null, 'password_changed_at' => $user['password_changed_at'] ?? null] : null;
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
        $allowed = ['profile.php', 'governance.php', 'governance_ideas.php', 'governance_election.php', 'governance_rules.php', 'governance_evidence.php', 'payroll.php', 'welfare.php'
    , 'contributions.php', 'messages.php', 'holidays.php', 'vault.php', 'knowledge.php', 'knowledge_article.php', 'knowledge_links.php', 'knowledge_categories.php', 'knowledge_rules.php'
    , 'knowledge_keywords.php', 'knowledge_integrations.php', 'knowledge_skills.php', 'knowledge_skill.php', 'knowledge_skill_import.php', 'knowledge_skills_export.php', 'knowledge_costs.php'
    ];
        if ($script === 'rules.php' && ($_GET['domain'] ?? $_POST['domain'] ?? '') === 'governance') $allowed[] = 'rules.php';
        if ($script === 'rules.php' && ($_GET['domain'] ?? $_POST['domain'] ?? '') === 'welfare') $allowed[] = 'rules.php';
        // 被分配了业务的管理层账号（如负责备案的董事长）：开放订单入口，页面内按“技术”身份录单，只能在分配的业务里建单、只能看到自己参与的订单。
        if (in_array($script, ['index.php', 'order.php', 'lookup.php', 'proof.php', 'credentials.php', 'ai.php', 'rule_request_api.php', 'import.php', 'files.php', 'import_undo_api.php'
    , 'file_sheet_api.php', 'renewals.php', 'renewal_gaps.php'], true) && ps_governance_has_business($actor)) {
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
        $knowledgeRuleRead = (($script === 'rules.php' && in_array($_GET['domain'] ?? '', ['welfare','governance'],true)) || $script === 'governance_rules.php') && ($_SERVER['REQUEST_METHOD'
    ] ?? 'GET') === 'GET';
        if (!$knowledgeRuleRead && !in_array($script, ['profile.php', 'vault.php', 'messages.php', 'dup_feedback.php', 'knowledge.php', 'knowledge_article.php', 'knowledge_links.php'
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
        $access = db()->prepare('SELECT 1 FROM project_participants WHERE order_id=? AND employee_id=? LIMIT 1');
        $access->execute([(int)$id, $actor['employee_id']]);
        if (!$access->fetchColumn() && !ps_department_import_uploader_access($id, (int)$actor['employee_id']) && !pdh_can_view_order($id, $actor)) {
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
