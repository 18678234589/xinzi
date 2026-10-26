// 我的在线表格（浏览器）：下拉格点一下就展开、新增行、粘贴超出末行自动加行、自动保存带总行数、停手后自动同步、状态列只读。
// NODE_PATH must contain playwright. Uses synthetic data and a loopback HTTP server only.
const { chromium } = require('playwright');
const http = require('http');
const fs = require('fs');
const path = require('path');
const assert = require('assert/strict');
const root = path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'project/files.php'), 'utf8');
const fragment = source.slice(source.indexOf('<div class="se-card"'), source.indexOf('<div id="sheetStatic"')).replace(/<\?php[\s\S]*?\?>/g, 'fixture');
const html = `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/lib/jspreadsheet/jsuites.css"><link rel="stylesheet" href="/assets/lib/jspreadsheet/jspreadsheet.css"><link rel="stylesheet" href="/assets/css/sheet_editor.css"><style>body{margin:12px} .form-control{box-sizing:border-box} button{cursor:pointer} .project-file-view{width:100%}</style></head><body><div class="project-file-view">${fragment}</div><script src="/assets/lib/jspreadsheet/jsuites.js"></script><script src="/assets/lib/jspreadsheet/jspreadsheet.js"></script><script src="/assets/js/sheet_editor.js"></script><script>var root=document.getElementById('seRoot');root.setAttribute('data-api','/api');root.setAttribute('data-import-url','/import');root.setAttribute('data-csrf','test-csrf');root.setAttribute('data-file','1');root.setAttribute('data-sheet','CSV');root.setAttribute('data-can-edit','1');root.setAttribute('data-online','1');window.editor=SheetEditor(root);</script></body></html>`;
const server = http.createServer((req, res) => {
  if (req.url === '/') { res.setHeader('Content-Type', 'text/html; charset=utf-8'); res.end(html); return; }
  const p = path.resolve(root, '.' + req.url.split('?')[0]);
  if (!p.startsWith(root + path.sep) || !fs.existsSync(p)) { res.statusCode = 404; res.end(); return; }
  res.setHeader('Content-Type', p.endsWith('.css') ? 'text/css' : 'text/javascript'); res.end(fs.readFileSync(p));
});
const HEAD = ['日期', '订单编号', '售价', '年限', '状态(填已完成/未完成)', '备注'];
(async () => {
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const browser = await chromium.launch({ headless: true, executablePath: process.env.SHEET_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe' });
  try {
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await context.newPage(), errors = [], saves = [], syncs = [], stored = new Map();
    let capacity = 5;
    page.on('pageerror', e => errors.push(e.message));
    await page.route('**/api**', async route => {
      const input = route.request().method() === 'GET' ? { action: 'load' } : route.request().postDataJSON();
      if (input.action === 'load') {
        await route.fulfill({ json: { sheet: { head: HEAD, kinds: ['date', 'order_no', 'amount', '', '', ''], editable: Array(6).fill(true), canEdit: true,
          options: { 3: ['1年', '2年', '3年'], 4: ['已完成', '未完成'] },
          rows: Array.from({ length: capacity }, () => Array(6).fill('')), rowNos: Array.from({ length: capacity }, (_, i) => i + 1), orderCol: 1, pending: 0, changed: [],
          online: { month: '2026-10', business: '网站模板', states: { 1: { s: 'ok', t: '已同步 · 订单 A1' }, 2: { s: 'bad', t: '需处理：日期无法识别' } } }, file: { id: 1, business: '网站模板' } } } });
      } else if (input.action === 'save') {
        assert(input.edits.length <= 500); saves.push({ n: input.edits.length, total: input.total });
        input.edits.forEach(e => stored.set(e.row + ':' + e.col, e.v));
        await route.fulfill({ json: { saved: input.edits.length, pending: 0, at: '12:00:00' } });
      } else if (input.action === 'sync') {
        syncs.push(Date.now());
        const states = { 1: { s: 'ok', t: '已同步 · 订单 A1' }, 2: { s: 'bad', t: '需处理：日期无法识别' } };
        for (const k of stored.keys()) { const row = Number(k.split(':')[0]); if (row > 2) states[row] = { s: 'ok', t: '已同步 · 订单 N' + row }; }
        await route.fulfill({ json: { ok: true, states, synced: 1, bad: 1, busy: false } });
      } else { await route.fulfill({ json: { results: [] } }); }
    });
    await page.goto(`http://127.0.0.1:${server.address().port}/`);
    await page.waitForFunction(() => document.querySelector('.se-status').textContent.includes('全部字段可编辑'));
    const grid = () => page.evaluate(() => document.querySelector('.se-grid').jexcel);

    // 1. 系统状态列：最右一列，只读，显示每行同步结果
    const cols = await page.evaluate(() => { const t = document.querySelector('.se-grid').jexcel; return { n: t.options.columns.length, last: t.options.columns[t.options.columns.length - 1], v1: t.getValueFromCoords(6, 0), v2: t.getValueFromCoords(6, 1) }; });
    assert.equal(cols.n, 7); assert.equal(cols.last.title, '系统状态'); assert.equal(cols.last.readOnly, true);
    assert(cols.v1.includes('已同步') && cols.v2.includes('需处理'), '状态列显示每行结果');
    await page.waitForFunction(() => document.querySelector('.se-sync-status').textContent.includes('已同步 1 行'));

    // 2. 下拉格：鼠标点一下就展开选项，选一项就写进去并保存
    await page.click('.jexcel td[data-x="3"][data-y="0"]');
    await page.waitForSelector('.jdropdown-container.jdropdown-focus, .jdropdown-container', { state: 'visible', timeout: 4000 });
    const optionTexts = await page.evaluate(() => [...document.querySelectorAll('.jdropdown-item')].filter(e => e.offsetParent !== null).map(e => e.textContent.trim()));
    assert(optionTexts.includes('2年'), '点下拉格应展开选项：' + JSON.stringify(optionTexts));
    await page.click('.jdropdown-item:has-text("2年")');
    await page.evaluate(() => editor.flush());
    assert.equal(stored.get('1:3'), '2年', '选中的下拉值已自动保存');

    // 3. 新增行：+10 行，保存时带总行数 15，新行的行号接在后面
    await page.click('.se-add[data-n="10"]');
    assert.equal(await page.evaluate(() => document.querySelector('.se-grid').jexcel.options.data.length), 15);
    await page.evaluate(() => document.querySelector('.se-grid').jexcel.setValueFromCoords(1, 12, '订单-新增行'));
    await page.evaluate(() => editor.flush());
    assert.equal(stored.get('13:1'), '订单-新增行', '第 13 行的内容保存到行号 13');
    assert.equal(saves[saves.length - 1].total, 15, '保存时带上总行数');

    // 4. 粘贴超出末行：自动加行（从 Excel 复制的多行多列）
    const lines = Array.from({ length: 20 }, (_, i) => ['2026-10-' + String(i + 1).padStart(2, '0'), 'P' + i, String(100 + i), '1年', '已完成', '粘贴备注' + i].join('\t')).join('\n');
    await page.evaluate(t => { const j = document.querySelector('.se-grid').jexcel; j.paste(0, 10, t); }, lines);
    await page.evaluate(() => editor.flush());
    const total = await page.evaluate(() => document.querySelector('.se-grid').jexcel.options.data.length);
    assert.equal(total, 30, '从第 11 行起贴 20 行：总行数 30（自动新增了 5 行）');
    assert.equal(stored.get('30:1'), 'P19'); assert.equal(stored.get('11:2'), '100');
    assert.equal(saves[saves.length - 1].total, 30);
    assert([...stored.keys()].every(k => Number(k.split(':')[1]) < 6), '状态列的值不会被保存');

    // 5. 状态列不能编辑
    await page.dblclick('.jexcel td[data-x="6"][data-y="0"]');
    assert.equal(await page.evaluate(() => !!document.querySelector('.se-grid').jexcel.edition), false, '状态列不能进入编辑');

    // 6. 停手约 8 秒后自动同步；同步结果写回状态列
    const t0 = Date.now(); const before = syncs.length;
    await page.evaluate(() => document.querySelector('.se-grid').jexcel.setValueFromCoords(2, 13, '999'));
    await page.waitForFunction(n => window.__syncs === undefined, before, { timeout: 100 }).catch(() => {});
    await new Promise(r => setTimeout(r, 9500));
    assert(syncs.length > before, '停手后自动同步');
    const st = await page.evaluate(() => document.querySelector('.se-grid').jexcel.getValueFromCoords(6, 12));
    assert(st && st.includes('已同步'), '同步结果写回状态列：' + st);
    assert(Date.now() - t0 >= 8000, '不是每次改动都立刻同步');

    // 7. 立即同步按钮
    const before2 = syncs.length; await page.click('.se-sync'); await page.waitForFunction(() => document.querySelector('.se-sync-status').textContent.includes('已同步'));
    assert(syncs.length > before2, '立即同步按钮');
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({ statusColumn: true, dropdownOpensOnClick: true, addRows: true, pasteAddsRows: true, autoSync: true, browserErrors: 0 }));
    await context.close();
  } finally { await browser.close(); server.close(); }
})().catch(e => { console.error(e); server.close(); process.exitCode = 1; });
