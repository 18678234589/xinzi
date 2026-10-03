<?php
require_once __DIR__ . '/../includes/ProjectKnowledge.php';
$actor = ps_require_actor(); $ctx = pk_context($actor);
$page_title = '知识库';
$search = mb_substr(trim((string)($_GET['q'] ?? '')),0,100);
$category = mb_substr(trim((string)($_GET['category'] ?? '')),0,60);
$mode = (string)($_GET['view'] ?? 'all');
$page = max(1,(int)($_GET['page'] ?? 1));
$articles = []; $categories = []; $total = 0;
if (pk_ready()) {
    $categories = array_fill_keys(pk_categories(),true);
    $acl = pk_access_sql($ctx,$params);
    $where = 'deleted_at IS NULL AND ' . $acl;
    $q = db()->prepare('SELECT DISTINCT category FROM project_kb_articles WHERE ' . $where . ' ORDER BY category'); $q->execute($params);
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $c) $categories[$c] = true;
    if ($category !== '') { $where .= ' AND category=?'; $params[] = $category; }
    if ($mode === 'mine') { $where .= ' AND owner_type=? AND owner_id=?'; $params[] = $actor['type']; $params[] = (int)$actor['id']; }
    $bookmark = 'EXISTS(SELECT 1 FROM project_kb_bookmarks b WHERE b.article_id=project_kb_articles.id AND b.actor_type=' . db()->quote($actor['type']) . ' AND b.actor_id=' . (int)$actor['id'] . ')';
    if ($mode === 'bookmarks') $where .= ' AND ' . $bookmark;
    if ($search !== '') { $where .= " AND LOCATE(?,CONCAT_WS(' ',title,tags,body))>0"; $params[] = $search; }
    $q = db()->prepare('SELECT COUNT(*) FROM project_kb_articles WHERE ' . $where); $q->execute($params); $total = (int)$q->fetchColumn();
    $page = min($page,max(1,(int)ceil($total/18)));
    $q = db()->prepare('SELECT id,title,SUBSTRING(body,1,200) AS body,category,status,visibility,updated_at,incoming_hash,' . $bookmark . ' AS bookmarked FROM project_kb_articles WHERE ' . $where . ' ORDER BY updated_at DESC,id DESC LIMIT 18 OFFSET ' . (($page-1)*18));
    $q->execute($params); $articles = $q->fetchAll();
}
include __DIR__ . '/../includes/header.php';
?>
<div class="kb-page">
<?php pk_hero('TOGETHER, WE KNOW MORE / 共创知识库','把经验留下，让下一次更轻松','收藏好方法，分享小发现。每一点积累，都让我们一起走得更远。'); pk_tabs('articles'); ?>
<?php if (!pk_ready()): ?><div class="kb-notice">知识库正在准备，请联系管理员完成迁移。</div><?php endif; ?>
<div class="kb-toolbar"><form class="kb-search" method="get"><label class="sr-only" for="kb-search">搜索知识</label><i class="fas fa-search"></i><input id="kb-search" name="q" value="<?php echo e($search); ?>" placeholder="搜标题、正文或标签…"><input type="hidden" name="view" value="<?php echo e($mode); ?>"><select name="category" aria-label="知识分类"><option value="">所有分类</option><?php foreach(array_keys($categories) as $c): ?><option <?php echo $c === $category ? 'selected' : ''; ?>><?php echo e($c); ?></option><?php endforeach; ?></select><button class="kb-button kb-button-soft">搜索</button></form><a class="kb-button" href="<?php echo BASE_URL; ?>/project/knowledge_article.php?new=1"><i class="fas fa-plus"></i> 写一篇文章</a><a class="kb-button kb-button-soft" href="<?php echo BASE_URL; ?>/project/knowledge_categories.php">添加分类</a></div>
<div class="kb-view-switch"><?php foreach(['all'=>'我可见的知识','mine'=>'我的文章','bookmarks'=>'我的收藏'] as $key=>$label): ?><a class="<?php echo $mode === $key ? 'is-active' : ''; ?>" href="?<?php echo e(http_build_query(['view'=>$key,'q'=>$search,'category'=>$category])); ?>"><?php echo $label; ?></a><?php endforeach; ?><span><?php echo $total; ?> 篇</span></div>
<div class="kb-article-grid"><?php foreach($articles as $a): ?><a class="kb-glass kb-article-card" href="<?php echo BASE_URL; ?>/project/knowledge_article.php?id=<?php echo (int)$a['id']; ?>"><div class="kb-card-top"><span class="kb-pill"><?php echo e($a['category']); ?></span><span><?php echo $a['bookmarked'] ? '★' : '↗'; ?></span></div><h2><?php echo e($a['title']); ?></h2><p><?php echo e(mb_substr(trim($a['body']) ?: '外部文档已读取，等待核对与发布。',0,120)); ?></p><div class="kb-card-meta"><span><?php echo $a['status'] === 'draft' ? '草稿 · ' : ''; echo ['all'=>'全员可见','department'=>'部门可见','private'=>'仅作者与超级管理员'][$a['visibility']]; ?></span><time><?php echo e(substr($a['updated_at'],0,10)); ?></time></div><?php if ($a['incoming_hash']): ?><span class="kb-pending">有外部更新待核对</span><?php endif; ?></a><?php endforeach; ?></div>
<?php if (!$articles): ?><div class="kb-empty kb-glass"><span>✧</span><h2>这里，等着你的第一点积累</h2><p>可以写工作方法、常见问题、交付清单，也可以收藏伙伴分享的经验。</p><a class="kb-button kb-button-soft" href="<?php echo BASE_URL; ?>/project/knowledge_article.php?new=1">写下一个小经验</a></div><?php endif; ?>
<?php if ($total > 18): ?><nav class="kb-pagination" aria-label="知识分页"><?php for($p=1;$p<=ceil($total/18);$p++): ?><a class="<?php echo $p === $page ? 'is-active' : ''; ?>" href="?<?php echo e(http_build_query(['page'=>$p,'q'=>$search,'category'=>$category,'view'=>$mode])); ?>"><?php echo $p; ?></a><?php endfor; ?></nav><?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
