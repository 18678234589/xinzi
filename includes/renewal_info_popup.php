<?php
/**
 * 首页弹窗：本人提交的订单缺“续费所需资料”时提醒补交。
 * - 网站类订单（AI网站定制 / 网站模板 / 网站续费 / 网站修改）：需要客户手机号 + 域名；
 * - 微信小程序订单：需要服务器到期日（实际日期，不是按下单日估算的预计日期）。
 * 未提供将不会获得本订单的续费分成。
 * 只提醒本人参与 / 代录的订单，近 90 天内；财务不弹（有专门的待补资料列表）。每次登录弹一次（退出再登录会重新弹），可点“稍后提醒”关闭。
 * 用法：在已定义 $actor（ps_actor() 结果）的页面里 include 本文件。
 */
require_once __DIR__ . '/ProjectRenewals.php';

if (!function_exists('rip_missing')) {
/** 返回 null 表示不提醒；否则 ['total'=>N,'rows'=>[['id','order_no','customer_name','project_type','order_date','need'=>['客户手机号','域名','服务器到期日']]...]]。 */
function rip_missing($actor, $limit = 8)
{
    try {
        if (!$actor || ($actor['role'] ?? '') === 'finance' || !pr_ready() || pr_scope($actor) === 'none' || empty($actor['employee_id'])) return null;
        $eid = (int)$actor['employee_id'];
        $since = (new DateTimeImmutable(pr_today()))->modify('-90 day')->format('Y-m-d');
        $mine = "(EXISTS (SELECT 1 FROM project_participants p WHERE p.order_id=o.id AND p.employee_id=?) OR EXISTS (SELECT 1 FROM project_department_uploaders u WHERE u.order_id=o.id AND u.employee_id=?))";
        $sql = "SELECT o.id,o.order_no,o.customer_name,o.project_type,o.order_date,
                  (o.project_type<>'小程序开发' AND NOT EXISTS (SELECT 1 FROM project_renewal_items r WHERE r.order_id=o.id AND ((r.phone_hash<>'' AND r.status<>'closed') OR r.owner='customer')) AND COALESCE(o.note,'') NOT REGEXP '(^|[^0-9])1[3-9][0-9]{9}([^0-9]|$)' AND COALESCE(o.note,'') NOT REGEXP '(微信|微信号|wx|海外|国外|邮箱|@)') AS need_phone,
                  (o.project_type<>'小程序开发' AND COALESCE(res.domain_mode,'pending')<>'none' AND NOT EXISTS (SELECT 1 FROM project_renewal_items r WHERE r.order_id=o.id AND r.resource_type='domain' AND ((r.resource_name<>'' AND r.status<>'closed') OR r.owner='customer'))) AS need_domain,
                  (o.project_type='小程序开发' AND NOT EXISTS (SELECT 1 FROM project_renewal_items r WHERE r.order_id=o.id AND r.resource_type='server' AND r.expires_on IS NOT NULL AND r.expiry_source IN ('confirmed','imported') AND r.status<>'closed')) AS need_server
                FROM project_orders o LEFT JOIN project_order_resources res ON res.order_id=o.id
                WHERE o.project_type IN ('AI网站定制','网站模板','网站续费','网站修改','小程序开发') AND o.order_date>=? AND $mine
                HAVING need_phone=1 OR need_domain=1 OR need_server=1
                ORDER BY o.order_date DESC,o.id DESC";
        $q = db()->prepare($sql);
        $q->execute([$since, $eid, $eid]);
        $all = $q->fetchAll();
        if (!$all) return null;
        $rows = [];
        foreach (array_slice($all, 0, (int)$limit) as $r) {
            $need = [];
            if ($r['need_phone']) $need[] = '客户手机号';
            if ($r['need_domain']) $need[] = '域名';
            if ($r['need_server']) $need[] = '服务器到期日';
            $r['need'] = $need; $rows[] = $r;
        }
        return ['total' => count($all), 'rows' => $rows];
    } catch (Throwable $e) {
        return null;
    }
}
}

$rip = rip_missing($actor ?? null, 8);
// 每次登录都提醒：每个登录会话在首页弹一次（退出再登录会重新弹），促进合作人员尽快补录；只是提醒，不影响其他操作。
if ($rip !== null && session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['rip_shown'])) $rip = null;
if ($rip !== null && session_status() === PHP_SESSION_ACTIVE) $_SESSION['rip_shown'] = 1;
if ($rip !== null):
?>
<style>
.rip-mask{position:fixed;top:0;right:0;bottom:0;left:0;background:rgba(20,30,25,.5);z-index:2100;display:flex;align-items:center;justify-content:center;padding:16px}
.rip-mask[hidden]{display:none}
.rip-box{background:#fff;border-radius:18px;max-width:640px;width:100%;max-height:88vh;display:flex;flex-direction:column;box-shadow:0 24px 60px rgba(0,0,0,.3);overflow:hidden}
.rip-head{padding:16px 20px;background:linear-gradient(135deg,#fff4dc,#fff);border-bottom:1px solid #f1e2bd}
.rip-head h5{margin:0 0 4px;font-weight:700;color:#7a4b00}.rip-head h5 i{margin-right:8px;color:#c27a00}
.rip-rule{font-size:.9rem;color:#5a3f10;line-height:1.7}.rip-rule strong{color:#b02a1a}
.rip-body{padding:12px 20px;overflow:auto}
.rip-item{display:flex;justify-content:space-between;gap:10px;align-items:center;padding:9px 12px;border:1px solid #eee3c8;border-radius:10px;margin-bottom:8px;text-decoration:none!important;background:#fffdf7}
.rip-item:hover{border-color:#c27a00;background:#fff8e6}
.rip-item b{display:block;color:#3a3326;font-size:.92rem}.rip-item span{display:block;color:#7b6a4e;font-size:.78rem}
.rip-need{display:flex;flex-wrap:wrap;gap:4px;justify-content:flex-end}.rip-need i{font-style:normal;background:#fde8e4;color:#b02a1a;border-radius:999px;padding:1px 9px;font-size:.75rem;white-space:nowrap}
.rip-foot{padding:12px 20px;border-top:1px solid #f1e2bd;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
.rip-foot .go{background:#c27a00;color:#fff!important;border:0;border-radius:10px;padding:8px 18px;font-weight:600;text-decoration:none!important}
.rip-foot .later{background:#fff;border:1px solid #d9cdb0;border-radius:10px;padding:8px 14px;color:#5a3f10}
</style>
<div class="rip-mask" id="renewalInfoPopup" hidden>
  <div class="rip-box" role="dialog" aria-modal="true" aria-labelledby="ripTitle">
    <div class="rip-head">
      <h5 id="ripTitle"><i class="fas fa-exclamation-triangle"></i>有 <?php echo (int)$rip['total']; ?> 张订单缺少续费资料</h5>
      <div class="rip-rule">网站类订单请提交 <strong>客户手机号和域名</strong>；微信小程序订单请提交 <strong>服务器到期日</strong>（订单里填写，或在上传的表格里带上对应列）。<strong>未提供将不会获得本订单的续费分成。</strong></div>
    </div>
    <div class="rip-body">
      <?php foreach ($rip['rows'] as $r): ?>
        <a class="rip-item" href="<?php echo BASE_URL; ?>/project/renewals.php?order_id=<?php echo (int)$r['id']; ?>#renewal-form">
          <div><b><?php echo e($r['customer_name'] !== '' ? $r['customer_name'] : '客户昵称待补'); ?></b><span><?php echo e($r['project_type']); ?> · <?php echo e($r['order_no']); ?> · <?php echo e($r['order_date']); ?></span></div>
          <div class="rip-need"><?php foreach ($r['need'] as $n): ?><i>缺 <?php echo e($n); ?></i><?php endforeach; ?></div>
        </a>
      <?php endforeach; ?>
      <?php if ($rip['total'] > count($rip['rows'])): ?><div class="small text-muted">还有 <?php echo (int)($rip['total'] - count($rip['rows'])); ?> 张，点“去补充”在“待补资料”页里集中填写。</div><?php endif; ?>
    </div>
    <div class="rip-foot">
      <span class="small text-muted">也可以在“我上传的表格”里直接像 Excel 一样补手机号 / 域名，再提交更正。</span>
      <span><button type="button" class="later" id="ripLater">稍后提醒</button> <a class="go" href="<?php echo BASE_URL; ?>/project/renewal_gaps.php">去补充</a></span>
    </div>
  </div>
</div>
<script>
(function () {
  var box = document.getElementById('renewalInfoPopup');
  box.hidden = false;
  document.getElementById('ripLater').addEventListener('click', function () { box.hidden = true; });
  box.addEventListener('click', function (e) { if (e.target === box) box.hidden = true; });
})();
</script>
<?php endif; ?>
