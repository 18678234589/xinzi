(function () {
  if (window.payrollRequestBound) return;
  window.payrollRequestBound = true;
  document.addEventListener('submit', function (ev) {
    var f = ev.target; if (!f.classList.contains('payroll-request-form')) return;
    ev.preventDefault();
    var button = f.querySelector('button'), message = f.querySelector('.payroll-request-message');
    if (!button || button.disabled || !confirm(button.textContent.trim() + '（' + f.dataset.month + '）？' + (f.dataset.people ? '\n代申请名单：\n' + f.dataset.people : '') + '\n按扣减后的实结算计算，已申请和已打款不会重复提交。\n提交后由财务核对，审核通过后方可打款。')) return;
    button.disabled = true; message.hidden = false; message.textContent = '正在提交…';
    // 表单里有名为 action 的隐藏输入框，f.action 会取到那个输入框而不是提交地址（请求发到 /project/[object HTMLInputElement] → 404），必须用 getAttribute。
    fetch(f.getAttribute('action'), { method: 'POST', credentials: 'same-origin', body: new FormData(f) })
      .then(function (r) { return r.json().catch(function () { throw Error('提交失败（' + r.status + '）'); }); })
      .then(function (r) {
        if (!r.ok) throw Error(r.message || '提交失败，请重试');
        message.textContent = r.message;
        var host = f.closest('#ppsHost');
        if (host) host.dispatchEvent(new CustomEvent('payroll-request-submitted', {detail: {month:f.dataset.month,message:r.message}}));
        else { alert(r.message); location.reload(); }
      })
      .catch(function (e) { message.textContent = e.message || '网络异常，请重试'; button.disabled = false; });
  });
})();
