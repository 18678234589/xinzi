
document.addEventListener('DOMContentLoaded', function () {
  var csrf = <?php echo json_encode(ps_csrf_token()); ?>, url = <?php echo json_encode(BASE_URL . '/project/order_fix_api.php'); ?>;
  document.querySelectorAll('.js-fix-submit').forEach(function (b) { b.addEventListener('click', function () {
    var box = b.closest('.import-fix'), msg = box.querySelector('.js-fix-msg'), changes = {};
    box.querySelectorAll('.js-fixf:checked').forEach(function (c) { changes[c.value] = c.getAttribute('data-new'); });
    if (!Object.keys(changes).length) { msg.textContent = '请至少勾选一项'; msg.className = 'small js-fix-msg text-danger'; return; }
    b.disabled = true; msg.textContent = '提交中…'; msg.className = 'small js-fix-msg text-muted';
    fetch(url, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({csrf: csrf, action: 'fix', order_id: box.dataset.orderId, changes: changes})}).then(function (r) {
      return r.text().then(function (t) { var d; try { d = JSON.parse(t); } catch (e) { throw new Error(r.status === 403 ? '没有权限更正这个订单（需要是订单参与人）' : '服务器返回了非预期内容，请刷新页面后重试'); } if (d.error) throw new Error(d.error); return d; });
    }).then(function (d) {
      msg.textContent = d.mode === 'applied' ? '✓ 原单已更正，请点上方“重新核对”' : '✓ 已提交，财务确认后点“重新核对”即可';
      msg.className = 'small js-fix-msg text-success';
    }).catch(function (e) { msg.textContent = e.message; msg.className = 'small js-fix-msg text-danger'; b.disabled = false; });
  }); });
});
