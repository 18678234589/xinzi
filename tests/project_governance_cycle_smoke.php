<?php
// 董事长脑洞按工作日划期：有提交的期不扣，未提交的期扣“奖金池 ÷ 期数”，重复同步不重复扣，豁免后奖金池恢复。事务内回滚。
require_once __DIR__ . '/../includes/ProjectGovernance.php';
$policy = pg_idea_policy();
if (!$policy || $policy['mode'] !== 'workday' || $policy['days'] !== 6) throw new RuntimeException('“每 6 个工作日”规则未生效');
$chair = db()->query("SELECT employee_id FROM project_governance_members WHERE governance_role='chair' AND is_active=1 ORDER BY employee_id LIMIT 1")->fetchColumn();
if (!$chair) throw new RuntimeException('缺少轮值董事长测试成员');
$start = (new DateTimeImmutable('today'))->modify('-20 days');
$end = (new DateTimeImmutable('today'))->modify('-1 day');
$quarter = pg_quarter_start($end->format('Y-m-d'));
db()->beginTransaction();
try {
    $beforePool = pg_chair_pool($quarter);
    db()->prepare('INSERT INTO project_governance_rotations (chair_employee_id,start_date,end_date,note,created_by_employee_id) VALUES (?,?,?,?,?)')
        ->execute([$chair,$start->format('Y-m-d'),$end->format('Y-m-d'),'回滚测试',$chair]);
    $rotationId = (int)db()->lastInsertId();
    $rotation = db()->query('SELECT * FROM project_governance_rotations WHERE id=' . $rotationId)->fetch();
    $term = pg_chair_term($rotation, $policy);
    $periods = count($term['windows']);
    if ($periods < 2) throw new RuntimeException('测试区间工作日不足两期');
    if (abs($term['per_miss'] * $periods - $term['pool']) >= $periods * 0.01) throw new RuntimeException('每期扣额应为奖金池 ÷ 期数');
    $first = $term['windows'][0];
    db()->prepare("INSERT INTO project_governance_records (record_kind,owner_employee_id,record_date,category,description,created_by_employee_id,created_at) VALUES ('chair',?,?,'三天脑洞','测试想法',?,?)")
        ->execute([$chair,$first['start'],$chair,$first['end'] . ' 10:00:00']);
    pg_sync_idea_penalties();
    $q = db()->prepare('SELECT id,window_start,window_end,amount,state FROM project_governance_penalties WHERE rotation_id=? ORDER BY window_start');
    $q->execute([$rotationId]);
    $rows = $q->fetchAll();
    if (count($rows) !== $periods - 1 || $rows[0]['window_start'] === $first['start'] || (float)$rows[0]['amount'] !== -$term['per_miss'] || $rows[0]['state'] !== 'applied') throw new RuntimeException('已提交的期不应扣，其余每期应扣奖金池 ÷ 期数');
    $afterPenalty = pg_chair_pool($quarter);
    if (abs(($afterPenalty['penalties'] - $beforePool['penalties']) + $term['per_miss'] * ($periods - 1)) > 0.001) throw new RuntimeException('扣减未进入季度奖金池');
    pg_sync_idea_penalties();
    $q->execute([$rotationId]);
    if (count($q->fetchAll()) !== $periods - 1) throw new RuntimeException('重复同步不能重复扣');
    db()->prepare("UPDATE project_governance_penalties SET state='waived',waiver_reason='测试豁免',waived_by_employee_id=? WHERE rotation_id=?")
        ->execute([$chair,$rotationId]);
    pg_sync_idea_penalties();
    $q->execute([$rotationId]);
    $afterWaiver = $q->fetchAll();
    if (count($afterWaiver) !== $periods - 1 || array_filter($afterWaiver, function ($r) { return $r['state'] !== 'waived'; })) throw new RuntimeException('豁免后不能再次自动扣');
    $afterWaiverPool = pg_chair_pool($quarter);
    if (round($afterWaiverPool['penalties'] - $beforePool['penalties'], 2) !== 0.0) throw new RuntimeException('豁免后奖金池未恢复');
    echo "governance cycle smoke OK（{$periods} 期，每期扣 {$term['per_miss']}）\n";
} finally {
    if (db()->inTransaction()) db()->rollBack();
}
