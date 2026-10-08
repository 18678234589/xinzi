
(function () {
  var template = document.getElementById('projectTemplate');
  var quantity = document.getElementById('projectQuantity');
  var customPrice = document.getElementById('projectCustomPrice');
  var proof = document.getElementById('projectProof');
  var proofField = document.getElementById('projectProofField');
  function refreshCostForm() {
    var selected = template.options[template.selectedIndex];
    var custom = template.value === '0';
    document.querySelectorAll('.project-custom-field').forEach(function (field) { field.classList.toggle('d-none', !custom); });
    var requiresProof = custom || selected.getAttribute('data-proof') === '1';
    proofField.classList.toggle('d-none', !requiresProof);
    proof.required = requiresProof;
    var price = custom ? parseFloat(customPrice.value) : parseFloat(selected.getAttribute('data-price'));
    var count = parseFloat(quantity.value);
    document.getElementById('projectCostPreview').textContent = Number.isFinite(price) && Number.isFinite(count) ? '¥' + (price * count).toFixed(2) : '—';
  }
  [template, quantity, customPrice].forEach(function (field) { field.addEventListener('input', refreshCostForm); field.addEventListener('change', refreshCostForm); });
  refreshCostForm();
})();
