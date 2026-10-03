(function (root) {
  'use strict';
  function escapeRegex(value) { return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
  function validLink(item) {
    try { var u = new URL(item.url); return u.protocol === 'https:' && !u.username && !u.password && /^[^\s<>]{2,40}$/.test(item.keyword.replace(/ /g, '')); } catch (e) { return false; }
  }
  function findMatches(text, links) {
    var byKey = Object.create(null);
    links.forEach(function (item) { if (validLink(item)) byKey[item.keyword.toLowerCase()] = item; });
    var keys = Object.keys(byKey).sort(function (a, b) { return b.length - a.length; });
    if (!keys.length) return [];
    var re = new RegExp(keys.map(escapeRegex).join('|'), 'gi'), matches = [], m;
    while ((m = re.exec(text))) {
      var key = m[0].toLowerCase(), start = m.index, end = start + m[0].length;
      if (/^[a-z0-9_ -]+$/i.test(key) && (/[a-z0-9_]/i.test(text.charAt(start - 1)) || /[a-z0-9_]/i.test(text.charAt(end)))) continue;
      // URL 本身不做子串改写。
      var prefix = text.slice(Math.max(0, start - 200), start);
      if (/https?:\/\/\S*$/i.test(prefix)) continue;
      matches.push({ start: start, end: end, text: m[0], link: byKey[key] });
    }
    return matches;
  }
  if (typeof document === 'undefined' && typeof module !== 'undefined' && module.exports) { module.exports = { findMatches: findMatches, validLink: validLink }; return; }
  var script = document.currentScript;
  function initUI() {
    document.documentElement.dataset.kbUiReady = '1';
    document.querySelectorAll('form[data-kb-transport]').forEach(function (form) {
      form.dataset.kbTransportReady = '1';
      form.addEventListener('submit', function (event) {
        if (event.defaultPrevented) return;
        event.preventDefault();
        if (form.dataset.kbSubmitting === '1') return;
        try {
          var data = {}, fields = form.dataset.kbTransport.split(',');
          fields.forEach(function (key) { var control = form.elements.namedItem(key); if (control) data[key] = control.value; });
          var bytes = new TextEncoder().encode(JSON.stringify(data)), chunks = [];
          for (var i = 0; i < bytes.length; i += 8192) chunks.push(String.fromCharCode.apply(null, bytes.subarray(i, i + 8192)));
          var encoded = btoa(chunks.join(''));
          var count = Math.ceil(encoded.length / 768);
          if (count > 900) throw new Error('too large');
          form.dataset.kbSubmitting = '1';
          crypto.subtle.digest('SHA-256', new TextEncoder().encode(encoded)).then(function (digest) {
            var hex = Array.from(new Uint8Array(digest)).map(function (b) { return b.toString(16).padStart(2, '0'); }).join('');
            function hidden(name, value) { var control = document.createElement('input'); control.type = 'hidden'; control.name = name; control.value = value; form.appendChild(control); }
            hidden('kb_parts_count', count); hidden('kb_content_sha256', hex);
            for (var j = 0; j < count; j++) hidden('kb_part_' + j, encoded.slice(j * 768, (j + 1) * 768));
            fields.forEach(function (key) { var control = form.elements.namedItem(key); if (control) control.disabled = true; });
            form.submit();
          }).catch(function () { form.dataset.kbSubmitting = ''; window.alert('正文校验未完成，请刷新后重试。'); });
        } catch (e) { event.preventDefault(); window.alert('正文传输未准备好，请缩短文章或刷新后重试。'); }
      });
    });
    document.querySelectorAll('form[data-kb-confirm]').forEach(function (form) {
      form.addEventListener('submit', function (event) { if (!window.confirm(form.dataset.kbConfirm)) event.preventDefault(); });
    });
    document.querySelectorAll('[data-kb-open]').forEach(function (link) {
      link.addEventListener('click', function () { var panel = document.getElementById(link.dataset.kbOpen); if (panel) { panel.open = true; var input = panel.querySelector('input[name="title"]'); if (input) setTimeout(function () { input.focus({ preventScroll: true }); }, 100); } });
    });
    var search = document.getElementById('kb-link-search'), category = document.getElementById('kb-link-category');
    if (search && category) {
      var cards = Array.from(document.querySelectorAll('.kb-link-card'));
      function filter() {
        var term = search.value.trim().toLowerCase(), count = 0;
        cards.forEach(function (card) { var show = (!term || card.dataset.search.indexOf(term) !== -1) && (!category.value || card.dataset.category === category.value); card.hidden = !show; if (show) count++; });
        document.getElementById('kb-links-empty').hidden = count !== 0;
        document.getElementById('kb-link-count').textContent = count + ' 个网址';
      }
      search.addEventListener('input', filter); category.addEventListener('change', filter);
    }
  }
  function applyKeywords(links) {
    var container = document.querySelector('.main-content'); if (!container) return;
    var skip = 'a,button,input,textarea,select,option,script,style,noscript,code,pre,label,th,nav,[contenteditable],[data-no-keywords],#credentials,.js-cred-secret,.js-cred-notes,.kb-domain,.kb-card-meta,.kb-keywords';
    function linkNode(node) {
      if (!node.parentElement || node.parentElement.closest(skip) || !node.nodeValue.trim()) return;
      var matches = findMatches(node.nodeValue, links); if (!matches.length) return;
      var text = node.nodeValue, fragment = document.createDocumentFragment(), end = 0;
      matches.forEach(function (match) {
        fragment.appendChild(document.createTextNode(text.slice(end, match.start)));
        var a = document.createElement('a'); a.href = match.link.url; a.target = '_blank'; a.rel = 'noopener noreferrer'; a.className = 'kb-keyword-link'; a.textContent = match.text; a.title = '打开' + match.link.title; fragment.appendChild(a); end = match.end;
      });
      fragment.appendChild(document.createTextNode(text.slice(end))); node.parentNode.replaceChild(fragment, node);
    }
    var observer;
    function scan(element) {
      if (element.nodeType === 3) { linkNode(element); return; }
      if (element.nodeType !== 1 || element.closest(skip)) return;
      var walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT), nodes = [], node;
      while ((node = walker.nextNode())) { if (nodes.length >= 8000) break; nodes.push(node); }
      nodes.forEach(linkNode);
    }
    scan(container);
    var pending = [], timer;
    observer = new MutationObserver(function (changes) {
      changes.forEach(function (change) { if (change.type === 'characterData') pending.push(change.target); else change.addedNodes.forEach(function (n) { if (n.nodeType === 1 || n.nodeType === 3) pending.push(n); }); });
      if (timer) return;
      timer = setTimeout(function () { observer.disconnect(); var batch = pending.splice(0,200); pending.length = 0; batch.forEach(function (n) { if (container.contains(n)) scan(n); }); observer.observe(container,{ childList:true,subtree:true,characterData:true }); timer = null; },120);
    });
    observer.observe(container,{ childList:true,subtree:true,characterData:true });
  }
  function start() {
    initUI();
    if (!script || script.dataset.skipKeywords === '1' || !script.dataset.keywordsUrl) return;
    fetch(script.dataset.keywordsUrl,{ credentials:'same-origin',headers:{ Accept:'application/json' } }).then(function (r) { if (!r.ok || !(r.headers.get('content-type') || '').includes('application/json')) throw new Error('unavailable'); return r.json(); }).then(function (data) { if (Array.isArray(data.links)) applyKeywords(data.links.filter(validLink)); }).catch(function () { /* 知识库不可用时，不影响订单和原页面。 */ });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded',start); else start();
})(typeof window !== 'undefined' ? window : globalThis);
