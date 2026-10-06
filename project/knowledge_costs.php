<?php
require_once __DIR__ . '/../includes/ProjectCostRequests.php';
// 成本速查：用户默认只看到自己角色（及负责业务）相关的成本；成本不对可申请更改，由部门主管 / 专属财务 / 超级管理员审核。
$actor = ps_require_actor(); $ctx = pk_context($actor);
pcr_ensure();
$canTag = pcr_can_tag($actor, $ctx);
$canReview = pcr_can_review($actor, $ctx);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = pks_json_body();
    if ($body === null) pks_json_reply(['error' => '请求格式不正确，请刷新页面后重试'], 400);
    if (!hash_equals(ps_csrf_token(), (string)($body['csrf'] ?? ''))) pks_json_reply(['error' => '页面已过期，请刷新后重试'], 403);
    try {
        $action = (string)($body['action'] ?? '');
        if ($action === 'request') {
            pks_json_reply(['id' => pcr_submit((int)($body['template_id'] ?? 0), $body['proposed_price'] ?? '', $body['proposed_note'] ?? '', $body['reason'] ?? '', $actor)]);
        } elseif ($action === 'tags') {
            pcr_set_tags((int)($body['template_id'] ?? 0), (array)($body['roles'] ?? []), $actor, $ctx);
            pks_json_reply(['ok' => true]);
        } elseif ($action === 'bulk_tag') {
            pks_json_reply(['count' => pcr_bulk_tag((array)($body['ids'] ?? []), (string)($body['role'] ?? ''), (string)($body['mode'] ?? 'add'), $actor, $ctx)]);
        } elseif ($action === 'handle') {
            pcr_handle((int)($body['id'] ?? 0), (string)($body['decision'] ?? ''), (string)($body['note'] ?? ''), $actor, $ctx);
            pks_json_reply(['ok' => true]);
        }
        pks_json_reply(['error' => '操作无效'], 400);
    } catch (RuntimeException $e) {
        pks_json_reply(['error' => $e->getMessage()], 400);
    } catch (Throwable $e) {
        error_log('knowledge_costs: ' . $e->getMessage());
        pks_json_reply(['error' => '处理失败，请稍后重试'], 500);
    }
}

$myRoles = pks_actor_roles($actor);
$myBusinesses = pks_actor_businesses($actor);
$scope = (($_GET['scope'] ?? '') === 'all') ? 'all' : 'mine';
$search = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$category = (string)($_GET['category'] ?? ''); if (!isset(PCR_CATEGORIES[$category])) $category = '';
$rows = pcr_templates($scope, $myRoles, $myBusinesses, $search, $category);
$mine = pcr_my_requests($actor);
$queue = $canReview ? pcr_requests('pending', $actor, $ctx) : [];
$statusLabel = ['pending' => '待审核', 'resolved' => '已采纳', 'rejected' => '未采纳'];
$money = function ($row) { return $row['price_mode'] === 'percent' ? rtrim(rtrim(number_format((float)$row['price'], 2), '0'), '.') . '%' : '¥' . number_format((float)$row['price'], 2); };
$query = function (array $over = []) use ($scope, $search, $category) { return '?' . http_build_query(array_filter(array_merge(['scope' => $scope === 'all' ? 'all' : '', 'q' => $search, 'category' => $category], $over), 'strlen')); };
$page_title = '成本速查 · 知识库';
include __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?php echo pks_asset('css/knowledge_skills.css'); ?>">
<div class="kb-page kbx kbx-form" data-no-keywords>
<?php pk_hero('KNOW YOUR COSTS / 心里有数', '成本速查', '角色相关的成本都贴了标签，打开就能看到自己要用的那几项；哪里不对，直接提申请。'); pk_tabs('costs'); ?>

<?php pks_rolebar($myRoles, $actor); ?>
<?php if ($myBusinesses): ?><div class="kbx-rolebar" style="margin-top:0"><span>负责业务：<strong><?php echo e(implode('、', $myBusinesses)); ?></strong></span></div><?php endif; ?>

<?php if ($canReview && $queue): ?>
<section class="kbx-panel" id="review" style="margin:1rem 0"><h2 style="margin-top:0">待审核的成本更改申请 <span class="kbx-tag warn num"><?php echo count($queue); ?></span></h2>
<?php foreach ($queue as $r): ?>
  <div class="kbx-req" id="req-<?php echo (int)$r['id']; ?>" data-id="<?php echo (int)$r['id']; ?>">
    <strong><?php echo e($r['template_name']); ?></strong> <span class="kb-muted"><?php echo e($r['template_spec']); ?><?php echo $r['business_scope'] !== '' ? ' · ' . e($r['business_scope']) : ''; ?></span>
    <div class="num">现价 ¥<?php echo number_format((float)$r['current_price'], 2); ?><?php echo $r['proposed_price'] !== null ? ' → 建议 <strong>¥' . number_format((float)$r['proposed_price'], 2) . '</strong>' : ''; ?><?php echo $r['proposed_note'] !== '' ? ' · ' . e($r['proposed_note']) : ''; ?></div>
    <div class="meta">申请人：<?php echo e($r['applicant_name']); ?> · <?php echo e(substr($r['created_at'], 0, 16)); ?></div>
    <div style="white-space:pre-wrap;margin:.3rem 0">理由：<?php echo e($r['reason']); ?></div>
    <textarea class="js-note" rows="2" placeholder="处理说明（不采纳时必填）"></textarea>
    <div class="kbx-actions" style="margin-top:.5rem"><button type="button" class="kbx-btn sm js-decide" data-d="resolved">采纳</button><button type="button" class="kbx-btn ghost sm js-decide" data-d="rejected">不采纳</button><span class="kbx-hint js-msg" role="status"></span></div>
  </div>
<?php endforeach; ?>
<p class="kbx-hint" style="margin:.4rem 0 0">“采纳”只记录结论并回复申请人；实际价格请财务在“成本中心”按新版本修改，历史订单不受影响。</p></section>
<?php endif; ?>

<div class="kbx-chips" role="group" aria-label="成本分类">
  <a class="kbx-pill<?php echo $category === '' ? ' is-on' : ''; ?>" href="<?php echo e($query(['category' => ''])); ?>">全部分类</a>
  <?php foreach (PCR_CATEGORIES as $k => $label): ?><a class="kbx-pill<?php echo $category === $k ? ' is-on' : ''; ?>" href="<?php echo e($query(['category' => $k])); ?>"><?php echo e($label); ?></a><?php endforeach; ?>
</div>
<form class="kbx-bar" method="get" role="search" style="position:static">
  <input type="hidden" name="category" value="<?php echo e($category); ?>">
  <label class="kbx-search"><i class="fas fa-search"></i><span style="position:absolute;left:-9999px">搜索</span><input name="q" value="<?php echo e($search); ?>" placeholder="搜成本名称、规格、业务…" autocomplete="off"><kbd>/</kbd></label>
  <select name="scope" aria-label="范围" onchange="this.form.submit()"><option value="mine" <?php echo $scope === 'mine' ? 'selected' : ''; ?>>只看和我相关</option><option value="all" <?php echo $scope === 'all' ? 'selected' : ''; ?>>全部成本</option></select>
  <span class="kb-muted num">共 <?php echo count($rows); ?> 项</span>
  <span style="flex:1"></span>
  <?php if ($canTag): ?><span class="kbx-hint">批量打标签：</span><select id="bulkRole" aria-label="角色"><?php foreach (PKS_ROLES as $k => $label): ?><option value="<?php echo $k; ?>"><?php echo e($label); ?></option><?php endforeach; ?></select>
  <button type="button" class="kbx-btn ghost sm" data-bulk="add">添加</button><button type="button" class="kbx-btn ghost sm" data-bulk="remove">移除</button><span class="kbx-hint" id="bulkMsg" role="status"></span><?php endif; ?>
</form>

<div class="kbx-tablewrap"><table class="kbx-table">
  <thead><tr><?php if ($canTag): ?><th><input type="checkbox" id="checkAll" aria-label="全选"></th><?php endif; ?><th>分类</th><th>业务</th><th>成本项</th><th>规格</th><th style="text-align:right">成本价</th><th>角色标签</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr class="<?php echo $r['relation'] === 'role' ? 'is-role' : ($r['relation'] === 'business' ? 'is-business' : ''); ?>" data-id="<?php echo (int)$r['id']; ?>" data-name="<?php echo e($r['name'] . ' · ' . $r['specification']); ?>" data-price="<?php echo e($money($r)); ?>">
      <?php if ($canTag): ?><td><input type="checkbox" class="js-pick" value="<?php echo (int)$r['id']; ?>" aria-label="选择"></td><?php endif; ?>
      <td><span class="kbx-cat"><?php echo e(PCR_CATEGORIES[$r['category']] ?? $r['category']); ?></span></td>
      <td><?php echo $r['business_scope'] !== '' ? e($r['business_scope']) : '<span class="kb-muted">通用</span>'; ?></td>
      <td><strong><?php echo e($r['name']); ?></strong><?php if ($r['relation'] === 'role'): ?> <span class="kbx-tag me">你的角色</span><?php elseif ($r['relation'] === 'business'): ?> <span class="kbx-tag">你的业务</span><?php endif; ?></td>
      <td><?php echo e($r['specification']); ?></td>
      <td class="kbx-price num"><?php echo e($money($r)); ?><small><?php echo e(PCR_KINDS[$r['cost_kind']] ?? ''); ?> / <?php echo e($r['unit']); ?></small></td>
      <td><?php if ($canTag): foreach (PKS_ROLES as $k => $label): ?><label class="kbx-tagtoggle"><input type="checkbox" class="js-tag" value="<?php echo $k; ?>" <?php echo in_array($k, $r['tags'], true) ? 'checked' : ''; ?>> <?php echo e($label); ?></label><?php endforeach; ?><button type="button" class="kbx-btn ghost sm js-savetags">保存</button><?php else: foreach ($r['tags'] as $t): ?><span class="kbx-tag"><?php echo e(PKS_ROLES[$t] ?? $t); ?></span> <?php endforeach; if (!$r['tags']): ?><span class="kb-muted" style="font-size:.78rem">未分角色</span><?php endif; endif; ?></td>
      <td style="text-align:right"><button type="button" class="kbx-btn ghost sm js-req" data-drawer-open="#reqDrawer">成本不对？</button></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8"><div class="kbx-empty" style="border:0;background:none"><span class="ic"><i class="fas fa-coins"></i></span><h2>没有符合条件的成本</h2><p><?php echo $scope === 'mine' ? '可以切换到“全部成本”看看，或请专属财务给你的角色打上成本标签。' : '换个关键词或分类试试。'; ?></p></div></td></tr><?php endif; ?>
  </tbody></table></div>

<section class="kbx-panel" id="mine" style="margin-top:1.2rem"><h2 style="margin-top:0">我提交的申请</h2>
<?php foreach ($mine as $r): ?>
  <div class="kbx-req"><strong><?php echo e($r['template_name']); ?></strong> <span class="kb-muted"><?php echo e($r['template_spec']); ?></span> <span class="kbx-tag<?php echo $r['status'] === 'pending' ? ' warn' : ''; ?>"><?php echo e($statusLabel[$r['status']]); ?></span>
    <div class="num">现价 ¥<?php echo number_format((float)$r['current_price'], 2); ?><?php echo $r['proposed_price'] !== null ? ' → 建议 ¥' . number_format((float)$r['proposed_price'], 2) : ''; ?> <span class="meta">· <?php echo e(substr($r['created_at'], 0, 16)); ?></span></div>
    <div style="white-space:pre-wrap">理由：<?php echo e($r['reason']); ?></div>
    <?php if ($r['status'] !== 'pending'): ?><div style="white-space:pre-wrap;margin-top:.3rem;color:#3d6d60">处理回复（<?php echo e($r['handler_name']); ?>）：<?php echo e($r['handle_note'] ?: '已采纳'); ?></div><?php endif; ?>
  </div>
<?php endforeach; ?>
<?php if (!$mine): ?><p class="kb-muted" style="margin:0">还没有提交过申请。哪项成本不对，点上面表格里的“成本不对？”就行。</p><?php endif; ?></section>
</div>

<div class="kbx-drawer-mask"></div>
<aside class="kbx-drawer kbx kbx-form" id="reqDrawer" aria-hidden="true" aria-label="申请更改成本">
  <h2>申请更改成本</h2>
  <div class="kbx-target" id="reqTarget"></div>
  <input type="hidden" id="reqTemplate">
  <label class="l" for="reqPrice">你认为正确的金额<span class="kbx-hint">可选</span></label><input type="number" step="0.01" min="0" id="reqPrice" placeholder="例如 180">
  <label class="l" for="reqNote">补充<span class="kbx-hint">规格、供应商等，可选</span></label><input type="text" id="reqNote" maxlength="255">
  <label class="l" for="reqReason">为什么不对？</label><textarea id="reqReason" rows="4" maxlength="2000" placeholder="例如：供应商 10 月起涨价到 xx，或这个规格实际不是这个价"></textarea>
  <p class="kbx-hint" style="margin:.7rem 0">申请会通知你的部门主管，并出现在专属财务和超级管理员的待办里，任意一方审核即可。</p>
  <div class="kbx-msgline" id="reqMsg" role="status"></div>
  <div class="kbx-actions"><button type="button" class="kbx-btn" id="reqSubmit">提交申请</button><button type="button" class="kbx-btn ghost" data-drawer-close>取消</button></div>
</aside>
<script>
<?php echo pks_post_script(); ?>
document.addEventListener('DOMContentLoaded', function () {
  var csrf = <?php echo json_encode(ps_csrf_token()); ?>;
  var $ = function (s) { return document.getElementById(s); };
  document.querySelectorAll('.js-req').forEach(function (b) { b.addEventListener('click', function () {
    var tr = b.closest('tr'); $('reqTemplate').value = tr.dataset.id; $('reqTarget').innerHTML = '<strong></strong><br>现价：<b class="num"></b>';
    $('reqTarget').querySelector('strong').textContent = tr.dataset.name; $('reqTarget').querySelector('b').textContent = tr.dataset.price;
    $('reqPrice').value = ''; $('reqNote').value = ''; $('reqReason').value = ''; $('reqMsg').textContent = '';
  }); });
  $('reqSubmit').addEventListener('click', function () {
    var b = this; b.disabled = true; $('reqMsg').textContent = '提交中…';
    pksPost({csrf: csrf, action: 'request', template_id: $('reqTemplate').value, proposed_price: $('reqPrice').value, proposed_note: $('reqNote').value, reason: $('reqReason').value})
      .then(function () { kbxDrawer($('reqDrawer'), false); kbxToast('已提交，主管和财务会收到通知'); setTimeout(function () { location.hash = 'mine'; location.reload(); }, 900); })
      .catch(function (e) { $('reqMsg').textContent = e.message; b.disabled = false; });
  });
  document.querySelectorAll('.js-savetags').forEach(function (b) { b.addEventListener('click', function () {
    var tr = b.closest('tr'), roles = Array.prototype.map.call(tr.querySelectorAll('.js-tag:checked'), function (c) { return c.value; });
    b.disabled = true; pksPost({csrf: csrf, action: 'tags', template_id: tr.dataset.id, roles: roles}).then(function () { kbxToast('标签已保存'); b.disabled = false; }).catch(function (e) { alert(e.message); b.disabled = false; });
  }); });
  var all = $('checkAll'); if (all) all.addEventListener('change', function () { document.querySelectorAll('.js-pick').forEach(function (c) { c.checked = all.checked; }); });
  document.querySelectorAll('[data-bulk]').forEach(function (b) { b.addEventListener('click', function () {
    var ids = Array.prototype.map.call(document.querySelectorAll('.js-pick:checked'), function (c) { return c.value; });
    if (!ids.length) { $('bulkMsg').textContent = '请先勾选成本'; return; }
    pksPost({csrf: csrf, action: 'bulk_tag', ids: ids, role: $('bulkRole').value, mode: b.dataset.bulk}).then(function (d) { $('bulkMsg').textContent = '已处理 ' + d.count + ' 项，刷新中…'; location.reload(); }).catch(function (e) { $('bulkMsg').textContent = e.message; });
  }); });
  document.querySelectorAll('.js-decide').forEach(function (b) { b.addEventListener('click', function () {
    var box = b.closest('.kbx-req'), msg = box.querySelector('.js-msg'); b.disabled = true;
    pksPost({csrf: csrf, action: 'handle', id: box.dataset.id, decision: b.dataset.d, note: box.querySelector('.js-note').value}).then(function () { location.reload(); }).catch(function (e) { msg.textContent = e.message; b.disabled = false; });
  }); });
});
</script>
<script src="<?php echo pks_asset('js/knowledge_skills.js'); ?>"></script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
