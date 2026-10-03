<?php
/** 共享分类只保存分类名称，不读取或公开私有文章的内容。 */
function pk_category_name($value)
{
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) throw new RuntimeException('请填写有效的分类名称。');
    if (preg_match('/[<>\x00-\x1F\x7F]/u', $value)) throw new RuntimeException('分类名称不能包含特殊控制字符。');
    $value = preg_replace('/[\p{Z}\s]+/u', ' ', trim($value));
    $value = trim($value);
    if ($value === '__new__' || preg_match('/[<>\x00-\x1F\x7F]/u', $value)) throw new RuntimeException('分类名称不能包含特殊控制字符。');
    return pk_limit($value, 60, '分类名称', true);
}

function pk_category_input(array $input, $default)
{
    $category = $input['category'] ?? $default;
    return pk_category_name($category === '__new__' ? ($input['new_category'] ?? '') : $category);
}

function pk_categories()
{
    if (!pk_ready()) return [];
    return db()->query('SELECT name FROM project_kb_categories ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
}

/** 可以在文章/网址事务内调用；失败时与正文一起回滚，不留下空分类。 */
function pk_create_category($name, array $ctx)
{
    $name = pk_category_name($name);
    $db = db(); $ownTransaction = !$db->inTransaction();
    if ($ownTransaction) $db->beginTransaction();
    try {
        $q = $db->prepare('SELECT id,name FROM project_kb_categories WHERE name=? FOR UPDATE');
        $q->execute([$name]); $existing = $q->fetch();
        if ($existing) { if ($ownTransaction) $db->commit(); return $existing['name']; }
        $q = $db->prepare('SELECT COUNT(*) FROM project_kb_categories WHERE owner_type=? AND owner_id=? AND created_at>=CURDATE()');
        $q->execute([$ctx['actor']['type'], $ctx['actor']['id']]);
        if ((int)$q->fetchColumn() >= 100) throw new RuntimeException('今天创建的分类较多，请先复用已有分类。');
        $db->prepare('INSERT INTO project_kb_categories (name,owner_type,owner_id) VALUES (?,?,?)')->execute([$name,$ctx['actor']['type'],$ctx['actor']['id']]);
        $id = (int)$db->lastInsertId();
        ps_audit('knowledge_category',$id,'add',$ctx['actor'],['name'=>$name]);
        if ($ownTransaction) $db->commit();
        return $name;
    } catch (Throwable $e) { if ($ownTransaction && $db->inTransaction()) $db->rollBack(); throw $e; }
}

function pk_category_picker($value, $newValue = '')
{
    static $sequence = 0;
    $id = 'kb-category-field-' . ++$sequence;
    static $catalog = null;
    if ($catalog === null) $catalog = pk_categories();
    $categories = $catalog;
    $isNew = $value === '__new__';
    // 旧文章的私有分类只出现在其授权编辑者的选择器中，不回填到公共分类库。
    if (!$isNew && !in_array($value,$categories,true)) $categories[] = $value;
    echo '<div class="kb-category-control" data-kb-category-field data-no-keywords><div class="kb-category-row"><label for="' . $id . '">分类<select name="category" id="' . $id . '" required>';
    foreach ($categories as $category) echo '<option value="' . e($category) . '"' . ($value === $category ? ' selected' : '') . '>' . e($category) . '</option>';
    echo '<option value="__new__"' . ($isNew ? ' selected' : '') . '>＋ 新建分类…</option></select></label><button type="button" class="kb-button kb-button-soft kb-category-toggle" data-kb-category-toggle aria-controls="' . $id . '-new" aria-expanded="' . ($isNew ? 'true' : 'false') . '">＋ 新建</button></div>';
    echo '<div class="kb-category-new" id="' . $id . '-new" data-kb-category-new' . ($isNew ? '' : ' hidden') . '><label for="' . $id . '-name">新分类名称<input id="' . $id . '-name" name="new_category" maxlength="60" value="' . e($newValue) . '" placeholder="例如：设计灵感 / 售后经验"' . ($isNew ? ' required' : ' disabled') . '></label><div class="kb-category-hint"><small>随本次保存创建，文章与网址都可复用。分类名称全员可见。</small><button type="button" class="kb-text-button" data-kb-category-cancel>取消新建</button></div></div><noscript><p class="kb-muted">如需新分类，请先到<a href="' . BASE_URL . '/project/knowledge_categories.php">分类库</a>添加，再刷新本页选择。</p></noscript></div>';
}
