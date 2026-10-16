<?php
// 财务页面顶部的常驻提示：自动结算的免审业务订单有待抽查任务时显示（每个财务页面都有，包括工作台首页）。
if (!empty($current_admin) && ($_rel ?? '') !== 'project/spot_checks.php') {
    try {
        require_once __DIR__ . '/ProjectSpotCheck.php';
        $spotBanner = psc_pending_by_business();
        if ($spotBanner): ?>
<div class="alert alert-warning d-flex flex-wrap align-items-center mb-3" role="alert" style="border-left:6px solid #e0a100;gap:10px">
  <i class="fas fa-search-dollar fa-lg"></i>
  <a class="flex-grow-1 text-reset" style="text-decoration:none" href="<?php echo BASE_URL; ?>/project/spot_checks.php"><strong>有 <?php echo (int)array_sum($spotBanner); ?> 个自动结算订单待财务抽查</strong>
    <span class="small ml-1">（<?php echo e(implode('、', array_map(function ($b, $n) { return $b . ' ' . $n . ' 单'; }, array_keys($spotBanner), $spotBanner))); ?>）系统已自动通过这些订单，请打开核对收款、成本、交付，没问题点“无误”。</span></a>
  <a class="btn btn-warning btn-sm font-weight-bold" href="<?php echo BASE_URL; ?>/project/spot_checks.php">去抽查</a>
</div>
<?php   endif;
    } catch (Throwable $e) {}
}
