/* 域名管理：在域名详情抽屉里加一个“去 DNSPod / Cloudflare 管理解析”的链接，不改动域名管理自身的脚本。 */
(function () {
  'use strict';
  var title = document.getElementById('domainDetailName');
  if (!title) return;
  var link = null;
  function sync() {
    var name = (title.textContent || '').trim();
    if (!/^[a-z0-9.-]+\.[a-z0-9-]{2,}$/i.test(name)) return; // 还没读到真实域名
    if (!link) {
      link = document.createElement('a');
      link.className = 'dns-drawer-link';
      link.innerHTML = '<i class="fas fa-network-wired" aria-hidden="true"></i> 用 DNSPod / Cloudflare 解析';
      title.parentNode.appendChild(link);
    }
    link.href = 'dns.php?d=' + encodeURIComponent(name.toLowerCase());
  }
  new MutationObserver(sync).observe(title, { childList: true, characterData: true, subtree: true });
  sync();
})();
