/* 续费资料就地补录：点“保存”用 fetch 提交，成功后卡片变绿收起，不整页刷新。 */
(function () {
  function field(card, k) { return card.querySelector('[data-k="' + k + '"]'); }
  function setMsg(card, text, kind) { var m = card.querySelector('.rf-msg'); if (m) { m.textContent = text; m.className = 'rf-msg' + (kind ? ' ' + kind : ''); } }
  function updateCounters(root) {
    var left = 0;
    root.querySelectorAll('.rf-card').forEach(function (c) { if (!c.classList.contains('rf-done')) left++; });
    document.querySelectorAll('[data-rf-left]').forEach(function (n) { n.textContent = left; });
    var good = root.querySelector('.rf-allgood'); if (good) good.hidden = left > 0;
  }
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest ? ev.target.closest('.rf-save') : null;
    if (!btn) return;
    var card = btn.closest('.rf-card'); var root = btn.closest('[data-rf-endpoint]');
    if (!card || !root || btn.disabled) return;
    var fd = new FormData();
    fd.append('csrf', root.getAttribute('data-rf-csrf')); fd.append('order_id', card.getAttribute('data-order'));
    var d = field(card, 'domain'), c = field(card, 'contact'), s = field(card, 'server'), perm = field(card, 'perm'), own = field(card, 'own');
    if (d && d.value.trim()) fd.append('domain', d.value.trim());
    if (c && c.value.trim()) fd.append('contact', c.value.trim());
    if (perm && perm.checked) fd.append('server', '永久'); else if (s && s.value) fd.append('server', s.value);
    if (own && own.checked) { fd.append('owner', 'customer'); fd.append('owner_note', '客户自备域名'); }
    btn.disabled = true; var old = btn.textContent; btn.textContent = '保存中…'; setMsg(card, '', '');
    fetch(root.getAttribute('data-rf-endpoint'), { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        btn.disabled = false; btn.textContent = old;
        if (!j.ok) { setMsg(card, j.message || '保存失败', 'err'); return; }
        if (j.complete) {
          card.classList.add('rf-done'); setMsg(card, '✔ ' + j.message + '，已补齐', 'ok'); updateCounters(root);
          setTimeout(function () { card.style.display = 'none'; }, 900);
        } else {
          setMsg(card, j.message + (j.still && j.still.length ? '；还缺 ' + j.still.join('、') : ''), 'ok');
        }
      })
      .catch(function () { btn.disabled = false; btn.textContent = old; setMsg(card, '网络异常，请重试', 'err'); });
  });
  document.addEventListener('keydown', function (ev) {
    var t = ev.target;
    if (ev.key !== 'Enter' || !t.matches || !t.matches('.rf-card input') || t.type === 'checkbox') return;
    ev.preventDefault(); var b = t.closest('.rf-card').querySelector('.rf-save'); if (b) b.click();
  });
  document.addEventListener('change', function (ev) {
    var t = ev.target;
    if (!t.matches || !t.matches('[data-k="perm"]')) return;
    var s = t.closest('.rf-date').querySelector('[data-k="server"]'); if (s) s.disabled = t.checked;
  });
})();
