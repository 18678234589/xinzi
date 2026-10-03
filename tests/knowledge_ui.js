// 轻量 DOM 模型测试：不启动浏览器，不访问生产 API 或存储。
const fs = require('fs'), vm = require('vm'), assert = require('assert');
const source = fs.readFileSync(require('path').join(__dirname,'../assets/js/knowledge.js'),'utf8');
let checks = 0;
function check(actual, expected) { assert.deepStrictEqual(actual,expected); checks++; }
function control(extra) {
  return Object.assign({dataset:{},attrs:{},events:{},hidden:false,addEventListener(k,f){this.events[k]=f;},setAttribute(k,v){this.attrs[k]=v;},focus(){this.focused=true;}},extra);
}
function boot(reduced, stored, storageThrows) {
  const select=control({value:'常用工具',options:[{value:'常用工具'},{value:'设计灵感'},{value:'__new__'}]}), input=control({value:''}), panel=control(), toggle=control(), cancel=control();
  const field={querySelector(s){return {'select[name="category"]':select,'[data-kb-category-new]':panel,'input[name="new_category"]':input,'[data-kb-category-toggle]':toggle,'[data-kb-category-cancel]':cancel}[s];}};
  const buttons=[control(),control()];
  const heroes=buttons.map(button=>({dataset:{},querySelector(){return button;}}));
  const document={documentElement:{dataset:{}},readyState:'complete',currentScript:{dataset:{skipKeywords:'1'}},getElementById(){return null;},querySelectorAll(s){return {'.kb-hero':heroes,'[data-kb-motion]':buttons,'[data-kb-category-field]':[field]}[s]||[];}};
  const storage={value:stored,getItem(){if(storageThrows)throw Error('restricted');return this.value;},setItem(k,v){if(storageThrows)throw Error('restricted');this.value=v;}};
  vm.runInNewContext(source,{document,window:{matchMedia(){return {matches:reduced};}},location:{hash:''},localStorage:storage,setTimeout,URL});
  return {select,input,panel,toggle,cancel,buttons,heroes,storage};
}
let ui=boot(true,null,false);
check(ui.heroes[0].dataset.motion,'off'); check(ui.buttons[0].textContent,'开启动效');
ui.buttons[0].events.click();
check(ui.heroes.map(h=>h.dataset.motion),['on','on']); check(ui.buttons[1].attrs['aria-pressed'],'true'); check(ui.storage.value,'on');
ui.buttons[1].events.click(); check(ui.heroes[0].dataset.motion,'off'); check(ui.storage.value,'off');
check(boot(false,null,false).heroes[0].dataset.motion,'on');
check(boot(true,'on',false).heroes[0].dataset.motion,'on');
check(boot(false,'off',false).heroes[0].dataset.motion,'off');
const restricted=boot(true,null,true); restricted.buttons[0].events.click(); check(restricted.heroes[0].dataset.motion,'on');
check(ui.panel.hidden,true); check(ui.input.disabled,true); check(ui.input.required,false);
ui.toggle.events.click(); check(ui.select.value,'__new__'); check(ui.panel.hidden,false); check(ui.input.disabled,false); check(ui.input.required,true); check(ui.input.focused,true); check(ui.toggle.attrs['aria-expanded'],'true');
ui.cancel.events.click(); check(ui.select.value,'常用工具'); check(ui.panel.hidden,true); check(ui.input.disabled,true); check(ui.input.required,false);
ui.select.value='设计灵感'; ui.select.events.change(); ui.toggle.events.click(); ui.cancel.events.click(); check(ui.select.value,'设计灵感');
ui.select.value='__new__'; ui.select.events.change(); check(ui.panel.hidden,false);
function luminance(hex) { const c=hex.match(/../g).map(v=>parseInt(v,16)/255).map(v=>v<=.04045?v/12.92:Math.pow((v+.055)/1.055,2.4)); return c[0]*.2126+c[1]*.7152+c[2]*.0722; }
function contrast(a,b) { const x=luminance(a),y=luminance(b); return (Math.max(x,y)+.05)/(Math.min(x,y)+.05); }
['377c69','23574d'].forEach(bg=>check(contrast('ffffff',bg)>=4.5,true));
check(contrast('285b4e','f1f7f3')>=4.5,true); check(contrast('8c3947','fff4f3')>=4.5,true);
console.log(`All ${checks} knowledge UI checks passed; no network or persistent writes.`);
