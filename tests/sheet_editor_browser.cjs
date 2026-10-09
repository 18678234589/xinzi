// NODE_PATH must contain playwright. Uses synthetic data and a loopback HTTP server only.
const { chromium } = require('playwright');
const http = require('http');
const fs = require('fs');
const path = require('path');
const assert = require('assert/strict');
const root = path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'project/files.php'), 'utf8');
const fragment = source.slice(source.indexOf('<div class="se-card"'), source.indexOf('<div id="sheetStatic"')).replace(/<\?php[\s\S]*?\?>/g, 'fixture');
const html = `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/lib/jspreadsheet/jsuites.css"><link rel="stylesheet" href="/assets/lib/jspreadsheet/jspreadsheet.css"><link rel="stylesheet" href="/assets/css/sheet_editor.css"><style>body{margin:12px} .form-control{box-sizing:border-box} button{cursor:pointer} .test-wrap{display:flex;max-width:100%} .project-file-view{width:100%}</style></head><body><div class="test-wrap"><div class="project-file-view">${fragment}</div></div><script src="/assets/lib/jspreadsheet/jsuites.js"></script><script src="/assets/lib/jspreadsheet/jspreadsheet.js"></script><script src="/assets/js/sheet_editor.js"></script><script>var root=document.getElementById('seRoot');root.setAttribute('data-api','/api');root.setAttribute('data-import-url','/import');root.setAttribute('data-csrf','test-csrf');root.setAttribute('data-file','1');root.setAttribute('data-sheet','CSV');root.setAttribute('data-can-edit','1');window.editor=SheetEditor(root);</script></body></html>`;
const server = http.createServer((req, res) => {
  if (req.url === '/') { res.setHeader('Content-Type', 'text/html; charset=utf-8'); res.end(html); return; }
  const p = path.resolve(root, '.' + req.url.split('?')[0]);
  if (!p.startsWith(root + path.sep) || !fs.existsSync(p)) { res.statusCode = 404; res.end(); return; }
  res.setHeader('Content-Type', p.endsWith('.css') ? 'text/css' : 'text/javascript'); res.end(fs.readFileSync(p));
});

(async () => {
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const browser = await chromium.launch({ headless: true, executablePath: process.env.SHEET_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe' });
  try {
    for (const width of [1200, 390]) {
      const context = await browser.newContext({ viewport: { width, height: 850 }, isMobile: width < 500, hasTouch: width < 500 });
      const page = await context.newPage(), errors = [], batches = [], stored = new Map();
      let rejectSaves = false, submitted = null;
      page.on('pageerror', e => errors.push(e.message));
      await page.route('**/api**', async route => {
        const input = route.request().method() === 'GET' ? { action: 'load' } : route.request().postDataJSON();
        if (input.action === 'load') {
          await route.fulfill({ json: { sheet: { head: Array.from({ length: 20 }, (_, i) => '字段' + i), kinds: Array(20).fill(''), editable: Array(20).fill(true), canEdit: true,
            rows: Array.from({ length: 80 }, (_, y) => Array.from({ length: 20 }, (_, x) => x === 0 ? '0012345678901234567890' : '原值' + y + ':' + x)), rowNos: Array.from({ length: 80 }, (_, i) => i + 1), orderCol: 0, pending: 0, changed: [], file: { business: '网站模板' } } } });
        } else if (input.action === 'save') {
          if (rejectSaves) { await route.fulfill({ status: 503, json: { error: '模拟保存失败' } }); return; }
          assert(input.edits.length <= 500); batches.push(input.edits.length);
          input.edits.forEach(e => stored.set(e.row + ':' + e.col, e.v));
          await route.fulfill({ json: { saved: input.edits.length, pending: stored.size, at: '12:00:00' } });
        } else { await route.fulfill({ json: { results: [] } }); }
      });
      await page.route('**/import', async route => { submitted = new URLSearchParams(route.request().postData()); await route.fulfill({ body: '核对页面', contentType: 'text/html' }); });
      await page.goto(`http://127.0.0.1:${server.address().port}/`);
      await page.waitForFunction(() => document.querySelector('.se-status').textContent.includes('全部字段可编辑'));
      const layout = await page.evaluate(() => { const c = document.querySelector('.jexcel_content'); return { overflow: c.scrollWidth > c.clientWidth, bodyWidth: document.body.scrollWidth, screen: innerWidth, readonly: document.querySelectorAll('.jexcel td.readonly').length }; });
      assert(layout.overflow, '表格必须可横向滚动'); assert(layout.bodyWidth <= width + 2, '页面不能被宽表撑开'); assert.equal(layout.readonly, 0);
      await page.click('[data-se-scroll="1"]');
      assert(await page.evaluate(() => document.querySelector('.jexcel_content').scrollLeft > 0), '向右按钮有效');
      await page.evaluate(() => { document.querySelector('.se-scrollbar').scrollLeft = 0; });
      await page.waitForFunction(() => document.querySelector('.jexcel_content').scrollLeft === 0);
      if (width < 500) {
        const cdp = await context.newCDPSession(page);
        const bounds = await page.locator('.jexcel_content').boundingBox();
        const y = Math.min(700, bounds.y + 90);
        await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: 310, y }] });
        for (const x of [270, 210, 140, 70]) await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x, y }] });
        await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
        await page.waitForFunction(() => document.querySelector('.jexcel_content').scrollLeft > 0);
        await page.evaluate(() => { document.querySelector('.jexcel_content').scrollLeft = 0; });
        await page.waitForFunction(() => document.querySelector('.se-scrollbar').scrollLeft === 0);
      }
      await page.click('.jexcel td[data-x="0"][data-y="0"]');
      await page.fill('.se-cell-input', '0098765432109876543210'); await page.click('.se-cell-apply');
      await page.evaluate(() => editor.flush());
      assert.equal(stored.get('1:0'), '0098765432109876543210');
      // Last cell remains editable, even after horizontal scrolling.
      await page.evaluate(() => document.querySelector('.se-grid').jexcel.setValueFromCoords(19, 0, '最后一列可编辑'));
      await page.evaluate(() => editor.flush()); assert.equal(stored.get('1:19'), '最后一列可编辑');
      // Paste-sized updates must be saved in complete batches, without silently dropping cells.
      const before = batches.length;
      await page.evaluate(() => { const t = document.querySelector('.se-grid').jexcel; for (let y = 1; y <= 75; y++) for (let x = 0; x < 20; x++) t.setValueFromCoords(x, y, '批量' + y + ':' + x); });
      await page.evaluate(() => editor.flush()); assert.equal(batches.slice(before).reduce((a, b) => a + b, 0), 1500);
      rejectSaves = true;
      await page.evaluate(() => document.querySelector('.se-grid').jexcel.setValueFromCoords(1, 0, '保存失败后保留'));
      await page.click('.se-import');
      await page.waitForFunction(() => document.querySelector('.se-status').textContent.includes('保存失败'));
      assert.equal(submitted, null, '保存失败不能提交旧数据');
      rejectSaves = false;
      await page.click('.se-import');
      await page.waitForURL('**/import');
      assert.equal(stored.get('1:1'), '保存失败后保留');
      assert.equal(submitted.get('action'), 'repreview'); assert.equal(submitted.get('resume_file'), '1'); assert.equal(submitted.get('file_id'), '1'); assert.equal(submitted.get('csrf'), 'test-csrf');
      assert.equal(submitted.get('auto_import'), null, '保存后先核对，用户确认后才写入订单');
      assert.deepEqual(errors, []);
      console.log(JSON.stringify({ width, horizontalScroll: true, allFieldsEditable: true, pastedCellsSaved: 1500, saveFailureBlocksSubmit: true, savedBeforeImport: true, browserErrors: 0 }));
      await context.close();
    }
  } finally { await browser.close(); server.close(); }
})().catch(e => { console.error(e); server.close(); process.exitCode = 1; });
