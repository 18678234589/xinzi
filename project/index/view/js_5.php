
(function () {
  var btn = document.getElementById('aiParseBtn');
  if (!btn) return;
  var status = document.getElementById('aiStatus');
  function fill(el, value) { if (el && value && !el.disabled && (el.value === '' || el.value === '0')) { el.value = value; el.classList.add('project-autofilled'); el.dispatchEvent(new Event('change')); return 1; } return 0; }
  function field(name) { return document.querySelector('#projectManualForm [name="' + name + '"]'); }
  btn.addEventListener('click', function () {
    var text = document.getElementById('aiPaste').value.trim();
    if (!text) { status.textContent = '请先粘贴内容'; return; }
    btn.disabled = true; status.textContent = 'AI 正在识别，大约需要几秒…';
    var body = new URLSearchParams({csrf: field('csrf').value, text: text});
    fetch('<?php echo BASE_URL; ?>/project/ai.php', {method: 'POST', credentials: 'same-origin', body: body}).then(function (r) { return r.json(); }).then(function (data) {
      btn.disabled = false;
      if (!data.ok) { status.textContent = data.error || '识别失败'; return; }
      var f = data.fields, n = 0;
      var business = document.getElementById('intakeBusiness');
      if (f.business && business.value !== f.business && Array.prototype.some.call(business.options, function (o) { return o.value === f.business; })) { business.value = f.business; business.dispatchEvent(new Event('change')); n++; }
      n += fill(document.getElementById('intakeOrderNo'), f.order_no);
      n += fill(document.getElementById('intakeKind'), f.order_kind);
      var date = document.getElementById('intakeDate');
      if (f.order_date && !date.value) { date.value = f.order_date; date.classList.add('project-autofilled'); n++; }
      n += fill(document.getElementById('intakeShop'), f.shop);
      n += fill(document.getElementById('intakeNickname'), f.payment_nickname);
      n += fill(document.getElementById('intakePrice'), f.contract_amount);
      n += fill(document.getElementById('intakeTrade'), f.trade_status);
      n += fill(field('customer_name'), f.customer_name);
      n += fill(field('contact_note'), f.contact_note);
      n += fill(field('details[make_requirement]'), f.requirement);
      var program = document.getElementById('intakeProgram');
      if (program && !program.disabled && f.program_name && program.value === '0') {
        var target = f.program_name.replace(/\s+/g, ''), years = (target.match(/^(\d+)年/) || [0, '1'])[1], name = target.replace(/^\d+年/, '');
        var match = Array.prototype.filter.call(program.options, function (o) { var t = o.textContent.replace(/\s+/g, ''); return t.indexOf(name + '·' + years + '年') === 0 && t.indexOf('空间+域名') !== -1; });
        if (match.length === 1) { program.value = match[0].value; program.classList.add('project-autofilled'); program.dispatchEvent(new Event('change')); n++; }
      }
      var orderNo = document.getElementById('intakeOrderNo');
      orderNo.dispatchEvent(new Event('blur'));
      status.textContent = n ? '已填写 ' + n + ' 项（绿色高亮），请核对后保存。' : '没有识别到可填写的新信息，已有内容未改动。';
    }).catch(function () { btn.disabled = false; status.textContent = 'AI 服务暂时连不上，请稍后再试'; });
  });
})();
