<?php
require_once __DIR__ . '/../includes/ProjectKnowledge.php';
$actor = ps_require_actor(); $ctx = pk_context($actor);
if (!pk_ready()) { http_response_code(503); exit('请先完成知识库迁移。'); }
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0); $error = '';
try { $row = $id ? pk_article($id,$ctx) : ['id'=>0,'revision'=>0,'title'=>'','body'=>'','category'=>'经验与方法','tags'=>'','visibility'=>'private','department'=>'','status'=>'draft','incoming_hash'=>null,'source_url'=>'','deleted_at'=>null]; }
catch (RuntimeException $e) { http_response_code(404); exit(e($e->getMessage())); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    try {
        $action = (string)($_POST['action'] ?? 'save');
        if ($action === 'save') {
            $_POST = pk_content_post($_POST,['title','body','category','tags']);
            $saved = pk_save_article($_POST,$ctx);
            header('Location: ' . BASE_URL . '/project/knowledge_article.php?id=' . $saved . '&saved=1'); exit;
        } elseif ($action === 'bookmark' && $id) {
            if (!empty($row['deleted_at'])) throw new RuntimeException('请先恢复文章。');
            if (!empty($_POST['remove'])) db()->prepare('DELETE FROM project_kb_bookmarks WHERE article_id=? AND actor_type=? AND actor_id=?')->execute([$id,$actor['type'],$actor['id']]);
            else db()->prepare('INSERT IGNORE INTO project_kb_bookmarks (article_id,actor_type,actor_id) VALUES (?,?,?)')->execute([$id,$actor['type'],$actor['id']]);
            header('Location: ' . BASE_URL . '/project/knowledge_article.php?id=' . $id); exit;
        } elseif (in_array($action,['delete','restore'],true) && $id) {
            if (!$ctx['super']) throw new RuntimeException('仅超级管理员可删除或恢复知识。');
            db()->beginTransaction();
            db()->prepare('UPDATE project_kb_articles SET deleted_at=' . ($action === 'restore' ? 'NULL' : 'NOW()') . ',revision=revision+1 WHERE id=?')->execute([$id]);
            ps_audit('knowledge_article',$id,$action,$actor,['title'=>$row['title']]); db()->commit();
            header('Location: ' . BASE_URL . '/project/knowledge.php'); exit;
        } else throw new RuntimeException('操作无效。');
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); $error = $e instanceof RuntimeException ? $e->getMessage() : '保存失败，请稍后重试。'; }
}
$editable = !$id || pk_editable($row,$ctx);
$editing = $editable && (!$id || !empty($_GET['edit']) || $error !== '');
$incoming = !empty($_GET['incoming']) && !empty($row['incoming_hash']) && $editable;
$form = $row;
$formRevision = $error !== '' && ($_POST['action'] ?? 'save') === 'save' ? (int)($_POST['revision'] ?? 0) : (int)$row['revision'];
if ($incoming) { $form['title'] = $row['incoming_title']; $form['body'] = $row['incoming_body']; }
if ($error !== '' && ($_POST['action'] ?? 'save') === 'save') foreach(['title','body','category','tags','visibility','department','status'] as $field) $form[$field] = (string)($_POST[$field] ?? $form[$field]);
$bookmarked = false; $history = [];
if ($id) {
    $q = db()->prepare('SELECT 1 FROM project_kb_bookmarks WHERE article_id=? AND actor_type=? AND actor_id=?'); $q->execute([$id,$actor['type'],$actor['id']]); $bookmarked = (bool)$q->fetchColumn();
    // 旧版本可能含已删敏感内容：只有当前编辑权限的人能看历史正文。
    if ($editable) {
        $q = db()->prepare('SELECT * FROM project_kb_revisions WHERE article_id=? ORDER BY revision DESC LIMIT 30'); $q->execute([$id]);
        foreach ($q->fetchAll() as $version) if (pk_readable(array_merge($row,$version),$ctx)) $history[] = $version;
    }
}
$departments = [];
if ($editing) $departments = db()->query("SELECT DISTINCT department FROM employees WHERE department<>'' ORDER BY department")->fetchAll(PDO::FETCH_COLUMN);
$page_title = $id ? $row['title'] . ' · 知识库' : '写一篇知识';
include __DIR__ . '/../includes/header.php';
?>
<div class="kb-page"><?php pk_tabs('articles'); ?><a class="kb-back" href="<?php echo BASE_URL; ?>/project/knowledge.php">← 回到知识库</a>
<?php if ($error): ?><div class="kb-notice kb-notice-error" role="alert"><?php echo e($error); ?></div><?php endif; ?>
<?php if (!empty($_GET['saved'])): ?><div class="kb-notice">已保存。谢谢你，让经验多留了一份。</div><?php endif; ?>
<?php if (!empty($row['deleted_at'])): ?><div class="kb-notice">这篇知识在回收站中。<form method="post"><input type="hidden" name="csrf" value="<?php echo ps_csrf_token(); ?>"><input type="hidden" name="id" value="<?php echo $id; ?>"><button name="action" value="restore" class="kb-button kb-button-soft">恢复文章</button></form></div><?php endif; ?>
<?php if (!empty($row['incoming_hash']) && $editable): ?><div class="kb-notice"><strong>外部文档有更新待核对。</strong> 当前已发布的版本保持不变。<a href="?id=<?php echo $id; ?>&edit=1&incoming=1">核对并采用更新 →</a></div><?php endif; ?>
<?php if ($editing): ?>
<form class="kb-glass kb-editor" method="post" data-kb-transport="title,body,category,tags"><input type="hidden" name="csrf" value="<?php echo ps_csrf_token(); ?>"><input type="hidden" name="id" value="<?php echo $id; ?>"><input type="hidden" name="revision" value="<?php echo $formRevision; ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="accept_incoming" value="<?php echo $incoming || !empty($_POST['accept_incoming']) ? '1' : '0'; ?>">
<h1><?php echo $incoming ? '核对外部更新' : ($id ? '编辑知识' : '分享一点，让大家轻松一点'); ?></h1><p class="kb-muted">纯文本编辑，支持分段。粘贴的 HTML、脚本不会执行；常用平台关键词会自动变成安全链接。</p>
<label>标题<input name="title" maxlength="200" required value="<?php echo e($form['title']); ?>" placeholder="比如：网站上线前，我会核对这 5 件事"></label>
<div class="kb-form-grid"><label>分类<input name="category" maxlength="60" required value="<?php echo e($form['category']); ?>" list="kb-categories"></label><label>标签<input name="tags" maxlength="255" value="<?php echo e($form['tags']); ?>" placeholder="用逗号分隔，例如：上线,检查清单"></label></div><datalist id="kb-categories"><option value="经验与方法"><option value="常见问题"><option value="交付清单"><option value="学习与成长"><option value="外部知识"></datalist>
<label>正文（最多 10 万字）<textarea name="body" rows="18" required maxlength="100000" placeholder="把步骤、注意事项和小经验写在这里…"><?php echo e($form['body']); ?></textarea></label>
<div class="kb-form-grid"><label>可见范围<select name="visibility"><option value="private" <?php echo $form['visibility'] === 'private' ? 'selected' : ''; ?>>仅作者与超级管理员</option><option value="department" <?php echo $form['visibility'] === 'department' ? 'selected' : ''; ?>>指定部门</option><option value="all" <?php echo $form['visibility'] === 'all' ? 'selected' : ''; ?>>全员</option></select></label><label>部门（指定部门时必选）<select name="department"><option value="">请选择</option><?php foreach($departments as $d): if (!$ctx['super'] && !in_array('*',$ctx['managed'],true) && $d !== $ctx['department'] && !in_array($d,$ctx['managed'],true)) continue; ?><option <?php echo $d === $form['department'] ? 'selected' : ''; ?>><?php echo e($d); ?></option><?php endforeach; ?></select></label><label>发布状态<select name="status"><option value="draft">保存草稿 / 待发布</option><?php if ($ctx['super'] || $ctx['managed']): ?><option value="published" <?php echo $form['status'] === 'published' ? 'selected' : ''; ?>>发布</option><?php endif; ?></select></label></div>
<?php if (!$ctx['super'] && !$ctx['managed']): ?><p class="kb-muted">选择“全员”或“指定部门”的草稿，主管可核对并发布；选择“仅作者”则不会进入主管待发布区。</p><?php endif; ?>
<div class="kb-actions"><button class="kb-button">保存<?php echo $incoming ? '并采用这次更新' : ''; ?></button><a class="kb-button kb-button-soft" href="<?php echo BASE_URL; ?>/project/<?php echo $id ? 'knowledge_article.php?id=' . $id : 'knowledge.php'; ?>">取消</a></div></form>
<?php else: ?><article class="kb-glass kb-reading"><div class="kb-card-top"><span class="kb-pill"><?php echo e($row['category']); ?></span><span class="kb-muted">版本 <?php echo (int)$row['revision']; ?> · <?php echo e(substr($row['updated_at'],0,10)); ?></span></div><h1><?php echo e($row['title']); ?></h1><div class="kb-actions"><?php if ($editable): ?><a class="kb-button kb-button-soft" href="?id=<?php echo $id; ?>&edit=1">编辑知识</a><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?php echo ps_csrf_token(); ?>"><input type="hidden" name="id" value="<?php echo $id; ?>"><input type="hidden" name="remove" value="<?php echo $bookmarked ? '1' : '0'; ?>"><button name="action" value="bookmark" class="kb-button kb-button-soft"><?php echo $bookmarked ? '★ 已收藏' : '☆ 收藏这篇'; ?></button></form></div><div class="kb-prose"><?php echo e($row['body']); ?></div><?php if ($row['tags']): ?><p class="kb-muted">标签：<?php echo e($row['tags']); ?></p><?php endif; ?><?php if ($row['source_url']): ?><p class="kb-muted">来源：<a href="<?php echo e(pk_url($row['source_url'])); ?>" target="_blank" rel="noopener noreferrer">打开原文 ↗</a> · 仅同步文字，图片与附件请查看原文。</p><?php endif; ?></article><?php endif; ?>
<?php if ($history): ?><details class="kb-glass kb-history"><summary>版本记录 · 最近 <?php echo count($history); ?> 个版本</summary><?php foreach($history as $v): ?><details><summary>版本 <?php echo (int)$v['revision']; ?> · <?php echo e($v['created_at']); ?> · <?php echo e($v['title']); ?></summary><div class="kb-prose"><?php echo e($v['body']); ?></div><a class="kb-button kb-button-soft" href="?id=<?php echo $id; ?>&edit=1">编辑当前版本</a><p class="kb-muted">需要恢复时，可复制历史正文到编辑页保存为新版本，历史不会丢失。</p></details><?php endforeach; ?></details><?php endif; ?>
<?php if ($ctx['super'] && $id && empty($row['deleted_at'])): ?><form method="post" class="kb-delete-form" data-kb-confirm="移到回收站后，其他伙伴将看不到这篇知识。确认吗？"><input type="hidden" name="csrf" value="<?php echo ps_csrf_token(); ?>"><input type="hidden" name="id" value="<?php echo $id; ?>"><button class="kb-button kb-button-danger" name="action" value="delete">移到回收站</button></form><?php endif; ?>
</div><?php include __DIR__ . '/../includes/footer.php'; ?>
