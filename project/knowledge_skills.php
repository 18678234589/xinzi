<?php
require_once __DIR__ . '/../includes/ProjectKnowledgeSkills.php';
// 沟通技巧库列表：本人角色的内容优先，其次本人业务，再按质量分和更新时间。
$actor = ps_require_actor(); $ctx = pk_context($actor);
$page_title = '沟通技巧库 · 知识库';
$editor = pks_is_editor($ctx);
$myRoles = pks_actor_roles($actor);
$myBusinesses = pks_actor_businesses($actor);
$kind = (string)($_GET['kind'] ?? ''); if (!isset(PKS_KINDS[$kind])) $kind = '';
$status = (string)($_GET['status'] ?? ''); if (!isset(PKS_STATUS[$status])) $status = '';
$business = (string)($_GET['business'] ?? '');
$onlyMine = !empty($_GET['mine_role']);
$mySubmissions = !empty($_GET['mine']);
$search = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$page = max(1, (int)($_GET['page'] ?? 1));
$rows = []; $total = 0; $ready = true; $counts = []; $pending = 0;
try {
    [$rows, $total] = pks_list($ctx, ['kind' => $kind, 'status' => $status, 'business' => $business, 'q' => $search, 'role' => $onlyMine ? $myRoles : [], 'mine' => $mySubmissions, 'my_roles' => $myRoles, 'my_businesses' => $myBusinesses], $page, 24);
    $counts = pks_counts($ctx);
    if ($editor) $pending = (int)db()->query("SELECT COUNT(*) FROM project_kb_skills WHERE deleted_at IS NULL AND status='pending'")->fetchColumn();
} catch (Throwable $e) { $ready = false; }
$businessNames = array_keys(ps_business_catalog());
$query = function (array $over = []) use ($kind, $status, $business, $onlyMine, $mySubmissions, $search) { return '?' . http_build_query(array_filter(array_merge(['kind' => $kind, 'status' => $status, 'business' => $business, 'mine_role' => $onlyMine ? 1 : '', 'mine' => $mySubmissions ? 1 : '', 'q' => $search], $over), 'strlen')); };
include __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?php echo pks_asset('css/knowledge_skills.css'); ?>">
<div class="kb-page kbx" data-no-keywords>
<?php pk_hero('SAY IT BETTER / 把好话术留下来', '沟通技巧库', '沟通技巧、业务知识、精选聊天记录——先看到你自己角色的内容，也为后续 AI 客服学习打好基础。'); pk_tabs('skills'); ?>
<?php if (!$ready): ?><div class="kbx-note err" role="alert">沟通技巧库暂时无法使用，请联系管理员。</div><?php endif; ?>
<?php if (!empty($_GET['imported'])): ?><div class="kbx-note" role="status">已导入 <strong><?php echo (int)$_GET['imported']; ?></strong> 段对话。<?php echo $editor ? '' : '它们在“待审核”中（列表里你能看到自己提交的内容），主管采纳后 AI 才会学习。'; ?></div><?php endif; ?>

<?php pks_rolebar($myRoles, $actor); ?>
<div class="kbx-tiles">
  <?php foreach (PKS_KINDS as $k => $label): ?>
  <a class="kbx-tile k-<?php echo $k; ?><?php echo $kind === $k ? ' is-active' : ''; ?>" href="<?php echo e($query(['kind' => $kind === $k ? '' : $k, 'page' => ''])); ?>" aria-pressed="<?php echo $kind === $k ? 'true' : 'false'; ?>">
    <span class="ic"><i class="fas <?php echo PKS_KIND_ICONS[$k]; ?>"></i></span><span><b class="num"><?php echo (int)($counts[$k] ?? 0); ?></b><span class="l"><?php echo e($label); ?></span></span>
  </a>
  <?php endforeach; ?>
</div>

<form class="kbx-bar" method="get" role="search">
  <input type="hidden" name="kind" value="<?php echo e($kind); ?>">
  <?php if ($mySubmissions): ?><input type="hidden" name="mine" value="1"><?php endif; ?>
  <label class="kbx-search"><i class="fas fa-search"></i><span style="position:absolute;left:-9999px">搜索</span><input name="q" value="<?php echo e($search); ?>" placeholder="搜标题、场景、话术、对话…" autocomplete="off"><kbd>/</kbd></label>
  <select name="business" aria-label="业务" onchange="this.form.submit()"><option value="">全部业务</option><?php foreach ($businessNames as $b): ?><option <?php echo $business === $b ? 'selected' : ''; ?>><?php echo e($b); ?></option><?php endforeach; ?></select>
  <?php if ($editor): ?><select name="status" aria-label="状态" onchange="this.form.submit()"><option value="">全部状态</option><?php foreach (PKS_STATUS as $k => $label): ?><option value="<?php echo $k; ?>" <?php echo $status === $k ? 'selected' : ''; ?>><?php echo e($label); ?><?php echo $k === 'pending' && $pending ? '（' . $pending . '）' : ''; ?></option><?php endforeach; ?></select><?php endif; ?>
  <?php if ($myRoles): ?><label class="kbx-check"><input type="checkbox" name="mine_role" value="1" <?php echo $onlyMine ? 'checked' : ''; ?> onchange="this.form.submit()"> 只看我的角色</label><?php endif; ?>
  <a class="kbx-btn ghost" href="<?php echo e($query(['mine' => $mySubmissions ? '' : 1, 'page' => ''])); ?>"<?php echo $mySubmissions ? ' aria-current="page"' : ''; ?>><i class="fas fa-user-edit"></i> <?php echo $mySubmissions ? '查看全部' : '我提交的'; ?></a>
  <span style="flex:1"></span>
  <a class="kbx-btn" href="<?php echo BASE_URL; ?>/project/knowledge_skill.php?new=skill"><i class="fas fa-plus"></i> 新增</a>
  <a class="kbx-btn ghost" href="<?php echo BASE_URL; ?>/project/knowledge_skill_import.php"><i class="fas fa-magic"></i> 导入聊天记录</a>
  <?php if ($editor): ?><a class="kbx-btn ghost" href="<?php echo BASE_URL; ?>/project/knowledge_skills_export.php" title="已采纳内容的结构化数据"><i class="fas fa-robot"></i> AI 数据</a><?php endif; ?>
</form>

<?php $lastMatch = null; ?>
<?php if ($rows): ?><div class="kbx-grid"><?php endif; ?>
<?php foreach ($rows as $r): ?>
  <?php if ($myRoles && $lastMatch === 1 && (int)$r['role_match'] === 0 && !$onlyMine): ?></div><div class="kbx-divider">其他角色的内容</div><div class="kbx-grid"><?php endif; $lastMatch = (int)$r['role_match']; ?>
  <article class="kbx-card k-<?php echo e($r['kind']); ?><?php echo $r['role_match'] ? ' is-me' : ''; ?>">
    <div class="head"><span class="kbx-kind"><i class="fas <?php echo PKS_KIND_ICONS[$r['kind']]; ?>"></i> <?php echo e(PKS_KINDS[$r['kind']]); ?></span><span class="kbx-stars" title="质量分 <?php echo (int)$r['quality']; ?>/5"><?php echo str_repeat('★', (int)$r['quality']); ?></span></div>
    <h3><a href="<?php echo BASE_URL; ?>/project/knowledge_skill.php?id=<?php echo (int)$r['id']; ?>"><?php echo e($r['title']); ?></a></h3>
    <?php if ($r['scenario'] !== ''): ?><p class="sc"><i class="far fa-lightbulb"></i> <?php echo e($r['scenario']); ?></p><?php endif; ?>
    <p class="pv"><?php echo e($r['preview']); ?></p>
    <div class="kbx-tags"><?php if ($r['role_match']): ?><span class="kbx-tag me">适合你</span><?php endif; foreach (pks_role_labels($r['roles']) as $label): ?><span class="kbx-tag"><?php echo e($label); ?></span><?php endforeach; if ($r['business'] !== ''): ?><span class="kbx-tag"><?php echo e($r['business']); ?></span><?php endif; if ($r['status'] !== 'approved'): ?><span class="kbx-tag warn"><?php echo e(explode('（', PKS_STATUS[$r['status']])[0]); ?></span><?php endif; ?></div>
    <div class="foot"><span><?php echo e($r['owner_name']); ?> · <?php echo e(substr($r['updated_at'], 0, 10)); ?></span><?php if ($r['copy_text'] !== ''): ?><button type="button" class="kbx-btn ghost sm kbx-copy" data-copy="<?php echo e($r['copy_text']); ?>"><i class="far fa-copy"></i> 复制话术</button><?php endif; ?></div>
  </article>
<?php endforeach; ?>
<?php if ($rows): ?></div><?php endif; ?>
<?php if (!$rows && $ready): $filtered = ($search !== '' || $kind !== '' || $business !== '' || $mySubmissions); ?>
  <div class="kbx-empty"><span class="ic"><i class="far fa-comments"></i></span><h2><?php echo $filtered ? '没有找到符合条件的内容' : '这里还没有内容'; ?></h2>
  <p><?php echo $filtered ? '换个关键词，或取消筛选试试。' : '把你觉得好用的话术、业务口径，或一段漂亮的聊天记录放进来，让大家和 AI 客服都能学到。'; ?></p>
  <div class="kbx-actions"><a class="kbx-btn" href="<?php echo BASE_URL; ?>/project/knowledge_skill_import.php"><i class="fas fa-magic"></i> 导入我的精选聊天记录</a><a class="kbx-btn ghost" href="<?php echo BASE_URL; ?>/project/knowledge_skill.php?new=skill">写一条沟通技巧</a></div></div>
<?php endif; ?>
<?php if ($total > 24): ?><nav class="kb-pagination" aria-label="分页"><?php for ($p = 1; $p <= ceil($total / 24); $p++): ?><a class="<?php echo $p === $page ? 'is-active' : ''; ?>" href="<?php echo e($query(['page' => $p])); ?>"><?php echo $p; ?></a><?php endfor; ?></nav><?php endif; ?>
</div>
<script src="<?php echo pks_asset('js/knowledge_skills.js'); ?>"></script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
