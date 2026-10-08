
// 点预计分成金额：按需加载“计算过程”。弹窗挂到 body 下（卡片的毛玻璃样式会把弹窗困在遮罩后面）。
document.addEventListener('DOMContentLoaded', function () {
  var modal = document.getElementById('calcAjaxModal');
  if (!modal) return;
  document.body.appendChild(modal);
  var box = modal.querySelector('.modal-content');
  var closeBtn = '<div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">关闭</button></div>';
  document.addEventListener('click', function (ev) {
    var link = ev.target.closest ? ev.target.closest('.calc-open') : null;
    if (!link) return;
    ev.preventDefault();
    box.innerHTML = '<div class="modal-body text-center text-muted py-5">正在加载计算过程…</div>';
    window.jQuery(modal).modal('show');
    var url = '<?php echo BASE_URL; ?>/project/calc_modal.php?order_id=' + encodeURIComponent(link.dataset.order) + '&employee_id=' + encodeURIComponent(link.dataset.employee) + '&group=' + encodeURIComponent(link.dataset.group);
    fetch(url, { credentials: 'same-origin' }).then(function (r) {
      return r.text().then(function (txt) {
        if (r.ok) { box.innerHTML = txt; return; }
        var body = document.createElement('div'); body.className = 'modal-body text-danger'; body.textContent = txt || '加载失败';
        box.innerHTML = ''; box.appendChild(body); box.insertAdjacentHTML('beforeend', closeBtn);
      });
    }).catch(function () { box.innerHTML = '<div class="modal-body text-danger">加载失败，请重试</div>' + closeBtn; });
  });
});
