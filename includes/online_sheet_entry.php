<?php
// “我的在线表格”入口卡片（原始表格页 / 工作台共用）：选月份和业务，直接在网页里像 Excel 一样录入；下面列出已有的月份表格。
// 需要 $actor。账号没有可用的业务（财务 / 管理账号）时什么都不显示。
require_once __DIR__ . '/ProjectOnlineSheets.php';
$olsBusinesses = pols_businesses($actor);
if ($olsBusinesses):
    $olsMonthNow = date('Y-m');
    $olsList = pols_list($actor);
    $olsCurrent = array_values(array_filter($olsList, function ($r) use ($olsMonthNow) { return $r['month'] === $olsMonthNow; }));
?>
<?php if (!defined('OLS_STYLE_DONE')): define('OLS_STYLE_DONE', 1); ?>
<style>
.ols-card{background:#fff;border:1px solid #cfe3d6;border-left:5px solid #1f6a52;border-radius:14px;padding:1rem 1.2rem;margin:0 0 1rem;box-shadow:0 8px 20px -16px rgba(31,106,82,.6)}
.ols-card h3{font-size:1.1rem;margin:0 0 .25rem;color:#17503e}.ols-card p{margin:0 0 .6rem;color:#51635b;font-size:.9rem}
.ols-form{display:flex;flex-wrap:wrap;gap:.6rem;align-items:end}.ols-form label{display:block;font-size:.78rem;color:#51635b;margin:0 0 .15rem}
.ols-form input,.ols-form select{min-height:38px;border:1px solid #b9cfc2;border-radius:8px;padding:.3rem .6rem;background:#fff}
.ols-go{min-height:38px;padding:.35rem 1.1rem;border:0;border-radius:8px;background:#1f6a52;color:#fff!important;font-weight:700;cursor:pointer;text-decoration:none!important;display:inline-flex;align-items:center}
.ols-go:hover{background:#18523f}
.ols-list{display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.7rem}
.ols-chip{display:inline-flex;align-items:center;gap:.5rem;padding:.35rem .7rem;border:1px solid #d6e4db;border-radius:999px;background:#f4faf6;color:#17503e!important;font-size:.85rem;text-decoration:none!important}
.ols-chip:hover{background:#e6f3ea}.ols-chip b{font-weight:700}.ols-chip .bad{color:#b4332f}
</style>
<?php endif; ?>
<section class="ols-card" id="my-online-sheets" aria-label="我的在线表格">
  <h3><i class="fas fa-table mr-1"></i> 我的在线表格</h3>
  <p>喜欢用表格录单？每个月一张在线表格，直接在网页里填、也可以把电脑上 Excel 里的内容复制粘贴进来；改动自动保存，停手几秒后自动同步到系统（每行的结果显示在“系统状态”列）。</p>
  <form class="ols-form" method="get" action="<?php echo BASE_URL; ?>/project/files.php">
    <input type="hidden" name="online" value="1">
    <div><label for="olsMonth">月份</label><input type="month" id="olsMonth" name="month" value="<?php echo e($olsMonthNow); ?>" min="2026-01" max="<?php echo e(date('Y-m', strtotime('+1 month'))); ?>" required></div>
    <div><label for="olsBusiness">业务</label><select id="olsBusiness" name="business"><?php foreach ($olsBusinesses as $olsB): ?><option value="<?php echo e($olsB); ?>"><?php echo e($olsB); ?></option><?php endforeach; ?></select></div>
    <button class="ols-go" type="submit"><i class="fas fa-pen-to-square mr-1"></i> 打开在线表格</button>
  </form>
  <?php if ($olsList): ?>
  <div class="ols-list" aria-label="已有的在线表格">
    <?php foreach (array_slice($olsList, 0, 12) as $olsRow): ?>
    <a class="ols-chip" href="<?php echo BASE_URL; ?>/project/files.php?view=<?php echo (int)$olsRow['file_id']; ?>"><b><?php echo e($olsRow['month']); ?></b> <?php echo e($olsRow['business_name']); ?> <span class="text-muted"><?php echo (int)$olsRow['rows']; ?> 行</span><?php if ($olsRow['bad']): ?> <span class="bad">需处理 <?php echo (int)$olsRow['bad']; ?></span><?php endif; ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>
