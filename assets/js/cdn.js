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
})();
