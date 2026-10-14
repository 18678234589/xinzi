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
    el('tcAck').checked = false; el('tcForm').hidden = false;
  }
  function collect() {
    var f = { kind: el('tcKind').value, cert_type: el('tcCertType').value, province: el('tcProvince').value, city: el('tcCity').value };
    Object.keys(map).forEach(function (k) { f[k] = el(map[k]).value.trim(); }); return f;
  }
  var warnText = { cert_no: '证件号码与文字或校验位不一致，请对照证件逐位核对', region: '省市没有识别完整，请手动选择', contact_default: '电话 / 邮箱使用了管理员设置的默认联系方式，请确认是否合适' };
  el('tcFiles').onchange = async function () {
    var picked = Array.prototype.slice.call(this.files || []);
    if (picked.length > 4) { note('tcNotice', '最多上传 4 张图片', true); this.value = ''; files = []; return; }
    files = []; for (var i = 0; i < picked.length; i++) files.push(await shrink(picked[i]));
    el('tcFileInfo').textContent = files.length ? '已选择 ' + files.length + ' 张图片' + (files.length > 1 ? '（将上下拼接为一张提交）' : '') : '';
  };
  el('tcKind').onchange = function () { fillCerts(this.value); };
  el('tcProvince').onchange = function () { fillCities(''); };
  el('tcAiForm').onsubmit = async function (e) {
    e.preventDefault(); var b = el('tcRecognize'); if (b.disabled) return;
    var text = el('tcText').value.trim(); if (!text && !files.length) { note('tcNotice', '请粘贴客户文字信息，或上传营业执照 / 身份证图片', true); return; }
    b.disabled = true; var old = b.textContent; b.textContent = '识别中，请稍候…'; note('tcNotice', '', false);
    try {
      var body = new FormData(); body.set('text', text); files.forEach(function (f) { body.append('images[]', f, f.name); });
      var d = await post('template_ai', body);
      apply(d.fields, d.warnings);
      var msgs = (d.warnings || []).map(function (w) { return warnText[w]; }).filter(Boolean);
      note('tcNotice', (d.note ? d.note + ' ' : '识别完成，请逐项核对下方信息（标红的需要重点确认）。') + msgs.join('；'), !!d.note);
      el('tcForm').scrollIntoView({ block: 'nearest' });
    } catch (err) { note('tcNotice', err.message, true); }
    finally { b.disabled = false; b.textContent = old; }
  };
  el('tcForm').onsubmit = async function (e) {
    e.preventDefault(); if (submitting) return;
    if (!files.length) { note('tcResult', '提交需要证件图片，请回到上方重新选择图片（出于隐私，页面刷新后不保留图片）。', true); return; }
    var f = collect(); if (!window.confirm('确认向商务中国提交实名审核？\n主体：' + f.owner_name + '\n证件号：' + f.cert_no + '\n提交后不能修改，信息不一致会审核失败。')) return;
    submitting = true; var b = el('tcSubmit'); b.disabled = true; var old = b.textContent; b.textContent = '提交中…'; note('tcResult', '', false);
    try {
      var body = new FormData(); body.set('fields', JSON.stringify(f)); body.set('ack', el('tcAck').checked ? '1' : '0'); files.forEach(function (x) { body.append('images[]', x, x.name); });
      var d = await post('template_create', body, 150000);
      var r = el('tcResult'); r.classList.remove('error'); r.hidden = false;
      r.innerHTML = '<strong>' + esc(d.template_name) + '</strong> 已提交，模板编号 <code>' + esc(d.contid) + '</code>。' + esc(d.message) + ' <button type="button" class="domain-btn ghost" id="tcCheck" data-id="' + esc(d.contid) + '">查询审核进度</button>';
      el('tcCheck').onclick = checkStatus; el('tcForm').hidden = true; el('tcText').value = ''; el('tcFiles').value = ''; files = []; el('tcFileInfo').textContent = '';
      el('registrationTemplateQuery').value = d.contid;
    } catch (err) { note('tcResult', err.message, true); }
    finally { submitting = false; b.disabled = false; b.textContent = old; }
  };
  async function checkStatus() {
    var b = this; b.disabled = true;
    try { var body = new FormData(); body.set('contid', b.dataset.id); var d = await post('template_status', body, 30000); note('tcNotice', '模板 ' + d.contid + '：' + d.label + (d.failed_reason ? '（' + d.failed_reason + '）' : ''), d.status === 'unpass'); }
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
})();
