const assert=require('assert'),fs=require('fs'),vm=require('vm'),path=require('path');
const source=fs.readFileSync(path.join(__dirname,'../assets/js/domain-registration.js'),'utf8');
function node(){return {dataset:{},value:'',disabled:false,hidden:true,textContent:'',innerHTML:'',classList:{toggle(){}},querySelectorAll(){return[];},setAttribute(){},click(){}};}
async function run(admin=false){
 const nodes=new Map(),get=id=>{if(id==='registrationSettingsForm'&&!admin)return null;if(!nodes.has(id))nodes.set(id,node());return nodes.get(id);};get('domainConsole').dataset.csrf='fixture';
 const requests=[],config={daily_limit:12,used:3,day:'2026-10-12',templates:[{id:'fixture',label:'<img>',owner:'公司<script>'}],ns:['ns1.example.com','ns2.example.com'],revision:1,admin,ready:true};
 let mode='ok';
 vm.runInNewContext(source,{document:{getElementById:get},URLSearchParams,AbortController,setTimeout,clearTimeout,window:{confirm(){return true;},location:{hash:''}},fetch:async(url,args)=>{requests.push(args.body);const action=args.body.get('action');let data;
 if(action==='registration_config')data=config;
 else if(action==='registration_history')data={rows:[]};
 else if(action==='registration_find_template')data={rows:[{id:'bizfound',label:'新客户<b>',owner:'新客户公司'}],warning:''};
 else if(action==='registration_quote'){
  const term=parseInt(args.body.get('term')||'1',10);
  data={id:1,token:'server-token',quote:term===5?{domain:'multi.example.com',price_cents:40200,unit_price_cents:5800,renew_price_cents:8600,premium:false,template:config.templates[0],ns:config.ns,term:5}:{domain:'safe.example.com',price_cents:5800,unit_price_cents:5800,renew_price_cents:8600,premium:false,template:config.templates[0],ns:config.ns,term:1}};
 }
 else if(action==='registration_submit'){if(mode==='timeout')throw new Error('fixture timeout');data={status:'processing'};}
 return{ok:true,redirected:false,json:async()=>({ok:true,data})};}});
 await new Promise(r=>setImmediate(r));return{get,requests,setMode:m=>mode=m};
}
(async()=>{let checks=0;function t(x,m){assert(x,m);checks++;}const a=await run();t(a.get('registrationQuota').textContent.includes('12')&&a.get('registrationQuota').textContent.includes('可用 9'),'quota visible');t(a.get('registrationTemplate').innerHTML.includes('&lt;img&gt;'),'template label escaped');t(!a.get('registrationTemplate').innerHTML.includes('<script>'),'owner escaped');t(a.requests[0].get('csrf')==='fixture','CSRF');
 a.get('registrationDomain').value='safe.example.com';a.get('registrationTemplate').value='fixture';a.get('registrationSearch').onsubmit({preventDefault(){}});await new Promise(r=>setImmediate(r));t(!a.get('registrationConfirm').hidden,'quote shown');t(a.get('registrationQuote').innerHTML.includes('58.00'),'price visible');t(a.requests.filter(x=>x.get('action')==='registration_submit').length===0,'quote no purchase');
 a.get('registrationDomain').value='multi.example.com';a.get('registrationTerm').value='5';a.get('registrationTemplate').value='fixture';a.get('registrationSearch').onsubmit({preventDefault(){}});await new Promise(r=>setImmediate(r));t(a.get('registrationQuote').innerHTML.includes('首年 ¥58.00 + 续费 ¥86.00 × 4年')&&a.get('registrationQuote').innerHTML.includes('402.00'),'multi-year breakdown shown');
 a.get('registrationAck').checked=true;a.setMode('timeout');a.get('registrationConfirm').onsubmit({preventDefault(){}});await new Promise(r=>setImmediate(r));t(a.get('registrationConfirm').hidden,'quote discarded on timeout');t(a.get('registrationNotice').textContent.includes('不要重复购买'),'friendly unknown prompt');a.get('registrationConfirm').onsubmit({preventDefault(){}});await new Promise(r=>setImmediate(r));t(a.requests.filter(x=>x.get('action')==='registration_submit').length===1,'no repeat purchase on click');
 a.get('registrationTemplateQuery').value='新客户';a.get('registrationRefreshTemplates').onclick();await new Promise(r=>setImmediate(r));const find=a.requests.filter(x=>x.get('action')==='registration_find_template')[0];t(find&&find.get('search')==='新客户'&&find.get('refresh')==='1','refresh search sent');t(a.get('registrationTemplate').innerHTML.includes('bizfound')&&!a.get('registrationTemplate').innerHTML.includes('<b>'),'found subject listed and escaped');
 console.log(JSON.stringify({passed:checks,failed:0}));})().catch(e=>{console.error(e);process.exit(1);});
