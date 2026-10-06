<?php
/**
 * 首页提醒：近三天（含今天）将到期、需要续费的订单，并带上客户手机号、微信号，方便直接联系。
 * 可见范围沿用续费工作台的权限（pr_scope）：管理员/财务看全部，售后部门看网站类订单，王亚看自己的小程序订单；其他人不显示。
 * 手机号优先取续费资料里登记的，其次取订单备注里的“客户联系方式”；微信号取小程序订单的“客户微信”，或备注里的微信信息。
 * 用法：在已定义 $actor（ps_actor() 结果）的页面里 include 本文件。
 */
require_once __DIR__ . '/ProjectRenewals.php';

if (!function_exists('rdw_contacts')) {
function rdw_contacts(array $row)
{
    $note = (string)$row['note'];
    $details = json_decode((string)($row['details_json'] ?? ''), true);
    $details = is_array($details) ? $details : [];
    $phone = '';
    if (!empty($row['phone_cipher'])) { try { $phone = (string)pv_decrypt($row['phone_cipher']); } catch (Throwable $e) { $phone = ''; } }
    if ($phone === '' && preg_match('/客户联系方式[:：]\s*([^\n；;]*)/u', $note, $m) && preg_match('/(?<![0-9])(1[3-9][0-9]{9})(?![0-9])/', $m[1], $pm)) $phone = $pm[1];
    if ($phone === '' && preg_match('/(?<![0-9])(1[3-9][0-9]{9})(?![0-9])/', (string)$row['customer_name'], $pm)) $phone = $pm[1];
    $wechat = trim((string)($details['customer_wechat'] ?? ''));
    if ($wechat === '' && preg_match('/微信(?:号)?\s*[:：]\s*([A-Za-z0-9_\-]{5,30})/u', $note, $m)) $wechat = $m[1];
    if ($wechat === '' && $phone !== '' && preg_match('/微信同号/u', $note)) $wechat = $phone . '（微信同号）';
    return [$phone, $wechat];
}

}
if (!function_exists('rdw_due')) {
/** 返回 null 表示当前账号无权查看（不显示）；否则返回 ['rows'=>[], 'overdue'=>N, 'days'=>3]。 */
function rdw_due($actor, $days = 3)
{
    try {
        if (!$actor || !pr_ready() || pr_scope($actor) === 'none') return null;
        $today = pr_today();
        $until = (new DateTimeImmutable($today))->modify('+' . (int)$days . ' day')->format('Y-m-d');
        $params = [$today, $until];
        $where = pr_order_where($actor, $params);
        $q = db()->prepare("SELECT r.id item_id,r.resource_type,r.resource_name,r.expires_on,r.expiry_source,r.phone_cipher,o.id order_id,o.order_no,o.customer_name,o.project_type,o.note,d.details_json
            FROM project_renewal_items r JOIN project_orders o ON o.id=r.order_id LEFT JOIN project_order_details d ON d.order_id=o.id
            WHERE r.status='active' AND r.expires_on BETWEEN ? AND ? AND $where ORDER BY r.expires_on,o.id LIMIT 200");
        $q->execute($params);
        $rows = [];
        foreach ($q->fetchAll() as $r) {
            [$phone, $wechat] = rdw_contacts($r);
            $details = json_decode((string)($r['details_json'] ?? ''), true);
            $r['phone'] = $phone; $r['wechat'] = $wechat;
            $r['resource'] = $r['resource_name'] !== '' ? $r['resource_name'] : (string)(is_array($details) ? ($details['website_url'] ?? ($details['miniapp_name'] ?? '')) : '');
            $r['days_left'] = pr_days($r['expires_on'], $today);
            $rows[] = $r;
        }
        $params2 = [$today];
        $where2 = pr_order_where($actor, $params2);
        $oq = db()->prepare("SELECT COUNT(*) FROM project_renewal_items r JOIN project_orders o ON o.id=r.order_id WHERE r.status='active' AND r.expires_on<? AND $where2");
        $oq->execute($params2);
        if ($rows && empty($GLOBALS['rdw_no_audit'])) ps_audit('renewal', 0, 'due_contacts_view', $actor, ['count' => count($rows)]);
        return ['rows' => $rows, 'overdue' => (int)$oq->fetchColumn(), 'days' => (int)$days];
    } catch (Throwable $e) {
        return null;
    }
}

}

$rdw = rdw_due($actor ?? null, 3);
if ($rdw !== null):
    $rdwTypes = pr_type_labels();
?>
<style>
.rdw{margin:0 0 1rem;border:1px solid #f0dfc0;border-radius:16px;background:linear-gradient(180deg,#fffaf0,#fff);box-shadow:0 1px 2px #6b4a1514,0 10px 26px -16px #6b4a1533;overflow:hidden}
.rdw-head{display:flex;flex-wrap:wrap;gap:.5rem 1rem;align-items:center;justify-content:space-between;padding:.9rem 1.2rem;border-bottom:1px solid #f4e8d0}
.rdw-title{display:flex;align-items:center;gap:.6rem;font-size:1.05rem;font-weight:700;color:#5a3f10}
.rdw-title i{color:#c27a00}
.rdw-count{display:inline-block;min-width:1.6rem;padding:.05rem .55rem;border-radius:999px;background:#c27a00;color:#fff;font-size:.8rem;text-align:center}
.rdw-count.zero{background:#9aa8a2}
.rdw-sub{font-size:.82rem;color:#7b6a4e}
.rdw .rdw-link{color:#8a5a00!important;text-decoration:none!important;font-size:.85rem;font-weight:600}
.rdw .rdw-link:hover{text-decoration:underline!important;background:none!important}
.rdw-wrap{overflow-x:auto}
.rdw table{width:100%;border-collapse:collapse;font-size:.88rem}
.rdw th{padding:.55rem .9rem;text-align:left;font-size:.74rem;letter-spacing:.04em;color:#8a7a5c;font-weight:600;white-space:nowrap;border-bottom:1px solid #f4e8d0;background:#fffdf7}
.rdw td{padding:.65rem .9rem;border-bottom:1px solid #f7efdc;vertical-align:middle;color:#3a3326}
.rdw tr:last-child td{border-bottom:0}
.rdw .due{display:inline-block;padding:.12rem .6rem;border-radius:999px;font-size:.78rem;font-weight:700;white-space:nowrap}
.rdw .due.d0{background:#b4332f;color:#fff}.rdw .due.d1{background:#d96a1b;color:#fff}.rdw .due.dn{background:#f6e3b8;color:#6b4a00}
.rdw .contact{display:inline-flex;align-items:center;gap:.35rem;font-variant-numeric:tabular-nums;white-space:nowrap}
.rdw .contact button{border:1px solid #e3d3ad;background:#fff;color:#6b4a00;border-radius:7px;padding:0 .45rem;font-size:.75rem;line-height:1.5;cursor:pointer}
.rdw .contact button:hover{background:#fff4d9}
.rdw .none{color:#a99f8a}
.rdw .meta{font-size:.76rem;color:#8a7a5c}
.rdw-empty{padding:1.1rem 1.3rem;color:#7b6a4e;font-size:.9rem}
</style>
<section class="rdw" aria-label="近三天将到期的续费订单">
  <div class="rdw-head">
    <div class="rdw-title"><i class="fas fa-bell"></i> 近 <?php echo (int)$rdw['days']; ?> 天将到期 · 需要续费 <span class="rdw-count<?php echo $rdw['rows'] ? '' : ' zero'; ?>"><?php echo count($rdw['rows']); ?></span></div>
    <div class="rdw-sub"><?php if ($rdw['overdue']): ?>另有 <strong><?php echo (int)$rdw['overdue']; ?></strong> 条已过期未处理 · <?php endif; ?><a class="rdw-link" href="<?php echo BASE_URL; ?>/project/renewals.php?filter=due">打开续费工作台 →</a></div>
  </div>
  <?php if (!$rdw['rows']): ?>
    <div class="rdw-empty"><i class="far fa-check-circle" style="color:#2f7a67"></i> 近三天没有将到期的订单，暂时不用联系客户续费。</div>
  <?php else: ?>
  <div class="rdw-wrap"><table>
    <thead><tr><th>到期</th><th>订单 / 客户</th><th>续费项目</th><th>客户手机号</th><th>微信号</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rdw['rows'] as $r): $dl = (int)$r['days_left']; ?>
      <tr>
        <td><span class="due <?php echo $dl <= 0 ? 'd0' : ($dl === 1 ? 'd1' : 'dn'); ?>"><?php echo $dl <= 0 ? '今天到期' : ($dl === 1 ? '明天到期' : $dl . ' 天后'); ?></span><div class="meta"><?php echo e($r['expires_on']); ?><?php echo $r['expiry_source'] === 'estimated' ? ' · 预估' : ''; ?></div></td>
        <td><strong><?php echo e($r['customer_name'] !== '' ? $r['customer_name'] : '—'); ?></strong><div class="meta"><?php echo e($r['order_no']); ?> · <?php echo e($r['project_type']); ?></div></td>
        <td><?php echo e($rdwTypes[$r['resource_type']] ?? $r['resource_type']); ?><?php if ($r['resource'] !== ''): ?><div class="meta"><?php echo e($r['resource']); ?></div><?php endif; ?></td>
        <td><?php if ($r['phone'] !== ''): ?><span class="contact"><?php echo e($r['phone']); ?><button type="button" data-rdw-copy="<?php echo e($r['phone']); ?>" aria-label="复制手机号">复制</button></span><?php else: ?><span class="none">未登记</span><?php endif; ?></td>
        <td><?php if ($r['wechat'] !== ''): ?><span class="contact"><?php echo e($r['wechat']); ?><button type="button" data-rdw-copy="<?php echo e(preg_replace('/（微信同号）$/u', '', $r['wechat'])); ?>" aria-label="复制微信号">复制</button></span><?php else: ?><span class="none">未登记</span><?php endif; ?></td>
        <td style="text-align:right"><a class="rdw-link" href="<?php echo BASE_URL; ?>/project/renewals.php?edit=<?php echo (int)$r['item_id']; ?>">去续费 →</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <script>
  document.querySelectorAll('[data-rdw-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var t = b.getAttribute('data-rdw-copy'), done = function () { var o = b.textContent; b.textContent = '已复制'; setTimeout(function () { b.textContent = o; }, 1200); };
      if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(t).then(done);
      else { var a = document.createElement('textarea'); a.value = t; a.style.cssText = 'position:fixed;opacity:0'; document.body.appendChild(a); a.select(); try { document.execCommand('copy'); done(); } catch (e) {} document.body.removeChild(a); }
    });
  });
  </script>
  <?php endif; ?>
</section>
<?php endif; ?>
