// 开通弹窗回归：勾选“确认真实扣费”不能清掉报价；未勾选时提示要显示在弹窗里。node tests/servers_buy_ui.js
const assert = require('assert'), fs = require('fs'), vm = require('vm'), path = require('path');
const source = fs.readFileSync(path.join(__dirname, '../assets/js/servers.js'), 'utf8');
function node(id) {
  return { id, dataset: {}, value: '', checked: false, disabled: false, hidden: false, open: false, textContent: '', innerHTML: '', classList: { toggle() {}, add() {}, remove() {} },
    querySelectorAll() { return []; }, scrollIntoView() {}, showModal() { this.open = true; }, close() { this.open = false; } };
}
(async () => {
  const nodes = new Map(), get = id => { if (!nodes.has(id)) nodes.set(id, node(id)); return nodes.get(id); };
  get('svRoot').dataset.csrf = 'fixture';
  get('svBoot').textContent = JSON.stringify({ products: [{ id: 1224, k: 'vps', t: '新加坡BGP云主机 2核 4GB B型', c: 'sg', zid: 1, p: 21.6, s: [], z: '', g: '', cy: { monthly: 21.6 } }], cycles: { monthly: '月付' }, countries: { sg: { sort: 1, flag: 'S', name: '新加坡' } }, categories: { vps: { sort: 1, name: '云服务器' } }, updated: 0, provisioners: [], employees: [] });
  const calls = [];
  const hostListData = [
    { id: 24299, name: 'ser523416892979', product: '香港CN2+BGP 4核 8GB D型', status: 'Active', status_text: '已激活', ip: '38.76.208.243', due: '2026-12-20 14:30', days_left: 27, amount: '58.50', cycle: '月付', notes: '', remark: '', auto_renew: false },
    { id: 24266, name: 'ser098543859948', product: '美国精品网 8核 16G 1G带宽 F型', status: 'Active', status_text: '已激活', ip: '64.83.19.140', due: '2026-12-19 01:09', days_left: 26, amount: '108.00', cycle: '月付', notes: 'ai 智能客服', remark: 'ai 智能客服', auto_renew: false },
  ];
  const fetchStub = async (url, args) => {
    const b = new URLSearchParams(args.body.toString()), a = b.get('action'); calls.push(a);
    const data = a === 'options' ? { cycles: { monthly: 21.6 }, options: [], stock_control: 0 }
      : a === 'quote' ? { token: 't', amount: 21.6, cycle_label: '月付', name: 'x', details: ['CPU：2核'] }
      : a === 'hosts' ? { rows: hostListData.map(x => Object.assign({}, x)), total: hostListData.length }
      : a === 'host_detail' ? { host: Object.assign({ assigned_ips: [], os_groups: [] }, hostListData.find(x => x.id === Number(b.get('id')))), can_manage: true }
      : a === 'host_remark' ? { id: Number(b.get('id')), remark: b.get('remark'), message: b.get('remark') ? '备注已保存' : '备注已清空' }
      : { order: { status: 'done', message: 'ok', host_id: 9 } };
    return { ok: true, redirected: false, json: async () => ({ ok: true, data }) };
  };
  let confirmed = 0;
  vm.runInNewContext(source, { document: { getElementById: get }, URLSearchParams, AbortController, setTimeout, clearTimeout, Promise, JSON, Number, String, Object, Array, Math, Date, fetch: fetchStub, window: { confirm() { confirmed++; return true; } } });
  const tick = () => new Promise(r => setTimeout(r, 5));
  // 打开开通弹窗 → 查询报价
  get('svGrid').onclick({ target: { closest: s => s === '[data-buy]' ? { dataset: { buy: '1224' } } : null } }); await tick();
  const form = get('svBuyForm'); assert(form.onsubmit, 'buy form rendered');
  form.querySelectorAll = () => []; form.onsubmit({ preventDefault() {} }); await tick(); await tick();
  assert(get('svQuoteBox').innerHTML.includes('svAck'), 'quote shows ack checkbox');
  // 勾选确认框触发 form 的 change：报价区不能被清空
  form.onchange({ target: { matches: () => false } });
  assert(get('svQuoteBox').innerHTML.includes('svAck'), 'ticking ack does not wipe the quote');
  // 改套餐选项才应清空
  form.onchange({ target: { matches: () => true } });
  assert(get('svQuoteBox').innerHTML === '', 'changing a config option clears the quote');
  // 未勾选直接提交：提示出现在弹窗里，不发 submit
  form.onsubmit({ preventDefault() {} }); await tick(); await tick();
  get('svAck').checked = false; get('svSubmitBtn').onclick.call({ disabled: false });
  assert(get('svBuyMsg').textContent.includes('请先勾选') && !get('svBuyMsg').hidden, 'unticked submit shows the hint inside the dialog');
  assert(!calls.includes('submit'), 'no purchase without ack');
  // 勾选 + 填编号后提交
  get('svAck').checked = true; get('svConfirm').value = '1224'; get('svSubmitBtn').onclick.call({ disabled: false, textContent: '', innerHTML: '' }); await tick(); await tick();
  assert(calls.includes('submit') && confirmed === 1, 'ticked submit goes through once');
  // 我的服务器：备注展示、按备注搜索、行内修改备注后立刻可搜
  get('svBuy').close();
  get('svTabs').querySelectorAll = () => [];
  get('svTabs').onclick({ target: { closest: s => s === '[data-tab]' ? { dataset: { tab: 'hosts' } } : null } }); await tick(); await tick();
  assert(get('svHostList').innerHTML.includes('ai 智能客服') && get('svHostList').innerHTML.includes('ser098543859948'), 'host card displays remark and hostname');
  // 按备注搜索（含多词与大小写）
  get('svHostQuery').value = 'AI 客服'; get('svHostQuery').oninput();
  assert(get('svHostList').innerHTML.includes('24266') && !get('svHostList').innerHTML.includes('24299'), 'search by remark filters hosts');
  get('svHostQuery').value = ''; get('svHostQuery').oninput();
  assert(get('svHostList').innerHTML.includes('24299') && get('svHostList').innerHTML.includes('24266'), 'clearing search restores all live hosts');
  // 卡片行内修改备注
  const slot = node('slot24299'); slot.hidden = true;
  const inp = node('inp24299'); inp.focus = () => {}; inp.select = () => {};
  const remarkForm = node('form24299'); const saveBtn = node('save24299'); const cancelBtn = node('cancel24299');
  remarkForm.querySelector = s => s === 'button[type=submit]' ? saveBtn : null;
  slot.querySelector = s => s === 'input' ? inp : (s === 'form' ? remarkForm : (s === '[data-remark-cancel]' ? cancelBtn : null));
  get('svHostList').querySelector = s => s === '[data-remark-slot="24299"]' ? slot : null;
  get('svHostList').onclick({ target: { closest: s => s === '[data-remark-btn]' ? { dataset: { remarkBtn: '24299' } } : null } });
  assert(!slot.hidden && remarkForm.onsubmit, 'clicking remark button opens inline form');
  inp.value = '香港官网主站';
  remarkForm.onsubmit({ preventDefault() {} }); await tick(); await tick();
  assert(calls.includes('host_remark') && get('svHostList').innerHTML.includes('香港官网主站'), 'saving remark updates card list');
  get('svHostQuery').value = '香港官网'; get('svHostQuery').oninput();
  assert(get('svHostList').innerHTML.includes('24299') && !get('svHostList').innerHTML.includes('24266'), 'newly saved remark is immediately searchable');
  console.log(JSON.stringify({ passed: 13 }));
})().catch(e => { console.error(e); process.exit(1); });
