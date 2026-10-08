<?php

function pg_idea_policy()
{
    $rule = db()->query("SELECT cadence_note,penalty_amount,rule_state FROM project_governance_rules WHERE rule_code='chair_idea' LIMIT 1")->fetch();
    if (!$rule || $rule['rule_state'] !== 'confirmed') return null;
    // “每 N 个工作日”：按任期工作日划期，每期扣减 = 任期奖金池 ÷ 最少提交期数（整任期不交正好扣光）
    if (preg_match('/每\s*(\d{1,2})\s*个?\s*工作日/u', (string)$rule['cadence_note'], $matches)) return (int)$matches[1] >= 1 ? ['days' => (int)$matches[1], 'mode' => 'workday', 'penalty' => null] : null;
    if ((float)$rule['penalty_amount'] <= 0) return null;
    if (preg_match('/每\s*(\d{1,2})\s*天/u', (string)$rule['cadence_note'], $matches)) $days = (int)$matches[1];
    elseif (trim((string)$rule['cadence_note']) === '每周') $days = 7;
    else return null;
    return $days >= 1 && $days <= 31 ? ['days' => $days, 'mode' => 'calendar', 'penalty' => round((float)$rule['penalty_amount'], 2)] : null;
}

/** 区间内的法定节假日（财务 / 监委会维护）；表未迁移时视为没有节假日。 */
function pg_holiday_dates($from, $to)
{
    try {
        $q = db()->prepare('SELECT holiday_date FROM project_holidays WHERE holiday_date BETWEEN ? AND ?');
        $q->execute([$from, $to]);
        return $q->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return [];
    }
}

/** 脑洞截止日：窗口内有 N 天节假日，就在窗口结束后顺延 N 个非节假日。 */
function pg_idea_deadline(DateTimeImmutable $start, DateTimeImmutable $end)
{
    $remaining = count(pg_holiday_dates($start->format('Y-m-d'), $end->format('Y-m-d')));
    if (!$remaining) return $end;
    $holidays = array_flip(pg_holiday_dates($end->modify('+1 day')->format('Y-m-d'), $end->modify('+60 days')->format('Y-m-d')));
    $deadline = $end;
    while ($remaining > 0) {
        $deadline = $deadline->modify('+1 day');
        if (!isset($holidays[$deadline->format('Y-m-d')])) $remaining--;
    }
    return $deadline;
}

/** 写站内信；同一人同一 dedupe_key 只发一次。 */
function pg_message($employeeId, $category, $title, $body, $link, $dedupeKey)
{
    $q = db()->prepare('INSERT IGNORE INTO project_messages (employee_id,category,title,body,link,dedupe_key) VALUES (?,?,?,?,?,?)');
    $q->execute([(int)$employeeId, $category, mb_substr($title, 0, 160), $body, $link, mb_substr($dedupeKey, 0, 120)]);
    return $q->rowCount();
}

/** 工作日：非周日、非法定节假日。返回 ($from, $to] 之间的工作日数。 */
function pg_workdays_between($from, $to)
{
    $holidays = array_flip(pg_holiday_dates($from, $to));
    $count = 0;
    for ($d = (new DateTimeImmutable($from))->modify('+1 day'); $d <= new DateTimeImmutable($to); $d = $d->modify('+1 day')) {
        if ($d->format('N') !== '7' && !isset($holidays[$d->format('Y-m-d')])) $count++;
    }
    return $count;
}

/** 督促从这一天起算，之前的历史不追发。 */
const PG_REMINDER_START = '2026-09-28';

/**
 * 每日督促（任务与管理层页面打开时都会执行，唯一键防重）：
 * - 轮值董事长：本期脑洞窗口剩 2 天及以内仍未提交，每天一条；自动扣减后通知本人。
 * - 监委会：距本人上次提交监督记录（或起算日）满 6 个工作日，每满 6 个工作日一条；脑洞待评审超过 1 天提醒其他监委。
 * 法定节假日当天不发督促（扣减通知照发）。
 */
function pg_sync_reminders($date = null)
{
    $today = $date ?: date('Y-m-d');
    try { db()->query('SELECT 1 FROM project_messages LIMIT 1'); } catch (PDOException $e) { return 0; }
    $sent = 0;
    $link = '/project/governance_ideas.php';
    $penalties = db()->prepare("SELECT id,chair_employee_id,window_start,window_end,amount FROM project_governance_penalties WHERE state='applied' AND window_end>=?");
    $penalties->execute([PG_REMINDER_START]);
    foreach ($penalties->fetchAll() as $p) {
        $sent += pg_message($p['chair_employee_id'], 'idea_penalty', '三天脑洞缺报已自动扣减 ¥' . money(abs((float)$p['amount'])), $p['window_start'] . ' 至 ' . $p['window_end'] . ' 没有有效的三天脑洞提交，已按规则从本任期奖金池扣减。如有正当理由，请联系监委会申请豁免。', $link . '#pool', 'idea-penalty:' . $p['id']);
    }
    if (pg_holiday_dates($today, $today) || $today < PG_REMINDER_START) return $sent;
    $window = pg_idea_window_status($today);
    if ($window && $window['counts'] && !$window['submitted'] && $window['days_left'] <= 2) {
        $sent += pg_message($window['chair_employee_id'], 'idea_due', '三天脑洞还剩 ' . $window['days_left'] . ' 天截止', '本期窗口 ' . $window['start'] . ' 至 ' . $window['end'] . ($window['deadline'] !== $window['end'] ? '（节假日顺延至 ' . $window['deadline'] . '）' : '') . '，你还没有提交。截止仍无有效提交，系统将自动扣减 ¥' . money($window['penalty']) . '。', $link, 'idea-due:' . $window['start'] . ':' . $today);
    }
    $committee = pg_committee_members();
    // 监委督战：董事长录入任务后 N 天内每位监委须提交监督意见；截止前 2 天（含当天）仍未提交，每天督促一次
    $oversight = pg_oversight_policy();
    if ($oversight) foreach (pg_oversight_tasks($oversight) as $task) {
        if ($task['deadline'] < $today) continue;
        $daysLeft = (int)(new DateTimeImmutable($today))->diff(new DateTimeImmutable($task['deadline']))->days + 1;
        if ($daysLeft > 2) continue;
        $title = $task['category'] === '三天脑洞' ? strtok($task['description'], "\n") : $task['category'];
        foreach ($committee as $memberId) {
            if (pg_oversight_done($task, $memberId)) continue;
            $sent += pg_message($memberId, 'oversight_due', '监督意见还剩 ' . $daysLeft . ' 天截止', $task['owner_name'] . ' 录入的任务「' . mb_substr($title, 0, 40) . '」需要你在 ' . $task['deadline'] . ' 前提交监督意见（进度、催办或评价）。逾期未提交将自动扣减 ¥' . money($oversight['penalty']) . '。', ($task['category'] === '三天脑洞' ? '/project/governance_ideas.php#idea-' : '/project/governance.php#record-') . (int)$task['id'], 'oversight-due:' . (int)$task['id'] . ':' . $today);
        }
    }
    $pending = db()->prepare("SELECT r.id,r.owner_employee_id,r.created_by_employee_id,e.name FROM project_governance_records r JOIN employees e ON e.id=r.owner_employee_id WHERE r.record_kind='chair' AND r.category='三天脑洞' AND r.review_state='pending' AND r.created_at<?");
    $pending->execute([(new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d 00:00:00')]);
    foreach ($pending->fetchAll() as $idea) foreach ($committee as $memberId) {
        if ((int)$memberId === (int)$idea['owner_employee_id'] || (int)$memberId === (int)$idea['created_by_employee_id']) continue;
        $sent += pg_message($memberId, 'idea_review', $idea['name'] . ' 的三天脑洞等待评审', '有一条三天脑洞已提交超过 1 天还没有评审，请尽快给出结论（通过留空金额按 +¥100 计）。', $link . '#idea-' . (int)$idea['id'], 'idea-review:' . (int)$idea['id']);
    }
    return $sent;
}
