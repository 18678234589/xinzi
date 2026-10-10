<?php
if (!empty($current_admin) || empty($project_staff['employee_id']) || empty($_SESSION['project_user_id'])) return;
require_once __DIR__ . '/ProjectAnnouncements.php';
try {
    $announcement = pna_pending(['type' => 'employee', 'employee_id' => (int)$project_staff['employee_id']]);
} catch (Throwable $e) {
    error_log('Announcement popup unavailable: ' . get_class($e));
    return; // 通知失败不能阻止录单。
}
if (!$announcement) return;
?>
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/announcement_popup.css?v=20261009.1">
<div class="modal fade project-announcement" id="projectAnnouncement" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="announcementTitle" aria-describedby="announcementBody" data-endpoint="<?php echo e(BASE_URL . '/project/message_ack.php'); ?>" data-csrf="<?php echo e(ps_csrf_token()); ?>" data-message-id="<?php echo (int)$announcement['id']; ?>">
  <div class="modal-dialog modal-dialog-centered" role="document"><div class="modal-content">
    <div class="modal-header"><div><span class="announcement-kicker">一起成长 · 平台通知</span><h5 class="modal-title" id="announcementTitle"><?php echo e($announcement['title']); ?></h5></div><button type="button" class="close" data-announcement-action="defer" aria-label="稍后查看"><span aria-hidden="true">&times;</span></button></div>
    <div class="modal-body"><div class="announcement-body" id="announcementBody"><?php echo nl2br(e($announcement['body'])); ?></div><p class="announcement-note">这份通知已存入你的站内信，之后也可以随时查看。</p><div class="announcement-error" role="alert" hidden></div></div>
    <div class="modal-footer"><a class="announcement-inbox" href="<?php echo BASE_URL; ?>/project/messages.php">查看站内信</a><button type="button" class="btn btn-outline-secondary" data-announcement-action="defer">稍后查看</button><button type="button" class="btn btn-success" data-announcement-action="read">我知道了</button></div>
  </div></div>
</div>
<script src="<?php echo BASE_URL; ?>/assets/js/announcement_popup.js?v=20261009.1" defer></script>
