
(function () {
  var all = document.getElementById('bulkAll');
  if (!all) return;
  var items = document.querySelectorAll('.bulk-item'), count = document.getElementById('bulkCount');
  function refresh() { var n = document.querySelectorAll('.bulk-item:checked').length; count.textContent = '已选 ' + n + ' 单'; document.querySelectorAll('.project-bulk-bar button').forEach(function (b) { b.disabled = n === 0; }); }
  all.addEventListener('change', function () { items.forEach(function (i) { i.checked = all.checked; }); refresh(); });
  items.forEach(function (i) { i.addEventListener('change', refresh); });
  refresh();
})();
