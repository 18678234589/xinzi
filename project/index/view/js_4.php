
(function () {
  var toggle = document.getElementById('manualOrderToggle');
  var panel = document.getElementById('manual-order');
  if (toggle && panel) toggle.addEventListener('click', function () {
    var opening = panel.classList.contains('d-none');
    panel.classList.toggle('d-none', !opening);
    toggle.setAttribute('aria-expanded', opening ? 'true' : 'false');
    toggle.querySelector('span').textContent = opening ? '收起在线录单' : '在线录入订单';
    if (opening) { panel.scrollIntoView({behavior:'smooth',block:'start'}); document.getElementById('intakeOrderNo').focus(); }
  });
  var business = document.getElementById('intakeBusiness');
  var resourceFields = document.getElementById('intakeResourceFields');
  if (!business) return;
  var canEditResources = <?php echo $actor['role'] === 'customer_service' ? 'false' : 'true'; ?>;
  var catalog = <?php echo json_encode(array_map(function ($d) { return ['resources' => !empty($d['resources']), 'program' => !empty($d['program']), 'kinds' => $d['order_kinds'] ?? [], 'fee' => (float)($d['service_fee_rate'] ?? 0), 'defaultKind' => $d['default_kind'] ?? '', 'costLabel' => !empty($d['import_cost']) ? ($d['cost_label'] ?? '成本') : '']; }, $businessCatalog), JSON_UNESCAPED_UNICODE); ?>;
  var roleDefaultKinds = <?php echo json_encode($roleDefaultKinds, JSON_UNESCAPED_UNICODE); ?>;
  var peopleLabels = <?php echo json_encode(array_reduce(array_keys($businessCatalog), function ($result, $name) { $result[$name] = ps_business_people_labels($name); return $result; }, []), JSON_UNESCAPED_UNICODE); ?>;
  var selectedKind = <?php echo json_encode((string)($_POST['order_kind'] ?? ''), JSON_UNESCAPED_UNICODE); ?>;
  var mode = document.getElementById('intakeDomainMode');
  var domainWrap = document.getElementById('intakeDomainTemplateWrap');
  var domain = document.getElementById('intakeDomainTemplate');
  var server = document.getElementById('intakeServerTemplate');
  var program = document.getElementById('intakeProgram');
  var kind = document.getElementById('intakeKind');
  var price = document.getElementById('intakePrice');
  function optionPrice(select) { var option = select.options[select.selectedIndex]; return Number(option && option.dataset.price || 0); }
  function money(n) { return '¥' + n.toFixed(2); }
  function refreshKinds() {
    var kinds = catalog[business.value].kinds;
    kind.innerHTML = '<option value="">' + (kinds.length ? '请选择' : '—') + '</option>';
    var want = selectedKind || roleDefaultKinds[business.value] || catalog[business.value].defaultKind;
    kinds.forEach(function (k) { var o = document.createElement('option'); o.value = k; o.textContent = k; if (k === want) o.selected = true; kind.appendChild(o); });
    document.getElementById('intakeKindWrap').hidden = !kinds.length;
  }
  function update() {
    var info = catalog[business.value];
    var resources = info.resources;
    document.getElementById('intakeFrontendLabel').textContent = peopleLabels[business.value].frontend;
    document.getElementById('intakeBackendLabel').textContent = peopleLabels[business.value].backend;
    document.getElementById('intakeCustomerServiceLabel').textContent = business.value === '网站模板' || business.value === 'AI网站定制' ? '网站客服' : '客服';
    document.getElementById('intakeFooterHint').textContent = '合作人员提交的售价仅作订单申报，实收仍由财务审核。' + (resources ? ' SSL 非标准成本须补凭证后审核。' : ' 如有特殊成本，可在结算单中补录凭证。');
    resourceFields.hidden = !resources || !canEditResources;
    resourceFields.querySelectorAll('input,select').forEach(function (input) { input.disabled = !resources || !canEditResources; });
    document.getElementById('intakeProgramRow').hidden = !info.program;
    program.disabled = !info.program || !canEditResources;
    document.querySelectorAll('.project-business-fields').forEach(function (section) {
      var active = section.dataset.business === business.value;
      section.hidden = !active;
      section.querySelectorAll('input').forEach(function (input) { input.disabled = !active; });
    });
    var usesProgram = info.program && Number(program.value) > 0;
    var usesDomain = resources && mode.value === 'template';
    domainWrap.hidden = !usesDomain;
    mode.required = resources && canEditResources && !usesProgram;
    domain.required = usesDomain && canEditResources;
    var sale = Number(price.value || 0);
    var outsource = document.getElementById('intakeOutsource');
    var outsourceCost = 0;
    if (outsource) {
      Array.prototype.forEach.call(outsource.options, function (o) { if (o.value !== '0') o.hidden = o.dataset.scope !== '' && o.dataset.scope !== business.value; });
      var picked = outsource.options[outsource.selectedIndex];
      if (picked && picked.hidden) outsource.value = '0';
      document.getElementById('intakeOutsourceRow').hidden = !Array.prototype.some.call(outsource.options, function (o) { return o.value !== '0' && !o.hidden; });
      picked = outsource.options[outsource.selectedIndex];
      if (outsource.value !== '0') outsourceCost = picked.dataset.mode === 'percent' ? Math.round(sale * Number(picked.dataset.price)) / 100 : Number(picked.dataset.price);
    }
    var directCost = document.getElementById('intakeDirectCost');
    document.getElementById('intakeDirectCostWrap').hidden = !info.costLabel;
    directCost.disabled = !info.costLabel;
    document.getElementById('intakeDirectCostLabel').textContent = info.costLabel || '成本';
    // 退款冲减：售价与稿费填负数
    var offset = kind.value === '退款冲减';
    price.min = offset ? '' : '0'; directCost.min = offset ? '' : '0';
    var cost = (info.costLabel ? Number(directCost.value || 0) : 0) + (resources && canEditResources ? (usesDomain ? optionPrice(domain) : 0) + optionPrice(server) + (usesProgram ? optionPrice(program) : 0) : 0) + outsourceCost;
    var fee = Math.round(sale * info.fee * 100) / 100;
    document.getElementById('intakeCostTotal').textContent = money(cost);
    document.getElementById('intakeFeeRate').textContent = (info.fee * 100).toFixed(1).replace(/\.0$/, '') + '%';
    document.getElementById('intakeFee').textContent = money(fee);
    document.getElementById('intakeProfit').textContent = price.value === '' ? '填售价后显示' : money(sale - fee - cost);
    document.getElementById('intakeCostHint').textContent = info.costLabel ? info.costLabel + '随订单入账（¥500 以内自动通过）' : !resources || !canEditResources ? '成本由技术在结算单确认' : (usesProgram ? '程序套餐已含空间与域名，保存时按成本中心现价入账' : (mode.value === 'pending' ? '域名待技术确认，暂不计成本' : (usesDomain && !domain.value ? '选择域名规格后显示标准价' : '最终以保存时成本模板单价为准')));
  }
  business.addEventListener('change', function () { selectedKind = ''; refreshKinds(); update(); });
  [mode, domain, server, program, document.getElementById('intakeOutsource')].forEach(function (el) { if (el) el.addEventListener('change', update); });
  price.addEventListener('input', update);
  document.getElementById('intakeDirectCost').addEventListener('input', update);
  kind.addEventListener('change', update);
  refreshKinds();
  update();

  // 订单号即时查询：已建档 → 提示打开原单；店铺 / ETMLL 流水 → 只填空白字段。
  var orderNo = document.getElementById('intakeOrderNo');
  var box = document.getElementById('intakeLookup');
  var timer = null, lastQuery = '';
  function setIfEmpty(el, value) { if (el && value !== null && value !== undefined && value !== '' && el.value === '') { el.value = value; el.classList.add('project-autofilled'); } }
  function escapeHtml(s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function lookup() {
    var value = orderNo.value.trim();
    if (value === lastQuery) return;
    lastQuery = value;
    if (value.length < 4) { box.hidden = true; return; }
    fetch('<?php echo BASE_URL; ?>/project/lookup.php?order_no=' + encodeURIComponent(value), {credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (data) {
      if (!data.ok || orderNo.value.trim() !== value) return;
      var html = '';
      if (data.project) {
        html = data.project.id ? '<i class="fas fa-folder-open mr-1"></i> 此订单号已建档（' + escapeHtml(data.project.business) + '）。<a class="font-weight-bold" href="' + escapeHtml(data.project.url) + '">打开原结算单继续补充</a>，无需重复录入。' : '<i class="fas fa-lock mr-1"></i> 此订单号已由同事建档。请让对方在结算单中关联你，或联系财务。';
        box.className = 'project-lookup is-existing';
      } else if (data.shop_orders.length === 1) {
        var m = data.shop_orders[0];
        var shop = document.getElementById('intakeShop');
        if (shop.value === '' && Array.prototype.some.call(shop.options, function (o) { return o.value === m.shop; })) { shop.value = m.shop; shop.classList.add('project-autofilled'); }
        setIfEmpty(document.getElementById('intakeNickname'), m.nickname);
        setIfEmpty(price, m.price);
        setIfEmpty(document.getElementById('intakeTrade'), m.status);
        if (m.date) { var d = document.getElementById('intakeDate'); if (d.value === '<?php echo date('Y-m-d'); ?>') { d.value = m.date; d.classList.add('project-autofilled'); } }
        html = '<i class="fas fa-magic mr-1"></i> 已从' + escapeHtml(m.source) + '流水带出：' + escapeHtml(m.shop) + (m.price !== null ? ' · 售价 ¥' + Number(m.price).toFixed(2) : '') + (m.status ? ' · ' + escapeHtml(m.status) : '') + '。只填了空白栏，可自行修改。' + (m.refund ? '<div class="text-danger mt-1"><i class="fas fa-exclamation-triangle mr-1"></i>店铺流水显示此单有退款 / 交易关闭' + (m.refund_amount ? '（¥' + Number(m.refund_amount).toFixed(2) + '）' : '') + '，请核对后再录入。</div>' : '');
        box.className = 'project-lookup is-found';
        update();
      } else if (data.shop_orders.length > 1) {
        html = '<i class="fas fa-random mr-1"></i> 店铺流水中有 ' + data.shop_orders.length + ' 个店铺使用同一订单号（' + data.shop_orders.map(function (m) { return escapeHtml(m.shop); }).join('、') + '），请手动选择店铺，系统不自动猜测。';
        box.className = 'project-lookup is-warning';
      } else {
        html = '<i class="fas fa-clock mr-1"></i> 店铺订单里暂未找到此单号。可以先保存，之后上传店铺订单或 ETMLL 同步时会自动补齐售价等空白信息。';
        box.className = 'project-lookup';
      }
      box.innerHTML = html;
      box.hidden = false;
    }).catch(function () {});
  }
  orderNo.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(lookup, 450); });
  orderNo.addEventListener('blur', lookup);
  if (orderNo.value) lookup();
})();
