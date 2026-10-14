<?php if (!empty($current_admin) || !empty($project_staff)): ?>
<style>.hn-entry{border-color:#6f42c1;color:#6f42c1}.hn-entry:hover,.hn-entry.active{background:#6f42c1;color:#fff}
#hn-panel{display:none;position:fixed;right:16px;bottom:16px;width:420px;max-width:calc(100vw - 32px);height:min(720px,calc(100vh - 90px));z-index:1050;background:#fff;border:1px solid #d9d4ec;border-radius:12px;box-shadow:0 12px 40px rgba(60,40,120,.28);overflow:hidden;flex-direction:column}
#hn-panel.open{display:flex}
#hn-panel .hn-bar{display:flex;align-items:center;justify-content:space-between;padding:8px 12px;background:#6f42c1;color:#fff;font-size:14px}
#hn-panel .hn-bar a,#hn-panel .hn-bar button{color:#fff;background:none;border:0;font-size:16px;line-height:1;padding:2px 6px;cursor:pointer;text-decoration:none}
#hn-panel iframe{flex:1;border:0;width:100%;background:#fff}
#hn-loading{padding:20px;color:#42645d;font-size:14px}
@media (max-width:576px){#hn-panel{right:0;bottom:0;width:100vw;max-width:100vw;height:85vh;border-radius:12px 12px 0 0}}</style>
<button type="button" id="hn-toggle" class="btn btn-sm hn-entry mx-2 d-inline-flex align-items-center text-nowrap" title="打开/关闭智能体共和国"><i class="fas fa-robot mr-1" aria-hidden="true"></i>智能体共和国</button>
<div id="hn-panel" role="dialog" aria-label="智能体共和国"><div class="hn-bar"><span>智能体共和国 · 首席调度官</span><span><a href="<?php echo BASE_URL; ?>/harness/entry.php" target="_blank" rel="noopener" title="在新窗口打开">&#8599;</a><button type="button" id="hn-close" title="关闭" aria-label="关闭">&#10005;</button></span></div><p id="hn-loading" role="status">正在加载首席调度官…</p></div>
<script>(function(){var p=document.getElementById('hn-panel'),b=document.getElementById('hn-toggle'),f=null;if(!p||!b)return;
// The topbar's backdrop-filter confines fixed descendants to the navigation box.
document.body.appendChild(p);
function set(o){p.classList.toggle('open',o);b.classList.toggle('active',o);if(o&&!f){f=document.createElement('iframe');f.src='<?php echo BASE_URL; ?>/harness/entry.php';f.title='智能体共和国首席调度官';f.setAttribute('allow','clipboard-write');f.onload=function(){document.getElementById('hn-loading').hidden=true;};p.appendChild(f);}}
b.addEventListener('click',function(){set(!p.classList.contains('open'));});
document.getElementById('hn-close').addEventListener('click',function(){set(false);});
document.addEventListener('keydown',function(e){if(e.key==='Escape'&&p.classList.contains('open'))set(false);});})();</script>
<a class="btn btn-outline-primary btn-sm mr-2 d-inline-flex align-items-center text-nowrap" href="https://media.laibangwo.com/?sso=me" title="使用当前账号进入自媒体工作台"><i class="fas fa-video mr-1" aria-hidden="true"></i>自媒体工作台</a>
<?php require_once __DIR__ . '/BizcnDomains.php'; if (bd_can_access(ps_actor())): ?>
<a class="btn btn-outline-primary btn-sm mr-2 d-inline-flex align-items-center text-nowrap" href="<?php echo BASE_URL; ?>/project/domains.php"><i class="fas fa-globe mr-1" aria-hidden="true"></i>域名管理</a>
<a class="btn btn-outline-primary btn-sm mr-2 d-inline-flex align-items-center text-nowrap" href="<?php echo BASE_URL; ?>/project/domains.php#register"><i class="fas fa-plus-circle mr-1" aria-hidden="true"></i>注册域名</a>
<a class="btn btn-outline-primary btn-sm mr-2 d-inline-flex align-items-center text-nowrap" href="<?php echo BASE_URL; ?>/project/cdn.php"><i class="fas fa-cloud mr-1" aria-hidden="true"></i>CDN 管理</a>
<?php endif; ?>
<?php endif; ?>
