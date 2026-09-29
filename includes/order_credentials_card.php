<?php
/**
 * 订单页 · 项目账号密码：客户服务器、宝塔 / 1Panel、域名管理面板、网站 / 小程序后台等。
 * 由 project/order.php 引入，需要 $order、$actor。只对技术与售后相关业务显示；能打开订单的人都能查看与补充。
 */
require_once __DIR__ . '/ProjectVault.php';
if (empty($order) || !pv_order_enabled($order)) return;
try { $orderCredentials = pv_order_credentials((int)$order['id']); } catch (PDOException $e) { return; }
$credNotice = $_SESSION['credential_notice'] ?? '';
$credError = $_SESSION['credential_error'] ?? '';
unset($_SESSION['credential_notice'], $_SESSION['credential_error']);
?>
<div class="card mb-3 project-form-card" id="credentials"><div class="card-body">
<h5><i class="fas fa-key mr-2 text-warning"></i>项目账号密码 <small class="text-muted"><?php echo count($orderCredentials); ?> 条</small></h5>
<p class="text-muted small mb-2">记录这个项目用到的客户服务器、宝塔 / 1Panel、域名管理面板、网站 / 小程序后台等账号密码，后续维护、续费、售后直接在这里找。密码加密保存，只有本单参与人和财务能看到，查看会留痕。</p>
<?php if ($credNotice): ?><div class="alert alert-success py-2"><?php echo e($credNotice); ?></div><?php endif; ?>
<?php if ($credError): ?><div class="alert alert-danger py-2"><?php echo e($credError); ?></div><?php endif; ?>
<?php if ($orderCredentials): ?>
<div class="table-responsive"><table class="table table-sm mb-3"><thead class="thead-light"><tr><th>类型</th><th>说明</th><th>地址</th><th>账号</th><th>密码</th><th>备注</th><th>记录人</th><th></th></tr></thead><tbody>
<?php foreach ($orderCredentials as $cred): $credMine = $actor['role'] === 'finance' || ($cred['created_by_type'] === $actor['type'] && (int)$cred['created_by_id'] === (int)$actor['id']); ?><tr>
<td class="text-nowrap small"><?php echo e($cred['kind']); ?></td>
<td class="small"><?php echo e($cred['label']); ?></td>
<td class="small"><?php if ($cred['url'] !== ''): ?><?php if (preg_match('~^https?://~i', $cred['url'])): ?><a href="<?php echo e($cred['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo e(mb_strimwidth($cred['url'], 0, 42, '…')); ?></a><?php else: ?><?php echo e($cred['url']); ?><?php endif; ?> <button type="button" class="btn btn-link btn-sm p-0 js-cred-copy-text" data-text="<?php echo e($cred['url']); ?>">复制</button><?php endif; ?></td>
<td class="small text-nowrap"><?php if ($cred['account'] !== ''): ?><?php echo e($cred['account']); ?> <button type="button" class="btn btn-link btn-sm p-0 js-cred-copy-text" data-text="<?php echo e($cred['account']); ?>">复制</button><?php endif; ?></td>
<td class="small text-nowrap"><?php if ($cred['password_enc'] !== ''): ?><span class="js-cred-secret" data-id="<?php echo (int)$cred['id']; ?>" style="font-family:Consolas,monospace">••••••</span> <button type="button" class="btn btn-outline-primary btn-sm py-0 js-cred-reveal" data-id="<?php echo (int)$cred['id']; ?>">显示</button> <button type="button" class="btn btn-outline-secondary btn-sm py-0 js-cred-copy" data-id="<?php echo (int)$cred['id']; ?>">复制</button><?php else: ?>—<?php endif; ?></td>
<td class="small"><?php if ($cred['notes_enc'] !== ''): ?><span class="text-muted js-cred-notes" data-id="<?php echo (int)$cred['id']; ?>">已加密</span> <button type="button" class="btn btn-link btn-sm p-0 js-cred-reveal" data-id="<?php echo (int)$cred['id']; ?>">查看</button><?php endif; ?></td>
<td class="small text-muted text-nowrap"><?php echo e(pv_actor_name($cred['created_by_type'], $cred['created_by_id'])); ?><br><?php echo e(substr($cred['created_at'], 0, 10)); ?></td>
<td><?php if ($credMine): ?><form method="post" action="<?php echo BASE_URL; ?>/project/credentials.php" onsubmit="return confirm('删除这条账号密码？');"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$cred['id']; ?>"><button class="btn btn-outline-danger btn-sm py-0">删除</button></form><?php endif; ?></td>
</tr><?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
<details<?php echo $orderCredentials ? '' : ' open'; ?>><summary class="mb-2" style="cursor:pointer"><strong><i class="fas fa-plus-circle text-success mr-1"></i>记录账号密码</strong></summary>
<div class="mb-2"><textarea class="form-control form-control-sm" id="credPaste" rows="3" placeholder="可直接粘贴客户发来的账号信息（如：宝塔 http://1.2.3.4:8888/abc 账号 admin 密码 xxx；域名在阿里云 账号 xxx 密码 xxx），点“智能识别”自动填到下面"></textarea><button type="button" class="btn btn-sm btn-warning mt-1" id="credParseBtn"><i class="fas fa-magic mr-1"></i>智能识别</button> <small class="text-muted" id="credParseNote">带“密码”标签的内容不会发给 AI。</small></div>
<form method="post" action="<?php echo BASE_URL; ?>/project/credentials.php" id="credForm"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>"><input type="hidden" name="action" value="add">
<div class="table-responsive"><table class="table table-sm table-borderless mb-1"><thead><tr class="small text-muted"><th style="width:160px">类型</th><th>说明</th><th>地址 / IP</th><th>账号</th><th>密码</th><th>备注</th></tr></thead><tbody id="credRows"></tbody></table></div>
<button type="button" class="btn btn-sm btn-outline-secondary" id="credAddRow">+ 再加一行</button> <button class="btn btn-sm btn-success"><i class="fas fa-save mr-1"></i>保存</button>
</form></details>
</div></div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var endpoint = <?php echo json_encode(BASE_URL . '/project/credentials.php'); ?>, csrf = <?php echo json_encode(ps_csrf_token()); ?>, orderId = <?php echo (int)$order['id']; ?>, kinds = <?php echo json_encode(PV_ORDER_KINDS, JSON_UNESCAPED_UNICODE); ?>, cache = {}, n = 0;
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]; }); };
  function post(data) { return fetch(endpoint, {method: 'POST', credentials: 'same-origin', body: new URLSearchParams(Object.assign({csrf: csrf, ajax: 1, order_id: orderId}, data))}).then(function (r) { return r.json(); }).then(function (d) { if (d.error) throw new Error(d.error); return d; }); }
  function secret(id, purpose) { return cache[id] && purpose !== 'copy' ? Promise.resolve(cache[id]) : post({action: 'reveal', id: id, purpose: purpose || 'reveal'}).then(function (d) { cache[id] = d; return d; }); }
  function copy(text, btn) { var ok = function () { var t = btn.textContent; btn.textContent = '已复制'; setTimeout(function () { btn.textContent = t; }, 1200); }; if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(ok); else { var ta = document.createElement('textarea'); ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0'; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); ok(); } catch (e) { prompt('复制：', text); } document.body.removeChild(ta); } }
  function addRow(it) {
    it = it || {}; var i = n++, tr = document.createElement('tr');
    tr.innerHTML = '<td><select class="form-control form-control-sm" name="cred[' + i + '][kind]">' + kinds.map(function (k) { return '<option' + (k === it.kind ? ' selected' : '') + '>' + esc(k) + '</option>'; }).join('') + '</select></td>'
      + '<td><input class="form-control form-control-sm" name="cred[' + i + '][label]" maxlength="120" placeholder="如 客户官网服务器" value="' + esc(it.label) + '"></td>'
      + '<td><input class="form-control form-control-sm" name="cred[' + i + '][url]" maxlength="500" placeholder="http:// 或 IP:端口" value="' + esc(it.url) + '"></td>'
      + '<td><input class="form-control form-control-sm" name="cred[' + i + '][account]" maxlength="200" autocomplete="off" value="' + esc(it.account) + '"></td>'
      + '<td><input class="form-control form-control-sm" name="cred[' + i + '][password]" autocomplete="new-password" value="' + esc(it.password) + '"></td>'
      + '<td><input class="form-control form-control-sm" name="cred[' + i + '][notes]" placeholder="安全入口、到期时间等" value="' + esc(it.notes) + '"></td>';
    document.getElementById('credRows').appendChild(tr);
  }
  addRow(); addRow();
  document.getElementById('credAddRow').addEventListener('click', function () { addRow(); });
  document.getElementById('credParseBtn').addEventListener('click', function () {
    var btn = this, note = document.getElementById('credParseNote'); btn.disabled = true; note.textContent = '识别中…';
    post({action: 'parse', text: document.getElementById('credPaste').value}).then(function (d) {
      var rows = document.getElementById('credRows');
      Array.prototype.slice.call(rows.querySelectorAll('tr')).forEach(function (tr) { if (!Array.prototype.some.call(tr.querySelectorAll('input'), function (el) { return el.value !== ''; })) tr.remove(); });
      d.items.forEach(addRow);
      note.textContent = d.items.length ? '已识别 ' + d.items.length + ' 条并填入下方，核对后点“保存”' + (d.note ? '；' + d.note : '') : '没有识别出账号信息';
    }).catch(function (e) { note.textContent = e.message; }).then(function () { btn.disabled = false; });
  });
  document.querySelectorAll('.js-cred-copy-text').forEach(function (b) { b.addEventListener('click', function () { copy(b.dataset.text, b); }); });
  document.querySelectorAll('.js-cred-copy').forEach(function (b) { b.addEventListener('click', function () { secret(b.dataset.id, 'copy').then(function (d) { copy(d.password, b); }).catch(function (e) { alert(e.message); }); }); });
  document.querySelectorAll('.js-cred-reveal').forEach(function (b) { b.addEventListener('click', function () {
    var id = b.dataset.id, span = document.querySelector('.js-cred-secret[data-id="' + id + '"]'), notes = document.querySelector('.js-cred-notes[data-id="' + id + '"]');
    secret(id).then(function (d) { if (span) span.textContent = d.password || '（空）'; if (notes) { notes.textContent = d.notes; notes.classList.remove('text-muted'); } }).catch(function (e) { alert(e.message); });
  }); });
});
</script>
