<?php

/** 本人未读站内信数；表未迁移时为 0。 */
function pg_unread_messages($employeeId)
{
    try {
        $q = db()->prepare('SELECT COUNT(*) FROM project_messages WHERE employee_id=? AND read_at IS NULL');
        $q->execute([(int)$employeeId]);
        return (int)$q->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

/** 区间内的工作日（周一至周六，不含法定节假日），闭区间，返回 Y-m-d 列表。 */
function pg_workdays_in($from, $to)
{
    $holidays = array_flip(pg_holiday_dates($from, $to));
    $days = [];
    for ($d = new DateTimeImmutable($from); $d <= new DateTimeImmutable($to); $d = $d->modify('+1 day')) {
        if ($d->format('N') !== '7' && !isset($holidays[$d->format('Y-m-d')])) $days[] = $d->format('Y-m-d');
    }
    return $days;
}

/** 本任期董事长奖金池额度：已建立的期初，否则按已确认的“轮值董事长季度尽职奖金池”规则。 */
function pg_chair_pool_amount($rotation)
{
    $q = db()->prepare("SELECT opening_amount FROM project_governance_pools WHERE quarter_start=? AND pool_role='chair'");
    $q->execute([$rotation['start_date']]);
    $opening = $q->fetchColumn();
    if ($opening !== false) return (float)$opening;
    $rule = db()->query("SELECT reward_amount,rule_state FROM project_governance_rules WHERE rule_code='chair_pool' LIMIT 1")->fetch();
    return $rule && $rule['rule_state'] === 'confirmed' ? (float)$rule['reward_amount'] : 0.0;
}

/**
 * 某轮值任期的脑洞考核计划（自动扣减、倒计时、页面说明共用同一口径）：
 * - 工作日模式：从起算日（轮值起点与扣减生效日取晚者）到任期末，每满 N 个工作日为一期，末尾不足 N 个工作日不成期；
 *   每期扣减 = 任期奖金池 ÷ 期数，整任期不交正好扣光。每期截止日 = 该期最后一个工作日。
 * - 日历模式（旧规则）：每 N 天一期、窗口内节假日顺延，每期扣固定金额。
 * 返回 ['from','to','workdays','windows' => [['start','end'],...],'per_miss','pool','mode','days']。
 */
function pg_chair_term($rotation, $policy)
{
    $guard = db()->prepare('SELECT penalty_effective_from FROM project_governance_rotation_guard WHERE rotation_id=?');
    $guard->execute([(int)$rotation['id']]);
    $effective = (string)$guard->fetchColumn();
    $termEnd = $rotation['end_date'] ?: (new DateTimeImmutable($rotation['start_date']))->modify('+3 months -1 day')->format('Y-m-d');
    $from = max($rotation['start_date'], $effective);
    $windows = [];
    if ($policy['mode'] === 'workday') {
        $workdays = pg_workdays_in($from, $termEnd);
        $start = $from;
        foreach (array_chunk($workdays, $policy['days']) as $chunk) {
            if (count($chunk) < $policy['days']) break;
            $end = end($chunk);
            $windows[] = ['start' => $start, 'end' => $end];
            $start = (new DateTimeImmutable($end))->modify('+1 day')->format('Y-m-d');
        }
        $pool = pg_chair_pool_amount($rotation);
        $perMiss = $windows ? round($pool / count($windows), 2) : 0.0;
        return ['from' => $from, 'to' => $termEnd, 'workdays' => count($workdays), 'windows' => $windows, 'per_miss' => $perMiss, 'pool' => $pool, 'mode' => 'workday', 'days' => $policy
    ['days']];
    }
    for ($start = new DateTimeImmutable($rotation['start_date']), $i = 0; $i < 1000; $i++, $start = $start->modify('+' . $policy['days'] . ' days')) {
        $end = $start->modify('+' . ($policy['days'] - 1) . ' days');
        if ($end->format('Y-m-d') > $termEnd) break;
        if ($effective && $start->format('Y-m-d') < $effective) continue;
        $windows[] = ['start' => $start->format('Y-m-d'), 'end' => pg_idea_deadline($start, $end)->format('Y-m-d')];
    }
    return ['from' => $from, 'to' => $termEnd, 'workdays' => count(pg_workdays_in($from, $termEnd)), 'windows' => $windows, 'per_miss' => $policy['penalty'], 'pool' => pg_chair_pool_amount
    ($rotation), 'mode' => 'calendar', 'days' => $policy['days']];
}

/** 仅完整的考核期触发；按真实提交时间而非可回填的“记录日期”核验。 */
function pg_sync_idea_penalties($date = null)
{
    $policy = pg_idea_policy();
    if (!$policy) return 0;
    $today = $date ?: date('Y-m-d');
    $rotations = db()->prepare('SELECT * FROM project_governance_rotations WHERE start_date<? ORDER BY start_date,id');
    $rotations->execute([$today]);
    $validIdea = db()->prepare("SELECT 1 FROM project_governance_records WHERE record_kind='chair' AND category='三天脑洞' AND owner_employee_id=? AND created_at>=? AND created_at<? AND review_state<>'rejected' LIMIT 1"
    );
    $insert = db()->prepare("INSERT IGNORE INTO project_governance_penalties (rotation_id,chair_employee_id,window_start,window_end,amount) VALUES (?,?,?,?,?)");
    $added = 0;
    foreach ($rotations->fetchAll() as $rotation) {
        $term = pg_chair_term($rotation, $policy);
        foreach ($term['windows'] as $window) {
            if ($window['end'] >= $today) break;
            $validIdea->execute([(int)$rotation['chair_employee_id'], $window['start'] . ' 00:00:00', (new DateTimeImmutable($window['end']))->modify('+1 day')->format('Y-m-d 00:00:00'
    )]);
            if ($validIdea->fetchColumn()) continue;
            $insert->execute([(int)$rotation['id'], (int)$rotation['chair_employee_id'], $window['start'], $window['end'], -$term['per_miss']]);
            if ($insert->rowCount() !== 1) continue;
            $added++;
            ps_audit('governance_penalty', (int)db()->lastInsertId(), 'auto_apply', ['type' => 'system', 'id' => 0], ['chair_employee_id' => (int)$rotation['chair_employee_id'], 'from'
    => $window['start'], 'to' => $window['end'], 'amount' => -$term['per_miss'], 'periods' => count($term['windows']), 'pool' => $term['pool']]);
        }
    }
    return $added;
}

/**
 * 当前轮值董事长本期脑洞考核（与自动扣减同一口径）：返回本期起止、剩余天数、是否已有有效提交、每期扣减额及任期计划；
 * 无轮值、规则未确认或已过最后一期时返回 null。
 */
function pg_idea_window_status($date = null)
{
    $policy = pg_idea_policy();
    $today = $date ?: date('Y-m-d');
    $rotation = pg_active_rotation($today);
    if (!$policy || !$rotation) return null;
    $term = pg_chair_term($rotation, $policy);
    $current = null;
    foreach ($term['windows'] as $index => $window) if ($window['end'] >= $today) { $current = $window + ['index' => $index + 1]; break; }
    if (!$current) return null;
    $q = db()->prepare("SELECT COUNT(*) FROM project_governance_records WHERE record_kind='chair' AND category='三天脑洞' AND owner_employee_id=? AND created_at>=? AND created_at<? AND review_state<>'rejected'"
    );
    $q->execute([(int)$rotation['chair_employee_id'], $current['start'] . ' 00:00:00', (new DateTimeImmutable($current['end']))->modify('+1 day')->format('Y-m-d 00:00:00')]);
    $name = db()->prepare('SELECT name FROM employees WHERE id=?');
    $name->execute([(int)$rotation['chair_employee_id']]);
    return ['chair_employee_id' => (int)$rotation['chair_employee_id'], 'chair_name' => (string)$name->fetchColumn(), 'start' => $current['start'], 'end' => $current['end'], 'deadline'
    => $current['end'],
        'days_left' => (int)(new DateTimeImmutable($today))->diff(new DateTimeImmutable($current['end']))->days + 1, 'submitted' => (int)$q->fetchColumn() > 0, 'penalty' => $term['per_miss'
    ], 'counts' => true,
        'index' => $current['index'], 'term' => $term];
}

/** 任期截止前 7 天生成一次站内提醒；轮值起点 +3 个月，即 9/15 起任到 12/14。 */
function pg_election_schedule($startDate, $confirmedEnd = null)
{
    $end = $confirmedEnd ?: (new DateTimeImmutable($startDate))->modify('+3 months -1 day')->format('Y-m-d');
    return ['end'=>$end,'reminder'=>(new DateTimeImmutable($end))->modify('-7 days')->format('Y-m-d')];
}

function pg_sync_election_notices()
{
    $today = date('Y-m-d');
    $rows = db()->query("SELECT id,start_date,end_date FROM project_governance_rotations WHERE start_date<=CURDATE() ORDER BY id")->fetchAll();
    $save = db()->prepare("INSERT IGNORE INTO project_governance_elections (rotation_id,reminder_date,deadline_date) VALUES (?,?,?)");
    $added = 0;
    foreach ($rows as $row) {
        $schedule = pg_election_schedule($row['start_date'],$row['end_date']);
        $end = $schedule['end'];
        $reminder = $schedule['reminder'];
        if ($reminder > $today) continue;
        $save->execute([(int)$row['id'],$reminder,$end]);
        $added += $save->rowCount();
    }
    return $added;
}

function pg_quarter_start($date = null)
{
    $d = $date ? new DateTimeImmutable($date) : new DateTimeImmutable('today');
    $month = (int)(floor(((int)$d->format('n') - 1) / 3) * 3 + 1);
    return $d->format('Y') . '-' . sprintf('%02d', $month) . '-01';
}

function pg_active_rotation($date = null)
{
    $date = $date ?: date('Y-m-d');
    $q = db()->prepare('SELECT * FROM project_governance_rotations WHERE start_date<=? AND (end_date IS NULL OR end_date>=?) ORDER BY start_date DESC LIMIT 1');
    $q->execute([$date,$date]);
    return $q->fetch() ?: null;
}

function pg_chair_pool($quarterStart)
{
    if ($quarterStart >= '2026-09-15') {
        $rule = db()->query("SELECT reward_amount,rule_state FROM project_governance_rules WHERE rule_code='chair_pool' LIMIT 1")->fetch();
        if ($rule && $rule['rule_state'] === 'confirmed' && $rule['reward_amount'] !== null) {
            db()->prepare("INSERT IGNORE INTO project_governance_pools (quarter_start,pool_role,opening_amount,source_note) VALUES (?,'chair',?,'按已确认的三个月任期奖金池规则自动建立')"
    )
                ->execute([$quarterStart,$rule['reward_amount']]);
        }
    }
    $q = db()->prepare("SELECT opening_amount,source_note FROM project_governance_pools WHERE quarter_start=? AND pool_role='chair'");
    $q->execute([$quarterStart]);
    $opening = $q->fetch();
    $rotationQuery = db()->prepare('SELECT chair_employee_id,end_date FROM project_governance_rotations WHERE start_date=? ORDER BY id LIMIT 1');
    $rotationQuery->execute([$quarterStart]);
    $rotation = $rotationQuery->fetch();
    $quarterEnd = $rotation && $rotation['end_date'] ? (new DateTimeImmutable($rotation['end_date']))->modify('+1 day')->format('Y-m-d') : (new DateTimeImmutable($quarterStart))->modify
    ('+3 months')->format('Y-m-d');
    $chairFilter = $rotation ? ' AND r.owner_employee_id=' . (int)$rotation['chair_employee_id'] : ' AND EXISTS (SELECT 1 FROM project_governance_members m WHERE m.employee_id=r.owner_employee_id AND m.governance_role=\'chair\')'
    ;
    $q = db()->prepare("SELECT COALESCE(SUM(GREATEST(COALESCE(r.bonus_delta,CASE WHEN r.category='三天脑洞' THEN 100 ELSE 0 END),0)),0) AS positive,COALESCE(SUM(LEAST(COALESCE(r.bonus_delta,0),0)),0) AS negative FROM project_governance_records r WHERE r.review_state='approved' AND r.record_kind<>'contribution' AND r.record_date>=? AND r.record_date<?"
    . $chairFilter);
    $q->execute([$quarterStart,$quarterEnd]);
    $reviewedParts = $q->fetch();
    $reviewed = (float)$reviewedParts['positive'] + (float)$reviewedParts['negative'];
    $penaltyFilter = $rotation ? ' AND chair_employee_id=' . (int)$rotation['chair_employee_id'] : '';
    $q = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM project_governance_penalties WHERE state='applied' AND window_end>=? AND window_end<?" . $penaltyFilter);
    $q->execute([$quarterStart,$quarterEnd]);
    $penalties = (float)$q->fetchColumn();
    // 这是董事长目标额度的站内剩余，不是福利池余额；已获奖励和缺报扣减都不可再领取。
    $balance = $opening ? round(max(0,(float)$opening['opening_amount']-(float)$reviewedParts['positive']+(float)$reviewedParts['negative']+$penalties),2) : null;
    return ['opening' => $opening ? (float)$opening['opening_amount'] : null, 'source_note' => $opening['source_note'] ?? '', 'reviewed' => $reviewed, 'approved_reward' => (float)$reviewedParts
    ['positive'], 'reviewed_penalties' => (float)$reviewedParts['negative'], 'penalties' => $penalties, 'balance' => $balance];
}
