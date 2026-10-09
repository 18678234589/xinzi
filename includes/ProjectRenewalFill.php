<?php
/**
 * 续费资料“就地补录”：弹窗 / 待补资料页 / 单个订单页共用同一套数据查询、保存和卡片。
 * - 缺什么显示什么输入框：域名、联系方式（手机号或微信号自动区分）、小程序服务器到期日（可写“永久”）；
 * - 保存走 pse_set_*（已有条目就更新、没有就新建），不会因为“已登记相同资源”等原因卡住；
 * - 已有内容不会被清空：留空的框不处理。
 */
require_once __DIR__ . '/ProjectSheetEdit.php';
require_once __DIR__ . '/ProjectRenewals.php';

/** 本人（财务看全部）缺续费资料的订单；$orderId 指定时只查这一张。每行带 need_phone / need_domain / need_server。 */
function prf_missing($actor, $limit = 150, $days = 120, $filter = '', $keyword = '', $orderId = 0)
{
    $params = []; $where = ['o.project_type IN (\'AI网站定制\',\'网站模板\',\'网站续费\',\'网站修改\',\'备案-提成\',\'小程序开发\')'];
    if ($orderId) { $where[] = 'o.id=?'; $params[] = (int)$orderId; }
    else { $since = (new DateTimeImmutable(pr_today()))->modify('-' . (int)$days . ' day')->format('Y-m-d'); $where[] = 'o.order_date>=?'; $params[] = $since; }
    if (($actor['role'] ?? '') !== 'finance') {
        $where[] = '(EXISTS (SELECT 1 FROM project_participants p WHERE p.order_id=o.id AND p.employee_id=?) OR EXISTS (SELECT 1 FROM project_department_uploaders u WHERE u.order_id=o.id AND u.employee_id=?))';
        $params[] = (int)$actor['employee_id']; $params[] = (int)$actor['employee_id'];
    }
    if ($keyword !== '') { $where[] = '(o.order_no LIKE ? OR o.customer_name LIKE ?)'; $like = '%' . $keyword . '%'; $params[] = $like; $params[] = $like; }
    $having = ['phone' => 'need_phone=1', 'domain' => 'need_domain=1', 'server' => 'need_server=1'][$filter] ?? '(need_phone=1 OR need_domain=1 OR need_server=1)';
    $sql = "SELECT o.id,o.order_no,o.customer_name,o.project_type,o.order_date,
              (o.project_type<>'小程序开发' AND NOT EXISTS (SELECT 1 FROM project_renewal_items r WHERE r.order_id=o.id AND (((r.phone_hash<>'' OR COALESCE(r.wechat_hash,'')<>'') AND r.status<>'closed') OR r.owner='customer'))) AS need_phone,
              (o.project_type<>'小程序开发' AND COALESCE(res.domain_mode,'pending')<>'none' AND NOT EXISTS (SELECT 1 FROM project_renewal_items r WHERE r.order_id=o.id AND r.resource_type='domain' AND ((r.resource_name<>'' AND r.status<>'closed') OR r.owner='customer'))) AS need_domain,
              (o.project_type='小程序开发' AND NOT EXISTS (SELECT 1 FROM project_renewal_items r WHERE r.order_id=o.id AND r.resource_type='server' AND r.expires_on IS NOT NULL AND r.expiry_source IN ('confirmed','imported') AND r.status<>'closed')) AS need_server
            FROM project_orders o LEFT JOIN project_order_resources res ON res.order_id=o.id
            WHERE " . implode(' AND ', $where) . " HAVING $having ORDER BY o.order_date DESC,o.id DESC LIMIT " . (int)$limit;
    $q = db()->prepare($sql); $q->execute($params);
    return $q->fetchAll();
}

/** 域名到期日：写到该订单第一个未结束的域名条目上（须先有域名）；可写“永久”（记 2099-01-01）。 */
function prf_set_domain_expiry($orderId, $value, $actor)
{
    $date = null;
    if (function_exists('ps_import_date')) { try { $date = ps_import_date($value); } catch (Throwable $e) { $date = null; } }
    $date = pr_date($date ?: $value);
    pr_order($orderId, $actor);
    $pdo = db();
    $q = $pdo->prepare("SELECT id,expires_on FROM project_renewal_items WHERE order_id=? AND resource_type='domain' AND status<>'closed' AND resource_name<>'' ORDER BY id LIMIT 1");
    $q->execute([(int)$orderId]); $item = $q->fetch();
    if (!$item) throw new RuntimeException('请先填写域名，再填域名到期日');
    if ($item['expires_on'] === $date) return;
    $pdo->prepare("UPDATE project_renewal_items SET expires_on=?,expiry_source='confirmed',revision=revision+1,updated_at=NOW() WHERE id=?")->execute([$date, (int)$item['id']]);
    try { $pdo->prepare("UPDATE project_renewal_sms SET state='cancelled',provider_code='RESOURCE_UPDATED',updated_at=NOW() WHERE item_id=? AND state IN ('pending','failed') AND expires_on<>?")->execute([(int)$item['id'], $date]); } catch (Throwable $e) { /* 提醒表可能尚未建立 */ }
    try { $pdo->prepare('INSERT INTO project_renewal_history (item_id,action,actor_type,actor_id,old_expiry,new_expiry,details_json) VALUES (?,?,?,?,?,?,?)')->execute([(int)$item['id'], 'quick_fill_domain_expiry', $actor['type'], (int)$actor['id'], $item['expires_on'], $date, json_encode(['order_id' => (int)$orderId], JSON_UNESCAPED_UNICODE)]); } catch (Throwable $e) { /* 历史表可能尚未建立 */ }
    ps_audit('renewal', (int)$item['id'], 'quick_fill_domain_expiry', $actor, ['order_id' => (int)$orderId, 'old_expiry' => $item['expires_on'], 'new_expiry' => $date]);
}

/** “联系方式”一个框：像手机号的当手机号，其余当微信号。返回 [phone, wechat]。 */
function prf_contact_split($value)
{
    $value = trim((string)$value);
    if ($value === '') return ['', ''];
    $compact = preg_replace('/[\s\-()]+/u', '', $value);
    if (preg_match('/^(\+?86|0086)?1[3-9][0-9]{9}$/D', $compact) || preg_match('/^\+?[0-9]{7,15}$/D', $compact)) return [pr_phone($value), ''];
    return ['', pr_wechat($value)];
}

/** 保存一张订单的补录内容；全部留空返回 false，成功返回提示文字，失败抛 RuntimeException。 */
function prf_save_order($actor, $orderId, array $f)
{
    $orderId = (int)$orderId;
    $domain = trim((string)($f['domain'] ?? '')); $server = trim((string)($f['server'] ?? '')); $domainExpiry = trim((string)($f['domain_expiry'] ?? ''));
    $contact = trim((string)($f['contact'] ?? ''));
    $phone = trim((string)($f['phone'] ?? '')); $wechat = trim((string)($f['wechat'] ?? ''));
    $owner = (string)($f['owner'] ?? ''); $ownerNote = trim((string)($f['owner_note'] ?? ''));
    if ($contact !== '') { [$p, $w] = prf_contact_split($contact); if ($p !== '') $phone = $p; if ($w !== '') $wechat = $w; }
    if ($phone === '' && $wechat === '' && $domain === '' && $server === '' && $owner === '' && $domainExpiry === '') return false;
    if ($owner === 'customer' && $ownerNote === '') $ownerNote = '客户自备域名';
    // 全部先校验再写入，避免写了一半才发现格式错
    $phone = pr_phone($phone); $wechat = pr_wechat($wechat);
    $done = [];
    if ($phone !== '') { pse_set_phone($orderId, $phone, $actor); $done[] = '手机号'; }
    if ($wechat !== '') { pse_set_wechat($orderId, $wechat, $actor); $done[] = '微信号'; }
    if ($domain !== '') { pse_set_domain($orderId, $domain, $actor); $done[] = '域名'; }
    if ($domainExpiry !== '') { pr_date($domainExpiry === '永久' ? '永久' : (function_exists('ps_import_date') ? (ps_import_date($domainExpiry) ?: $domainExpiry) : $domainExpiry)); prf_set_domain_expiry($orderId, $domainExpiry, $actor); $done[] = '域名到期日'; }
    if ($owner !== '') { pse_set_domain_owner($orderId, $owner, $ownerNote, $actor); $done[] = $owner === 'customer' ? '客户自有域名' : '域名归属'; }
    if ($server !== '') { pse_set_server_expiry($orderId, $server, $actor); $done[] = '服务器到期日'; }
    return '已保存：' . implode('、', $done);
}

/** 一张订单的补录卡片（弹窗、待补资料页、单订单页共用）。$r 为 prf_missing 的一行。 */
function prf_card(array $r, $compact = false)
{
    $isMini = $r['project_type'] === '小程序开发';
    $tags = [];
    if (!empty($r['need_domain'])) $tags[] = '域名';
    if (!empty($r['need_phone'])) $tags[] = '联系方式';
    if (!empty($r['need_server'])) $tags[] = '服务器到期日';
    ob_start(); ?>
<div class="rf-card" data-order="<?php echo (int)$r['id']; ?>">
  <div class="rf-top">
    <div class="rf-title"><b><?php echo e($r['customer_name'] !== '' ? $r['customer_name'] : '客户昵称待补'); ?></b><small><?php echo e($r['project_type']); ?> · <?php echo e($r['order_no']); ?> · <?php echo e($r['order_date']); ?></small></div>
    <div class="rf-tags"><?php foreach ($tags as $t): ?><i>缺 <?php echo e($t); ?></i><?php endforeach; ?></div>
  </div>
  <div class="rf-fields">
    <?php if (!empty($r['need_domain'])): ?>
      <label class="rf-f"><span>网站域名</span><input data-k="domain" maxlength="120" placeholder="example.com" autocomplete="off"></label>
    <?php endif; ?>
    <?php if (!$isMini): ?>
      <label class="rf-f"><span>域名到期日（选填）</span><span class="rf-date"><input data-k="domain_expiry" type="date" min="2000-01-01" max="2100-12-31"><label class="rf-perm"><input type="checkbox" data-k="dperm"> 永久</label></span></label>
    <?php endif; ?>
    <?php if (!empty($r['need_phone']) || $isMini): ?>
      <label class="rf-f"><span>续费联系方式<?php echo $isMini ? '（选填）' : ''; ?></span><input data-k="contact" maxlength="60" placeholder="客户手机号，或海外客户微信号" autocomplete="off"></label>
    <?php endif; ?>
    <?php if (!empty($r['need_server'])): ?>
      <label class="rf-f"><span>服务器到期日</span><span class="rf-date"><input data-k="server" type="date" min="2000-01-01" max="2100-12-31"><label class="rf-perm"><input type="checkbox" data-k="perm"> 永久</label></span></label>
    <?php endif; ?>
  </div>
  <div class="rf-actions">
    <?php if (!empty($r['need_domain'])): ?><label class="rf-own"><input type="checkbox" data-k="own"> 客户自备域名（不续费）</label><?php endif; ?>
    <span class="rf-msg" role="status"></span>
    <a class="rf-link" href="<?php echo BASE_URL; ?>/project/order.php?id=<?php echo (int)$r['id']; ?>">订单详情</a>
    <button type="button" class="rf-save">保存</button>
  </div>
</div>
<?php
    return ob_get_clean();
}

/** 引入补录样式和脚本（每页只引一次）。 */
function prf_assets()
{
    static $done = false;
    if ($done) return '';
    $done = true;
    return '<link rel="stylesheet" href="' . BASE_URL . '/assets/css/renewal_fill.css?v=20261009.1"><script src="' . BASE_URL . '/assets/js/renewal_fill.js?v=20261009.1" defer></script>';
}
