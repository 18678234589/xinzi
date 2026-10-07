<?php
// 财务首页：上传淘宝订单入口（店铺流水）。按店铺一键进入上传页，并显示各店铺最近一笔订单日期，方便看哪个店铺该更新了。
if (!isset($actor) || ($actor['role'] ?? '') !== 'finance') return;
try {
    $ftShops = db()->query('SELECT id,name FROM shops ORDER BY sort ASC,id ASC')->fetchAll();
    $ftLast = [];
    foreach (db()->query("SELECT shop,MAX(order_date) d,COUNT(*) c FROM orders WHERE employee_id=0 AND order_scope='department' AND COALESCE(is_deleted,0)=0 AND shop<>'' GROUP BY shop")->fetchAll() as $r) $ftLast[$r['shop']] = $r;
} catch (Throwable $e) { return; }
if (!$ftShops) return;
?>
<style>
.ft-card { border: 1px solid #cfe3d6; border-radius: 16px; background: linear-gradient(135deg,#f4faf6,#fff); padding: 16px 18px; margin-bottom: 16px; }
.ft-card h5 { margin: 0 0 4px; font-weight: 700; color: #1f4b3d; }
.ft-card .ft-sub { font-size: .85rem; color: #5f6b64; margin-bottom: 12px; }
.ft-grid { display: flex; flex-wrap: wrap; gap: 10px; }
.ft-shop { display: block; min-width: 190px; padding: 10px 14px; border-radius: 12px; background: #2e805e; text-decoration: none !important; box-shadow: 0 4px 12px rgba(31,75,61,.14); }
.ft-shop:hover { background: #256b51; }
.ft-shop b { display: block; color: #fff !important; font-size: .98rem; }
.ft-shop span { display: block; color: #e3f2e9 !important; font-size: .78rem; margin-top: 2px; }
.ft-links a { font-size: .85rem; margin-right: 14px; }
</style>
<div class="ft-card" id="financeTaobaoUpload">
  <div class="d-flex justify-content-between flex-wrap align-items-start">
    <div><h5><i class="fas fa-cloud-upload-alt mr-1"></i> 上传淘宝订单</h5><div class="ft-sub">选择店铺进入上传页（XLSX / CSV）。近一年订单会自动与 ETMLL 双向同步，无需重复上传。</div></div>
    <div class="ft-links"><a href="<?php echo BASE_URL; ?>/shops/etmll_sync.php"><i class="fas fa-sync-alt"></i> ETMLL 订单同步</a><a href="<?php echo BASE_URL; ?>/shops/index.php"><i class="fas fa-store"></i> 店铺管理</a></div>
  </div>
  <div class="ft-grid">
    <?php foreach ($ftShops as $s): $l = $ftLast[$s['name']] ?? null; ?>
      <a class="ft-shop" href="<?php echo BASE_URL; ?>/shops/upload.php?shop_id=<?php echo (int)$s['id']; ?>">
        <b><i class="fas fa-upload mr-1"></i><?php echo e($s['name']); ?></b>
        <span><?php echo $l ? '最近订单 ' . e($l['d']) . ' · 共 ' . number_format((int)$l['c']) . ' 条' : '还没有订单'; ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</div>
