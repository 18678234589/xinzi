(function () {
  'use strict';
  var root = document.getElementById('domainConsole');
  if (!root) return;
  var csrf = root.dataset.csrf, page = 1, total = 0, search = '', filter = 'all', statusKey = '', scope = root.dataset.defaultScope === 'mine' ? 'mine' : 'all', scopeNote = '', domain = '', sequence = 0, detailSequence = 0, recordsSequence = 0, editRecord = null, rows = [], recordProvider = '', recordLabel = '', currentDetailData = null;
  var byId = function (id) { return document.getElementById(id); };
  var esc = function (v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (x) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[x]; }); };

  // 添加 / 编辑解析的表单在抽屉下方，顶部的提示看不到：表单打开时把结果显示在表单旁边，并滚动到可见位置。
  var message = function (text, detail, error) {
    var box = byId(detail ? 'domainDetailNotice' : 'domainNotice'), form = byId('domainRecordForm'), inline = byId('recordFormStatus');
    var nsInline = byId('nsFormStatus'), nsPanel = byId('domainNs');
    if (detail && nsInline && nsPanel && !nsPanel.hidden) {
      if (inline) inline.hidden = true;
      box.hidden = true; nsInline.textContent = text; nsInline.hidden = !text; nsInline.classList.toggle('error', !!error);
      if (text && nsInline.scrollIntoView) nsInline.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      return;
    }
    var recordsPanel = byId('domainRecords');
    var useInline = !!(detail && inline && text && ((form && !form.hidden) || (recordsPanel && !recordsPanel.hidden)));
    if (inline) { inline.textContent = useInline ? text : ''; inline.hidden = !useInline; inline.classList.toggle('error', !!error); }
    box.textContent = useInline ? '' : text; box.hidden = useInline || !text; box.classList.toggle('error', !!error);
    var target = useInline ? inline : (text ? box : null);
    if (target && target.scrollIntoView) target.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  };

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
    } catch (e) { if (e.name === 'AbortError') throw new Error('服务商响应较慢，请稍后重试；修改类操作请先刷新核对结果，勿重复提交'); throw e; }
    finally { clearTimeout(timer); }
  }

  async function busy(button, work) {
    if (button.disabled) return;
    var requestedDomain = domain, inDetail = byId('domainDrawer').open;
    var text = button.innerHTML; button.disabled = true; button.textContent = '处理中…';
    try { await work(); } catch (e) { if (!inDetail || (byId('domainDrawer').open && requestedDomain === domain)) message(e.message, inDetail, true); }
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

  // 状态栏目：计数来自后台逐个域名同步的实名 / 命名审核状态；没同步到的域名只按目录里的到期日判断。
  var statusClass = { naming_failed: 'bad', unsubmitted: 'warn', auditing: 'info', realname_failed: 'bad', template_failed: 'bad', abnormal: 'warn', expiring: 'warn', expired: 'bad', redemption: 'info' };
  function statusTags(row) {
    return (row.status_tags || []).map(function (t) { return '<span class="domain-tag ' + (statusClass[t.key] || '') + '"' + (row.audit_reason && /failed/.test(t.key) ? ' title="' + esc(row.audit_reason) + '"' : '') + '>' + esc(t.label) + '</span>'; }).join('');
  }
  function statusSummary(s) {
    var note = byId('domainStatusNote');
    if (!s || s.scope === 'mine') { if (s && s.scope === 'mine') root.querySelectorAll('[data-count]').forEach(function (b) { var n = Number((s.counts || {})[b.dataset.count] || 0); b.textContent = n ? n.toLocaleString() : ''; b.hidden = !n; }); if (note) note.hidden = true; return; }
    root.querySelectorAll('[data-count]').forEach(function (b) { var n = Number((s.counts || {})[b.dataset.count] || 0); b.textContent = n ? n.toLocaleString() : ''; b.hidden = !n; });
    if (!note) return;
    var when = s.last_checked ? new Date(s.last_checked * 1000).toLocaleString('zh-CN', { hour12: false }) : '尚未同步';
    note.hidden = false;
    note.textContent = '状态数据来自后台逐个域名同步：已同步 ' + Number(s.synced).toLocaleString() + ' / ' + Number(s.total).toLocaleString() + ' 个，最近同步 ' + when + (s.synced < s.total ? '。同步未完成，计数会逐步完整。' : '。有问题的域名每小时复查，其余每 3 天复查。');
  }
  function markScope() {
    var tabs = byId('domainScopeTabs');
    if (tabs) tabs.querySelectorAll('[data-scope]').forEach(function (b) { b.classList.toggle('active', b.dataset.scope === scope); });
    var chips = byId('domainFilterTabs'); if (chips) chips.hidden = scope === 'mine';
    var imp = root.querySelector('.domain-import'); if (imp) imp.hidden = scope === 'mine';
  }
  async function load() {
    var current = ++sequence;
    byId('domainPrev').disabled = byId('domainNext').disabled = true;
    byId('domainRows').innerHTML = '<tr><td colspan="8" class="domain-empty"><span class="domain-spinner"></span> 正在读取域名列表…</td></tr>';
    message('', false);
    try {
      var data = await api('list', { search: search, page: page, source: filter, status: statusKey, scope: scope });
      if (current !== sequence) return;
      if (scope === 'mine' && !search && !statusKey && !data.total) { scope = 'all'; scopeNote = '你参与的订单还没有登记域名，已显示全部域名。注册域名时填写订单号，或在域名详情里绑定订单，就会出现在「我的订单域名」。'; markScope(); return load(); }
      rows = data.rows || []; total = data.total; page = data.page;
      if (data.mine_total != null && byId('countMine')) byId('countMine').textContent = Number(data.mine_total).toLocaleString();
      markScope();
      if (data.counts) {
        if (byId('countAll')) byId('countAll').textContent = Number(data.counts.all || 0).toLocaleString();
        if (byId('countDns')) byId('countDns').textContent = Number(data.counts.dns || 0).toLocaleString();
        if (byId('countRenewal')) byId('countRenewal').textContent = Number(data.counts.renewal || 0).toLocaleString();
        if (byId('countBizcn')) byId('countBizcn').textContent = Number(data.counts.bizcn || 0).toLocaleString();
      }
      statusSummary(data.status_summary);
      byId('domainCount').textContent = total.toLocaleString() + ' 个' + (scope === 'mine' ? '（我的订单）' : '');
      if (scopeNote) { message(scopeNote, false, false); scopeNote = ''; }
      byId('domainUpdated').textContent = data.source === 'unified'
        ? '多数据源统一管理（DNS解析托管 + 业务续费 + 商务中国）· 数据时间 ' + (data.updated_at || '最新')
        : (data.source === 'export' ? '商务中国导出表快照：' + (data.updated_at || '暂无') + (data.registered_added ? ' · 另有 API 确认注册 ' + data.registered_added + ' 个' : '') + ' · 临近到期优先' : '数据更新时间 ' + data.updated_at);
      byId('domainRows').innerHTML = rows.length ? rows.map(function (row, i) {
        var ns = nameservers(row.nameserver), name = row.domainname || row.doaminnamecode;
        var badgeClass = row.source_badge || 'bizcn';
        var badgeLabel = row.source_label || (row.source === 'dns' ? 'DNS解析' : (row.source === 'renewal' ? '业务续费' : '商务中国'));
        var sourceHtml = '<span class="domain-source-badge ' + esc(badgeClass) + '">' + esc(badgeLabel) + '</span>';
        var ownerDisplay = esc(row.owner_username || '暂未返回');
        if (row.order_no) {
          ownerDisplay = '<a href="index.php?search=' + encodeURIComponent(row.order_no) + '" target="_blank" style="color:inherit;text-decoration:underline;" title="点击在订单管理中查看">' + ownerDisplay + '</a>';
        }
        return '<tr><td><button class="domain-name" data-domain-index="' + i + '">' + esc(row.display_name || name) + '</button>' + sourceHtml + '</td><td>' + esc(row.registrar || '详情中查看') + '</td><td>' + ownerDisplay + '</td><td>' + esc(date(row.regdate)) + '</td><td>' + expiry(row.expireddate) + '</td><td><span class="domain-status">' + esc(row.status || '暂未返回') + '</span>' + statusTags(row) + '</td><td class="domain-ns-cell">' + (row.ns_label ? '<span class="domain-tag ns-' + esc(row.ns_provider) + '">' + esc(row.ns_label) + '</span><br>' : '') + (ns.length ? ns.map(esc).join('<br>') : '<span class="domain-muted">详情中查看</span>') + '</td><td class="domain-actions"><button class="domain-btn" data-domain-index="' + i + '" data-open-records="1">解析</button><button class="domain-btn ghost" data-domain-index="' + i + '">管理 <i class="fas fa-arrow-right"></i></button></td></tr>';
      }).join('') : '<tr><td colspan="8" class="domain-empty">没有找到匹配的域名。试试输入域名的一部分。</td></tr>';
      byId('domainPageInfo').textContent = '第 ' + page + ' / ' + Math.max(1, Math.ceil(total / 30)) + ' 页 · 共 ' + total.toLocaleString() + ' 个域名';
      byId('domainPrev').disabled = page <= 1; byId('domainNext').disabled = page * 30 >= total;
    } catch (e) { if (current === sequence) { byId('domainRows').innerHTML = '<tr><td colspan="8" class="domain-empty">暂未加载数据。可以点击右上角刷新重试。</td></tr>'; byId('domainUpdated').textContent = '连接待核验 · 暂未读取域名数据'; message(e.message, false, true); } }
  }

  function updateDrawerFooter(data) {
    var panelButtons = root.querySelectorAll('[data-panel]');
    panelButtons.forEach(function (button) {
      if (data && data.source === 'dns') {
        button.innerHTML = '在 DNS 解析中打开 <i class="fas fa-external-link-alt"></i>';
        button.onclick = function () { window.open('dns.php?d=' + encodeURIComponent(domain) + '&p=' + encodeURIComponent(data.dns_provider || ''), '_blank'); };
      } else if (data && data.source === 'renewal' && data.order_no) {
        button.innerHTML = '查看业务订单 <i class="fas fa-external-link-alt"></i>';
        button.onclick = function () { window.open('index.php?search=' + encodeURIComponent(data.order_no), '_blank'); };
      } else {
        button.innerHTML = '打开官方面板 <i class="fas fa-external-link-alt"></i>';
        button.onclick = function () {
          var popup = window.open('about:blank', '_blank');
          if (!popup) { message('请允许浏览器打开新窗口后重试', true, true); return; }
          popup.opener = null;
          busy(button, async function () {
            try {
              var res = await api('panel', { domain: domain });
              var url = new URL(res.url);
              if (url.protocol !== 'https:' || url.hostname !== 'dnsmsn.com') throw new Error('安全检查未通过');
              popup.location.replace(url.href);
            } catch (e) { popup.close(); throw e; }
          });
        };
      }
    });
  }

  async function openDetail(name) {
    domain = name; var current = ++detailSequence;
    recordProvider = ''; recordLabel = ''; currentDetailData = null; recordsSequence++; byId('domainDnsWarning').hidden = true; byId('domainExternalDnsLink').hidden = true; byId('nsFormStatus').hidden = true;
    byId('domainDetailName').textContent = domain; message('', true);
    byId('domainOverview').innerHTML = '<p class="domain-help">正在读取域名详情…</p>';
    byId('domainNsValue').value = ''; byId('domainRecordForm').hidden = true;
    updateDrawerFooter(null);
    byId('domainDrawer').showModal(); tab('overview');
    try {
      var data = await api('detail', { domain: domain }); if (current !== detailSequence) return;
      currentDetailData = data;
      updateDrawerFooter(data);
      if (data.warning) message(data.warning, true, false);
      var info = data.info, ns = [];
      for (var n = 1; n <= 6; n++) if (info['dns_host' + n]) ns.push(info['dns_host' + n]);
      byId('domainNsValue').value = ns.join('\n');
      if (window.bizcnNsSync) window.bizcnNsSync(byId('domainNsValue'));
      var pairs = [
        ['注册时间', date(info.reg_date)],
        ['到期时间', date(info.expire_date)],
        ['注册商', info.registrar || '暂未返回'],
        ['域名状态', info.status || '暂未返回'],
        ['数据来源', data.source_label || (data.source === 'dns' ? 'DNS解析托管' : (data.source === 'renewal' ? '业务续费' : '商务中国'))],
        ['所属账号 / 客户', data.owner_username || '暂未返回']
      ];
      var extraLinks = '';
      if (data.source === 'dns') {
        extraLinks = '<div style="margin-top:14px;"><a class="domain-btn ghost" href="dns.php?d=' + encodeURIComponent(domain) + '&p=' + encodeURIComponent(data.dns_provider || '') + '" target="_blank"><i class="fas fa-network-wired"></i> 在 DNS 解析管理中心打开 ↗</a></div>';
      } else if (data.source === 'renewal' && data.order_no) {
        extraLinks = '<div style="margin-top:14px;"><a class="domain-btn ghost" href="index.php?search=' + encodeURIComponent(data.order_no) + '" target="_blank"><i class="fas fa-file-invoice"></i> 查看关联业务订单（' + esc(data.order_no) + '）↗</a></div>';
      }
      byId('domainOverview').innerHTML = '<div class="domain-detail-grid">' + pairs.map(function (pair) { return '<article><span>' + esc(pair[0]) + '</span><strong>' + esc(pair[1]) + '</strong></article>'; }).join('') + '</div><div class="domain-ns-summary"><h3>当前 NS</h3><p id="domainCurrentNs">' + (ns.length ? ns.map(esc).join('<br>') : '暂未返回 NS') + '</p></div>' + extraLinks;
      renderOrders(data);
    } catch (e) { if (current === detailSequence) message(e.message, true, true); }
  }

  async function records(savedMessage) {
    var requested = domain, current = detailSequence, read = ++recordsSequence;
    recordProvider = ''; byId('domainAddRecord').disabled = true; byId('domainRecordForm').hidden = true;
    byId('domainRecordHelp').textContent = '正在根据当前 NS 连接解析服务商…';
    byId('domainRecordRows').innerHTML = '<p class="domain-help">正在读取解析…</p>';
    try {
      var data = await api('records', { domain: requested }); if (current !== detailSequence || read !== recordsSequence) return;
      if (data.provider === 'external') {
        recordProvider = '';
        byId('domainAddRecord').disabled = true;
        byId('domainRecordHelp').textContent = data.message || '当前 NS 暂未接入本站托管解析。';
        byId('domainRecordRows').innerHTML = '<p class="domain-empty">' + esc(data.message || '当前 NS 暂未接入本站托管解析。') + '</p><div style="text-align:center;margin-top:16px;"><a class="domain-btn" href="dns.php?d=' + encodeURIComponent(requested) + '" target="_blank"><i class="fas fa-plus"></i> 前往本站【解析管理】接入解析</a></div>';
        return;
      }
      recordProvider = data.provider || 'bizcn'; recordLabel = data.provider_label || '商务中国';
      byId('domainAddRecord').disabled = false;
      byId('domainRecordHelp').textContent = '当前解析服务商：' + recordLabel + '。此处直接通过 API 管理该服务商的解析记录。';
      byId('domainRecordRows').innerHTML = data.rows.length ? data.rows.map(function (row, i) {
        return '<article class="domain-record"><span class="domain-record-type">' + esc(row.type) + '</span><div><strong>' + esc(row.host) + '</strong><code>' + esc(row.value) + '</code><small>' + (row.ttl ? 'TTL ' + esc(row.ttl === 1 ? '自动' : row.ttl + ' 秒') + ' · ' : '') + (row.line ? esc(row.line) + ' · ' : '') + (row.proxied ? '已开启代理 · ' : '') + (row.enabled === false ? '已停用 · ' : '') + '优先级 ' + esc(row.mxlevel == null ? '—' : row.mxlevel) + '</small></div>' + (row.editable === false ? '<small>只读</small>' : '<button class="domain-btn ghost" data-record-edit="' + i + '">编辑</button><button class="domain-btn danger" data-record-delete="' + i + '">删除</button>') + '</article>';
      }).join('') : '<p class="domain-empty">' + esc(recordLabel) + ' 暂无解析记录，可以直接添加。</p>';
      byId('domainRecordRows').querySelectorAll('[data-record-edit]').forEach(function (button) { button.onclick = function () { recordForm(data.rows[Number(button.dataset.recordEdit)]); }; });
      byId('domainRecordRows').querySelectorAll('[data-record-delete]').forEach(function (button) { button.onclick = function () { var row = data.rows[Number(button.dataset.recordDelete)]; if (!window.confirm('确认删除 ' + requested + ' 在 ' + recordLabel + ' 的 ' + row.host + ' / ' + row.type + ' 记录？可能影响网站或邮箱。')) return; busy(button, async function () { var result = await api('record_delete', { domain: requested, confirm: requested, provider: data.provider || 'bizcn', record_id: row.id || '', record_revision: row.revision || '', type: row.type, host: row.host, old_value: row.value, priority: row.mxlevel || 10 }); if (current !== detailSequence) return; var saved = result.message || '解析记录已删除'; message(saved, true); await records(saved); }); }; });
    } catch (e) { if (current === detailSequence && read === recordsSequence) { byId('domainRecordHelp').textContent = '暂未连接到当前解析服务商，请刷新解析记录重试。'; byId('domainRecordRows').innerHTML = ''; message(savedMessage ? savedMessage + '。但记录列表刷新失败：' + e.message + '，请稍后刷新核对，勿重复保存。' : e.message, true, true); } }
  }

  function recordForm(row) {
    if (!recordProvider || (row && row.editable === false)) return;
    editRecord = row || null; byId('domainRecordForm').hidden = false; message('', true);
    byId('domainRecordTitle').textContent = row ? '编辑解析' : '添加解析';
    byId('recordType').value = row ? row.type : 'A'; byId('recordHost').value = row ? row.host : '@';
    byId('recordType').disabled = byId('recordHost').disabled = false; byId('recordEditHint').hidden = !row;
    byId('recordEditHint').textContent = recordProvider === 'bizcn' ? '修改为 CNAME 等互斥类型时，会先删除原记录再添加；添加失败会尝试恢复原记录。请提交后查看结果提示。' : '保存后将直接修改 ' + recordLabel + ' 中的这条记录。';
    byId('recordType').querySelectorAll('option').forEach(function (option) { option.disabled = recordProvider !== 'bizcn' && ['SRV', 'CAA'].indexOf(option.value) >= 0; });
    byId('recordValue').value = row ? row.value : ''; byId('recordPriority').value = row && row.mxlevel != null ? row.mxlevel : 10;
    byId('recordTtlWrap').hidden = recordProvider === 'bizcn'; byId('recordTtl').value = row && row.ttl != null ? row.ttl : 600;
    byId('recordProxyWrap').hidden = recordProvider !== 'cloudflare'; byId('recordProxyMode').value = row && row.proxied ? '1' : '0';
    byId('recordValue').focus();
  }

  async function realname() {
    var requested = domain, current = detailSequence;
    byId('domainRealnameResult').textContent = '正在读取实名认证状态…';
    try {
      var data = await api('realname', { domain: requested }); if (current !== detailSequence) return;
      byId('domainRealnameResult').textContent = '注册商审核状态：' + (data.status || '暂未返回') + (data.failed_reason ? '；说明：' + data.failed_reason : '');
    } catch (e) { if (current === detailSequence) byId('domainRealnameResult').textContent = e.message; }
  }

  function tab(name) {
    ['overview', 'ns', 'records', 'realname'].forEach(function (n) { byId('domain' + n[0].toUpperCase() + n.slice(1)).hidden = n !== name; });
    root.querySelectorAll('[data-tab]').forEach(function (b) { b.classList.toggle('active', b.dataset.tab === name); });
    if (name === 'records') records(); if (name === 'realname') realname();
  }

  byId('domainSearch').onsubmit = function (e) { e.preventDefault(); search = byId('domainQuery').value.trim(); page = 1; load(); };
  byId('domainRefresh').onclick = function () {
    busy(byId('domainRefresh'), async function () {
      await api('list', { search: search, page: page, source: filter, refresh: 1 });
      page = 1;
      await load();
      message('域名目录与解析托管状态已刷新', false);
    });
  };

  var filterTabs = byId('domainFilterTabs');
  if (filterTabs) {
    filterTabs.onclick = function (e) {
      var btn = e.target.closest('[data-filter]');
      if (!btn) return;
      filterTabs.querySelectorAll('[data-filter]').forEach(function (b) { b.classList.remove('active'); });
      btn.classList.add('active');
      filter = btn.dataset.filter || 'all';
      statusKey = '';
      markStatus();
      page = 1;
      load();
    };
  }

  function markStatus() {
    var tabs = byId('domainStatusTabs');
    if (tabs) tabs.querySelectorAll('[data-status]').forEach(function (b) { b.classList.toggle('active', (b.dataset.status || '') === statusKey); });
    if (statusKey && filterTabs) filterTabs.querySelectorAll('[data-filter]').forEach(function (b) { b.classList.remove('active'); });
  }
  var statusTabs = byId('domainStatusTabs');
  if (statusTabs) statusTabs.onclick = function (e) {
    var btn = e.target.closest('[data-status]'); if (!btn) return;
    statusKey = btn.dataset.status || '';
    if (!statusKey) { filter = 'all'; if (filterTabs) filterTabs.querySelectorAll('[data-filter]').forEach(function (b) { b.classList.toggle('active', b.dataset.filter === 'all'); }); }
    markStatus(); page = 1; load();
  };

  var scopeTabs = byId('domainScopeTabs');
  if (scopeTabs) scopeTabs.onclick = function (e) {
    var btn = e.target.closest('[data-scope]'); if (!btn || btn.dataset.scope === scope) return;
    scope = btn.dataset.scope; statusKey = ''; filter = 'all'; scopeNote = '';
    if (filterTabs) filterTabs.querySelectorAll('[data-filter]').forEach(function (b) { b.classList.toggle('active', b.dataset.filter === 'all'); });
    markStatus(); markScope(); page = 1; load();
  };
  // 域名详情里的订单：查看、绑定、解绑。
  function renderOrders(data) {
    var box = byId('domainOrders');
    if (!box) { box = document.createElement('div'); box.id = 'domainOrders'; box.className = 'domain-ns-summary'; byId('domainOverview').appendChild(box); }
    var list = data.orders || [];
    box.innerHTML = '<h3>关联订单</h3>' + (list.length ? list.map(function (o) {
      return '<p><a href="index.php?search=' + encodeURIComponent(o.order_no) + '" target="_blank">' + esc(o.order_no) + '</a> · ' + esc(o.customer_name || '客户') + (o.can_manage && (o.source === 'manual' || o.source === 'registration') ? ' <button class="domain-btn ghost" type="button" data-unbind="' + esc(o.order_no) + '">解除绑定</button>' : '') + '</p>';
    }).join('') : '<p class="domain-help">还没有关联订单。绑定后，这个域名会出现在该订单参与人的「我的订单域名」里。</p>')
      + '<div class="domain-bind-row"><input id="domainBindOrder" maxlength="100" placeholder="输入订单编号" autocomplete="off"><button class="domain-btn" type="button" id="domainBindBtn">绑定到订单</button></div>';
    var requested = domain;
    byId('domainBindBtn').onclick = function () { var no = byId('domainBindOrder').value.trim(); if (!no) { message('请输入订单编号', true, true); return; } busy(this, async function () { var d = await api('order_bind', { domain: requested, order_no: no }); if (requested !== domain) return; message(d.message, true, false); renderOrders({ orders: d.orders }); }); };
    box.querySelectorAll('[data-unbind]').forEach(function (b) { b.onclick = function () { if (!window.confirm('解除 ' + requested + ' 与订单 ' + b.dataset.unbind + ' 的绑定？')) return; busy(b, async function () { var d = await api('order_unbind', { domain: requested, order_no: b.dataset.unbind }); if (requested !== domain) return; message(d.message, true, false); renderOrders({ orders: d.orders }); }); }; });
  }

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
  byId('domainRows').onclick = function (e) { var button = e.target.closest('[data-domain-index]'); if (button) { var row = rows[Number(button.dataset.domainIndex)]; openDetail(row.doaminnamecode || row.domainname); if (button.dataset.openRecords) tab('records'); } };
  byId('domainClose').onclick = function () { byId('domainDrawer').close(); detailSequence++; };
  byId('domainDrawer').addEventListener('close', function () { detailSequence++; });
  root.querySelectorAll('[data-tab]').forEach(function (b) { b.onclick = function () { tab(b.dataset.tab); }; });
  byId('domainNsForm').onsubmit = function (e) { e.preventDefault(); var requested = domain, current = detailSequence; if (!window.confirm('确认将 ' + requested + ' 的 NS 修改为：\n' + byId('domainNsValue').value + '\n\n请确保新 NS 上已有完整解析。')) return; busy(e.submitter || this.querySelector('button'), async function () { var data = await api('ns', { domain: requested, confirm: requested, ns: byId('domainNsValue').value }); if (current !== detailSequence) return; detailSequence++; recordProvider = ''; recordsSequence++; byId('domainRecordForm').hidden = true; if (data.nameservers) { byId('domainNsValue').value = data.nameservers.join('\n'); if (byId('domainCurrentNs')) byId('domainCurrentNs').innerHTML = data.nameservers.map(esc).join('<br>'); } message(data.message || 'NS 服务器已修改成功，全球生效需等待 DNS 缓存更新', true); }); };
  byId('domainAddRecord').onclick = function () { recordForm(null); };
  byId('recordCancel').onclick = function () { byId('domainRecordForm').hidden = true; };
  byId('domainRecordForm').onsubmit = function (e) { e.preventDefault(); if (!recordProvider) return; var requested = domain, old = editRecord, current = detailSequence; if (!window.confirm('确认' + (old ? '修改' : '添加') + ' ' + requested + ' 在 ' + recordLabel + ' 的解析记录？')) return; busy(e.submitter || this.querySelector('button'), async function () { var data = await api(old ? 'record_edit' : 'record_add', { domain: requested, confirm: requested, provider: recordProvider, record_id: old ? (old.id || '') : '', record_revision: old ? (old.revision || '') : '', ttl: byId('recordTtl').value, proxied: byId('recordProxyMode').value, type: byId('recordType').value, host: byId('recordHost').value, value: byId('recordValue').value, old_value: old ? old.value : '', old_type: old ? old.type : '', old_host: old ? old.host : '', old_priority: old ? (old.mxlevel || 10) : '', priority: byId('recordPriority').value }); if (current !== detailSequence) return; byId('domainRecordForm').hidden = true; var saved = data.message || '解析记录已保存成功'; message(saved, true); await records(saved); }); };

  load();
})();
