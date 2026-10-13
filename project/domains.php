<?php
require_once __DIR__ . '/../includes/BizcnDomains.php';
require_once __DIR__ . '/../includes/BizcnRegistration.php';
$actor = ps_require_actor();
if (!bd_can_access($actor)) { http_response_code(403); exit('当前账户未分配域名管理权限'); }
header('Cache-Control: no-store, max-age=0');
header('Referrer-Policy: same-origin');
header('X-Robots-Tag: noindex');
$csrf = ps_csrf_token();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) { http_response_code(403); echo json_encode(['error'=>'页面已过期，请刷新重试'], JSON_UNESCAPED_UNICODE); exit; }
    $action = (string)($_POST['action'] ?? '');
    // Save CSRF/auth state before bounded external IO; do not lock other tabs.
    session_write_close();
    try {
        if (strpos($action,'registration_')===0) {
            $result=br_dispatch($action,$_POST,$actor);
        } elseif ($action === 'list') {
            $result = bd_list($_POST['search'] ?? '', $_POST['page'] ?? 1);
        } elseif ($action === 'import_export') {
            if ($actor['type'] !== 'admin' || pv_admin_username($actor['id']) !== 'admin') throw new RuntimeException('仅超级管理员可导入域名目录');
            $upload=$_FILES['file'] ?? [];
            if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'] ?? '') || strtolower(pathinfo($upload['name'] ?? '',PATHINFO_EXTENSION)) !== 'xlsx') throw new InvalidArgumentException('请选择商务中国导出的 XLSX 文件');
            $result=bc_import($upload['tmp_name'],$upload['name'],$_POST['source_as_of'] ?? '',$actor);
        } else {
            $domain = bd_domain($_POST['domain'] ?? '');
            $snapshot=bc_find($domain);
            // Snapshot membership permits reading only. Writes and panel tokens keep live ownership checks.
            if (!$snapshot || !in_array($action,['detail','records','realname'],true)) bd_require_owned($domain);
            if ($action === 'detail') {
                $whois=[];$warning='';$live=false;
                try {$whois=bd_call('infoDomainWhoisService', 'infoDomainWhois', ['infoDomainWhois'=>['domainname'=>$domain,'detailed'=>'false']]);$live=true;}
                catch(RuntimeException $e) {if(!$snapshot)throw $e;$warning='实时详情暂未读取成功，先展示导出表资料：'.$e->getMessage();}
                // Do not return owner identity/contact fields to ordinary operators.
                $fields = ['reg_date','expire_date','status','registrar','whois_status','dnvc_status','template_contid','dns_host1','dns_host2','dns_host3','dns_host4','dns_host5','dns_host6'];
                $base=$snapshot ? ['reg_date'=>$snapshot['regdate'],'expire_date'=>$snapshot['expireddate'],'status'=>$snapshot['status'],'registrar'=>$snapshot['registrar']] : [];
                $info=array_intersect_key($whois,array_flip($fields));
                foreach($base as $key=>$value)if(!isset($info[$key]) || $info[$key]==='')$info[$key]=$value;
                $result = ['domain'=>$domain,'info'=>$info,'live'=>$live,'warning'=>$warning,'snapshot_date'=>$snapshot['source_as_of'] ?? null,'owner_username'=>$snapshot['owner_username'] ?? ''];
            } elseif ($action === 'records') {
                $data = bd_call('infoDnsRecordService','infoDnsRecord',['domainname'=>$domain]);
                $result = ['rows'=>bd_rows($data['records']['record'] ?? [])];
            } elseif ($action === 'realname') {
                $result = bd_call('domainService','domainAuditQuery',['domainname'=>$domain]);
            } elseif ($action === 'panel') {
                $data = bd_call('domainService','jumpPanel',['domainname'=>$domain]);
                if (empty($data['token'])) throw new RuntimeException('暂未获取到管理面板入口');
                ps_audit('domain',0,'panel',$actor,['domain'=>$domain]);
                $result = ['url'=>'https://dnsmsn.com/login/domain/doLogin.do?token=' . rawurlencode($data['token'])];
            } elseif ($action === 'ns') {
                if (($_POST['confirm'] ?? '') !== $domain) throw new RuntimeException('请确认正在修改的域名');
                $ns = bd_ns($_POST['ns'] ?? '');
                ps_audit('domain',0,'ns_requested',$actor,['domain'=>$domain,'ns'=>$ns]);
                bd_call('modDomainDnsService','modDomainDns',['moddomaindns'=>['domainname'=>$domain,'dns_host'=>$ns]]);
                ps_audit('domain',0,'ns_success',$actor,['domain'=>$domain,'ns'=>$ns]);
                $result = ['message'=>'NS 修改请求已成功提交，全球生效需等待 DNS 缓存更新'];
            } elseif (in_array($action,['record_add','record_edit','record_delete'],true)) {
                if (($_POST['confirm'] ?? '') !== $domain) throw new RuntimeException('请确认正在修改的域名');
                $mode = substr($action,7); $args = bd_record($_POST,$mode);
                $oldType = strtoupper(trim((string)($_POST['old_type'] ?? ''))); $oldHost = trim((string)($_POST['old_host'] ?? ''));
                $replace = $mode === 'edit' && (($oldType !== '' && $oldType !== $args['resolvetype']) || ($oldHost !== '' && $oldHost !== $args['resolvehost']));
                if ($replace) {
                    // 改了类型或主机记录：商务中国靠“类型+主机+旧值”定位记录、没有改类型的接口，改为先新增再删旧。
                    $oldArgs = bd_record(['domain'=>$domain,'type'=>$oldType ?: $args['resolvetype'],'host'=>$oldHost !== '' ? $oldHost : $args['resolvehost'],'old_value'=>$_POST['old_value'] ?? '','priority'=>$_POST['old_priority'] ?? 10],'delete');
                    $result = ['message'=>bd_record_replace($domain,$oldArgs,bd_record($_POST,'add'),$actor)];
                } else {
                $live = bd_call('infoDnsRecordService','infoDnsRecord',['domainname'=>$domain]); $found=false; $duplicate=false;
                foreach (bd_rows($live['records']['record'] ?? []) as $row) {
                    if ((string)$row['host']===$args['resolvehost'] && strtoupper((string)$row['type'])===$args['resolvetype']) {
                        if ($mode !== 'add' && (string)$row['value']===$args['resolveoldvalue']) $found=true;
                        if ($mode !== 'delete' && (string)$row['value']===$args['resolvevalue']) $duplicate=true;
                    }
                }
                if ($mode !== 'add' && !$found) throw new RuntimeException('原解析记录已变化，请刷新后再修改，避免覆盖他人操作');
                if ($mode === 'add' && $duplicate) throw new RuntimeException('这条解析记录已存在，无需重复添加');
                $services=['add'=>['addDnsRecordService','addDnsRecord','add'],'edit'=>['modDnsRecordService','modDnsRecord','mod'],'delete'=>['delDnsRecordService','delDnsRecord','del']];
                $service=$services[$mode];
                ps_audit('domain',0,$action.'_requested',$actor,['domain'=>$domain,'type'=>$args['resolvetype'],'host'=>$args['resolvehost']]);
                bd_call($service[0],$service[1],[$service[2]=>$args]);
                ps_audit('domain',0,$action.'_success',$actor,['domain'=>$domain,'type'=>$args['resolvetype'],'host'=>$args['resolvehost']]);
                $result=['message'=>'解析记录已成功提交'];
                }
            } else throw new InvalidArgumentException('不支持此操作');
        }
        echo json_encode(['ok'=>true,'data'=>$result],JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP);
    } catch (Throwable $e) {
        http_response_code(400);
        $safe = get_class($e) === 'RuntimeException' || $e instanceof InvalidArgumentException;
        if (!$safe) error_log('Bizcn domain operation failed: '.get_class($e));
        echo json_encode(['error'=>$safe?$e->getMessage():'暂时未能完成操作，请稍后重试'],JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP);
    }
    exit;
}
$page_title = '域名管理';
include __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/domains.css?v=20261013-contrast">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/domain-registration.css?v=20261012r5">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/dns.css?v=20261013-contrast">
<main class="domain-console" id="domainConsole" data-csrf="<?php echo htmlspecialchars($csrf,ENT_QUOTES,'UTF-8'); ?>">
  <section class="domain-hero">
    <div class="domain-orbit" aria-hidden="true"><span></span><span></span><span></span><i class="fas fa-globe"></i></div>
    <div class="domain-hero-copy"><div class="domain-kicker">DOMAIN STUDIO <span>商务中国 · 域名 API</span></div><h1>每一个域名，都有自己的轨道。</h1><p>在这里搜索、查看到期时间，轻松管理 NS 与解析。<br>让客户的网站，被稳稳地连接。</p><div class="domain-hero-tags"><span><i class="fas fa-shield-alt"></i> 安全操作留痕</span><span><i class="fas fa-sync-alt"></i> 直接读取注册商</span><span><i class="fas fa-mobile-alt"></i> 随时随地管理</span></div></div>
  </section>
  <div class="dns-entry"><div><strong><i class="fas fa-network-wired" aria-hidden="true"></i> 本站解析管理：DNSPod / Cloudflare</strong><span>使用已接入的 API，在本站添加域名、增删改解析记录、复制解析。无需登录腾讯云；商务中国域名还可在本站修改 NS。</span></div><a href="dns.php">在本站管理解析 <i class="fas fa-arrow-right" aria-hidden="true"></i></a></div>
  <div class="dns-entry"><div><strong><i class="fas fa-cloud" aria-hidden="true"></i> 七牛 CDN 管理</strong><span>在本站添加 CDN 加速域名、查看源站与配置状态、取得 CNAME 并接入解析。</span></div><a href="cdn.php">进入 CDN 管理 <i class="fas fa-arrow-right" aria-hidden="true"></i></a></div>
  <?php include __DIR__.'/domain_registration_view.php'; ?>
  <section class="domain-toolbar"><div><h2>我的域名空间 <span id="domainCount">—</span></h2><p id="domainUpdated">正在读取域名目录…</p></div><form id="domainSearch"><label class="sr-only" for="domainQuery">搜索域名、所属账号或注册商</label><i class="fas fa-search"></i><input id="domainQuery" placeholder="搜索域名 / 所属账号 / 注册商" maxlength="253"><button class="domain-btn" type="submit">搜索</button><button class="domain-btn ghost" type="button" id="domainRefresh" title="刷新当前域名目录"><i class="fas fa-sync-alt"></i></button></form></section>
  <?php if ($actor['type']==='admin' && pv_admin_username($actor['id'])==='admin'): ?>
  <details class="domain-import"><summary><i class="fas fa-file-import"></i> 导入商务中国域名表</summary><form id="domainImportForm"><label>全部域名导出表（XLSX，最多10MB）<input id="domainImportFile" name="file" type="file" accept=".xlsx" required></label><label>导出表日期<input type="date" name="source_as_of" required value="<?php echo date('Y-m-d'); ?>"></label><button class="domain-btn" type="submit">确认导入目录</button><p>同一文件不重复导入；新表更新已有域名资料，不删除未出现的域名，也不修改 NS、解析或执行续费。多账号归属保持原样。</p></form></details>
  <?php endif; ?>
  <div id="domainNotice" class="domain-notice" role="status" aria-live="polite" hidden></div>
  <section class="domain-table-card"><div class="domain-table-scroll"><table class="domain-table"><thead><tr><th>域名</th><th>注册商</th><th>所属账号</th><th>注册时间</th><th>到期时间</th><th>注册商状态</th><th>NS 服务器</th><th>管理</th></tr></thead><tbody id="domainRows"><tr><td colspan="8" class="domain-empty"><span class="domain-spinner"></span> 正在读取域名列表…</td></tr></tbody></table></div><footer class="domain-pagination"><span id="domainPageInfo">每页 30 个域名 · 数据来源商务中国</span><div><button class="domain-btn ghost" id="domainPrev" disabled>上一页</button><button class="domain-btn ghost" id="domainNext" disabled>下一页</button></div></footer></section>
  <p class="domain-footnote"><i class="fas fa-info-circle"></i> 导出目录是表格日期的快照，不代表已实时同步。详情按需查询；NS、解析修改和官方面板仍需注册商实时核验归属。仅在注册区明确确认付款后才申请购买；查询、刷新不扣费。</p>
  <dialog id="domainDrawer" class="domain-drawer"><div class="domain-drawer-head"><div><span class="domain-kicker">DOMAIN CONTROL</span><h2 id="domainDetailName">域名详情</h2></div><button class="domain-close" id="domainClose" aria-label="关闭详情">×</button></div><nav class="domain-tabs" aria-label="域名管理选项"><button data-tab="overview" class="active">概览</button><button data-tab="ns">NS 服务器</button><button data-tab="records">解析记录</button><button data-tab="realname">实名认证</button></nav><div class="domain-drawer-body"><div id="domainDetailNotice" class="domain-notice" role="status" hidden></div><section id="domainOverview"></section><section id="domainNs" hidden><p class="domain-help">切换 NS 会改变域名的权威解析服务。请先在新服务商配置好全部解析，避免网站或邮箱中断。</p><form id="domainNsForm"><label for="domainNsValue">NS 服务器，一行一个（2–6 个）</label><textarea id="domainNsValue" rows="6" placeholder="ns1.example.com&#10;ns2.example.com" required></textarea><button class="domain-btn" type="submit">确认修改 NS</button></form></section><section id="domainRecords" hidden><div id="domainDnsWarning" class="domain-notice error" role="status" hidden></div><a id="domainExternalDnsLink" class="domain-btn ghost" href="dns.php" hidden>去本站管理实际解析</a><div class="domain-section-heading"><h3>解析记录</h3><button class="domain-btn" id="domainAddRecord">＋添加记录</button></div><div id="recordFormStatus" class="domain-notice" role="status" aria-live="polite" hidden></div><p class="domain-help">本区域仅管理商务中国记录。DNSPod / Cloudflare 请从本站“解析管理”修改；使用外部 NS 时，改动此处记录不会影响正在使用的解析。</p><div id="domainRecordRows"></div><form id="domainRecordForm" hidden><h3 id="domainRecordTitle">添加解析</h3><div class="domain-form-grid"><label>记录类型<select id="recordType"><option>A</option><option>AAAA</option><option>CNAME</option><option>MX</option><option>TXT</option><option>NS</option><option>SRV</option><option>CAA</option></select></label><label>主机记录<input id="recordHost" value="@" required maxlength="253"></label><label class="domain-form-wide">解析值<input id="recordValue" required maxlength="2048" placeholder="IP 地址、目标域名或文本"></label><label>MX 优先级<input id="recordPriority" type="number" min="0" max="65535" value="10"></label></div><p class="domain-help" id="recordEditHint" hidden>修改为 CNAME 等互斥类型时，需要先删除原记录再添加新记录；添加失败会尝试恢复原记录。请先核对目标地址，提交后查看下面的结果提示。</p><div class="domain-form-actions"><button type="submit" class="domain-btn">保存解析</button><button type="button" class="domain-btn ghost" id="recordCancel">取消</button></div></form></section><section id="domainRealname" hidden><h3>实名认证</h3><div id="domainRealnameResult">正在查询…</div><p class="domain-help">实名资料包含证件信息，上传、补件与信息模板管理在商务中国官方安全面板完成，避免在多人协作系统中留存证件。</p><button class="domain-btn" data-panel>进入官方实名管理 <i class="fas fa-external-link-alt"></i></button></section></div><footer class="domain-drawer-footer"><span>实名、续费与高级功能</span><button class="domain-btn ghost" data-panel>打开官方面板 ↗</button></footer></dialog>
  <template id="domainNsChoicesTemplate"><?php $ns_target='domainNsValue';$ns_group='domain_ns_mode';include __DIR__.'/domain_dns_choices.php'; ?></template>
</main>
<script src="<?php echo BASE_URL; ?>/assets/js/domain-dns.js?v=20261012r1" defer></script>
<script src="<?php echo BASE_URL; ?>/assets/js/domains.js?v=20261013-expiry" defer></script>
<script src="<?php echo BASE_URL; ?>/assets/js/domain-dns-link.js?v=20261012-dnspod" defer></script>
<script src="<?php echo BASE_URL; ?>/assets/js/domain-registration.js?v=20261012r6" defer></script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
