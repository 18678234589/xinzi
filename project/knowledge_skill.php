<?php
require_once __DIR__ . '/../includes/ProjectKnowledgeSkills.php';
// 沟通技巧库：查看 / 编辑单条。长文字一律用 JSON 提交（避开网站防火墙对长表单字段的拦截）。
$actor = ps_require_actor(); $ctx = pk_context($actor);
pks_ensure();
$editor = pks_is_editor($ctx);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = pks_json_body();
    if ($body === null) pks_json_reply(['error' => '请求格式不正确，请刷新页面后重试'], 400);
    if (!hash_equals(ps_csrf_token(), (string)($body['csrf'] ?? ''))) pks_json_reply(['error' => '页面已过期，请刷新后重试'], 403);
    try {
        $action = (string)($body['action'] ?? '');
        if ($action === 'save') {
            $id = pks_save($body, $ctx);
            pks_json_reply(['id' => $id]);
        } elseif ($action === 'ai_fill') {
            pks_json_reply(['fields' => pks_ai_fill((string)($body['kind'] ?? ''), (string)($body['raw'] ?? ''))]);
        } elseif ($action === 'status') {
            pks_set_status((int)($body['id'] ?? 0), (string)($body['status'] ?? ''), $ctx);
            pks_json_reply(['ok' => true]);
        } elseif ($action === 'delete') {
            pks_delete((int)($body['id'] ?? 0), $ctx);
            pks_json_reply(['ok' => true]);
        }
        pks_json_reply(['error' => '操作无效'], 400);
    } catch (RuntimeException $e) {
        pks_json_reply(['error' => $e->getMessage()], 400);
    } catch (Throwable $e) {
        error_log('knowledge_skill: ' . $e->getMessage());
        pks_json_reply(['error' => '保存失败，请稍后重试'], 500);
    }
}

$id = (int)($_GET['id'] ?? 0);
$error = '';
$row = null;
if ($id) {
    try { $row = pks_row($id, $ctx); } catch (RuntimeException $e) { $error = $e->getMessage(); }
}
$newKind = (string)($_GET['new'] ?? 'skill'); if (!isset(PKS_KINDS[$newKind])) $newKind = 'skill';
$canEdit = $row ? pks_can_edit($row, $ctx) : true;
$editing = !$row ? ($error === '') : ($canEdit && !empty($_GET['edit']));
$form = $row ?: ['id' => 0, 'kind' => $newKind, 'title' => '', 'roles' => implode(',', pks_actor_roles($actor)), 'business' => '', 'scenario' => '', 'customer_says' => '', 'reply' => '', 'avoid' => '', 'content' => '', 'chat_log' => '', 'tags' => '', 'quality' => 3, 'status' => 'pending', 'revision' => 0];
$businessNames = array_keys(ps_business_catalog());
$formRoles = $form['roles'] === '' ? [] : explode(',', $form['roles']);
$page_title = ($row ? $row['title'] : '新增内容') . ' · 沟通技巧库';
include __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?php echo pks_asset('css/knowledge_skills.css'); ?>">
<div class="kb-page kbx" data-no-keywords>
<?php pk_tabs('skills'); ?><a class="kb-back" href="<?php echo BASE_URL; ?>/project/knowledge_skills.php">← 回到沟通技巧库</a>
<?php if ($error): ?><div class="kbx-note err" role="alert"><?php echo e($error); ?></div><?php endif; ?>

<?php if ($editing): ?>
<form class="kbx-panel kbx-form k-<?php echo e($form['kind']); ?>" id="kbsForm" onsubmit="return false" style="max-width:860px">
  <h1><?php echo $row ? '编辑内容' : '新增内容'; ?></h1>
  <p class="kb-muted">手机号、邮箱、身份证、银行卡号会在保存和交给 AI 之前自动打码。<?php echo $editor ? '你保存后可直接选择“已采纳”。' : '提交后由主管审核，采纳后 AI 才会学习。'; ?></p>
  <label class="l">内容类型</label>
  <div class="kbx-seg" id="kindSeg"><?php foreach (PKS_KINDS as $k => $label): ?><label class="k-<?php echo $k; ?>"><input type="radio" name="kind" value="<?php echo $k; ?>" <?php echo $form['kind'] === $k ? 'checked' : ''; ?>><span><i class="fas <?php echo PKS_KIND_ICONS[$k]; ?>"></i> <?php echo e($label); ?></span></label><?php endforeach; ?></div>

  <div class="kbx-ai"><strong><i class="fas fa-magic"></i> 用 AI 帮我整理</strong><span class="kbx-hint">粘贴一段话、笔记或聊天记录，AI 会按所选类型填好下面的栏目，你再核对修改。</span>
    <textarea id="f-raw" rows="4" placeholder="粘贴原始文字…"></textarea><button type="button" class="kbx-btn ghost sm" id="kbsAiBtn">AI 整理</button> <span class="kbx-hint" id="kbsAiNote"></span></div>

  <label class="l" for="f-title">标题</label><input type="text" id="f-title" maxlength="200" value="<?php echo e($form['title']); ?>" placeholder="比如：客户嫌贵时，先肯定再给对比">
  <div class="kbx-row2">
    <div><label class="l">适用角色<span class="kbx-hint">不选 = 全部角色，选中的优先看到</span></label><div><?php foreach (PKS_ROLES as $k => $label): ?><label class="kbx-check"><input type="checkbox" class="f-role" value="<?php echo $k; ?>" <?php echo in_array($k, $formRoles, true) ? 'checked' : ''; ?>> <?php echo e($label); ?></label><?php endforeach; ?></div></div>
    <div><label class="l" for="f-business">所属业务<span class="kbx-hint">不选 = 通用</span></label><select id="f-business"><option value="">通用</option><?php foreach ($businessNames as $b): ?><option <?php echo $form['business'] === $b ? 'selected' : ''; ?>><?php echo e($b); ?></option><?php endforeach; ?></select></div>
  </div>
  <div data-for="skill chat"><label class="l" for="f-scenario">场景 / 客户意图<span class="kbx-hint">例如：客户嫌价格高、客户问多久能做好</span></label><input type="text" id="f-scenario" maxlength="255" value="<?php echo e($form['scenario']); ?>"></div>
  <div data-for="skill business"><label class="l" for="f-says">客户常见说法<span class="kbx-hint">每行一句，AI 用它们来匹配客户的问题</span></label><textarea id="f-says" rows="3" maxlength="4000"><?php echo e($form['customer_says']); ?></textarea></div>
  <div data-for="skill business"><label class="l" for="f-reply">推荐话术<span class="kbx-hint">口语化、能直接发给客户；多条话术之间空一行</span></label><textarea id="f-reply" rows="6" maxlength="8000"><?php echo e($form['reply']); ?></textarea></div>
  <div data-for="skill"><label class="l" for="f-avoid">禁忌 / 反面示例<span class="kbx-hint">每行一条：不要这么说，以及原因</span></label><textarea id="f-avoid" rows="3" maxlength="4000"><?php echo e($form['avoid']); ?></textarea></div>
  <div data-for="chat"><label class="l" for="f-log">对话原文<span class="kbx-hint">每句一行，以“客户：”或“客服：”开头</span></label><textarea id="f-log" rows="12" maxlength="60000"><?php echo e($form['chat_log']); ?></textarea></div>
  <div><label class="l" for="f-content" id="f-content-label">要点说明</label><textarea id="f-content" rows="7" maxlength="60000"><?php echo e($form['content']); ?></textarea></div>
  <label class="l" for="f-tags">标签<span class="kbx-hint">逗号分隔，如：价格异议,成交</span></label><input type="text" id="f-tags" maxlength="255" value="<?php echo e($form['tags']); ?>">
  <?php if ($editor): ?><div class="kbx-row2"><div><label class="l" for="f-quality">质量分（影响排序）</label><select id="f-quality"><?php for ($i = 5; $i >= 1; $i--): ?><option value="<?php echo $i; ?>" <?php echo (int)$form['quality'] === $i ? 'selected' : ''; ?>><?php echo str_repeat('★', $i); ?></option><?php endfor; ?></select></div>
  <div><label class="l" for="f-status">状态</label><select id="f-status"><?php foreach (PKS_STATUS as $k => $label): ?><option value="<?php echo $k; ?>" <?php echo $form['status'] === $k ? 'selected' : ''; ?>><?php echo e($label); ?></option><?php endforeach; ?></select></div></div><?php endif; ?>
  <div class="kbx-msgline" id="kbsMsg" role="status"></div>
  <div class="kbx-actions"><button type="button" class="kbx-btn" id="kbsSave"><i class="fas fa-check"></i> 保存</button><a class="kbx-btn ghost" href="<?php echo BASE_URL; ?>/project/<?php echo $row ? 'knowledge_skill.php?id=' . (int)$row['id'] : 'knowledge_skills.php'; ?>">取消</a></div>
</form>
<script>
<?php echo pks_post_script(); ?>
document.addEventListener('DOMContentLoaded', function () {
  var csrf = <?php echo json_encode(ps_csrf_token()); ?>, id = <?php echo (int)($row['id'] ?? 0); ?>, revision = <?php echo (int)$form['revision']; ?>;
  var $ = function (s) { return document.getElementById(s); };
  var kind = function () { return document.querySelector('#kindSeg input:checked').value; };
  function sync() {
    var k = kind();
    document.querySelectorAll('[data-for]').forEach(function (el) { el.style.display = el.getAttribute('data-for').split(' ').indexOf(k) !== -1 ? '' : 'none'; });
    $('f-content-label').textContent = k === 'chat' ? '亮点点评（为什么值得学）' : (k === 'business' ? '要点说明（流程、口径、注意事项）' : '补充说明（可选）');
    $('kbsForm').className = $('kbsForm').className.replace(/k-(skill|business|chat)/, 'k-' + k);
  }
  document.querySelectorAll('#kindSeg input').forEach(function (r) { r.addEventListener('change', sync); }); sync();
  $('kbsAiBtn').addEventListener('click', function () {
    var b = this, n = $('kbsAiNote'); b.disabled = true; n.textContent = 'AI 整理中，可能需要十几秒…';
    pksPost({csrf: csrf, action: 'ai_fill', kind: kind(), raw: $('f-raw').value}).then(function (d) {
      var f = d.fields, map = {title: 'f-title', scenario: 'f-scenario', customer_says: 'f-says', reply: 'f-reply', avoid: 'f-avoid', content: 'f-content', chat_log: 'f-log', tags: 'f-tags'};
      Object.keys(map).forEach(function (k) { if (f[k]) $(map[k]).value = f[k]; });
      n.textContent = '已填入下方，请核对后保存';
    }).catch(function (e) { n.textContent = e.message; }).then(function () { b.disabled = false; });
  });
  function save() {
    var b = $('kbsSave'), m = $('kbsMsg'); b.disabled = true; m.textContent = '保存中…';
    var data = {csrf: csrf, action: 'save', id: id, revision: revision, kind: kind(), title: $('f-title').value, business: $('f-business').value,
      roles: Array.prototype.map.call(document.querySelectorAll('.f-role:checked'), function (c) { return c.value; }),
      scenario: $('f-scenario').value, customer_says: $('f-says').value, reply: $('f-reply').value, avoid: $('f-avoid').value, content: $('f-content').value, chat_log: $('f-log').value, tags: $('f-tags').value};
    if ($('f-quality')) { data.quality = $('f-quality').value; data.status = $('f-status').value; }
    pksPost(data).then(function (d) { location.href = location.pathname + '?id=' + d.id + '&saved=1'; })
      .catch(function (e) { m.textContent = e.message; b.disabled = false; });
  }
  $('kbsSave').addEventListener('click', save);
  document.addEventListener('keydown', function (e) { if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') save(); });
});
</script>

<?php elseif ($row): $rec = pks_ai_record($row); ?>
<?php if (!empty($_GET['saved'])): ?><div class="kbx-note" role="status">已保存。<?php echo $row['status'] === 'pending' ? '待主管审核，采纳后 AI 才会学习。' : ''; ?></div><?php endif; ?>
<div class="kbx-detail k-<?php echo e($row['kind']); ?>">
  <article class="kbx-panel">
    <span class="kbx-kind"><i class="fas <?php echo PKS_KIND_ICONS[$row['kind']]; ?>"></i> <?php echo e(PKS_KINDS[$row['kind']]); ?></span>
    <h1><?php echo e($row['title']); ?></h1>
    <?php if ($row['scenario'] !== ''): ?><p class="kb-muted"><i class="far fa-lightbulb"></i> 场景：<?php echo e($row['scenario']); ?></p><?php endif; ?>
    <?php if ($rec['customer_says']): ?><h2>客户常见说法</h2><ul class="kbx-says"><?php foreach ($rec['customer_says'] as $s): ?><li><?php echo e($s); ?></li><?php endforeach; ?></ul><?php endif; ?>
    <?php if ($rec['recommended_replies']): ?><h2>推荐话术</h2><?php foreach ($rec['recommended_replies'] as $r): ?><div class="kbx-reply"><?php echo e($r); ?><button type="button" class="kbx-btn ghost sm kbx-copy" data-copy="<?php echo e($r); ?>"><i class="far fa-copy"></i> 复制</button></div><?php endforeach; endif; ?>
    <?php if ($rec['avoid']): ?><h2>禁忌 / 反面示例</h2><ul class="kbx-avoid"><?php foreach ($rec['avoid'] as $s): ?><li><?php echo e($s); ?></li><?php endforeach; ?></ul><?php endif; ?>
    <?php if ($rec['dialogue']): ?><h2>对话原文 <button type="button" class="kbx-btn ghost sm" style="margin-left:auto" data-copy="<?php echo e(implode("\n", array_map(function ($t) { return ($t['speaker'] === 'agent' ? '客服：' : '客户：') . $t['text']; }, $rec['dialogue']))); ?>"><i class="far fa-copy"></i> 复制整段</button></h2>
      <div class="kbx-chat"><?php foreach ($rec['dialogue'] as $t): ?><div class="kbx-msg <?php echo e($t['speaker']); ?>"><span class="av"><?php echo $t['speaker'] === 'agent' ? '服' : '客'; ?></span><div class="bb"><?php echo e($t['text']); ?></div></div><?php endforeach; ?></div><?php endif; ?>
    <?php if ($row['content'] !== ''): ?><h2><?php echo $row['kind'] === 'chat' ? '亮点点评' : '要点说明'; ?></h2><div class="kbx-prose"><?php echo e($row['content']); ?></div><?php endif; ?>
  </article>
  <aside class="kbx-aside">
    <div class="kbx-panel">
      <dl>
        <dt>状态</dt><dd><span class="kbx-tag<?php echo $row['status'] === 'approved' ? '' : ' warn'; ?>"><?php echo e(explode('（', PKS_STATUS[$row['status']])[0]); ?></span><?php echo $row['status'] === 'approved' ? ' <span class="kb-muted" style="font-size:.76rem">AI 可学习</span>' : ''; ?></dd>
        <dt>适用角色</dt><dd class="kbx-tags"><?php foreach (pks_role_labels($row['roles']) as $label): ?><span class="kbx-tag"><?php echo e($label); ?></span><?php endforeach; ?></dd>
        <dt>业务</dt><dd><?php echo $row['business'] !== '' ? e($row['business']) : '通用'; ?></dd>
        <dt>质量分</dt><dd class="kbx-stars"><?php echo str_repeat('★', (int)$row['quality']) . str_repeat('☆', 5 - (int)$row['quality']); ?></dd>
        <?php if ($row['tags'] !== ''): ?><dt>标签</dt><dd><?php echo e($row['tags']); ?></dd><?php endif; ?>
        <dt>作者</dt><dd><?php echo e($row['owner_name']); ?></dd>
        <dt>更新</dt><dd class="num"><?php echo e(substr($row['updated_at'], 0, 10)); ?> · 版本 <?php echo (int)$row['revision']; ?></dd>
      </dl>
    </div>
    <div class="kbx-panel">
      <div class="kbx-actions" style="flex-direction:column;align-items:stretch">
        <?php if ($canEdit): ?><a class="kbx-btn ghost" href="?id=<?php echo (int)$row['id']; ?>&edit=1"><i class="far fa-edit"></i> 编辑</a><?php endif; ?>
        <?php if ($editor): ?>
          <?php if ($row['status'] !== 'approved'): ?><button type="button" class="kbx-btn" data-st="approved"><i class="fas fa-check"></i> 采纳（AI 可学习）</button><?php endif; ?>
          <?php if ($row['status'] === 'approved'): ?><button type="button" class="kbx-btn ghost" data-st="pending">退回待审核</button><?php endif; ?>
          <?php if ($row['status'] !== 'archived'): ?><button type="button" class="kbx-btn ghost" data-st="archived">归档</button><?php endif; ?>
        <?php endif; ?>
        <?php if (!empty($ctx['super'])): ?><button type="button" class="kbx-btn danger" id="kbsDel"><i class="far fa-trash-alt"></i> 删除</button><?php endif; ?>
      </div>
      <div class="kbx-msgline" id="kbsMsg" role="status"></div>
    </div>
  </aside>
</div>
<script>
<?php echo pks_post_script(); ?>
document.addEventListener('DOMContentLoaded', function () {
  var csrf = <?php echo json_encode(ps_csrf_token()); ?>, id = <?php echo (int)$row['id']; ?>, m = document.getElementById('kbsMsg');
  document.querySelectorAll('[data-st]').forEach(function (b) { b.addEventListener('click', function () {
    b.disabled = true; pksPost({csrf: csrf, action: 'status', id: id, status: b.dataset.st}).then(function () { location.reload(); }).catch(function (e) { m.textContent = e.message; b.disabled = false; });
  }); });
  var del = document.getElementById('kbsDel');
  if (del) del.addEventListener('click', function () { if (!confirm('删除后其他人将看不到这条内容，确认吗？')) return; pksPost({csrf: csrf, action: 'delete', id: id}).then(function () { location.href = <?php echo json_encode(BASE_URL . '/project/knowledge_skills.php'); ?>; }).catch(function (e) { m.textContent = e.message; }); });
});
</script>
<?php endif; ?>
</div>
<script src="<?php echo pks_asset('js/knowledge_skills.js'); ?>"></script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
