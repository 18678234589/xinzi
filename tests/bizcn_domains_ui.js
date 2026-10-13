const assert = require('assert');
const fs = require('fs');
const vm = require('vm');
const source = fs.readFileSync(require('path').join(__dirname, '../assets/js/domains.js'), 'utf8');
function element() { return { dataset: {}, innerHTML: '', textContent: '', value: '', hidden: false, disabled: false, open: false, classList: { toggle() {} }, querySelectorAll() { return []; }, addEventListener() {}, focus() {}, showModal() { this.open = true; }, close() { this.open = false; } }; }
async function run(response, ok = true, redirected = false) {
  const nodes = new Map();
  const get = id => { if (!nodes.has(id)) nodes.set(id, element()); return nodes.get(id); };
  get('domainConsole').dataset.csrf = 'fixture-token';
  let request;
  vm.runInNewContext(source, {
    document: { getElementById: get }, URLSearchParams, AbortController, setTimeout, clearTimeout, URL,
    fetch: async (url, args) => { request = { url, args }; return { ok, redirected, json: async () => response }; },
    window: { confirm() { throw new Error('No mutations expected'); } }
  });
  await new Promise(resolve => setImmediate(resolve));
  return { nodes, request, get };
}
(async () => {
  let checks = 0;
  const test = (value, label) => { assert(value, label); checks++; };
  let page = await run({ ok: true, data: { rows: [{ domainname: '<img src=x onerror=bad()>', status: '<script>bad()</script>', regdate: '2020-01-01', expireddate: '2026-09-01', nameserver: { ns: ['ns1.example.com', 'ns2.example.com'] } }], total: 61, page: 1, updated_at: '2026-10-10' } });
  test(page.get('domainCount').textContent === '61 个', 'provider count');
  test(page.get('domainNext').disabled === false, 'pagination next');
  test(page.get('domainPrev').disabled === true, 'pagination previous');
  test(page.get('domainRows').innerHTML.includes('&lt;img'), 'domain escaped');
  test(!page.get('domainRows').innerHTML.includes('<script>'), 'status escaped');
  test(page.get('domainRows').innerHTML.includes('ns1.example.com<br>ns2.example.com'), 'nested NS values');
  test(page.request.args.credentials === 'same-origin', 'session transport');
  test(page.request.args.body.get('csrf') === 'fixture-token', 'CSRF sent');
  page = await run({ error: '商务中国接口授权未通过' }, false);
  test(page.get('domainNotice').textContent === '商务中国接口授权未通过', 'friendly provider failure');
  test(page.get('domainCount').textContent !== '0 个', 'failure not shown as zero domains');
  test(page.get('domainNext').disabled, 'pagination disabled on failed load');
  test(page.get('domainUpdated').textContent.includes('连接待核验'), 'accurate connection state');
  page = await run({}, true, true);
  test(page.get('domainNotice').textContent.includes('登录已过期'), 'expired session');
  page = await run({ ok: true, data: { rows: [], total: 0, page: 1, updated_at: 'now' } });
  test(page.get('domainRows').innerHTML.includes('没有找到'), 'genuine empty list');
  page = await run({ok:true,data:{source:'export',rows:[{domainname:'xn--b9ww0pz4bdy6d.com',display_name:'xn--b9ww0pz4bdy6d.com(鸿源盛祥.com)',registrar:'<img>',owner_username:'jishu003<script>',regdate:'2016-10-15',expireddate:'2026-10-15',status:'正常'}],total:7114,page:1,updated_at:'2026-10-12'}});
  test(page.get('domainUpdated').textContent.includes('不是实时同步'),'snapshot not claimed live');
  test(page.get('domainRows').innerHTML.includes('鸿源盛祥.com'),'IDN visible');
  test(page.get('domainRows').innerHTML.includes('&lt;img&gt;'),'registrar escaped');
  test(page.get('domainRows').innerHTML.includes('jishu003&lt;script&gt;'),'owner account escaped');
  test(page.get('domainPageInfo').textContent.includes('7114') || page.get('domainPageInfo').textContent.includes('7,114'),'inventory count displayed');
  console.log(JSON.stringify({ passed: checks, failed: 0 }));
})().catch(error => { console.error(error.message); process.exit(1); });
