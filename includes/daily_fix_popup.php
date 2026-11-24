<?php
// 每日“待完善订单”弹窗（员工，非财务）：页面加载后问接口今天要不要弹，要弹就放一个大窗口，里面直接更正。见 ProjectDailyFix.php。
if (!empty($current_admin) || empty($project_staff['employee_id']) || empty($_SESSION['project_user_id']) || (($project_staff['role'] ?? '') === 'finance')) return;
$pdfUrl = BASE_URL . '/project/daily_fix.php';
?>
<div class="modal fade" id="pdfModal" tabindex="-1" role="dialog" aria-modal="true"><div class="modal-dialog modal-xl modal-dialog-scrollable" role="document"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="fas fa-clipboard-check mr-2"></i>你有订单资料没填完整，请更正（<span id="pdfCount">0</span>）</h5><button type="button" class="close" data-dismiss="modal" aria-label="关闭"><span>&times;</span></button></div>
  <div class="modal-body" id="pdfBody"></div>
  <div class="modal-footer"><span class="small text-muted mr-auto">没改好的，明天第一次打开系统还会提醒。</span><button type="button" class="btn btn-secondary" data-dismiss="modal">稍后处理</button></div>
</div></div></div>
<script>
(function () {
  var url = <?php echo json_encode($pdfUrl); ?>;
  function render(d) {
    if (!d || !d.show) return;
    $('#pdfBody').html(d.html); $('#pdfCount').text(d.count || 0); $('#pdfModal').modal('show');
  }
  window.openDailyFix = function () { $.getJSON(url + '?manual=1').done(function (d) { render(d); }); };
  $(function () { $.getJSON(url + '?auto=1').done(render); });
  $(document).on('click', '#pdfBody .pdf-fill', function () { $(this).closest('form').find('[name=amount]').val($(this).data('v')); });
  $(document).on('submit', '#pdfBody form', function (e) {
    e.preventDefault();
    var f = $(this), card = f.closest('.pdf-card'), msg = card.find('.pdf-msg'), btn = f.find('button[type=submit],button:not([type])').prop('disabled', true);
    $.post(url, f.serialize(), null, 'json').done(function (r) {
      msg.removeClass('text-danger text-success').addClass(r.ok ? 'text-success' : 'text-danger').text((r.ok ? '✓ ' : '') + r.msg);
      if (r.ok) { card.find('form').remove(); card.css('opacity', .6); $('#pdfCount').text(r.left); if (!r.left) setTimeout(function () { $('#pdfModal').modal('hide'); }, 900); } else btn.prop('disabled', false);
    }).fail(function () { msg.addClass('text-danger').text('保存失败，请稍后再试'); btn.prop('disabled', false); });
  });
})();
</script>
