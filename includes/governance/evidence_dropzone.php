<?php
// 举证图片上传区（建议 / Bug / 脑洞等表单共用）：点击选择、拖入、Ctrl+V 粘贴截图，可多张，带缩略图预览。
// 用法：$dzId = '唯一id'; include 本文件。表单须 enctype="multipart/form-data"；后端用 pg_store_evidence_many() 保存。
$dzId = $dzId ?? 'evidenceFiles';
$dzJs = dirname(__DIR__, 2) . '/assets/js/evidence_drop.js';
?>
<div class="contribution-drop evidence-drop" data-drop>
  <input class="contribution-file" type="file" id="<?php echo e($dzId); ?>" name="evidence_files[]" accept=".png,.jpg,.jpeg,.webp,.pdf" multiple>
  <label for="<?php echo e($dzId); ?>"><i class="fas fa-image"></i> 上传图片 / 截图（可选）：点击选择、拖入，或在表单里按 <kbd>Ctrl</kbd>+<kbd>V</kbd> 粘贴截图</label>
  <div class="evidence-thumbs" data-thumbs></div>
  <div class="contribution-picked" data-picked></div>
  <small>PNG / JPG / WebP / PDF，可多张，每个不超过 8 MB；保存在网站目录外，只有本栏目成员能查看。</small>
</div>
<?php if (empty($GLOBALS['pg_evidence_drop_js'])): $GLOBALS['pg_evidence_drop_js'] = true; ?>
<script src="<?php echo e(BASE_URL . '/assets/js/evidence_drop.js?v=' . (is_file($dzJs) ? filemtime($dzJs) : 1)); ?>" defer></script>
<?php endif; ?>
