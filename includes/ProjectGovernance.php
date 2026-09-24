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
            $validIdea->execute([(int)$rotation['chair_employee_id'],$windowStart->format('Y-m-d 00:00:00'),$next->format('Y-m-d 00:00:00')]);
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

function pg_chair_pool($quarterStart)
{
    if ($quarterStart >= '2026-10-01') {
        $rule = db()->query("SELECT reward_amount,rule_state FROM project_governance_rules WHERE rule_code='chair_pool' LIMIT 1")->fetch();
        if ($rule && $rule['rule_state'] === 'confirmed' && $rule['reward_amount'] !== null) {
            db()->prepare("INSERT IGNORE INTO project_governance_pools (quarter_start,pool_role,opening_amount,source_note) VALUES (?,'chair',?,'按已确认的季度奖金池规则自动建立')")
                ->execute([$quarterStart,$rule['reward_amount']]);
        }
    }
    $q = db()->prepare("SELECT opening_amount,source_note FROM project_governance_pools WHERE quarter_start=? AND pool_role='chair'");
    $q->execute([$quarterStart]);
    $opening = $q->fetch();
    $quarterEnd = (new DateTimeImmutable($quarterStart))->modify('+3 months')->format('Y-m-d');
    $q = db()->prepare("SELECT COALESCE(SUM(r.bonus_delta),0) FROM project_governance_records r JOIN project_governance_members m ON m.employee_id=r.owner_employee_id AND m.governance_role='chair' WHERE r.review_state='approved' AND r.bonus_delta IS NOT NULL AND r.record_date>=? AND r.record_date<?");
    $q->execute([$quarterStart,$quarterEnd]);
    $reviewed = (float)$q->fetchColumn();
    $q = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM project_governance_penalties WHERE state='applied' AND window_end>=? AND window_end<?");
    $q->execute([$quarterStart,$quarterEnd]);
    $penalties = (float)$q->fetchColumn();
    // 这是董事长目标额度的站内剩余，不是福利池余额；已获奖励和缺报扣减都不可再领取。
    $balance = $opening ? round(max(0,min((float)$opening['opening_amount'],(float)$opening['opening_amount']-$reviewed+$penalties)),2) : null;
    return ['opening' => $opening ? (float)$opening['opening_amount'] : null, 'source_note' => $opening['source_note'] ?? '', 'reviewed' => $reviewed, 'penalties' => $penalties, 'balance' => $balance];
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
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
    if (!isset($extensions[$mime])) throw new RuntimeException('举证文件只支持 PNG、JPG、WebP 或 PDF');
    $dir = pg_private_dir();
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('举证文件目录不可写');
    $stored = bin2hex(random_bytes(20)) . '.' . $extensions[$mime];
    $path = $dir . DIRECTORY_SEPARATOR . $stored;
    if (!move_uploaded_file($tmp, $path)) throw new RuntimeException('举证文件保存失败');
    @chmod($path, 0600);
    $name = trim(str_replace(["\r", "\n", '/', '\\'], '', (string)($file['name'] ?? '举证材料')));
    if ($name === '') $name = '举证材料.' . $extensions[$mime];
    try {
        db()->prepare('INSERT INTO project_governance_evidence (record_id,original_name,stored_name,mime_type,file_size,uploaded_by_employee_id) VALUES (?,?,?,?,?,?)')
            ->execute([$recordId, mb_substr($name, 0, 255), $stored, $mime, (int)$actor['employee_id']]);
    } catch (Throwable $e) {
        @unlink($path);
        throw $e;
    }
    return $path;
}
