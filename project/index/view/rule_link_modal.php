<?php
/**
 * 订单列表“待配置 · 去对应”弹窗：列出规则中心里同业务同组的规则，一键对应（改订单类型 / 岗位，或启用被停用的规则）。接口见 project/rule_link_api.php。
 */
?>
<div class="modal fade" id="ruleLinkModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable" role="document">
    <div class="modal-content shadow-lg border-0" style="border-radius:14px;overflow:hidden">
      <div class="modal-header py-3" style="background:#f4f7f6;border-bottom:1px solid #e1e9e5">
        <h6 class="modal-title font-weight-bold mb-0"><i class="fas fa-link text-primary mr-1"></i> 对应分成规则 <span class="small text-muted ml-2" id="rlOrder"></span></h6>
        <button type="button" class="close" data-dismiss="modal" aria-label="关闭"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body p-3" id="rlBody"><div class="text-muted small">正在读取…</div></div>
      <div class="modal-footer py-2" style="background:#f9fbfb"><span class="small text-muted mr-auto">规则内容在“规则中心”维护；这里只负责把订单和规则对上。</span><button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">关闭</button></div>
    </div>
  </div>
</div>
<style>
.rl-person{border:1px solid #dfe7e2;border-radius:12px;padding:12px 14px;margin-bottom:12px}
.rl-person h6{margin:0 0 4px;font-weight:700;color:#1f3a2c}.rl-why{font-size:.82rem;color:#b26a00;margin-bottom:8px}
.rl-rule{display:flex;align-items:center;gap:10px;border-top:1px dashed #e4ebe7;padding:8px 0}.rl-rule .rl-main{flex:1;min-width:0}
.rl-rule .rl-t{font-size:.85rem;color:#33443a}.rl-rule .rl-s{font-size:.78rem;color:#7a8a80}.rl-off{opacity:.8}
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var modal = document.getElementById('ruleLinkModal');
  if (!modal) return;
  document.body.appendChild(modal);
  var body = document.getElementById('rlBody'), title = document.getElementById('rlOrder');
  var api = '<?php echo BASE_URL; ?>/project/rule_link_api.php', csrf = <?php echo json_encode(ps_csrf_token()); ?>;
  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; }
  function note(text, cls) { body.innerHTML = ''; body.appendChild(el('div', cls || 'text-muted small', text)); }
  function apply(orderId, empId, rule, btn) {
    btn.disabled = true; btn.textContent = '正在保存…';
    fetch(api, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({csrf: csrf, action: 'apply', order_id: orderId, employee_id: empId, rule_id: rule})})
      .then(function (r) { return r.json().then(function (d) { return [r.ok, d]; }); })
      .then(function (x) {
        if (x[0] && x[1].ok) { note('已对应：' + x[1].msg, 'alert alert-success'); setTimeout(function () { location.reload(); }, 600); }
        else { btn.disabled = false; btn.textContent = '重试'; alert(x[1].error || '保存失败，请重试'); }
      }).catch(function () { btn.disabled = false; btn.textContent = '重试'; alert('网络异常，请刷新页面重试'); });
  }
  function render(d) {
    title.textContent = d.order.order_no + ' · ' + d.order.business + ' · 类型：' + d.order.kind_label;
    body.innerHTML = '';
    if (!d.people.length) { body.appendChild(el('div', 'alert alert-success mb-0', '这张订单的分成规则已经全部对上了，刷新页面即可看到金额。')); return; }
    if (!d.can_edit) body.appendChild(el('div', 'alert alert-warning small', d.why || '你没有权限修改这张订单'));
    d.people.forEach(function (p) {
      var box = el('div', 'rl-person');
      box.appendChild(el('h6', '', p.name + '（' + (p.group === 'technical' ? '技术组' : '客服组') + ' · 岗位：' + (p.role || '未填') + '）'));
      box.appendChild(el('div', 'rl-why', '没对上的原因：' + p.reason));
      p.rules.forEach(function (r) {
        var row = el('div', 'rl-rule' + (r.active ? '' : ' rl-off')), main = el('div', 'rl-main');
        main.appendChild(el('div', 'rl-t', '订单类型：' + r.kind_label + ' · 岗位：' + (r.role === '*' ? '全部' : r.role) + (r.active ? '' : '（已停用）')));
        main.appendChild(el('div', 'rl-s', r.text));
        row.appendChild(main);
        if (d.can_edit && r.action) {
          var blocked = r.needs_enable && !d.can_enable;
          var b = el('button', 'btn btn-sm ' + (blocked ? 'btn-outline-secondary' : 'btn-success'), blocked ? '需主管或财务启用' : r.action);
          b.type = 'button';
          if (blocked) b.disabled = true; else b.addEventListener('click', function () { apply(d.order.id, p.employee_id, r.rule_id, b); });
          row.appendChild(b);
        }
        box.appendChild(row);
      });
      if (!p.rules.length) box.appendChild(el('div', 'small text-muted', '请财务到“规则中心”为这个业务新增对应规则。'));
      body.appendChild(box);
    });
  }
  document.addEventListener('click', function (ev) {
    var t = ev.target.closest ? ev.target.closest('.js-rule-link') : null;
    if (!t) return;
    ev.preventDefault();
    note('正在读取…'); title.textContent = '';
    window.jQuery(modal).modal('show');
    fetch(api + '?order_id=' + encodeURIComponent(t.getAttribute('data-id')), {credentials: 'same-origin'})
      .then(function (r) { return r.json().then(function (d) { return [r.ok, d]; }); })
      .then(function (x) { if (x[0]) render(x[1]); else note(x[1].error || '读取失败', 'alert alert-danger'); })
      .catch(function () { note('网络异常，请刷新页面重试', 'alert alert-danger'); });
  });
});
</script>
