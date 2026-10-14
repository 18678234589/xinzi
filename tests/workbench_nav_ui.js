const assert = require('assert'), fs = require('fs'), vm = require('vm');
const html = fs.readFileSync(require('path').join(__dirname, '../includes/workbench-nav.php'), 'utf8');
const script = html.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/<\?php echo BASE_URL; \?>/g, '');
function node() {
  const classes = new Set();
  return { children: [], handlers: {}, classList: { toggle(name, on) { on ? classes.add(name) : classes.delete(name); }, contains(name) { return classes.has(name); } },
    appendChild(child) { child.parentNode = this; this.children.push(child); }, addEventListener(name, fn) { this.handlers[name] = fn; }, setAttribute() {} };
}
const body = node(), panel = node(), toggle = node(), close = node(), keys = {};
vm.runInNewContext(script, { document: { body, getElementById(id) { return { 'hn-panel': panel, 'hn-toggle': toggle, 'hn-close': close }[id]; }, createElement: node, addEventListener(name, fn) { keys[name] = fn; } } });
assert.strictEqual(panel.parentNode, body, 'fixed panel must escape filtered navbar');
toggle.handlers.click(); assert(panel.classList.contains('open')); assert.strictEqual(panel.children[0].src, '/harness/entry.php');
close.handlers.click(); assert(!panel.classList.contains('open'));
toggle.handlers.click(); assert.strictEqual(panel.children.length, 1, 'reopening keeps the same chat frame');
keys.keydown({ key: 'Escape' }); assert(!panel.classList.contains('open'));
console.log('Workbench panel placement, toggle, frame reuse and closing passed');
