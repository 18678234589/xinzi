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
      else if(action==='ns')data=mode==='nsfail'?{ok:false,error:'fixture NS rejected'}:{ok:true,data:{nameservers:['sixteen.dnspod.net','carl.dnspod.net']}};
      else if(action==='records'){
        reads++;
        if(reads>1&&mode==='refreshfail'){ok=false;data={error:'fixture refresh failed'};}
        else data={ok:true,data:{provider:mode==='external'?'dnspod':mode==='cloudflare'?'cloudflare':'bizcn',provider_label:mode==='external'?'DNSPod':mode==='cloudflare'?'Cloudflare':'商务中国',rows:[{id:'42',revision:'fixture-revision',ttl:1800,proxied:mode==='cloudflare',line:'电信',type:reads>1?'CNAME':'A',host:'www',value:reads>1?'target.example.net':'1.2.3.4'}]}};
      } else if(action==='record_edit'){
        if(mode==='savefail'){ok=false;data={error:'fixture provider rejected CNAME'};}
        else data={ok:true,data:{message:'解析记录已保存成功'}};
      } else throw new Error('Unexpected action '+action);
      return {ok:ok&&data.ok!==false,redirected:false,json:async()=>data};
    }});
  await flush();
  get('domainRows').onclick({target:{closest:()=>({dataset:{domainIndex:'0'}})}});
  await flush();
  if(mode==='ns'||mode==='nsfail') {
    tabs.find(t=>t.dataset.tab==='ns').onclick();get('domainNsValue').value='sixteen.dnspod.net\ncarl.dnspod.net';
    get('domainNsForm').onsubmit({preventDefault(){},submitter:saveButton});await flush();await flush();
    return {get,requests,saveButton,tabs};
  }
  tabs.find(t=>t.dataset.tab==='records').onclick();await flush();editButton.onclick();
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
  test(page.get('domainDnsWarning').hidden&&page.get('domainExternalDnsLink').hidden,'DNSPod manages records inline without registrar warning');
  test(page.get('domainRecordHelp').textContent.includes('DNSPod'),'active provider shown beside actual records');
  sent=page.requests.find(r=>r.action==='record_edit').body;
  test(sent.get('provider')==='dnspod'&&sent.get('record_id')==='42'&&sent.get('record_revision')==='fixture-revision','DNSPod edits identify provider and exact record revision');
  test(sent.get('ttl')==='1800','existing TTL preserved');
  page=await scenario('cloudflare');sent=page.requests.find(r=>r.action==='record_edit').body;
  test(sent.get('provider')==='cloudflare'&&sent.get('proxied')==='1','Cloudflare proxy preserved while editing');
  page=await scenario('ns');
  test(!page.get('nsFormStatus').hidden&&page.get('nsFormStatus').textContent.includes('修改成功'),'NS success visible next to NS form even if API omits message');
  test(page.get('nsFormStatus').scrolls>0,'NS result scrolled into view');
  test(page.get('domainCurrentNs').innerHTML.includes('sixteen.dnspod.net'),'overview NS updates on save');
  page=await scenario('nsfail');
  test(!page.get('nsFormStatus').hidden&&page.get('nsFormStatus').textContent.includes('NS rejected'),'NS errors visible next to NS form');
  console.log(JSON.stringify({passed:checks,failed:0,real_dns_writes:0}));
})().catch(e=>{console.error(e);process.exit(1);});
