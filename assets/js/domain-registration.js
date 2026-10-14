(function () {
  'use strict';
  var root = document.getElementById('domainConsole'), section = document.getElementById('domainRegistration');
  if (!root || !section) return;
  var config, quote = null, approved = Object.create(null), templatePage = 1, templateTotal = 0;
  var el = function (id) { return document.getElementById(id); };
  var esc = function (v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var states = { reserved: '已保留额度 · 等待提交', unknown: '结果待核对 · 请勿重复注册', processing: '注册商处理中', registered: '已注册', failed: '已关闭 / 注册失败' };
  function notice(text, error) { el('registrationNotice').textContent = text; el('registrationNotice').hidden = !text; el('registrationNotice').classList.toggle('error', !!error); }
  async function api(action, values) {
    var ctl = new AbortController(), timer = setTimeout(function () { ctl.abort(); }, 90000);
    try {
      var r = await fetch('domains.php', { method: 'POST', credentials: 'same-origin', body: new URLSearchParams(Object.assign({ action: 'registration_' + action, csrf: root.dataset.csrf }, values || {})), signal: ctl.signal });
      if (r.redirected) throw new Error('登录已过期，请刷新后重新登录');
      var d; try { d = await r.json(); } catch (_) { throw new Error('未收到有效结果。注册申请请先刷新记录核对，不要重复购买。'); }
      if (!r.ok || !d.ok) throw new Error(d.error || '操作未完成'); return d.data;
    } catch (e) { if (e.name === 'AbortError') throw new Error('响应较慢。请刷新申请记录核对结果，不要重复注册。'); throw e; }
    finally { clearTimeout(timer); }
  }
  async function busy(button, fn) {
    if (button.disabled) return; button.disabled = true; var old = button.textContent; button.textContent = '请稍候…';
    try { await fn(); } catch (e) { notice(e.message, true); } finally { button.disabled = false; button.textContent = old; if(button.id==='registrationTemplatePrev')button.disabled=templatePage<=1;if(button.id==='registrationTemplateNext')button.disabled=templatePage*20>=templateTotal; }
  }
  function approvedView() {
    var box = el('registrationApproved'); if (!box) return;
    box.innerHTML = Object.keys(approved).map(function (id, i) { var t = approved[id]; return '<article class="registration-template"><div><strong>' + esc(t.label) + '</strong><small>' + esc(id) + '</small></div><label>实名主体<input data-owner-index="' + i + '" value="' + esc(t.owner) + '" maxlength="200" required></label><button type="button" class="domain-btn ghost" data-remove-index="' + i + '">移除</button></article>'; }).join('') || '<p class="domain-help">尚未批准模板，注册暂不可用。</p>';
    var ids = Object.keys(approved);
    box.querySelectorAll('[data-owner-index]').forEach(function (input) { input.oninput = function () { approved[ids[Number(input.dataset.ownerIndex)]].owner = input.value; }; });
    box.querySelectorAll('[data-remove-index]').forEach(function (b) { b.onclick = function () { delete approved[ids[Number(b.dataset.removeIndex)]]; approvedView(); }; });
  }
  function templateOptions(rows, first) {
    var select = el('registrationTemplate'), current = select.value;
    select.innerHTML = '<option value="">' + esc(first) + '</option>' + rows.map(function (t) { return '<option value="' + esc(t.id) + '">' + esc((t.label && t.label !== t.owner ? t.label + ' · ' : '') + (t.owner || t.id) + ' · ' + t.id) + '</option>'; }).join('');
    if (rows.some(function (t) { return t.id === current; })) select.value = current;
  }
  async function findTemplates(refresh) {
    var term = el('registrationTemplateQuery').value.trim();
    if (term.length < 2) throw new Error('请输入客户实名主体名称或模板编号（至少2个字）');
    var d = await api('find_template', { search: term, refresh: refresh ? '1' : '0' });
    templateOptions(d.rows, d.rows.length ? '请选择实际实名主体（找到 ' + d.rows.length + ' 个）' : '没有找到匹配的实名主体');
    cancelQuote();
    if (d.rows.length) { el('registrationTemplate').focus(); notice(d.warning || '已找到 ' + d.rows.length + ' 个实名主体，请在下方「域名实名主体」选择，并核对主体名称。', !!d.warning); }
    else notice(d.warning || '没有找到。新客户请先点「新客户？去商务中国创建实名信息」，审核通过（2–5 个工作日）后再点「刷新最新实名并查找」。', true);
  }
  async function loadConfig(resetSettings) {
    config = await api('config');
    el('registrationQuota').textContent = '北京时间 ' + config.day + ' · 全平台每日上限 ' + config.daily_limit + ' 个 · 已用/保留 ' + config.used + ' 个 · 可用 ' + Math.max(0, config.daily_limit - config.used) + ' 个';
    templateOptions(config.templates, config.templates.length ? '请选择实际实名主体' : '请先查找实名主体');
    el('registrationCheck').disabled = !config.ready;
    if (!config.ready) notice('注册准备中：请管理员同步实名模板目录、确认默认 NS，并开启每日额度。', false);
    if (config.admin && resetSettings) {
      el('registrationLimit').value = config.daily_limit; el('registrationDefaultNs').value = config.ns.join('\n'); approved = Object.create(null);
      if(window.bizcnNsSync)window.bizcnNsSync(el('registrationDefaultNs'));
      config.templates.forEach(function (t) { approved[t.id] = t; }); approvedView();
    }
  }
  async function history() {
    var data = await api('history');
    el('registrationHistory').innerHTML = data.rows.map(function (r, i) {
      return '<article class="registration-template"><div><strong>' + esc(r.domain) + '</strong><small>申请 #' + r.id + ' · ' + esc(r.day) + ' · ¥' + (r.quote.price_cents / 100).toFixed(2) + '</small><p>' + esc(states[r.status] || r.status) + (r.order_id ? ' · 注册商订单 ' + esc(r.order_id) : '') + '</p></div><button class="domain-btn ghost" type="button" data-status-index="' + i + '">核对结果</button>' + (config.admin && r.status === 'unknown' ? '<button class="domain-btn ghost" type="button" data-reconcile-index="' + i + '">关联注册商订单</button>' : '') + (config.admin && (r.status === 'unknown' || r.status === 'reserved') ? '<button class="domain-btn ghost" type="button" data-release-index="' + i + '">人工核验未扣费</button>' : '') + '</article>';
    }).join('') || '<p class="domain-help">还没有注册申请。查询报价不会计入注册额度。</p>';
    el('registrationHistory').querySelectorAll('[data-status-index]').forEach(function (b) { b.onclick = function () { busy(b, async function () { var r = await api('status', { id: data.rows[Number(b.dataset.statusIndex)].id }); notice(r.domain + '：' + (states[r.status] || r.status), false); await history(); await loadConfig(false); if (r.status === 'registered') el('domainRefresh').click(); }); }; });
    el('registrationHistory').querySelectorAll('[data-reconcile-index]').forEach(function (b) { b.onclick = function () { var r=data.rows[Number(b.dataset.reconcileIndex)], order=window.prompt('填写商务中国后台中 '+r.domain+' 的注册订单号，仅查询核对，不购买'); if(!order)return; busy(b,async function(){var d=await api('reconcile',{id:r.id,order_id:order});notice(d.domain+'：'+(states[d.status]||d.status),false);await history();await loadConfig(false);if(d.status==='registered')el('domainRefresh').click();}); }; });
    el('registrationHistory').querySelectorAll('[data-release-index]').forEach(function (b) { b.onclick = function () {
      var r = data.rows[Number(b.dataset.releaseIndex)];
      if (!window.confirm('仅在商务中国确认 ' + r.domain + ' 未注册、未扣款且无处理中订单后才能关闭申请。确认已核实？')) return;
      var note = window.prompt('请填写注册商核验依据（至少8字，不填写密码或证件）'); if (!note) return;
      busy(b, async function () { var d = await api('release', { id: r.id, confirm: r.domain, note: note }); notice(d.message, false); await history(); await loadConfig(false); });
    }; });
  }
  function cancelQuote() { quote = null; el('registrationConfirm').hidden = true; el('registrationAck').checked = false; }
  el('registrationToggle').onclick = function () { var open = el('registrationWorkspace').hidden; el('registrationWorkspace').hidden = !open; this.setAttribute('aria-expanded', String(open)); if (open) history().catch(function (e) { notice(e.message, true); }); };
  el('registrationSearch').onsubmit = function (e) {
    e.preventDefault(); cancelQuote(); busy(el('registrationCheck'), async function () {
      quote = await api('quote', { domain: el('registrationDomain').value.trim(), template_id: el('registrationTemplate').value, term: el('registrationTerm').value, order_no: el('registrationOrderNo').value.trim() }); var q = quote.quote;
      el('registrationQuote').innerHTML = [['域名', q.domain], ['注册年限', (q.term || 1) + ' 年'], [(q.term || 1) > 1 ? '合计（单年 ¥' + (q.unit_price_cents / 100).toFixed(2) + ' × ' + q.term + '）' : '一年注册价', '¥' + (q.price_cents / 100).toFixed(2) + (q.premium ? ' · 高价域名' : '')], ['实名主体', q.template.owner], ['模板', q.template.label], ['默认 NS', q.ns.join(' / ')], ['有效期', '5分钟，提交时再次核价']].concat(q.order_no ? [['关联订单', q.order_no]] : []).concat(q.template_pending ? [['实名状态', '审核中：域名先注册，实名随后审核通过']] : []).map(function (p) { return '<article><span>' + esc(p[0]) + '</span><strong>' + esc(p[1]) + '</strong></article>'; }).join('');
      el('registrationConfirm').hidden = false; notice('可以注册。请核对以下信息，再确认购买。', false);
    });
  };
  el('registrationFindTemplate').onclick = function () { busy(this, function () { return findTemplates(false); }); };
  el('registrationRefreshTemplates').onclick = function () { busy(this, function () { return findTemplates(true); }); };
  el('registrationTemplateQuery').onkeydown = function (e) { if (e.key === 'Enter') { e.preventDefault(); el('registrationFindTemplate').click(); } };
  el('registrationNewSubject').onclick = function () {
    // Open the tab inside the click so popup blockers allow it; certificates are entered on Bizcn's own page only.
    var tab = window.open('', '_blank'); if (tab) tab.opener = null;
    busy(this, async function () { try { var d = await api('panel'); if (tab) tab.location.href = d.url; else window.location.href = d.url; notice('已打开商务中国官方面板：在「域名管理 > 添加域名模板」创建实名信息，审核通过后回到这里刷新最新实名。', false); } catch (e) { if (tab) tab.close(); throw e; } });
  };
  el('registrationDomain').oninput = cancelQuote; el('registrationTemplate').onchange = cancelQuote; el('registrationTerm').onchange = cancelQuote; el('registrationCancel').onclick = cancelQuote;
  el('registrationConfirm').onsubmit = function (e) {
    e.preventDefault(); if (!quote || !el('registrationAck').checked) return;
    var saved = quote, q = saved.quote;
    if (!window.confirm('确认注册 ' + q.domain + '（' + (q.term || 1) + '年）\n实名主体：' + q.template.owner + '\n真实扣费：¥' + (q.price_cents / 100).toFixed(2) + '？')) return;
    busy(el('registrationBuy'), async function () {
      // Stop repeat purchase UX immediately, including on timeout. The ledger remains authoritative.
      cancelQuote();
      try { var r = await api('submit', { token: saved.token, confirm: q.domain, ack: '1' }); notice(q.domain + '：' + (states[r.status] || r.status), false); if (r.status === 'registered') el('domainRefresh').click(); }
      catch (e) { notice(e.message + ' 可在申请记录中核对，不要重复购买。', true); }
      await history(); await loadConfig(false);
    });
  };
  el('registrationHistoryRefresh').onclick = function () { busy(this, history); };
  async function templates() {
    var d = await api('templates', { page: templatePage, search: el('registrationTemplateSearch').value.trim(), contid: el('registrationTemplateIdSearch').value.trim() }); templateTotal = d.total;templatePage=d.page;
    el('registrationTemplatePage').textContent = '第 ' + templatePage + ' 页 · 匹配 ' + templateTotal + ' 个模板' + (d.cached ? ' · 已同步目录 ' + d.cached_count + ' 个（可继续同步更新）' : '');
    el('registrationTemplatePrev').disabled = templatePage <= 1; el('registrationTemplateNext').disabled = templatePage * 20 >= templateTotal;
    el('registrationCandidates').innerHTML = d.rows.map(function (t, i) { return '<article class="registration-template"><div><strong>' + esc(t.label || t.id) + '</strong><small>' + esc(t.id) + ' · ' + esc(t.owner || '请选择后填写准确实名主体') + '</small></div><button class="domain-btn ghost" type="button" data-approve-index="' + i + '">加入批准列表</button></article>'; }).join('');
    el('registrationCandidates').querySelectorAll('[data-approve-index]').forEach(function (b) { b.onclick = function () { var t = d.rows[Number(b.dataset.approveIndex)]; approved[t.id] = { id: t.id, label: t.label || t.id, owner: t.owner }; approvedView(); }; });
  }
  if (el('registrationSettingsForm')) {
    el('registrationSyncTemplates').onclick=function(){busy(this,async function(){for(var p=1;p<=1000;p++){var d=await api('sync_templates',{page:p});notice('正在同步模板目录：'+d.completed+' / '+d.total+'。不会批准模板或购买域名。',false);if(d.done){templatePage=1;await templates();notice('模板目录同步完成，可以按名称、主体或编号搜索。',false);return;}}throw new Error('模板数量超过本次同步范围，请联系管理员');});};
    el('registrationLoadTemplates').onclick = function () { templatePage=1;busy(this, templates); };
    el('registrationTemplatePrev').onclick = function () { templatePage--; busy(this, templates); };
    el('registrationTemplateNext').onclick = function () { templatePage++; busy(this, templates); };
    el('registrationSettingsForm').onsubmit = function (e) { e.preventDefault(); busy(e.submitter || this.querySelector('[type=submit]'), async function () { var d = await api('settings', { revision: config.revision, daily_limit: el('registrationLimit').value, ns: el('registrationDefaultNs').value, templates: JSON.stringify(Object.keys(approved).map(function (id) { return approved[id]; })) }); cancelQuote(); await loadConfig(true); notice(d.message, false); }); };
  }
  loadConfig(true).catch(function (e) { notice(e.message, true); });
  if (window.location.hash === '#register') { el('registrationToggle').click(); section.scrollIntoView(); }
})();
