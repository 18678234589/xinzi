
    (function () {
      var role = <?php echo json_encode($actor['role']); ?>, sel = document.getElementById('intakeBusiness'), wrap = document.getElementById('intakeRenewalInfo');
      if (!sel || !wrap) return;
      var web = ['AI网站定制', '网站模板', '网站续费', '网站修改', '备案-提成'];
      function sync() {
        var b = sel.value, isWeb = web.indexOf(b) !== -1, isMini = b === '小程序开发';
        wrap.querySelectorAll('[data-for]').forEach(function (g) { g.hidden = !(g.getAttribute('data-for') === 'web' ? isWeb : isMini); });
        wrap.querySelectorAll('.info-star').forEach(function (s) { var r = s.getAttribute('data-role'); s.hidden = !((isWeb && r === role) || (isMini && r === '*')); });
        document.getElementById('intakeInfoLaterWrap').parentNode.hidden = !(isWeb || isMini) || role === 'finance';
        wrap.hidden = !(isWeb || isMini);
      }
      sel.addEventListener('change', sync); sync();
    })();
    