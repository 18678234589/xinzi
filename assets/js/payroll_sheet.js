/* 首页项目报酬表：异步加载片段、切换月份、按部门筛选并重算合计 */
(function () {
  var host = document.getElementById('ppsHost'); if (!host) return;
  var cols = ['base', 'share', 'bonus', 'mgr', 'lead', 'ins_sub', 'other', 'payable', 'deduct', 'parking', 'reserve', 'unit_ins', 'personal_ins', 'net'];
  var money = function (v) { return Math.abs(v) < 0.005 ? '—' : v.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  function load(month, message) {
    host.setAttribute('aria-busy', 'true'); host.style.opacity = '.55';
    fetch(host.getAttribute('data-src') + '?pm=' + encodeURIComponent(month), { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.text(); })
      .then(function (html) { host.innerHTML = html; if (message) { var status = document.createElement('p'); status.className = 'pps-save-status'; status.setAttribute('role', 'status'); status.textContent = message; host.prepend(status); } })
      .catch(function () { host.innerHTML = '<section class="pps-card"><div class="pps-empty">项目报酬表加载失败，请刷新页面重试。</div></section>'; })
      .then(function () { host.style.opacity = ''; host.removeAttribute('aria-busy'); });
  }
  host.addEventListener('change', function (ev) { if (ev.target.classList.contains('pps-month')) load(ev.target.value); });
  host.addEventListener('payroll-request-submitted', function (ev) { load(ev.detail.month, ev.detail.message); });
  host.addEventListener('click', function (ev) {
    var b = ev.target.closest('.pps-chips button'); if (!b) return;
    var card = host.querySelector('#ppsCard'), chips = b.parentNode;
    [].forEach.call(chips.children, function (x) { x.classList.toggle('is-on', x === b); });
    var dept = b.getAttribute('data-dept'), sum = {}, n = 0;
    cols.forEach(function (c) { sum[c] = 0; });
    [].forEach.call(card.querySelectorAll('tbody tr'), function (tr) {
      var show = dept === '' || tr.getAttribute('data-dept') === dept; tr.hidden = !show; if (!show) return;
      n++; var tds = tr.querySelectorAll('td.pps-n[data-v]');
      cols.forEach(function (c, i) { sum[c] += parseFloat(tds[i].getAttribute('data-v')) || 0; });
    });
    cols.forEach(function (c) { var th = card.querySelector('th[data-c="' + c + '"]'); if (th) th.textContent = money(sum[c]); });
    card.querySelector('[data-t="cnt"]').textContent = n + ' 人';
    card.querySelector('[data-t="count"]').textContent = n;
    card.querySelector('[data-t="payable"]').textContent = '¥' + money(sum.payable);
    card.querySelector('[data-t="cut"]').textContent = '¥' + money(sum.payable - sum.net);
    card.querySelector('[data-t="net"]').textContent = '¥' + money(sum.net);
  });
  var FLAG_TOL = 0.05;
  function flagDiff(dlg) {
    var net = parseFloat(dlg.getAttribute('data-net') || 0), ref = parseFloat(dlg.querySelector('[name="ref"]').value), box = dlg.querySelector('[data-f="diff"]'), hint = dlg.querySelector('[data-f="fhint"]');
    if (!ref) { box.textContent = '—'; box.className = ''; hint.textContent = '填入对照表金额后自动计算差距；差距在 5% 以内可标绿。'; return; }
    var d = (net - ref) / Math.abs(ref);
    box.textContent = (d >= 0 ? '+' : '') + (d * 100).toFixed(1) + '%'; box.className = Math.abs(d) > FLAG_TOL ? 'over' : 'ok';
    hint.textContent = Math.abs(d) > FLAG_TOL ? '差距超过 5%：建议先让对方补充 / 财务审核订单，再标绿。' : '差距在 5% 以内，可以标绿，对方即可申请结算。';
  }
  var yuan = function (v) { return '\u00a5' + Number(v).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  host.addEventListener('click', function (ev) {
    var sa = ev.target.closest('.pps-settle-all');
    if (sa) {
      if (!confirm('按首页实结算申请往月报酬（' + sa.getAttribute('data-n') + ' 个月，¥' + sa.getAttribute('data-amt') + '）？\n金额包含固定工资、全勤、提成和全部扣减，已申请或已结算的金额会自动扣除。')) return;
      var sfd = new FormData(); sfd.append('csrf', sa.getAttribute('data-token')); sfd.append('action', 'request_settle_all'); sa.disabled = true;
      fetch(host.getAttribute('data-src'), { method: 'POST', credentials: 'same-origin', body: sfd })
        .then(function (r) { return r.json().catch(function () { return { ok: false, message: '提交失败（' + r.status + '）' }; }); })
        .then(function (j) {
          if (!j.ok) { alert(j.message || '提交失败'); return; }
          var msg = (j.done || []).length ? '已提交：' + j.done.map(function (d) { return d[0] + ' ' + yuan(d[1]); }).join('、') + '。' : '没有提交任何月份。';
          if ((j.skipped || []).length) msg += '\n未提交：' + j.skipped.map(function (d) { return d[0] + '（' + d[1] + '）'; }).join('；');
          alert(msg); load(host.querySelector('.pps-month').value);
        })
        .catch(function () { alert('网络异常，请重试'); })
        .then(function () { sa.disabled = false; });
      return;
    }
    var bg = ev.target.closest('.pps-bulk-green, .pps-bulk:not(.pps-settle-all)');
    if (bg) {
      if (!confirm('把差距在 5% 以内的 ' + bg.getAttribute('data-n') + ' 人标为绿色？他们会收到站内信，并可以申请结算。')) return;
      var bd = new FormData(); bd.append('csrf', bg.getAttribute('data-token')); bd.append('action', 'bulk_green'); bd.append('month', bg.getAttribute('data-month')); bg.disabled = true;
      fetch(host.getAttribute('data-src'), { method: 'POST', credentials: 'same-origin', body: bd }).then(function (r) { return r.json(); })
        .then(function (j) { if (j.ok) load(host.querySelector('.pps-month').value); else { alert(j.message || '操作失败'); bg.disabled = false; } }).catch(function () { alert('网络异常，请重试'); bg.disabled = false; });
      return;
    }
    var fb = ev.target.closest('.pps-flag');
    if (fb) {
      var fd = host.querySelector('#ppsFlag'), ff = fd.querySelector('form'), month = fb.getAttribute('data-month'), id = fb.getAttribute('data-id');
      ff.employee_id.value = id; ff.month.value = month; ff.reason.value = ''; ff.ref.value = ''; [].forEach.call(ff.color, function (r) { r.checked = false; });
      fd.querySelector('[data-f="ftitle"]').textContent = fb.getAttribute('data-name') + ' · ' + month; fd.querySelector('[data-f="ferr"]').hidden = true; fd.setAttribute('data-net', '0');
      fd.querySelector('[data-f="net"]').textContent = '…'; fd.querySelector('[data-f="diff"]').textContent = '—';
      var rv = fd.querySelector('[data-f="review"]'); rv.hidden = false; rv.href = '/project/batch_review.php?month=' + encodeURIComponent(month) + '&emp=' + encodeURIComponent(id);
      var rl = fd.querySelector('[data-f="reasons"]'); rl.innerHTML = '<div class="pps-empty">正在分析差异原因…</div>';
      fd.showModal();
      fetch(host.getAttribute('data-src') + '?flag_info=' + encodeURIComponent(id) + '&pm=' + encodeURIComponent(month), { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          fd.setAttribute('data-net', j.net); fd.querySelector('[data-f="net"]').textContent = yuan(j.net);
          if (j.ref !== null) ff.ref.value = j.ref; if (j.color) { var rr = ff.querySelector('input[name="color"][value="' + j.color + '"]'); if (rr) rr.checked = true; } ff.reason.value = j.reason || '';
          rl.innerHTML = '';
          (j.reasons || []).forEach(function (it) {
            var d = document.createElement('div'); d.className = 'pps-why is-' + it.who;
            d.innerHTML = '<span class="who"></span><span class="tx"></span><button type="button" class="use" title="填入理由">填入理由</button>' + (it.link ? '<a target="_blank" href="' + it.link + '">去处理</a>' : '');
            d.querySelector('.who').textContent = it.who === 'finance' ? '财务可处理' : '需本人 / 技术补充'; d.querySelector('.tx').textContent = it.text; rl.appendChild(d);
          });
          flagDiff(fd);
        }).catch(function () { rl.innerHTML = '<div class="pps-empty">原因分析加载失败，可手动填写。</div>'; });
      return;
    }
    var use = ev.target.closest('.pps-why .use');
    if (use) { var ta = use.closest('dialog').querySelector('textarea[name="reason"]'), tx = use.parentNode.querySelector('.tx').textContent; ta.value = ta.value ? ta.value + '；' + tx : tx; ta.focus(); return; }
    if (ev.target.matches('[data-f="fcancel"]')) { ev.target.closest('dialog').close(); return; }
    var sb = ev.target.closest('.pps-settle');
    if (sb) {
      var sd = host.querySelector('#ppsSettle'), sf = sd.querySelector('form'), list = sd.querySelector('[data-f="list"]'), month = sb.getAttribute('data-month');
      list.innerHTML = '<div class="pps-empty">加载中…</div>'; sf.month.value = month; sd.querySelector('[data-f="stitle"]').textContent = month; sd.querySelector('[data-f="serr"]').hidden = true;
      sd.showModal();
      fetch(host.getAttribute('data-src') + '?settle_orders=1&pm=' + encodeURIComponent(month), { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          list.innerHTML = '';
          (j.orders || []).forEach(function (o) {
            var row = document.createElement('label'), can = o.state === 'available';
            row.className = 'pps-orow' + (can ? '' : ' is-off'); row.setAttribute('data-t', o.project_type || ''); row.setAttribute('data-s', (o.order_no + ' ' + (o.project_type || '') + ' ' + (o.customer || '')).toLowerCase());
            row.innerHTML = '<input type="checkbox" name="order_ids[]" value="' + o.order_id + '" data-amt="' + o.amount + '"' + (can ? '' : ' disabled') + '><span class="no"></span><span class="ty"></span><span class="dt"></span><b class="am">' + yuan(o.amount) + '</b><em></em>';
            row.querySelector('.no').textContent = o.order_no; row.querySelector('.ty').textContent = o.project_type; row.querySelector('.dt').textContent = o.order_date;
            row.querySelector('em').textContent = o.state === 'pending' ? '已申请' : (o.state === 'settled' ? '已结算' : (o.state === 'correcting' ? '更正中' : ''));
            list.appendChild(row);
          });
          if (!(j.orders || []).length) list.innerHTML = '<div class="pps-empty">该月份没有已审核的订单</div>';
          var types = {}; (j.orders || []).forEach(function (o) { var t = o.project_type || '其他'; types[t] = (types[t] || 0) + 1; });
          var chips = sd.querySelector('[data-f="chips"]'); chips.innerHTML = '<button type="button" class="is-on" data-type="">全部 <b>' + (j.orders || []).length + '</b></button>';
          Object.keys(types).forEach(function (t) { var b = document.createElement('button'); b.type = 'button'; b.setAttribute('data-type', t); b.innerHTML = '<span></span> <b>' + types[t] + '</b>'; b.firstChild.textContent = t; chips.appendChild(b); });
          sd.querySelector('[data-f="q"]').value = ''; filterRows(sd);
          sumUp(sd);
        }).catch(function () { list.innerHTML = '<div class="pps-empty">加载失败，请重试</div>'; });
      return;
    }
    if (ev.target.matches('[data-f="scancel"]')) { ev.target.closest('dialog').close(); return; }
    if (ev.target.matches('[data-f="corrtoggle"], [data-f="corrcancel"]')) {
      var cd = ev.target.closest('dialog'), panel = cd.querySelector('[data-f="corr"]'), open = ev.target.matches('[data-f="corrtoggle"]');
      panel.hidden = !open; if (open) panel.querySelector('textarea').focus(); return;
    }
    if (ev.target.matches('[data-f="corrgo"]')) {
      var cdlg = ev.target.closest('dialog'), cform = cdlg.querySelector('form'), cerr = cdlg.querySelector('[data-f="serr"]');
      if (!cform.querySelector('input[name="order_ids[]"]:checked')) { cerr.textContent = '请先勾选算得不对的订单'; cerr.hidden = false; return; }
      var cfd = new FormData(cform); cfd.set('action', 'request_correction'); ev.target.disabled = true;
      fetch(host.getAttribute('data-src'), { method: 'POST', credentials: 'same-origin', body: cfd })
        .then(function (r) { return r.json().catch(function () { return { ok: false, message: '提交失败（' + r.status + '）' }; }); })
        .then(function (j) { if (j.ok) { cdlg.close(); load(host.querySelector('.pps-month').value); } else { cerr.textContent = j.message || '提交失败'; cerr.hidden = false; } })
        .catch(function () { cerr.textContent = '网络异常，请重试'; cerr.hidden = false; })
        .then(function () { ev.target.disabled = false; });
      return;
    }
    var addc = ev.target.closest('.pps-addcom'), btn = addc ? addc.parentNode.querySelector('.pps-edit') : ev.target.closest('.pps-edit');
    if (btn) {
      var dlg = host.querySelector('#ppsEdit'), f = dlg.querySelector('form'), pf = JSON.parse(btn.getAttribute('data-profile'));
      f.employee_id.value = btn.getAttribute('data-id'); f.supervisor.value = pf.supervisor || ''; f.bank_card.value = pf.bank_card || '';
      f.insurance_company.value = pf.insurance_company || ''; f.reserve_deducted.value = pf.reserve_deducted === null ? '' : pf.reserve_deducted;
      f.signed.checked = String(pf.signed) === '1'; f.no_insurance.checked = String(pf.no_insurance) === '1';
      dlg.querySelector('[data-f="title"]').textContent = btn.getAttribute('data-name');
      dlg.querySelector('[data-f="err"]').hidden = true;
      var ul = dlg.querySelector('[data-f="items"]'), fi = dlg.querySelector('.pps-itemform'); ul.innerHTML = '';
      dlg.querySelector('[data-f="month"]').textContent = pf.month || ''; fi.employee_id.value = btn.getAttribute('data-id'); fi.month.value = pf.month || ''; fi.label.value = ''; fi.amount.value = ''; fi.note.value = '';
      dlg.querySelector('[data-f="itemerr"]').hidden = true;
      (pf.items || []).forEach(function (it) {
        var li = document.createElement('li'), a = Number(it.amount);
        li.innerHTML = '<span></span><b class="' + (a < 0 ? 'neg' : '') + '">' + (a < 0 ? '-' : '+') + Math.abs(a).toFixed(2) + '</b>' + (it.automatic ? '<small>每月自动</small>' : '<button type="button" data-del="' + it.id + '" title="删除">&times;</button>');
        li.firstChild.textContent = it.label + (it.note ? '（' + it.note + '）' : ''); ul.appendChild(li);
      });
      dlg.showModal();
      if (addc) { dlg.querySelector('.pps-items').scrollIntoView({ block: 'center' }); fi.label.focus(); }
      return;
    }
    if (ev.target.matches('[data-f="cancel"]')) ev.target.closest('dialog').close();
    var del = ev.target.closest('[data-del]');
    if (del) {
      var dlg2 = host.querySelector('#ppsEdit'), fi2 = dlg2.querySelector('.pps-itemform'), fd = new FormData();
      fd.append('csrf', fi2.csrf.value); fd.append('action', 'delete_item'); fd.append('employee_id', fi2.employee_id.value); fd.append('month', fi2.month.value); fd.append('item_id', del.getAttribute('data-del'));
      post(fd, dlg2.querySelector('[data-f="itemerr"]'));
    }
  });
  function filterRows(sd) {
    var q = sd.querySelector('[data-f="q"]').value.trim().toLowerCase(), on = sd.querySelector('[data-f="chips"] .is-on'), type = on ? on.getAttribute('data-type') : '';
    [].forEach.call(sd.querySelectorAll('.pps-orow'), function (r) {
      var show = (!type || r.getAttribute('data-t') === type) && (!q || r.getAttribute('data-s').indexOf(q) !== -1);
      r.hidden = !show; if (!show) { var b = r.querySelector('input'); if (b && !b.disabled) b.dataset.hid = '1'; } else { var b2 = r.querySelector('input'); if (b2) delete b2.dataset.hid; }
    });
  }
  function sumUp(sd) {
    var all = [].slice.call(sd.querySelectorAll('input[name="order_ids[]"]:not(:disabled)')), boxes = all.filter(function (b) { return !b.dataset.hid; }), picked = all.filter(function (b) { return b.checked; });
    var total = picked.reduce(function (s, b) { return s + parseFloat(b.getAttribute('data-amt') || 0); }, 0);
    sd.querySelector('[data-f="sum"]').textContent = yuan(total) + '（' + picked.length + ' 单）';
    sd.querySelector('[data-f="count"]').textContent = '（' + boxes.length + ' 单）';
    sd.querySelector('[data-f="all"]').checked = boxes.length > 0 && boxes.every(function (b) { return b.checked; });
  }
  host.addEventListener('change', function (ev) {
    var sd = host.querySelector('#ppsSettle'); if (!sd || !sd.contains(ev.target)) return;
    if (ev.target.matches('[data-f="all"]')) [].forEach.call(sd.querySelectorAll('input[name="order_ids[]"]:not(:disabled)'), function (b) { if (!b.dataset.hid) b.checked = ev.target.checked; });
    sumUp(sd);
  });
  host.addEventListener('input', function (ev) { var fd = host.querySelector('#ppsFlag'); if (fd && ev.target.matches('#ppsFlag [name="ref"]')) flagDiff(fd); });
  host.addEventListener('input', function (ev) { var sd = host.querySelector('#ppsSettle'); if (sd && ev.target.matches('[data-f="q"]')) { filterRows(sd); sumUp(sd); } });
  host.addEventListener('click', function (ev) {
    var chip = ev.target.closest('#ppsSettle [data-type]'); if (!chip) return;
    var sd = host.querySelector('#ppsSettle');
    [].forEach.call(sd.querySelectorAll('[data-f="chips"] button'), function (b) { b.classList.toggle('is-on', b === chip); });
    filterRows(sd); sumUp(sd);
  });
  function post(fd, err) {
    return fetch(host.getAttribute('data-src'), { method: 'POST', credentials: 'same-origin', body: fd })
      .then(function (r) { return r.json().catch(function () { return { ok: false, message: '保存失败（' + r.status + '）' }; }); })
      .then(function (j) { if (j.ok) { host.querySelector('#ppsEdit').close(); load(host.querySelector('.pps-month').value); } else { err.textContent = j.message || '保存失败'; err.hidden = false; } })
      .catch(function () { err.textContent = '网络异常，请重试'; err.hidden = false; });
  }
  host.addEventListener('submit', function (ev) {
    if (ev.target.classList.contains('pps-fform')) {
      ev.preventDefault();
      var ffm = ev.target, fdlg = ffm.closest('dialog'), ferr = fdlg.querySelector('[data-f="ferr"]'), fbtn = ffm.querySelector('.is-primary'); fbtn.disabled = true;
      fetch(host.getAttribute('data-src'), { method: 'POST', credentials: 'same-origin', body: new FormData(ffm) })
        .then(function (r) { return r.json().catch(function () { return { ok: false, message: '保存失败（' + r.status + '）' }; }); })
        .then(function (j) { if (j.ok) { fdlg.close(); load(host.querySelector('.pps-month').value); } else { ferr.textContent = j.message || '保存失败'; ferr.hidden = false; } })
        .catch(function () { ferr.textContent = '网络异常，请重试'; ferr.hidden = false; })
        .then(function () { fbtn.disabled = false; });
      return;
    }
    if (ev.target.classList.contains('pps-sform')) {
      ev.preventDefault();
      var sdlg = ev.target.closest('dialog'), serr = sdlg.querySelector('[data-f="serr"]');
      if (!ev.target.querySelector('input[name="order_ids[]"]:checked')) { serr.textContent = '请先勾选要结算的订单（可点“全选”）'; serr.hidden = false; return; }
      var sbtn = ev.target.querySelector('.is-primary'); sbtn.disabled = true;
      fetch(host.getAttribute('data-src'), { method: 'POST', credentials: 'same-origin', body: new FormData(ev.target) })
        .then(function (r) { return r.json().catch(function () { return { ok: false, message: '提交失败（' + r.status + '）' }; }); })
        .then(function (j) { if (j.ok) { sdlg.close(); load(host.querySelector('.pps-month').value); } else { serr.textContent = j.message || '提交失败'; serr.hidden = false; } })
        .catch(function () { serr.textContent = '网络异常，请重试'; serr.hidden = false; })
        .then(function () { sbtn.disabled = false; });
      return;
    }
    if (ev.target.classList.contains('pps-itemform')) { ev.preventDefault(); post(new FormData(ev.target), ev.target.parentNode.querySelector('[data-f="itemerr"]')); return; }
    var f = ev.target; if (!f.classList.contains('pps-form') || f.classList.contains('pps-parkingform')) return;
    ev.preventDefault();
    var err = f.querySelector('[data-f="err"]'), btn = f.querySelector('.is-primary'); btn.disabled = true;
    fetch(host.getAttribute('data-src'), { method: 'POST', credentials: 'same-origin', body: new FormData(f) })
      .then(function (r) { return r.json().catch(function () { return { ok: false, message: '保存失败（' + r.status + '）' }; }); })
      .then(function (j) {
        if (j.ok) { f.closest('dialog').close(); load(host.querySelector('.pps-month').value); }
        else { err.textContent = j.message || '保存失败'; err.hidden = false; }
      })
      .catch(function () { err.textContent = '网络异常，请重试'; err.hidden = false; })
      .then(function () { btn.disabled = false; });
  });
  host.addEventListener('click', function (ev) {
    var b = ev.target.closest('.pps-parking'); if (!b) return;
    var dlg = host.querySelector('#ppsParking'), f = dlg.querySelector('form'), month = host.querySelector('.pps-month').value;
    f.employee_id.value = b.getAttribute('data-id'); f.month.value = month;
    f.amount.value = b.getAttribute('data-amount');
    dlg.querySelector('[data-f="title"]').textContent = b.getAttribute('data-name');
    dlg.querySelector('[data-f="month"]').textContent = month;
    dlg.querySelector('[data-f="err"]').hidden = true;
    dlg.showModal();
  });
  host.addEventListener('submit', function (ev) {
    if (!ev.target.classList.contains('pps-parkingform')) return;
    ev.preventDefault();
    var f = ev.target, dlg = f.closest('dialog'), err = dlg.querySelector('[data-f="err"]'), fd = new FormData(f), buttons = f.querySelectorAll('button');
    if (ev.submitter && ev.submitter.hasAttribute('data-parking-stop')) fd.set('amount', '0');
    err.hidden = true; buttons.forEach(function (b) { b.disabled = true; });
    fetch(host.getAttribute('data-src'), { method: 'POST', credentials: 'same-origin', body: fd })
      .then(function (r) { return r.json().catch(function () { return { ok: false, message: '保存失败（' + r.status + '）' }; }); })
      .then(function (j) {
        if (!j.ok) { err.textContent = j.message || '保存失败'; err.hidden = false; return; }
        dlg.close(); load(f.month.value, Number(fd.get('amount')) === 0 ? '车位费已取消，自' + f.month.value + '起不再自动扣减。' : '已保存：自' + f.month.value + '起每月自动扣车位费' + Number(fd.get('amount')).toFixed(2) + '元。');
      })
      .catch(function () { err.textContent = '网络异常，请重试'; err.hidden = false; })
      .then(function () { buttons.forEach(function (b) { b.disabled = false; }); });
  });
  host.addEventListener('click', function (ev) {
    if (ev.target.closest('[data-director-cancel]')) { ev.target.closest('dialog').close(); return; }
    var b = ev.target.closest('.pps-director'); if (!b) return;
    var dlg = host.querySelector('#ppsDirector'), f = dlg.querySelector('form'), month = host.querySelector('.pps-month').value;
    f.employee_id.value = b.getAttribute('data-id'); f.month.value = month;
    f.amount.value = b.getAttribute('data-amount'); f.note.value = '';
    dlg.querySelector('[data-f="title"]').textContent = b.getAttribute('data-name');
    dlg.querySelector('[data-f="month"]').textContent = month;
    dlg.querySelector('[data-f="err"]').hidden = true;
    dlg.showModal(); f.amount.focus();
  });
  host.addEventListener('submit', function (ev) {
    if (!ev.target.classList.contains('pps-directorform')) return;
    ev.preventDefault();
    var f = ev.target, dlg = f.closest('dialog'), err = dlg.querySelector('[data-f="err"]'), buttons = f.querySelectorAll('button');
    err.hidden = true; buttons.forEach(function (b) { b.disabled = true; });
    fetch(host.getAttribute('data-src'), { method: 'POST', credentials: 'same-origin', body: new FormData(f) })
      .then(function (r) { return r.json().catch(function () { return { ok: false, message: '保存失败（' + r.status + '）' }; }); })
      .then(function (j) {
        if (!j.ok) { err.textContent = j.message || '保存失败'; err.hidden = false; return; }
        dlg.close(); load(f.month.value, '已保存' + f.month.value + '董事长/董事会补助核定金额 ' + yuan(f.amount.value) + '，仅本月生效。');
      })
      .catch(function () { err.textContent = '网络异常，请重试'; err.hidden = false; })
      .then(function () { buttons.forEach(function (b) { b.disabled = false; }); });
  });
  load(host.getAttribute('data-month'));
})();
