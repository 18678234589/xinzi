(function () {
  'use strict';
  var box = document.getElementById('projectAnnouncement');
  if (!box || !window.jQuery || !jQuery.fn.modal) return;
  var modal = jQuery(box), settled = false, busy = false, observer;
  function anotherDialog() {
    return Array.prototype.some.call(document.querySelectorAll('.modal.show,.rf-mask:not([hidden])'), function (el) { return el !== box; });
  }
  function showWhenReady() {
    if (settled || anotherDialog()) return;
    if (observer) observer.disconnect();
    modal.modal({backdrop: 'static', keyboard: false, show: true});
  }
  // 等其他资料弹窗关闭后再显示，不打断尚未保存的输入。
  observer = new MutationObserver(showWhenReady);
  observer.observe(document.body, {attributes: true, subtree: true, attributeFilter: ['class', 'hidden']});
  jQuery(document).on('hidden.bs.modal', showWhenReady);
  function buttons(disabled) {
    box.querySelectorAll('[data-announcement-action]').forEach(function (el) { el.disabled = disabled; });
  }
  box.querySelectorAll('[data-announcement-action]').forEach(function (button) {
    button.addEventListener('click', function () {
      if (busy) return;
      var action = button.getAttribute('data-announcement-action');
      var body = new URLSearchParams({csrf: box.dataset.csrf, message_id: box.dataset.messageId, action: action});
      var error = box.querySelector('.announcement-error');
      var controller = new AbortController();
      var timeout = setTimeout(function () { controller.abort(); }, 12000);
      busy = true; buttons(true); error.hidden = true;
      fetch(box.dataset.endpoint, {method: 'POST', credentials: 'same-origin', body: body, signal: controller.signal})
        .then(function (response) { return response.json(); })
        .then(function (result) {
          if (!result.ok) throw new Error(result.error || '暂时未能保存，请稍后重试');
          settled = true; modal.modal('hide');
        })
        .catch(function () {
          if (action === 'defer') { settled = true; modal.modal('hide'); return; }
          error.textContent = '暂时未能标记已读，你可以重试，或点“稍后查看”继续操作。'; error.hidden = false;
        })
        .finally(function () { clearTimeout(timeout); busy = false; buttons(false); });
    });
  });
  showWhenReady();
})();
