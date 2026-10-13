<?php
/**
 * 域名管理 · 解析管理（DNSPod / Cloudflare）。
 * 权限与“域名管理”一致（bd_can_access）；凭据来自“接口设置”；所有改动写审计日志（entity=dns）。
 */
require_once __DIR__ . '/../includes/BizcnDomains.php';
require_once __DIR__ . '/../includes/DnsProviders.php';
$actor = ps_require_actor();
if (!bd_can_access($actor)) { http_response_code(403); exit('当前账户未分配域名管理权限'); }
header('Cache-Control: no-store, max-age=0');
header('Referrer-Policy: same-origin');
header('X-Robots-Tag: noindex');

$flash = $_SESSION['dns_flash'] ?? null;
unset($_SESSION['dns_flash']);
$go = function ($url, $notice = null) {
    if ($notice) $_SESSION['dns_flash'] = $notice;
    header('Location: ' . BASE_URL . '/project/dns.php' . $url);
    if (PHP_SAPI === 'cli') return;
    exit;
};
$short = function ($text) { return mb_substr((string)$text, 0, 120); };
$domain = trim((string)($_GET['d'] ?? $_POST['d'] ?? ''));
$provider = (string)($_GET['p'] ?? $_POST['p'] ?? '');
if ($provider !== '' && !isset(DNS_PROVIDERS[$provider])) $provider = '';
$error = '';
$cdnPrefill = null;
$copyPlan = null;
$srcLabels = DNS_PROVIDERS + ['bizcn' => '商务中国'];
$copyFrom = '';
$copyTo = '';

// 读取两家的域名列表；某一家读不到不影响另一家。
$zonesBy = []; $providerErrors = [];
foreach (DNS_PROVIDERS as $key => $label) {
    $zonesBy[$key] = [];
    if (!dns_provider_configured($key)) { $providerErrors[$key] = '尚未配置，请管理员到“接口设置”保存密钥'; continue; }
    try { foreach (dns_zones($key) as $z) $zonesBy[$key][$z['domain']] = $z; } catch (Throwable $e) { $providerErrors[$key] = $e instanceof RuntimeException ? $e->getMessage() : '暂时读取失败，请稍后重试'; }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['cdn_domain'], $_GET['cdn_cname'])) {
    try {
        $cdnPrefill = dns_cdn_prefill((string)$_GET['cdn_domain'], (string)$_GET['cdn_cname'], $zonesBy, $provider);
        if (!$cdnPrefill) throw new RuntimeException('所选解析账号中没有覆盖此加速域名的主域名。请先添加实际所属主域名，再按 CDN 页面提供的 CNAME 配置记录；本页没有修改任何解析。');
        $domain = $cdnPrefill['domain']; $provider = $cdnPrefill['provider'];
    } catch (RuntimeException $e) { $error = $e->getMessage(); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'add_zone' || $action === 'create_zone') {
            if (!isset(DNS_PROVIDERS[(string)($_POST['provider'] ?? '')])) throw new RuntimeException('请选择解析服务商');
            $target = (string)$_POST['provider'];
            $name = dns_validate_domain($_POST['domain'] ?? '');
            if (isset($zonesBy[$target][$name])) throw new RuntimeException($name . ' 已经在 ' . DNS_PROVIDERS[$target] . ' 里了，直接管理即可');
            $created = dns_create_zone($target, $name);
            ps_audit('dns', 0, 'zone_create', $actor, ['provider' => $target, 'domain' => $name, 'ns' => $created['ns']]);
            $go('?d=' . rawurlencode($name) . '&p=' . $target, ['ok' => $name . ' 已添加到 ' . DNS_PROVIDERS[$target] . '。现在把域名的 NS 改成下面的值，解析才会交给它。', 'ns' => $created['ns'], 'ns_domain' => $name]);
        } elseif ($action === 'record_save' || $action === 'record_delete') {
            $name = dns_validate_domain($domain);
            if (!$provider || !isset($zonesBy[$provider][$name])) throw new RuntimeException('该域名不在所选服务商里');
            $zone = $zonesBy[$provider][$name];
            $recordId = trim((string)($_POST['record_id'] ?? ''));
            if ($action === 'record_delete') {
                $existing = null;
                foreach (dns_records($provider, $zone) as $r) if ($r['id'] === $recordId) $existing = $r;
                if (!$existing || !$existing['editable']) throw new RuntimeException('这条记录不存在或不支持在这里删除');
                dns_record_delete($provider, $zone, $recordId);
                ps_audit('dns', 0, 'record_delete', $actor, ['provider' => $provider, 'domain' => $name, 'type' => $existing['type'], 'name' => $existing['name'], 'value' => $short($existing['value'])]);
                $go('?d=' . rawurlencode($name) . '&p=' . $provider, ['ok' => '已删除 ' . $existing['type'] . ' 记录 ' . $existing['name']]);
            } else {
                $rec = dns_validate_record($_POST['type'] ?? '', $_POST['name'] ?? '', $_POST['value'] ?? '', $_POST['ttl'] ?? 600, $_POST['priority'] ?? null, !empty($_POST['proxied']), $provider);
                if ($recordId !== '') {
                    $existing = null;
                    foreach (dns_records($provider, $zone) as $r) if ($r['id'] === $recordId) $existing = $r;
                    if (!$existing || !$existing['editable']) throw new RuntimeException('这条记录已变化或不支持修改，请刷新后再试');
                    dns_record_update($provider, $zone, $recordId, $rec, $existing['line']);
                    ps_audit('dns', 0, 'record_update', $actor, ['provider' => $provider, 'domain' => $name, 'type' => $rec['type'], 'name' => $rec['name'], 'old' => $short($existing['value']), 'value' => $short($rec['value'])]);
                    $go('?d=' . rawurlencode($name) . '&p=' . $provider, ['ok' => '已修改 ' . $rec['type'] . ' 记录 ' . $rec['name']]);
                } else {
                    dns_record_create($provider, $zone, $rec);
                    ps_audit('dns', 0, 'record_create', $actor, ['provider' => $provider, 'domain' => $name, 'type' => $rec['type'], 'name' => $rec['name'], 'value' => $short($rec['value'])]);
                    $go('?d=' . rawurlencode($name) . '&p=' . $provider, ['ok' => '已添加 ' . $rec['type'] . ' 记录 ' . $rec['name']]);
                }
            }
        } elseif ($action === 'copy_preview' || $action === 'copy_run') {
            $name = dns_validate_domain($domain);
            $copyFrom = (string)($_POST['from'] ?? ''); $copyTo = (string)($_POST['to'] ?? '');
            if ((!isset(DNS_PROVIDERS[$copyFrom]) && $copyFrom !== 'bizcn') || !isset(DNS_PROVIDERS[$copyTo]) || $copyFrom === $copyTo) throw new RuntimeException('请选择要复制到的另一家服务商');
            if ($copyFrom === 'bizcn') {
                bd_require_owned($name); // 实时核验：域名确实在商务中国账户里
                $fromRecords = dns_normalize_bizcn_rows(bd_rows(bd_call('infoDnsRecordService', 'infoDnsRecord', ['domainname' => $name])['records']['record'] ?? []));
            } else {
                if (!isset($zonesBy[$copyFrom][$name])) throw new RuntimeException('来源服务商里没有这个域名');
                $fromRecords = dns_records($copyFrom, $zonesBy[$copyFrom][$name]);
            }
            if (!isset($zonesBy[$copyTo][$name])) throw new RuntimeException('请先在 ' . DNS_PROVIDERS[$copyTo] . ' 里添加这个域名，再复制解析');
            $copyPlan = dns_copy_plan($fromRecords, dns_records($copyTo, $zonesBy[$copyTo][$name]), $copyTo);
            if ($action === 'copy_run') {
                $done = 0; $failed = [];
                foreach (array_slice($copyPlan['add'], 0, 300) as $rec) {
                    try { dns_record_create($copyTo, $zonesBy[$copyTo][$name], dns_validate_record($rec['type'], $rec['name'], $rec['value'], $rec['ttl'], $rec['priority'], false, $copyTo)); $done++; }
                    catch (Throwable $e) { $failed[] = $rec['type'] . ' ' . $rec['name'] . '：' . ($e instanceof RuntimeException ? $e->getMessage() : '失败'); }
                }
                ps_audit('dns', 0, 'records_copy', $actor, ['domain' => $name, 'from' => $copyFrom, 'to' => $copyTo, 'copied' => $done, 'failed' => count($failed)]);
                $go('?d=' . rawurlencode($name) . '&p=' . $copyTo, ['ok' => '已把 ' . $srcLabels[$copyFrom] . ' 的 ' . $done . ' 条解析复制到 ' . DNS_PROVIDERS[$copyTo] . ($failed ? '，' . count($failed) . ' 条失败' : '') . '。确认无误后再修改 NS。', 'failed' => $failed]);
            }
        } elseif ($action === 'bizcn_ns') {
            $name = dns_validate_domain($domain);
            if (!$provider || !isset($zonesBy[$provider][$name])) throw new RuntimeException('请先把域名添加到所选服务商');
            if (trim((string)($_POST['confirm'] ?? '')) !== $name) throw new RuntimeException('请输入域名以确认');
            $ns = dns_zone_ns($provider, $zonesBy[$provider][$name]);
            if (count($ns) < 2) throw new RuntimeException('没有取到该服务商分配的 NS，请稍后重试');
            bd_require_owned($name); // 实时核验：该域名确实在商务中国账户中
            $nsValue = bd_ns(implode("\n", $ns));
            ps_audit('dns', 0, 'bizcn_ns_requested', $actor, ['domain' => $name, 'provider' => $provider, 'ns' => $nsValue]);
            bd_call('modDomainDnsService', 'modDomainDns', ['moddomaindns' => ['domainname' => $name, 'dns_host' => $nsValue]]);
            ps_audit('dns', 0, 'bizcn_ns_success', $actor, ['domain' => $name, 'provider' => $provider, 'ns' => $nsValue]);
            $go('?d=' . rawurlencode($name) . '&p=' . $provider, ['ok' => 'NS 修改请求已提交给商务中国，全球生效需要等待 DNS 缓存更新（通常几分钟到 48 小时）。']);
        } else throw new RuntimeException('操作无效');
    } catch (Throwable $e) {
        $error = ($e instanceof RuntimeException || $e instanceof InvalidArgumentException) ? $e->getMessage() : '暂时未能完成操作，请稍后重试';
        if (!($e instanceof RuntimeException || $e instanceof InvalidArgumentException)) error_log('DNS management failed: ' . get_class($e));
    }
}

$csrf = ps_csrf_token();
$detail = null;
if ($domain !== '') {
    try { $domain = dns_validate_domain($domain); } catch (RuntimeException $e) { $error = $error ?: $e->getMessage(); $domain = ''; }
}
if ($domain !== '') {
    $have = [];
    foreach (DNS_PROVIDERS as $key => $label) if (isset($zonesBy[$key][$domain])) $have[] = $key;
    $publicNs = dns_public_ns($domain);
    // 默认查看正在解析的那一家（公网 NS 指向的），没有则取第一家。
    if (!$provider || !isset($zonesBy[$provider][$domain])) $provider = in_array(dns_ns_resolver($publicNs), $have, true) ? dns_ns_resolver($publicNs) : ($have[0] ?? '');
    $detail = ['have' => $have, 'public_ns' => $publicNs, 'resolver' => dns_ns_resolver($publicNs), 'records' => [], 'ns' => [], 'record_error' => '', 'bizcn' => false];
    if ($provider) {
        try {
            $detail['records'] = dns_records($provider, $zonesBy[$provider][$domain]);
            $detail['ns'] = dns_zone_ns($provider, $zonesBy[$provider][$domain]);
        } catch (Throwable $e) { $detail['record_error'] = $e instanceof RuntimeException ? $e->getMessage() : '暂时读取失败，请稍后重试'; }
    }
    if (function_exists('bc_find')) { try { $detail['bizcn'] = (bool)bc_find($domain); } catch (Throwable $e) { $detail['bizcn'] = false; } }
}
$resolverLabel = ['cloudflare' => 'Cloudflare 解析中', 'dnspod' => 'DNSPod 解析中', 'mixed' => 'NS 混用（异常）', 'other' => '其他服务商解析中', 'none' => '未检测到 NS'];
$page_title = '解析管理';
include __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/dns.css?v=20261013-contrast">
<main class="dns-console" data-csrf="<?php echo e($csrf); ?>">
  <section class="dns-hero">
    <div><div class="dns-kicker">DNS · <?php echo $domain !== '' ? e($domain) : 'DNSPod / Cloudflare'; ?></div>
      <h1><?php echo $domain !== '' ? '解析管理' : '让域名，选择自己的解析服务商'; ?></h1>
      <p><?php echo $domain !== '' ? '直接在本站添加、编辑或删除解析记录，无需登录腾讯云或 Cloudflare；换解析商时先复制记录，再修改 NS。' : '通过已配置的 API，直接在本站添加域名、管理 DNSPod / Cloudflare 解析记录和复制解析。商务中国域名也可在本站修改 NS，无需另开腾讯云控制台。'; ?></p></div>
    <a class="dns-btn ghost" href="<?php echo BASE_URL; ?>/project/domains.php"><i class="fas fa-arrow-left"></i> 返回域名管理</a>
  </section>
  <?php if ($error): ?><div class="dns-notice error" role="alert" id="dnsSaveNotice" tabindex="-1"><?php echo e($error); ?></div><?php endif; ?>
  <?php if ($flash && !empty($flash['ok'])): ?><div class="dns-notice ok" role="status" id="dnsSuccessNotice" tabindex="-1"><?php echo e($flash['ok']); ?>
    <?php if (!empty($flash['ns'])): ?><div class="dns-ns-box"><strong>请到域名注册商处，把 <?php echo e($flash['ns_domain']); ?> 的 NS 改为：</strong><?php foreach ($flash['ns'] as $ns): ?><code><?php echo e($ns); ?></code><?php endforeach; ?><button type="button" class="dns-btn ghost" data-copy="<?php echo e(implode("\n", $flash['ns'])); ?>">复制全部</button></div><?php endif; ?>
    <?php if (!empty($flash['failed'])): ?><ul class="dns-fail"><?php foreach ($flash['failed'] as $f): ?><li><?php echo e($f); ?></li><?php endforeach; ?></ul><?php endif; ?></div><?php endif; ?>
  <?php foreach ($providerErrors as $key => $message): ?><div class="dns-notice warn"><strong><?php echo e(DNS_PROVIDERS[$key]); ?></strong>：<?php echo e($message); ?></div><?php endforeach; ?>

<?php if ($domain === ''): ?>
  <section class="dns-card">
    <h2>添加域名</h2>
    <p class="dns-help">填写主域名，选择由谁来解析。创建成功后会给出要去注册商（如商务中国）修改的 NS。<br>Cloudflare 添加新域名需要“创建区域”权限的令牌，没有时会提示；DNSPod 可直接添加。</p>
    <form method="post" class="dns-form-row"><input type="hidden" name="csrf" value="<?php echo e($csrf); ?>"><input type="hidden" name="action" value="add_zone">
      <label class="dns-grow">域名<input name="domain" required maxlength="253" placeholder="example.com" autocomplete="off"></label>
      <fieldset class="dns-radio"><legend>解析服务商</legend>
        <?php foreach (DNS_PROVIDERS as $key => $label): ?><label><input type="radio" name="provider" value="<?php echo e($key); ?>" <?php echo $key === 'dnspod' ? 'checked' : ''; ?>> <?php echo e($label); ?></label><?php endforeach; ?></fieldset>
      <button class="dns-btn" type="submit"><i class="fas fa-plus"></i> 添加并获取 NS</button></form>
  </section>
  <section class="dns-card">
    <h2>我的域名</h2>
    <?php
    $all = [];
    foreach (DNS_PROVIDERS as $key => $label) foreach ($zonesBy[$key] as $name => $z) $all[$name][$key] = $z;
    ksort($all);
    ?>
    <div class="dns-table-wrap"><table class="dns-table"><thead><tr><th>域名</th><th>DNSPod</th><th>Cloudflare</th><th>当前公网 NS（谁在解析）</th><th></th></tr></thead><tbody>
    <?php if (!$all): ?><tr><td colspan="5" class="dns-empty">两家服务商里都还没有域名。先在上面添加一个。</td></tr><?php endif; ?>
    <?php $lookups = 0; foreach ($all as $name => $by):
        $ns = $lookups++ < 60 ? dns_public_ns($name) : []; $resolver = dns_ns_resolver($ns); ?>
      <tr><td><a class="dns-domain" href="?d=<?php echo rawurlencode($name); ?>"><?php echo e($name); ?></a></td>
        <?php foreach (['dnspod', 'cloudflare'] as $key): ?><td><?php echo isset($by[$key]) ? '<span class="dns-chip on"><i class="fas fa-check"></i> 已添加' . ($by[$key]['records'] !== null ? ' · ' . (int)$by[$key]['records'] . ' 条' : '') . '</span>' : '<span class="dns-chip">未添加</span>'; ?></td><?php endforeach; ?>
        <td><span class="dns-chip <?php echo isset($by[$resolver]) ? 'on' : ($resolver === 'none' ? '' : 'warn'); ?>"><?php echo e($resolverLabel[$resolver]); ?></span><small><?php echo e(implode(' / ', $ns)); ?></small></td>
        <td><a class="dns-btn ghost" href="?d=<?php echo rawurlencode($name); ?>">管理 <i class="fas fa-arrow-right"></i></a></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <p class="dns-help">“当前公网 NS”是实时查询结果：如果它指向的服务商里没有这个域名（或解析不全），网站和邮箱会受影响。</p>
  </section>

<?php else: ?>
  <section class="dns-card dns-status">
    <div><h2>解析状态</h2>
      <p>当前公网 NS：<?php if ($detail['public_ns']): foreach ($detail['public_ns'] as $ns): ?><code><?php echo e($ns); ?></code><?php endforeach; else: ?><span class="dns-muted">未查到</span><?php endif; ?></p>
      <p><span class="dns-chip <?php echo in_array($detail['resolver'], ['cloudflare', 'dnspod'], true) ? 'on' : 'warn'; ?>"><?php echo e($resolverLabel[$detail['resolver']]); ?></span>
        <?php if (in_array($detail['resolver'], ['cloudflare', 'dnspod'], true) && !in_array($detail['resolver'], $detail['have'], true)): ?><span class="dns-chip warn">但该服务商账号里没有这个域名</span><?php endif; ?></p></div>
    <div class="dns-providers"><?php foreach (DNS_PROVIDERS as $key => $label): $has = in_array($key, $detail['have'], true); ?>
      <div class="dns-provider <?php echo $has ? 'has' : ''; ?> <?php echo $key === $provider ? 'current' : ''; ?>"><strong><?php echo e($label); ?></strong>
        <?php if ($has): ?><a href="?d=<?php echo rawurlencode($domain); ?>&p=<?php echo e($key); ?>"><?php echo $key === $provider ? '正在查看' : '查看解析'; ?></a>
        <?php else: ?><form method="post"><input type="hidden" name="csrf" value="<?php echo e($csrf); ?>"><input type="hidden" name="action" value="create_zone"><input type="hidden" name="domain" value="<?php echo e($domain); ?>"><input type="hidden" name="provider" value="<?php echo e($key); ?>"><button class="dns-link" type="submit">在 <?php echo e($label); ?> 添加此域名</button></form><?php endif; ?></div>
    <?php endforeach; ?></div>
  </section>

  <?php $guideProvider = $provider ?: (dns_provider_configured('dnspod') ? 'dnspod' : 'cloudflare');
    if (dns_provider_configured($guideProvider)):
      $guideLabel = DNS_PROVIDERS[$guideProvider];
      $guideHas = in_array($guideProvider, $detail['have'], true);
      $guideNs = $guideHas ? $detail['ns'] : [];
      $guideNsLive = $guideHas && $guideNs && !array_diff($guideNs, $detail['public_ns']);
      $sources = [];
      if ($detail['bizcn']) $sources['bizcn'] = '商务中国';
      foreach ($detail['have'] as $k) if ($k !== $guideProvider) $sources[$k] = DNS_PROVIDERS[$k];
  ?>
  <section class="dns-card dns-guide">
    <h2><i class="fas fa-cloud" aria-hidden="true"></i> 接入 <?php echo e($guideLabel); ?> 解析 <?php echo $guideNsLive ? '<span class="dns-chip on"><i class="fas fa-check"></i> 已完成</span>' : ''; ?></h2>
    <p class="dns-help">下面的创建域名、复制解析和记录增删改都在本站完成，无需登录 <?php echo $guideProvider === 'dnspod' ? '腾讯云' : 'Cloudflare'; ?>。尚未接入时按三步操作：添加域名 → 复制并核对记录 → 修改注册商 NS。已经接入的域名，直接使用下方“添加记录”或“编辑”。</p>
    <?php if (in_array($detail['resolver'], ['other', 'mixed'], true)): ?><div class="dns-notice warn">当前公网 NS 指向其它服务商或存在混用。请先到当前实际使用的解析后台核对全部记录；商务中国或其它账号中保存的记录可能不是正在生效的记录。原服务商不在本页支持范围时，请在 <?php echo e($guideLabel); ?> 的“添加记录”中逐条录入，确认网站、邮箱所需记录齐全后再改 NS。</div><?php endif; ?>
    <ol class="dns-steps">
      <li class="<?php echo $guideHas ? 'done' : 'todo'; ?>"><div><strong>① 在本站添加到 <?php echo e($guideLabel); ?></strong><small><?php echo $guideHas ? '已添加，' . e($guideLabel) . ' 分配的 NS：' . e(implode('、', $guideNs)) : '点击下面按钮，通过 API 添加并获取实际分配的 NS（第 3 步用）。'; ?></small></div>
        <?php if (!$guideHas): ?><form method="post"><input type="hidden" name="csrf" value="<?php echo e($csrf); ?>"><input type="hidden" name="action" value="create_zone"><input type="hidden" name="domain" value="<?php echo e($domain); ?>"><input type="hidden" name="provider" value="<?php echo e($guideProvider); ?>"><button class="dns-btn" type="submit">添加到 <?php echo e($guideLabel); ?></button></form><?php else: ?><span class="dns-chip on"><i class="fas fa-check"></i> 已完成</span><?php endif; ?></li>
      <li class="<?php echo !$guideHas ? 'wait' : 'todo'; ?>"><div><strong>② 复制现有解析到 <?php echo e($guideLabel); ?></strong><small>预览后确认复制（只新增、不覆盖），并核对网站、邮箱所需记录，再修改 NS。</small></div>
        <?php if (!$guideHas): ?><span class="dns-muted">先完成第 ①步</span>
        <?php elseif (!$sources): ?><span class="dns-muted">没有可直接复制的其它服务商记录：使用下方“添加记录”填写，或按现有记录直接管理。迁移时请保留网站的 A / CNAME、邮箱的 MX / TXT 等记录。</span>
        <?php else: foreach ($sources as $k => $label): ?><form method="post"><input type="hidden" name="csrf" value="<?php echo e($csrf); ?>"><input type="hidden" name="action" value="copy_preview"><input type="hidden" name="d" value="<?php echo e($domain); ?>"><input type="hidden" name="p" value="<?php echo e($guideProvider); ?>"><input type="hidden" name="from" value="<?php echo e($k); ?>"><input type="hidden" name="to" value="<?php echo e($guideProvider); ?>"><button class="dns-btn ghost" type="submit">预览：从 <?php echo e($label); ?> 复制</button></form><?php endforeach; endif; ?></li>
      <li class="<?php echo $guideNsLive ? 'done' : ($guideHas ? 'todo' : 'wait'); ?>"><div><strong>③ 把注册商 NS 改成 <?php echo e($guideLabel); ?> 的</strong><small><?php echo $guideNsLive ? '公网 NS 已指向 ' . e($guideLabel) . '，无需再次修改，直接在本站管理解析记录。' : ($detail['bizcn'] ? '域名在商务中国账户里，可以在本站修改，无需另开后台。' : '域名不在商务中国账户里：仅首次接入需要到它所在的注册商修改 NS；解析记录仍在本站管理。'); ?></small></div>
        <?php if ($guideHas && !$guideNsLive): ?><a class="dns-btn" href="?d=<?php echo rawurlencode($domain); ?>&p=<?php echo e($guideProvider); ?>#dnsNs">在本站查看并修改 NS</a><?php elseif (!$guideHas): ?><span class="dns-muted">先完成第 ①步</span><?php endif; ?></li>
    </ol>
  </section>
  <?php endif; ?>
  <?php if ($copyPlan !== null): ?>
  <div class="dns-plan"><h3>预览：<?php echo e($srcLabels[$copyFrom]); ?> → <?php echo e(DNS_PROVIDERS[$copyTo]); ?></h3>
    <p>将新增 <strong><?php echo count($copyPlan['add']); ?></strong> 条，已存在跳过 <?php echo (int)$copyPlan['existing']; ?> 条，无法复制 <?php echo count($copyPlan['skipped']); ?> 条<?php if ($copyPlan['proxied']): ?>；其中 <?php echo (int)$copyPlan['proxied']; ?> 条在 Cloudflare 上经过代理，复制后变成直接解析到源站 IP<?php endif; ?>。</p>
    <?php if ($copyPlan['add']): ?><div class="dns-table-wrap"><table class="dns-table"><tbody><?php foreach ($copyPlan['add'] as $r): ?><tr><td><span class="dns-type"><?php echo e($r['type']); ?></span></td><td><?php echo e($r['name']); ?></td><td><code class="dns-value"><?php echo e($r['value']); ?></code></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    <?php if ($copyPlan['skipped']): ?><details><summary>无法复制的记录（<?php echo count($copyPlan['skipped']); ?>）</summary><ul class="dns-fail"><?php foreach ($copyPlan['skipped'] as [$r, $why]): ?><li><?php echo e($r['type'] . ' ' . $r['name'] . ' ' . mb_substr($r['value'], 0, 60)); ?> — <?php echo e($why); ?></li><?php endforeach; ?></ul></details><?php endif; ?>
    <?php if ($copyPlan['add']): ?><form method="post"><input type="hidden" name="csrf" value="<?php echo e($csrf); ?>"><input type="hidden" name="action" value="copy_run"><input type="hidden" name="d" value="<?php echo e($domain); ?>"><input type="hidden" name="from" value="<?php echo e($copyFrom); ?>"><input type="hidden" name="to" value="<?php echo e($copyTo); ?>"><button class="dns-btn" type="submit" onclick="return confirm('确认向 <?php echo e(DNS_PROVIDERS[$copyTo]); ?> 新增 <?php echo count($copyPlan['add']); ?> 条解析记录？');">确认复制</button></form>
    <?php else: ?><p class="dns-help">没有需要新增的记录。</p><?php endif; ?></div>
  <?php endif; ?>

  <?php if ($provider): $zoneNs = $detail['ns']; $nsLive = $zoneNs && !array_diff($zoneNs, $detail['public_ns']); ?>
  <section class="dns-card" id="dnsNs">
    <h2><?php echo e(DNS_PROVIDERS[$provider]); ?> 要求的 NS <?php echo $nsLive ? '<span class="dns-chip on"><i class="fas fa-check"></i> 已生效</span>' : '<span class="dns-chip warn">尚未生效</span>'; ?></h2>
    <?php if ($zoneNs): ?><div class="dns-ns-box"><?php foreach ($zoneNs as $ns): ?><code><?php echo e($ns); ?></code><?php endforeach; ?><button type="button" class="dns-btn ghost" data-copy="<?php echo e(implode("\n", $zoneNs)); ?>">复制</button></div>
    <p class="dns-help">到域名注册商处，把 NS 改成上面这两条。改完全球生效需要几分钟到 48 小时，刷新本页可以看到“已生效”。<strong>改之前请确认下面已有完整的解析记录</strong>，否则网站和邮箱会中断。</p>
    <?php if ($detail['bizcn'] && !$nsLive): ?>
    <form method="post" class="dns-form-row dns-bizcn"><input type="hidden" name="csrf" value="<?php echo e($csrf); ?>"><input type="hidden" name="action" value="bizcn_ns"><input type="hidden" name="d" value="<?php echo e($domain); ?>"><input type="hidden" name="p" value="<?php echo e($provider); ?>">
      <?php if (isset(DNS_PROVIDERS[$detail['resolver']]) && $detail['resolver'] !== $provider): ?><div class="dns-notice warn dns-grow" style="flex-basis:100%;margin:0">当前由 <strong><?php echo e(DNS_PROVIDERS[$detail['resolver']]); ?></strong> 解析这个域名。NS 生效后，解析将切换到 <?php echo e(DNS_PROVIDERS[$provider]); ?>：请先核对下面的记录与 <?php echo e(DNS_PROVIDERS[$detail['resolver']]); ?> 一致（可用下方“复制解析”）。生效期间旧解析也要保持可用。</div><?php endif; ?>
      <label class="dns-grow">这个域名在商务中国账户里，可以直接改 NS。输入域名确认<input name="confirm" required autocomplete="off" placeholder="<?php echo e($domain); ?>"></label>
      <button class="dns-btn" type="submit" onclick="return confirm('确认把 <?php echo e($domain); ?> 的 NS 改为 <?php echo e(DNS_PROVIDERS[$provider]); ?> 的 NS？\n请确保下面已有完整解析。');"><i class="fas fa-random"></i> 一键改为 <?php echo e(DNS_PROVIDERS[$provider]); ?> 解析</button></form>
    <?php elseif (!$nsLive): ?><p class="dns-help">不在商务中国账户里的域名，请到它所在的注册商后台修改 NS。</p><?php endif; ?>
    <?php else: ?><p class="dns-help">暂时没有取到该服务商分配的 NS。</p><?php endif; ?>
  </section>

  <section class="dns-card">
    <div class="dns-card-head"><h2><?php echo e(DNS_PROVIDERS[$provider]); ?> 解析记录 <small><?php echo count($detail['records']); ?> 条</small></h2><button class="dns-btn" type="button" id="dnsAddBtn"><i class="fas fa-plus"></i> 添加记录</button></div>
    <?php if ($detail['record_error']): ?><div class="dns-notice error"><?php echo e($detail['record_error']); ?></div><?php endif; ?>
    <?php if ($cdnPrefill): ?><div class="dns-notice warn">已预填七牛 CDN 的 CNAME，尚未修改解析。请核对同名的 A / AAAA / CNAME 记录和源站设置，确认后再保存；已有同名记录请使用“编辑”，不要重复添加。Cloudflare 使用“仅 DNS”。</div><?php endif; ?>
    <form method="post" class="dns-record-form" id="dnsRecordForm" <?php echo $cdnPrefill?'':'hidden'; ?>><input type="hidden" name="csrf" value="<?php echo e($csrf); ?>"><input type="hidden" name="action" value="record_save"><input type="hidden" name="d" value="<?php echo e($domain); ?>"><input type="hidden" name="p" value="<?php echo e($provider); ?>"><input type="hidden" name="record_id" id="rId">
      <h3 id="rTitle">添加记录</h3>
      <p class="dns-help" style="flex-basis:100%;margin:0">主机记录：@ 表示主域名，www 表示 www.<?php echo e($domain); ?>。A 记录填服务器 IPv4，AAAA 填 IPv6，CNAME 填目标域名，MX / TXT 按邮箱或验证服务给出的内容填写。迁移时先保留原记录的 TTL 和代理设置，不确定时请技术人员核对。</p>
      <label>类型<select name="type" id="rType"><?php foreach (DNS_EDIT_TYPES as $t): ?><option <?php echo $cdnPrefill && $t==='CNAME'?'selected':''; ?>><?php echo e($t); ?></option><?php endforeach; ?></select></label>
      <label>主机记录<input name="name" id="rName" required maxlength="200" placeholder="@ 或 www" autocomplete="off" value="<?php echo e($cdnPrefill['name']??''); ?>"></label>
      <label class="dns-grow">记录值<input name="value" id="rValue" required maxlength="2048" placeholder="IP 或域名" autocomplete="off" value="<?php echo e($cdnPrefill['value']??''); ?>"></label>
      <label>TTL（秒）<input name="ttl" id="rTtl" type="number" value="600" min="1" max="86400"></label>
      <label id="rPriorityWrap">MX 优先级<input name="priority" id="rPriority" type="number" value="10" min="0" max="65535"></label>
      <?php if ($provider === 'cloudflare'): ?><label class="dns-check"><input type="checkbox" name="proxied" id="rProxied" value="1"> 经 Cloudflare 代理（橙云）</label><?php endif; ?>
      <div class="dns-form-actions"><button class="dns-btn" type="submit">保存</button><button class="dns-btn ghost" type="button" id="rCancel">取消</button></div></form>
    <div class="dns-table-wrap"><table class="dns-table"><thead><tr><th>类型</th><th>主机记录</th><th>记录值</th><th>TTL</th><?php if ($provider === 'cloudflare'): ?><th>代理</th><?php endif; ?><th></th></tr></thead><tbody>
    <?php if (!$detail['records'] && !$detail['record_error']): ?><tr><td colspan="6" class="dns-empty">还没有解析记录。</td></tr><?php endif; ?>
    <?php foreach ($detail['records'] as $r): ?>
      <tr class="<?php echo $r['enabled'] ? '' : 'off'; ?>"><td><span class="dns-type"><?php echo e($r['type']); ?></span></td><td><strong><?php echo e($r['name']); ?></strong><?php if ($r['line'] !== '' && $r['line'] !== '默认'): ?><small>线路：<?php echo e($r['line']); ?></small><?php endif; ?></td>
        <td><code class="dns-value"><?php echo e($r['value']); ?></code><?php if ($r['type'] === 'MX'): ?><small>优先级 <?php echo (int)$r['priority']; ?></small><?php endif; ?><?php if (!$r['enabled']): ?><small>已停用</small><?php endif; ?></td>
        <td><?php echo $r['ttl'] === 1 ? '自动' : (int)$r['ttl']; ?></td>
        <?php if ($provider === 'cloudflare'): ?><td><?php echo $r['proxied'] === null ? '' : ($r['proxied'] ? '<span class="dns-chip on">已代理</span>' : '<span class="dns-chip">仅 DNS</span>'); ?></td><?php endif; ?>
        <td class="dns-actions"><?php if ($r['editable']): ?>
          <button type="button" class="dns-btn ghost" data-edit='<?php echo e(json_encode(['id' => $r['id'], 'type' => $r['type'], 'name' => $r['name'], 'value' => $r['value'], 'ttl' => $r['ttl'], 'priority' => $r['priority'], 'proxied' => (bool)$r['proxied']], JSON_UNESCAPED_UNICODE)); ?>'>编辑</button>
          <form method="post" onsubmit="return confirm('确认删除 <?php echo e($r['type'] . ' ' . $r['name']); ?>？可能影响网站或邮箱。');"><input type="hidden" name="csrf" value="<?php echo e($csrf); ?>"><input type="hidden" name="action" value="record_delete"><input type="hidden" name="d" value="<?php echo e($domain); ?>"><input type="hidden" name="p" value="<?php echo e($provider); ?>"><input type="hidden" name="record_id" value="<?php echo e($r['id']); ?>"><button class="dns-btn danger" type="submit">删除</button></form>
        <?php else: ?><span class="dns-muted"><?php echo $r['type'] === 'NS' && $r['name'] === '@' ? '系统记录' : '请到服务商后台修改'; ?></span><?php endif; ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
  </section>

  <?php $others = array_values(array_diff($detail['have'], [$provider])); $targets = array_values(array_diff(array_keys(DNS_PROVIDERS), [$provider])); ?>
  <section class="dns-card">
    <h2>切换解析商：复制解析到另一家</h2>
    <p class="dns-help">先把当前这家的记录复制到另一家（只新增、不覆盖，已有的相同记录会跳过），确认无误再去改 NS，这样切换时网站和邮箱不会中断。不复制：根域 NS、停用记录、分线路记录、暂不支持的类型。</p>
    <?php if (!$others): ?><p class="dns-help">另一家服务商里还没有这个域名，请先在上面“解析状态”里点“在 … 添加此域名”。</p>
    <?php else: foreach ($others as $to): ?>
    <form method="post" class="dns-form-row"><input type="hidden" name="csrf" value="<?php echo e($csrf); ?>"><input type="hidden" name="action" value="copy_preview"><input type="hidden" name="d" value="<?php echo e($domain); ?>"><input type="hidden" name="p" value="<?php echo e($provider); ?>"><input type="hidden" name="from" value="<?php echo e($provider); ?>"><input type="hidden" name="to" value="<?php echo e($to); ?>">
      <button class="dns-btn ghost" type="submit"><i class="fas fa-copy"></i> 预览：把 <?php echo e(DNS_PROVIDERS[$provider]); ?> 的解析复制到 <?php echo e(DNS_PROVIDERS[$to]); ?></button></form>
    <?php endforeach; endif; ?>
  </section>
  <?php else: ?>
  <section class="dns-card"><p class="dns-help">这个域名还不在 DNSPod 或 Cloudflare 里。点上面“解析状态”中的“在 … 添加此域名”即可创建，并拿到要改的 NS。</p></section>
  <?php endif; ?>
<?php endif; ?>
</main>
<script>
(function () {
  var notice = document.getElementById('dnsSaveNotice') || document.getElementById('dnsSuccessNotice');
  if (notice) { notice.scrollIntoView({ block: 'center' }); notice.focus({ preventScroll: true }); }
  document.querySelectorAll('[data-copy]').forEach(function (b) { b.addEventListener('click', function () {
    var t = b.getAttribute('data-copy'), done = function () { var o = b.textContent; b.textContent = '已复制'; setTimeout(function () { b.textContent = o; }, 1500); };
    if (navigator.clipboard) navigator.clipboard.writeText(t).then(done); else { var a = document.createElement('textarea'); a.value = t; document.body.appendChild(a); a.select(); document.execCommand('copy'); a.remove(); done(); } }); });
  var form = document.getElementById('dnsRecordForm'); if (!form) return;
  var $ = function (id) { return document.getElementById(id); };
  var syncType = function () { $('rPriorityWrap').hidden = $('rType').value !== 'MX'; };
  var open = function (r) {
    form.hidden = false; $('rTitle').textContent = r ? '编辑记录' : '添加记录'; $('rId').value = r ? r.id : '';
    $('rType').value = r ? r.type : 'A'; $('rName').value = r ? r.name : ''; $('rValue').value = r ? r.value : ''; $('rTtl').value = r ? r.ttl : 600; $('rPriority').value = r && r.priority !== null ? r.priority : 10;
    if ($('rProxied')) $('rProxied').checked = !!(r && r.proxied);
    syncType(); form.scrollIntoView({ behavior: 'smooth', block: 'center' }); $('rName').focus();
  };
  $('rType').addEventListener('change', syncType);
  syncType();
  $('dnsAddBtn').addEventListener('click', function () { open(null); });
  $('rCancel').addEventListener('click', function () { form.hidden = true; });
  document.querySelectorAll('[data-edit]').forEach(function (b) { b.addEventListener('click', function () { open(JSON.parse(b.getAttribute('data-edit'))); }); });
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
