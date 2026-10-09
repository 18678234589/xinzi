<?php
/** 知识库：显式授权、乐观锁、版本记录；不参与财务计算。 */
require_once __DIR__ . '/ProjectSettlement.php';

function pk_ready()
{
    static $ready;
    if ($ready !== null) return $ready;
    try { db()->query('SELECT id FROM project_kb_links LIMIT 1'); db()->query('SELECT id FROM project_kb_categories LIMIT 1'); return $ready = true; }
    catch (PDOException $e) { return $ready = false; }
}

function pk_is_super(array $actor)
{
    if (($actor['type'] ?? '') !== 'admin' || !pk_ready()) return false;
    $q = db()->prepare('SELECT 1 FROM project_kb_super_admins WHERE admin_id=?');
    $q->execute([(int)$actor['id']]);
    return (bool)$q->fetchColumn();
}

function pk_departments(array $actor)
{
    if (ps_management_company($actor)) return ['*'];
    if (pk_is_super($actor)) return ['*'];
    $id = (int)($actor['employee_id'] ?? 0);
    if (!$id) return [];
    $out = [];
    if (ps_is_management($actor)) foreach (ps_actor_businesses($actor) as $business) {
        $out = array_merge($out, ps_business_catalog()[$business]['departments'] ?? []);
    }
    foreach (['project_dept_heads','project_welfare_managers'] as $table) {
        try {
            $q = db()->prepare('SELECT department FROM ' . $table . ' WHERE employee_id=?');
            $q->execute([$id]);
            $out = array_merge($out, $q->fetchAll(PDO::FETCH_COLUMN));
        } catch (PDOException $e) { /* 旧站尚未迁移主管表时不擅自授权。 */ }
    }
    if (pk_ready()) {
        $q = db()->prepare('SELECT 1 FROM project_kb_editors WHERE employee_id=?');
        $q->execute([$id]);
        if ($q->fetchColumn()) $out[] = '*';
    }
    return array_values(array_unique($out));
}

function pk_context(array $actor)
{
    $department = '';
    if (!empty($actor['employee_id'])) {
        $q = db()->prepare('SELECT department FROM employees WHERE id=?');
        $q->execute([(int)$actor['employee_id']]);
        $department = (string)$q->fetchColumn();
    }
    return ['actor'=>$actor, 'super'=>pk_is_super($actor), 'managed'=>pk_departments($actor), 'department'=>$department];
}

function pk_owner(array $row, array $ctx)
{
    return ($row['owner_type'] ?? '') === $ctx['actor']['type'] && (int)($row['owner_id'] ?? 0) === (int)$ctx['actor']['id'];
}

function pk_readable(array $row, array $ctx)
{
    if (!empty($row['deleted_at'])) return !empty($ctx['super']);
    if (!empty($ctx['super']) || pk_owner($row, $ctx)) return true;
    $scope = $row['visibility'] ?? 'private';
    if ($scope === 'private') return false;
    $dept = (string)($row['department'] ?? '');
    $manager = in_array('*', $ctx['managed'], true) || in_array($dept, $ctx['managed'], true);
    if (($row['status'] ?? '') !== 'published') return $manager || ($scope === 'all' && !empty($ctx['managed']));
    return $scope === 'all' || ($scope === 'department' && $dept !== '' && ($dept === $ctx['department'] || $manager));
}

function pk_editable(array $row, array $ctx)
{
    if (!pk_readable($row, $ctx) || !empty($row['deleted_at'])) return false;
    if (!empty($ctx['super']) || pk_owner($row, $ctx)) return true;
    return !empty($ctx['managed']) && (($row['visibility'] ?? '') === 'all' || in_array('*', $ctx['managed'], true) || in_array($row['department'], $ctx['managed'], true));
}

function pk_article($id, array $ctx)
{
    $q = db()->prepare('SELECT * FROM project_kb_articles WHERE id=?');
    $q->execute([(int)$id]);
    $row = $q->fetch();
    if (!$row || !pk_readable($row, $ctx)) throw new RuntimeException('这篇知识不存在，或尚未向你开放。');
    return $row;
}

/** 列表在 SQL 中先做可见性过滤，再分页，不读取全库正文。 */
function pk_access_sql(array $ctx, &$params)
{
    $params = [];
    if (!empty($ctx['super'])) return '1=1';
    $params = [$ctx['actor']['type'],(int)$ctx['actor']['id']];
    $parts = ['(owner_type=? AND owner_id=?)'];
    $scopes = ["visibility='all'"];
    if ($ctx['department'] !== '') { $scopes[] = "(visibility='department' AND department=?)"; $params[] = $ctx['department']; }
    // 下方经理条件单独维护参数，防止 SQL 占位符顺序错位。
    $parts[] = "(status='published' AND (" . implode(' OR ',$scopes) . '))';
    if (!empty($ctx['managed'])) {
        if (in_array('*',$ctx['managed'],true)) $parts[] = "visibility<>'private'";
        else { $parts[] = "(visibility='all' OR (visibility='department' AND department IN (" . implode(',',array_fill(0,count($ctx['managed']),'?')) . ')))'; $params = array_merge($params,$ctx['managed']); }
    }
    return '(' . implode(' OR ',$parts) . ')';
}

function pk_limit($value, $max, $label, $required = false)
{
    $value = trim((string)$value);
    if (($required && $value === '') || mb_strlen($value) > $max || strpos($value, "\0") !== false) throw new RuntimeException($label . '不能为空或超出长度限制。');
    return $value;
}

/** 正文 UTF-8 JSON 封包传输，避免教程文字被误认成 SQL；解码后照常校验。 */
function pk_content_post(array $input, array $allowed)
{
    if (isset($input['kb_parts_count'])) {
        if (!is_scalar($input['kb_parts_count']) || !preg_match('/^[1-9][0-9]{0,2}$/D',(string)$input['kb_parts_count']) || (int)$input['kb_parts_count'] > 900) throw new RuntimeException('正文分段数量无效，请拆分文章。');
        $count = (int)$input['kb_parts_count']; $packed = '';
        for ($i=0;$i<$count;$i++) {
            $part = $input['kb_part_' . $i] ?? null;
            if (!is_string($part) || strlen($part) > 768 || $part === '') throw new RuntimeException('正文分段缺失，请刷新后重试，文章尚未保存。');
            $packed .= $part;
        }
        $digest = $input['kb_content_sha256'] ?? '';
        if (!is_string($digest) || !preg_match('/^[a-f0-9]{64}$/D',$digest) || !hash_equals(hash('sha256',$packed),$digest)) throw new RuntimeException('正文校验未通过，文章尚未保存。');
        $input['kb_content'] = $packed;
    }
    if (!isset($input['kb_content'])) return $input;
    $encoded = $input['kb_content'];
    if (!is_string($encoded) || strlen($encoded) > 2000000) throw new RuntimeException('正文封包过大，请拆分文章。');
    $raw = base64_decode($encoded,true);
    $data = $raw === false ? null : json_decode($raw,true,4);
    if (!is_array($data)) throw new RuntimeException('正文传输不完整，请刷新后重试。');
    foreach ($data as $key=>$value) {
        if (!in_array($key,$allowed,true) || !is_string($value) || !mb_check_encoding($value,'UTF-8')) throw new RuntimeException('正文封包包含无效字段。');
        $input[$key] = $value;
    }
    return $input;
}

/** 仅公开 HTTPS 网址；绝不把这些网址作为服务器下载目标。 */
function pk_url($value)
{
    $value = trim((string)$value);
    if (strlen($value) > 1000 || preg_match('/[\x00-\x20\x7f\\\\]/', $value) || !filter_var($value, FILTER_VALIDATE_URL)) throw new RuntimeException('请填写完整、有效的 HTTPS 网址。');
    $p = parse_url($value);
    if (!empty($p['query'])) {
        parse_str($p['query'],$query);
        foreach (array_keys($query) as $key) if (preg_match('/^(access[_-]?token|token|api[_-]?key|app[_-]?secret|password|passwd|secret|authorization|auth|signature)$/i',(string)$key)) throw new RuntimeException('网址中包含访问密钥或认证参数，请移除后再添加。');
    }
    $host = strtolower($p['host'] ?? '');
    if (($p['scheme'] ?? '') !== 'https' || isset($p['user']) || isset($p['pass']) || (isset($p['port']) && (int)$p['port'] !== 443)
        || strpos($host, '.') === false || filter_var($host, FILTER_VALIDATE_IP) || preg_match('/(^|\.)(localhost|local|internal|test|invalid)$/', $host)) {
        throw new RuntimeException('仅支持公开的 HTTPS 域名，不支持账号密码、IP 或内网地址。');
    }
    return 'https://' . $host . ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '') . (isset($p['fragment']) ? '#' . $p['fragment'] : '');
}

function pk_keywords($value)
{
    $parts = preg_split('/[,，;；\r\n]+/u', pk_limit($value, 255, '关键词'));
    $out = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') continue;
        if (mb_strlen($part) < 2 || mb_strlen($part) > 40 || preg_match('/[<>\x00-\x1f]/u', $part)) throw new RuntimeException('每个关键词请填写 2–40 个字，用逗号分隔。');
        $out[mb_strtolower($part)] = $part;
    }
    if (count($out) > 12) throw new RuntimeException('每个网址最多关联 12 个关键词。');
    return array_values($out);
}

function pk_keyword_conflicts(array $keywords, array $links, $exceptId = 0)
{
    $keys = array_map('mb_strtolower', $keywords);
    foreach ($links as $link) {
        if ((int)$link['id'] === (int)$exceptId || !empty($link['deleted_at'])) continue;
        foreach (pk_keywords($link['keywords']) as $key) if (in_array(mb_strtolower($key), $keys, true)) return $key;
    }
    return '';
}

function pk_links($deleted = false)
{
    if (!pk_ready()) return [];
    return db()->query('SELECT * FROM project_kb_links WHERE deleted_at IS ' . ($deleted ? 'NOT ' : '') . 'NULL ORDER BY sort_order,title,id')->fetchAll();
}

function pk_save_link(array $input, array $ctx)
{
    $id = (int)($input['id'] ?? 0);
    if ($id && empty($ctx['managed']) && empty($ctx['super'])) throw new RuntimeException('网址编辑由主管负责，你可以添加新网址。');
    $title = pk_limit($input['title'] ?? '', 100, '名称', true);
    $url = pk_url($input['url'] ?? '');
    $keywords = pk_keywords($input['keywords'] ?? '');
    $category = pk_category_input($input, '常用工具');
    $description = pk_limit($input['description'] ?? '', 255, '介绍');
    $sort = max(0, min(9999, (int)($input['sort_order'] ?? 100)));
    $db = db(); $db->beginTransaction();
    try {
        // 全量锁定小型导航库，避免同时提交产生重复关键词。
        $links = $db->query('SELECT * FROM project_kb_links ORDER BY id FOR UPDATE')->fetchAll();
        $old = null;
        foreach ($links as $link) {
            if ((int)$link['id'] === $id) $old = $link;
            if ((int)$link['id'] !== $id && $link['url_hash'] === hash('sha256', $url)) throw new RuntimeException(empty($link['deleted_at']) ? '这个网址已在导航中，无需重复添加。' : '这个网址在回收站，请联系超级管理员恢复。');
        }
        $conflict = pk_keyword_conflicts($keywords, $links, $id);
        if ($conflict !== '') throw new RuntimeException('关键词“' . $conflict . '”已指向其他网址，请换一个关键词。');
        if ($id) {
            if (!$old || !empty($old['deleted_at']) || (int)$old['revision'] !== (int)($input['revision'] ?? 0)) throw new RuntimeException('网址刚刚被更新，请刷新后再编辑。');
            if (($input['category'] ?? '') === '__new__') $category = pk_create_category($category,$ctx);
            $db->prepare('UPDATE project_kb_links SET title=?,url=?,url_hash=?,keywords=?,category=?,description=?,sort_order=?,revision=revision+1 WHERE id=?')->execute([$title,$url,hash('sha256',$url),implode(',',$keywords),$category,$description,$sort,$id]);
        } else {
            $q = $db->prepare('SELECT COUNT(*) FROM project_kb_links WHERE owner_type=? AND owner_id=? AND created_at>=CURRENT_DATE');
            $q->execute([$ctx['actor']['type'],$ctx['actor']['id']]);
            if ((int)$q->fetchColumn() >= 100) throw new RuntimeException('今天已添加很多网址，请明天再继续。');
            if (($input['category'] ?? '') === '__new__') $category = pk_create_category($category,$ctx);
            $db->prepare('INSERT INTO project_kb_links (title,url,url_hash,keywords,category,description,sort_order,owner_type,owner_id) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$title,$url,hash('sha256',$url),implode(',',$keywords),$category,$description,$sort,$ctx['actor']['type'],$ctx['actor']['id']]);
            $id = (int)$db->lastInsertId();
        }
        ps_audit('knowledge_link',$id,$old ? 'edit' : 'add',$ctx['actor'],['title'=>$title,'url'=>$url,'previous'=>$old ? array_intersect_key($old,array_flip(['title','url','keywords','category','description','sort_order'])) : null]);
        $db->commit(); return $id;
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}

function pk_link_state($id, $restore, array $ctx)
{
    if (empty($ctx['super'])) throw new RuntimeException('只有超级管理员可以删除或恢复网址。');
    $db = db(); $db->beginTransaction();
    try {
        $all = $db->query('SELECT * FROM project_kb_links ORDER BY id FOR UPDATE')->fetchAll();
        $row = null; foreach ($all as $item) if ((int)$item['id'] === (int)$id) $row = $item;
        if (!$row) throw new RuntimeException('网址不存在。');
        if ($restore && pk_keyword_conflicts(pk_keywords($row['keywords']),$all,$id) !== '') throw new RuntimeException('原关键词已被其他网址使用，请先调整后再恢复。');
        $db->prepare('UPDATE project_kb_links SET deleted_at=' . ($restore ? 'NULL' : 'NOW()') . ',revision=revision+1 WHERE id=?')->execute([(int)$id]);
        ps_audit('knowledge_link',(int)$id,$restore ? 'restore' : 'delete',$ctx['actor'],['title'=>$row['title']]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}

function pk_article_input(array $input, array $ctx)
{
    $data = [
        'title'=>pk_limit($input['title'] ?? '',200,'标题',true),
        'body'=>pk_limit($input['body'] ?? '',100000,'正文（最多 10 万字）',true),
        'category'=>pk_category_input($input, '经验与方法'),
        'tags'=>pk_limit($input['tags'] ?? '',255,'标签'),
        'visibility'=>(string)($input['visibility'] ?? 'private'),
        'department'=>pk_limit($input['department'] ?? '',100,'部门'),
        'status'=>(string)($input['status'] ?? 'draft')
    ];
    if (!in_array($data['visibility'],['all','department','private'],true) || !in_array($data['status'],['draft','published'],true)) throw new RuntimeException('可见范围或发布状态无效。');
    if ($data['visibility'] !== 'department') $data['department'] = '';
    if ($data['visibility'] === 'department') {
        if ($data['department'] === '') throw new RuntimeException('请选择可见部门。');
        if (empty($ctx['super']) && !in_array('*',$ctx['managed'],true) && $data['department'] !== $ctx['department'] && !in_array($data['department'],$ctx['managed'],true)) throw new RuntimeException('不能向未授权的部门发布。');
    }
    if ($data['status'] === 'published' && empty($ctx['managed']) && empty($ctx['super'])) throw new RuntimeException('请先保存草稿，交由主管发布。');
    return $data;
}

function pk_revision(array $row, array $actor)
{
    db()->prepare('INSERT INTO project_kb_revisions (article_id,revision,title,body,category,tags,visibility,department,status,actor_type,actor_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([$row['id'],$row['revision'],$row['title'],$row['body'],$row['category'],$row['tags'],$row['visibility'],$row['department'],$row['status'],$actor['type'],$actor['id']]);
}

function pk_save_article(array $input, array $ctx)
{
    $data = pk_article_input($input,$ctx);
    $id = (int)($input['id'] ?? 0);
    $db = db(); $db->beginTransaction();
    try {
        if ($id) {
            $q = $db->prepare('SELECT * FROM project_kb_articles WHERE id=? FOR UPDATE'); $q->execute([$id]); $old = $q->fetch();
            if (!$old || !pk_editable($old,$ctx)) throw new RuntimeException('无权编辑这篇知识。');
            if ((int)$old['revision'] !== (int)($input['revision'] ?? 0)) throw new RuntimeException('这篇知识已被更新。请刷新并核对后再保存，内容不会被覆盖。');
            if (($input['category'] ?? '') === '__new__') $data['category'] = pk_create_category($data['category'],$ctx);
            $values = array_values($data); $values[] = $id;
            $db->prepare('UPDATE project_kb_articles SET title=?,body=?,category=?,tags=?,visibility=?,department=?,status=?,revision=revision+1 WHERE id=?')->execute($values);
            if (!empty($input['accept_incoming']) && $old['incoming_hash']) {
                $db->prepare('UPDATE project_kb_articles SET source_hash=incoming_hash,incoming_title=NULL,incoming_body=NULL,incoming_hash=NULL WHERE id=?')->execute([$id]);
            }
        } else {
            if (($input['category'] ?? '') === '__new__') $data['category'] = pk_create_category($data['category'],$ctx);
            $values = array_merge(array_values($data),[$ctx['actor']['type'],$ctx['actor']['id']]);
            $db->prepare('INSERT INTO project_kb_articles (title,body,category,tags,visibility,department,status,owner_type,owner_id) VALUES (?,?,?,?,?,?,?,?,?)')->execute($values);
            $id = (int)$db->lastInsertId();
        }
        $row = pk_article($id,$ctx); pk_revision($row,$ctx['actor']);
        ps_audit('knowledge_article',$id,'save',$ctx['actor'],['title'=>$data['title'],'revision'=>$row['revision'],'visibility'=>$data['visibility'],'status'=>$data['status']]);
        $db->commit(); return $id;
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}

/** 来源更新只暂存；绝不覆盖已发布正文或重置原权限。 */
function pk_stage_document($provider, $key, array $doc, array $ctx)
{
    if (empty($ctx['super'])) throw new RuntimeException('仅超级管理员可同步外部文档。');
    $title = pk_limit($doc['title'] ?? '',200,'文档标题',true);
    $body = pk_limit($doc['body'] ?? '',100000,'文档正文（最多 10 万字，请拆分较长文档）',true);
    $url = pk_url($doc['url'] ?? '');
    $hash = hash('sha256',$title . "\n" . $body);
    $db = db(); $db->beginTransaction();
    try {
        $q = $db->prepare('SELECT * FROM project_kb_articles WHERE source_provider=? AND source_key=? FOR UPDATE'); $q->execute([$provider,$key]); $old = $q->fetch();
        if ($old && !empty($old['deleted_at'])) throw new RuntimeException('文档已在回收站，请先恢复再同步。');
        if ($old && ($old['incoming_hash'] === $hash || ($old['source_hash'] === $hash && !$old['incoming_hash']))) {
            $db->prepare('UPDATE project_kb_articles SET synced_at=NOW() WHERE id=?')->execute([$old['id']]);
            ps_audit('knowledge_sync',(int)$old['id'],'unchanged',$ctx['actor'],['provider'=>$provider]);
            $db->commit(); return ['id'=>(int)$old['id'],'changed'=>false];
        }
        if ($old && $old['source_hash'] === $hash) {
            // 外部撤回了上一次修改，清除过时的待核对内容；本地正文不变。
            $db->prepare('UPDATE project_kb_articles SET incoming_title=NULL,incoming_body=NULL,incoming_hash=NULL,synced_at=NOW(),revision=revision+1 WHERE id=?')->execute([$old['id']]);
            ps_audit('knowledge_sync',(int)$old['id'],'incoming_reverted',$ctx['actor'],['provider'=>$provider]);
            $db->commit(); return ['id'=>(int)$old['id'],'changed'=>false];
        }
        if ($old) {
            $id = (int)$old['id'];
            $db->prepare('UPDATE project_kb_articles SET incoming_title=?,incoming_body=?,incoming_hash=?,synced_at=NOW(),revision=revision+1 WHERE id=?')->execute([$title,$body,$hash,$id]);
        } else {
            $db->prepare("INSERT INTO project_kb_articles (title,body,category,owner_type,owner_id,source_provider,source_key,source_url,incoming_title,incoming_body,incoming_hash,synced_at) VALUES (?,'','外部知识',?,?,?,?,?,?,?,?,NOW())")->execute([$title,$ctx['actor']['type'],$ctx['actor']['id'],$provider,$key,$url,$title,$body,$hash]);
            $id = (int)$db->lastInsertId();
        }
        ps_audit('knowledge_sync',$id,'staged',$ctx['actor'],['provider'=>$provider,'source_key'=>$key,'hash'=>$hash]);
        $db->commit(); return ['id'=>$id,'changed'=>true];
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}

function pk_tabs($active)
{
    $actor = ps_actor();
    $tabs = ['articles'=>['knowledge.php','知识文章','fa-book-open'],'skills'=>['knowledge_skills.php','沟通技巧库','fa-comments'],'costs'=>['knowledge_costs.php','成本速查','fa-coins'],'links'=>['knowledge_links.php','常用网址','fa-compass'],'categories'=>['knowledge_categories.php','分类库','fa-folder-open'],'rules'=>['knowledge_rules.php','规则中心','fa-sliders-h']];
    if ($actor && pk_is_super($actor)) $tabs['integrations'] = ['knowledge_integrations.php','同步与权限','fa-plug'];
    echo '<nav class="kb-tabs" aria-label="知识库栏目">';
    foreach ($tabs as $key=>$tab) echo '<a class="' . ($active === $key ? 'is-active' : '') . '" href="' . BASE_URL . '/project/' . $tab[0] . '"' . ($active === $key ? ' aria-current="page"' : '') . '><i class="fas ' . $tab[2] . '"></i> ' . $tab[1] . '</a>';
    echo '</nav>';
}

function pk_hero($eyebrow, $title, $subtitle)
{
    echo '<section class="kb-hero"><div class="kb-hero-art" aria-hidden="true"></div><div class="kb-hero-grid" aria-hidden="true"></div><div class="kb-hero-flow" aria-hidden="true"></div><div class="kb-orb kb-orb-one" aria-hidden="true"></div><div class="kb-orb kb-orb-two" aria-hidden="true"></div><div class="kb-hero-copy"><span class="kb-eyebrow"><span class="kb-live-dot" aria-hidden="true"></span>' . e($eyebrow) . '</span><h1>' . e($title) . '</h1><p>' . e($subtitle) . '</p><div class="kb-hero-caption" aria-hidden="true"><span></span> CONNECT · SHARE · GROW</div></div><div class="kb-hero-orbit" aria-hidden="true"><span></span><span></span></div><button type="button" class="kb-motion-toggle" data-kb-motion hidden aria-label="开启背景动效" aria-pressed="false">开启动效</button></section>';
}

require_once __DIR__ . '/ProjectKnowledgeCategories.php';
