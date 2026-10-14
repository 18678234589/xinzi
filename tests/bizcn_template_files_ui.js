// 证件图片投放区：多次添加会累加、可拖拽 / 粘贴、可单张移除、最多 4 张。无需浏览器，用最小 DOM 替身运行页面脚本。
const assert = require('assert'), fs = require('fs'), vm = require('vm'), path = require('path');
const source = fs.readFileSync(path.join(__dirname, '../assets/js/domain-template-ai.js'), 'utf8');
function makeNode(id) {
  const handlers = {};
  return {
    id, dataset: {}, value: '', hidden: false, disabled: false, textContent: '', innerHTML: '', open: false, files: null,
    classList: { add() {}, remove() {}, toggle() {} },
    querySelectorAll(selector) { return this._buttons && selector === '[data-remove-file]' ? this._buttons : []; },
    addEventListener(name, fn) { handlers[name] = fn; }, fire(name, event) { return handlers[name](event); },
    click() { this.clicked = (this.clicked || 0) + 1; }, focus() {}, scrollIntoView() {}, getAttribute() { return null; },
  };
}
(async () => {
  let checks = 0; const t = (ok, m) => { assert(ok, m); checks++; };
  const nodes = new Map(), get = id => { if (!nodes.has(id)) nodes.set(id, makeNode(id)); return nodes.get(id); };
  get('domainConsole').dataset.csrf = 'x';
  const box = get('templateCreator'); box.dataset.regions = '{}'; box.dataset.certTypes = '{"company":{},"person":{}}';
  box.querySelectorAll = () => []; // 高亮清理用
  const revoked = [];
  const ctx = { document: { getElementById: get, createElement: () => makeNode('x') }, window: { location: { hash: '' }, confirm() { return true; } }, URL: { createObjectURL: f => 'blob:' + f.name, revokeObjectURL: u => revoked.push(u) }, FormData, AbortController, setTimeout, clearTimeout, fetch: async () => { throw new Error('no network'); }, console };
  vm.runInNewContext(source, ctx);
  const file = (name, type = 'image/jpeg') => ({ name, type, size: 1000 });
  const names = () => (get('tcThumbs').innerHTML.match(/alt="证件图片 \d+"/g) || []).length;

  // 第一次选 1 张，第二次再选 1 张：两张都保留
  get('tcFiles').files = [file('front.jpg')]; await get('tcFiles').onchange.call(get('tcFiles'));
  t(names() === 1 && get('tcFileInfo').textContent.includes('1 / 4'), 'first file listed');
  get('tcFiles').files = [file('back.jpg')]; await get('tcFiles').onchange.call(get('tcFiles'));
  t(names() === 2 && get('tcFileInfo').textContent.includes('2 / 4') && get('tcFileInfo').textContent.includes('上下拼接'), 'second file added, first kept');
  t(get('tcFiles').value === '', 'input reset so the same file can be chosen again');

  // 拖拽进投放区
  const buttons = [0, 1, 2, 3].map(i => ({ dataset: { removeFile: String(i) }, onclick: null }));
  get('tcThumbs')._buttons = buttons;
  const drop = get('tcDrop'); let prevented = 0;
  await drop.fire('drop', { preventDefault() { prevented++; }, dataTransfer: { files: [file('license.png', 'image/png')] } });
  t(names() === 3 && prevented === 1, 'drag and drop adds a file');
  // 非图片被拒绝
  await drop.fire('drop', { preventDefault() {}, dataTransfer: { files: [file('notes.pdf', 'application/pdf')] } });
  t(names() === 3 && get('tcNotice').textContent.includes('JPG / PNG'), 'non-image refused with a message');
  // 超过 4 张只收满
  await drop.fire('drop', { preventDefault() {}, dataTransfer: { files: [file('a.jpg'), file('b.jpg'), file('c.jpg')] } });
  t(names() === 4 && get('tcNotice').textContent.includes('最多 4 张'), 'limited to four');
  await drop.fire('drop', { preventDefault() {}, dataTransfer: { files: [file('d.jpg')] } });
  t(names() === 4 && get('tcNotice').textContent.includes('先移除'), 'full list asks to remove first');

  // 粘贴截图
  box.fire('paste', { preventDefault() {}, clipboardData: { files: [file('shot.png', 'image/png')] } });
  t(names() === 4, 'paste while full does not exceed the limit');

  // 移除一张后列表减少，并释放旧预览地址
  const before = revoked.length;
  buttons[0].onclick({ stopPropagation() {} });
  t(names() === 3 && get('tcFileInfo').textContent.includes('3 / 4'), 'remove button drops that file only');
  t(revoked.length > before, 'old preview URLs are released');
  await drop.fire('drop', { preventDefault() {}, dataTransfer: { files: [file('again.jpg')] } });
  t(names() === 4, 'room freed after removal');
  console.log(JSON.stringify({ passed: checks, failed: 0 }));
})().catch(e => { console.error(e); process.exit(1); });
