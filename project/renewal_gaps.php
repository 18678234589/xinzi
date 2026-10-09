<?php
require_once __DIR__ . '/../includes/ProjectRenewalFill.php';
// 待补资料总表：本人订单里缺“续费所需资料”的集中补录页（财务看全部）。
// 网站类订单：域名 + 客户手机号或海外微信号；微信小程序订单：服务器到期日（永久的勾“永久”）。每张订单一张卡片，填好点“保存”即写入续费资料，不整页刷新。
$actor = ps_require_actor();
if (pr_scope($actor) === 'none') { http_response_code(403); exit('当前账户未分配续费工作台权限'); }
pr_require($actor);
$isFinance = ($actor['role'] ?? '') === 'finance';

$filter = (string)($_GET['f'] ?? '');
if (!in_array($filter, ['', 'phone', 'domain', 'server'], true)) $filter = '';
$keyword = trim((string)($_GET['q'] ?? ''));
$rows = prf_missing($actor, 150, 120, $filter, $keyword);
$all = prf_missing($actor, 5000, 120);
$count = ['phone' => 0, 'domain' => 0, 'server' => 0];
foreach ($all as $r) { if ($r['need_phone']) $count['phone']++; if ($r['need_domain']) $count['domain']++; if ($r['need_server']) $count['server']++; }
$page_title = '待补资料';
include __DIR__ . '/../includes/header.php';
echo prf_assets();
?>
<div class="rf-page" data-rf-endpoint="<?php echo BASE_URL; ?>/project/renewal_fill.php" data-rf-csrf="<?php echo e(ps_csrf_token()); ?>">
  <div class="rf-hero">
    <h2>待补资料</h2>
    <p>网站类订单补<b>域名</b>和<b>客户手机号或海外客户微信号</b>；微信小程序订单补<b>服务器到期日</b>（永久的勾“永久”）。每张卡片<b>填完离开输入框就会自动保存</b>（也可点“保存”），补齐的订单会自动收起。未补资料将没有该订单的续费分成；微信号仅用于人工联系，不发送短信。</p>
  </div>
  <div class="rf-bar">
    <?php foreach (['' => ['全部缺项', count($all)], 'domain' => ['缺域名', $count['domain']], 'phone' => ['缺联系方式', $count['phone']], 'server' => ['缺服务器到期日', $count['server']]] as $key => [$label, $n]): ?>
      <a class="rf-chip <?php echo $filter === (string)$key ? 'on' : ''; ?>" href="?f=<?php echo e($key); ?>&q=<?php echo e(rawurlencode($keyword)); ?>"><?php echo e($label); ?><b><?php echo (int)$n; ?></b></a>
    <?php endforeach; ?>
    <form method="get" class="rf-search"><input type="hidden" name="f" value="<?php echo e($filter); ?>"><input name="q" value="<?php echo e($keyword); ?>" placeholder="订单号 / 客户昵称"><button class="rf-btn" type="submit">查找</button></form>
  </div>
  <?php foreach ($rows as $r) echo prf_card($r); ?>
  <div class="rf-allgood" <?php echo $rows ? 'hidden' : ''; ?>>🎉 没有缺项订单，资料都齐了</div>
  <?php if ($rows): ?><p class="small text-muted mt-2">只显示最近 120 天内、前 150 张。<?php echo $isFinance ? '（财务看到全部人员的订单）' : '只显示你参与或代录的订单。'; ?>原有的表格更正入口、批量补全模板仍可使用：<a href="<?php echo BASE_URL; ?>/project/batch_fill.php">下载模板批量补</a>。</p><?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
