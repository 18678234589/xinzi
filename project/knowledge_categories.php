<?php
require_once __DIR__ . '/../includes/ProjectKnowledge.php';
$actor = ps_require_actor(); $ctx = pk_context($actor); $error = ''; $created = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    try {
        $created = pk_create_category($_POST['name'] ?? '',$ctx);
        header('Location: ' . BASE_URL . '/project/knowledge_categories.php?created=' . rawurlencode($created)); exit;
    } catch (Throwable $e) { $error = $e instanceof RuntimeException ? $e->getMessage() : '分类保存失败，请稍后重试。'; }
}
$categories = pk_categories();
$page_title = '分类库 · 知识库';
include __DIR__ . '/../includes/header.php';
?>
<div class="kb-page">
<?php pk_hero('A PLACE FOR EVERY IDEA / 给经验一个位置','分类清楚，查找更轻松','为工具和经验起个好找的名字。一次创建，文章与网址都能直接选用。'); pk_tabs('categories'); ?>
<?php if ($error): ?><div class="kb-notice kb-notice-error" role="alert"><?php echo e($error); ?></div><?php endif; ?>
<?php if (isset($_GET['created'])): ?><div class="kb-notice" role="status">“<?php echo e(mb_substr((string)$_GET['created'],0,60)); ?>”已准备好，添加文章或网址时可直接选择。</div><?php endif; ?>
<section class="kb-glass kb-category-library" data-no-keywords>
<div class="kb-category-heading"><div><h2>共享分类库</h2><p class="kb-muted">所有伙伴都可以创建。分类名称全员可见，不会改变文章的可见范围。</p></div><span class="kb-pill"><?php echo count($categories); ?> 个分类</span></div>
<form method="post" class="kb-category-create"><input type="hidden" name="csrf" value="<?php echo ps_csrf_token(); ?>"><label for="kb-new-category">添加一个分类<input id="kb-new-category" name="name" required maxlength="60" value="<?php echo $error ? e($_POST['name'] ?? '') : ''; ?>" placeholder="例如：设计灵感、售后经验"></label><button class="kb-button"><i class="fas fa-plus"></i> 创建分类</button></form>
<p class="kb-muted">重名分类自动复用，不会重复创建。</p>
<div class="kb-category-chips"><?php foreach($categories as $category): ?><span><i class="far fa-folder"></i> <?php echo e($category); ?></span><?php endforeach; ?></div>
<div class="kb-actions"><a class="kb-button" href="<?php echo BASE_URL; ?>/project/knowledge_links.php#kb-add-link" data-kb-open="kb-add-link">添加网址</a><a class="kb-button kb-button-soft" href="<?php echo BASE_URL; ?>/project/knowledge_article.php">写一篇文章</a></div>
</section></div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
