const assert = require('assert');
const fs = require('fs');
const vm = require('vm');
const source = fs.readFileSync(require('path').join(__dirname, '../assets/js/domains.js'), 'utf8');
const flush = () => new Promise(resolve => setImmediate(resolve));
async function scenario(mode) {
  const nodes = new Map(), requests = [];
  function element() { return { dataset:{}, hidden:false, disabled:false, value:'', textContent:'', innerHTML:'保存解析', open:false, scrolls:0,
    classList:{toggle(){}}, addEventListener(){}, querySelectorAll(){return [];}, focus(){}, scrollIntoView(){this.scrolls++;}, showModal(){this.open=true;}, close(){this.open=false;} }; }
  function get(id) { if (!nodes.has(id)) nodes.set(id, element()); return nodes.get(id); }
  const saveButton = element(), editButton = element(); editButton.dataset.recordEdit='0';
  const tabs = ['overview','ns','records','realname'].map(name => {const e=element();e.dataset.tab=name;return e;});
  get('domainConsole').dataset.csrf='fixture';
  get('domainConsole').querySelectorAll = selector => selector==='[data-tab]'?tabs:[];
  get('domainRecordRows').querySelectorAll = selector => selector==='[data-record-edit]'?[editButton]:[];
  get('domainRecordForm').querySelector = () => saveButton;
  let reads = 0;
  vm.runInNewContext(source, {document:{getElementById:get},URLSearchParams,AbortController,setTimeout,clearTimeout,URL,
    window:{confirm:()=>true}, fetch:async (url,args)=>{
      const action=args.body.get('action');requests.push({action,body:args.body});let ok=true,data;
      if(action==='list')data={ok:true,data:{rows:[{domainname:'example.com'}],total:1,page:1,updated_at:'now'}};
      else if(action==='detail')data={ok:true,data:{info:mode==='external'?{dns_host1:'carl.dnspod.net',dns_host2:'sixteen.dnspod.net'}:{}}};
      else if(action==='records'){
        reads++;
        if(reads>1&&mode==='refreshfail'){ok=false;data={error:'fixture refresh failed'};}
        else data={ok:true,data:{rows:[{type:reads>1?'CNAME':'A',host:'www',value:reads>1?'target.example.net':'1.2.3.4'}]}};
      } else if(action==='record_edit'){
        if(mode==='savefail'){ok=false;data={error:'fixture provider rejected CNAME'};}
        else data={ok:true,data:{message:'解析记录已保存成功'}};
      } else throw new Error('Unexpected action '+action);
      return {ok,redirected:false,json:async()=>data};
    }});
  await flush();
  get('domainRows').onclick({target:{closest:()=>({dataset:{domainIndex:'0'}})}});
  await flush();tabs.find(t=>t.dataset.tab==='records').onclick();await flush();editButton.onclick();
  assert.strictEqual(get('recordType').disabled,false);
  get('recordType').value='CNAME';get('recordValue').value='target.example.net';
  get('domainRecordForm').onsubmit({preventDefault(){},submitter:saveButton});
  await flush();await flush();
  return {get,requests,saveButton};
}
(async()=>{
  let checks=0;function test(v,label){assert(v,label);checks++;}
  let page=await scenario('success');let sent=page.requests.find(r=>r.action==='record_edit').body;
  test(sent.get('old_type')==='A'&&sent.get('type')==='CNAME','submit both original and requested types');
  test(sent.get('old_value')==='1.2.3.4'&&sent.get('value')==='target.example.net','original value preserved for safe replacement');
  test(page.get('domainRecordForm').hidden,'editor closes on successful save');
  test(!page.get('recordFormStatus').hidden&&page.get('recordFormStatus').textContent.includes('保存成功'),'success remains visible after editor closes');
  test(page.get('recordFormStatus').scrolls>0,'result scrolled into view');
  test(page.requests.filter(r=>r.action==='records').length===2,'refresh list after save');
  test(!page.saveButton.disabled,'save button restored');
  page=await scenario('savefail');
  test(!page.get('domainRecordForm').hidden,'failed save keeps user inputs');
  test(!page.get('recordFormStatus').hidden&&page.get('recordFormStatus').textContent.includes('provider rejected'),'provider error visible beside records');
  test(page.get('recordValue').value==='target.example.net','failed save retains target value');
  test(page.requests.filter(r=>r.action==='records').length===1,'failed save not falsely refreshed as successful');
  page=await scenario('refreshfail');
  test(page.get('recordFormStatus').textContent.includes('保存成功')&&page.get('recordFormStatus').textContent.includes('刷新失败'),'refresh failure does not erase successful mutation outcome');
  test(page.get('recordFormStatus').textContent.includes('勿重复保存'),'ambiguous refresh guides user to query instead of retry');
  page=await scenario('external');
  test(!page.get('domainDnsWarning').hidden&&page.get('domainDnsWarning').textContent.includes('DNSPod'),'external resolver warning is visible');
  test(page.get('domainExternalDnsLink').href==='dns.php?d=example.com&p=dnspod','external resolver links to site management');
  test(page.get('recordFormStatus').textContent.includes('解析未变更'),'registrar save never claims external DNS changed');
  console.log(JSON.stringify({passed:checks,failed:0,real_dns_writes:0}));
})().catch(e=>{console.error(e);process.exit(1);});
