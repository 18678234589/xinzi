const assert=require('assert'),fs=require('fs'),vm=require('vm'),path=require('path');
const target={id:'domainNsValue',value:'',readOnly:false,focus(){this.focused=true;}},warning={hidden:true};
const radios=['default','smart','custom'].map(value=>({value,checked:value==='custom',addEventListener(type,fn){this.onchange=fn;}}));
const group={dataset:{nsTarget:target.id},querySelectorAll(){return radios;},querySelector(){return warning;}};
const window={};vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../assets/js/domain-dns.js'),'utf8'),{window,document:{getElementById(id){return id===target.id?target:null;},querySelectorAll(){return[group];}}});
let count=0;function test(v,m){assert(v,m);count++;}function select(index){radios.forEach((r,i)=>r.checked=i===index);radios[index].onchange();}
select(0);test(target.value==='ns7.cnmsn.net\nns8.cnmsn.net','default pair');test(target.readOnly,'preset readonly');test(warning.hidden,'default no smart warning');
select(1);test(target.value==='bizcn1.dnspod.net\nbizcn2.dnspod.net','smart pair');test(!warning.hidden,'DNSPod constraint visible');
select(2);test(!target.readOnly&&target.focused,'custom editable');test(warning.hidden,'warning follows mode');target.value='ns8.cnmsn.net\nns7.cnmsn.net';window.bizcnNsSync(target);test(radios[0].checked,'existing default recognized');
target.value='other1.example.com\nother2.example.com';window.bizcnNsSync(target);test(radios[2].checked&&!target.readOnly,'existing custom recognized');
test(!sourceHasNetwork(),'no automatic DNS change');function sourceHasNetwork(){return /fetch\s*\(|XMLHttpRequest/.test(fs.readFileSync(path.join(__dirname,'../assets/js/domain-dns.js'),'utf8'));}
console.log(JSON.stringify({passed:count,failed:0}));
