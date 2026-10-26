// 我的在线表格 端到端（真实页面 + 真实接口 + 本地隔离库）：工作台入口 → 打开在线表格 → 下拉点开 → 粘贴一行 → 同步 → 状态列 → 刷新后还在 → 数据库里有订单。
// 需要先启动本地服务（只连隔离库 13399）：
//   DB_HOST=127.0.0.1 DB_PORT=13399 DB_NAME=xinzi_test E2E_USER=<宋倩倩的 project_users.id> php -d extension=zip -S 127.0.0.1:8099 -t . <router.php 注入会话>
// 运行：NODE_PATH=<playwright> node tests/online_sheet_e2e.cjs   （E2E_BASE 默认 http://127.0.0.1:8099）
const { chromium } = require('playwright');
const assert = require('assert/strict');
const { execFileSync } = require('child_process');
const base = process.env.E2E_BASE || 'http://127.0.0.1:8099';
const tag = Math.random().toString(16).slice(2, 8);
const now = new Date(); const ym = `${now.getFullYear()}.${now.getMonth() + 1}`;
const orderNo = `91278${tag}00000001`;
const php = (code) => execFileSync('php', ['-d', 'extension=zip', '-r', code], { cwd: require('path').resolve(__dirname, '..'), env: { ...process.env, DB_HOST: '127.0.0.1', DB_PORT: '13399', DB_NAME: 'xinzi_test', E2E_USER: process.env.E2E_USER || '32' }, encoding: 'utf8' });
(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.SHEET_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe' });
  const errors = [];
  try {
    const page = await (await browser.newContext({ viewport: { width: 1360, height: 900 } })).newPage();
    page.on('pageerror', e => errors.push(e.message));
    page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });

    // 1. 工作台有入口，选月份和业务后进入在线表格
    await page.goto(base + '/project/dashboard.php');
    await page.waitForSelector('#my-online-sheets');
    assert((await page.textContent('#my-online-sheets')).includes('我的在线表格'));
    await page.selectOption('#olsBusiness', '网站模板');
    await Promise.all([page.waitForURL('**/project/files.php?view=*'), page.click('#my-online-sheets .ols-go')]);
    await page.waitForFunction(() => document.querySelector('.se-status') && document.querySelector('.se-status').textContent.includes('全部字段可编辑'));
    const fileUrl = page.url();
    const info = await page.evaluate(() => { const t = document.querySelector('.se-grid').jexcel; return { titles: t.options.columns.map(c => c.title), types: t.options.columns.map(c => c.type), rows: t.options.data.length }; });
    assert(info.titles.includes('订单编号') && info.titles[info.titles.length - 1] === '系统状态', '表头是网站模板 + 系统状态列');
    assert(info.rows >= 30, '开箱有空行');
    const col = h => info.titles.indexOf(h);

    // 2. 下拉格：点一下展开选项
    await page.click(`.jexcel td[data-x="${col('状态(填已完成/未完成)')}"][data-y="0"]`);
    await page.waitForSelector('.jdropdown-item', { state: 'visible', timeout: 4000 });
    const opts = await page.evaluate(() => [...document.querySelectorAll('.jdropdown-item')].filter(e => e.offsetParent !== null).map(e => e.textContent.trim()));
    assert(opts.includes('已完成') && opts.includes('未完成'), '状态下拉展开：' + JSON.stringify(opts));
    await page.click('.jdropdown-item:has-text("未完成")');
    await page.evaluate(() => document.querySelector('.se-grid').jexcel.setValueFromCoords(0, 0, '')); // 先清掉，后面整行粘贴
    await page.evaluate(y => document.querySelector('.se-grid').jexcel.setValueFromCoords(y, 0, ''), col('状态(填已完成/未完成)'));

    // 3. 粘贴一整行（像从 Excel 复制来的）
    const v = { '日期': `${ym}.5`, '编号类型': '美呀美旗舰店', '付款账号': 'e2e' + tag, '订单编号': orderNo, '售价': '321', '程序名称': 'JSP展示中级版', '年限': '1年', '客服': '宋倩倩', '技术': '张强', '状态(填已完成/未完成)': '已完成', '续费联系方式电话': '13800138000', '网站域名': `e2e${tag}.com`, '域名到期日期': '2099-01-05', '服务器到期日期': '2099-01-05' };
    const line = info.titles.slice(0, -1).map(h => v[h] || '').join('\t');
    await page.evaluate(t => document.querySelector('.se-grid').jexcel.paste(0, 0, t), line);
    await page.waitForFunction(() => document.querySelector('.se-status').textContent.startsWith('已保存') || document.querySelector('.se-status').textContent.includes('稍后自动保存'));

    // 4. 新增行按钮
    await page.click('.se-add[data-n="10"]');
    assert.equal(await page.evaluate(() => document.querySelector('.se-grid').jexcel.options.data.length), info.rows + 10);

    // 5. 立即同步：状态列出现“已同步”，数据库里有订单
    await page.click('.se-sync');
    await page.waitForFunction(() => /已同步 [1-9]/.test(document.querySelector('.se-sync-status').textContent), null, { timeout: 60000 });
    const status = await page.evaluate(() => document.querySelector('.se-grid').jexcel.getValueFromCoords(document.querySelector('.se-grid').jexcel.options.columns.length - 1, 0));
    assert(status.includes('已同步'), '第 1 行状态：' + status);
    const dbRow = JSON.parse(php(`require "config/database.php"; $q=db()->prepare("SELECT order_no,contract_amount,settlement_status FROM project_orders WHERE order_no LIKE ?"); $q->execute(["%${tag}%"]); echo json_encode($q->fetchAll(PDO::FETCH_ASSOC));`));
    assert.equal(dbRow.length, 1, '数据库里有这张订单'); assert.equal(Number(dbRow[0].contract_amount), 321);

    // 6. 刷新后表格还在、状态还在、新增的行数还在
    await page.goto(fileUrl);
    await page.waitForFunction(() => document.querySelector('.se-status') && document.querySelector('.se-status').textContent.includes('全部字段可编辑'));
    const after = await page.evaluate(() => { const t = document.querySelector('.se-grid').jexcel; return { rows: t.options.data.length, no: t.getValueFromCoords(3, 0), st: t.getValueFromCoords(t.options.columns.length - 1, 0) }; });
    assert.equal(after.rows, info.rows + 10, '新增的行数保存了'); assert.equal(after.no, orderNo); assert(after.st.includes('已同步'));

    // 7. “我的表格”页列出这张在线表格
    await page.goto(base + '/project/files.php');
    assert((await page.textContent('#my-online-sheets')).includes('网站模板'));
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({ dashboardEntry: true, dropdownOpens: true, paste: true, addRows: true, sync: true, persisted: true, browserErrors: 0 }));
  } finally {
    try { php(`require "config/database.php"; require "includes/ProjectOrderDelete.php"; $p=db(); foreach($p->query("SELECT id FROM project_orders WHERE order_no LIKE '%${tag}%'")->fetchAll(PDO::FETCH_COLUMN) as $id) pod_delete_rows((int)$id); $p->exec("DELETE FROM project_order_trash WHERE order_no LIKE '%${tag}%'"); foreach($p->query("SELECT file_id FROM project_online_sheets s JOIN project_users u ON u.employee_id=s.employee_id WHERE u.id=".(int)getenv('E2E_USER')." AND s.month='".date('Y-m')."'")->fetchAll(PDO::FETCH_COLUMN) as $f){ foreach(['project_import_edits','project_online_sheet_rows','project_import_followups'] as $t) $p->exec("DELETE FROM $t WHERE file_id=$f"); $p->exec("DELETE FROM project_online_sheets WHERE file_id=$f"); $p->exec("DELETE FROM project_audit_logs WHERE entity_type='import_file' AND entity_id=$f"); $p->exec("DELETE FROM project_import_files WHERE id=$f"); }`); } catch (e) { /* 清理失败不影响结果 */ }
    await browser.close();
  }
})().catch(e => { console.error(e); process.exitCode = 1; });
