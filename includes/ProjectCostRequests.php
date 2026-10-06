<?php
/**
 * 成本速查与更改申请：
 *  - 给成本模板（成本中心）打“角色标签”，用户默认只看自己角色（以及自己负责业务）相关的成本；
 *  - 发现成本不对，可提交更改申请；申请人的部门主管、专属财务、超级管理员 admin 都能审核。
 * 审核只记录结论并回复申请人，不会自动改成本中心的价格（价格由财务在成本中心按版本修改，历史订单不受影响）。
 */
require_once __DIR__ . '/ProjectKnowledgeSkills.php';
require_once __DIR__ . '/dup_feedback.php';

const PCR_CATEGORIES = ['domain' => '域名', 'server' => '服务器', 'program' => '程序', 'certificate' => '证书', 'certification' => '认证', 'api' => '接口', 'plugin' => '插件', 'outsourcing' => '外包', 'other' => '其他'];
const PCR_KINDS = ['one_time' => '一次性', 'annual' => '按年', 'monthly' => '按月'];

function pcr_ensure()
{
    static $done = false;
    if ($done) return;
    db()->exec("CREATE TABLE IF NOT EXISTS project_cost_role_tags (
      template_id BIGINT UNSIGNED NOT NULL,
      role VARCHAR(30) NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (template_id, role),
      KEY idx_cost_tag_role (role)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE IF NOT EXISTS project_cost_change_requests (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      template_id BIGINT UNSIGNED NOT NULL,
      template_name VARCHAR(200) NOT NULL,
      template_spec VARCHAR(150) NOT NULL DEFAULT '',
      business_scope VARCHAR(100) NOT NULL DEFAULT '',
      current_price DECIMAL(14,2) NULL,
      proposed_price DECIMAL(14,2) NULL,
      proposed_note VARCHAR(255) NOT NULL DEFAULT '',
      reason TEXT NOT NULL,
      applicant_type VARCHAR(20) NOT NULL,
      applicant_id INT NOT NULL,
      applicant_employee_id INT NULL,
      applicant_name VARCHAR(80) NOT NULL DEFAULT '',
      status ENUM('pending','resolved','rejected') NOT NULL DEFAULT 'pending',
      handler_name VARCHAR(80) NULL,
      handle_note TEXT NULL,
      handled_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_cost_req_status (status, created_at),
      KEY idx_cost_req_template (template_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** 能打角色标签：超级管理员 admin 与专属财务。 */
function pcr_can_tag(array $actor, array $ctx)
{
    return !empty($ctx['super']) || pd_is_dedicated_finance($actor);
}

/** 能审核更改申请：超级管理员、专属财务，以及申请人的部门主管。 */
function pcr_can_review(array $actor, array $ctx, array $request = null)
{
    if (!empty($ctx['super']) || pd_is_dedicated_finance($actor)) return true;
    if ($request === null) return !empty($ctx['managed']);
    $eid = (int)($actor['employee_id'] ?? 0);
    return $eid > 0 && !empty($request['applicant_employee_id']) && in_array($eid, pd_department_heads((int)$request['applicant_employee_id']), true);
}

function pcr_tag_map()
{
    pcr_ensure();
    $map = [];
    foreach (db()->query('SELECT template_id,role FROM project_cost_role_tags')->fetchAll() as $r) $map[(int)$r['template_id']][] = $r['role'];
    return $map;
}

/**
 * 成本列表。$scope='mine'：打了本人角色标签的，或没有任何标签但属于本人业务 / 通用的；$scope='all'：全部在用成本。
 * 每行附 tags（角色键数组）与 relation（role=角色标签命中 / business=业务命中 / ''）。
 */
function pcr_templates($scope, array $roles, array $businesses, $search = '', $category = '')
{
    pcr_ensure();
    $where = 'is_active=1'; $params = [];
    if ($category !== '' && isset(PCR_CATEGORIES[$category])) { $where .= ' AND category=?'; $params[] = $category; }
    if ($search !== '') { $where .= " AND LOCATE(?,CONCAT_WS(' ',name,specification,business_scope))>0"; $params[] = $search; }
    $q = db()->prepare("SELECT id,category,business_scope,name,specification,unit,price_mode,cost_kind,price,supplier_price,version FROM project_cost_templates WHERE $where ORDER BY category,business_scope,name,id LIMIT 600");
    $q->execute($params);
    $tags = pcr_tag_map(); $out = [];
    foreach ($q->fetchAll() as $row) {
        $row['tags'] = $tags[(int)$row['id']] ?? [];
        $row['relation'] = '';
        if ($roles && array_intersect($roles, $row['tags'])) $row['relation'] = 'role';
        elseif (!$row['tags'] && ($row['business_scope'] === '' || in_array($row['business_scope'], $businesses, true))) $row['relation'] = $row['business_scope'] === '' ? '' : 'business';
        $show = $scope === 'all' || $row['relation'] !== '' || (!$row['tags'] && $row['business_scope'] === '');
        if ($show) $out[] = $row;
    }
    // 角色标签命中的排最前，其次本人业务，再其余
    usort($out, function ($a, $b) { $w = ['role' => 0, 'business' => 1, '' => 2]; return $w[$a['relation']] <=> $w[$b['relation']]; });
    return $out;
}

function pcr_set_tags($templateId, array $roles, array $actor, array $ctx)
{
    if (!pcr_can_tag($actor, $ctx)) throw new RuntimeException('仅超级管理员和专属财务可以给成本打角色标签。');
    pcr_ensure();
    $roles = array_values(array_intersect(array_keys(PKS_ROLES), $roles));
    $db = db(); $db->beginTransaction();
    try {
        $exists = $db->prepare('SELECT 1 FROM project_cost_templates WHERE id=?'); $exists->execute([(int)$templateId]);
        if (!$exists->fetchColumn()) throw new RuntimeException('成本项不存在。');
        $db->prepare('DELETE FROM project_cost_role_tags WHERE template_id=?')->execute([(int)$templateId]);
        $ins = $db->prepare('INSERT INTO project_cost_role_tags (template_id,role) VALUES (?,?)');
        foreach ($roles as $r) $ins->execute([(int)$templateId, $r]);
        ps_audit('cost_template', (int)$templateId, 'role_tags', $actor, ['roles' => $roles]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}

/** 批量：给一组成本 添加 / 移除 某个角色标签。 */
function pcr_bulk_tag(array $ids, $role, $mode, array $actor, array $ctx)
{
    if (!pcr_can_tag($actor, $ctx)) throw new RuntimeException('仅超级管理员和专属财务可以给成本打角色标签。');
    if (!isset(PKS_ROLES[$role])) throw new RuntimeException('请选择角色。');
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids || count($ids) > 600) throw new RuntimeException('请先勾选要打标签的成本（一次最多 600 条）。');
    pcr_ensure();
    $db = db(); $db->beginTransaction();
    try {
        if ($mode === 'remove') {
            $db->prepare('DELETE FROM project_cost_role_tags WHERE role=? AND template_id IN (' . implode(',', $ids) . ')')->execute([$role]);
        } else {
            $ins = $db->prepare('INSERT IGNORE INTO project_cost_role_tags (template_id,role) SELECT id,? FROM project_cost_templates WHERE id=?');
            foreach ($ids as $id) $ins->execute([$role, $id]);
        }
        ps_audit('cost_template', 0, 'role_tags_bulk', $actor, ['role' => $role, 'mode' => $mode, 'count' => count($ids)]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    return count($ids);
}

function pcr_message($employeeId, $title, $body, $link, $key)
{
    try {
        db()->prepare('INSERT IGNORE INTO project_messages (employee_id,category,title,body,link,dedupe_key) VALUES (?,?,?,?,?,?)')
            ->execute([(int)$employeeId, 'cost_request', mb_substr($title, 0, 160), $body, $link, $key]);
    } catch (Throwable $e) { /* 站内信表异常不影响申请 */ }
}

function pcr_submit($templateId, $proposedPrice, $proposedNote, $reason, array $actor)
{
    pcr_ensure();
    $reason = trim((string)$reason);
    if (mb_strlen($reason) < 5) throw new RuntimeException('请写清为什么成本不对（至少 5 个字）。');
    if (mb_strlen($reason) > 2000) throw new RuntimeException('理由不能超过 2000 字。');
    $proposedNote = mb_substr(trim((string)$proposedNote), 0, 255);
    $price = null;
    if ($proposedPrice !== '' && $proposedPrice !== null) {
        if (!is_numeric($proposedPrice) || (float)$proposedPrice < 0 || (float)$proposedPrice > 99999999) throw new RuntimeException('建议金额不正确。');
        $price = round((float)$proposedPrice, 2);
    }
    $q = db()->prepare('SELECT * FROM project_cost_templates WHERE id=? AND is_active=1');
    $q->execute([(int)$templateId]);
    $tpl = $q->fetch();
    if (!$tpl) throw new RuntimeException('成本项不存在或已停用。');
    $name = pks_actor_name($actor);
    $dup = db()->prepare("SELECT 1 FROM project_cost_change_requests WHERE template_id=? AND applicant_type=? AND applicant_id=? AND status='pending'");
    $dup->execute([(int)$templateId, $actor['type'], (int)$actor['id']]);
    if ($dup->fetchColumn()) throw new RuntimeException('这一项你已提交过申请，请等待审核结果。');
    $cnt = db()->prepare('SELECT COUNT(*) FROM project_cost_change_requests WHERE applicant_type=? AND applicant_id=? AND created_at>=CURRENT_DATE');
    $cnt->execute([$actor['type'], (int)$actor['id']]);
    if ((int)$cnt->fetchColumn() >= 30) throw new RuntimeException('今天提交的申请较多，请明天再继续。');
    db()->prepare('INSERT INTO project_cost_change_requests (template_id,template_name,template_spec,business_scope,current_price,proposed_price,proposed_note,reason,applicant_type,applicant_id,applicant_employee_id,applicant_name) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([(int)$templateId, $tpl['name'], $tpl['specification'], $tpl['business_scope'], $tpl['price'], $price, $proposedNote, $reason, $actor['type'], (int)$actor['id'], $actor['employee_id'] ?? null, $name]);
    $id = (int)db()->lastInsertId();
    ps_audit('cost_template', (int)$templateId, 'change_request', $actor, ['request_id' => $id, 'proposed_price' => $price, 'reason' => $reason]);
    // 通知申请人的部门主管（财务、超级管理员在页面和铃铛里看到）
    if (!empty($actor['employee_id'])) {
        foreach (pd_department_heads((int)$actor['employee_id']) as $headId) {
            pcr_message($headId, $name . ' 申请更改成本：' . $tpl['name'], '规格：' . $tpl['specification'] . "\n现价 ¥" . number_format((float)$tpl['price'], 2) . ($price !== null ? '，建议 ¥' . number_format($price, 2) : '') . "\n理由：" . $reason, '/project/knowledge_costs.php?review=1#req-' . $id, 'cost_req:' . $id . ':' . $headId);
        }
    }
    return $id;
}

function pcr_requests($status, array $actor, array $ctx)
{
    pcr_ensure();
    $where = $status === 'all' ? '1=1' : 'status=' . db()->quote($status);
    $rows = db()->query("SELECT * FROM project_cost_change_requests WHERE $where ORDER BY (status='pending') DESC, id DESC LIMIT 200")->fetchAll();
    return array_values(array_filter($rows, function ($r) use ($actor, $ctx) { return pcr_can_review($actor, $ctx, $r); }));
}

function pcr_my_requests(array $actor)
{
    pcr_ensure();
    $q = db()->prepare('SELECT * FROM project_cost_change_requests WHERE applicant_type=? AND applicant_id=? ORDER BY id DESC LIMIT 30');
    $q->execute([$actor['type'], (int)$actor['id']]);
    return $q->fetchAll();
}

function pcr_handle($id, $decision, $note, array $actor, array $ctx)
{
    pcr_ensure();
    if (!in_array($decision, ['resolved', 'rejected'], true)) throw new RuntimeException('处理结果无效。');
    $note = trim((string)$note);
    if ($decision === 'rejected' && $note === '') throw new RuntimeException('不采纳时请写明原因。');
    $q = db()->prepare("SELECT * FROM project_cost_change_requests WHERE id=? AND status='pending'");
    $q->execute([(int)$id]);
    $row = $q->fetch();
    if (!$row) throw new RuntimeException('申请不存在或已处理。');
    if (!pcr_can_review($actor, $ctx, $row)) throw new RuntimeException('你没有权限处理这条申请。');
    $handler = !empty($actor['employee_id']) ? pks_actor_name($actor) : ($actor['type'] === 'admin' ? pd_admin_username($actor) : '');
    db()->prepare("UPDATE project_cost_change_requests SET status=?,handler_name=?,handle_note=?,handled_at=NOW() WHERE id=? AND status='pending'")->execute([$decision, $handler, $note, (int)$id]);
    ps_audit('cost_template', (int)$row['template_id'], 'change_request_' . $decision, $actor, ['request_id' => (int)$id, 'note' => $note]);
    if (!empty($row['applicant_employee_id'])) {
        pcr_message((int)$row['applicant_employee_id'], '你申请更改成本“' . $row['template_name'] . '”已' . ($decision === 'resolved' ? '采纳' : '回复（未采纳）'),
            ($note !== '' ? '处理回复：' . $note : '已采纳，财务会在成本中心按新版本调整，之后新订单按新成本计算。') . '（处理人：' . $handler . '）',
            '/project/knowledge_costs.php#mine', 'cost_done:' . (int)$id);
    }
}

/** 铃铛 / 侧栏角标：当前登录者待处理的成本更改申请条数。 */
function pcr_pending_count(array $actor)
{
    try {
        $ctx = pk_context($actor);
        if (!pcr_can_review($actor, $ctx)) return 0;
        return count(pcr_requests('pending', $actor, $ctx));
    } catch (Throwable $e) { return 0; }
}
