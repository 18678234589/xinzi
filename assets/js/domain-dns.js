(function () {
  'use strict';
  var presets={default:['ns7.cnmsn.net','ns8.cnmsn.net'],smart:['bizcn1.dnspod.net','bizcn2.dnspod.net']};
  var template=document.getElementById('domainNsChoicesTemplate'),form=document.getElementById('domainNsForm');
  if(template && form)form.insertBefore(template.content.cloneNode(true),form.firstChild);
  function sync(target){
    document.querySelectorAll('[data-ns-target]').forEach(function(group){
      if(group.dataset.nsTarget!==target.id)return;
      var values=target.value.trim().toLowerCase().split(/[\s,;，；]+/).filter(Boolean).sort(),mode='custom';
      Object.keys(presets).forEach(function(key){if(JSON.stringify(values)===JSON.stringify(presets[key].slice().sort()))mode=key;});
      group.querySelectorAll('input[type=radio]').forEach(function(radio){radio.checked=radio.value===mode;});
      target.readOnly=mode!=='custom';group.querySelector('[data-smart-warning]').hidden=mode!=='smart';
    });
  }
  window.bizcnNsSync=sync;
  document.querySelectorAll('[data-ns-target]').forEach(function(group){
    var target=document.getElementById(group.dataset.nsTarget);if(!target)return;
    group.querySelectorAll('input[type=radio]').forEach(function(radio){radio.addEventListener('change',function(){
      if(!radio.checked)return;
      if(presets[radio.value])target.value=presets[radio.value].join('\n');
      target.readOnly=radio.value!=='custom';group.querySelector('[data-smart-warning]').hidden=radio.value!=='smart';
      if(radio.value==='custom')target.focus();
      // No network request, no DNS modification until the enclosing form is explicitly submitted.
    });});
    sync(target);
  });
})();
