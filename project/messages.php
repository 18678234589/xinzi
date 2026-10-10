<?php
require_once __DIR__ . '/../includes/ProjectGovernance.php';
// 我的站内信：平台公告与规则提醒。打开即标记已读。
$actor = ps_require_actor();
if (($actor['type'] ?? '') !== 'employee') { header('Location: ' . BASE_URL . '/project/holidays.php'); exit; }
$employeeId = (int)$actor['employee_id'];
pg_sync_reminders();
$q = db()->prepare('SELECT * FROM project_messages WHERE employee_id=? ORDER BY created_at DESC,id DESC LIMIT 100');
$q->execute([$employeeId]);
$messages = $q->fetchAll();
db()->prepare('UPDATE project_messages SET read_at=NOW() WHERE employee_id=? AND read_at IS NULL')->execute([$employeeId]);
$page_title = '我的站内信';
include __DIR__ . '/../includes/header.php';
?>
<div class="governance-page">
  <section class="governance-hero"><div><span class="governance-kicker">INBOX</span><h1>我的站内信</h1><p>平台公告与规则提醒都在这里，重要消息可以随时回看。法定节假日当天不发督促。</p></div></section>
  <section class="governance-card">
    <?php if (!$messages): ?><div class="governance-empty">暂无站内信。</div><?php endif; ?>
    <?php foreach ($messages as $m): ?>
      <article class="governance-record<?php echo $m['read_at'] ? '' : ' message-unread'; ?>">
        <div class="governance-record-top"><?php if (!$m['read_at']): ?><span class="governance-status rejected">新</span><?php endif; ?><small><?php echo e(substr($m['created_at'], 0, 16)); ?></small></div>
        <h3><?php echo e($m['title']); ?></h3>
        <p class="governance-description"><?php echo nl2br(e($m['body'])); ?></p>
        <?php if ($m['link']): ?><a class="btn btn-sm btn-success" href="<?php echo e(BASE_URL . $m['link']); ?>"><?php echo $m['category'] === 'announcement' ? '查看规则' : '去处理'; ?></a><?php endif; ?>
      </article>
    <?php endforeach; ?>
  </section>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
