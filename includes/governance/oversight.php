<?php

/** 监委会督战规则（规则中心“监委会监督反馈”已确认才生效）：['days' => 7, 'penalty' => 150]。 */
function pg_oversight_policy()
{
    $rule = db()->query("SELECT cadence_note,penalty_amount,rule_state FROM project_governance_rules WHERE rule_code='committee_oversight' LIMIT 1")->fetch();
    if (!$rule || $rule['rule_state'] !== 'confirmed' || abs((float)$rule['penalty_amount']) <= 0) return null;
    if (!preg_match('/(\d{1,2})\s*天/u', (string)$rule['cadence_note'], $m)) return null;
    return ['days' => (int)$m[1], 'penalty' => round(abs((float)$rule['penalty_amount']), 2)];
}

/** 需要监委会提交监督意见的任务：轮值董事长本人录入、未被退回、起算日之后。附截止日（录入后 N 天，节假日顺延）。 */
function pg_oversight_tasks($policy)
{
    $rows = db()->prepare("SELECT r.id,r.owner_employee_id,r.category,r.description,r.reviewer_employee_id,r.created_at,e.name AS owner_name FROM project_governance_records r JOIN employees e ON e.id=r.owner_employee_id WHERE r.record_kind='chair' AND r.created_by_employee_id=r.owner_employee_id AND r.review_state<>'rejected' AND r.created_at>=? ORDER BY r.created_at");
    $rows->execute([PG_REMINDER_START . ' 00:00:00']);
    $tasks = [];
    foreach ($rows->fetchAll() as $task) {
        $created = new DateTimeImmutable(substr($task['created_at'], 0, 10));
        $task['deadline'] = pg_idea_deadline($created->modify('+1 day'), $created->modify('+' . $policy['days'] . ' days'))->format('Y-m-d');
        $tasks[] = $task;
    }
    return $tasks;
}

/**
 * 监委会是否已就该任务完成监督（团队口径：任一监委做了即算全体完成）：该监委评审了该任务，
 * 或截止前提交了监督记录（挂在该任务下或未挂任务，未被退回）。返回完成者员工 ID，未完成返回 0。
 */
function pg_oversight_done_by($task)
{
    $members = pg_committee_members();
    if ((int)$task['reviewer_employee_id'] > 0 && in_array((int)$task['reviewer_employee_id'], $members, true)) return (int)$task['reviewer_employee_id'];
    if (!$members) return 0;
    $q = db()->prepare("SELECT owner_employee_id FROM project_governance_records WHERE record_kind='committee' AND owner_employee_id IN (" . implode(',', $members) . ") AND review_state<>'rejected' AND created_at>=? AND created_at<? AND (parent_record_id IS NULL OR parent_record_id=?) ORDER BY created_at LIMIT 1");
    $q->execute([$task['created_at'], (new DateTimeImmutable($task['deadline']))->modify('+1 day')->format('Y-m-d 00:00:00'), (int)$task['id']]);
    return (int)$q->fetchColumn();
}

/**
 * 监委会本任期监督计划：需要监督的次数 = 董事长本任期期数，每次每人 = 每人任期目标（全员福利池规则，默认 1000）÷ 期数。
 */
function pg_committee_unit_plan($rotation)
{
    $policy = pg_idea_policy();
    $periods = $policy && $rotation ? count(pg_chair_term($rotation, $policy)['windows']) : 0;
    try { $target = db()->query('SELECT committee_person_target FROM project_welfare_policy WHERE id=1')->fetchColumn(); } catch (PDOException $e) { $target = false; }
    $target = $target !== false ? (float)$target : 1000.0;
    return ['periods' => $periods, 'target' => $target, 'unit' => $periods ? round($target / $periods, 2) : 0.0];
}

/** 兼容旧调用：团队口径下与具体监委无关。 */
function pg_oversight_done($task, $memberId = null)
{
    return pg_oversight_done_by($task) > 0;
}

/**
 * 监委会团队监督计分（本任期 [$from, $until)）：每项任务只要有一位监委做了有效监督（评审了该董事长任务，或提交并核验通过监督记录）
 * 就计 1 次，同一任务多人提交只计 1 次；未挂任务的监督记录每条计 1 次。每次三位监委各得 50 元。
 * 返回 ['units' => 次数, 'keys' => [...]]。填写了明确金额的监督记录不在此计，按原方式只计给本人。
 */
function pg_committee_team_units($from, $until)
{
    $members = pg_committee_members();
    if (!$members) return ['units' => 0, 'keys' => []];
    $in = implode(',', $members);
    $keys = [];
    $q = db()->prepare("SELECT id,parent_record_id FROM project_governance_records WHERE record_kind='committee' AND review_state='approved' AND bonus_delta IS NULL AND owner_employee_id IN ($in) AND record_date>=? AND record_date<?");
    $q->execute([$from, $until]);
    foreach ($q->fetchAll() as $r) $keys[$r['parent_record_id'] ? 'task:' . (int)$r['parent_record_id'] : 'record:' . (int)$r['id']] = true;
    $q = db()->prepare("SELECT id FROM project_governance_records WHERE record_kind='chair' AND review_state IN ('approved','rejected') AND reviewer_employee_id IN ($in) AND record_date>=? AND record_date<?");
    $q->execute([$from, $until]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) $keys['task:' . (int)$id] = true;
    return ['units' => count($keys), 'keys' => array_keys($keys)];
}

function pg_committee_members()
{
    return array_map('intval', db()->query("SELECT employee_id FROM project_governance_members WHERE governance_role='committee' AND is_active=1 ORDER BY employee_id")->fetchAll(PDO::FETCH_COLUMN));
}

/** 截止日已过仍未提交监督意见的监委，每项任务每人扣一次（唯一键防重）并发站内信。 */
function pg_sync_oversight_penalties($date = null)
{
    $policy = pg_oversight_policy();
    if (!$policy) return 0;
    try { db()->query('SELECT 1 FROM project_governance_committee_penalties LIMIT 1'); } catch (PDOException $e) { return 0; }
    $today = $date ?: date('Y-m-d');
    $insert = db()->prepare('INSERT IGNORE INTO project_governance_committee_penalties (task_record_id,employee_id,due_date,amount) VALUES (?,?,?,?)');
    $added = 0;
    foreach (pg_oversight_tasks($policy) as $task) {
        if ($task['deadline'] >= $today) continue;
        foreach (pg_committee_members() as $memberId) {
            if (pg_oversight_done($task, $memberId)) continue;
            $insert->execute([(int)$task['id'], $memberId, $task['deadline'], -$policy['penalty']]);
            if ($insert->rowCount() !== 1) continue;
            $added++;
            $penaltyId = (int)db()->lastInsertId();
            ps_audit('governance_committee_penalty', $penaltyId, 'auto_apply', ['type' => 'system', 'id' => 0], ['task_record_id' => (int)$task['id'], 'employee_id' => $memberId, 'due' => $task['deadline'], 'amount' => -$policy['penalty']]);
            pg_message($memberId, 'oversight_penalty', '未按时提交监督意见，已自动扣减 ¥' . money($policy['penalty']), $task['owner_name'] . ' 录入的任务「' . mb_substr($task['category'] === '三天脑洞' ? strtok($task['description'], "\n") : $task['category'], 0, 40) . '」截止 ' . $task['deadline'] . ' 前没有你的监督意见，已按规则从你本任期监委奖励中扣减。如有正当理由，请其他监委写明理由豁免。', '/project/governance_ideas.php#penalties', 'oversight-penalty:' . $penaltyId);
        }
    }
    return $added;
}
