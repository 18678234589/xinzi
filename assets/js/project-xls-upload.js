(function () {
  document.querySelectorAll('form[data-legacy-xls-upload]').forEach(function (form) {
    var source = form.querySelector('input[name="file"]');
    var parsed = form.querySelector('input[name="parsed_file"]');
    var status = form.querySelector('[data-xls-status]');
    if (!source || !parsed) return;
    form.addEventListener('submit', async function (event) {
      var file = source.files && source.files[0];
      if (!file || !/\.xls$/i.test(file.name)) return;
      event.preventDefault();
      if (!window.XLSX) { status.textContent = '旧版 XLS 解析组件未加载，请刷新页面后重试'; return; }
      var button = form.querySelector('[type="submit"]');
      if (button) button.disabled = true;
      status.textContent = '正在读取旧版 XLS，原文件会保留，转换件只用于核对…';
      try {
        var workbook = window.XLSX.read(await file.arrayBuffer(), { type: 'array', cellDates: false });
        var data = window.XLSX.write(workbook, { type: 'array', bookType: 'xlsx' });
        if (data.byteLength > 25 * 1024 * 1024) throw new Error('转换后的文件超过 25 MB，请拆分工作表上传');
        var converted = new File([data], file.name.replace(/\.xls$/i, '.xlsx'), { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
        var transfer = new DataTransfer();
        transfer.items.add(converted);
        parsed.files = transfer.files;
        status.textContent = '转换完成，正在上传原件并核对…';
        form.submit();
      } catch (error) {
        status.textContent = '旧版 XLS 无法转换：' + (error && error.message ? error.message : '请另存为 XLSX 后重试');
        if (button) button.disabled = false;
      }
    });
  });
})();
