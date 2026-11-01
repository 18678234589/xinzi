(function () {
  'use strict';
  var root = document.getElementById('svRoot'); if (!root) return;
  var boot = JSON.parse(document.getElementById('svBoot').textContent), csrf = root.dataset.csrf;
  var $ = function (id) { return document.getElementById(id); };
  var esc = function (v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var state = { country: '', category: '', q: '', onlyStock: false, hosts: null, hostFilter: 'all', hostQ: '' };

  function notice(text, error) { var n = $('svNotice'); n.textContent = text || ''; n.hidden = !text; n.classList.toggle('error', !!error); if (text) n.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
  async function api(action, values) {
    var body = new URLSearchParams({ action: action, csrf: csrf });
    Object.keys(values || {}).forEach(function (k) { var v = values[k]; if (Array.isArray(v)) v.forEach(function (x) { body.append(k + '[]', x); }); else body.append(k, v); });
    var ctl = new AbortController(), timer = setTimeout(function () { ctl.abort(); }, 90000);
    try {
      var r = await fetch('servers.php', { method: 'POST', credentials: 'same-origin', body: body, signal: ctl.signal });
      if (r.redirected) throw new Error('登录已过期，请刷新后重新登录');
      var d; try { d = await r.json(); } catch (_) { throw new Error('未收到有效结果，请稍后重试'); }
      if (!r.ok || !d.ok) throw new Error(d.error || '操作未完成'); return d.data;
    } catch (e) { if (e.name === 'AbortError') throw new Error('响应较慢，请稍后刷新核对结果，不要重复提交'); throw e; }
    finally { clearTimeout(timer); }
  }
  async function busy(btn, fn) { if (btn.disabled) return; var old = btn.innerHTML; btn.disabled = true; btn.textContent = '请稍候…'; try { await fn(); } catch (e) { notice(e.message, true); } finally { btn.disabled = false; btn.innerHTML = old; } }
  var money = function (v) { return '¥' + Number(v).toFixed(2); };

  /* ---------------- 目录 ---------------- */
  function visible(p, ignoreCountry, ignoreCategory) {
    if (state.q) { var h = (p.t + ' ' + p.z + ' ' + p.g + ' ' + p.s.map(function (s) { return s[1]; }).join(' ')).toLowerCase(); if (h.indexOf(state.q.toLowerCase()) < 0) return false; }
    if (state.onlyStock && p.sc && p.st !== null && p.st <= 0) return false;
    if (!ignoreCountry && state.country && p.c !== state.country) return false;
    if (!ignoreCategory && state.category && p.k !== state.category) return false;
    return true;
  }
  function stockBadge(p) {
    if (p.err) return '<span class="sv-stock out" title="' + esc(p.err) + '">暂不可售</span>';
    if (p.st === null || p.st === undefined) return '';
    if (p.sc && p.st <= 0) return '<span class="sv-stock out">缺货</span>';
    if (p.sc && p.st <= 5) return '<span class="sv-stock low">仅剩 ' + p.st + '</span>';
    return p.sc ? '<span class="sv-stock ok">有货</span>' : '';
  }
  function card(p) {
    var specs = p.s.filter(function (s) { return s[1]; }).slice(0, 6).map(function (s) { return '<span class="sv-spec">' + (s[0] ? '<b>' + esc(s[0]) + '</b>' : '') + esc(String(s[1]).replace(/\s*\n\s*/g, ' · ')) + '</span>'; }).join('');
    var price = p.p !== null ? '<div class="sv-price">' + money(p.p) + '<small>/' + esc((p.pl || '').replace('付', '') || '月') + ' 起</small></div>' : '<div class="sv-price none">价格同步中</div>';
    var out = !p.err && p.sc && p.st !== null && p.st <= 0;
    var btn = boot.canProvision ? '<button type="button" class="sv-btn sm" data-buy="' + p.id + '"' + (out || p.err ? ' disabled' : '') + '>开通</button>' : '<button type="button" class="sv-btn sm ghost" data-info="' + p.id + '">配置详情</button>';
    return '<article class="sv-card">' + stockBadge(p) + '<h4>' + esc(p.t) + '</h4><div class="sv-specs">' + specs + '</div><div class="sv-foot">' + price + btn + '</div></article>';
  }
  function renderCatalog() {
    var all = boot.products, shown = all.filter(function (p) { return visible(p); });
    // 国家条：显示按其他筛选后的数量
    var cc = {}; all.forEach(function (p) { if (visible(p, true, false)) cc[p.c] = (cc[p.c] || 0) + 1; });
    var total = Object.keys(cc).reduce(function (a, k) { return a + cc[k]; }, 0);
    var rail = '<button type="button" class="sv-chip' + (!state.country ? ' active' : '') + '" data-country=""><span class="f">🌍</span>全部<small>' + total + '</small></button>';
    Object.keys(boot.countries).sort(function (a, b) { return boot.countries[a].sort - boot.countries[b].sort; }).forEach(function (k) { if (!cc[k]) return; rail += '<button type="button" class="sv-chip' + (state.country === k ? ' active' : '') + '" data-country="' + k + '"><span class="f">' + boot.countries[k].flag + '</span>' + esc(boot.countries[k].name) + '<small>' + cc[k] + '</small></button>'; });
    $('svCountries').innerHTML = rail;
    var kc = {}; all.forEach(function (p) { if (visible(p, false, true)) kc[p.k] = (kc[p.k] || 0) + 1; });
    var pills = '<button type="button" class="sv-pill' + (!state.category ? ' active' : '') + '" data-category="">全部品类</button>';
    Object.keys(boot.categories).sort(function (a, b) { return boot.categories[a].sort - boot.categories[b].sort; }).forEach(function (k) { if (!kc[k]) return; pills += '<button type="button" class="sv-pill' + (state.category === k ? ' active' : '') + '" data-category="' + k + '">' + esc(boot.categories[k].name) + ' ' + kc[k] + '</button>'; });
    $('svCategories').innerHTML = pills;
    // 国家 → 品类 → 分区
    var tree = {}; shown.forEach(function (p) { ((tree[p.c] = tree[p.c] || {})[p.k] = tree[p.c][p.k] || {})[p.zid] = (tree[p.c][p.k][p.zid] || { z: p.z, items: [] }); tree[p.c][p.k][p.zid].items.push(p); });
    var html = '';
    Object.keys(tree).sort(function (a, b) { return (boot.countries[a] || { sort: 99 }).sort - (boot.countries[b] || { sort: 99 }).sort; }).forEach(function (c) {
      var info = boot.countries[c] || { name: c, flag: '📦' };
      html += '<h3 class="sv-country-title"><span class="f">' + info.flag + '</span>' + esc(info.name) + '</h3>';
      Object.keys(tree[c]).sort(function (a, b) { return boot.categories[a].sort - boot.categories[b].sort; }).forEach(function (k) {
        html += '<div class="sv-cat-title">' + esc(boot.categories[k].name) + '</div>';
        Object.keys(tree[c][k]).forEach(function (z) { var zone = tree[c][k][z]; html += '<div class="sv-zone-title">' + esc(zone.z) + ' · ' + zone.items.length + ' 个套餐</div><div class="sv-cards">' + zone.items.map(card).join('') + '</div>'; });
      });
    });
    $('svGrid').innerHTML = html || '<p class="sv-empty">没有符合条件的套餐，换个关键词或取消筛选试试。</p>';
  }
  function renderStats() {
    var p = boot.products, countries = {}, zones = {}, priced = 0;
    p.forEach(function (x) { countries[x.c] = 1; zones[x.zid] = 1; if (x.p !== null) priced++; });
    $('svStats').innerHTML = [[p.length, '个套餐'], [Object.keys(countries).length, '个国家 / 地区'], [Object.keys(zones).length, '个机房分区'], [priced, '个已同步价格']].map(function (s) { return '<div class="sv-stat"><b>' + s[0] + '</b><span>' + s[1] + '</span></div>'; }).join('');
    $('svUpdated').textContent = boot.updated ? '价格 / 库存更新于 ' + new Date(boot.updated * 1000).toLocaleString('zh-CN', { hour12: false }) + '（后台每 6 小时同步）' : '价格还没有同步，稍后刷新';
  }
  $('svCountries').onclick = function (e) { var b = e.target.closest('[data-country]'); if (!b) return; state.country = b.dataset.country; renderCatalog(); };
  $('svCategories').onclick = function (e) { var b = e.target.closest('[data-category]'); if (!b) return; state.category = b.dataset.category; renderCatalog(); };
  var qTimer; $('svQuery').oninput = function () { clearTimeout(qTimer); var v = this.value.trim(); qTimer = setTimeout(function () { state.q = v; renderCatalog(); }, 160); };
  $('svOnlyStock').onchange = function () { state.onlyStock = this.checked; renderCatalog(); };
  $('svGrid').onclick = function (e) {
    var b = e.target.closest('[data-buy]'); if (b) return openBuy(Number(b.dataset.buy));
    b = e.target.closest('[data-info]'); if (b) return openInfo(Number(b.dataset.info));
  };
  function product(id) { return boot.products.filter(function (p) { return p.id === id; })[0]; }
  function openInfo(id) {
    var p = product(id); if (!p) return;
    var cycles = Object.keys(p.cy || {}).map(function (k) { return '<dt>' + esc(boot.cycles[k] || k) + '</dt><dd>' + money(p.cy[k]) + '</dd>'; }).join('');
    $('svBuyTitle').textContent = p.t;
    $('svBuyBody').innerHTML = '<div class="sv-sec"><h3>配置</h3><div class="sv-specs">' + p.s.filter(function (s) { return s[1]; }).map(function (s) { return '<span class="sv-spec"><b>' + esc(s[0]) + '</b>' + esc(String(s[1]).replace(/\s*\n\s*/g, ' · ')) + '</span>'; }).join('') + '</div></div>' + (cycles ? '<div class="sv-sec"><h3>价格（雅云账号价）</h3><dl class="sv-kv">' + cycles + '</dl></div>' : '') + '<p class="sv-help">开通服务器会从雅云账号余额扣费，需要「可开通名单」里的同事操作。请把套餐名称发给他们：<b>' + esc(p.t) + '</b>（编号 ' + p.id + '）。</p>';
    $('svBuy').showModal();
  }

  /* ---------------- 开通 ---------------- */
  var buy = null;
  async function openBuy(pid) {
    var p = product(pid); if (!p) return;
    buy = { pid: pid, info: null, quote: null };
    $('svBuyTitle').textContent = '开通：' + p.t; $('svBuyBody').innerHTML = '<p class="sv-help">正在读取配置…</p>'; $('svBuy').showModal();
    try { buy.info = await api('options', { pid: pid }); renderBuy(); } catch (e) { $('svBuyBody').innerHTML = '<div class="sv-notice error">' + esc(e.message) + '</div>'; }
  }
  function renderBuy() {
    var i = buy.info, cyc = Object.keys(i.cycles);
    var opts = i.options.filter(function (o) { return o.kind === 'select' || o.kind === 'range'; }).map(function (o) {
      if (o.kind === 'select') return '<label>' + esc(o.label) + '<select data-opt="' + o.id + '">' + o.subs.map(function (s) { return '<option value="' + s.id + '"' + (s.id === o.default ? ' selected' : '') + '>' + esc(s.label) + '</option>'; }).join('') + '</select></label>';
      return '<label>' + esc(o.label) + '（' + o.min + '–' + o.max + esc(o.unit) + '）<input type="number" data-opt="' + o.id + '" min="' + o.min + '" max="' + o.max + '" value="' + o.default + '"></label>';
    }).join('');
    var fixed = i.options.filter(function (o) { return o.kind === 'fixed' || o.kind === 'fixed_sub'; }).map(function (o) { return '<span class="sv-spec"><b>' + esc(o.label) + '</b>' + esc(o.kind === 'fixed' ? o.min + (o.unit || '') : o.text) + '</span>'; }).join('');
    $('svBuyBody').innerHTML = '<form class="sv-form" id="svBuyForm"><div class="sv-specs">' + fixed + '</div>' +
      '<label>付款周期<select id="svCycle">' + cyc.map(function (c) { return '<option value="' + c + '">' + esc(boot.cycles[c] || c) + ' · ' + money(i.cycles[c]) + '</option>'; }).join('') + '</select></label>' + opts +
      (i.stock_control ? '<p class="sv-help">当前库存：' + i.stock + '</p>' : '') + '<button type="submit" class="sv-btn" id="svQuoteBtn">查询报价（不扣费）</button><div id="svQuoteBox"></div></form>';
    $('svBuyForm').onsubmit = function (e) { e.preventDefault(); busy($('svQuoteBtn'), quote); };
    $('svBuyForm').onchange = function () { buy.quote = null; $('svQuoteBox').innerHTML = ''; };
  }
  async function quote() {
    var sel = {}; $('svBuyForm').querySelectorAll('[data-opt]').forEach(function (el) { sel[el.dataset.opt] = el.value; });
    var q = await api('quote', { pid: buy.pid, cycle: $('svCycle').value, selection: JSON.stringify(sel) }); buy.quote = q;
    $('svQuoteBox').innerHTML = '<div class="sv-total">合计 <b>' + money(q.amount) + '</b>（' + esc(q.cycle_label) + '），从雅云账号余额扣除</div><ul class="sv-help">' + q.details.map(function (d) { return '<li>' + esc(d) + '</li>'; }).join('') + '</ul>' +
      '<label class="chk"><input type="checkbox" id="svAck">我已核对套餐、周期和金额，确认真实扣费开通</label><label>输入商品编号 <b>' + buy.pid + '</b> 确认<input id="svConfirm" autocomplete="off" inputmode="numeric" placeholder="' + buy.pid + '"></label><button type="button" class="sv-btn" id="svSubmitBtn">确认开通并扣费</button><p class="sv-help">报价 10 分钟内有效；提交后不会自动重试，结果不确定时请先到「我的服务器」或雅云后台核对，不要重复开通。</p>';
    $('svSubmitBtn').onclick = function () { submit(this); };
  }
  function submit(btn) {
    var q = buy.quote; if (!q || !$('svAck').checked) { notice('请先勾选确认', true); return; }
    if (!window.confirm('确认开通「' + q.name + '」（' + q.cycle_label + '）？\n将从雅云余额真实扣费 ' + money(q.amount) + '。')) return;
    busy(btn, async function () {
      var r = await api('submit', { token: q.token, confirm: $('svConfirm').value.trim(), ack: '1', pid: buy.pid }); buy.quote = null; var o = r.order;
      var cls = o.status === 'done' ? '' : (o.status === 'failed' ? ' error' : ' warn');
      $('svBuyBody').innerHTML = '<div class="sv-notice' + cls + '"><b>' + ({ done: '开通成功', failed: '开通失败', unknown: '结果待核对', started: '处理中' }[o.status] || o.status) + '</b>：' + esc(o.message) + (o.host_id ? '（主机编号 ' + o.host_id + '）' : '') + '</div><p class="sv-help">可以到「我的服务器」查看新主机；开通可能需要几分钟，状态会从“开通中”变为“运行中”。</p>';
      if (o.status === 'done') { state.hosts = null; }
    });
  }

  /* ---------------- 我的服务器 ---------------- */
  function dueClass(h) { return h.days_left === null ? '' : (h.days_left < 0 ? 'bad' : (h.days_left < 7 ? 'bad' : (h.days_left < 15 ? 'warn' : ''))); }
  function statusBadge(h) { var c = h.status === 'Active' ? 'ok' : (h.status === 'Pending' ? 'warn' : 'bad'); return '<span class="sv-badge ' + c + '">' + esc(h.status_text || h.status) + '</span>'; }
  function renderHosts() {
    var list = (state.hosts || []).filter(function (h) {
      if (state.hostFilter === 'Active' && h.status !== 'Active') return false;
      if (state.hostFilter === 'soon' && !(h.days_left !== null && h.days_left < 15 && h.status === 'Active')) return false;
      if (state.hostFilter === 'other' && h.status === 'Active') return false;
      if (state.hostQ) { var t = (h.name + ' ' + h.ip + ' ' + h.product + ' ' + h.id).toLowerCase(); if (t.indexOf(state.hostQ.toLowerCase()) < 0) return false; }
      return true;
    });
    $('svHostList').innerHTML = list.length ? '<div class="sv-hosts">' + list.map(function (h) {
      return '<article class="sv-host"><div class="sv-row"><h4>' + esc(h.name || ('主机 ' + h.id)) + '</h4>' + statusBadge(h) + '</div><div class="sv-row"><span>' + esc(h.product) + '</span></div><div class="sv-row"><span class="ip">' + esc(h.ip || '—') + '</span><span>#' + h.id + '</span></div>' +
        '<div class="sv-row"><span>到期</span><b class="sv-due ' + dueClass(h) + '">' + esc(h.due || '—') + (h.days_left !== null ? '（' + (h.days_left < 0 ? '已过期' : h.days_left + ' 天') + '）' : '') + '</b></div><div class="sv-row"><span>续费</span><b>' + money(h.amount) + ' / ' + esc(h.cycle) + '</b></div><div class="sv-actions"><button type="button" class="sv-btn sm" data-host="' + h.id + '">管理</button></div></article>';
    }).join('') + '</div>' : '<p class="sv-empty">没有符合条件的服务器。</p>';
    $('svHostCount').textContent = state.hosts ? state.hosts.length : '';
  }
  async function loadHosts(force) {
    if (state.hosts && !force) return renderHosts();
    $('svHostList').innerHTML = '<p class="sv-empty">正在读取雅云账号下的主机…</p>';
    try { var d = await api('hosts'); state.hosts = d.rows; renderHosts(); } catch (e) { $('svHostList').innerHTML = '<div class="sv-notice error">' + esc(e.message) + '</div>'; }
  }
  $('svHostRefresh').onclick = function () { busy(this, function () { return loadHosts(true); }); };
  $('svHostFilter').onclick = function (e) { var b = e.target.closest('[data-f]'); if (!b) return; state.hostFilter = b.dataset.f; this.querySelectorAll('button').forEach(function (x) { x.classList.toggle('active', x === b); }); renderHosts(); };
  $('svHostQuery').oninput = function () { state.hostQ = this.value.trim(); renderHosts(); };
  $('svHostList').onclick = function (e) { var b = e.target.closest('[data-host]'); if (b) openHost(Number(b.dataset.host)); };

  /* ---------------- 主机详情 / 管理 ---------------- */
  var cur = null;
  async function openHost(id) {
    cur = { id: id }; $('svDrawerTitle').textContent = '主机 #' + id; $('svDrawerBody').innerHTML = '<p class="sv-help">正在读取详情…</p>'; $('svDrawer').showModal();
    try { var d = await api('host_detail', { id: id }); cur.host = d.host; cur.manage = d.can_manage; renderHost(); } catch (e) { $('svDrawerBody').innerHTML = '<div class="sv-notice error">' + esc(e.message) + '</div>'; }
  }
  function renderHost() {
    var h = cur.host, m = cur.manage;
    $('svDrawerTitle').textContent = h.name || ('主机 #' + h.id);
    var power = '<button class="sv-btn sm" data-do="on">开机</button><button class="sv-btn sm ghost" data-do="reboot">重启</button><button class="sv-btn sm ghost" data-do="off">关机</button><button class="sv-btn sm ghost" data-do="status">查看状态</button><button class="sv-btn sm ghost" data-do="vnc">VNC 控制台</button>' +
      (m ? '<button class="sv-btn sm danger" data-do="hard_reboot">强制重启</button><button class="sv-btn sm danger" data-do="hard_off">强制断电</button>' : '');
    var login = m ? '<div class="sv-sec"><h3>登录信息</h3><dl class="sv-kv"><dt>账号</dt><dd>' + esc(h.username || '—') + '</dd><dt>端口</dt><dd>' + esc(h.port || '上游未提供') + '</dd><dt>密码</dt><dd><span class="sv-pw" id="svPw">••••••••</span> <button type="button" class="sv-btn sm ghost" id="svShowPw">显示</button></dd></dl></div>' : '<p class="sv-help">登录账号和密码仅「可开通名单」里的同事可见。</p>';
    var manage = m ? '<div class="sv-sec"><h3>高级管理</h3><div class="sv-actions"><button class="sv-btn sm" data-panel="renew">续费</button><button class="sv-btn sm ghost" data-panel="reinstall">重装系统</button><button class="sv-btn sm ghost" data-panel="password">重置密码</button><button class="sv-btn sm ghost" data-panel="rescue">救援系统</button><button class="sv-btn sm danger" data-panel="cancel">申请停用</button></div><div id="svPanel"></div></div>' : '';
    $('svDrawerBody').innerHTML = '<div class="sv-sec"><h3>概览</h3><dl class="sv-kv"><dt>状态</dt><dd>' + statusBadge(h) + '</dd><dt>套餐</dt><dd>' + esc(h.product) + '</dd><dt>主 IP</dt><dd>' + esc(h.ip || '—') + '</dd><dt>附加 IP</dt><dd>' + esc((h.assigned_ips || []).join(' / ') || '—') + '</dd><dt>系统</dt><dd>' + esc(h.os || '—') + '</dd><dt>开通时间</dt><dd>' + esc(h.active || '—') + '</dd><dt>到期时间</dt><dd class="sv-due ' + dueClass(h) + '">' + esc(h.due || '—') + (h.days_left !== null ? '（' + (h.days_left < 0 ? '已过期' : h.days_left + ' 天') + '）' : '') + '</dd><dt>续费价</dt><dd>' + money(h.amount) + ' / ' + esc(h.cycle) + '</dd><dt>自动续费</dt><dd>' + (h.auto_renew ? '已开启' : '未开启') + '</dd></dl></div>' +
      '<div class="sv-sec"><h3>电源与控制台</h3><div class="sv-actions" id="svPower">' + power + '</div></div>' + login +
      '<div class="sv-sec"><h3>监控（近 24 小时）</h3><div class="sv-actions"><button class="sv-btn sm ghost" data-chart="cpu">CPU</button><button class="sv-btn sm ghost" data-chart="memory">内存</button><button class="sv-btn sm ghost" data-chart="flow">流量</button><button class="sv-btn sm ghost" data-chart="disk">磁盘</button></div><div id="svChart"></div></div>' + manage;
    if ($('svShowPw')) $('svShowPw').onclick = function () { var el = $('svPw'); if (this.dataset.on) { el.textContent = '••••••••'; this.textContent = '显示'; delete this.dataset.on; } else { el.textContent = h.password || '上游未提供'; this.textContent = '隐藏'; this.dataset.on = '1'; } };
  }
  function spark(list, unit) {
    var v = list.map(function (x) { return Number(x.value != null ? x.value : (x.y != null ? x.y : (Array.isArray(x) ? x[1] : 0))) || 0; }); if (v.length < 2) return '<p class="sv-help">暂无监控数据</p>';
    var max = Math.max.apply(null, v) || 1, w = 520, hgt = 90, pts = v.map(function (n, i) { return (i / (v.length - 1) * w).toFixed(1) + ',' + (hgt - 6 - n / max * (hgt - 14)).toFixed(1); }).join(' ');
    return '<svg class="sv-spark" viewBox="0 0 ' + w + ' ' + hgt + '" preserveAspectRatio="none"><polyline fill="none" stroke="#4fd1b5" stroke-width="2" points="' + pts + '"/></svg><p class="sv-help">峰值 ' + max.toFixed(2) + ' ' + esc(unit || '') + '</p>';
  }
  $('svDrawerBody').onclick = function (e) {
    var b = e.target.closest('[data-do]');
    if (b) {
      var act = b.dataset.do, dangerous = { off: '关机', hard_off: '强制断电', hard_reboot: '强制重启', reboot: '重启' };
      if (dangerous[act] && !window.confirm('确认对 ' + (cur.host.name || cur.id) + ' 执行「' + dangerous[act] + '」？业务会中断。')) return;
      return busy(b, async function () { var r = await api('host_action', { id: cur.id, do: act }); if (act === 'vnc') { window.open(r.url, '_blank', 'noopener'); notice('已打开 VNC 控制台', false); } else if (act === 'status') notice('当前电源状态：' + (r.desc || r.status || '未知'), false); else notice(r.message || '已提交', false); });
    }
    b = e.target.closest('[data-chart]'); if (b) return busy(b, async function () { var r = await api('host_chart', { id: cur.id, type: b.dataset.chart }); $('svChart').innerHTML = spark(r.list, r.unit); });
    b = e.target.closest('[data-panel]'); if (b) return panel(b.dataset.panel);
  };
  async function panel(kind) {
    var box = $('svPanel'), h = cur.host, idc = '<label>输入主机编号 <b>' + h.id + '</b> 确认<input id="svPConfirm" autocomplete="off" inputmode="numeric"></label>';
    if (kind === 'renew') {
      box.innerHTML = '<p class="sv-help">正在读取续费报价…</p>';
      try { var d = await api('renew_info', { id: h.id });
        box.innerHTML = '<form class="sv-form" id="svPForm"><label>续费周期<select id="svPCycle">' + d.cycles.map(function (c) { return '<option value="' + esc(c.cycle) + '">' + esc(c.duration || c.cycle) + ' · ' + money(c.amount) + '</option>'; }).join('') + '</select></label>' + idc + '<button class="sv-btn" type="submit">确认续费并扣费</button></form>';
        $('svPForm').onsubmit = function (ev) { ev.preventDefault(); var s = $('svPCycle'); if (!window.confirm('确认续费 ' + s.options[s.selectedIndex].text + '？将从雅云余额真实扣费。')) return; busy(this.querySelector('button'), async function () { var r = await api('renew', { id: h.id, cycle: s.value, confirm: $('svPConfirm').value.trim() }); notice(r.message, false); state.hosts = null; await openHost(h.id); }); };
      } catch (e) { box.innerHTML = '<div class="sv-notice error">' + esc(e.message) + '</div>'; }
    } else if (kind === 'reinstall') {
      box.innerHTML = '<form class="sv-form" id="svPForm"><label>系统<select id="svPOs">' + h.os_groups.map(function (g) { return '<optgroup label="' + esc(g.name) + '">' + g.images.map(function (i) { return '<option value="' + i.id + '">' + esc(i.name) + '</option>'; }).join('') + '</optgroup>'; }).join('') + '</select></label><label>SSH / 远程端口（可选）<input id="svPPort" inputmode="numeric" placeholder="留空用默认"></label><label class="chk"><input type="checkbox" id="svPFmt">同时格式化数据盘</label>' + idc + '<p class="sv-help">重装会清空系统盘上的全部数据，且不可恢复。</p><button class="sv-btn danger" type="submit">确认重装</button></form>';
      $('svPForm').onsubmit = function (ev) { ev.preventDefault(); if (!window.confirm('重装会清空系统盘全部数据，确认继续？')) return; busy(this.querySelector('button'), async function () { var r = await api('host_action', { id: h.id, do: 'reinstall', os: $('svPOs').value, port: $('svPPort').value, format_data_disk: $('svPFmt').checked ? 1 : 0, confirm: $('svPConfirm').value.trim() }); notice(r.message || '重装已提交', false); }); };
    } else if (kind === 'password') {
      box.innerHTML = '<form class="sv-form" id="svPForm"><label>新密码（8–30 位，字母数字和常用符号）<input id="svPPw" maxlength="30" autocomplete="new-password"></label><button class="sv-btn" type="submit">重置密码</button></form>';
      $('svPForm').onsubmit = function (ev) { ev.preventDefault(); busy(this.querySelector('button'), async function () { var r = await api('host_action', { id: h.id, do: 'reset_password', password: $('svPPw').value }); notice(r.message || '密码重置已提交', false); }); };
    } else if (kind === 'rescue') {
      box.innerHTML = '<form class="sv-form" id="svPForm"><label>救援系统<select id="svPType"><option value="1">Linux 救援系统</option><option value="2">Windows 救援系统</option></select></label><label>临时密码（8–30 位）<input id="svPTmp" maxlength="30" autocomplete="new-password"></label><div class="sv-actions"><button class="sv-btn" type="submit">进入救援</button><button class="sv-btn ghost" type="button" id="svPExit">退出救援</button></div></form>';
      $('svPForm').onsubmit = function (ev) { ev.preventDefault(); busy(this.querySelector('button'), async function () { var r = await api('host_action', { id: h.id, do: 'rescue', type: $('svPType').value, temp_pass: $('svPTmp').value }); notice(r.message || '已提交', false); }); };
      $('svPExit').onclick = function () { busy(this, async function () { var r = await api('host_action', { id: h.id, do: 'exit_rescue' }); notice(r.message || '已提交', false); }); };
    } else if (kind === 'cancel') {
      box.innerHTML = '<form class="sv-form" id="svPForm"><label>停用方式<select id="svPCType"><option value="Endofbilling">到期后停用（推荐）</option><option value="Immediate">立即停用</option></select></label><label>停用原因<input id="svPReason" maxlength="200"></label>' + idc + '<button class="sv-btn danger" type="submit">提交停用申请</button></form>';
      $('svPForm').onsubmit = function (ev) { ev.preventDefault(); if (!window.confirm('确认提交停用申请？立即停用会马上终止服务。')) return; busy(this.querySelector('button'), async function () { var r = await api('cancel', { id: h.id, type: $('svPCType').value, reason: $('svPReason').value, confirm: $('svPConfirm').value.trim() }); notice(r.message, false); state.hosts = null; }); };
    }
  }

  /* ---------------- 开通记录 / 权限 ---------------- */
  async function loadOrders() {
    if (!$('svOrderList')) return; $('svOrderList').innerHTML = '<p class="sv-empty">读取中…</p>';
    try { var d = await api('orders'); $('svOrderList').innerHTML = d.rows.length ? '<table class="sv-table"><thead><tr><th>时间</th><th>套餐</th><th>周期 / 金额</th><th>状态</th><th>操作人</th></tr></thead><tbody>' + d.rows.map(function (o) { var c = o.status === 'done' ? 'ok' : (o.status === 'failed' ? 'bad' : 'warn'); return '<tr><td>' + esc(o.created) + '</td><td>' + esc(o.name) + '<br><small>' + esc(o.details.join(' / ')) + '</small></td><td>' + esc(o.cycle) + '<br>' + money(o.amount) + '</td><td><span class="sv-badge ' + c + '">' + ({ done: '已开通', failed: '失败', unknown: '待核对', started: '处理中' }[o.status] || o.status) + '</span><br><small>' + esc(o.message) + (o.host_id ? ' · 主机 #' + o.host_id : '') + '</small></td><td>' + esc(o.actor) + '</td></tr>'; }).join('') + '</tbody></table>' : '<p class="sv-empty">还没有开通记录。</p>'; }
    catch (e) { $('svOrderList').innerHTML = '<div class="sv-notice error">' + esc(e.message) + '</div>'; }
  }
  function renderSettings() {
    if (!$('svProvisioners')) return; var set = {}; boot.provisioners.forEach(function (id) { set[id] = 1; });
    $('svProvisioners').innerHTML = boot.employees.map(function (e) { return '<label><input type="checkbox" value="' + e.id + '"' + (set[e.id] ? ' checked' : '') + '><span>' + esc(e.name) + ' <small>' + esc(e.department || '') + '</small></span></label>'; }).join('');
    $('svSaveProvisioners').onclick = function () { busy(this, async function () { var ids = Array.prototype.map.call($('svProvisioners').querySelectorAll('input:checked'), function (i) { return i.value; }); var r = await api('provisioners_save', { employees: ids }); boot.provisioners = r.employees.map(Number); notice('可开通名单已保存（' + r.employees.length + ' 人）', false); }); };
  }

  /* ---------------- 标签页 ---------------- */
  $('svTabs').onclick = function (e) {
    var b = e.target.closest('[data-tab]'); if (!b) return; var tab = b.dataset.tab;
    this.querySelectorAll('button').forEach(function (x) { x.classList.toggle('active', x === b); });
    [['catalog', 'svCatalog'], ['hosts', 'svHosts'], ['orders', 'svOrders'], ['settings', 'svSettings']].forEach(function (p) { var el = $(p[1]); if (el) el.hidden = p[0] !== tab; });
    if (tab === 'hosts') loadHosts(false); if (tab === 'orders') loadOrders();
  };
  $('svDrawerClose').onclick = function () { $('svDrawer').close(); }; $('svBuyClose').onclick = function () { $('svBuy').close(); };
  renderStats(); renderCatalog(); renderSettings();
})();
