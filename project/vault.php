<?php
require_once __DIR__ . '/../includes/ProjectVault.php';

$actor = pv_require_access();
if (!headers_sent()) { header('Cache-Control: no-store, max-age=0'); header('X-Robots-Tag: noindex'); }
$canManageAccess = pv_can_manage_access($actor);
$error = '';
$notice = '';
$json = function ($data, $status = 200) { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; };

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ajax = !empty($_POST['ajax']);
    if (!hash_equals(ps_csrf_token(), (string)($_POST['csrf'] ?? ''))) { if ($ajax) $json(['error' => '页面已过期，请刷新后重试'], 403); ps_check_csrf(); }
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'reveal') {
            $q = db()->prepare('SELECT * FROM project_vault_items WHERE id=? AND deleted_at IS NULL');
            $q->execute([(int)($_POST['id'] ?? 0)]);
            $item = $q->fetch();
            if (!$item) $json(['error' => '条目不存在'], 404);
            ps_audit('vault', (int)$item['id'], (string)($_POST['purpose'] ?? '') === 'copy' ? 'copy' : 'reveal', $actor, ['name' => $item['name']]);
            $json(['password' => pv_decrypt($item['password_enc']), 'notes' => pv_decrypt($item['notes_enc'])]);
        } elseif ($action === 'parse') {
            $json(pv_parse_paste((string)($_POST['text'] ?? ''), PV_CATEGORIES, $actor));
        } elseif ($action === 'import') {
            $items = json_decode((string)($_POST['items'] ?? ''), true);
            if (!is_array($items) || !$items) throw new RuntimeException('没有要保存的条目');
            $saved = 0;
            db()->beginTransaction();
            try {
                foreach ($items as $item) if (is_array($item)) { pv_item_save($item + ['via' => 'ai_import'], $actor); $saved++; }
                db()->commit();
            } catch (Throwable $e) { db()->rollBack(); throw $e; }
            $_SESSION['vault_notice'] = '已导入 ' . $saved . ' 条平台信息';
            header('Location: ' . BASE_URL . '/project/vault.php'); exit;
        } elseif ($action === 'save') {
            $id = pv_item_save($_POST, $actor, (int)($_POST['id'] ?? 0));
            $_SESSION['vault_notice'] = '已保存“' . (string)($_POST['name'] ?? '') . '”';
            header('Location: ' . BASE_URL . '/project/vault.php#item-' . $id); exit;
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            db()->prepare('UPDATE project_vault_items SET deleted_at=NOW(),updated_by_type=?,updated_by_id=? WHERE id=? AND deleted_at IS NULL')->execute([$actor['type'], $actor['id'], $id]);
            ps_audit('vault', $id, 'delete', $actor, []);
            $_SESSION['vault_notice'] = '已删除';
            header('Location: ' . BASE_URL . '/project/vault.php'); exit;
        } elseif ($action === 'access' && $canManageAccess) {
            $admins = array_values(array_unique(array_merge(['admin'], array_map('strval', (array)($_POST['admins'] ?? [])))));
            $employees = array_values(array_unique(array_map('intval', (array)($_POST['employees'] ?? []))));
            ps_setting_set('vault_access', ['admins' => $admins, 'employees' => $employees], $actor['id']);
            ps_audit('vault', 0, 'access', $actor, ['admins' => $admins, 'employees' => $employees]);
            $_SESSION['vault_notice'] = '可见人员已更新';
            header('Location: ' . BASE_URL . '/project/vault.php#access'); exit;
        } else throw new RuntimeException('操作无效');
    } catch (RuntimeException $e) {
        if ($ajax) $json(['error' => $e->getMessage()], 400);
        $error = $e->getMessage();
    }
}
$notice = $_SESSION['vault_notice'] ?? '';
unset($_SESSION['vault_notice']);
$search = trim((string)($_GET['q'] ?? ''));
$category = in_array($_GET['category'] ?? '', PV_CATEGORIES, true) ? $_GET['category'] : '';
$items = pv_items($search, $category);
$counts = [];
foreach (db()->query('SELECT category,COUNT(*) n FROM project_vault_items WHERE deleted_at IS NULL GROUP BY category')->fetchAll() as $row) $counts[$row['category']] = (int)$row['n'];
$grouped = [];
foreach ($items as $item) $grouped[$item['category']][] = $item;
$logs = db()->query("SELECT l.*,v.name AS item_name FROM project_audit_logs l LEFT JOIN project_vault_items v ON v.id=l.entity_id WHERE l.entity_type='vault' ORDER BY l.id DESC LIMIT 20")->fetchAll();
$actionLabels = ['reveal' => '查看密码', 'copy' => '复制密码', 'create' => '新增', 'update' => '修改', 'delete' => '删除', 'ai_parse' => 'AI 识别', 'access' => '调整可见人员'];
if ($canManageAccess) {
    $access = pv_access_list();
    $accessAdmins = db()->query('SELECT username FROM admins ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    $accessPeople = db()->query("SELECT e.id,e.name,e.department,u.username FROM employees e JOIN project_users u ON u.employee_id=e.id AND u.is_active=1 ORDER BY e.department,e.name")->fetchAll();
}
$page_title = '平台与服务器信息';
include __DIR__ . '/../includes/header.php';
?>
<style>
.vault-secret{font-family:SFMono-Regular,Consolas,monospace;letter-spacing:.5px}
.vault-cat{scroll-margin-top:80px}
.vault-table td{vertical-align:middle}
.vault-table .btn-xs{padding:1px 6px;font-size:.75rem;line-height:1.4}
.vault-pills .btn{border-radius:999px}
.vault-preview input,.vault-preview select,.vault-preview textarea{font-size:.8rem}
</style>
<div class="project-hero mb-3"><div><div class="project-eyebrow">项目合作结算中心 · 管理</div><h2>平台与服务器信息</h2><p>服务器、宝塔、1Panel、MySQL、腾讯云、阿里云、雅云、野草云、商务中国、新网等平台的登录网址与账号密码。密码与备注加密保存，每次查看、复制都会留痕；仅股东、管理层、管理员与服务器维护人员可见。</p></div><div class="project-hero-actions"><button class="btn btn-light" type="button" data-toggle="modal" data-target="#vaultEdit" data-mode="new"><i class="fas fa-plus mr-1"></i>新增</button> <button class="btn btn-warning" type="button" data-toggle="collapse" data-target="#vaultAi"><i class="fas fa-magic mr-1"></i>AI 智能导入</button></div></div>
<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
<?php if ($notice): ?><div class="alert alert-success"><?php echo e($notice); ?></div><?php endif; ?>

<div class="collapse<?php echo !$counts ? ' show' : ''; ?>" id="vaultAi"><div class="card project-form-card mb-3"><div class="card-body">
  <h5><i class="fas fa-magic text-warning mr-1"></i>粘贴进来，AI 自动整理</h5>
  <p class="small text-muted mb-2">把聊天记录、备忘录、表格里复制的账号信息整段粘贴进来（一次可以多个平台），点“智能识别”，核对后保存。写了“密码 / password / 密钥”标签的内容会先在服务器上替换成占位符再交给 AI，<strong>密码本身不会发给 AI</strong>。</p>
  <textarea class="form-control mb-2" id="vaultPaste" rows="7" placeholder="例如：&#10;腾讯云主账号 https://cloud.tencent.com/login 账号：13800000000 密码：xxxx&#10;&#10;官网服务器 宝塔 http://1.2.3.4:8888/abcd 用户名 admin 密码 xxxx，到期 2027-03"></textarea>
  <button class="btn btn-warning" type="button" id="vaultParseBtn"><i class="fas fa-magic mr-1"></i>智能识别</button> <span class="small text-muted ml-2" id="vaultParseNote"></span>
  <form method="post" id="vaultImportForm" class="mt-3 d-none"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="import"><input type="hidden" name="items" id="vaultImportItems">
    <div class="table-responsive"><table class="table table-sm table-bordered vault-preview mb-2"><thead class="thead-light"><tr><th style="width:36px"><input type="checkbox" id="vaultCheckAll" checked aria-label="全选"></th><th style="width:120px">类别</th><th>名称</th><th>登录网址</th><th>主机 / IP</th><th>账号</th><th>密码</th><th>备注</th></tr></thead><tbody id="vaultPreviewBody"></tbody></table></div>
    <button class="btn btn-success" type="submit" id="vaultImportBtn"><i class="fas fa-save mr-1"></i>保存选中的条目</button> <small class="text-muted">可直接在表格里修改后再保存。</small>
  </form>
</div></div></div>

<form method="get" class="d-flex flex-wrap align-items-center mb-3" style="gap:8px"><input class="form-control" style="max-width:280px" name="q" value="<?php echo e($search); ?>" placeholder="搜索名称、网址、IP、账号"><?php if ($category): ?><input type="hidden" name="category" value="<?php echo e($category); ?>"><?php endif; ?><button class="btn btn-outline-primary">搜索</button>
<div class="vault-pills d-flex flex-wrap ml-md-2" style="gap:6px"><a class="btn btn-sm <?php echo $category === '' ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="?q=<?php echo rawurlencode($search); ?>">全部 <?php echo array_sum($counts); ?></a><?php foreach (PV_CATEGORIES as $c): if (empty($counts[$c])) continue; ?><a class="btn btn-sm <?php echo $category === $c ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="?category=<?php echo rawurlencode($c); ?>&q=<?php echo rawurlencode($search); ?>"><?php echo e($c); ?> <?php echo $counts[$c]; ?></a><?php endforeach; ?></div></form>

<?php if (!$items): ?><div class="card project-form-card"><div class="card-body text-center text-muted py-5"><i class="fas fa-key fa-2x mb-2 d-block"></i><?php echo $counts ? '没有符合条件的条目' : '还没有记录。点右上角“AI 智能导入”，把现有的账号信息整段粘贴进来即可。'; ?></div></div><?php endif; ?>
<?php foreach ($grouped as $cat => $rows): ?>
<div class="card project-form-card mb-3 vault-cat" id="cat-<?php echo e(md5($cat)); ?>"><div class="card-body pb-1"><h5 class="mb-2"><?php echo e($cat); ?> <small class="text-muted"><?php echo count($rows); ?> 条</small></h5></div>
<div class="table-responsive"><table class="table table-hover vault-table mb-0"><thead><tr><th>名称</th><th>登录网址 / 主机</th><th>账号</th><th>密码</th><th>备注</th><th>更新</th><th style="width:110px"></th></tr></thead><tbody>
<?php foreach ($rows as $item): ?><tr id="item-<?php echo (int)$item['id']; ?>">
<td><strong><?php echo e($item['name']); ?></strong></td>
<td class="small"><?php if ($item['url'] !== ''): ?><a href="<?php echo e(preg_match('~^https?://~i', $item['url']) ? $item['url'] : 'http://' . $item['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo e(mb_strimwidth($item['url'], 0, 48, '…')); ?></a><?php endif; ?><?php if ($item['host'] !== ''): ?><div class="text-muted"><?php echo e($item['host']); ?> <button type="button" class="btn btn-link btn-xs p-0 js-copy-text" data-text="<?php echo e($item['host']); ?>">复制</button></div><?php endif; ?></td>
<td class="small"><?php if ($item['account'] !== ''): ?><span class="vault-secret"><?php echo e($item['account']); ?></span> <button type="button" class="btn btn-outline-secondary btn-xs js-copy-text" data-text="<?php echo e($item['account']); ?>">复制</button><?php endif; ?></td>
<td class="small text-nowrap"><?php if ($item['password_enc'] !== ''): ?><span class="vault-secret js-secret" data-id="<?php echo (int)$item['id']; ?>">••••••••</span> <button type="button" class="btn btn-outline-primary btn-xs js-reveal" data-id="<?php echo (int)$item['id']; ?>">显示</button> <button type="button" class="btn btn-outline-secondary btn-xs js-copy-secret" data-id="<?php echo (int)$item['id']; ?>">复制</button><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
<td class="small"><?php if ($item['notes_enc'] !== ''): ?><span class="text-muted js-notes" data-id="<?php echo (int)$item['id']; ?>">已加密</span> <button type="button" class="btn btn-link btn-xs p-0 js-reveal" data-id="<?php echo (int)$item['id']; ?>">查看</button><?php endif; ?></td>
<td class="small text-muted text-nowrap"><?php echo e(pv_actor_name($item['updated_by_type'], $item['updated_by_id'])); ?><br><?php echo e(substr($item['updated_at'], 0, 16)); ?></td>
<td class="text-nowrap"><button type="button" class="btn btn-outline-primary btn-sm" data-toggle="modal" data-target="#vaultEdit" data-mode="edit" data-item="<?php echo e(json_encode(['id' => (int)$item['id'], 'category' => $item['category'], 'name' => $item['name'], 'url' => $item['url'], 'host' => $item['host'], 'account' => $item['account'], 'has_notes' => $item['notes_enc'] !== ''], JSON_UNESCAPED_UNICODE)); ?>">编辑</button>
<form method="post" class="d-inline" onsubmit="return confirm('确定删除“<?php echo e(addslashes($item['name'])); ?>”？');"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$item['id']; ?>"><button class="btn btn-outline-danger btn-sm">删除</button></form></td>
</tr><?php endforeach; ?>
</tbody></table></div></div>
<?php endforeach; ?>

<div class="card project-form-card mb-3"><div class="card-body"><h5 class="mb-2"><i class="fas fa-history mr-1 text-muted"></i>最近操作记录</h5>
<?php if (!$logs): ?><p class="text-muted small mb-0">暂无记录</p><?php else: ?><div class="table-responsive"><table class="table table-sm mb-0 small"><tbody><?php foreach ($logs as $log): ?><tr><td class="text-nowrap text-muted"><?php echo e(substr($log['created_at'], 0, 16)); ?></td><td class="text-nowrap"><?php echo e(pv_actor_name($log['actor_type'], $log['actor_id'])); ?></td><td><?php echo e($actionLabels[$log['action']] ?? $log['action']); ?></td><td><?php echo e($log['item_name'] ?? ''); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</div></div>

<?php if ($canManageAccess): ?>
<div class="card project-form-card mb-3" id="access"><div class="card-body"><h5><i class="fas fa-user-shield mr-1"></i>可见人员 <small class="text-muted">仅 admin 可调整</small></h5>
<form method="post"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="access">
<div class="small text-muted mb-1">管理员账号</div><div class="d-flex flex-wrap mb-3" style="gap:6px 16px"><?php foreach ($accessAdmins as $username): ?><label class="mb-0"><input type="checkbox" name="admins[]" value="<?php echo e($username); ?>" <?php echo in_array($username, $access['admins'], true) ? 'checked' : ''; ?> <?php echo $username === 'admin' ? 'disabled checked' : ''; ?>> <?php echo e($username); ?></label><?php endforeach; ?></div>
<div class="small text-muted mb-1">合作人员账号</div><div class="d-flex flex-wrap mb-3" style="gap:6px 16px"><?php foreach ($accessPeople as $person): ?><label class="mb-0"><input type="checkbox" name="employees[]" value="<?php echo (int)$person['id']; ?>" <?php echo in_array((int)$person['id'], $access['employees'], true) ? 'checked' : ''; ?>> <?php echo e($person['name']); ?> <small class="text-muted"><?php echo e($person['department']); ?></small></label><?php endforeach; ?></div>
<button class="btn btn-primary btn-sm">保存可见人员</button></form></div></div>
<?php endif; ?>

<div class="modal fade" id="vaultEdit" tabindex="-1" role="dialog" aria-labelledby="vaultEditTitle" aria-hidden="true"><div class="modal-dialog" role="document"><form method="post" class="modal-content"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="ve_id" value="0">
<div class="modal-header"><h5 class="modal-title" id="vaultEditTitle">新增平台信息</h5><button type="button" class="close" data-dismiss="modal" aria-label="关闭"><span aria-hidden="true">&times;</span></button></div>
<div class="modal-body">
<div class="form-row"><div class="form-group col-5"><label for="ve_category">类别</label><select class="form-control" name="category" id="ve_category"><?php foreach (PV_CATEGORIES as $c): ?><option><?php echo e($c); ?></option><?php endforeach; ?></select></div><div class="form-group col-7"><label for="ve_name">名称</label><input class="form-control" name="name" id="ve_name" maxlength="120" placeholder="如 官网服务器、腾讯云主账号"></div></div>
<div class="form-group"><label for="ve_url">登录网址</label><input class="form-control" name="url" id="ve_url" maxlength="500" placeholder="https://"></div>
<div class="form-group"><label for="ve_host">主机 / IP / 端口</label><input class="form-control" name="host" id="ve_host" maxlength="200" placeholder="如 1.2.3.4:22"></div>
<div class="form-row"><div class="form-group col-6"><label for="ve_account">账号</label><input class="form-control" name="account" id="ve_account" maxlength="200" autocomplete="off"></div><div class="form-group col-6"><label for="ve_password">密码</label><input class="form-control" name="password" id="ve_password" autocomplete="new-password"><small class="form-text text-muted" id="ve_password_hint"></small></div></div>
<div class="form-group mb-0"><label for="ve_notes">备注 <small class="text-muted">（加密保存：安全入口、到期时间、数据库名等）</small></label><textarea class="form-control" name="notes" id="ve_notes" rows="3"></textarea></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-dismiss="modal">取消</button><button class="btn btn-primary">保存</button></div></form></div></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var csrf = <?php echo json_encode(ps_csrf_token()); ?>, categories = <?php echo json_encode(PV_CATEGORIES, JSON_UNESCAPED_UNICODE); ?>, cache = {};
  function post(data) {
    var body = new URLSearchParams(Object.assign({csrf: csrf, ajax: 1}, data));
    return fetch(location.pathname, {method: 'POST', body: body, credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (d) { if (d.error) throw new Error(d.error); return d; });
  }
  function secret(id, purpose) { return cache[id] && purpose !== 'copy' ? Promise.resolve(cache[id]) : post({action: 'reveal', id: id, purpose: purpose || 'reveal'}).then(function (d) { cache[id] = d; return d; }); }
  function copy(text, btn) {
    var done = function () { if (!btn) return; var t = btn.textContent; btn.textContent = '已复制'; setTimeout(function () { btn.textContent = t; }, 1200); };
    if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(done, function () { fallback(); }); else fallback();
    function fallback() { var ta = document.createElement('textarea'); ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0'; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); done(); } catch (e) { prompt('复制：', text); } document.body.removeChild(ta); }
  }
  document.querySelectorAll('.js-copy-text').forEach(function (b) { b.addEventListener('click', function () { copy(b.dataset.text, b); }); });
  document.querySelectorAll('.js-reveal').forEach(function (b) { b.addEventListener('click', function () {
    var id = b.dataset.id, span = document.querySelector('.js-secret[data-id="' + id + '"]'), notes = document.querySelector('.js-notes[data-id="' + id + '"]');
    if (span && span.dataset.shown) { span.textContent = '••••••••'; delete span.dataset.shown; b.textContent = '显示'; return; }
    secret(id).then(function (d) { if (span) { span.textContent = d.password || '（空）'; span.dataset.shown = 1; } if (notes) { notes.textContent = d.notes; notes.classList.remove('text-muted'); notes.style.whiteSpace = 'pre-wrap'; } if (b.textContent === '显示') b.textContent = '隐藏'; }).catch(function (e) { alert(e.message); });
  }); });
  document.querySelectorAll('.js-copy-secret').forEach(function (b) { b.addEventListener('click', function () { secret(b.dataset.id, 'copy').then(function (d) { copy(d.password, b); }).catch(function (e) { alert(e.message); }); }); });

  // 新增 / 编辑
  jQuery('#vaultEdit').on('show.bs.modal', function (ev) {
    var btn = ev.relatedTarget, item = btn && btn.dataset.item ? JSON.parse(btn.dataset.item) : null;
    document.getElementById('vaultEditTitle').textContent = item ? '编辑平台信息' : '新增平台信息';
    document.getElementById('ve_id').value = item ? item.id : 0;
    ['category', 'name', 'url', 'host', 'account'].forEach(function (k) { document.getElementById('ve_' + k).value = item ? item[k] : (k === 'category' ? categories[0] : ''); });
    document.getElementById('ve_password').value = '';
    document.getElementById('ve_password_hint').textContent = item ? '留空表示不修改' : '';
    var notes = document.getElementById('ve_notes'); notes.value = '';
    if (item && item.has_notes) { notes.placeholder = '加载中…'; secret(item.id).then(function (d) { notes.value = d.notes; notes.placeholder = ''; }); }
  });

  // AI 智能导入
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]; }); };
  document.getElementById('vaultParseBtn').addEventListener('click', function () {
    var btn = this, note = document.getElementById('vaultParseNote'), text = document.getElementById('vaultPaste').value;
    btn.disabled = true; note.textContent = '识别中…';
    post({action: 'parse', text: text}).then(function (d) {
      var body = document.getElementById('vaultPreviewBody'); body.innerHTML = '';
      d.items.forEach(function (it) {
        var tr = document.createElement('tr');
        tr.innerHTML = '<td><input type="checkbox" class="js-pick" checked></td><td><select class="form-control form-control-sm" data-k="category">' + categories.map(function (c) { return '<option' + (c === it.category ? ' selected' : '') + '>' + esc(c) + '</option>'; }).join('') + '</select></td>'
          + ['name', 'url', 'host', 'account', 'password'].map(function (k) { return '<td><input class="form-control form-control-sm" data-k="' + k + '" value="' + esc(it[k]) + '"></td>'; }).join('')
          + '<td><textarea class="form-control form-control-sm" rows="1" data-k="notes">' + esc(it.notes) + '</textarea></td>';
        body.appendChild(tr);
      });
      document.getElementById('vaultImportForm').classList.toggle('d-none', !d.items.length);
      note.textContent = (d.items.length ? '识别出 ' + d.items.length + ' 条' + (d.source === 'ai' ? '（AI）' : '（规则）') + '，请核对后保存' : '没有识别出登录信息，请检查粘贴的内容') + (d.note ? '；' + d.note : '');
    }).catch(function (e) { note.textContent = e.message; }).then(function () { btn.disabled = false; });
  });
  document.getElementById('vaultCheckAll').addEventListener('change', function () { var on = this.checked; document.querySelectorAll('.js-pick').forEach(function (c) { c.checked = on; }); });
  document.getElementById('vaultImportForm').addEventListener('submit', function (ev) {
    var items = [];
    document.querySelectorAll('#vaultPreviewBody tr').forEach(function (tr) { if (!tr.querySelector('.js-pick').checked) return; var it = {}; tr.querySelectorAll('[data-k]').forEach(function (el) { it[el.dataset.k] = el.value; }); items.push(it); });
    if (!items.length) { ev.preventDefault(); alert('请至少勾选一条'); return; }
    document.getElementById('vaultImportItems').value = JSON.stringify(items);
  });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
