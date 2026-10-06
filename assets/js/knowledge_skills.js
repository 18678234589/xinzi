/* 沟通技巧库 / 成本速查的小交互：一键复制、"/" 聚焦搜索、拖拽上传、提示条。无依赖。 */
(function () {
  'use strict';
  var toastEl, toastTimer;
  function toast(text) {
    if (!toastEl) { toastEl = document.createElement('div'); toastEl.className = 'kbx-toast'; toastEl.setAttribute('role', 'status'); document.body.appendChild(toastEl); }
    toastEl.textContent = text; toastEl.classList.add('is-on');
    clearTimeout(toastTimer); toastTimer = setTimeout(function () { toastEl.classList.remove('is-on'); }, 1800);
  }
  window.kbxToast = toast;

  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
    return new Promise(function (resolve, reject) {
      var ta = document.createElement('textarea'); ta.value = text; ta.style.cssText = 'position:fixed;opacity:0'; document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy') ? resolve() : reject(); } catch (e) { reject(e); } document.body.removeChild(ta);
    });
  }

  // [data-copy]：直接复制属性内容；[data-copy-from]：复制指定元素的文字
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest && ev.target.closest('[data-copy],[data-copy-from]');
    if (!btn) return;
    ev.preventDefault(); ev.stopPropagation();
    var text = btn.getAttribute('data-copy');
    if (text === null) { var src = document.querySelector(btn.getAttribute('data-copy-from')); text = src ? src.innerText : ''; }
    copyText(text).then(function () { toast('已复制，去粘贴吧'); }, function () { toast('复制失败，请手动选择文字'); });
  });

  // "/" 聚焦搜索框（不在输入框内时）
  document.addEventListener('keydown', function (ev) {
    if (ev.key !== '/' || ev.ctrlKey || ev.metaKey || ev.altKey) return;
    var t = ev.target; if (t && /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName)) return;
    var box = document.querySelector('.kbx-search input'); if (box) { ev.preventDefault(); box.focus(); box.select(); }
  });

  // 拖拽上传：.kbx-drop 里的 input[type=file]，读成文本后交给 data-target 指定的 textarea
  document.querySelectorAll('.kbx-drop').forEach(function (zone) {
    var input = zone.querySelector('input[type=file]'), target = document.querySelector(zone.getAttribute('data-target'));
    function load(file) {
      if (!file || !target) return;
      if (file.size > 400000) { toast('文件太大，请分成几个文件（每个不超过 12000 字）'); return; }
      var r = new FileReader(); r.onload = function () { target.value = r.result; toast('已读入「' + file.name + '」，点“智能识别”'); target.dispatchEvent(new Event('input')); }; r.readAsText(file, 'utf-8');
    }
    zone.addEventListener('click', function (e) { if (e.target !== input) input.click(); });
    input.addEventListener('change', function () { load(input.files[0]); });
    ['dragenter', 'dragover'].forEach(function (n) { zone.addEventListener(n, function (e) { e.preventDefault(); zone.classList.add('is-over'); }); });
    ['dragleave', 'drop'].forEach(function (n) { zone.addEventListener(n, function (e) { e.preventDefault(); zone.classList.remove('is-over'); }); });
    zone.addEventListener('drop', function (e) { load(e.dataTransfer.files[0]); });
    zone.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } });
  });

  // 抽屉：[data-drawer-open="#id"] 打开，[data-drawer-close] 或点遮罩关闭，Esc 关闭
  function setDrawer(drawer, on) {
    var mask = document.querySelector('.kbx-drawer-mask'); if (!drawer) return;
    drawer.classList.toggle('is-open', on); if (mask) mask.classList.toggle('is-open', on); drawer.setAttribute('aria-hidden', on ? 'false' : 'true');
    if (on) { var f = drawer.querySelector('input:not([type=hidden]),textarea'); if (f) setTimeout(function () { f.focus(); }, 280); }
  }
  window.kbxDrawer = setDrawer;
  document.addEventListener('click', function (ev) {
    var open = ev.target.closest && ev.target.closest('[data-drawer-open]');
    if (open) { setDrawer(document.querySelector(open.getAttribute('data-drawer-open')), true); return; }
    if (ev.target.closest && (ev.target.closest('[data-drawer-close]') || ev.target.classList.contains('kbx-drawer-mask'))) setDrawer(document.querySelector('.kbx-drawer.is-open'), false);
  });
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') setDrawer(document.querySelector('.kbx-drawer.is-open'), false); });

  // 示例填充：[data-fill="#textarea"] + data-sample
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest && ev.target.closest('[data-fill]'); if (!b) return;
    var t = document.querySelector(b.getAttribute('data-fill')); if (t) { t.value = b.getAttribute('data-sample'); t.focus(); }
  });
})();
