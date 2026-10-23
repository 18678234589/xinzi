/* 举证图片上传区：多选、拖入、粘贴截图都汇入同一个文件框，图片显示缩略图并可单独移除。 */
(function () {
  function init(zone) {
    if (zone.dataset.ready) return; zone.dataset.ready = '1';
    var input = zone.querySelector('input[type=file]'), picked = zone.querySelector('[data-picked]'), thumbs = zone.querySelector('[data-thumbs]'), files = [], urls = [];
    function sync() {
      if (typeof DataTransfer !== 'undefined') { var dt = new DataTransfer(); files.forEach(function (f) { dt.items.add(f); }); input.files = dt.files; }
      urls.forEach(function (u) { URL.revokeObjectURL(u); }); urls = [];
      if (thumbs) {
        thumbs.innerHTML = '';
        files.forEach(function (f, i) {
          var box = document.createElement('span'); box.className = 'evidence-thumb';
          if (/^image\//.test(f.type)) { var img = document.createElement('img'), u = URL.createObjectURL(f); urls.push(u); img.src = u; img.alt = f.name; box.appendChild(img); }
          else { box.innerHTML = '<i class="fas fa-file-pdf"></i>'; }
          var x = document.createElement('button'); x.type = 'button'; x.title = '移除 ' + f.name; x.setAttribute('aria-label', '移除 ' + f.name); x.textContent = '×';
          x.addEventListener('click', function () { files.splice(i, 1); sync(); }); box.appendChild(x); thumbs.appendChild(box);
        });
      }
      picked.textContent = files.length ? '已选 ' + files.length + ' 个：' + files.map(function (f) { return f.name; }).join('、') : '';
    }
    function add(list) {
      Array.prototype.forEach.call(list, function (f) {
        if (!/^image\/|application\/pdf/.test(f.type)) return;
        if (!f.name || f.name === 'image.png') f = new File([f], '截图-' + new Date().toISOString().slice(0, 19).replace(/[-:T]/g, '') + '-' + (files.length + 1) + '.png', { type: f.type });
        files.push(f);
      });
      sync();
    }
    input.addEventListener('change', function () { var fresh = Array.prototype.slice.call(input.files); files = files.concat(fresh.filter(function (f) { return !files.some(function (o) { return o.name === f.name && o.size === f.size; }); })); sync(); });
    zone.addEventListener('dragover', function (e) { e.preventDefault(); zone.classList.add('over'); });
    zone.addEventListener('dragleave', function () { zone.classList.remove('over'); });
    zone.addEventListener('drop', function (e) { e.preventDefault(); zone.classList.remove('over'); add(e.dataTransfer.files); });
    var form = zone.closest('form');
    if (form) form.addEventListener('paste', function (e) { var items = (e.clipboardData && e.clipboardData.files) || []; if (items.length) { e.preventDefault(); add(items); } });
  }
  function boot() { document.querySelectorAll('.evidence-drop[data-drop]').forEach(init); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
