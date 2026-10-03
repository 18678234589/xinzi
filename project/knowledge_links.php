<?php
require_once __DIR__ . '/../includes/ProjectKnowledge.php';
$actor = ps_require_actor(); $ctx = pk_context($actor); $error = ''; $success = '';
$editing = $ctx['super'] || !empty($ctx['managed']);
$recycle = !empty($_GET['recycle']) && $ctx['super'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    try {
        if (!pk_ready()) throw new RuntimeException('知识库尚未准备完成。');
        $action = (string)($_POST['action'] ?? 'save');
        if ($action === 'save') pk_save_link($_POST,$ctx);
        elseif (in_array($action,['delete','restore'],true)) pk_link_state((int)($_POST['id'] ?? 0),$action === 'restore',$ctx);
        else throw new RuntimeException('操作无效。');
        header('Location: ' . BASE_URL . '/project/knowledge_links.php?saved=1' . ($recycle ? '&recycle=1' : '')); exit;
    } catch (Throwable $e) { $error = $e instanceof RuntimeException ? $e->getMessage() : '保存失败，请稍后重试。'; }
}
$links = pk_links($recycle); $categories = [];
foreach($links as $link) $categories[$link['category']] = true;
$page_title = '常用网址 · 知识库';
function pk_link_form($row, $failed = false) {
    $row = array_merge(['id'=>0,'revision'=>0,'title'=>'','url'=>'','keywords'=>'','category'=>'常用工具','description'=>'','sort_order'=>100],$row);
    if ($failed) foreach(['title','url','keywords','category','description','sort_order'] as $k) $row[$k] = $_POST[$k] ?? $row[$k];
    if ($failed) $row['revision'] = (int)($_POST['revision'] ?? 0);
    ?><form method="post" class="kb-link-form" data-no-keywords><input type="hidden" name="csrf" value="<?php echo ps_csrf_token(); ?>"><input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>"><input type="hidden" name="revision" value="<?php echo (int)$row['revision']; ?>"><input type="hidden" name="action" value="save"><div class="kb-form-grid"><label>名称<input name="title" required maxlength="100" value="<?php echo e($row['title']); ?>" placeholder="比如：雅云"></label><label>网址<input name="url" type="url" required maxlength="1000" value="<?php echo e($row['url']); ?>" placeholder="https://…"></label><label>分类<input name="category" required maxlength="60" value="<?php echo e($row['category']); ?>" list="kb-link-categories"></label><label>关联关键词<input name="keywords" maxlength="255" value="<?php echo e($row['keywords']); ?>" placeholder="用逗号分隔；出现时自动链接"></label></div><label>一句话介绍<input name="description" maxlength="255" value="<?php echo e($row['description']); ?>" placeholder="帮伙伴知道，什么时候会用到它"></label><label>排序（小的在前）<input name="sort_order" type="number" min="0" max="9999" value="<?php echo (int)$row['sort_order']; ?>"></label><button class="kb-button"><?php echo $row['id'] ? '保存修改' : '添加到大家的导航'; ?></button></form><?php
}
include __DIR__ . '/../includes/header.php';
?>
<div class="kb-page">
<?php pk_hero('A LITTLE SHORTCUT / 让工作少绕一点路','常用网址，触手可及','把好用的工具放在一起。你添加的一条捷径，也可能帮伙伴省下很多时间。'); pk_tabs('links'); ?>
<?php if ($error): ?><div class="kb-notice kb-notice-error" role="alert"><?php echo e($error); ?></div><?php endif; ?>
<?php if (!empty($_GET['saved'])): ?><div class="kb-notice" role="status">已更新导航，关键词链接将在刷新页面后生效。</div><?php endif; ?>
<div class="kb-toolbar"><div class="kb-search"><i class="fas fa-search"></i><input id="kb-link-search" aria-label="搜索网址" placeholder="搜名称、关键词、介绍或网址…"><select id="kb-link-category" aria-label="网址分类"><option value="">所有分类</option><?php foreach(array_keys($categories) as $c): ?><option><?php echo e($c); ?></option><?php endforeach; ?></select></div><a class="kb-button" href="#kb-add-link" data-kb-open="kb-add-link"><i class="fas fa-plus"></i> 添加网址</a><?php if ($ctx['super']): ?><a class="kb-button kb-button-soft" href="?<?php echo $recycle ? '' : 'recycle=1'; ?>"><?php echo $recycle ? '返回导航' : '回收站'; ?></a><?php endif; ?></div>
<div class="kb-permission-note"><i class="fas fa-heart"></i> 全员可查看和添加 · 主管可编辑 · 超级管理员可删除与恢复<span id="kb-link-count" aria-live="polite"><?php echo count($links); ?> 个网址</span></div>
<div class="kb-link-grid" id="kb-link-grid">
<?php foreach($links as $i=>$link): $host = parse_url($link['url'],PHP_URL_HOST); ?>
<section class="kb-glass kb-link-card" data-category="<?php echo e($link['category']); ?>" data-search="<?php echo e(mb_strtolower(implode(' ',[$link['title'],$link['url'],$link['keywords'],$link['description'],$link['category']]))); ?>" style="--kb-hue:<?php echo ($i*37+145)%360; ?>">
<a class="kb-link-main" href="<?php echo e($link['url']); ?>" target="_blank" rel="noopener noreferrer"><div class="kb-card-top"><span class="kb-site-icon" aria-hidden="true"><?php echo e(mb_substr($link['title'],0,1)); ?></span><span class="kb-link-arrow" aria-hidden="true">↗</span></div><span class="kb-pill"><?php echo e($link['category']); ?></span><h2><?php echo e($link['title']); ?></h2><p><?php echo e($link['description'] ?: '一键到达，开始今天的创造。'); ?></p><span class="kb-domain"><?php echo e($host); ?></span></a>
<?php if ($link['keywords']): ?><div class="kb-keywords" data-no-keywords><?php foreach(pk_keywords($link['keywords']) as $keyword): ?><span><?php echo e($keyword); ?></span><?php endforeach; ?></div><?php endif; ?>
<?php if ($editing && !$recycle): ?><details class="kb-link-edit" <?php echo $error && (int)($_POST['id'] ?? 0) === (int)$link['id'] ? 'open' : ''; ?>><summary>编辑网址 <i class="fas fa-pen"></i></summary><?php pk_link_form($link,$error && (int)($_POST['id'] ?? 0) === (int)$link['id']); ?></details><?php endif; ?>
<?php if ($ctx['super']): ?><form method="post" class="kb-link-delete" <?php echo !$recycle ? 'data-kb-confirm="移到回收站后，对应关键词将不再自动链接。确认吗？"' : ''; ?>><input type="hidden" name="csrf" value="<?php echo ps_csrf_token(); ?>"><input type="hidden" name="id" value="<?php echo (int)$link['id']; ?>"><button class="kb-text-button" name="action" value="<?php echo $recycle ? 'restore' : 'delete'; ?>"><?php echo $recycle ? '恢复到导航' : '移到回收站'; ?></button></form><?php endif; ?>
</section><?php endforeach; ?></div>
<div class="kb-empty kb-glass" id="kb-links-empty" <?php echo $links ? 'hidden' : ''; ?>><span>✧</span><h2>暂时没有匹配的网址</h2><p>换一个关键词试试，也欢迎添加你的常用工具。</p></div>
<details id="kb-add-link" class="kb-glass kb-add-panel" <?php echo $error && empty($_POST['id']) ? 'open' : ''; ?>><summary><span>＋ 添加一条大家都能用的捷径</span><small>点击展开 / 收起</small></summary><p class="kb-muted">请添加可信的 HTTPS 网址。不放账号密码、私人链接或带访问密钥的网址。关键词会在正文、订单备注等非编辑文字中自动关联；不会改动表单内容和计算数据。</p><?php pk_link_form([],$error && empty($_POST['id'])); ?><datalist id="kb-link-categories"><option value="云与域名"><option value="开发与协作"><option value="AI 与创作"><option value="学习与成长"><option value="常用工具"></datalist></details>
</div><?php include __DIR__ . '/../includes/footer.php'; ?>
