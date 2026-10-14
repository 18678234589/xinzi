<?php if (!empty($current_admin) || !empty($project_staff)): ?>
<style>.hn-entry{background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff!important;border:0;box-shadow:0 2px 8px rgba(79,70,229,.28);transition:transform .15s,box-shadow .15s}.hn-entry:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(79,70,229,.4);color:#fff!important;text-decoration:none}</style>
<a class="btn btn-sm hn-entry mx-2 d-inline-flex align-items-center text-nowrap" href="<?php echo BASE_URL; ?>/harness/entry.php" title="使用当前账号进入智能体共和国（AI 编程助手，含 1GB 个人空间）"><i class="fas fa-robot mr-1" aria-hidden="true"></i>智能体共和国</a>
<a class="btn btn-outline-primary btn-sm mr-2 d-inline-flex align-items-center text-nowrap" href="https://media.laibangwo.com/?sso=me" title="使用当前账号进入自媒体工作台"><i class="fas fa-video mr-1" aria-hidden="true"></i>自媒体工作台</a>
<?php require_once __DIR__ . '/BizcnDomains.php'; if (bd_can_access(ps_actor())): ?>
<a class="btn btn-outline-primary btn-sm mr-2 d-inline-flex align-items-center text-nowrap" href="<?php echo BASE_URL; ?>/project/domains.php"><i class="fas fa-globe mr-1" aria-hidden="true"></i>域名管理</a>
<a class="btn btn-outline-primary btn-sm mr-2 d-inline-flex align-items-center text-nowrap" href="<?php echo BASE_URL; ?>/project/domains.php#register"><i class="fas fa-plus-circle mr-1" aria-hidden="true"></i>注册域名</a>
<a class="btn btn-outline-primary btn-sm mr-2 d-inline-flex align-items-center text-nowrap" href="<?php echo BASE_URL; ?>/project/cdn.php"><i class="fas fa-cloud mr-1" aria-hidden="true"></i>CDN 管理</a>
<?php endif; ?>
<?php endif; ?>
