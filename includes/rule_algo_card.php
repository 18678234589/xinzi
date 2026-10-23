<?php
// 首页“我的分成算法”：每位合作人员只看到自己的按单分成算法、固定服务费 / 全勤奖 / 固定补助和月度规则；
// 算法不对可直接提交更正（填入认为正确的数值、详细说明，可选上传订单算法示例），管理员和财务审核。
// 用法：在已定义 $actor（ps_actor() 结果）的页面里 include 本文件。管理员看到的是待审核入口。
require_once __DIR__ . '/ProjectRuleAlgo.php';
if (!function_exists('ra_f')) {
    /** 公式文字：转义后把运算符淡化，数字更突出。 */
    function ra_f($text) { return preg_replace('/([+−×÷])/u', '<span class="op">$1</span>', e((string)$text)); }
    function ra_asset($rel) { $f = dirname(__DIR__) . '/assets/' . $rel; return e(BASE_URL . '/assets/' . $rel . '?v=' . (is_file($f) ? filemtime($f) : 1)); }
}
$raActor = $actor ?? null;
if ($raActor):
    $raAdmin = ($raActor['role'] ?? '') === 'finance';
    try {
        if ($raAdmin) { $raPending = pra_pending_count(); $raData = $raFixed = $raMine = []; }
        else { $raData = pra_my_algorithms($raActor); $raFixed = pra_my_fixed_income($raActor); $raMine = pra_my_requests($raActor); $raPending = count(array_filter($raMine, function ($r) { return $r['status'] === 'pending'; })); }
        $raOk = true;
    } catch (Throwable $e) { $raOk = false; }
endif;
if (!empty($raOk)):
?>
<link rel="stylesheet" href="<?php echo ra_asset('css/rule_algo.css'); ?>">
<?php if ($raAdmin): ?>
<section class="ra" aria-label="分成算法更正申请">
  <div class="ra-head"><div class="ra-title"><span class="ra-badge"><i class="fas fa-calculator"></i></span><div><h3>分成算法更正申请</h3><p><?php echo $raPending ? '有 ' . (int)$raPending . ' 条合作人员提交的算法更正等待审核' : '合作人员认为自己的算法不对时，会在这里提交更正，目前没有待审核的'; ?></p></div></div>
    <div class="ra-btn-row"><a class="ra-btn<?php echo $raPending ? '' : ' ghost'; ?>" href="<?php echo BASE_URL; ?>/project/rule_requests.php">去审核<?php echo $raPending ? '（' . (int)$raPending . '）' : ''; ?> →</a> <a class="ra-btn ghost" href="<?php echo BASE_URL; ?>/project/preview_as.php"><i class="fas fa-user-secret"></i> 员工视角预览</a></div></div>
</section>
<?php else:
    $raTotalRules = 0; foreach ($raData as $b) $raTotalRules += $b['rule_count'] + count($b['monthly']);
?>
<section class="ra" id="raCard" aria-label="我的分成算法">
  <div class="ra-head">
    <div class="ra-title"><span class="ra-badge"><i class="fas fa-calculator"></i></span><div><h3>我的分成算法</h3><p>只显示和你有关的 · 共 <?php echo count($raData); ?> 项业务、<?php echo (int)$raTotalRules; ?> 条规则<?php echo $raFixed ? '、' . count($raFixed) . ' 项每月固定收入' : ''; ?> · 不对可以直接提更正</p></div></div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap"><button type="button" class="ra-btn" data-ra-open="<?php echo $raFixed ? 'ra-pane-fixed' : ($raData ? 'ra-pane-b0' : 'ra-pane-req'); ?>"><i class="fas fa-expand-alt"></i> 查看全部算法</button><button type="button" class="ra-btn ghost" data-ra-open="ra-pane-req"><i class="far fa-comment-dots"></i> 我的更正<?php echo $raPending ? '（' . (int)$raPending . ' 待审）' : ''; ?></button></div>
  </div>
  <?php if ($raFixed): ?>
  <div class="ra-fixed">
    <?php foreach ($raFixed as $m): ?><button type="button" class="ra-money<?php echo $m['rule_type'] === 'base_fee' ? '' : ' alt'; ?>" data-ra-open="ra-pane-fixed" style="text-align:left;font:inherit;cursor:pointer"><small><?php echo e($m['rule_type'] === 'base_fee' ? '固定服务费' : $m['type_label']); ?></small><b><?php echo e($m['text']['amount'] !== null ? pra_money($m['text']['amount']) : '—'); ?><i>/ 月</i></b></button><?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php if ($raData): ?>
  <div class="ra-grid">
    <?php foreach ($raData as $bi => $b): $shown = 0; ?>
    <button type="button" class="ra-tile" data-ra-open="ra-pane-b<?php echo $bi; ?>">
      <h4><?php echo e($b['business']); ?><span><?php echo (int)($b['rule_count'] + count($b['monthly'])); ?> 条</span></h4>
      <div class="ra-chips"><?php foreach ($b['groups'] as $g): ?><span class="ra-chip"><?php echo e($g['label'] . ($g['roles'] ? ' · ' . implode('/', $g['roles']) : '')); ?></span><?php endforeach; ?></div>
      <?php foreach ($b['groups'] as $g) foreach ($g['rules'] as $r) { if ($shown >= 2 || $r['formula']['headline'] === '') continue; $shown++; ?>
        <div class="ra-line"><em><?php echo e($r['order_kind'] === '*' ? '全部类型' : $r['order_kind']); ?></em><span><?php echo ra_f($r['formula']['headline']); ?></span></div>
      <?php } ?>
      <?php if ($b['rule_count'] > $shown): ?><span class="ra-more">还有 <?php echo (int)($b['rule_count'] - $shown); ?> 条按单规则<?php echo $b['monthly'] ? '、' . count($b['monthly']) . ' 条月度规则' : ''; ?> →</span><?php elseif ($b['monthly']): ?><span class="ra-more">另有 <?php echo count($b['monthly']); ?> 条月度规则 →</span><?php endif; ?>
    </button>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div style="padding:.3rem 1.3rem 1.2rem"><div class="ra-empty"><i class="far fa-folder-open"></i><h3>还没有匹配到你的分成算法</h3><p>可能是账号还没分配业务，或还没有参与过订单。如果你有自己的算法，可以告诉我们。</p><button type="button" class="ra-btn ghost js-wrong" data-kind="other" data-id="0" data-business="" data-title="规则缺失 / 其他" data-headline="">提交我的算法</button></div></div>
  <?php endif; ?>
  <div class="ra-foot"><span>数据来自“规则中心”，新订单按生效日期最新版本计算；已审核订单不受影响。</span><span><i class="far fa-lightbulb"></i> 算法有出入？点规则右侧“算法不对？”</span></div>
</section>

<div class="ra-mask" id="raMask"></div>
<div class="ra-modal<?php echo !empty($raPreview) ? ' ra-preview' : ''; ?>" id="raModal"<?php echo !empty($raPreview) ? ' data-preview="1"' : ''; ?> role="dialog" aria-modal="true" aria-label="我的分成算法" data-api="<?php echo e(BASE_URL . '/project/rule_request_api.php'); ?>" data-csrf="<?php echo e(ps_csrf_token()); ?>">
  <aside class="ra-side"><h3><i class="fas fa-calculator" style="color:var(--accent)"></i> 我的分成算法</h3>
    <?php if ($raFixed): ?><button type="button" class="ra-nav" data-ra-pane="ra-pane-fixed">每月固定收入<small>固定服务费 · 全勤奖 · 补助</small></button><?php endif; ?>
    <?php foreach ($raData as $bi => $b): ?><button type="button" class="ra-nav" data-ra-pane="ra-pane-b<?php echo $bi; ?>"><?php echo e($b['business']); ?><small><?php echo count($b['groups']) ? e(implode(' · ', array_map(function ($g) { return $g['label']; }, $b['groups']))) : '月度规则'; ?></small></button><?php endforeach; ?>
    <button type="button" class="ra-nav" data-ra-pane="ra-pane-req">我的更正申请<?php echo $raPending ? '<b>' . (int)$raPending . '</b>' : ''; ?><small>提交记录与审核结果</small></button>
  </aside>
  <div class="ra-main">
    <button type="button" class="ra-x" aria-label="关闭" data-ra-close>✕</button>

    <?php if ($raFixed): ?>
    <section class="ra-pane" id="ra-pane-fixed"><h2>每月固定收入</h2><p class="sub">这些是每月固定发放的项目，只显示你自己的金额。</p>
      <?php foreach ($raFixed as $m): ?>
      <div class="ra-rule"><div class="top"><span class="ra-kind m"><?php echo e($m['rule_type'] === 'base_fee' ? '固定服务费' : $m['type_label']); ?></span><button type="button" class="ra-btn ghost sm js-wrong" data-kind="monthly" data-id="<?php echo (int)$m['id']; ?>" data-business="" data-title="<?php echo e('月度规则 · ' . $m['name']); ?>" data-headline="<?php echo e($m['text']['headline']); ?>"><i class="far fa-edit"></i> 算法不对？</button></div>
        <div class="ra-formula"><?php echo ra_f($m['text']['headline']); ?></div>
        <?php if ($m['text']['details']): ?><ul class="ra-details"><?php foreach ($m['text']['details'] as $d): ?><li><?php echo e($d); ?></li><?php endforeach; ?></ul><?php endif; ?>
        <div class="ra-note">规则：<?php echo e($m['name']); ?> · 自 <?php echo e($m['effective_from']); ?> 起生效</div></div>
      <?php endforeach; ?></section>
    <?php endif; ?>

    <?php foreach ($raData as $bi => $b): ?>
    <section class="ra-pane" id="ra-pane-b<?php echo $bi; ?>"><h2><?php echo e($b['business']); ?></h2><p class="sub">按你在这个业务里的岗位列出，订单类型不同算法可能不同。</p>
      <?php foreach ($b['groups'] as $g): ?>
        <div class="ra-group"><?php echo e($g['label']); ?><?php echo $g['roles'] ? ' · ' . e(implode(' / ', $g['roles'])) : ''; ?></div>
        <?php if (!$g['rules']): ?><div class="ra-empty" style="padding:1.2rem"><h3 style="font-size:.95rem">规则中心里还没有这个岗位的按单算法</h3><button type="button" class="ra-btn ghost sm js-wrong" data-kind="other" data-id="0" data-business="<?php echo e($b['business']); ?>" data-title="<?php echo e($b['business'] . ' · ' . $g['label'] . ' · 规则缺失'); ?>" data-headline="">告诉我们应该怎么算</button></div><?php endif; ?>
        <?php foreach ($g['rules'] as $r): $f = $r['formula']; ?>
        <div class="ra-rule"><div class="top"><span class="ra-kind"><?php echo e($r['order_kind'] === '*' ? '所有订单类型' : $r['order_kind']); ?><?php echo !in_array($r['role_name'], ['*', ''], true) ? ' · ' . e($r['role_name']) : ''; ?></span>
          <button type="button" class="ra-btn ghost sm js-wrong" data-kind="order" data-id="<?php echo (int)$r['id']; ?>" data-business="<?php echo e($b['business']); ?>" data-title="<?php echo e($b['business'] . ' · ' . $g['label'] . ' · ' . ($r['order_kind'] === '*' ? '所有订单类型' : $r['order_kind'])); ?>" data-headline="<?php echo e($f['headline']); ?>" data-rate="<?php echo e(rtrim(rtrim(number_format((float)$r['rate'] * 100, 4, '.', ''), '0'), '.')); ?>" data-fee="<?php echo ($r['service_fee_rate'] === null || $r['service_fee_rate'] === '') ? '' : e(rtrim(rtrim(number_format((float)$r['service_fee_rate'] * 100, 4, '.', ''), '0'), '.')); ?>" data-subsidy="<?php echo e(rtrim(rtrim(number_format((float)$r['per_order_subsidy'], 2, '.', ''), '0'), '.')); ?>" data-min="<?php echo (float)$r['min_contract_amount'] > 0 ? e(rtrim(rtrim(number_format((float)$r['min_contract_amount'], 2, '.', ''), '0'), '.')) : ''; ?>"><i class="far fa-edit"></i> 算法不对？</button></div>
          <div class="ra-formula"><?php echo $f['headline'] !== '' ? ra_f($f['headline']) : '—'; ?></div>
          <?php if ($f['details']): ?><ul class="ra-details"><?php foreach ($f['details'] as $d): ?><li><?php echo e($d); ?></li><?php endforeach; ?></ul><?php endif; ?>
          <?php if ((string)$r['note'] !== ''): ?><div class="ra-note"><?php echo e($r['note']); ?> · 自 <?php echo e($r['effective_from']); ?> 起生效</div><?php endif; ?></div>
        <?php endforeach; ?>
      <?php endforeach; ?>
      <?php if ($b['monthly']): ?><div class="ra-group">月度规则</div>
        <?php foreach ($b['monthly'] as $m): ?>
        <div class="ra-rule"><div class="top"><span class="ra-kind m"><?php echo e($m['type_label']); ?></span><button type="button" class="ra-btn ghost sm js-wrong" data-kind="monthly" data-id="<?php echo (int)$m['id']; ?>" data-business="<?php echo e($b['business']); ?>" data-title="<?php echo e('月度规则 · ' . $m['name']); ?>" data-headline="<?php echo e($m['text']['headline']); ?>"><i class="far fa-edit"></i> 算法不对？</button></div>
          <div class="ra-formula" style="font-size:1rem"><strong><?php echo e($m['name']); ?></strong><br><?php echo ra_f($m['text']['headline']); ?></div>
          <?php if ($m['text']['details']): ?><ul class="ra-details"><?php foreach ($m['text']['details'] as $d): ?><li><?php echo e($d); ?></li><?php endforeach; ?></ul><?php endif; ?></div>
        <?php endforeach; ?><?php endif; ?>
      <p style="margin-top:1.2rem"><button type="button" class="ra-btn ghost sm js-wrong" data-kind="other" data-id="0" data-business="<?php echo e($b['business']); ?>" data-title="<?php echo e($b['business'] . ' · 规则缺失 / 其他'); ?>" data-headline="">这里没有我的算法 / 还有别的规则</button></p></section>
    <?php endforeach; ?>

    <section class="ra-pane" id="ra-pane-req"><h2>我的更正申请</h2><p class="sub">提交后管理员和财务会审核，结果也会发到你的站内信。</p>
      <?php if (!$raMine): ?><div class="ra-empty"><i class="far fa-paper-plane"></i><h3>还没有提交过更正</h3><p>觉得哪条算法不对，在规则右侧点“算法不对？”就行。</p></div><?php endif; ?>
      <?php $raLabel = ['pending' => '待审核', 'resolved' => '已采纳', 'rejected' => '未采纳']; foreach ($raMine as $r): ?>
      <div class="ra-reqcard"><div style="display:flex;justify-content:space-between;gap:.5rem;flex-wrap:wrap"><strong><?php echo e($r['rule_title']); ?></strong><span class="ra-status s-<?php echo e($r['status']); ?>"><?php echo e($raLabel[$r['status']]); ?></span></div>
        <div class="ra-meta"><?php echo e(substr($r['created_at'], 0, 16)); ?><?php foreach ([['proposed_rate', '比例', '%'], ['proposed_fee_rate', '服务费率', '%'], ['proposed_subsidy', '每单补助', '元'], ['proposed_min', '起算售价', '元']] as [$k, $n, $u]) if ($r[$k] !== null) echo ' · ' . e($n . ' ' . rtrim(rtrim(number_format((float)$r[$k], 4, '.', ''), '0'), '.') . $u); ?><?php echo $r['example_stored'] !== '' ? ' · 已附示例' : ''; ?></div>
        <p><?php echo e($r['detail']); ?></p>
        <?php if ($r['status'] !== 'pending'): ?><div class="ra-reply">处理回复（<?php echo e($r['handler_name']); ?>）：<?php echo e($r['handle_note'] ?: '已采纳，财务会在规则中心按新版本调整，之后新订单按新算法计算。'); ?></div><?php endif; ?></div>
      <?php endforeach; ?></section>

    <section class="ra-pane" id="ra-pane-form"><button type="button" class="ra-back" id="raBack"><i class="fas fa-arrow-left"></i> 返回</button>
      <h2>提交算法更正</h2><p class="sub">把你认为正确的写下来，管理员和财务会核对；不确定的数值可以留空，在说明里讲清楚。</p>
      <div class="ra-target"><div class="ra-meta" id="raTargetTitle"></div><div class="ra-formula" id="raTargetFormula"></div></div>
      <div class="ra-form">
        <div id="raNumsWrap"><label class="l">你认为正确的数值<span class="hint">只填需要改的，其余留空</span></label>
          <div class="ra-nums">
            <div class="ra-num"><input type="number" step="0.01" min="0" max="100" id="raRate" placeholder="比例"><span>%</span></div>
            <div class="ra-num"><input type="number" step="0.01" min="0" max="100" id="raFee" placeholder="服务费率"><span>%</span></div>
            <div class="ra-num"><input type="number" step="0.01" min="0" id="raSubsidy" placeholder="每单补助"><span>元</span></div>
            <div class="ra-num"><input type="number" step="0.01" min="0" id="raMin" placeholder="起算售价"><span>元</span></div>
          </div><div class="ra-meta" id="raCurrent" style="margin-top:.4rem"></div></div>
        <label class="l" for="raText">其他修改<span class="hint">可选，例如“二次备案每单再加 5 元”“淘宝订单额外 0.5 元”</span></label><input type="text" id="raText" maxlength="500">
        <label class="l" for="raDetail">详细说明<span class="hint">必填：哪里不对、你的算法是怎样算出来的</span></label><textarea id="raDetail" maxlength="3000" placeholder="例如：我 9 月有 3 单备案，售价 120、成本 60，按我的算法应得 (120−60)×20%+1 = 13 元，但系统预计只有 12 元……"></textarea>
        <label class="l">订单算法示例<span class="hint">可选：上传一张截图 / PDF / Excel / Word，帮助核对（≤ 8 MB）</span></label>
        <div class="ra-drop" id="raDrop" tabindex="0" role="button" aria-label="选择或拖入示例文件"><i class="fas fa-cloud-upload-alt"></i><span id="raDropText">拖入文件，或点这里选择</span><input type="file" id="raFile" accept=".png,.jpg,.jpeg,.webp,.pdf,.xls,.xlsx,.doc,.docx"></div>
        <div class="ra-msg" id="raMsg" role="status"></div>
        <div class="ra-actions"><button type="button" class="ra-btn" id="raSubmit"><i class="fas fa-paper-plane"></i> 提交给管理员和财务</button><button type="button" class="ra-btn ghost" id="raCancel">取消</button></div>
      </div>
    </section>
  </div>
</div>
<script src="<?php echo ra_asset('js/rule_algo.js'); ?>"></script>
<?php endif; ?>
<?php endif; ?>
