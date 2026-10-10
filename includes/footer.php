</div><!-- /.main-content -->

<script src="<?php echo BASE_URL; ?>/assets/lib/jquery/jquery.min.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/lib/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
// 手机端：列表表格按表头自动加标签，窄屏下每行显示成卡片（样式见 theme.css .project-stack-table）
(function () {
  document.querySelectorAll('table.project-order-table, table.project-stack-table').forEach(function (table) {
    table.classList.add('project-stack-table');
    var heads = Array.prototype.map.call(table.querySelectorAll('thead th'), function (th) { return th.textContent.trim(); });
    Array.prototype.forEach.call(table.tBodies, function (body) {
      Array.prototype.forEach.call(body.rows, function (tr) {
        Array.prototype.forEach.call(tr.cells, function (td, i) { if (!td.hasAttribute('data-label') && heads[i] && td.colSpan === 1) td.setAttribute('data-label', heads[i]); });
      });
    });
  });
})();
</script>
<?php if (isset($current_admin, $project_staff) || !empty($current_admin) || !empty($project_staff)): ?>
<script src="<?php echo BASE_URL; ?>/assets/js/knowledge.js?v=20261002.7" data-keywords-url="<?php echo BASE_URL; ?>/project/knowledge_keywords.php" <?php echo ($_rel ?? '') === 'project/vault.php' ? 'data-skip-keywords="1"' : ''; ?> defer></script>
<?php endif; ?>
<?php include __DIR__ . '/announcement_popup.php'; ?>
</body>
</html>
