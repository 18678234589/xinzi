<?php
require_once __DIR__ . '/../includes/ProjectGovernance.php';
// 法定节假日：当天不发督促；三天脑洞窗口内每个节假日把截止日顺延一天。财务与监委会维护。
[$actor, $editorName] = pg_require_contribution_editor();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'add') {
            $from = pg_validate_date($_POST['from'] ?? '', true);
            $to = pg_validate_date($_POST['to'] ?? '') ?: $from;
            if ($to < $from || (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days > 31) throw new RuntimeException('日期范围不正确（一次最多 31 天）');
            $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 60);
            if ($name === '') throw new RuntimeException('请填写节日名称，如 国庆节');
            $save = db()->prepare('INSERT INTO project_holidays (holiday_date,name) VALUES (?,?) ON DUPLICATE KEY UPDATE name=VALUES(name)');
            for ($d = new DateTimeImmutable($from); $d <= new DateTimeImmutable($to); $d = $d->modify('+1 day')) $save->execute([$d->format('Y-m-d'), $name]);
            ps_audit('holiday', 0, 'add', $actor, ['from' => $from, 'to' => $to, 'name' => $name]);
        } elseif ($action === 'delete') {
            $date = pg_validate_date($_POST['date'] ?? '', true);
            db()->prepare('DELETE FROM project_holidays WHERE holiday_date=?')->execute([$date]);
            ps_audit('holiday', 0, 'delete', $actor, ['date' => $date]);
        } else throw new RuntimeException('操作无效');
        header('Location: ' . BASE_URL . '/project/holidays.php?saved=1');
        if (PHP_SAPI === 'cli') return;
        exit;
    } catch (RuntimeException $e) { $error = $e->getMessage(); }
}
$holidays = db()->query('SELECT * FROM project_holidays WHERE holiday_date>=DATE_SUB(CURDATE(),INTERVAL 30 DAY) ORDER BY holiday_date')->fetchAll();
$page_title = '法定节假日';
include __DIR__ . '/../includes/header.php';
?>
<div class="governance-page">
  <section class="governance-hero"><div><span class="governance-kicker">HOLIDAYS</span><h1>法定节假日</h1><p>节假日当天系统不发督促站内信；三天脑洞窗口里每有一天节假日，截止日顺延一天（自动扣减同口径）。调休上班日不用填。</p></div><span class="governance-role"><?php echo e($editorName); ?></span></section>
  <?php if ($error): ?><div class="alert alert-danger mt-3"><?php echo e($error); ?></div><?php endif; ?>
  <?php if (isset($_GET['saved'])): ?><div class="alert alert-success mt-3">已保存。</div><?php endif; ?>
  <section class="governance-card"><h2>添加放假日期</h2>
    <form method="post" class="form-row align-items-end"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="add">
      <div class="form-group col-md-3"><label>节日名称</label><input class="form-control" name="name" required placeholder="如 国庆节" maxlength="60"></div>
      <div class="form-group col-md-3"><label>放假开始</label><input class="form-control" type="date" name="from" required></div>
      <div class="form-group col-md-3"><label>放假结束（单日可不填）</label><input class="form-control" type="date" name="to"></div>
      <div class="form-group col-md-3"><button class="btn btn-success btn-block" type="submit">添加</button></div>
    </form>
  </section>
  <section class="governance-card"><h2>近期与未来节假日</h2>
    <?php if (!$holidays): ?><div class="governance-empty">还没有登记节假日。请按国务院办公厅公布的放假安排添加。</div><?php else: ?>
    <div class="table-responsive"><table class="table contribution-table"><thead><tr><th>日期</th><th>星期</th><th>节日</th><th></th></tr></thead><tbody>
    <?php foreach ($holidays as $h): ?><tr><td><?php echo e($h['holiday_date']); ?></td><td><?php echo ['', '一', '二', '三', '四', '五', '六', '日'][(int)date('N', strtotime($h['holiday_date']))]; ?></td><td><?php echo e($h['name']); ?></td><td><form method="post" onsubmit="return confirm('删除这一天？');"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="date" value="<?php echo e($h['holiday_date']); ?>"><button class="btn btn-sm btn-outline-danger">删除</button></form></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </section>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
