<?php if (!empty($current_admin) || !empty($project_staff)): ?>
<a class="btn btn-outline-primary btn-sm mx-2 d-inline-flex align-items-center text-nowrap" href="https://media.laibangwo.com/?sso=me" title="使用当前账号进入自媒体工作台"><i class="fas fa-video mr-1" aria-hidden="true"></i>自媒体工作台</a>
<?php require_once __DIR__ . '/BizcnDomains.php'; if (bd_can_access(ps_actor())): ?>
<a class="btn btn-outline-primary btn-sm mr-2 d-inline-flex align-items-center text-nowrap" href="<?php echo BASE_URL; ?>/project/domains.php"><i class="fas fa-globe mr-1" aria-hidden="true"></i>域名管理</a>
<?php endif; ?>
<?php endif; ?>
