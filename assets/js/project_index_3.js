
(function () {
  var all = document.getElementById('bulkAll');
  if (!all) return;
  var items = document.querySelectorAll('.bulk-item'), count = document.getElementById('bulkCount');
  function refresh() { var n = document.querySelectorAll('.bulk-item:checked').length; count.textContent = '已选 ' + n + ' 单'; document.querySelectorAll('.project-bulk-bar button').forEach(function (b) { b.disabled = n === 0; }); }
  all.addEventListener('change', function () { items.forEach(function (i) { i.checked = all.checked; }); refresh(); });
  items.forEach(function (i) { i.addEventListener('change', refresh); });
  refresh();
})();

/* 订单表：在表格任意位置按住左右拖动即可平移（小屏不用够到底部滚动条）；表格高度限制在一屏内，横向滚动条始终可见 */
(function () {
  var t = document.querySelector('.project-order-table'), box = t && t.closest('.table-responsive');
  if (!box) return;
  var down = false, moved = false, x0 = 0, left0 = 0;
  box.addEventListener('mousedown', function (e) {
    if (e.button !== 0 || e.target.closest('a,button,input,select,textarea,label,summary')) return;
    down = true; moved = false; x0 = e.clientX; left0 = box.scrollLeft;
  });
  window.addEventListener('mousemove', function (e) {
    if (!down) return;
    var dx = e.clientX - x0;
    if (!moved && Math.abs(dx) < 4) return;
    moved = true; box.classList.add('is-dragging'); box.scrollLeft = left0 - dx; e.preventDefault();
  });
  window.addEventListener('mouseup', function () { down = false; box.classList.remove('is-dragging'); });
  box.addEventListener('click', function (e) { if (moved) { e.preventDefault(); e.stopPropagation(); moved = false; } }, true);
})();
