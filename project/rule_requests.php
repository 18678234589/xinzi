<?php
require_once __DIR__ . '/../includes/ProjectRuleAlgo.php';
// 分成算法更正申请：管理员与财务审核。采纳只记录结论并回复申请人；规则本身由财务在“规则中心”按新版本修改。
$actor = ps_require_actor();
if (($actor['role'] ?? '') !== 'finance') { http_response_code(403); exit('仅管理员或财务可以审核分成算法更正申请'); }
pra_ensure();
$status = (string)($_GET['status'] ?? 'pending'); if (!in_array($status, ['pending', 'resolved', 'rejected', 'all'], true)) $status = 'pending';
$rows = pra_requests($status);
$counts = [];
foreach (db()->query('SELECT status,COUNT(*) n FROM project_rule_requests GROUP BY status')->fetchAll() as $c) $counts[$c['status']] = (int)$c['n'];
$label = ['pending' => '待审核', 'resolved' => '已采纳', 'rejected' => '未采纳'];
$page_title = '分成算法更正申请';
include __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/correction_tabs.php';
?>
<link rel="stylesheet" href="<?php echo e(BASE_URL . '/assets/css/rule_algo.css?v=' . (is_file(dirname(__DIR__) . '/assets/css/rule_algo.css') ? filemtime(dirname(__DIR__) . '/assets/css/rule_algo.css') : 1)); ?>">
<div class="ra-page">
  <?php pc_tabs('rule'); ?>
  <div class="ra-page-head"><div><h2>分成算法更正</h2><p>合作人员认为自己的分成算法不对时提交，管理员和财务审核。采纳后请在<a class="ra-link" href="<?php echo BASE_URL; ?>/project/rules.php">规则中心</a>按新版本修改，已审核订单不受影响。</p></div>
    <nav class="ra-tabs" aria-label="状态"><?php foreach (['pending' => '待审核', 'resolved' => '已采纳', 'rejected' => '未采纳', 'all' => '全部'] as $k => $t): ?><a class="ra-tab<?php echo $status === $k ? ' is-on' : ''; ?>" href="?status=<?php echo $k; ?>"><?php echo $t; ?><?php if ($k !== 'all' && !empty($counts[$k])): ?> <b><?php echo $counts[$k]; ?></b><?php endif; ?></a><?php endforeach; ?></nav></div>
  <?php if (!$rows): ?><div class="ra-empty"><i class="far fa-check-circle"></i><h3>没有<?php echo $status === 'pending' ? '待审核的' : ''; ?>申请</h3><p>合作人员在首页“我的分成算法”里提交的更正会出现在这里。</p></div><?php endif; ?>
  <?php foreach ($rows as $r): $snap = $r['rule_snapshot'] ? json_decode($r['rule_snapshot'], true) : null; ?>
  <article class="ra-req" id="req-<?php echo (int)$r['id']; ?>" data-id="<?php echo (int)$r['id']; ?>">
    <header><div><span class="ra-status s-<?php echo e($r['status']); ?>"><?php echo e($label[$r['status']]); ?></span> <strong><?php echo e($r['rule_title']); ?></strong></div><span class="ra-meta"><?php echo e($r['applicant_name']); ?> · <?php echo e(substr($r['created_at'], 0, 16)); ?></span></header>
    <div class="ra-diff">
      <div><h4>当前算法</h4><?php if ($snap && $r['rule_kind'] === 'order'): $f = pra_formula($snap, 0); ?><div class="ra-formula"><?php echo e($f['headline'] ?: '—'); ?></div>
        <dl><dt>比例</dt><dd><?php echo e(pra_pct($snap['rate'])); ?></dd><dt>服务费率</dt><dd><?php echo ($snap['service_fee_rate'] === null || $snap['service_fee_rate'] === '') ? '业务默认' : e(pra_pct($snap['service_fee_rate'])); ?></dd><dt>每单补助</dt><dd><?php echo e(pra_money($snap['per_order_subsidy'])); ?></dd><dt>起算售价</dt><dd><?php echo (float)$snap['min_contract_amount'] > 0 ? e(pra_money($snap['min_contract_amount'])) : '无'; ?></dd></dl>
        <?php elseif ($snap): ?><div class="ra-formula"><?php echo e($snap['name'] ?? ''); ?></div><p class="ra-meta"><?php echo e($snap['note'] ?? ''); ?></p><?php else: ?><p class="ra-meta">未关联具体规则（缺失 / 其他）</p><?php endif; ?></div>
      <div><h4>申请人认为正确的</h4>
        <dl><?php foreach ([['proposed_rate', '比例', '%'], ['proposed_fee_rate', '服务费率', '%'], ['proposed_subsidy', '每单补助', '元'], ['proposed_min', '起算售价', '元']] as [$k, $n, $u]): if ($r[$k] === null) continue; ?><dt><?php echo $n; ?></dt><dd class="ra-new"><?php echo e(rtrim(rtrim(number_format((float)$r[$k], 4, '.', ''), '0'), '.') . ($u === '%' ? '%' : ' 元')); ?></dd><?php endforeach; ?><?php if ($r['proposed_text'] !== ''): ?><dt>其他</dt><dd class="ra-new"><?php echo e($r['proposed_text']); ?></dd><?php endif; ?></dl>
        <?php if ($r['proposed_rate'] === null && $r['proposed_fee_rate'] === null && $r['proposed_subsidy'] === null && $r['proposed_min'] === null && $r['proposed_text'] === ''): ?><p class="ra-meta">未填写具体数值，见下方说明</p><?php endif; ?></div>
    </div>
    <div class="ra-detail"><h4>详细说明</h4><p><?php echo nl2br(e($r['detail'])); ?></p>
      <?php if ($r['example_stored'] !== ''): ?><a class="ra-file" href="<?php echo BASE_URL; ?>/project/rule_request_api.php?download=<?php echo (int)$r['id']; ?>" target="_blank" rel="noopener"><i class="fas fa-paperclip"></i> <?php echo e($r['example_name']); ?></a><?php endif; ?></div>
    <?php if ($r['status'] === 'pending'): ?>
    <footer><textarea class="js-note" rows="2" placeholder="处理说明（不采纳时必填；采纳时可写明新算法何时生效）"></textarea>
      <div class="ra-actions"><button type="button" class="ra-btn js-decide" data-d="resolved"><i class="fas fa-check"></i> 采纳</button><button type="button" class="ra-btn ghost js-decide" data-d="rejected">不采纳</button><a class="ra-link" href="<?php echo BASE_URL; ?>/project/rules.php">去规则中心修改 →</a><span class="ra-msg js-msg" role="status"></span></div></footer>
    <?php else: ?><footer class="done"><span>处理人：<?php echo e($r['handler_name']); ?> · <?php echo e(substr((string)$r['handled_at'], 0, 16)); ?></span><?php if ($r['handle_note']): ?><p><?php echo nl2br(e($r['handle_note'])); ?></p><?php endif; ?></footer><?php endif; ?>
  </article>
  <?php endforeach; ?>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var csrf = <?php echo json_encode(ps_csrf_token()); ?>;
  document.querySelectorAll('.js-decide').forEach(function (b) { b.addEventListener('click', function () {
    var box = b.closest('.ra-req'), msg = box.querySelector('.js-msg'); b.disabled = true;
    fetch(<?php echo json_encode(BASE_URL . '/project/rule_request_api.php'); ?>, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({csrf: csrf, action: 'handle', id: box.dataset.id, decision: b.dataset.d, note: box.querySelector('.js-note').value})})
      .then(function (r) { return r.text().then(function (t) { var d; try { d = JSON.parse(t); } catch (e) { throw new Error('服务器返回了非预期内容，请刷新后重试'); } if (d.error) throw new Error(d.error); return d; }); })
      .then(function () { location.reload(); }).catch(function (e) { msg.textContent = e.message; b.disabled = false; });
  }); });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
