(function () {
  'use strict';
  var root = document.getElementById('domainConsole');
  if (!root) return;
  var csrf = root.dataset.csrf, page = 1, total = 0, search = '', domain = '', sequence = 0, detailSequence = 0, editRecord = null, rows = [];
  var byId = function (id) { return document.getElementById(id); };
  var esc = function (v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (x) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[x]; }); };
  var message = function (text, detail, error) { var box = byId(detail ? 'domainDetailNotice' : 'domainNotice'); box.textContent = text; box.hidden = !text; box.classList.toggle('error', !!error); };
  async function api(action, values) {
    var body;
    if (typeof FormData !== 'undefined' && values instanceof FormData) { body = values; body.set('action', action); body.set('csrf', csrf); }
    else body = new URLSearchParams(Object.assign({ action: action, csrf: csrf }, values || {}));
    var controller = new AbortController(), timer = setTimeout(function () { controller.abort(); }, 65000);
    try {
      var response = await fetch('domains.php', { method: 'POST', credentials: 'same-origin', body: body, signal: controller.signal });
      if (response.redirected) throw new Error('登录已过期，请刷新页面重新登录');
      var data;
      try { data = await response.json(); } catch (_) { throw new Error('暂未收到有效响应，请稍后重试'); }
      if (!response.ok || !data.ok) throw new Error(data.error || '操作暂未成功');
      return data.data;
    } catch (e) { if (e.name === 'AbortError') throw new Error('商务中国响应较慢，请稍后重试；修改类操作请先刷新核对结果，勿重复提交'); throw e; }
    finally { clearTimeout(timer); }
  }
  async function busy(button, work) {
    if (button.disabled) return;
    var text = button.innerHTML; button.disabled = true; button.textContent = '处理中…';
    try { await work(); } catch (e) { message(e.message, byId('domainDrawer').open, true); }
    finally { button.disabled = false; button.innerHTML = text; }
  }
  function date(value) { return value ? String(value).slice(0, 10) : '暂未返回'; }
  function expiry(value) {
    if (!value) return '<span class="domain-muted">暂未返回</span>';
    var t = Date.parse(String(value).slice(0, 10) + 'T00:00:00'), days = Math.ceil((t - Date.now()) / 86400000);
    return esc(date(value)) + (Number.isFinite(days) && days <= 30 ? '<span class="domain-expiry ' + (days < 0 ? 'expired' : '') + '">' + (days < 0 ? '已到期' : '约 ' + days + ' 天后到期') + '</span>' : '');
  }
  function nameservers(value) {
    if (!value) return [];
    if (typeof value === 'string') return value.split(/[,;\s]+/).filter(Boolean);
    var output = [];
    Object.keys(value).forEach(function (key) { output = output.concat(nameservers(value[key])); });
    return output;
  }
  async function load() {
    var current = ++sequence;
    byId('domainPrev').disabled = byId('domainNext').disabled = true;
    byId('domainRows').innerHTML = '<tr><td colspan="8" class="domain-empty"><span class="domain-spinner"></span> 正在读取域名列表…</td></tr>';
    message('', false);
    try {
      var data = await api('list', { search: search, page: page });
      if (current !== sequence) return;
      rows = data.rows || []; total = data.total; page = data.page;
      byId('domainCount').textContent = total.toLocaleString() + ' 个';
      byId('domainUpdated').textContent = data.source === 'export' ? '商务中国导出表快照：' + (data.updated_at || '暂无') + (data.registered_added ? ' · 另有 API 确认注册 ' + data.registered_added + ' 个' : '') + ' · 按到期时间排序 · 不是实时同步' : '数据更新时间 ' + data.updated_at + ' · 按注册商顺序';
      byId('domainRows').innerHTML = rows.length ? rows.map(function (row, i) {
        var ns = nameservers(row.nameserver), name = row.domainname || row.doaminnamecode;
        return '<tr><td><button class="domain-name" data-domain-index="' + i + '">' + esc(row.display_name || name) + '</button><small>商务中国</small></td><td>' + esc(row.registrar || '详情中查看') + '</td><td>' + esc(row.owner_username || '暂未返回') + '</td><td>' + esc(date(row.regdate)) + '</td><td>' + expiry(row.expireddate) + '</td><td><span class="domain-status">' + esc(row.status || '暂未返回') + '</span></td><td class="domain-ns-cell">' + (ns.length ? ns.map(esc).join('<br>') : '<span class="domain-muted">详情中查看</span>') + '</td><td><button class="domain-btn ghost" data-domain-index="' + i + '">管理 <i class="fas fa-arrow-right"></i></button></td></tr>';
      }).join('') : '<tr><td colspan="8" class="domain-empty">没有找到匹配的域名。试试输入域名的一部分。</td></tr>';
      byId('domainPageInfo').textContent = '第 ' + page + ' / ' + Math.max(1, Math.ceil(total / 30)) + ' 页 · 共 ' + total.toLocaleString() + ' 个';
      byId('domainPrev').disabled = page <= 1; byId('domainNext').disabled = page * 30 >= total;
    } catch (e) { if (current === sequence) { byId('domainRows').innerHTML = '<tr><td colspan="8" class="domain-empty">暂未加载数据。可以点击右上角刷新重试。</td></tr>'; byId('domainUpdated').textContent = '连接待核验 · 暂未读取域名数据'; message(e.message, false, true); } }
  }
  async function openDetail(name) {
    domain = name; var current = ++detailSequence;
    byId('domainDetailName').textContent = domain; message('', true);
    byId('domainOverview').innerHTML = '<p class="domain-help">正在读取域名详情…</p>';
    byId('domainNsValue').value = ''; byId('domainRecordForm').hidden = true;
    byId('domainDrawer').showModal(); tab('overview');
    try {
      var data = await api('detail', { domain: domain }); if (current !== detailSequence) return;
      if (data.warning) message(data.warning, true, false);
      var info = data.info, ns = [];
      for (var n = 1; n <= 6; n++) if (info['dns_host' + n]) ns.push(info['dns_host' + n]);
      byId('domainNsValue').value = ns.join('\n');
      if(window.bizcnNsSync)window.bizcnNsSync(byId('domainNsValue'));
      byId('domainOverview').innerHTML = '<div class="domain-detail-grid">' + [['注册时间', date(info.reg_date)], ['到期时间', date(info.expire_date)], ['注册商', info.registrar || '暂未返回'], ['域名状态', info.status || '暂未返回'], ['WHOIS 核验状态', info.whois_status || '暂未返回'], ['实名状态（注册商原值）', info.dnvc_status || '请在实名页查询']].map(function (pair) { return '<article><span>' + esc(pair[0]) + '</span><strong>' + esc(pair[1]) + '</strong></article>'; }).join('') + '</div><div class="domain-ns-summary"><h3>当前 NS</h3><p>' + (ns.length ? ns.map(esc).join('<br>') : '注册商暂未返回 NS') + '</p></div>';
    } catch (e) { if (current === detailSequence) message(e.message, true, true); }
  }
  async function records() {
    var requested = domain, current = detailSequence;
    byId('domainRecordRows').innerHTML = '<p class="domain-help">正在读取解析…</p>';
    try {
      var data = await api('records', { domain: requested }); if (current !== detailSequence) return;
      byId('domainRecordRows').innerHTML = data.rows.length ? data.rows.map(function (row, i) {
        return '<article class="domain-record"><span class="domain-record-type">' + esc(row.type) + '</span><div><strong>' + esc(row.host) + '</strong><code>' + esc(row.value) + '</code><small>优先级 ' + esc(row.mxlevel || '—') + '</small></div><button class="domain-btn ghost" data-record-edit="' + i + '">编辑</button><button class="domain-btn danger" data-record-delete="' + i + '">删除</button></article>';
      }).join('') : '<p class="domain-empty">当前未返回解析记录。若 NS 在外部服务商，请到对应平台管理。</p>';
      byId('domainRecordRows').querySelectorAll('[data-record-edit]').forEach(function (button) { button.onclick = function () { recordForm(data.rows[Number(button.dataset.recordEdit)]); }; });
      byId('domainRecordRows').querySelectorAll('[data-record-delete]').forEach(function (button) { button.onclick = function () { var row = data.rows[Number(button.dataset.recordDelete)]; if (!window.confirm('确认删除 ' + requested + ' 的 ' + row.host + ' / ' + row.type + ' 记录？可能影响网站或邮箱。')) return; busy(button, async function () { await api('record_delete', { domain: requested, confirm: requested, type: row.type, host: row.host, old_value: row.value, priority: row.mxlevel || 10 }); message('解析记录已删除', true); await records(); }); }; });
    } catch (e) { if (current === detailSequence) { byId('domainRecordRows').innerHTML = ''; message(e.message, true, true); } }
  }
  function recordForm(row) {
    editRecord = row || null; byId('domainRecordForm').hidden = false;
    byId('domainRecordTitle').textContent = row ? '编辑解析' : '添加解析';
    byId('recordType').value = row ? row.type : 'A'; byId('recordHost').value = row ? row.host : '@';
    byId('recordType').disabled = byId('recordHost').disabled = !!row;
    byId('recordValue').value = row ? row.value : ''; byId('recordPriority').value = row ? (row.mxlevel || 10) : 10;
    byId('recordValue').focus();
  }
  async function realname() {
    var requested = domain, current = detailSequence;
    byId('domainRealnameResult').textContent = '正在读取实名认证状态…';
    try {
      var data = await api('realname', { domain: requested }); if (current !== detailSequence) return;
      // Preserve provider value; avoid claiming undocumented status-code semantics.
      byId('domainRealnameResult').textContent = '注册商审核状态：' + (data.status || '暂未返回') + (data.failed_reason ? '；说明：' + data.failed_reason : '');
    } catch (e) { if (current === detailSequence) byId('domainRealnameResult').textContent = e.message; }
  }
  function tab(name) {
    ['overview', 'ns', 'records', 'realname'].forEach(function (n) { byId('domain' + n[0].toUpperCase() + n.slice(1)).hidden = n !== name; });
    root.querySelectorAll('[data-tab]').forEach(function (b) { b.classList.toggle('active', b.dataset.tab === name); });
    if (name === 'records') records(); if (name === 'realname') realname();
  }
  byId('domainSearch').onsubmit = function (e) { e.preventDefault(); search = byId('domainQuery').value.trim(); page = 1; load(); };
  byId('domainRefresh').onclick = load;
  var importForm = byId('domainImportForm');
  if (importForm) importForm.onsubmit = function (e) {
    e.preventDefault(); var file = byId('domainImportFile').files[0];
    if (!file || file.size > 10 * 1024 * 1024) { message('请选择不超过10MB的 XLSX 域名导出表', false, true); return; }
    busy(e.submitter || this.querySelector('button'), async function () {
      var result = await api('import_export', new FormData(importForm));
      page = 1; await load();
      message(result.already_imported ? '这份表已经导入过，无需重复登记。' : '导入完成：新增 ' + result.created + ' 个，更新 ' + result.updated + ' 个，资料不变 ' + result.unchanged + ' 个。', false, false);
    });
  };
  byId('domainPrev').onclick = function () { if (page > 1) { page--; load(); } };
  byId('domainNext').onclick = function () { if (page * 30 < total) { page++; load(); } };
  byId('domainRows').onclick = function (e) { var button = e.target.closest('[data-domain-index]'); if (button) { var row = rows[Number(button.dataset.domainIndex)]; openDetail(row.doaminnamecode || row.domainname); } };
  byId('domainClose').onclick = function () { byId('domainDrawer').close(); detailSequence++; };
  byId('domainDrawer').addEventListener('close', function () { detailSequence++; });
  root.querySelectorAll('[data-tab]').forEach(function (b) { b.onclick = function () { tab(b.dataset.tab); }; });
  byId('domainNsForm').onsubmit = function (e) { e.preventDefault(); var requested = domain; if (!window.confirm('确认将 ' + requested + ' 的 NS 修改为：\n' + byId('domainNsValue').value + '\n\n请确保新 NS 上已有完整解析。')) return; busy(e.submitter || this.querySelector('button'), async function () { var data = await api('ns', { domain: requested, confirm: requested, ns: byId('domainNsValue').value }); message(data.message, true); }); };
  byId('domainAddRecord').onclick = function () { recordForm(null); };
  byId('recordCancel').onclick = function () { byId('domainRecordForm').hidden = true; };
  byId('domainRecordForm').onsubmit = function (e) { e.preventDefault(); var requested = domain, old = editRecord; if (!window.confirm('确认' + (old ? '修改' : '添加') + ' ' + requested + ' 的解析记录？')) return; busy(e.submitter || this.querySelector('button'), async function () { var data = await api(old ? 'record_edit' : 'record_add', { domain: requested, confirm: requested, type: byId('recordType').value, host: byId('recordHost').value, value: byId('recordValue').value, old_value: old ? old.value : '', priority: byId('recordPriority').value }); byId('domainRecordForm').hidden = true; message(data.message, true); await records(); }); };
  root.querySelectorAll('[data-panel]').forEach(function (button) { button.onclick = function () {
    // Open synchronously so browsers do not block the tab after asynchronous IO.
    var popup = window.open('about:blank', '_blank'); if (!popup) { message('请允许浏览器打开新窗口后重试', true, true); return; }
    popup.opener = null;
    busy(button, async function () { try { var data = await api('panel', { domain: domain }); var url = new URL(data.url); if (url.protocol !== 'https:' || url.hostname !== 'dnsmsn.com') throw new Error('安全检查未通过'); popup.location.replace(url.href); } catch (e) { popup.close(); throw e; } });
  }; });
  load();
})();
