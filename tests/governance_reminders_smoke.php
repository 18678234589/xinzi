<?php
// 管理层督促站内信、董事长按工作日划期扣减、监委督战：模拟日期执行，事务内结束回滚。
// php tests/governance_reminders_smoke.php（需先执行 20260927_governance_reminders.sql）
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectGovernance.php';

$pdo = db();
$pdo->beginTransaction();
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$messages = function ($employeeId, $prefix) use ($pdo) { $q = $pdo->prepare('SELECT COUNT(*) FROM project_messages WHERE employee_id=? AND dedupe_key LIKE ?'); $q->execute([$employeeId, $prefix . '%']); return (int)$q->fetchColumn(); };
try {
    $rotation = $pdo->query("SELECT * FROM project_governance_rotations WHERE start_date='2026-09-15'")->fetch();
    $policy = pg_idea_policy();
    $check((bool)$rotation && $policy['mode'] === 'workday' && $policy['days'] === 6, '栾鑫 2026-09-15 起轮值；脑洞每 6 个工作日一期');
    $chair = (int)$rotation['chair_employee_id'];
    $committee = array_map('intval', $pdo->query("SELECT employee_id FROM project_governance_members WHERE governance_role='committee' AND is_active=1")->fetchAll(PDO::FETCH_COLUMN));
    // 隔离：只保留官方节假日（中秋 9/25–27、国庆 10/1–7），清掉本任期 9/28 后的真实脑洞影响（事务内，结束回滚）
    $pdo->exec("DELETE FROM project_holidays WHERE holiday_date BETWEEN '2026-09-20' AND '2026-12-31'");
    $pdo->exec("INSERT INTO project_holidays (holiday_date,name) VALUES ('2026-09-25','中秋节'),('2026-09-26','中秋节'),('2026-09-27','中秋节'),('2026-10-01','国庆节'),('2026-10-02','国庆节'),('2026-10-03','国庆节'),('2026-10-04','国庆节'),('2026-10-05','国庆节'),('2026-10-06','国庆节'),('2026-10-07','国庆节')");
    $pdo->prepare("DELETE FROM project_governance_records WHERE record_kind='chair' AND owner_employee_id=? AND created_at>='2026-09-28'")->execute([$chair]);
    $pdo->prepare('DELETE FROM project_governance_penalties WHERE rotation_id=?')->execute([(int)$rotation['id']]);

    echo "=== 董事长按工作日划期、扣光为止 ===\n";
    $term = pg_chair_term($rotation, $policy);
    $periods = count($term['windows']);
    $check($term['from'] === '2026-09-28' && $term['to'] === '2026-12-14', '本任期考核区间 9/28–12/14');
    $check($term['windows'][0] === ['start' => '2026-09-28', 'end' => '2026-10-10'] && $term['windows'][1] === ['start' => '2026-10-11', 'end' => '2026-10-17'], '第 1 期跳过国庆：9/28–10/10；第 2 期 10/11–10/17');
    $check($term['workdays'] === count(pg_workdays_in('2026-09-28', '2026-12-14')) && $periods === intdiv($term['workdays'], 6), '期数 = 工作日数 ÷ 6 取整（' . $term['workdays'] . ' 个工作日 → ' . $periods . ' 期）');
    $check(abs($term['per_miss'] * $periods - $term['pool']) < $periods * 0.01 && $term['pool'] == 10000, '每期扣 ¥' . $term['per_miss'] . ' ≈ 奖金池 ¥10000 ÷ ' . $periods . ' 期');

    echo "=== 董事长脑洞截止督促 ===\n";
    $w = pg_idea_window_status('2026-10-08');
    $check($w['index'] === 1 && $w['end'] === '2026-10-10' && $w['days_left'] === 3 && !$w['submitted'] && $w['penalty'] === $term['per_miss'], '10/8：第 1 期剩 3 天，未提交，每期扣额与计划一致');
    pg_sync_reminders('2026-10-08');
    $check($messages($chair, 'idea-due:') === 0, '剩 3 天不督促');
    pg_sync_reminders('2026-10-09');
    $check($messages($chair, 'idea-due:2026-09-28:2026-10-09') === 1, '10/9 剩 2 天未提交：给栾鑫发站内信');
    pg_sync_reminders('2026-10-09');
    $check($messages($chair, 'idea-due:') === 1, '同一天重复运行不重复发');
    $before = (int)$pdo->query('SELECT COUNT(*) FROM project_messages')->fetchColumn();
    pg_sync_reminders('2026-10-03');
    $check((int)$pdo->query('SELECT COUNT(*) FROM project_messages')->fetchColumn() === $before, '国庆当天不发督促');
    $check(pg_workdays_between('2026-09-27', '2026-10-10') === 6, '工作日不含周日与节假日（9/28–10/10 共 6 天）');

    echo "=== 到期自动扣减 ===\n";
    $check(pg_sync_idea_penalties('2026-10-10') === 0, '截止当天不扣');
    $check(pg_sync_idea_penalties('2026-10-11') === 1, '10/11：第 1 期未提交，扣一笔');
    $amount = (float)$pdo->query('SELECT amount FROM project_governance_penalties WHERE rotation_id=' . (int)$rotation['id'] . " AND window_start='2026-09-28'")->fetchColumn();
    $check($amount === -$term['per_miss'], '扣减额 = −¥' . $term['per_miss']);
    $pdo->prepare("INSERT INTO project_governance_records (record_kind,owner_employee_id,record_date,category,description,created_by_employee_id,created_at) VALUES ('chair',?,'2026-10-13','三天脑洞','第二期脑洞',?,'2026-10-13 10:00:00')")->execute([$chair, $chair]);
    $check(pg_sync_idea_penalties('2026-10-18') === 0, '第 2 期按时提交，不扣');
    pg_sync_idea_penalties('2026-12-15');
    $count = (int)$pdo->query('SELECT COUNT(*) FROM project_governance_penalties WHERE rotation_id=' . (int)$rotation['id'])->fetchColumn();
    $check($count === $periods - 1, '任期结束：除按时提交的第 2 期外，其余 ' . ($periods - 1) . ' 期都扣');
    // 模拟整任期一期都不交：删掉第 2 期脑洞后，该期在下次同步时补扣
    $pdo->exec("DELETE FROM project_governance_records WHERE description='第二期脑洞'");
    pg_sync_idea_penalties('2026-12-15');
    $allMissed = abs((float)$pdo->query('SELECT SUM(amount) FROM project_governance_penalties WHERE rotation_id=' . (int)$rotation['id'])->fetchColumn());
    $count = (int)$pdo->query('SELECT COUNT(*) FROM project_governance_penalties WHERE rotation_id=' . (int)$rotation['id'])->fetchColumn();
    $check($count === $periods && abs($allMissed - $term['pool']) < $periods * 0.01, '整任期一期都不交：' . $periods . ' 期全扣，合计 ¥' . money($allMissed) . ' = 奖金池');
    require_once __DIR__ . '/../includes/ProjectWelfare.php';
    $check(pw_chair_earned(pw_policy(), 100, 0, $allMissed) == 0, '董事长所得为 0，本任期额度全部转入福利池');
    $pdo->prepare('DELETE FROM project_governance_penalties WHERE rotation_id=?')->execute([(int)$rotation['id']]);

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
    $taskPenalties = function () use ($pdo, $taskId) { return $pdo->query('SELECT employee_id,amount,due_date FROM project_governance_committee_penalties WHERE task_record_id=' . $taskId)->fetchAll(); };
    pg_sync_oversight_penalties('2026-10-17');
    $check(count($taskPenalties()) === 0, '截止当天不扣');
    $earlier = (int)$pdo->query('SELECT COUNT(*) FROM project_governance_committee_penalties WHERE task_record_id=' . $ideaId)->fetchColumn();
    $check($earlier === count($committee), '10/3 的任务（截止 10/10）三位监委都没提交，各扣一笔');
    pg_sync_oversight_penalties('2026-10-18');
    $rows = $taskPenalties();
    $check(count($rows) === 1 && (int)$rows[0]['employee_id'] === $c && (float)$rows[0]['amount'] === -150.0 && $rows[0]['due_date'] === '2026-10-17', '10/18：只给未提交的那位监委扣 ¥150');
    $penaltyId = (int)$pdo->query('SELECT id FROM project_governance_committee_penalties WHERE task_record_id=' . $taskId)->fetchColumn();
    $check($messages($c, 'oversight-penalty:' . $penaltyId) === 1, '扣减后给本人发站内信');
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
