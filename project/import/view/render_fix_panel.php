<?php
// 待处理清单：上传预览里有问题的行，用清爽的卡片集中展示——能在线补的（订单号 / 对公交易号 / 日期 / 接单技术）直接在卡片里填，
// 其余问题用一句话说明原因和处理办法。输入框名称沿用 fix_*[行号]，点“应用补填并重新核对”后重新核对，已通过的行不受影响。
$fxRows = array_values(array_filter($preview, function ($r) use ($isSkipRow) { return empty($r['base_valid']) && !$isSkipRow($r); }));
if ($fxRows):
    $fxInline = []; $fxOther = [];
    foreach ($fxRows as $r) {
        $needNo = empty($r['order_no']); $needDate = empty($r['order_date']); $needTech = !empty($r['need_technical']);
        if ($needNo || $needDate || $needTech || !empty($r['can_join'])) $fxInline[] = $r; else $fxOther[] = $r;
    }
    $fxTechChoices = null;
?>
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/import_fix.css?v=20261009.1">
<div class="fx-panel" id="importFixPanel">
  <div class="fx-title"><span class="fx-badge"><?php echo count($fxRows); ?></span><div><h5>需要处理的订单</h5><p>红色行不会入账。能在线补的直接填在下面、点“应用补填并重新核对”；已通过的行可以先导入，不受影响。</p></div></div>
  <?php foreach ($fxInline as $r): $ln = (int)$r['line']; $isPublic = !empty($r['public_transfer']); ?>
  <div class="fx-card">
    <div class="fx-head"><b>第 <?php echo $ln % 10000; ?> 行</b><span><?php
        $bits = array_filter([$r['payment_nickname'] ?? '', $r['project_type'] ?? '', ($r['contract_amount'] ?? '') !== '' ? '¥' . $r['contract_amount'] : '']);
        echo e(implode(' · ', $bits) ?: '这一行'); ?><?php if (!empty($r['sheet']) && count($previewSheets) > 1): ?>　<small>【<?php echo e($r['sheet']); ?>】</small><?php endif; ?></span></div>
    <div class="fx-msg"><?php echo e($r['error']); ?></div>
    <div class="fx-controls">
      <?php if (empty($r['order_no'])): ?>
        <?php if (!$isPublic): ?><label class="fx-f"><span>店铺订单编号</span><input name="fix_order_no[<?php echo $ln; ?>]" maxlength="100" placeholder="有则填"></label><?php endif; ?>
        <label class="fx-f"><span><?php echo $isPublic ? '对公交易号（对公收款无需订单号）' : '或：微信交易流水号 / 支付订单号'; ?></span><input name="fix_payment_reference[<?php echo $ln; ?>]" maxlength="200" placeholder="<?php echo $isPublic ? '银行流水 / 对公交易号' : '没有订单号时填这个'; ?>"></label>
      <?php endif; ?>
      <?php if (empty($r['order_date'])): ?>
        <label class="fx-f"><span>订单日期<?php echo isset($suggestedDates[$ln]) ? '（已带入建议，请核对）' : ''; ?></span><input type="date" name="fix_date[<?php echo $ln; ?>]" value="<?php echo e($suggestedDates[$ln] ?? ''); ?>"></label>
      <?php endif; ?>
      <?php if (!empty($r['can_join'])): ?>
        <label class="fx-f" style="flex:1 1 100%"><span><input type="checkbox" name="fix_join[<?php echo $ln; ?>]" value="1"> 我确实参与了这单（原技术：<?php echo e($r['can_join']['names']); ?>）：加入为共同技术，技术分成按人数均分，财务审核订单时核对</span></label>
      <?php endif; ?>
      <?php if (!empty($r['need_technical'])): if ($fxTechChoices === null) $fxTechChoices = poj_technician_choices($selectedBusiness); if (!isset($fxOutsource)) $fxOutsource = null; ?>
        <label class="fx-f"><span>接单技术</span><select name="fix_technical[<?php echo $ln; ?>]"><option value="">选择接单技术…</option><?php foreach ($fxTechChoices as $techId => $techName): ?><option value="<?php echo (int)$techId; ?>"><?php echo e($techName); ?></option><?php endforeach; ?><?php if ($fxOutsource === null) { ps_ensure_outsource_templates(); $fxOutsource = db()->prepare("SELECT id FROM project_cost_templates WHERE category='outsourcing' AND is_active=1 AND requires_proof=0 AND (business_scope='' OR business_scope=?) ORDER BY business_scope DESC,id DESC LIMIT 1"); $fxOutsource->execute([ps_business_normalize($selectedBusiness)]); $fxOutsource = (int)$fxOutsource->fetchColumn(); } if ($fxOutsource): ?><option value="outsource">外包技术（外部开发，不走本公司技术）</option><?php endif; ?></select></label>
        <label class="fx-f"><span>外包费用（元，选“外包技术”时填）</span><input type="number" min="0.01" step="0.01" name="fix_outsource_amount[<?php echo $ln; ?>]" placeholder="付给外包的实际费用"></label>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if ($fxOther): ?>
  <details class="fx-other" <?php echo $fxInline ? '' : 'open'; ?>>
    <summary>其他需要处理的 <?php echo count($fxOther); ?> 行（需改表格、或财务核对）</summary>
    <?php foreach ($fxOther as $r): $guide = ps_import_fix_guide($r['error'], $selectedBusiness); ?>
    <div class="fx-item"><b>第 <?php echo (int)$r['line'] % 10000; ?> 行</b><span class="fx-no"><?php echo e($r['order_no'] ?: '—'); ?></span><div class="fx-msg"><?php echo e($r['error']); ?></div><?php if ($guide): ?><div class="fx-how">处理方法：<?php echo e($guide['how']); ?></div><?php endif; ?></div>
    <?php endforeach; ?>
  </details>
  <?php endif; ?>
  <?php if ($fxInline): ?><div class="fx-foot"><button class="btn btn-primary" type="submit" name="action" value="repair_preview">应用补填并重新核对</button><span>填好后点这里，系统会重新核对这些行。</span></div><?php endif; ?>
</div>
<?php endif; ?>
