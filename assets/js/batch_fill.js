(function () {
  'use strict';
  var input = document.querySelector('#pbfUpload input[name="file"]'), drop = document.getElementById('pbfDrop'), name = document.getElementById('pbfFileName');
  if (input && drop) {
    function showName() { name.textContent = input.files.length ? input.files[0].name : '支持 XLSX / XLS / CSV · 20 MB 以内'; }
    input.addEventListener('change', showName);
    ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-drag'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function () { drop.classList.remove('is-drag'); }); });
    drop.addEventListener('drop', function (e) { e.preventDefault(); if (e.dataTransfer.files.length) { var dt = new DataTransfer(); dt.items.add(e.dataTransfer.files[0]); input.files = dt.files; showName(); } });
  }
  var all = document.getElementById('pbfSelectAll');
  if (all) all.addEventListener('change', function () { document.querySelectorAll('input[name="selected[]"]:not(:disabled)').forEach(function (c) { c.checked = all.checked; }); });
  var download = document.getElementById('pbfDownload'), status = document.getElementById('pbfDownloadStatus');
  if (download) download.addEventListener('click', async function () {
    download.disabled = true; status.textContent = '正在整理你的待补订单…';
    try {
      if (!window.XLSX) throw new Error('Excel 组件未加载，可点击备用 CSV 下载');
      var response = await fetch(download.dataset.url, { credentials: 'same-origin' }), data = await response.json();
      if (!response.ok || data.error) throw new Error(data.error || '模板读取失败，请稍后重试');
      var book = XLSX.utils.book_new(), sheet = XLSX.utils.aoa_to_sheet(data.rows);
      sheet['!cols'] = [{ wch: 10 }, { wch: 26 }, { wch: 16 }, { wch: 20 }, { wch: 34 }].concat(Array(10).fill({ wch: 23 }));
      XLSX.utils.book_append_sheet(book, sheet, '订单资料补全');
      XLSX.utils.book_append_sheet(book, XLSX.utils.aoa_to_sheet([
        ['填写说明'], ['只填写空白资料列；订单ID、订单号、业务类型和参考列请保持不变。'],
        ['手机号或海外客户微信号任填一项即可，也可同时填写；微信号不发送短信。'],
        ['重复信息会跳过；与已有内容不同的会单独提示，不会覆盖。'],
        ['预计到期日可以补为实际日期；已核实日期不能用此入口覆盖。'],
        ['客户自有域名请填归属备注；所有日期按 YYYY-MM-DD 填写。'],
        ['售价、收款、成本和分成不在此模板修改；请用原有订单更正入口。'],
        ['上传时找不到的订单不会新建；技术须先被客服关联为该订单参与人。']
      ]), '填写说明');
      XLSX.writeFile(book, '订单资料补全模板.xlsx'); status.textContent = '已下载 ' + data.count + ' 张订单，填空后拖拽上传即可';
    } catch (e) { status.textContent = e.message || '下载失败，请使用备用 CSV'; }
    finally { download.disabled = false; }
  });
})();
