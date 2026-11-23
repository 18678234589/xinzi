<?php if (!empty($current_admin) || !empty($project_staff)): ?>
<style>.hn-entry{border-color:#6f42c1;color:#6f42c1}.hn-entry:hover,.hn-entry.active{background:#6f42c1;color:#fff}
#hn-backdrop{display:none;position:fixed;inset:0;background:rgba(15,23,42,.36);backdrop-filter:blur(2px);z-index:1049}
#hn-backdrop.open{display:block}
#hn-panel{display:none;position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);width:min(1180px,calc(100vw - 40px));height:min(820px,calc(100vh - 56px));z-index:1050;background:#fff;border:1px solid #d9d4ec;border-radius:14px;box-shadow:0 20px 60px rgba(30,20,70,.32);overflow:hidden;flex-direction:column}
#hn-panel.open{display:flex}
#hn-panel.expanded{left:12px;top:12px;right:12px;bottom:12px;transform:none;width:auto;height:auto}
#hn-panel .hn-bar{display:flex;align-items:center;justify-content:space-between;padding:9px 14px;background:#171717;color:#fff;font-size:14px;flex-shrink:0}
#hn-panel .hn-bar-actions{display:flex;align-items:center;gap:4px}
#hn-panel .hn-bar a,#hn-panel .hn-bar button{color:#fff;background:none;border:0;font-size:15px;line-height:1;padding:4px 7px;cursor:pointer;text-decoration:none;border-radius:6px}
#hn-panel .hn-bar a:hover,#hn-panel .hn-bar button:hover{background:rgba(255,255,255,.15)}
#hn-panel iframe{flex:1;border:0;width:100%;background:#fff}
#hn-loading{padding:24px;color:#52525b;font-size:14px;text-align:center}
@media (max-width:768px){#hn-panel{left:0;top:auto;bottom:0;transform:none;width:100vw;max-width:100vw;height:90vh;border-radius:14px 14px 0 0}}</style>
<button type="button" id="hn-toggle" class="btn btn-sm hn-entry mx-2 d-inline-flex align-items-center text-nowrap" title="打开/关闭智能体共和国"><i class="fas fa-robot mr-1" aria-hidden="true"></i>智能体共和国</button>
<div id="hn-backdrop" aria-hidden="true"></div>
<div id="hn-panel" role="dialog" aria-label="智能体共和国"><div class="hn-bar"><span>🤖 智能体共和国 · 自动登录工作台</span><span class="hn-bar-actions"><button type="button" id="hn-expand" title="全屏/还原">&#x26F6;</button><a href="<?php echo BASE_URL; ?>/harness/entry.php" target="_blank" rel="noopener" title="在新窗口打开">&#8599;</a><button type="button" id="hn-close" title="关闭" aria-label="关闭">&#10005;</button></span></div><p id="hn-loading" role="status">正在自动登录智能体共和国…</p></div>
<script>(function(){var p=document.getElementById('hn-panel'),bd=document.getElementById('hn-backdrop'),b=document.getElementById('hn-toggle'),ex=document.getElementById('hn-expand'),f=null;if(!p||!b)return;
// The topbar's backdrop-filter confines fixed descendants to the navigation box.
if(bd)document.body.appendChild(bd);
document.body.appendChild(p);
function set(o){p.classList.toggle('open',o);if(bd)bd.classList.toggle('open',o);b.classList.toggle('active',o);if(o&&!f){f=document.createElement('iframe');f.src='<?php echo BASE_URL; ?>/harness/entry.php';f.title='智能体共和国工作台';f.setAttribute('allow','clipboard-write; clipboard-read; microphone');f.onload=function(){var l=document.getElementById('hn-loading');if(l)l.hidden=true;};p.appendChild(f);}}
b.addEventListener('click',function(){set(!p.classList.contains('open'));});
if(ex)ex.addEventListener('click',function(){p.classList.toggle('expanded');});
if(bd)bd.addEventListener('click',function(){set(false);});
document.getElementById('hn-close').addEventListener('click',function(){set(false);});
document.addEventListener('keydown',function(e){if(e.key==='Escape'&&p.classList.contains('open'))set(false);});})();</script>
<a class="btn btn-outline-primary btn-sm mr-2 d-inline-flex align-items-center text-nowrap" href="https://media.laibangwo.com/?sso=me" target="_blank" rel="noopener noreferrer" title="使用当前账号进入自媒体工作台"><i class="fas fa-video mr-1" aria-hidden="true"></i>自媒体工作台</a>
<?php require_once __DIR__ . '/BizcnDomains.php'; if (bd_can_access(ps_actor())): ?>
<a class="btn btn-outline-primary btn-sm mr-2 d-inline-flex align-items-center text-nowrap" href="<?php echo BASE_URL; ?>/project/domains.php"><i class="fas fa-globe mr-1" aria-hidden="true"></i>域名管理</a>
<a class="btn btn-outline-primary btn-sm mr-2 d-inline-flex align-items-center text-nowrap" href="<?php echo BASE_URL; ?>/project/domains.php#register"><i class="fas fa-plus-circle mr-1" aria-hidden="true"></i>注册域名</a>
<a class="btn btn-outline-primary btn-sm mr-2 d-inline-flex align-items-center text-nowrap" href="<?php echo BASE_URL; ?>/project/cdn.php"><i class="fas fa-cloud mr-1" aria-hidden="true"></i>CDN 管理</a>
<?php endif; ?>
<?php require_once __DIR__ . '/BizcnDomains.php'; require_once __DIR__ . '/YayunAccess.php'; if (ya_can_view(ps_actor())): ?>
<a class="btn btn-outline-primary btn-sm mr-2 d-inline-flex align-items-center text-nowrap" href="<?php echo BASE_URL; ?>/project/servers.php"><i class="fas fa-server mr-1" aria-hidden="true"></i>服务器管理</a>
<?php endif; ?>
<?php endif; ?>
