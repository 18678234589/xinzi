<?php
// 管理层督促站内信与法定节假日顺延：模拟日期执行，事务内结束回滚。
// php tests/governance_reminders_smoke.php（需先执行 20260927_governance_reminders.sql）
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectGovernance.php';

$pdo = db();
$pdo->beginTransaction();
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$messages = function ($employeeId, $prefix) use ($pdo) { $q = $pdo->prepare('SELECT COUNT(*) FROM project_messages WHERE employee_id=? AND dedupe_key LIKE ?'); $q->execute([$employeeId, $prefix . '%']); return (int)$q->fetchColumn(); };
try {
    $rotation = $pdo->query("SELECT * FROM project_governance_rotations WHERE start_date='2026-09-15'")->fetch();
    $check((bool)$rotation && pg_idea_policy()['days'] === 6, '栾鑫 2026-09-15 起轮值、每 6 天一期');
    $chair = (int)$rotation['chair_employee_id'];
    $committee = array_map('intval', $pdo->query("SELECT employee_id FROM project_governance_members WHERE governance_role='committee' AND is_active=1")->fetchAll(PDO::FETCH_COLUMN));
    // 隔离：清掉本任期真实记录的影响（事务内，结束回滚）
    $pdo->exec("DELETE FROM project_holidays WHERE holiday_date BETWEEN '2026-09-20' AND '2026-10-20'");

    echo "=== 董事长脑洞截止督促 ===\n";
    $w = pg_idea_window_status('2026-09-30');
    $check($w['start'] === '2026-09-27' && $w['end'] === '2026-10-02' && $w['deadline'] === '2026-10-02' && $w['days_left'] === 3, '9/30：本期 9/27–10/2，剩 3 天');
    pg_sync_reminders('2026-09-30');
    $check($messages($chair, 'idea-due:') === 0, '剩 3 天不督促');
    $hasIdea = !empty(pg_idea_window_status('2026-10-01')['submitted']);
    pg_sync_reminders('2026-10-01');
    $check($hasIdea || $messages($chair, 'idea-due:2026-09-27:2026-10-01') === 1, '10/1 剩 2 天未提交：给栾鑫发站内信');
    pg_sync_reminders('2026-10-01');
    $check($hasIdea || $messages($chair, 'idea-due:') === 1, '同一天重复运行不重复发');

    echo "=== 法定节假日 ===\n";
    $pdo->exec("INSERT INTO project_holidays (holiday_date,name) VALUES ('2026-10-01','测试节'),('2026-10-02','测试节'),('2026-10-03','测试节')");
    $w = pg_idea_window_status('2026-09-30');
    $check($w['deadline'] === '2026-10-05', '窗口内 2 天节假日，顺延 2 个非节假日（跳过 10/3）到 10/5');
    $check($w['days_left'] === 6, '9/30 起算还剩 6 天');
    $before = (int)$pdo->query('SELECT COUNT(*) FROM project_messages')->fetchColumn();
    pg_sync_reminders('2026-10-02');
    $check((int)$pdo->query('SELECT COUNT(*) FROM project_messages')->fetchColumn() === $before, '节假日当天不发督促');
    $check(pg_workdays_between('2026-09-27', '2026-10-05') === 4, '工作日不含周日与节假日（9/28–10/5 共 4 天）');

    echo "=== 监委会监督意见与待评审 ===\n";
    $pdo->exec("DELETE FROM project_holidays WHERE holiday_date BETWEEN '2026-09-20' AND '2026-10-20'");
    $pdo->prepare("INSERT INTO project_governance_records (record_kind,owner_employee_id,record_date,category,description,created_by_employee_id,created_at) VALUES ('chair',?,'2026-10-03','三天脑洞','测试待评审',?,'2026-10-03 10:00:00')")->execute([$chair, $chair]);
    $ideaId = (int)$pdo->lastInsertId();
    pg_sync_reminders('2026-10-05');
    foreach ($committee as $member) $check($messages($member, 'idea-review:' . $ideaId) === 1, $pdo->query('SELECT name FROM employees WHERE id=' . $member)->fetchColumn() . '：脑洞待评审超过 1 天，发提醒');
    $check($messages($chair, 'idea-review:') === 0, '董事长本人不收评审提醒');

    echo "=== 监委督战：任务录入后 7 天内提交监督意见，否则每人扣 150 ===\n";
    $policy = pg_oversight_policy();
    $check($policy === ['days' => 7, 'penalty' => 150.0], '规则“监委会监督反馈”已确认：7 天、扣 150');
    [$a, $b, $c] = $committee;
    $pdo->prepare("INSERT INTO project_governance_records (record_kind,owner_employee_id,record_date,category,description,created_by_employee_id,created_at) VALUES ('chair',?,'2026-10-10','三天脑洞','测试任务',?,'2026-10-10 09:00:00')")->execute([$chair, $chair]);
    $taskId = (int)$pdo->lastInsertId();
    $task = array_values(array_filter(pg_oversight_tasks($policy), function ($t) use ($taskId) { return (int)$t['id'] === $taskId; }))[0];
    $check($task['deadline'] === '2026-10-17', '10/10 录入的任务，监督意见截止 10/17');
    pg_sync_reminders('2026-10-15');
    $check($messages($c, 'oversight-due:' . $taskId) === 0, '剩 3 天不督促');
    pg_sync_reminders('2026-10-16');
    $check($messages($a, 'oversight-due:' . $taskId . ':2026-10-16') === 1 && $messages($c, 'oversight-due:' . $taskId . ':2026-10-16') === 1, '剩 2 天：给未提交的监委发督促');
    $pdo->prepare("INSERT INTO project_governance_records (record_kind,owner_employee_id,record_date,category,description,parent_record_id,created_by_employee_id,created_at) VALUES ('committee',?,'2026-10-12','监督意见','进度正常',?,?,'2026-10-12 11:00:00')")->execute([$a, $taskId, $a]);
    $pdo->prepare('UPDATE project_governance_records SET reviewer_employee_id=?,review_state=\'approved\',reviewed_at=\'2026-10-13 10:00:00\' WHERE id=?')->execute([$b, $taskId]);
    $check(pg_oversight_done($task, $a) && !pg_oversight_done($task, $c), '挂在任务下的监督意见算已提交；没提交的仍是待提交');
    $task['reviewer_employee_id'] = $b;
    $check(pg_oversight_done($task, $b), '评审了该任务的监委也算已监督');
    $check(pg_sync_oversight_penalties('2026-10-17') === 0, '截止当天不扣');
    $added = pg_sync_oversight_penalties('2026-10-18');
    $rows = $pdo->query('SELECT employee_id,amount,due_date FROM project_governance_committee_penalties WHERE task_record_id=' . $taskId)->fetchAll();
    $check($added === 1 && count($rows) === 1 && (int)$rows[0]['employee_id'] === $c && (float)$rows[0]['amount'] === -150.0 && $rows[0]['due_date'] === '2026-10-17', '10/18：只给未提交的那位监委扣 ¥150');
    $check($messages($c, 'oversight-penalty:') === 1, '扣减后给本人发站内信');
    $check(pg_sync_oversight_penalties('2026-10-19') === 0, '重复运行不重复扣');
    $pdo->exec("INSERT INTO project_holidays (holiday_date,name) VALUES ('2026-10-24','测试节')");
    $pdo->prepare("INSERT INTO project_governance_records (record_kind,owner_employee_id,record_date,category,description,created_by_employee_id,created_at) VALUES ('chair',?,'2026-10-20','三天脑洞','节假日任务',?,'2026-10-20 09:00:00')")->execute([$chair, $chair]);
    $holidayTaskId = (int)$pdo->lastInsertId();
    $holidayTask = array_values(array_filter(pg_oversight_tasks($policy), function ($t) use ($holidayTaskId) { return (int)$t['id'] === $holidayTaskId; }))[0];
    $check($holidayTask['deadline'] === '2026-10-28', '7 天内有 1 天节假日：截止从 10/27 顺延到 10/28');
    $pdo->prepare("INSERT INTO project_governance_records (record_kind,owner_employee_id,record_date,category,description,created_by_employee_id,review_state,created_at) VALUES ('chair',?,'2026-10-21','三天脑洞','被退回的任务',?,'rejected','2026-10-21 09:00:00')")->execute([$chair, $chair]);
    $rejectedId = (int)$pdo->lastInsertId();
    $check(!array_filter(pg_oversight_tasks($policy), function ($t) use ($rejectedId) { return (int)$t['id'] === $rejectedId; }), '被退回的任务不要求监督意见');

    $pdo->rollBack();
    echo "\n=== 督促站内信与节假日顺延全部通过，测试数据已回滚 ===\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
