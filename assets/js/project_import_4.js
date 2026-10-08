
(function () {
  var zone = document.getElementById('projectDropZone');
  var input = document.getElementById('projectImportFile');
  var label = document.getElementById('projectFileName');
  if (!zone || !input) return;
  input.addEventListener('change', function () { label.textContent = input.files.length ? input.files[0].name : '尚未选择文件'; });
  ['dragenter','dragover'].forEach(function (eventName) { zone.addEventListener(eventName, function (event) { event.preventDefault(); zone.classList.add('is-dragging'); }); });
  ['dragleave','drop'].forEach(function (eventName) { zone.addEventListener(eventName, function (event) { event.preventDefault(); zone.classList.remove('is-dragging'); }); });
  zone.addEventListener('drop', function (event) { if (!event.dataTransfer.files.length) return; input.files = event.dataTransfer.files; label.textContent = input.files[0].name; });
})();
