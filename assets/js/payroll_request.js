(function () {
  if (window.payrollRequestBound) return;
  window.payrollRequestBound = true;
  var money = function (v) { return Number(v).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  var dlg;
  // 确认弹窗：金额、对象、提示都在这里，表格里的按钮保持小巧。
  function confirmDialog(f, button, done) {
    var notes = [].map.call(f.querySelectorAll('.pr-detail small, .payroll-request-reason p'), function (n) { return n.textContent.trim(); }).filter(Boolean);
    if (!notes.some(function (t) { return t.indexOf('财务核对') >= 0; })) notes.push('提交后由财务核对，审核通过后方可打款。');
    notes.push('已申请和已打款的金额不会重复提交。');
    if (!window.HTMLDialogElement) { done(confirm((f.dataset.label || button.textContent.trim()) + '（' + f.dataset.month + '）？\n' + notes.join('\n'))); return; }
    if (!dlg) {
      dlg = document.createElement('dialog'); dlg.className = 'pr-dialog';
      dlg.innerHTML = '<form method="dialog"><div class="pr-d-head"><span class="pr-d-ico"><i class="fas fa-paper-plane"></i></span><div><h6></h6><small></small></div></div>' +
        '<div class="pr-d-amount"><small>本次申请金额</small><b></b></div><ul class="pr-d-notes"></ul><div class="pr-d-people" hidden></div>' +
        '<div class="pr-d-act"><button type="button" value="cancel" class="pr-d-cancel">取消</button><button type="submit" value="ok" class="pr-d-ok">确认提交</button></div></form>';
      document.body.appendChild(dlg);
    }
    dlg.querySelector('h6').textContent = f.dataset.label || button.textContent.trim();
    dlg.querySelector('.pr-d-head small').textContent = (f.dataset.name ? f.dataset.name + ' · ' : '') + f.dataset.month;
    dlg.querySelector('.pr-d-amount b').textContent = '¥' + money(f.dataset.amount || 0);
    var ul = dlg.querySelector('.pr-d-notes'); ul.innerHTML = '';
    notes.forEach(function (t) { var li = document.createElement('li'); li.textContent = t; ul.appendChild(li); });
    var people = dlg.querySelector('.pr-d-people'); people.hidden = !f.dataset.people; people.textContent = f.dataset.people || '';
    var ok = dlg.querySelector('.pr-d-ok'), cancel = dlg.querySelector('.pr-d-cancel');
    var finish = function (v) { ok.onclick = cancel.onclick = null; dlg.close(); done(v); };
    ok.onclick = function (e) { e.preventDefault(); finish(true); }; cancel.onclick = function (e) { e.preventDefault(); finish(false); };
    dlg.oncancel = function () { ok.onclick = cancel.onclick = null; };
    dlg.showModal(); ok.focus();
  }
  document.addEventListener('submit', function (ev) {
    var f = ev.target; if (!f.classList.contains('payroll-request-form')) return;
    ev.preventDefault();
    var button = f.querySelector('button'), message = f.querySelector('.payroll-request-message');
    if (!button || button.disabled) return;
    confirmDialog(f, button, function (yes) {
      if (!yes) return;
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
        .catch(function (e) { message.textContent = e.message || '网络异常，请重试'; message.hidden = false; button.disabled = false; if (f.classList.contains('is-compact')) alert(message.textContent); });
    });
  });
})();
