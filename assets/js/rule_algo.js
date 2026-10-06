/* 我的分成算法：弹窗导航、更正表单（JSON 提交 + 可选示例文件上传）。无依赖。 */
(function () {
  'use strict';
  var modal = document.getElementById('raModal'), mask = document.getElementById('raMask');
  if (!modal) return;
  var api = modal.getAttribute('data-api'), csrf = modal.getAttribute('data-csrf');
  var $ = function (id) { return document.getElementById(id); };
  var current = 'ra-pane-req', previous = null, target = null;

  function show(id) {
    var pane = $(id); if (!pane) return;
    if (id !== 'ra-pane-form') previous = id;
    modal.querySelectorAll('.ra-pane').forEach(function (p) { p.classList.toggle('is-on', p === pane); });
    modal.querySelectorAll('.ra-nav').forEach(function (n) { n.classList.toggle('is-on', n.getAttribute('data-ra-pane') === (id === 'ra-pane-form' ? previous : id)); });
    current = id; modal.querySelector('.ra-main').scrollTop = 0;
  }
  function open(id) { modal.classList.add('is-open'); mask.classList.add('is-open'); show(id || 'ra-pane-req'); document.body.style.overflow = 'hidden'; }
  function close() { modal.classList.remove('is-open'); mask.classList.remove('is-open'); document.body.style.overflow = ''; }

  document.querySelectorAll('[data-ra-open]').forEach(function (b) { b.addEventListener('click', function () { open(b.getAttribute('data-ra-open')); }); });
  modal.querySelectorAll('[data-ra-pane]').forEach(function (b) { b.addEventListener('click', function () { show(b.getAttribute('data-ra-pane')); }); });
  modal.querySelectorAll('[data-ra-close]').forEach(function (b) { b.addEventListener('click', close); });
  mask.addEventListener('click', close);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal.classList.contains('is-open')) close(); });

  // 提交成功后刷新并回到“我的更正申请”
  try { var flag = sessionStorage.getItem('raOpen'); if (flag) { sessionStorage.removeItem('raOpen'); open(flag); } } catch (e) {}

  // 打开更正表单
  function openForm(btn) {
    if (modal.getAttribute('data-preview')) return; // 管理员预览员工视角：只读，不开提交表单
    target = {kind: btn.getAttribute('data-kind') || 'other', id: btn.getAttribute('data-id') || '0', business: btn.getAttribute('data-business') || ''};
    $('raTargetTitle').textContent = btn.getAttribute('data-title') || '';
    var h = btn.getAttribute('data-headline') || '';
    $('raTargetFormula').textContent = h ? '当前算法：' + h : '当前没有对应的算法，请在下面写清楚应该怎么算';
    var order = target.kind === 'order';
    $('raNumsWrap').style.display = order ? '' : 'none';
    ['raRate', 'raFee', 'raSubsidy', 'raMin'].forEach(function (i) { $(i).value = ''; });
    var fee = btn.getAttribute('data-fee');
    $('raCurrent').textContent = '现行：比例 ' + (btn.getAttribute('data-rate') || '—') + '% · 服务费率 ' + (fee === null || fee === '' ? '业务默认' : fee + '%') + ' · 每单补助 ' + (btn.getAttribute('data-subsidy') || '0') + ' 元 · 起算售价 ' + (btn.getAttribute('data-min') || '无');
    $('raText').value = ''; $('raDetail').value = ''; $('raFile').value = ''; $('raDropText').textContent = '拖入文件，或点这里选择';
    $('raMsg').textContent = ''; $('raMsg').className = 'ra-msg'; $('raSubmit').disabled = false;
    if (!modal.classList.contains('is-open')) { modal.classList.add('is-open'); mask.classList.add('is-open'); document.body.style.overflow = 'hidden'; }
    show('ra-pane-form'); setTimeout(function () { $('raDetail').focus(); }, 320);
  }
  document.querySelectorAll('.js-wrong').forEach(function (b) { b.addEventListener('click', function () { openForm(b); }); });
  $('raBack').addEventListener('click', function () { show(previous || 'ra-pane-req'); });
  $('raCancel').addEventListener('click', function () { show(previous || 'ra-pane-req'); });

  // 示例文件：点击 / 拖拽
  var drop = $('raDrop'), file = $('raFile');
  function picked() { $('raDropText').innerHTML = ''; var s = document.createElement('span'); s.className = 'picked'; s.textContent = file.files[0] ? file.files[0].name : '拖入文件，或点这里选择'; $('raDropText').appendChild(s); }
  drop.addEventListener('click', function (e) { if (e.target !== file) file.click(); });
  drop.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); file.click(); } });
  file.addEventListener('change', picked);
  ['dragenter', 'dragover'].forEach(function (n) { drop.addEventListener(n, function (e) { e.preventDefault(); drop.classList.add('is-over'); }); });
  ['dragleave', 'drop'].forEach(function (n) { drop.addEventListener(n, function (e) { e.preventDefault(); drop.classList.remove('is-over'); }); });
  drop.addEventListener('drop', function (e) { if (e.dataTransfer.files[0]) { try { file.files = e.dataTransfer.files; } catch (x) {} picked(); } });

  function parse(r) {
    return r.text().then(function (t) {
      var d; try { d = JSON.parse(t); } catch (e) {
        if (r.status === 403 && t.indexOf('防火墙') !== -1) throw new Error('请求被网站防火墙拦截，请缩短说明或换一个文件再试');
        if (r.redirected || t.indexOf('login') !== -1) throw new Error('登录已过期，请刷新页面重新登录后再试');
        throw new Error('服务器返回了非预期内容（HTTP ' + r.status + '），请刷新页面后重试');
      }
      if (d.error) throw new Error(d.error); return d;
    });
  }
  function msg(text, err) { $('raMsg').textContent = text; $('raMsg').className = 'ra-msg' + (err ? ' err' : ''); }

  $('raSubmit').addEventListener('click', function () {
    var btn = this; if (!target) return;
    btn.disabled = true; msg('提交中…');
    var payload = {csrf: csrf, action: 'submit', rule_kind: target.kind, rule_id: target.id, business: target.business, proposed_rate: $('raRate').value, proposed_fee_rate: $('raFee').value, proposed_subsidy: $('raSubsidy').value, proposed_min: $('raMin').value, proposed_text: $('raText').value, detail: $('raDetail').value};
    fetch(api, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload)}).then(parse).then(function (d) {
      if (!file.files[0]) return d;
      msg('正在上传示例文件…');
      var fd = new FormData(); fd.append('csrf', csrf); fd.append('action', 'upload'); fd.append('id', d.id); fd.append('example', file.files[0]);
      return fetch(api, {method: 'POST', credentials: 'same-origin', body: fd}).then(parse).then(function () { return d; }, function (e) { throw new Error('申请已提交，但示例文件没有传上去：' + e.message + '（可联系财务补发）'); });
    }).then(function () {
      msg('已提交，管理员和财务会尽快审核');
      try { sessionStorage.setItem('raOpen', 'ra-pane-req'); } catch (e) {}
      setTimeout(function () { location.reload(); }, 700);
    }).catch(function (e) { msg(e.message, true); btn.disabled = false; });
  });
})();
