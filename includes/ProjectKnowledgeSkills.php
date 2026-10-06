<?php
/**
 * 沟通技巧库：沟通技巧 / 业务知识 / 精选聊天记录，三类内容按角色、业务分发，
 * 并以“后期接入 AI 客服学习”为导向存成结构化字段（场景、客户常见说法、推荐话术、禁忌、对话轮次）。
 * 独立建表，不参与订单、成本、结算计算；只有“已采纳”的条目才会进入 AI 学习数据。
 */
require_once __DIR__ . '/ProjectKnowledge.php';
require_once __DIR__ . '/ProjectBusiness.php';

const PKS_KINDS = ['skill' => '沟通技巧', 'business' => '业务知识', 'chat' => '精选聊天记录'];
const PKS_ROLES = ['customer_service' => '客服', 'technical' => '技术', 'design' => '设计', 'finance' => '财务/管理员', 'governance' => '管理层'];
const PKS_KIND_ICONS = ['skill' => 'fa-comments', 'business' => 'fa-book-open', 'chat' => 'fa-quote-right'];
const PKS_STATUS = ['pending' => '待审核', 'approved' => '已采纳（AI 可学习）', 'archived' => '已归档'];

/** 静态资源地址：版本号取文件修改时间，样式 / 脚本一更新，浏览器立刻拿到新文件，不会继续用旧缓存。 */
function pks_asset($relative)
{
    $file = dirname(__DIR__) . '/assets/' . $relative;
    return BASE_URL . '/assets/' . $relative . '?v=' . (is_file($file) ? filemtime($file) : time());
}

/** 读取 JSON 请求体（网站防火墙会拦“表单里单个字段过长”的请求，长文字统一用 JSON 提交）。 */
function pks_json_body()
{
    if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) return null;
    $data = json_decode((string)file_get_contents('php://input'), true);
    return is_array($data) ? $data : null;
}

function pks_json_reply($data, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** 前端 JSON 提交脚本：非 JSON 返回时给出明确提示，而不是“Unexpected token”。 */
function pks_post_script()
{
    return <<<'JS'
function pksPost(data) {
  return fetch(location.pathname + location.search, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(data)}).then(function (r) {
    return r.text().then(function (t) {
      var d;
      try { d = JSON.parse(t); } catch (e) {
        if (r.status === 403 && t.indexOf('防火墙') !== -1) throw new Error('请求被网站防火墙拦截，请把内容分成更短的几段再试');
        if (r.redirected || t.indexOf('login') !== -1) throw new Error('登录已过期，请刷新页面重新登录后再试');
        throw new Error('服务器返回了非预期内容（HTTP ' + r.status + '），请刷新页面后重试');
      }
      if (d.error) throw new Error(d.error);
      return d;
    });
  });
}
JS;
}

function pks_ensure()
{
    static $done = false;
    if ($done) return;
    db()->exec("CREATE TABLE IF NOT EXISTS project_kb_skills (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      kind ENUM('skill','business','chat') NOT NULL DEFAULT 'skill',
      title VARCHAR(200) NOT NULL,
      roles VARCHAR(120) NOT NULL DEFAULT '',
      business VARCHAR(100) NOT NULL DEFAULT '',
      scenario VARCHAR(255) NOT NULL DEFAULT '',
      customer_says TEXT NULL,
      reply MEDIUMTEXT NULL,
      avoid TEXT NULL,
      content MEDIUMTEXT NULL,
      chat_log MEDIUMTEXT NULL,
      tags VARCHAR(255) NOT NULL DEFAULT '',
      quality TINYINT UNSIGNED NOT NULL DEFAULT 3,
      status ENUM('pending','approved','archived') NOT NULL DEFAULT 'pending',
      revision INT NOT NULL DEFAULT 1,
      owner_type VARCHAR(20) NOT NULL,
      owner_id INT NOT NULL,
      owner_name VARCHAR(80) NOT NULL DEFAULT '',
      deleted_at DATETIME NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_skill_list (deleted_at,status,kind,updated_at),
      KEY idx_skill_owner (owner_type,owner_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** 自动判断的角色（可多个）：账号角色 + 所在部门（美工 / 设计类部门同时算“设计”）。 */
function pks_auto_roles(array $actor)
{
    if (($actor['type'] ?? '') === 'admin') return ['finance'];
    $roles = [];
    $role = (string)($actor['role'] ?? '');
    if (isset(PKS_ROLES[$role])) $roles[] = $role;
    if (!empty($actor['employee_id'])) {
        $q = db()->prepare('SELECT department FROM employees WHERE id=?');
        $q->execute([(int)$actor['employee_id']]);
        $dept = (string)$q->fetchColumn();
        if ($dept !== '' && preg_match('/美工|设计/u', $dept) && !in_array('design', $roles, true)) $roles[] = 'design';
    }
    return $roles;
}

/**
 * 当前使用的角色：用户手动切换过就用切换的（?as=customer_service,design，?as=auto 恢复自动），否则自动判断。
 * 切换只影响“谁排在前面”，不影响能看到什么。
 */
function pks_actor_roles(array $actor)
{
    if (isset($_GET['as']) && session_status() === PHP_SESSION_ACTIVE) {
        $want = array_values(array_intersect(array_keys(PKS_ROLES), preg_split('/[,\s]+/', (string)$_GET['as'], -1, PREG_SPLIT_NO_EMPTY)));
        if ($want) $_SESSION['kb_roles'] = $want; else unset($_SESSION['kb_roles']);
    }
    $chosen = (array)($_SESSION['kb_roles'] ?? []);
    $chosen = array_values(array_intersect(array_keys(PKS_ROLES), $chosen));
    return $chosen ?: pks_auto_roles($actor);
}

/** 兼容旧调用：第一个角色，没有则空串。 */
function pks_actor_role(array $actor)
{
    $roles = pks_actor_roles($actor);
    return $roles ? $roles[0] : '';
}

function pks_actor_businesses(array $actor)
{
    try {
        return ($actor['role'] ?? '') === 'finance' ? [] : array_values(ps_actor_businesses($actor));
    } catch (Throwable $e) { return []; }
}

/** 审核、采纳、导出的权限：知识库超级管理员或各部门主管 / 编辑者。 */
function pks_is_editor(array $ctx)
{
    return !empty($ctx['super']) || !empty($ctx['managed']);
}

function pks_actor_name(array $actor)
{
    if (!empty($actor['employee_id'])) {
        $q = db()->prepare('SELECT name FROM employees WHERE id=?');
        $q->execute([(int)$actor['employee_id']]);
        $name = (string)$q->fetchColumn();
        if ($name !== '') return $name;
    }
    return (string)($actor['username'] ?? ($actor['type'] === 'admin' ? '管理员' : ''));
}

/** 聊天记录等内容中的手机号、邮箱、身份证、银行卡号等个人信息先脱敏，再保存或交给 AI。 */
function pks_mask_pii($text)
{
    $text = (string)$text;
    $text = preg_replace_callback('/(?<!\d)(1[3-9]\d)(\d{4})(\d{4})(?!\d)/', function ($m) { return $m[1] . '****' . $m[3]; }, $text);
    $text = preg_replace_callback('/(?<![0-9A-Za-z])(\d{6})(\d{8})(\d{3}[0-9Xx])(?![0-9A-Za-z])/', function ($m) { return $m[1] . '********' . $m[3]; }, $text);
    $text = preg_replace('/(?<!\d)(\d{4})\d{8,11}(\d{4})(?!\d)/', '$1********$2', $text);
    $text = preg_replace_callback('/([A-Za-z0-9._%+-]{1,3})[A-Za-z0-9._%+-]*@([A-Za-z0-9.-]+\.[A-Za-z]{2,})/', function ($m) { return $m[1] . '***@' . $m[2]; }, $text);
    return $text;
}

function pks_clean_roles($value)
{
    $roles = is_array($value) ? $value : preg_split('/[,\s]+/', (string)$value, -1, PREG_SPLIT_NO_EMPTY);
    $out = [];
    foreach ($roles as $role) {
        if ($role === '*') return '';
        if (isset(PKS_ROLES[$role])) $out[$role] = $role;
    }
    return implode(',', array_keys($out));
}

function pks_text($value, $max, $label, $required = false)
{
    $value = str_replace("\r", '', trim((string)$value));
    if (strpos($value, "\0") !== false || !mb_check_encoding($value, 'UTF-8')) throw new RuntimeException($label . '包含无效字符。');
    if ($required && $value === '') throw new RuntimeException('请填写' . $label . '。');
    if (mb_strlen($value) > $max) throw new RuntimeException($label . '不能超过 ' . $max . ' 字。');
    return $value;
}

function pks_input(array $input, array $ctx)
{
    $kind = (string)($input['kind'] ?? 'skill');
    if (!isset(PKS_KINDS[$kind])) throw new RuntimeException('请选择内容类型。');
    $business = trim((string)($input['business'] ?? ''));
    if ($business !== '' && !isset(ps_business_catalog()[$business])) throw new RuntimeException('所属业务不在业务列表中。');
    $data = [
        'kind' => $kind,
        'title' => pks_text($input['title'] ?? '', 200, '标题', true),
        'roles' => pks_clean_roles($input['roles'] ?? []),
        'business' => $business,
        'scenario' => pks_text($input['scenario'] ?? '', 255, '场景 / 客户意图', $kind === 'skill'),
        'customer_says' => pks_text($input['customer_says'] ?? '', 4000, '客户常见说法'),
        'reply' => pks_text($input['reply'] ?? '', 8000, '推荐话术', $kind === 'skill'),
        'avoid' => pks_text($input['avoid'] ?? '', 4000, '禁忌 / 反面示例'),
        'content' => pks_text($input['content'] ?? '', 60000, $kind === 'chat' ? '亮点点评' : '要点说明', $kind === 'business'),
        'chat_log' => pks_text($input['chat_log'] ?? '', 60000, '对话原文', $kind === 'chat'),
        'tags' => pks_text($input['tags'] ?? '', 255, '标签'),
    ];
    // 聊天记录与话术里可能带客户隐私：保存前统一脱敏
    foreach (['customer_says', 'reply', 'avoid', 'content', 'chat_log', 'scenario', 'title'] as $field) $data[$field] = pks_mask_pii($data[$field]);
    return $data;
}

function pks_visible_sql(array $ctx, &$params)
{
    $params = [];
    if (pks_is_editor($ctx)) return 'deleted_at IS NULL';
    $params = [$ctx['actor']['type'], (int)$ctx['actor']['id']];
    return "deleted_at IS NULL AND (status='approved' OR (owner_type=? AND owner_id=?))";
}

function pks_row($id, array $ctx)
{
    pks_ensure();
    $q = db()->prepare('SELECT * FROM project_kb_skills WHERE id=?');
    $q->execute([(int)$id]);
    $row = $q->fetch();
    $own = $row && $row['owner_type'] === $ctx['actor']['type'] && (int)$row['owner_id'] === (int)$ctx['actor']['id'];
    if (!$row || (!empty($row['deleted_at']) && empty($ctx['super'])) || ($row['status'] !== 'approved' && !$own && !pks_is_editor($ctx))) throw new RuntimeException('这条内容不存在，或尚未向你开放。');
    return $row;
}

function pks_can_edit(array $row, array $ctx)
{
    $own = $row['owner_type'] === $ctx['actor']['type'] && (int)$row['owner_id'] === (int)$ctx['actor']['id'];
    return pks_is_editor($ctx) || $own;
}

function pks_save(array $input, array $ctx)
{
    pks_ensure();
    $data = pks_input($input, $ctx);
    $editor = pks_is_editor($ctx);
    $id = (int)($input['id'] ?? 0);
    $status = (string)($input['status'] ?? 'pending');
    $quality = max(1, min(5, (int)($input['quality'] ?? 3)));
    $db = db(); $db->beginTransaction();
    try {
        if ($id) {
            $q = $db->prepare('SELECT * FROM project_kb_skills WHERE id=? FOR UPDATE'); $q->execute([$id]); $old = $q->fetch();
            if (!$old || !empty($old['deleted_at']) || !pks_can_edit($old, $ctx)) throw new RuntimeException('无权编辑这条内容。');
            if ((int)$old['revision'] !== (int)($input['revision'] ?? 0)) throw new RuntimeException('这条内容刚刚被更新，请刷新后再编辑，内容不会被覆盖。');
            // 非编辑者修改已采纳的内容，需重新审核；质量分与状态只有编辑者能改
            if (!$editor) { $status = 'pending'; $quality = (int)$old['quality']; }
            elseif (!isset(PKS_STATUS[$status])) $status = $old['status'];
            $db->prepare('UPDATE project_kb_skills SET kind=?,title=?,roles=?,business=?,scenario=?,customer_says=?,reply=?,avoid=?,content=?,chat_log=?,tags=?,quality=?,status=?,revision=revision+1 WHERE id=?')
                ->execute(array_merge(array_values($data), [$quality, $status, $id]));
            $action = 'edit';
        } else {
            $q = $db->prepare('SELECT COUNT(*) FROM project_kb_skills WHERE owner_type=? AND owner_id=? AND created_at>=CURRENT_DATE');
            $q->execute([$ctx['actor']['type'], (int)$ctx['actor']['id']]);
            if ((int)$q->fetchColumn() >= 100) throw new RuntimeException('今天已提交很多条，请明天再继续。');
            if (!$editor || !isset(PKS_STATUS[$status])) $status = 'pending';
            if (!$editor) $quality = 3;
            $db->prepare('INSERT INTO project_kb_skills (kind,title,roles,business,scenario,customer_says,reply,avoid,content,chat_log,tags,quality,status,owner_type,owner_id,owner_name) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute(array_merge(array_values($data), [$quality, $status, $ctx['actor']['type'], (int)$ctx['actor']['id'], pks_actor_name($ctx['actor'])]));
            $id = (int)$db->lastInsertId();
            $action = 'add';
        }
        ps_audit('knowledge_skill', $id, $action, $ctx['actor'], ['title' => $data['title'], 'kind' => $data['kind'], 'status' => $status]);
        $db->commit();
        return $id;
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}

function pks_set_status($id, $status, array $ctx)
{
    pks_ensure();
    if (!pks_is_editor($ctx)) throw new RuntimeException('仅主管或知识库管理员可以采纳或归档。');
    if (!isset(PKS_STATUS[$status])) throw new RuntimeException('状态无效。');
    $db = db(); $db->beginTransaction();
    try {
        $db->prepare('UPDATE project_kb_skills SET status=?,revision=revision+1 WHERE id=? AND deleted_at IS NULL')->execute([$status, (int)$id]);
        ps_audit('knowledge_skill', (int)$id, 'status_' . $status, $ctx['actor'], []);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}

function pks_delete($id, array $ctx)
{
    if (empty($ctx['super'])) throw new RuntimeException('仅知识库超级管理员可以删除。');
    db()->prepare('UPDATE project_kb_skills SET deleted_at=NOW(),revision=revision+1 WHERE id=? AND deleted_at IS NULL')->execute([(int)$id]);
    ps_audit('knowledge_skill', (int)$id, 'delete', $ctx['actor'], []);
}

/**
 * 列表：先按可见性过滤，再“本人角色优先 → 本人业务优先 → 质量分 → 最近更新”排序。
 * 返回 [rows, total]。
 */
function pks_list(array $ctx, array $f, $page = 1, $perPage = 20)
{
    pks_ensure();
    $where = pks_visible_sql($ctx, $params);
    foreach (['kind' => 'kind', 'status' => 'status', 'business' => 'business'] as $key => $col) {
        if (!empty($f[$key])) { $where .= " AND $col=?"; $params[] = $f[$key]; }
    }
    $only = array_values(array_filter((array)($f['role'] ?? [])));
    if ($only) { $where .= " AND (roles='' OR " . implode(' OR ', array_fill(0, count($only), 'FIND_IN_SET(?,roles)>0')) . ')'; $params = array_merge($params, $only); }
    if (!empty($f['q'])) { $where .= " AND LOCATE(?,CONCAT_WS(' ',title,scenario,customer_says,reply,content,chat_log,tags))>0"; $params[] = $f['q']; }
    $q = db()->prepare("SELECT COUNT(*) FROM project_kb_skills WHERE $where"); $q->execute($params);
    $total = (int)$q->fetchColumn();
    $order = [];
    $myRoles = array_values(array_filter((array)($f['my_roles'] ?? [])));
    $orderParams = [];
    $match = $myRoles ? '(' . implode(' OR ', array_fill(0, count($myRoles), 'FIND_IN_SET(?,roles)>0')) . ')' : '0';
    if ($myRoles) { $order[] = "CASE WHEN $match THEN 0 WHEN roles='' THEN 1 ELSE 2 END"; $orderParams = array_merge($orderParams, $myRoles); }
    $mine = array_values(array_filter((array)($f['my_businesses'] ?? []), 'strlen'));
    if ($mine) { $order[] = "CASE WHEN business IN (" . implode(',', array_fill(0, count($mine), '?')) . ") THEN 0 WHEN business='' THEN 1 ELSE 2 END"; $orderParams = array_merge($orderParams, $mine); }
    $order[] = 'quality DESC'; $order[] = 'updated_at DESC'; $order[] = 'id DESC';
    $offset = (max(1, (int)$page) - 1) * $perPage;
    $q = db()->prepare("SELECT id,kind,title,roles,business,scenario,SUBSTRING(COALESCE(NULLIF(reply,''),NULLIF(content,''),chat_log,''),1,200) AS preview,SUBSTRING(SUBSTRING_INDEX(COALESCE(reply,''),CONCAT(CHAR(10),CHAR(10)),1),1,600) AS copy_text,tags,quality,status,owner_name,updated_at"
        . ($myRoles ? ",($match) AS role_match" : ',0 AS role_match')
        . " FROM project_kb_skills WHERE $where ORDER BY " . implode(',', $order) . " LIMIT $perPage OFFSET $offset");
    $q->execute(array_merge($myRoles, $params, $orderParams));
    return [$q->fetchAll(), $total];
}

/** 各类型可见条数（顶部统计卡用），随同样的可见性规则。 */
function pks_counts(array $ctx)
{
    pks_ensure();
    $where = pks_visible_sql($ctx, $params);
    $q = db()->prepare("SELECT kind,COUNT(*) FROM project_kb_skills WHERE $where GROUP BY kind");
    $q->execute($params);
    return $q->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** 页面顶部的“我的角色”条：点击角色可多选切换（只影响排序优先级），“自动”恢复按账号判断。 */
function pks_rolebar(array $roles, array $actor)
{
    $auto = pks_auto_roles($actor);
    $isAuto = empty($_SESSION['kb_roles']);
    $base = $_GET; unset($base['as'], $base['page']);
    $href = function ($as) use ($base) { return '?' . http_build_query($base + ['as' => $as]); };
    echo '<div class="kbx-rolebar" role="group" aria-label="我的角色"><span>我的角色：</span>';
    foreach (PKS_ROLES as $key => $label) {
        $on = in_array($key, $roles, true);
        $next = $on ? array_values(array_diff($roles, [$key])) : array_merge($roles, [$key]);
        echo '<a class="kbx-pill' . ($on ? ' is-on' : '') . '" href="' . e($href($next ? implode(',', $next) : 'auto')) . '" aria-pressed="' . ($on ? 'true' : 'false') . '">' . e($label) . '</a>';
    }
    if (!$isAuto) echo '<a class="kbx-pill" href="' . e($href('auto')) . '">恢复自动' . ($auto ? '（' . e(implode('、', array_map(function ($r) { return PKS_ROLES[$r]; }, $auto))) . '）' : '') . '</a>';
    echo '<span>优先显示所选角色的内容，其他内容照常可看</span></div>';
}

function pks_role_labels($roles)
{
    if ($roles === '' || $roles === null) return ['全部角色'];
    return array_values(array_filter(array_map(function ($r) { return PKS_ROLES[$r] ?? ''; }, explode(',', $roles))));
}

/** 对话原文 → [{speaker: customer|agent|other, text}]。认“客户/顾客/买家/用户”为客户，“客服/商家/我/售后/销售/技术”为我方。 */
function pks_parse_chat($text)
{
    $turns = [];
    foreach (preg_split('/\n/u', str_replace("\r", '', (string)$text)) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (preg_match('/^(客户|顾客|买家|用户|客人|甲方)\s*[:：]\s*(.*)$/u', $line, $m)) $turns[] = ['speaker' => 'customer', 'text' => $m[2]];
        elseif (preg_match('/^(客服|商家|卖家|我|售后|销售|技术|设计师|我方)\s*[:：]\s*(.*)$/u', $line, $m)) $turns[] = ['speaker' => 'agent', 'text' => $m[2]];
        elseif ($turns) $turns[count($turns) - 1]['text'] .= "\n" . $line;
        else $turns[] = ['speaker' => 'other', 'text' => $line];
    }
    return $turns;
}

function pks_lines($text)
{
    return array_values(array_filter(array_map('trim', preg_split('/\n+/u', (string)$text)), 'strlen'));
}

/** 单条 → AI 学习用的结构化记录（字段名稳定，后续接入 AI 客服时直接使用）。 */
function pks_ai_record(array $row)
{
    $replies = array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/u', str_replace("\r", '', (string)$row['reply']))), 'strlen'));
    return [
        'id' => (int)$row['id'],
        'type' => $row['kind'],
        'type_label' => PKS_KINDS[$row['kind']] ?? $row['kind'],
        'title' => $row['title'],
        'roles' => $row['roles'] === '' ? ['*'] : explode(',', $row['roles']),
        'business' => $row['business'],
        'scenario' => $row['scenario'],
        'customer_says' => pks_lines($row['customer_says']),
        'recommended_replies' => $replies,
        'avoid' => pks_lines($row['avoid']),
        'content' => (string)$row['content'],
        'dialogue' => $row['kind'] === 'chat' ? pks_parse_chat($row['chat_log']) : [],
        'tags' => array_values(array_filter(array_map('trim', preg_split('/[,，;；\s]+/u', (string)$row['tags'])), 'strlen')),
        'quality' => (int)$row['quality'],
        'revision' => (int)$row['revision'],
        'updated_at' => $row['updated_at'],
    ];
}

/** 只导出“已采纳”的条目；可按类型、角色、业务过滤。供导出下载与后续接入 AI 使用。 */
function pks_ai_records(array $f = [], $limit = 5000)
{
    pks_ensure();
    $where = "deleted_at IS NULL AND status='approved'"; $params = [];
    if (!empty($f['kind']) && isset(PKS_KINDS[$f['kind']])) { $where .= ' AND kind=?'; $params[] = $f['kind']; }
    if (!empty($f['role'])) { $where .= " AND (roles='' OR FIND_IN_SET(?,roles)>0)"; $params[] = $f['role']; }
    if (!empty($f['business'])) { $where .= " AND (business='' OR business=?)"; $params[] = $f['business']; }
    $q = db()->prepare("SELECT * FROM project_kb_skills WHERE $where ORDER BY quality DESC,id LIMIT " . (int)$limit);
    $q->execute($params);
    return array_map('pks_ai_record', $q->fetchAll());
}

/** AI 客服检索入口（后期接入用）：按问题文字对已采纳条目打分，返回最相关的 N 条。 */
function pks_ai_search($question, $role = '', $business = '', $limit = 5)
{
    $question = trim((string)$question);
    if ($question === '') return [];
    $words = array_values(array_unique(array_filter(preg_split('/[\s,，。！？!?、；;]+/u', $question), function ($w) { return mb_strlen($w) >= 2; })));
    $scored = [];
    foreach (pks_ai_records(['role' => $role, 'business' => $business]) as $rec) {
        $hay = mb_strtolower($rec['title'] . ' ' . $rec['scenario'] . ' ' . implode(' ', $rec['customer_says']) . ' ' . implode(' ', $rec['tags']) . ' ' . $rec['content']);
        $score = 0;
        foreach ($words as $w) if (mb_strpos($hay, mb_strtolower($w)) !== false) $score += 1 + (mb_strpos(mb_strtolower($rec['title'] . $rec['scenario']), mb_strtolower($w)) !== false ? 2 : 0);
        foreach ($rec['customer_says'] as $say) if ($say !== '' && mb_strpos($question, $say) !== false) $score += 5;
        if ($score > 0) $scored[] = [$score + $rec['quality'] / 10, $rec];
    }
    usort($scored, function ($a, $b) { return $b[0] <=> $a[0]; });
    return array_map(function ($p) { return $p[1]; }, array_slice($scored, 0, (int)$limit));
}

/** 把一段粘贴文字交给 AI，整理成该类型的字段（先脱敏，再发送）。 */
function pks_ai_fill($kind, $raw)
{
    if (!isset(PKS_KINDS[$kind])) throw new RuntimeException('请选择内容类型。');
    $raw = pks_mask_pii(pks_text($raw, 12000, '要整理的文字', true));
    if (!ps_ai_ready()) throw new RuntimeException('AI 尚未启用，请在“系统设置”里配置后再使用，或手动填写。');
    $hint = [
        'skill' => '沟通技巧：场景 scenario（客户什么意图/情形）、客户常见说法 customer_says（每行一句）、推荐话术 reply（多条用空行隔开，口语化、可直接发给客户）、禁忌 avoid（每行一条）、要点 content。',
        'business' => '业务知识：标题 title、要点说明 content（分点写清楚流程、价格口径、注意事项）、客户常问 customer_says（每行一句）、推荐回答 reply。',
        'chat' => '精选聊天记录：对话原文 chat_log（每句一行，以“客户：”或“客服：”开头）、场景 scenario、亮点点评 content（为什么值得学习）。',
    ][$kind];
    $prompt = "请把下面的文字整理成知识库条目，类型是" . PKS_KINDS[$kind] . "。" . $hint
        . "\n输出 JSON：{\"title\":\"\",\"scenario\":\"\",\"customer_says\":\"\",\"reply\":\"\",\"avoid\":\"\",\"content\":\"\",\"chat_log\":\"\",\"tags\":\"逗号分隔\"}。不适用的字段留空字符串，不要编造原文没有的信息，手机号等已被打码的内容保持原样。只输出 JSON。\n\n" . $raw;
    $reply = ps_ai_chat([['role' => 'system', 'content' => '你是严谨的客服培训资料整理助手，只输出 JSON。'], ['role' => 'user', 'content' => $prompt]], 10000);
    $data = ps_ai_json($reply);
    $out = [];
    foreach (['title', 'scenario', 'customer_says', 'reply', 'avoid', 'content', 'chat_log', 'tags'] as $field) {
        $v = $data[$field] ?? '';
        $out[$field] = is_array($v) ? implode("\n", array_map('strval', $v)) : (string)$v;
    }
    return $out;
}
