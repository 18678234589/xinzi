
document.addEventListener('DOMContentLoaded', function () {
  var bulk = document.getElementById('kindBulk'); if (!bulk) return;
  bulk.addEventListener('change', function () {
    var all = document.getElementById('kindBulkAll').checked;
    document.querySelectorAll('.js-kind-choice').forEach(function (sel) { if (bulk.value && (all || !sel.value)) { sel.value = bulk.value; sel.classList.remove('is-invalid'); } });
  });
  document.querySelectorAll('.js-kind-choice').forEach(function (sel) { sel.addEventListener('change', function () { sel.classList.toggle('is-invalid', !sel.value); }); });
});
