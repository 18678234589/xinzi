<?php
require_once __DIR__ . '/../includes/ProjectKnowledgeChatImport.php';
// 导入我的精选聊天记录：粘贴或上传 .txt → AI 智能切分整理（失败时规则识别托底）→ 预览核对 → 批量保存。
$actor = ps_require_actor(); $ctx = pk_context($actor);
pks_ensure();
$editor = pks_is_editor($ctx);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = pks_json_body();
    if ($body === null) pks_json_reply(['error' => '请求格式不正确，请刷新页面后重试'], 400);
    if (!hash_equals(ps_csrf_token(), (string)($body['csrf'] ?? ''))) pks_json_reply(['error' => '页面已过期，请刷新后重试'], 403);
    try {
        $action = (string)($body['action'] ?? '');
        if ($action === 'parse') {
            $names = preg_split('/[,，、;；\s]+/u', (string)($body['my_names'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
            $result = pks_chat_import_parse((string)($body['text'] ?? ''), $names);
            ps_audit('knowledge_skill', 0, 'chat_import_parse', $actor, ['source' => $result['source'], 'items' => count($result['items'])]);
            pks_json_reply($result);
        } elseif ($action === 'save') {
            $defaults = ['roles' => $body['roles'] ?? [], 'business' => (string)($body['business'] ?? ''), 'approve' => $editor && !empty($body['approve'])];
            $saved = pks_chat_import_save((array)($body['items'] ?? []), $defaults, $ctx);
            pks_json_reply(['saved' => $saved]);
        }
        pks_json_reply(['error' => '操作无效'], 400);
    } catch (RuntimeException $e) {
        pks_json_reply(['error' => $e->getMessage()], 400);
    } catch (Throwable $e) {
        error_log('knowledge_skill_import: ' . $e->getMessage());
        pks_json_reply(['error' => '处理失败，请稍后重试'], 500);
    }
}
$myRoles = pks_actor_roles($actor);
$myBusinesses = pks_actor_businesses($actor);
$businessNames = array_keys(ps_business_catalog());
$sample = "客户：你好，做一个公司官网大概多少钱？\n客服：您好～基础版 800 元，包含 5 个页面和一年空间域名，您是做什么行业的呢？\n客户：做餐饮的，能便宜点吗\n客服：理解您想控制预算。这样，我们送您一年的网站维护，等于变相优惠 200 元，您看可以吗？\n客户：行，那就这个吧";
$page_title = '导入精选聊天记录 · 沟通技巧库';
include __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?php echo pks_asset('css/knowledge_skills.css'); ?>">
<div class="kb-page kbx kbx-form k-chat" data-no-keywords>
<?php pk_hero('LEARN FROM THE BEST / 好对话，值得被学到', '导入我的精选聊天记录', '把你和客户的优秀聊天放进来，AI 自动切分、整理成条目，核对后保存——供大家学习，也供后续 AI 客服学习。'); pk_tabs('skills'); ?>
<a class="kb-back" href="<?php echo BASE_URL; ?>/project/knowledge_skills.php">← 回到沟通技巧库</a>
<ol class="kbx-steps" aria-label="步骤"><li class="is-on" id="step1"><b>1</b> 放入聊天记录</li><li id="step2"><b>2</b> 核对并导入</li></ol>

<section class="kbx-panel" style="max-width:920px">
  <h2 style="margin-top:0">放入聊天记录</h2>
  <p class="kb-muted" style="margin:.2rem 0 .8rem">支持微信、QQ 的导出文本，也支持自己写的“客户：… / 客服：…”。一次可放多段对话，每次不超过 <?php echo PKS_CHAT_IMPORT_MAX; ?> 字；手机号、邮箱、身份证、银行卡号会先自动打码。</p>
  <div class="kbx-drop" id="drop" data-target="#i-text" tabindex="0" role="button" aria-label="选择或拖入 .txt 文件"><i class="fas fa-file-upload"></i><strong>拖入 .txt 文件，或点这里选择</strong><span style="font-size:.8rem">UTF-8 文本；也可以直接粘贴到下面</span><input type="file" accept=".txt,.text,.md,.log,text/plain"></div>
  <label class="l" for="i-text">聊天记录 <button type="button" class="kbx-btn ghost sm" style="margin-left:.5rem" data-fill="#i-text" data-sample="<?php echo e($sample); ?>">填入示例</button><span class="kbx-hint" id="iCount"></span></label>
  <textarea id="i-text" rows="10" placeholder="例如：&#10;客户：这个网站多少钱？&#10;客服：基础版 800 元…&#10;&#10;&#10;客户：多久能做好？&#10;客服：七个工作日…"></textarea>
  <div class="kbx-row2">
    <div><label class="l" for="i-names">我的昵称<span class="kbx-hint">可选。这些昵称的发言当作“客服”，其余当作“客户”，更准；多个用逗号分隔</span></label><input type="text" id="i-names" maxlength="120" placeholder="例如：小王客服,王小明"></div>
  </div>
  <div class="kbx-actions" style="margin-top:1rem"><button type="button" class="kbx-btn" id="iParse"><i class="fas fa-magic"></i> 智能识别</button><span class="kbx-hint" id="iNote" role="status"></span></div>
</section>

<section class="kbx-panel" id="iResult" hidden style="max-width:920px;margin-top:1.1rem">
  <h2 style="margin-top:0">核对并导入</h2>
  <p class="kb-muted" id="iSummary" style="margin:.2rem 0 .8rem"></p>
  <div class="kbx-row2">
    <div><label class="l">适用角色<span class="kbx-hint">不选 = 全部角色</span></label><div><?php foreach (PKS_ROLES as $k => $label): ?><label class="kbx-check"><input type="checkbox" class="i-role" value="<?php echo $k; ?>" <?php echo in_array($k, $myRoles, true) ? 'checked' : ''; ?>> <?php echo e($label); ?></label><?php endforeach; ?></div></div>
    <div><label class="l" for="i-business">所属业务</label><select id="i-business"><option value="">通用</option><?php foreach ($businessNames as $b): ?><option <?php echo ($myBusinesses && $b === $myBusinesses[0]) ? 'selected' : ''; ?>><?php echo e($b); ?></option><?php endforeach; ?></select></div>
  </div>
  <?php if ($editor): ?><label class="kbx-check" style="margin-top:.8rem"><input type="checkbox" id="i-approve" checked> 直接采纳（AI 可学习）；不勾选则进入待审核</label><?php else: ?><p class="kbx-hint" style="margin-top:.8rem">导入后进入“待审核”，主管采纳后 AI 才会学习。</p><?php endif; ?>
  <div id="iItems"></div>
  <div class="kbx-actions" style="margin-top:1rem"><button type="button" class="kbx-btn" id="iSave"><i class="fas fa-save"></i> 导入选中的对话</button><span class="kbx-hint" id="iSaveNote" role="status"></span></div>
</section>
</div>
<script>
<?php echo pks_post_script(); ?>
document.addEventListener('DOMContentLoaded', function () {
  var csrf = <?php echo json_encode(ps_csrf_token()); ?>;
  var $ = function (s) { return document.getElementById(s); };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]; }); };
  var limit = <?php echo (int)PKS_CHAT_IMPORT_MAX; ?>;
  function count() { var n = $('i-text').value.length; $('iCount').textContent = n ? n + ' / ' + limit + ' 字' : ''; $('iCount').style.color = n > limit ? '#b4332f' : ''; }
  $('i-text').addEventListener('input', count); count();
  $('iParse').addEventListener('click', function () {
    var b = this, n = $('iNote'); b.disabled = true; n.textContent = '识别中，AI 可能需要十几秒到一分钟…';
    pksPost({csrf: csrf, action: 'parse', text: $('i-text').value, my_names: $('i-names').value}).then(function (d) {
      var box = $('iItems'); box.innerHTML = '';
      d.items.forEach(function (it) {
        var div = document.createElement('div'); div.className = 'kbx-item';
        div.innerHTML = '<div class="top"><input type="checkbox" class="i-pick" checked aria-label="导入这段"><input type="text" data-k="title" maxlength="200" value="' + esc(it.title) + '" placeholder="标题"></div>'
          + (it.guessed ? '<div class="kbx-warn">⚠ 客户 / 客服是推测的，请核对每句开头</div>' : '')
          + '<label class="l">场景 / 客户意图</label><input type="text" data-k="scenario" maxlength="255" value="' + esc(it.scenario) + '">'
          + '<label class="l">对话原文<span class="kbx-hint">每句一行，以“客户：”或“客服：”开头，可直接修改</span></label><textarea data-k="chat_log">' + esc(it.chat_log) + '</textarea>'
          + '<label class="l">亮点点评<span class="kbx-hint">为什么值得学，可选</span></label><textarea data-k="content" rows="2" style="min-height:60px">' + esc(it.content) + '</textarea>'
          + '<label class="l">标签</label><input type="text" data-k="tags" maxlength="255" value="' + esc(it.tags) + '">';
        box.appendChild(div);
      });
      $('iResult').hidden = !d.items.length;
      $('step2').classList.toggle('is-on', d.items.length > 0);
      $('iSummary').textContent = d.items.length ? '识别出 ' + d.items.length + ' 段对话' + (d.source === 'ai' ? '（AI）' : '（规则）') + '，取消勾选的不会导入' + (d.note ? '；' + d.note : '') : '';
      n.textContent = d.items.length ? '' : ('没有识别出完整的对话，请检查内容' + (d.note ? '；' + d.note : ''));
      if (d.items.length) $('iResult').scrollIntoView({behavior: 'smooth', block: 'start'});
    }).catch(function (e) { n.textContent = e.message; }).then(function () { b.disabled = false; });
  });
  $('iSave').addEventListener('click', function () {
    var b = this, n = $('iSaveNote'), items = [];
    document.querySelectorAll('#iItems .kbx-item').forEach(function (div) {
      if (!div.querySelector('.i-pick').checked) return;
      var it = {}; div.querySelectorAll('[data-k]').forEach(function (el) { it[el.dataset.k] = el.value; }); items.push(it);
    });
    if (!items.length) { n.textContent = '请至少勾选一段'; return; }
    b.disabled = true; n.textContent = '保存中…';
    pksPost({csrf: csrf, action: 'save', items: items, business: $('i-business').value, approve: $('i-approve') ? $('i-approve').checked : false,
      roles: Array.prototype.map.call(document.querySelectorAll('.i-role:checked'), function (c) { return c.value; })})
      .then(function (d) { location.href = <?php echo json_encode(BASE_URL . '/project/knowledge_skills.php?kind=chat&mine=1&imported='); ?> + d.saved; })
      .catch(function (e) { n.textContent = e.message; b.disabled = false; });
  });
});
</script>
<script src="<?php echo pks_asset('js/knowledge_skills.js'); ?>"></script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
