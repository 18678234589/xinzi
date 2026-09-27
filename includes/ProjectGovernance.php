<?php
require_once __DIR__ . '/ProjectSettlement.php';
// 脑洞轮值按中国业务日期结算，避免 CLI / Web 的系统默认时区不同造成跨日误扣。
date_default_timezone_set('Asia/Shanghai');

/** 本栏目只认已登录合作人员对应的员工 ID；财务管理员身份不自动取得权限。 */
function pg_member($actor)
{
    if (!$actor || ($actor['type'] ?? '') !== 'employee' || (int)($actor['employee_id'] ?? 0) < 1) return null;
    $q = db()->prepare('SELECT m.employee_id,m.governance_role,e.name FROM project_governance_members m JOIN employees e ON e.id=m.employee_id WHERE m.employee_id=? AND m.is_active=1 LIMIT 1');
    $q->execute([(int)$actor['employee_id']]);
    return $q->fetch() ?: null;
}

function pg_require_member()
{
    $actor = ps_require_actor();
    $member = pg_member($actor);
    if (!$member) { http_response_code(403); exit('无权限查看管理层激励考核'); }
    return [$actor, $member];
}

/**
 * 建议 / Bug / 主动做事奖励台账：财务（管理员）与监委会成员可录入、补凭证、登记发放；其他人 403。
 * 返回 [$actor, 显示名, 是否财务]。
 */
function pg_require_contribution_editor()
{
    $actor = ps_require_actor();
    if (($actor['type'] ?? '') === 'admin') {
        $q = db()->prepare('SELECT username FROM admins WHERE id=?');
        $q->execute([(int)$actor['id']]);
        return [$actor, '财务 ' . ($q->fetchColumn() ?: '#' . (int)$actor['id']), true];
    }
    $member = pg_member($actor);
    if (!$member || $member['governance_role'] !== 'committee') { http_response_code(403); exit('仅财务与监委会可录入建议 / Bug 奖励'); }
    return [$actor, $member['name'], false];
}

/** 录入人字段：管理员写 admin_id，合作人员写 employee_id；系统补录两者皆空。 */
function pg_actor_columns($actor)
{
    if (($actor['type'] ?? '') === 'admin') return [null, (int)$actor['id']];
    return [(int)($actor['employee_id'] ?? 0) > 0 ? (int)$actor['employee_id'] : null, null];
}

/** 多文件上传（name="evidence_files[]"）拆成单个文件数组。 */
function pg_uploaded_files($field)
{
    $files = [];
    if (!isset($field['name'])) return $files;
    if (!is_array($field['name'])) return [$field];
    foreach ($field['name'] as $i => $name) {
        $files[] = ['name' => $name, 'type' => $field['type'][$i] ?? '', 'tmp_name' => $field['tmp_name'][$i] ?? '', 'error' => $field['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size' => $field['size'][$i] ?? 0];
    }
    return $files;
}

function pg_kind_label($kind)
{
    return ['chair' => '轮值董事长事项', 'committee' => '监委会监督', 'contribution' => '建议 / Bug / 主动做事'][$kind] ?? '其他';
}

function pg_review_label($state)
{
    return ['pending' => '待监委核验', 'approved' => '已核验', 'rejected' => '已退回'][$state] ?? '待核验';
}

function pg_can_review($member, $record)
{
    return $member && $member['governance_role'] === 'committee'
        && (int)$member['employee_id'] !== (int)$record['owner_employee_id']
        && (int)$member['employee_id'] !== (int)($record['created_by_employee_id'] ?? 0)
        && $record['review_state'] === 'pending';
}

function pg_validate_date($date, $required = false)
{
    $date = trim((string)$date);
    if ($date === '') {
        if ($required) throw new RuntimeException('请填写记录日期');
        return null;
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new RuntimeException('日期格式不正确');
    return $date;
}

function pg_idea_policy()
{
    $rule = db()->query("SELECT cadence_note,penalty_amount,rule_state FROM project_governance_rules WHERE rule_code='chair_idea' LIMIT 1")->fetch();
    if (!$rule || $rule['rule_state'] !== 'confirmed' || (float)$rule['penalty_amount'] <= 0) return null;
    if (preg_match('/每\s*(\d{1,2})\s*天/u', (string)$rule['cadence_note'], $matches)) $days = (int)$matches[1];
    elseif (trim((string)$rule['cadence_note']) === '每周') $days = 7;
    else return null;
    return $days >= 1 && $days <= 31 ? ['days' => $days, 'penalty' => round((float)$rule['penalty_amount'], 2)] : null;
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
    $committee = db()->query("SELECT employee_id FROM project_governance_members WHERE governance_role='committee' AND is_active=1")->fetchAll(PDO::FETCH_COLUMN);
    $last = db()->prepare("SELECT MAX(DATE(created_at)) FROM project_governance_records WHERE record_kind='committee' AND owner_employee_id=? AND review_state<>'rejected'");
    foreach ($committee as $memberId) {
        $last->execute([(int)$memberId]);
        $since = max((string)$last->fetchColumn(), (new DateTimeImmutable(PG_REMINDER_START))->modify('-1 day')->format('Y-m-d'));
        $workdays = pg_workdays_between($since, $today);
        if ($workdays >= 6) {
            $sent += pg_message($memberId, 'oversight_due', '已 ' . $workdays . ' 个工作日未提交监督意见', '按规则监委会每 6 个工作日需要提交一次监督意见（如脑洞进度、完成率、催办结果）。你上次提交是 ' . ($since >= PG_REMINDER_START ? $since : '起算日前') . '，请在“事项与评审”里登记。', '/project/governance.php#new-record', 'oversight-due:' . $since . ':' . intdiv($workdays, 6));
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

/** 仅完整的轮值窗口触发；按真实提交时间而非可回填的“记录日期”核验。 */
function pg_sync_idea_penalties()
{
    $policy = pg_idea_policy();
    if (!$policy) return 0;
    $amount = -$policy['penalty'];
    $rotations = db()->query("SELECT r.*,g.penalty_effective_from FROM project_governance_rotations r LEFT JOIN project_governance_rotation_guard g ON g.rotation_id=r.id WHERE r.start_date<CURDATE() ORDER BY r.start_date,r.id")->fetchAll();
    $validIdea = db()->prepare("SELECT 1 FROM project_governance_records WHERE record_kind='chair' AND category='三天脑洞' AND owner_employee_id=? AND created_at>=? AND created_at<? AND review_state<>'rejected' LIMIT 1");
    $insert = db()->prepare("INSERT IGNORE INTO project_governance_penalties (rotation_id,chair_employee_id,window_start,window_end,amount) VALUES (?,?,?,?,?)");
    $today = new DateTimeImmutable('today');
    $added = 0;
    foreach ($rotations as $rotation) {
        $windowStart = new DateTimeImmutable($rotation['start_date']);
        $rotationEnd = $rotation['end_date'] ? new DateTimeImmutable($rotation['end_date']) : $today->modify('-1 day');
        for ($i = 0; $i < 1000; $i++) {
            $windowEnd = $windowStart->modify('+' . ($policy['days'] - 1) . ' days');
            if ($windowEnd >= $today || $windowEnd > $rotationEnd) break;
            $next = $windowEnd->modify('+1 day');
            if ($rotation['penalty_effective_from'] && $windowStart->format('Y-m-d') < $rotation['penalty_effective_from']) { $windowStart = $next; continue; }
            // 法定节假日例外：窗口内每个节假日把截止日顺延一天，顺延期内提交也算
            $deadline = pg_idea_deadline($windowStart, $windowEnd);
            if ($deadline >= $today) { $windowStart = $next; continue; }
            $validIdea->execute([(int)$rotation['chair_employee_id'],$windowStart->format('Y-m-d 00:00:00'),$deadline->modify('+1 day')->format('Y-m-d 00:00:00')]);
            if (!$validIdea->fetchColumn()) {
                $insert->execute([(int)$rotation['id'],(int)$rotation['chair_employee_id'],$windowStart->format('Y-m-d'),$windowEnd->format('Y-m-d'),$amount]);
                if ($insert->rowCount() === 1) {
                    $added++;
                    ps_audit('governance_penalty', (int)db()->lastInsertId(), 'auto_apply', ['type' => 'system', 'id' => 0], ['chair_employee_id' => (int)$rotation['chair_employee_id'], 'from' => $windowStart->format('Y-m-d'), 'to' => $windowEnd->format('Y-m-d'), 'amount' => $amount]);
                }
            }
            $windowStart = $next;
        }
    }
    return $added;
}

/**
 * 当前轮值董事长本期脑洞窗口（与自动扣减同一口径）：返回窗口起止、剩余天数、是否已有有效提交；
 * 无轮值、规则未确认或尚未到扣减生效日时返回 null。
 */
function pg_idea_window_status($date = null)
{
    $policy = pg_idea_policy();
    $rotation = pg_active_rotation($date);
    if (!$policy || !$rotation) return null;
    $today = new DateTimeImmutable($date ?: 'today');
    $guard = db()->prepare('SELECT penalty_effective_from FROM project_governance_rotation_guard WHERE rotation_id=?');
    $guard->execute([(int)$rotation['id']]);
    $effective = $guard->fetchColumn();
    $start = new DateTimeImmutable($rotation['start_date']);
    // 与 pg_sync_idea_penalties 相同：从轮值起点按 N 天滚动，生效日前的窗口不扣
    while ($start->modify('+' . $policy['days'] . ' days') <= $today) $start = $start->modify('+' . $policy['days'] . ' days');
    $end = $start->modify('+' . ($policy['days'] - 1) . ' days');
    $deadline = pg_idea_deadline($start, $end);
    $q = db()->prepare("SELECT COUNT(*) FROM project_governance_records WHERE record_kind='chair' AND category='三天脑洞' AND owner_employee_id=? AND created_at>=? AND created_at<? AND review_state<>'rejected'");
    $q->execute([(int)$rotation['chair_employee_id'], $start->format('Y-m-d 00:00:00'), $deadline->modify('+1 day')->format('Y-m-d 00:00:00')]);
    $name = db()->prepare('SELECT name FROM employees WHERE id=?');
    $name->execute([(int)$rotation['chair_employee_id']]);
    return ['chair_employee_id' => (int)$rotation['chair_employee_id'], 'chair_name' => (string)$name->fetchColumn(), 'start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'), 'deadline' => $deadline->format('Y-m-d'),
        'days_left' => (int)$today->diff($deadline)->days + 1, 'submitted' => (int)$q->fetchColumn() > 0, 'penalty' => $policy['penalty'], 'counts' => !$effective || $start->format('Y-m-d') >= $effective];
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
            db()->prepare("INSERT IGNORE INTO project_governance_pools (quarter_start,pool_role,opening_amount,source_note) VALUES (?,'chair',?,'按已确认的三个月任期奖金池规则自动建立')")
                ->execute([$quarterStart,$rule['reward_amount']]);
        }
    }
    $q = db()->prepare("SELECT opening_amount,source_note FROM project_governance_pools WHERE quarter_start=? AND pool_role='chair'");
    $q->execute([$quarterStart]);
    $opening = $q->fetch();
    $rotationQuery = db()->prepare('SELECT chair_employee_id,end_date FROM project_governance_rotations WHERE start_date=? ORDER BY id LIMIT 1');
    $rotationQuery->execute([$quarterStart]);
    $rotation = $rotationQuery->fetch();
    $quarterEnd = $rotation && $rotation['end_date'] ? (new DateTimeImmutable($rotation['end_date']))->modify('+1 day')->format('Y-m-d') : (new DateTimeImmutable($quarterStart))->modify('+3 months')->format('Y-m-d');
    $chairFilter = $rotation ? ' AND r.owner_employee_id=' . (int)$rotation['chair_employee_id'] : ' AND EXISTS (SELECT 1 FROM project_governance_members m WHERE m.employee_id=r.owner_employee_id AND m.governance_role=\'chair\')';
    $q = db()->prepare("SELECT COALESCE(SUM(GREATEST(COALESCE(r.bonus_delta,CASE WHEN r.category='三天脑洞' THEN 100 ELSE 0 END),0)),0) AS positive,COALESCE(SUM(LEAST(COALESCE(r.bonus_delta,0),0)),0) AS negative FROM project_governance_records r WHERE r.review_state='approved' AND r.record_kind<>'contribution' AND r.record_date>=? AND r.record_date<?" . $chairFilter);
    $q->execute([$quarterStart,$quarterEnd]);
    $reviewedParts = $q->fetch();
    $reviewed = (float)$reviewedParts['positive'] + (float)$reviewedParts['negative'];
    $penaltyFilter = $rotation ? ' AND chair_employee_id=' . (int)$rotation['chair_employee_id'] : '';
    $q = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM project_governance_penalties WHERE state='applied' AND window_end>=? AND window_end<?" . $penaltyFilter);
    $q->execute([$quarterStart,$quarterEnd]);
    $penalties = (float)$q->fetchColumn();
    // 这是董事长目标额度的站内剩余，不是福利池余额；已获奖励和缺报扣减都不可再领取。
    $balance = $opening ? round(max(0,(float)$opening['opening_amount']-(float)$reviewedParts['positive']+(float)$reviewedParts['negative']+$penalties),2) : null;
    return ['opening' => $opening ? (float)$opening['opening_amount'] : null, 'source_note' => $opening['source_note'] ?? '', 'reviewed' => $reviewed, 'approved_reward' => (float)$reviewedParts['positive'], 'reviewed_penalties' => (float)$reviewedParts['negative'], 'penalties' => $penalties, 'balance' => $balance];
}

function pg_private_dir()
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'project_governance_private';
}

/** 仅接受图片/PDF，随机落盘到站点目录外；返回新建的绝对路径供异常时回收。 */
function pg_store_evidence($recordId, $actor, $file)
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($file['error'] ?? 0) !== UPLOAD_ERR_OK || (int)($file['size'] ?? 0) < 1 || (int)$file['size'] > 8 * 1024 * 1024) {
        throw new RuntimeException('举证文件须为不超过 8 MB 的图片或 PDF');
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if (!is_uploaded_file($tmp)) throw new RuntimeException('举证文件上传无效');
    return pg_save_evidence_file($recordId, $actor, $tmp, (string)($file['name'] ?? '举证材料'), true);
}

/** 落盘并登记一个举证文件；$uploaded=false 用于历史补录（复制本地文件）。 */
function pg_save_evidence_file($recordId, $actor, $source, $originalName, $uploaded = false)
{
    $size = (int)@filesize($source);
    if ($size < 1 || $size > 8 * 1024 * 1024) throw new RuntimeException('举证文件须为不超过 8 MB 的图片或 PDF');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($source);
    $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
    if (!isset($extensions[$mime])) throw new RuntimeException('举证文件只支持 PNG、JPG、WebP 或 PDF');
    $dir = pg_private_dir();
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('举证文件目录不可写');
    $stored = bin2hex(random_bytes(20)) . '.' . $extensions[$mime];
    $path = $dir . DIRECTORY_SEPARATOR . $stored;
    if (!($uploaded ? move_uploaded_file($source, $path) : copy($source, $path))) throw new RuntimeException('举证文件保存失败');
    @chmod($path, 0600);
    $name = trim(str_replace(["\r", "\n", '/', '\\'], '', $originalName));
    if ($name === '') $name = '举证材料.' . $extensions[$mime];
    [$employeeId, $adminId] = pg_actor_columns($actor);
    try {
        db()->prepare('INSERT INTO project_governance_evidence (record_id,original_name,stored_name,mime_type,file_size,uploaded_by_employee_id,uploaded_by_admin_id) VALUES (?,?,?,?,?,?,?)')
            ->execute([$recordId, mb_substr($name, 0, 255), $stored, $mime, $size, $employeeId, $adminId]);
    } catch (Throwable $e) {
        @unlink($path);
        throw $e;
    }
    return $path;
}
