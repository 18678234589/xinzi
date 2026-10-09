<?php
/**
 * 首页弹窗：本人提交的订单缺“续费所需资料”时提醒补交，并且可以直接在弹窗里填写保存（不用再跳页面）。
 * - 网站类订单（AI网站定制 / 网站模板 / 网站续费 / 网站修改）：需要域名 + 客户手机号或海外客户微信号；
 * - 微信小程序订单：需要服务器到期日（实际日期，不是按下单日估算的预计日期；永久的写“永久”）。
 * 未提供将不会获得本订单的续费分成。
 * 只提醒本人参与 / 代录的订单，近 90 天内；财务不弹（有专门的待补资料列表）。每次登录弹一次（退出再登录会重新弹），可点“稍后提醒”关闭。
 * 用法：在已定义 $actor（ps_actor() 结果）的页面里 include 本文件。
 */
require_once __DIR__ . '/ProjectRenewalFill.php';

if (!function_exists('rip_missing')) {
/** 返回 null 表示不提醒；否则 ['total'=>N,'rows'=>[prf_missing 的行…]]。 */
function rip_missing($actor, $limit = 8)
{
    try {
        if (!$actor || ($actor['role'] ?? '') === 'finance' || !pr_ready() || pr_scope($actor) === 'none' || empty($actor['employee_id'])) return null;
        $all = prf_missing($actor, 500, 90);
        if (!$all) return null;
        return ['total' => count($all), 'rows' => array_slice($all, 0, (int)$limit)];
    } catch (Throwable $e) {
        return null;
    }
}
}

$rip = rip_missing($actor ?? null, 6);
// 每次登录都提醒：每个登录会话在首页弹一次（退出再登录会重新弹），促进合作人员尽快补录；只是提醒，不影响其他操作。
if ($rip !== null && session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['rip_shown'])) $rip = null;
if ($rip !== null && session_status() === PHP_SESSION_ACTIVE) $_SESSION['rip_shown'] = 1;
if ($rip !== null):
echo prf_assets();
?>
<div class="rf-mask" id="renewalInfoPopup" hidden>
  <div class="rf-modal" role="dialog" aria-modal="true" aria-labelledby="ripTitle" data-rf-endpoint="<?php echo BASE_URL; ?>/project/renewal_fill.php" data-rf-csrf="<?php echo e(ps_csrf_token()); ?>">
    <div class="rf-head">
      <button type="button" class="rf-x" id="ripClose" aria-label="关闭">&times;</button>
      <h5 id="ripTitle">有 <em data-rf-left><?php echo (int)count($rip['rows']); ?></em> 张订单待补续费资料<?php if ($rip['total'] > count($rip['rows'])): ?><small class="text-muted" style="font-weight:400;font-size:.8rem">（本页显示前 <?php echo (int)count($rip['rows']); ?> 张，共 <?php echo (int)$rip['total']; ?> 张）</small><?php endif; ?></h5>
      <p>直接在下面填写、点“保存”即可：网站订单补<b>域名</b>和<b>客户手机号或海外微信号</b>；小程序订单补<b>服务器到期日</b>（永久的勾“永久”）。未补资料将没有该订单的续费分成。</p>
    </div>
    <div class="rf-body">
      <?php foreach ($rip['rows'] as $r) echo prf_card($r); ?>
      <div class="rf-allgood" hidden>🎉 这几张都补齐了，谢谢！</div>
      <?php if ($rip['total'] > count($rip['rows'])): ?><div class="rf-more">还有 <?php echo (int)($rip['total'] - count($rip['rows'])); ?> 张，补完这几张后可到“待补资料”页继续。</div><?php endif; ?>
    </div>
    <div class="rf-foot">
      <small>微信号仅用于人工联系，不发送短信。</small>
      <span><button type="button" class="rf-btn" id="ripLater">稍后提醒</button> <a class="rf-btn" href="<?php echo BASE_URL; ?>/project/batch_fill.php">下载模板批量补</a> <a class="rf-btn primary" href="<?php echo BASE_URL; ?>/project/renewal_gaps.php">全部待补资料</a></span>
    </div>
  </div>
</div>
<script>
(function () {
  var box = document.getElementById('renewalInfoPopup');
  box.hidden = false;
  function close() { box.hidden = true; }
  document.getElementById('ripLater').addEventListener('click', close);
  document.getElementById('ripClose').addEventListener('click', close);
  box.addEventListener('click', function (e) { if (e.target === box) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
})();
</script>
<?php endif; ?>
