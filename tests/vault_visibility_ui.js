// Browser-free regression for hiding revealed credentials and long notes.
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const assert = require('assert');
const page = fs.readFileSync(path.join(__dirname, '../project/vault.php'), 'utf8');
const start = page.indexOf("  document.querySelectorAll('.js-reveal').forEach");
const end = page.indexOf("  document.querySelectorAll('.js-copy-secret')", start);
assert(start > 0 && end > start);
const handler = page.slice(start, end);
let checks = 0;
function element(text) {
    return {textContent: text, dataset: {}, classList: {add() {}, remove() {}}};
}
async function scenario(withPassword) {
    const span = withPassword ? element('••••••••') : null;
    const notes = element('已加密');
    const passwordButton = element('显示');
    const notesButton = element('查看');
    const buttons = withPassword ? [passwordButton, notesButton] : [notesButton];
    buttons.forEach(button => {
        button.dataset.id = '1';
        button.addEventListener = (name, callback) => { button.click = callback; };
        button.closest = () => ({querySelector: () => button === passwordButton ? span : null});
    });
    const document = {
        querySelector: selector => selector.includes('.js-secret') ? span : notes,
        querySelectorAll: () => buttons
    };
    vm.runInNewContext(handler, {
        document,
        secret: () => Promise.resolve({password:'fixture-only', notes:'fixture connection notes'}),
        alert: message => { throw new Error(message); }
    });
    notesButton.click.call(notesButton);
    await Promise.resolve(); await Promise.resolve();
    assert.strictEqual(notes.textContent, 'fixture connection notes'); checks++;
    assert.strictEqual(notes.dataset.shown, 1); checks++;
    assert.strictEqual(notesButton.textContent, '收起'); checks++;
    if (span) { assert.strictEqual(span.textContent, 'fixture-only'); checks++; }
    notesButton.click.call(notesButton);
    assert.strictEqual(notes.textContent, '已加密'); checks++;
    assert.strictEqual(notes.dataset.shown, undefined); checks++;
    assert.strictEqual(notesButton.textContent, '查看'); checks++;
    if (span) { assert.strictEqual(span.textContent, '••••••••'); checks++; }
}
(async () => {
    await scenario(true);
    await scenario(false);
    assert(page.includes('.js-notes[data-shown]') && page.includes('max-height:320px')); checks++;
    const header = fs.readFileSync(path.join(__dirname, '../includes/header.php'), 'utf8');
    const links = header.split(/\r?\n/).filter(line => line.includes("$nav('/project/vault.php'"));
    assert(links.length > 0 && links.every(line => line.includes('if ($vault_nav)'))); checks++;
    assert(page.indexOf('$actor = pv_require_access();') < page.indexOf("include __DIR__ . '/../includes/header.php'")); checks++;
    console.log(JSON.stringify({checks, passed:true}));
})().catch(error => { console.error(error.message); process.exitCode = 1; });
