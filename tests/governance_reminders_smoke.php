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
    $recent = $pdo->prepare("SELECT COUNT(*) FROM project_governance_records WHERE record_kind='committee' AND owner_employee_id=? AND created_at>='2026-09-27'");
    pg_sync_reminders('2026-10-05');
    foreach ($committee as $member) {
        $recent->execute([$member]);
        $name = $pdo->query('SELECT name FROM employees WHERE id=' . $member)->fetchColumn();
        if (!(int)$recent->fetchColumn()) $check($messages($member, 'oversight-due:') === 1, $name . '：9/28 起满 6 个工作日未提交监督意见，发督促');
        $check($messages($member, 'idea-review:' . $ideaId) === 1, $name . '：脑洞待评审超过 1 天，发提醒');
    }
    $check($messages($chair, 'idea-review:') === 0, '董事长本人不收评审提醒');

    $pdo->rollBack();
    echo "\n=== 督促站内信与节假日顺延全部通过，测试数据已回滚 ===\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
