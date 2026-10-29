(function () {
  'use strict';
  var form = document.getElementById('cdnCreateForm');
  if (form) {
    var source = document.getElementById('cdnSourceType');
    var protocol = document.getElementById('cdnProtocol');
    var region = document.getElementById('cdnRegion');
    function sync() {
      var bucket = source.value === 'qiniuBucket';
      document.querySelectorAll('[data-origin-field]').forEach(function (el) { el.hidden = bucket; });
      document.getElementById('cdnSourceValue').placeholder = bucket ? '已有存储空间名称' : (source.value === 'domain' ? 'origin.example.com' : '例如 1.2.3.4');
      document.getElementById('cdnSourceHelp').textContent = bucket ? '填写账号中已有的七牛存储空间，动态加速不支持此源站。' : (source.value === 'domain' ? '不要填写加速域名本身，以免 CNAME 接入后回源循环。' : '服务器公网 IPv4；多个地址用逗号分隔。');
      var https = protocol.value === 'https';
      document.getElementById('cdnCert').hidden = !https;
      document.getElementById('cdnCert').querySelector('select').required = https;
      document.getElementById('cdnForceHttps').hidden = !https;
      var icp = document.getElementById('cdnIcp');
      icp.hidden = region.value === 'foreign';
      icp.querySelector('input').required = region.value !== 'foreign';
    }
    [source, protocol, region].forEach(function (el) { el.addEventListener('change', sync); });
    sync();
  }
  document.querySelectorAll('[data-copy]').forEach(function (button) {
    button.addEventListener('click', function () {
      var text = button.getAttribute('data-copy');
      if (!navigator.clipboard) return;
      navigator.clipboard.writeText(text).then(function () { button.textContent = '已复制'; }).catch(function () { button.textContent = '请选中地址复制'; });
    });
  });
  // 列表按「近 7 天流量 / 今日」排序（只排当前页已显示的域名；再点一次反向）。
  document.querySelectorAll('.cdn-sort').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var key = btn.dataset.sort, body = btn.closest('table').tBodies[0], dir = btn.dataset.dir === 'desc' ? 'asc' : 'desc';
      document.querySelectorAll('.cdn-sort').forEach(function (b) { delete b.dataset.dir; b.querySelector('span').textContent = '↕'; });
      btn.dataset.dir = dir; btn.querySelector('span').textContent = dir === 'desc' ? '↓' : '↑';
      var rows = Array.prototype.slice.call(body.querySelectorAll('tr[data-name]'));
      rows.sort(function (a, b) {
        var x = Number(a.querySelector('[data-sort-' + key + ']').dataset['sort' + key.charAt(0).toUpperCase() + key.slice(1)]), y = Number(b.querySelector('[data-sort-' + key + ']').dataset['sort' + key.charAt(0).toUpperCase() + key.slice(1)]);
        return dir === 'desc' ? y - x : x - y;
      });
      rows.forEach(function (r) { body.appendChild(r); });
    });
  });

  // 预览卡片交互增强：自动平滑滚动定位、一键填入确认域名
  var previewCard = document.getElementById('cdnPreview');
  if (previewCard) {
    try { previewCard.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) {}
  }
  var fillBtn = document.getElementById('cdnFillConfirm');
  var confirmInput = document.getElementById('cdnConfirmInput');
  if (fillBtn && confirmInput) {
    fillBtn.addEventListener('click', function () {
      confirmInput.value = fillBtn.getAttribute('data-fill') || '';
      confirmInput.focus();
    });
  }

  // 待验证状态后台静默定时探测（每 4 秒轮询一次，最多 15 次，一旦通过直接更新徽标并解锁提交）
  var verifyBox = document.querySelector('.cdn-verify.doing');
  if (verifyBox) {
    var tokenInput = document.querySelector('input[name="token"]');
    var csrfInput = document.querySelector('input[name="csrf"]');
    var submitBtn = document.getElementById('cdnSubmitBtn');
    if (tokenInput && csrfInput) {
      var pollCount = 0;
      var pollTimer = setInterval(function () {
        pollCount++;
        if (pollCount > 15) { clearInterval(pollTimer); return; }
        var fd = new FormData();
        fd.append('csrf', csrfInput.value);
        fd.append('action', 'check_verify');
        fd.append('token', tokenInput.value);
        fetch('cdn.php', { method: 'POST', body: fd, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            if (res && res.ok && res.verified) {
              clearInterval(pollTimer);
              verifyBox.classList.remove('doing');
              verifyBox.classList.add('success');
              var badge = verifyBox.querySelector('.cdn-dns');
              if (badge) {
                badge.className = 'cdn-dns ok';
                badge.textContent = '已验证';
              }
              var notice = document.createElement('div');
              notice.className = 'dns-notice ok';
              notice.textContent = '主域名已通过七牛所有权验证！现在可以直接确认添加。';
              verifyBox.parentNode.insertBefore(notice, verifyBox);
              if (submitBtn) {
                submitBtn.removeAttribute('disabled');
                submitBtn.removeAttribute('title');
              }
              var actions = verifyBox.querySelector('.cdn-verify-actions');
              if (actions) actions.style.display = 'none';
            }
          })
          .catch(function () {});
      }, 4000);
    }
  }
})();
