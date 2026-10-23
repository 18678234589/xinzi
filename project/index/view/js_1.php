    (function () {
      var role = <?php echo json_encode($actor['role']); ?>, sel = document.getElementById('intakeBusiness'), wrap = document.getElementById('intakeRenewalInfo');
      if (!sel || !wrap) return;
      var web = ['AI网站定制', '网站模板', '网站续费', '网站修改', '备案-提成'];
      var tplRow = document.getElementById('intakeTplRow');
      function sync() {
        var b = sel.value, isWeb = web.indexOf(b) !== -1, isMini = b === '小程序开发', isTpl = b === '网站模板';
        wrap.querySelectorAll('[data-for]').forEach(function (g) {
          var f = g.getAttribute('data-for');
          g.hidden = !(f === 'web' ? isWeb : (f === 'tpl' ? isTpl : isMini));
          if (f === 'tpl') g.querySelectorAll('input').forEach(function (i) { i.disabled = g.hidden; });
        });
        wrap.querySelectorAll('.info-star').forEach(function (s) { var r = s.getAttribute('data-role'); s.hidden = !((isWeb && r === role) || (isMini && r === '*')); });
        document.getElementById('intakeInfoLaterWrap').parentNode.hidden = !(isWeb || isMini) || role === 'finance';
        wrap.hidden = !(isWeb || isMini);
        if (tplRow) {
          tplRow.hidden = !isTpl;
          tplRow.querySelectorAll('select[name]').forEach(function (i) { i.disabled = !isTpl; });
          tplRow.querySelectorAll('[data-tpl-tech]').forEach(function (g) { g.hidden = role === 'customer_service'; });
        }
      }
      sel.addEventListener('change', sync); sync();
      // 网站模板表格同款下拉：编号类型对应订单号 / 流水号栏；程序名称 + 年限自动选成本中心的程序套餐；域名来源“客户提供”＝不计域名成本
      if (!tplRow) return;
      function el(id) { return document.getElementById(id); }
      function fire(node) { node.dispatchEvent(new Event('change', { bubbles: true })); }
      el('tplNumKind').addEventListener('change', function () {
        var v = this.value, no = el('noOrderNo'), shop = el('intakeShop');
        if (!v) return;
        if (/微信|对公/.test(v)) {
          if (no && !no.checked) { no.checked = true; fire(no); }
          if (el('intakePaymentReference')) el('intakePaymentReference').focus();
        } else {
          if (no && no.checked) { no.checked = false; fire(no); }
          if (/旗舰店|专营店/.test(v) && shop && document.querySelector('#intakeShopList option[value="' + v + '"]')) shop.value = v;
          if (el('intakeOrderNo')) el('intakeOrderNo').focus();
        }
      });
      function programSync() {
        var name = el('tplProgram').value, years = parseInt(el('tplYears').value || '1', 10), source = el('tplDomainSource').value;
        var prog = el('intakeProgram'), mode = el('intakeDomainMode'), kind = el('intakeKind'), hint = el('tplHint');
        if (source === '客户提供' && mode && !mode.disabled) { mode.value = 'none'; fire(mode); }
        if (!name || !prog || prog.disabled) return;
        if (['SSL证书', '纯利润', '补差价', '域名注册'].indexOf(name) !== -1 && kind) Array.prototype.forEach.call(kind.options, function (o) { if (o.value === '加购/纯利润') { kind.value = o.value; } });
        var group = Array.prototype.filter.call(prog.querySelectorAll('optgroup'), function (g) { return g.label.replace(/\s+/g, '').toLowerCase() === name.toLowerCase(); })[0];
        var opts = group ? Array.prototype.filter.call(group.querySelectorAll('option'), function (o) { return new RegExp('^' + years + '年').test(((o.text.split(' · ')[1]) || '').trim()); }) : [];
        var pick = opts.filter(function (o) { return source === '客户提供' ? !/空间\+域名/.test(o.text) : /空间\+域名/.test(o.text); })[0] || opts[0] || null;
        prog.value = pick ? pick.value : '0'; fire(prog);
        hint.textContent = pick ? '已按「' + name + ' · ' + years + '年」选好程序套餐，可在下面核对。'
          : (['博山定制', 'AI定制', '华梦定制'].indexOf(name) !== -1 ? '「' + name + '」是定制单：请把上面的业务类型改成 AI网站定制，并选对应订单类型。'
          : (group ? '成本中心没有「' + name + '」的 ' + years + ' 年套餐，请在下面“程序套餐”手动选择。' : '「' + name + '」没有对应的标准程序套餐，成本按实际填写 / 财务核对。'));
      }
      ['tplProgram', 'tplYears', 'tplDomainSource'].forEach(function (id) { el(id).addEventListener('change', programSync); });
    })();
