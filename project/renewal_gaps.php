<?php
require_once __DIR__ . '/../includes/ProjectSheetEdit.php';
require_once __DIR__ . '/../includes/ProjectRenewals.php';
// 待补资料总表：本人订单里缺“续费所需资料”的集中补录页（财务看全部）。
// 网站类订单：客户手机号 + 域名；微信小程序订单：服务器到期日。补完保存即写入续费资料，未补充将不会获得本订单的续费分成。
$actor = ps_require_actor();
if (pr_scope($actor) === 'none') { http_response_code(403); exit('当前账户未分配续费工作台权限'); }
pr_require($actor);
$error = ''; $success = '';
$isFinance = ($actor['role'] ?? '') === 'finance';
$webTypes = ['AI网站定制', '网站模板', '网站续费', '网站修改', '备案-提成'];

if (!function_exists('rg_rows')) {
function rg_rows($actor, $filter, $keyword, $limit)
{
    $params = [];
    $since = (new DateTimeImmutable(pr_today()))->modify('-120 day')->format('Y-m-d');
    $scope = '1=1';
    if (($actor['role'] ?? '') !== 'finance') {
        $scope = "(EXISTS (SELECT 1 FROM project_participants p WHERE p.order_id=o.id AND p.employee_id=?) OR EXISTS (SELECT 1 FROM project_department_uploaders u WHERE u.order_id=o.id AND u.employee_id=?))";
        $params[] = (int)$actor['employee_id']; $params[] = (int)$actor['employee_id'];
    }
    $sql = "SELECT o.id,o.order_no,o.customer_name,o.project_type,o.order_date,o.note,
              (o.project_type<>'小程序开发' AND NOT EXISTS (SELECT 1 FROM project_renewal_items r WHERE r.order_id=o.id AND (((r.phone_hash<>'' OR COALESCE(r.wechat_hash,'')<>'') AND r.status<>'closed') OR r.owner='customer'))) AS need_phone,
              (o.project_type<>'小程序开发' AND COALESCE(res.domain_mode,'pending')<>'none' AND NOT EXISTS (SELECT 1 FROM project_renewal_items r WHERE r.order_id=o.id AND r.resource_type='domain' AND ((r.resource_name<>'' AND r.status<>'closed') OR r.owner='customer'))) AS need_domain,
              (o.project_type='小程序开发' AND NOT EXISTS (SELECT 1 FROM project_renewal_items r WHERE r.order_id=o.id AND r.resource_type='server' AND r.expires_on IS NOT NULL AND r.expiry_source IN ('confirmed','imported') AND r.status<>'closed')) AS need_server
            FROM project_orders o LEFT JOIN project_order_resources res ON res.order_id=o.id
            WHERE o.project_type IN ('AI网站定制','网站模板','网站续费','网站修改','备案-提成','小程序开发') AND o.order_date>=? AND $scope";
    array_unshift($params, $since);
    // 参数顺序：since 在 scope 之前（SQL 中 ? 的出现顺序一致）
    if ($keyword !== '') { $sql .= ' AND (o.order_no LIKE ? OR o.customer_name LIKE ?)'; $like = '%' . $keyword . '%'; $params[] = $like; $params[] = $like; }
    $having = ['phone' => 'need_phone=1', 'domain' => 'need_domain=1', 'server' => 'need_server=1'][$filter] ?? '(need_phone=1 OR need_domain=1 OR need_server=1)';
    $sql .= " HAVING $having ORDER BY o.order_date DESC,o.id DESC LIMIT " . (int)$limit;
    $q = db()->prepare($sql); $q->execute($params);
    return $q->fetchAll();
}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    $done = 0; $fails = [];
    foreach ((array)($_POST['row'] ?? []) as $orderId => $f) {
        $orderId = (int)$orderId;
        $phone = trim((string)($f['phone'] ?? '')); $domain = trim((string)($f['domain'] ?? '')); $server = trim((string)($f['server'] ?? ''));
        $wechat = trim((string)($f['wechat'] ?? ''));
        $owner = (string)($f['owner'] ?? ''); $ownerNote = trim((string)($f['owner_note'] ?? ''));
        if ($phone === '' && $wechat === '' && $domain === '' && $server === '' && $owner === '') continue;
        try {
            if ($owner === 'customer' && $ownerNote === '') throw new RuntimeException('客户自有域名请写一句备注（如：客户自备域名，已交付源码）');
            // 全行先校验联系方式，避免手机号写入后才发现微信号格式有误。
            $phone = pr_phone($phone); $wechat = pr_wechat($wechat);
            if ($phone !== '') pse_set_phone($orderId, $phone, $actor);
            if ($wechat !== '') pse_set_wechat($orderId, $wechat, $actor);
            if ($domain !== '') pse_set_domain($orderId, $domain, $actor);
            if ($owner !== '') pse_set_domain_owner($orderId, $owner, $ownerNote, $actor);
            if ($server !== '') pse_set_server_expiry($orderId, $server, $actor);
            $done++;
        } catch (Throwable $e) {
            $no = db()->prepare('SELECT order_no FROM project_orders WHERE id=?'); $no->execute([$orderId]);
            $fails[] = (string)$no->fetchColumn() . '：' . ($e instanceof RuntimeException ? $e->getMessage() : '保存失败');
        }
    }
    $success = $done ? '已保存 ' . $done . ' 张订单的续费资料' : '';
    if ($fails) $error = '有 ' . count($fails) . ' 张没有保存成功：' . implode('；', array_slice($fails, 0, 5));
    if (!$done && !$fails) $error = '没有填写任何内容';
}

$filter = (string)($_GET['f'] ?? '');
$keyword = trim((string)($_GET['q'] ?? ''));
$rows = rg_rows($actor, $filter, $keyword, 150);
$all = rg_rows($actor, '', '', 5000);
$count = ['phone' => 0, 'domain' => 0, 'server' => 0];
foreach ($all as $r) { if ($r['need_phone']) $count['phone']++; if ($r['need_domain']) $count['domain']++; if ($r['need_server']) $count['server']++; }
$page_title = '待补资料';
include __DIR__ . '/../includes/header.php';
?>
<div class="project-intake-page">
<div class="project-hero mb-3"><div><div class="project-eyebrow">项目合作结算中心 · 续费资料</div><h2>待补资料</h2><p>网站类订单补<strong>手机号或海外客户微信号（二选一），以及域名</strong>；微信小程序订单补<strong>服务器到期日</strong>。客户自有域名登记归属即可。可在线填写，也可下载模板一次补齐多单。</p></div><div class="project-hero-actions"><a class="btn btn-success" style="color:#fff" href="<?php echo BASE_URL; ?>/project/batch_fill.php">批量补全资料</a><a class="btn btn-light" href="<?php echo BASE_URL; ?>/project/renewals.php">续费工作台</a></div></div>
<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>
<div class="card mb-3"><div class="card-body d-flex flex-wrap align-items-center" style="gap:8px 14px">
  <?php foreach (['' => ['全部缺项', count($all)], 'phone' => ['缺联系方式', $count['phone']], 'domain' => ['缺域名', $count['domain']], 'server' => ['缺服务器到期日', $count['server']]] as $key => [$label, $n]): ?>
    <a class="btn btn-sm <?php echo $filter === (string)$key ? 'btn-success' : 'btn-outline-secondary'; ?>" style="<?php echo $filter === (string)$key ? 'color:#fff' : ''; ?>" href="?f=<?php echo e($key); ?>&q=<?php echo e(rawurlencode($keyword)); ?>"><?php echo e($label); ?> <strong><?php echo (int)$n; ?></strong></a>
  <?php endforeach; ?>
  <form method="get" class="form-inline ml-auto"><input type="hidden" name="f" value="<?php echo e($filter); ?>"><input class="form-control form-control-sm mr-2" name="q" value="<?php echo e($keyword); ?>" placeholder="订单号 / 客户" style="width:200px"><button class="btn btn-sm btn-outline-primary">查找</button></form>
</div></div>
<form method="post"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>">
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle">
  <thead class="thead-light"><tr><th>订单</th><th>业务 / 日期</th><th style="min-width:220px">联系方式（二选一）</th><th style="min-width:230px">域名 / 归属</th><th style="min-width:150px">服务器到期日</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $isMini = $r['project_type'] === '小程序开发'; ?>
    <tr>
      <td><a href="<?php echo BASE_URL; ?>/project/order.php?id=<?php echo (int)$r['id']; ?>"><strong><?php echo e($r['order_no']); ?></strong></a><div class="small text-muted"><?php echo e($r['customer_name'] !== '' ? $r['customer_name'] : '客户昵称待补'); ?></div></td>
      <td class="small"><?php echo e($r['project_type']); ?><div class="text-muted"><?php echo e($r['order_date']); ?></div></td>
      <td><?php if ($r['need_phone']): ?><input aria-label="客户手机号" class="form-control form-control-sm mb-1" name="row[<?php echo (int)$r['id']; ?>][phone]" maxlength="20" inputmode="tel" placeholder="客户手机号"><input aria-label="海外客户微信号" class="form-control form-control-sm" name="row[<?php echo (int)$r['id']; ?>][wechat]" maxlength="60" placeholder="或填写海外客户微信号"><small class="text-muted">任一项即可；微信号不发短信</small><?php else: ?><span class="text-success small">✔ 已有联系方式</span><?php endif; ?></td>
      <td><?php if ($isMini): ?><span class="text-muted small">—</span><?php elseif ($r['need_domain']): ?><input class="form-control form-control-sm mb-1" name="row[<?php echo (int)$r['id']; ?>][domain]" maxlength="120" placeholder="example.com"><div class="d-flex" style="gap:4px"><select class="form-control form-control-sm" style="max-width:120px" name="row[<?php echo (int)$r['id']; ?>][owner]" onchange="this.nextElementSibling.hidden=this.value!=='customer'"><option value="">归属不变</option><option value="customer">客户自有</option><option value="ours">我们代管</option></select><input class="form-control form-control-sm" name="row[<?php echo (int)$r['id']; ?>][owner_note]" maxlength="200" placeholder="备注：已交付源码" hidden></div><?php else: ?><span class="text-success small">✔ 已有</span><?php endif; ?></td>
      <td><?php if (!$isMini): ?><span class="text-muted small">—</span><?php elseif ($r['need_server']): ?><input class="form-control form-control-sm" type="date" name="row[<?php echo (int)$r['id']; ?>][server]"><?php else: ?><span class="text-success small">✔ 已有</span><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="5" class="text-center text-muted py-4">没有缺项订单，资料都齐了 🎉</td></tr><?php endif; ?>
  </tbody></table></div>
  <?php if ($rows): ?><div class="card-body d-flex justify-content-between align-items-center flex-wrap" style="gap:8px"><span class="small text-muted">只显示最近 120 天内、前 150 张；填完的行会写入续费资料，保存后从列表里消失。<?php echo $isFinance ? '（财务看全部订单）' : '（只显示你参与或代录的订单）'; ?></span><button class="btn btn-success" style="color:#fff">保存</button></div><?php endif; ?>
</div></form>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
