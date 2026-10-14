(function () {
  'use strict';
  var root = document.getElementById('domainConsole'), box = document.getElementById('templateCreator');
  if (!root || !box) return;
  var regions = JSON.parse(box.dataset.regions || '{}'), certTypes = JSON.parse(box.dataset.certTypes || '{}');
  var el = function (id) { return document.getElementById(id); };
  var esc = function (v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var files = [], submitting = false;
  // 表单字段 → 输入框；后端返回的字段名与此一致。
  var map = { template_name: 'tcTemplateName', owner_name: 'tcOwnerName', cert_no: 'tcCertNo', surname: 'tcSurname', given_name: 'tcGivenName', address: 'tcAddress', postcode: 'tcPostcode', phone: 'tcPhone', email: 'tcEmail', org_e: 'tcOrgE', surname_e: 'tcSurnameE', given_name_e: 'tcGivenNameE', province_e: 'tcProvinceE', city_e: 'tcCityE', address_e: 'tcAddressE' };
  var warnMap = { cert_no: 'tcCertNo', region: 'tcProvince', owner_name: 'tcOwnerName', surname: 'tcSurname', given_name: 'tcGivenName', address: 'tcAddress', postcode: 'tcPostcode', org_e: 'tcOrgE', surname_e: 'tcSurnameE', given_name_e: 'tcGivenNameE', province_e: 'tcProvinceE', city_e: 'tcCityE', address_e: 'tcAddressE', contact_default: 'tcPhone' };
  function note(id, text, error) { var n = el(id); n.textContent = text; n.hidden = !text; n.classList.toggle('error', !!error); }
  async function post(action, body, timeout) {
    body.set('action', 'registration_' + action); body.set('csrf', root.dataset.csrf);
    var ctl = new AbortController(), timer = setTimeout(function () { ctl.abort(); }, timeout || 120000);
    try {
      var r = await fetch('domains.php', { method: 'POST', credentials: 'same-origin', body: body, signal: ctl.signal });
      if (r.redirected) throw new Error('登录已过期，请刷新后重新登录');
      var d; try { d = await r.json(); } catch (_) { throw new Error('未收到有效结果，请稍后重试'); }
      if (!r.ok || !d.ok) throw new Error(d.error || '操作未完成'); return d.data;
    } catch (e) { if (e.name === 'AbortError') throw new Error('响应较慢。提交实名前请先到商务中国后台确认是否已生成模板，不要重复提交。'); throw e; }
    finally { clearTimeout(timer); }
  }
  // 手机拍的照片常有 5MB 以上：先在浏览器里缩到最长边 2000，同时按 EXIF 方向摆正。
  async function shrink(file) {
    try {
      var bmp = await createImageBitmap(file), scale = Math.min(1, 2000 / Math.max(bmp.width, bmp.height));
      var c = document.createElement('canvas'); c.width = Math.round(bmp.width * scale); c.height = Math.round(bmp.height * scale);
      var g = c.getContext('2d'); g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height); g.drawImage(bmp, 0, 0, c.width, c.height);
      var blob = await new Promise(function (ok) { c.toBlob(ok, 'image/jpeg', 0.9); });
      return blob ? new File([blob], 'cert.jpg', { type: 'image/jpeg' }) : file;
    } catch (_) { return file; }
  }
  function fillCerts(kind, keep) {
    var list = certTypes[kind] || {}; el('tcCertType').innerHTML = Object.keys(list).map(function (k) { return '<option value="' + esc(k) + '">' + esc(list[k] + '（' + k + '）') + '</option>'; }).join('');
    if (keep && list[keep]) el('tcCertType').value = keep;
    el('tcOwnerHint').textContent = kind === 'person' ? '个人姓名' : '单位全称';
  }
  function fillProvinces(province, city) {
    el('tcProvince').innerHTML = '<option value="">请选择省份</option>' + Object.keys(regions).map(function (p) { return '<option value="' + esc(p) + '">' + esc(p) + '</option>'; }).join('');
    el('tcProvince').value = province || ''; fillCities(city);
  }
  function fillCities(city) {
    var list = regions[el('tcProvince').value] || [];
    el('tcCity').innerHTML = '<option value="">请选择城市</option>' + list.map(function (c) { return '<option value="' + esc(c) + '">' + esc(c) + '</option>'; }).join('');
    el('tcCity').value = city || '';
  }
  function apply(fields, warnings) {
    el('tcKind').value = fields.kind; fillCerts(fields.kind, fields.cert_type); fillProvinces(fields.province, fields.city);
    Object.keys(map).forEach(function (k) { el(map[k]).value = fields[k] || ''; });
    box.querySelectorAll('.tc-warn').forEach(function (n) { n.classList.remove('tc-warn'); });
    (warnings || []).forEach(function (w) { if (warnMap[w]) el(warnMap[w]).classList.add('tc-warn'); });
    el('tcForm').hidden = false; el('tcResult').hidden = true;
  }
  function collect() {
    var f = { kind: el('tcKind').value, cert_type: el('tcCertType').value, province: el('tcProvince').value, city: el('tcCity').value };
    Object.keys(map).forEach(function (k) { f[k] = el(map[k]).value.trim(); }); return f;
  }
  var warnText = { cert_no: '证件号码与文字或校验位不一致，请对照证件逐位核对', region: '省市没有识别完整，请手动选择', contact_default: '电话 / 邮箱使用了管理员设置的默认联系方式，请确认是否合适' };
  // 证件图片：可拖拽、点击选择、粘贴；多次添加会累加（不会覆盖已选的），每张可单独移除。
  var MAX_FILES = 4, previews = [];
  function renderFiles() {
    previews.forEach(function (u) { URL.revokeObjectURL(u); }); previews = [];
    el('tcThumbs').innerHTML = files.map(function (f, i) {
      var url = URL.createObjectURL(f); previews.push(url);
      return '<figure class="registration-thumb"><img src="' + url + '" alt="证件图片 ' + (i + 1) + '"><figcaption>第 ' + (i + 1) + ' 张</figcaption><button type="button" data-remove-file="' + i + '" aria-label="移除第 ' + (i + 1) + ' 张">×</button></figure>';
    }).join('');
    el('tcThumbs').querySelectorAll('[data-remove-file]').forEach(function (b) { b.onclick = function (e) { e.stopPropagation(); files.splice(Number(b.dataset.removeFile), 1); renderFiles(); }; });
    el('tcFileInfo').textContent = files.length ? '已添加 ' + files.length + ' / ' + MAX_FILES + ' 张' + (files.length > 1 ? '（身份证正反面等多张会上下拼接为一张提交）' : '') : '';
  }
  async function addFiles(list) {
    var picked = Array.prototype.slice.call(list || []).filter(function (f) { return /^image\//.test(f.type) || /\.(jpe?g|png)$/i.test(f.name); });
    if (!picked.length) { note('tcNotice', '请选择 JPG / PNG 图片', true); return; }
    var room = MAX_FILES - files.length;
    if (room <= 0) { note('tcNotice', '最多 ' + MAX_FILES + ' 张，请先移除不需要的图片', true); return; }
    if (picked.length > room) note('tcNotice', '最多 ' + MAX_FILES + ' 张，已添加前 ' + room + ' 张', true); else note('tcNotice', '', false);
    picked = picked.slice(0, room);
    for (var i = 0; i < picked.length; i++) files.push(await shrink(picked[i]));
    renderFiles();
  }
  function clearFiles() { files = []; el('tcFiles').value = ''; renderFiles(); }
  var drop = el('tcDrop');
  drop.onclick = function () { el('tcFiles').click(); };
  drop.onkeydown = function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); el('tcFiles').click(); } };
  el('tcFiles').onclick = function (e) { e.stopPropagation(); };
  el('tcFiles').onchange = async function () { var chosen = this.files; await addFiles(chosen); this.value = ''; };
  ['dragenter', 'dragover'].forEach(function (name) { drop.addEventListener(name, function (e) { e.preventDefault(); drop.classList.add('over'); }); });
  ['dragleave', 'dragend'].forEach(function (name) { drop.addEventListener(name, function () { drop.classList.remove('over'); }); });
  drop.addEventListener('drop', function (e) { e.preventDefault(); drop.classList.remove('over'); addFiles(e.dataTransfer && e.dataTransfer.files); });
  // 在识别区任意位置粘贴截图 / 复制的图片。
  box.addEventListener('paste', function (e) {
    var items = (e.clipboardData && e.clipboardData.files) || [];
    if (items.length) { e.preventDefault(); addFiles(items); }
  });
  // 拖到识别区之外时浏览器会直接打开图片；在识别区内放开则交给上面的投放区。
  box.addEventListener('dragover', function (e) { if (e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') >= 0) e.preventDefault(); });
  box.addEventListener('drop', function (e) { if (e.target !== drop && !drop.contains(e.target) && e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) { e.preventDefault(); addFiles(e.dataTransfer.files); } });
  el('tcKind').onchange = function () { fillCerts(this.value); };
  el('tcProvince').onchange = function () { fillCities(''); };
  var money = function (cents) { return '¥' + (cents / 100).toFixed(2); };
  function card(kind, title, body) { return '<article class="registration-check ' + kind + '"><strong>' + title + '</strong><div>' + body + '</div></article>'; }
  // 预检：域名可注册性 + 该公司是否已有实名模板（只读，不扣费、不创建）。
  async function precheck(domain, owner) {
    var body = new FormData(); body.set('domain', domain); body.set('owner', owner);
    var d = await post('precheck', body, 90000), html = '', dom = d.domain, usable = null, pending = null;
    if (!domain) html += card('warn', '域名', '没有识别到域名，请在上方填写要注册的域名后点「重新预检」。');
    else if (dom.state === 'available') html += card(dom.premium_blocked ? 'warn' : 'ok', '域名 ' + esc(dom.name) + ' 可以注册', '一年注册价 <b>' + money(dom.price_cents) + '</b>' + (dom.premium ? '（高价域名' + (dom.premium_blocked ? '，需由管理员注册' : '') + '）' : '') + '。');
    else html += card('bad', '域名 ' + esc(dom.name) + (dom.state === 'owned' ? ' 已在本账户中' : ' 暂不能注册'), esc(dom.message || '请更换域名'));
    (d.templates || []).forEach(function (t) { if (t.status === 'pass' && !usable) usable = t; if (t.status === 'cnnicauditing' && !pending) pending = t; });
    if (!owner) html += card('warn', '实名主体', '没有识别到公司名 / 姓名，请在下方表单填写。');
    else if (usable) html += card('ok', '实名主体已就绪', esc(usable.owner || usable.label) + ' 已有审核通过的实名模板 <code>' + esc(usable.id) + '</code>，无需再提交实名。');
    else if (pending) html += card('warn', '实名审核中', esc(pending.owner || pending.label) + ' 的实名模板 <code>' + esc(pending.id) + '</code> 正在审核。可以<b>先注册域名、实名随后审核</b>（与商务中国后台「注册 + 实名一起提交」一致）；是否接受以商务中国返回为准，若失败请先到「注册申请记录」核对。 <button type="button" class="domain-btn" id="tcStatusBtn" data-id="' + esc(pending.id) + '">查询审核进度</button>');
    else html += card('warn', '还没有实名模板', esc(owner) + ' 在商务中国还没有实名模板，需要先提交实名（下方表单已填好，核对后点「核对无误，提交实名审核」）。');
    var actions = '';
    var chosen = usable || pending;
    if (chosen && dom && dom.state === 'available' && !dom.premium_blocked) actions += '<button type="button" class="domain-btn" id="tcUse">' + (usable ? '使用该主体注册 ' : '实名审核中也先注册 ') + esc(dom.name) + '（查询报价）</button>';
    var holder = el('tcChecks'); holder.innerHTML = html + (actions ? '<div class="registration-check-actions">' + actions + '</div>' : '');
    var st = el('tcStatusBtn'); if (st) st.onclick = checkStatus;
    var use = el('tcUse');
    if (use) use.onclick = function () {
      el('registrationDomain').value = dom.name;
      var sel = el('registrationTemplate'); sel.innerHTML = '<option value="' + esc(chosen.id) + '" selected>' + esc((chosen.owner || chosen.label) + ' · ' + chosen.id) + '</option>'; sel.value = chosen.id;
      el('registrationSearch').scrollIntoView({ behavior: 'smooth', block: 'center' }); el('registrationCheck').click();
    };
    el('tcPrecheck').hidden = false; el('tcDomain').value = domain;
    return { usable: usable, pending: pending, domain: dom };
  }
  el('tcRecheck').onclick = async function () {
    var b = this; if (b.disabled) return; b.disabled = true;
    try { note('tcNotice', '正在查询域名与实名主体…', false); await precheck(el('tcDomain').value.trim(), el('tcOwnerName').value.trim()); note('tcNotice', '预检完成，结果见下方。', false); }
    catch (err) { note('tcNotice', err.message, true); } finally { b.disabled = false; }
  };
  el('tcAiForm').onsubmit = async function (e) {
    e.preventDefault(); var b = el('tcRecognize'); if (b.disabled) return;
    var text = el('tcText').value.trim(); if (!text && !files.length) { note('tcNotice', '请粘贴客户信息（域名、公司名、电话、邮箱、地址），或上传营业执照 / 身份证图片', true); return; }
    b.disabled = true; var old = b.textContent; b.textContent = '识别中，请稍候…'; note('tcNotice', '正在识别客户信息…', false);
    try {
      var body = new FormData(); body.set('text', text); files.forEach(function (f) { body.append('images[]', f, f.name); });
      var d = await post('template_ai', body);
      apply(d.fields, d.warnings);
      var msgs = (d.warnings || []).map(function (w) { return warnText[w]; }).filter(Boolean);
      var done = (d.note ? d.note + ' ' : '识别完成。') + msgs.join('；');
      note('tcNotice', done + ' 正在查询域名与实名主体…', !!d.note);
      b.textContent = '预检中…';
      try { await precheck(d.domain || '', d.fields.owner_name || ''); note('tcNotice', done + ' 预检结果见下方；表单已填好，请逐项核对（标橙色的需要重点确认）。', !!d.note); }
      catch (err) { note('tcNotice', done + ' 但预检没有成功：' + err.message + '。可点「重新预检」。', true); el('tcPrecheck').hidden = false; el('tcDomain').value = d.domain || ''; }
      el('tcNotice').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    } catch (err) { note('tcNotice', err.message, true); }
    finally { b.disabled = false; b.textContent = old; }
  };
  el('tcForm').onsubmit = async function (e) {
    e.preventDefault(); if (submitting) return;
    if (!files.length) { note('tcResult', '提交实名需要证件图片，请在上方重新选择营业执照 / 身份证图片（出于隐私，页面刷新后不保留图片）。', true); return; }
    var f = collect(); if (!window.confirm('确认向商务中国提交实名审核？\n主体：' + f.owner_name + '\n证件号：' + f.cert_no + '\n省市：' + f.province + ' ' + f.city + '\n电话：' + f.phone + '　邮箱：' + f.email + '\n提交后不能修改，信息与证件不一致会审核失败。')) return;
    submitting = true; var b = el('tcSubmit'); b.disabled = true; var old = b.textContent; b.textContent = '提交中…'; note('tcResult', '', false);
    try {
      var body = new FormData(); body.set('fields', JSON.stringify(f)); body.set('ack', '1'); files.forEach(function (x) { body.append('images[]', x, x.name); });
      var d = await post('template_create', body, 150000);
      var r = el('tcResult'); r.classList.remove('error'); r.hidden = false;
      r.innerHTML = '<strong>' + esc(d.template_name) + '</strong> 已提交，模板编号 <code>' + esc(d.contid) + '</code>。' + esc(d.message) + ' <button type="button" class="domain-btn" id="tcStatusBtn2" data-id="' + esc(d.contid) + '">查询审核进度</button>';
      el('tcStatusBtn2').onclick = checkStatus; el('tcForm').hidden = true; clearFiles();
      el('registrationTemplateQuery').value = d.contid;
      // 紧接着预检：域名可注册时直接出现「实名审核中也先注册」按钮，实名与注册一起走。
      try { note('tcNotice', '实名已提交，正在查询域名…', false); await precheck(el('tcDomain').value.trim(), f.owner_name); note('tcNotice', '实名已提交。域名预检结果见下方，可直接继续注册。', false); } catch (e2) { note('tcNotice', '实名已提交，但域名预检没有成功：' + e2.message, true); }
    } catch (err) { note('tcResult', err.message, true); }
    finally { submitting = false; b.disabled = false; b.textContent = old; }
  };
  async function checkStatus() {
    var b = this; b.disabled = true;
    try { var body = new FormData(); body.set('contid', b.dataset.id); var d = await post('template_status', body, 30000); note('tcNotice', '实名模板 ' + d.contid + '：' + d.label + (d.failed_reason ? '（' + d.failed_reason + '）' : ''), d.status === 'unpass'); el('tcNotice').scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
    catch (err) { note('tcNotice', err.message, true); } finally { b.disabled = false; }
  }
  // 管理员：默认已验证联系方式（客户没提供手机 / 邮箱时自动填入）。
  var save = el('registrationSaveContact');
  if (save) {
    save.onclick = async function () {
      save.disabled = true;
      try { var body = new FormData(); body.set('phone', el('registrationContactPhone').value.trim()); body.set('email', el('registrationContactEmail').value.trim()); var d = await post('template_contact', body, 30000); el('registrationNotice').textContent = d.message; el('registrationNotice').hidden = false; el('registrationNotice').classList.remove('error'); }
      catch (err) { el('registrationNotice').textContent = err.message; el('registrationNotice').hidden = false; el('registrationNotice').classList.add('error'); }
      finally { save.disabled = false; }
    };
    var cfg = new FormData(); post('config', cfg, 30000).then(function (c) { if (c.template_contact) { el('registrationContactPhone').value = c.template_contact.phone.replace('+86.', ''); el('registrationContactEmail').value = c.template_contact.email; } }).catch(function () {});
  }
  fillCerts('company'); fillProvinces('', '');
  // 入口：页头按钮 / 导航“注册域名”（#register）直接展开注册区并打开智能识别。
  function openCreator() {
    if (el('registrationWorkspace').hidden) el('registrationToggle').click();
    box.scrollIntoView({ behavior: 'smooth', block: 'start' }); el('tcText').focus({ preventScroll: true });
  }
  el('registrationOpenCreator').onclick = openCreator;
  if (window.location.hash === '#register') setTimeout(openCreator, 0);
})();
