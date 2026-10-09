/* 续费资料就地补录：填完离开输入框（或选好日期 / 勾选）即自动保存，也可点“保存”；成功后补齐的卡片变绿收起，不整页刷新。 */
(function () {
  function field(card, k) { return card.querySelector('[data-k="' + k + '"]'); }
  function setMsg(card, text, kind) { var m = card.querySelector('.rf-msg'); if (m) { m.textContent = text; m.className = 'rf-msg' + (kind ? ' ' + kind : ''); } }
  function updateCounters(root) {
    var left = 0;
    root.querySelectorAll('.rf-card').forEach(function (c) { if (!c.classList.contains('rf-done')) left++; });
    document.querySelectorAll('[data-rf-left]').forEach(function (n) { n.textContent = left; });
    var good = root.querySelector('.rf-allgood'); if (good) good.hidden = left > 0;
  }
  function hasInput(card) {
    var any = false;
    card.querySelectorAll('[data-k="domain"],[data-k="contact"],[data-k="server"],[data-k="domain_expiry"]').forEach(function (i) { if (i.value && i.value.trim() && !i.disabled) any = true; });
    ['perm', 'dperm', 'own'].forEach(function (k) { var c = field(card, k); if (c && c.checked) any = true; });
    return any;
  }
  function save(btn, auto) {
    var card = btn.closest('.rf-card'); var root = btn.closest('[data-rf-endpoint]');
    if (!card || !root || btn.disabled) return;
    if (auto && !hasInput(card)) return;
    var fd = new FormData();
    fd.append('csrf', root.getAttribute('data-rf-csrf')); fd.append('order_id', card.getAttribute('data-order'));
    var d = field(card, 'domain'), c = field(card, 'contact'), s = field(card, 'server'), perm = field(card, 'perm'), own = field(card, 'own');
    if (d && d.value.trim()) fd.append('domain', d.value.trim());
    if (c && c.value.trim()) fd.append('contact', c.value.trim());
    if (perm && perm.checked) fd.append('server', '永久'); else if (s && s.value) fd.append('server', s.value);
    var de = field(card, 'domain_expiry'), dperm = field(card, 'dperm');
    if (dperm && dperm.checked) fd.append('domain_expiry', '永久'); else if (de && de.value) fd.append('domain_expiry', de.value);
    if (own && own.checked) { fd.append('owner', 'customer'); fd.append('owner_note', '客户自备域名'); }
    btn.disabled = true; var old = btn.textContent; btn.textContent = '保存中…'; setMsg(card, '', '');
    fetch(root.getAttribute('data-rf-endpoint'), { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        btn.disabled = false; btn.textContent = old;
        if (!j.ok) { setMsg(card, j.message || '保存失败', 'err'); return; }
        var prefix = auto ? '✔ 已自动保存：' : '✔ ';
        var msg = j.message.replace(/^已保存：/, '');
        if (j.complete) {
          card.classList.add('rf-done'); setMsg(card, prefix + msg + '，已补齐', 'ok'); updateCounters(root);
          setTimeout(function () { card.style.display = 'none'; }, 1200);
        } else {
          setMsg(card, prefix + msg + (j.still && j.still.length ? '；还缺 ' + j.still.join('、') : ''), 'ok');
        }
      })
      .catch(function () { btn.disabled = false; btn.textContent = old; setMsg(card, '网络异常，请点保存重试', 'err'); });
  }
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest ? ev.target.closest('.rf-save') : null;
    if (btn) save(btn, false);
  });
  document.addEventListener('keydown', function (ev) {
    var t = ev.target;
    if (ev.key !== 'Enter' || !t.matches || !t.matches('.rf-card input') || t.type === 'checkbox') return;
    ev.preventDefault(); var b = t.closest('.rf-card').querySelector('.rf-save'); if (b) b.click();
  });
  var timers = new WeakMap();
  document.addEventListener('change', function (ev) {
    var t = ev.target;
    if (!t.matches) return;
    if (t.matches('[data-k="perm"],[data-k="dperm"]')) {
      var s = t.closest('.rf-date').querySelector('[data-k="server"],[data-k="domain_expiry"]'); if (s) s.disabled = t.checked;
    }
    if (!t.matches('.rf-card [data-k]')) return;
    var card = t.closest('.rf-card'); var btn = card.querySelector('.rf-save');
    if (!btn || card.classList.contains('rf-done')) return;
    clearTimeout(timers.get(card));
    // 稍等 350ms，避免连着改两个框时发两次；点“保存”按钮时的失焦也不会重复提交
    timers.set(card, setTimeout(function () { save(btn, true); }, 350));
  });
  // 点“保存”时取消尚未触发的自动保存
  document.addEventListener('mousedown', function (ev) {
    var b = ev.target.closest ? ev.target.closest('.rf-save') : null; if (!b) return;
    var card = b.closest('.rf-card'); if (card) clearTimeout(timers.get(card));
  });
})();
