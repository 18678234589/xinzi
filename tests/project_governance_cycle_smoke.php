<?php
require_once __DIR__ . '/../includes/ProjectGovernance.php';
if (pg_idea_policy() !== ['days' => 6, 'penalty' => 300.0]) throw new RuntimeException('六天 / 三百元规则未生效');
$chair = db()->query("SELECT employee_id FROM project_governance_members WHERE governance_role='chair' AND is_active=1 ORDER BY employee_id LIMIT 1")->fetchColumn();
if (!$chair) throw new RuntimeException('缺少轮值董事长测试成员');
$start = (new DateTimeImmutable('today'))->modify('-12 days');
$firstEnd = $start->modify('+5 days');
$end = (new DateTimeImmutable('today'))->modify('-1 day');
$quarter = pg_quarter_start($end->format('Y-m-d'));
db()->beginTransaction();
try {
    $beforePool = pg_chair_pool($quarter);
    db()->prepare('INSERT INTO project_governance_rotations (chair_employee_id,start_date,end_date,note,created_by_employee_id) VALUES (?,?,?,?,?)')
        ->execute([$chair,$start->format('Y-m-d'),$end->format('Y-m-d'),'回滚测试',$chair]);
    $rotationId = (int)db()->lastInsertId();
    db()->prepare("INSERT INTO project_governance_records (record_kind,owner_employee_id,record_date,category,description,created_by_employee_id,created_at) VALUES ('chair',?,?,'三天脑洞','测试想法',?,?)")
        ->execute([$chair,$start->format('Y-m-d'),$chair,$firstEnd->format('Y-m-d 10:00:00')]);
    pg_sync_idea_penalties();
    $q = db()->prepare('SELECT id,window_start,window_end,amount,state FROM project_governance_penalties WHERE rotation_id=?');
    $q->execute([$rotationId]);
    $rows = $q->fetchAll();
    if (count($rows) !== 1 || (float)$rows[0]['amount'] !== -300.0 || $rows[0]['state'] !== 'applied') throw new RuntimeException('缺报应自动扣 300，已提交窗口不应扣');
    $afterPenalty = pg_chair_pool($quarter);
    if (round($afterPenalty['penalties'] - $beforePool['penalties'], 2) !== -300.0) throw new RuntimeException('扣减未进入季度奖金池');
    pg_sync_idea_penalties();
    $q->execute([$rotationId]);
    if (count($q->fetchAll()) !== 1) throw new RuntimeException('重复同步不能重复扣');
    db()->prepare("UPDATE project_governance_penalties SET state='waived',waiver_reason='测试豁免',waived_by_employee_id=? WHERE id=?")
        ->execute([$chair,$rows[0]['id']]);
    pg_sync_idea_penalties();
    $q->execute([$rotationId]);
    $afterWaiver = $q->fetchAll();
    if (count($afterWaiver) !== 1 || $afterWaiver[0]['state'] !== 'waived') throw new RuntimeException('豁免后不能再次自动扣');
    $afterWaiverPool = pg_chair_pool($quarter);
    if (round($afterWaiverPool['penalties'] - $beforePool['penalties'], 2) !== 0.0) throw new RuntimeException('豁免后奖金池未恢复');
    echo "governance cycle smoke OK\n";
} finally {
    if (db()->inTransaction()) db()->rollBack();
}
