<?php
/**
 * 首页提醒：网站类订单还没有录入域名。近 45 天内的订单、本人参与（财务看全部、售后看网站类）、域名还没填的，
 * 提醒去“续费资料”里录入域名和到期日，便于到期提醒；后期接入域名注册商 API 后可自动取得到期日。
 * 用法：在已定义 $actor（ps_actor() 结果）的页面里 include 本文件。
 */
require_once __DIR__ . '/ProjectRenewals.php';

if (!function_exists('dmw_missing')) {
/** 返回 null 表示无权限；否则 ['total'=>N,'rows'=>[...]]。 */
function dmw_missing($actor, $limit = 8)
{
    try {
        if (!$actor || !pr_ready() || pr_scope($actor) === 'none') return null;
        $params = [];
        $where = pr_order_where($actor, $params);
        $web = "o.project_type IN ('AI网站定制','网站模板','网站续费','网站修改')";
        $since = (new DateTimeImmutable(pr_today()))->modify('-45 day')->format('Y-m-d');
        $base = "FROM project_orders o LEFT JOIN project_order_resources res ON res.order_id=o.id
                 WHERE $web AND ($where) AND o.order_date>=? AND COALESCE(res.domain_mode,'pending')<>'none'
                   AND NOT EXISTS (SELECT 1 FROM project_renewal_items r WHERE r.order_id=o.id AND r.resource_type='domain' AND r.resource_name<>'' AND r.status<>'closed')";
        $p = array_merge($params, [$since]);
        $c = db()->prepare("SELECT COUNT(*) $base"); $c->execute($p);
        $total = (int)$c->fetchColumn();
        $q = db()->prepare("SELECT o.id,o.order_no,o.customer_name,o.project_type,o.order_date $base ORDER BY o.order_date DESC,o.id DESC LIMIT " . (int)$limit);
        $q->execute($p);
        return ['total' => $total, 'rows' => $q->fetchAll()];
    } catch (Throwable $e) {
        return null;
    }
}
}

$dmw = dmw_missing($actor ?? null, 8);
if ($dmw !== null && $dmw['total'] > 0):
?>
<style>
.dmw{margin:0 0 1rem;border:1px solid #cfe0f0;border-radius:16px;background:linear-gradient(180deg,#f3f8fd,#fff);overflow:hidden}
.dmw-head{display:flex;flex-wrap:wrap;gap:.4rem 1rem;align-items:center;justify-content:space-between;padding:.85rem 1.2rem;border-bottom:1px solid #dbe8f4}
.dmw-title{font-size:1.02rem;font-weight:700;color:#1d4468}.dmw-title i{color:#2e6fa8;margin-right:.45rem}
.dmw-count{display:inline-block;min-width:1.6rem;padding:.05rem .55rem;border-radius:999px;background:#2e6fa8;color:#fff;font-size:.8rem;text-align:center;margin-left:.4rem}
.dmw-sub{font-size:.82rem;color:#5b7189}
.dmw-list{display:flex;flex-wrap:wrap;gap:.5rem;padding:.85rem 1.2rem}
.dmw-item{display:block;padding:.5rem .8rem;border-radius:10px;background:#fff;border:1px solid #dbe8f4;text-decoration:none!important;min-width:210px}
.dmw-item:hover{border-color:#2e6fa8;background:#f3f8fd}
.dmw-item b{display:block;color:#1d4468;font-size:.9rem}.dmw-item span{display:block;color:#6b7f93;font-size:.78rem}
</style>
<div class="dmw" id="domainMissing">
  <div class="dmw-head">
    <div><div class="dmw-title"><i class="fas fa-globe"></i>请录入域名<span class="dmw-count"><?php echo (int)$dmw['total']; ?></span></div>
    <div class="dmw-sub">近 45 天的网站订单还没填域名。点进去登记域名和到期日，到期前才能提醒客户续费。</div></div>
    <a class="dmw-sub" href="<?php echo BASE_URL; ?>/project/renewal_gaps.php?f=domain"><strong>集中补录域名 →</strong></a>
  </div>
  <div class="dmw-list">
    <?php foreach ($dmw['rows'] as $r): ?>
      <a class="dmw-item" href="<?php echo BASE_URL; ?>/project/renewals.php?order_id=<?php echo (int)$r['id']; ?>#renewal-form">
        <b><?php echo e($r['customer_name'] !== '' ? $r['customer_name'] : '客户昵称待补'); ?></b>
        <span><?php echo e($r['project_type']); ?> · <?php echo e($r['order_no']); ?> · <?php echo e($r['order_date']); ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>
