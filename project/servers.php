<?php
/**
 * 服务器管理：雅云上游的各国家 / 各品类服务器目录、开通新服务器、管理已购 VPS。
 * 查看与日常管理：有域名管理权限的人 + 可开通名单；开通 / 续费 / 重装 / 看密码 / 停用：超级管理员 + 可开通名单（见 includes/YayunAccess.php）。
 */
require_once __DIR__ . '/../includes/BizcnDomains.php';
require_once __DIR__ . '/../includes/YayunAccess.php';
require_once __DIR__ . '/../includes/YayunCatalog.php';
require_once __DIR__ . '/../includes/YayunHosts.php';
require_once __DIR__ . '/../includes/YayunOrder.php';
require_once __DIR__ . '/../includes/YayunWallet.php';
$actor = ps_require_actor();
if (!ya_can_view($actor)) { http_response_code(403); exit('当前账户未分配服务器管理权限'); }
header('Cache-Control: no-store, max-age=0');
header('Referrer-Policy: same-origin');
header('X-Robots-Tag: noindex');
$csrf = ps_csrf_token();
$canProvision = ya_can_provision($actor);
$isSuper = ya_is_super_admin($actor);
$configured = pas_is_set('yayun', 'username') && pas_is_set('yayun', 'password') && pas_is_set('yayun', 'url');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) { http_response_code(403); echo json_encode(['error' => '页面已过期，请刷新重试'], JSON_UNESCAPED_UNICODE); exit; }
    session_write_close();
    $action = (string)($_POST['action'] ?? '');
    try {
        if (!$configured) throw new RuntimeException('尚未配置雅云接口，请超级管理员在“接口设置”里填写');
        $pdo = db();
        $id = (int)($_POST['id'] ?? 0);
        $audit = function ($name, array $data = []) use ($actor) { ps_audit('yayun', 0, $name, $actor, $data); };
        $needProvision = function () use ($canProvision) { if (!$canProvision) throw new RuntimeException('这个操作只有可开通名单里的人才能做'); };
        switch ($action) {
            case 'hosts': list($rows, $total) = yh_list(); $result = ['rows' => $rows, 'total' => $total]; break;
            case 'host_detail': $result = ['host' => yh_detail($id, $canProvision), 'can_manage' => $canProvision]; break;
            case 'host_action':
                $act = (string)($_POST['do'] ?? '');
                if (in_array($act, yh_manage_actions(), true)) $needProvision();
                $in = array_intersect_key($_POST, array_flip(['password', 'type', 'temp_pass', 'os', 'port', 'confirm', 'format_data_disk']));
                list($msg, $data) = yh_action($id, $act, $in);
                if (in_array($act, ['reboot', 'off', 'on', 'hard_off', 'hard_reboot', 'reinstall', 'reset_password', 'rescue', 'exit_rescue'], true)) $audit('host_' . $act, ['host' => $id]);
                $result = ['message' => $msg] + $data; break;
            case 'host_chart': $result = yh_chart($id, (string)($_POST['type'] ?? '')); break;
            case 'renew_info': $needProvision(); $result = ['cycles' => yh_renew_info($id)]; break;
            case 'renew':
                $needProvision();
                $audit('host_renew_requested', ['host' => $id, 'cycle' => (string)($_POST['cycle'] ?? '')]);
                $q = yh_renew($id, (string)($_POST['cycle'] ?? ''), (string)($_POST['confirm'] ?? ''));
                $audit('host_renew_success', ['host' => $id, 'cycle' => $q['cycle'], 'amount' => $q['amount']]);
                $result = ['message' => '续费成功，已从雅云余额扣除 ¥' . $q['amount']]; break;
            case 'cancel':
                $needProvision();
                $audit('host_cancel_requested', ['host' => $id, 'type' => (string)($_POST['type'] ?? '')]);
                $result = ['message' => yh_cancel($id, (string)($_POST['type'] ?? ''), (string)($_POST['reason'] ?? ''), (string)($_POST['confirm'] ?? ''))]; break;
            case 'host_remark':
                $result = yh_remark($id, (string)($_POST['remark'] ?? ''));
                $audit('host_remark', ['host' => $id, 'remark' => $result['remark']]); break;
            case 'options': $needProvision(); $result = yo_options((int)($_POST['pid'] ?? 0), 'ya_call', true); break;
            case 'quote':
                $needProvision();
                $sel = json_decode((string)($_POST['selection'] ?? '{}'), true);
                $result = yo_quote($pdo, $actor, (int)($_POST['pid'] ?? 0), (string)($_POST['cycle'] ?? ''), is_array($sel) ? $sel : []);
                $audit('order_quoted', ['pid' => (int)($_POST['pid'] ?? 0), 'amount' => $result['amount']]); break;
            case 'submit':
                $needProvision();
                $audit('order_submit_requested', ['pid' => (int)($_POST['pid'] ?? 0)]);
                $row = yo_submit($pdo, $actor, (string)($_POST['token'] ?? ''), (string)($_POST['confirm'] ?? ''), (string)($_POST['ack'] ?? ''));
                $audit('order_submit_' . $row['status'], ['order' => (int)$row['id'], 'amount_cents' => (int)$row['amount_cents'], 'host' => $row['upstream_host_id']]);
                $result = ['order' => yo_public($row)]; break;
            case 'balance': $needProvision(); $result = ya_balance(); break;
            case 'recharge':
                $needProvision();
                $audit('recharge_requested', ['amount' => (string)($_POST['amount'] ?? '')]);
                $result = ya_recharge((string)($_POST['amount'] ?? ''));
                $audit('recharge_invoice_created', ['invoice' => $result['invoice_id'], 'amount' => $result['amount']]); break;
            case 'orders': $needProvision(); $result = ['rows' => yo_history($pdo)]; break;
            case 'provisioners_save':
                $ids = array_filter(array_map('intval', (array)($_POST['employees'] ?? [])));
                $result = ['employees' => ya_save_provisioners($pdo, $ids, $actor)];
                $audit('provisioners_saved', ['employees' => $result['employees']]); break;
            default: throw new InvalidArgumentException('不支持此操作');
        }
        echo json_encode(['ok' => true, 'data' => $result], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    } catch (Throwable $e) {
        http_response_code(400);
        $safe = $e instanceof RuntimeException || $e instanceof InvalidArgumentException;
        if (!$safe) error_log('Yayun operation failed: ' . get_class($e));
        echo json_encode(['error' => $safe ? $e->getMessage() : '暂时未能完成操作，请稍后重试'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    }
    exit;
}

// 目录和价格内嵌到页面，筛选在浏览器里完成（价格缓存由后台任务定时更新，页面不等雅云接口）。
$prices = [];
try { $prices = ya_price_map(db()); } catch (Throwable $e) { $prices = []; }
$products = array_map(function ($p) use ($prices) {
    $pr = $prices[$p['id']] ?? null;
    $disp = ya_display_price($pr);
    return ['id' => $p['id'], 't' => $p['title'], 'z' => $p['zone'], 'zid' => $p['zone_id'], 'g' => $p['group'], 'c' => $p['country'], 'k' => $p['category'], 's' => $p['specs'],
        'p' => $disp ? $disp['amount'] : null, 'pl' => $disp ? $disp['label'] : '', 'lp' => ($pr && $disp && isset($pr['list'][$disp['cycle']])) ? $pr['list'][$disp['cycle']] : null, 'cy' => $pr ? $pr['cycles'] : new stdClass(), 'st' => $pr ? $pr['stock'] : null, 'sc' => $pr ? $pr['stock_control'] : 0, 'err' => $pr ? $pr['error'] : ''];
}, ya_products());
$updated = $prices ? max(array_column($prices, 'updated_at')) : 0;
$employees = [];
if ($isSuper) { try { $employees = db()->query("SELECT id,name,department FROM employees ORDER BY department,name")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {} }
$boot = [
    'products' => $products, 'countries' => array_map(function ($c) { return ['name' => $c[0], 'flag' => $c[1], 'sort' => $c[2]]; }, ya_countries()),
    'categories' => array_map(function ($c) { return ['name' => $c[0], 'sort' => $c[1]]; }, ya_categories()), 'cycles' => ya_cycle_labels(),
    'canProvision' => $canProvision, 'isSuper' => $isSuper, 'configured' => $configured, 'updated' => $updated,
    'provisioners' => $isSuper ? ya_provisioner_ids() : [], 'employees' => $employees, 'statusLabels' => yh_status_labels(),
];
$page_title = '服务器管理';
include __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/servers.css?v=20261122a">
<main class="sv" id="svRoot" data-csrf="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
  <section class="sv-hero">
    <div class="sv-hero-copy">
      <div class="sv-kicker">SERVER STUDIO <span>云服务器 · 物理机 · 裸金属 · 高防 CDN</span></div>
      <h1>全球机房，一站选购与管理。</h1>
      <p>按国家和品类浏览雅云的全部服务器，实时价格与库存；开通后在同一页面开关机、重装、续费、看监控。</p>
      <div class="sv-stats" id="svStats"></div>
    </div>
    <?php if ($canProvision && $configured): ?>
    <aside class="sv-wallet" id="svWallet" aria-label="雅云账户余额"><div class="sv-wallet-k">雅云账户余额</div><div class="sv-wallet-v" id="svBalance">读取中…</div><div class="sv-wallet-sub" id="svBalanceNote">开通 / 续费从这里扣费</div><div class="sv-actions"><button type="button" class="sv-btn sm" id="svRecharge">充值</button><button type="button" class="sv-btn sm ghost" id="svBalanceRefresh" title="刷新余额"><i class="fas fa-sync-alt"></i></button></div></aside>
    <?php else: ?>
    <div class="sv-globe" aria-hidden="true"><span></span><span></span><span></span><i class="fas fa-server"></i></div>
    <?php endif; ?>
  </section>
  <?php if (!$configured): ?><div class="sv-notice warn">尚未配置雅云接口。请超级管理员到「接口设置」填写雅云站点地址、API 账号和密码，之后这里才能开通和管理服务器。</div><?php endif; ?>
  <nav class="sv-tabs" id="svTabs" role="tablist">
    <button type="button" class="active" data-tab="catalog"><i class="fas fa-globe-asia"></i> 服务器目录</button>
    <button type="button" data-tab="hosts"><i class="fas fa-hdd"></i> 我的服务器 <span class="sv-count" id="svHostCount"></span></button>
    <?php if ($canProvision): ?><button type="button" data-tab="orders"><i class="fas fa-receipt"></i> 开通记录</button><?php endif; ?>
    <?php if ($isSuper): ?><button type="button" data-tab="settings"><i class="fas fa-user-shield"></i> 开通权限</button><?php endif; ?>
  </nav>
  <div id="svNotice" class="sv-notice" role="status" aria-live="polite" hidden></div>

  <section id="svCatalog" class="sv-panel">
    <div class="sv-toolbar">
      <label class="sv-search"><i class="fas fa-search"></i><input id="svQuery" placeholder="搜索套餐 / 配置 / 机房，如 4核 香港 CN2" maxlength="60" autocomplete="off"></label>
      <label class="sv-switch"><input type="checkbox" id="svOnlyStock"><span>只看有货</span></label>
      <span class="sv-updated" id="svUpdated"></span>
    </div>
    <div class="sv-rail" id="svCountries" aria-label="按国家 / 地区筛选"></div>
    <div class="sv-pills" id="svCategories" aria-label="按品类筛选"></div>
    <div id="svGrid"></div>
  </section>

  <section id="svHosts" class="sv-panel" hidden>
    <div class="sv-toolbar"><label class="sv-search"><i class="fas fa-search"></i><input id="svHostQuery" placeholder="搜索备注 / 主机名 / IP / 套餐" maxlength="60" autocomplete="off"></label>
      <div class="sv-seg" id="svHostFilter"><button type="button" class="active" data-f="all">全部</button><button type="button" data-f="Active">运行中</button><button type="button" data-f="soon">即将到期</button><button type="button" data-f="other">已删除 / 已过期</button></div>
      <button type="button" class="sv-btn ghost" id="svHostRefresh"><i class="fas fa-sync-alt"></i> 刷新</button></div>
    <div id="svHostList"><p class="sv-empty">点击上方「我的服务器」加载…</p></div>
  </section>
  <?php if ($canProvision): ?><section id="svOrders" class="sv-panel" hidden><div id="svOrderList"></div></section><?php endif; ?>
  <?php if ($isSuper): ?><section id="svSettings" class="sv-panel" hidden>
    <h2>谁可以开通新服务器</h2>
    <p class="sv-help">开通会从雅云账号余额真实扣费，所以只有超级管理员和下面名单里的员工可以开通、续费、重装、查看登录密码、停用。其他有域名管理权限的人可以查看目录价格、查看主机状态并做开机 / 关机 / 重启 / VNC / 监控。</p>
    <div id="svProvisioners" class="sv-checks"></div>
    <button type="button" class="sv-btn" id="svSaveProvisioners">保存名单</button>
  </section><?php endif; ?>

  <dialog id="svDrawer" class="sv-drawer"><div class="sv-drawer-head"><div><span class="sv-kicker">SERVER</span><h2 id="svDrawerTitle">服务器</h2></div><button type="button" class="sv-close" id="svDrawerClose" aria-label="关闭">×</button></div><div class="sv-notice" id="svDrawerMsg" hidden style="margin:12px 24px 0"></div><div class="sv-drawer-body" id="svDrawerBody"></div></dialog>
  <dialog id="svBuy" class="sv-drawer wide"><div class="sv-drawer-head"><div><span class="sv-kicker">NEW SERVER</span><h2 id="svBuyTitle">开通服务器</h2></div><button type="button" class="sv-close" id="svBuyClose" aria-label="关闭">×</button></div><div class="sv-notice" id="svBuyMsg" hidden style="margin:12px 24px 0;position:sticky;top:76px;z-index:5;box-shadow:0 6px 20px rgba(0,0,0,.35)"></div><div class="sv-drawer-body" id="svBuyBody"></div></dialog>
</main>
<script id="svBoot" type="application/json"><?php echo json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<script src="<?php echo BASE_URL; ?>/assets/js/servers.js?v=20261122a" defer></script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
