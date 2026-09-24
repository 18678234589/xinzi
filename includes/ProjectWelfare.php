<?php
require_once __DIR__ . '/ProjectGovernance.php';

function pw_policy()
{
    return db()->query('SELECT * FROM project_welfare_policy WHERE id=1')->fetch();
}

function pw_quarter($date)
{
    $d = new DateTimeImmutable($date);
    return $d->format('Y') . '-Q' . (int)ceil((int)$d->format('n') / 3);
}

function pw_bounds($quarter)
{
    if (!preg_match('/^(20\d{2})-Q([1-4])$/', $quarter, $m)) throw new RuntimeException('季度格式不正确');
    $start = new DateTimeImmutable($m[1] . '-' . sprintf('%02d', ((int)$m[2] - 1) * 3 + 1) . '-01');
    return [$start->format('Y-m-d'), $start->modify('+3 months')->format('Y-m-d')];
}

function pw_balance()
{
    return round((float)db()->query('SELECT COALESCE(SUM(amount),0) FROM project_welfare_ledger')->fetchColumn(), 2);
}

function pw_balance_as_of($quarter)
{
    $q = db()->prepare('SELECT COALESCE(SUM(amount),0) FROM project_welfare_ledger WHERE quarter_key<=?');
    $q->execute([$quarter]);
    return round((float)$q->fetchColumn(),2);
}

function pw_ledger($source, $type, $amount, $quarter, $employee, $note)
{
    if (abs($amount) < .005) return;
    db()->prepare('INSERT INTO project_welfare_ledger (source_key,event_type,amount,quarter_key,employee_id,note) VALUES (?,?,?,?,?,?)')
        ->execute([$source,$type,round($amount,2),$quarter,$employee,$note]);
}

/** 只在季度结束、轮值完整、全部治理事项已核验时入账；重复执行不会重复转入。 */
function pw_close_quarter($quarter)
{
    $policy = pw_policy();
    if ($quarter < $policy['effective_quarter']) throw new RuntimeException('历史季度须先完成人工对账，不自动追溯转入');
    [$from,$until] = pw_bounds($quarter);
    if ($until > date('Y-m-d')) throw new RuntimeException('季度尚未结束');
    pg_sync_idea_penalties();
    db()->beginTransaction();
    try {
        $lock = db()->prepare('SELECT * FROM project_welfare_policy WHERE id=1 FOR UPDATE');
        $lock->execute();
        $exists = db()->prepare('SELECT quarter_key FROM project_welfare_closures WHERE quarter_key=?');
        $exists->execute([$quarter]);
        if ($exists->fetchColumn()) { db()->commit(); return false; }
        $rot = db()->prepare('SELECT * FROM project_governance_rotations WHERE start_date<? AND (end_date IS NULL OR end_date>=?) ORDER BY start_date');
        $rot->execute([$until,$from]);
        $rotations = $rot->fetchAll();
        $cursor = $from;
        $lastDay = (new DateTimeImmutable($until))->modify('-1 day')->format('Y-m-d');
        $segments = [];
        foreach ($rotations as $rotation) {
            $segmentStart = max($from,$rotation['start_date']);
            $segmentEnd = min($lastDay,$rotation['end_date'] ?: $lastDay);
            if ($segmentStart !== $cursor || $segmentEnd < $segmentStart) throw new RuntimeException('本季度轮值期有空档或重叠，先核对换届排期');
            $segments[] = ['chair_employee_id'=>(int)$rotation['chair_employee_id'],'from'=>$segmentStart,'until'=>(new DateTimeImmutable($segmentEnd))->modify('+1 day')->format('Y-m-d')];
            $cursor = (new DateTimeImmutable($segmentEnd))->modify('+1 day')->format('Y-m-d');
        }
        if ($cursor !== $until || !$segments) throw new RuntimeException('本季度轮值期尚未完整确认；先完成换届投票或核对排期');
        $members = db()->query("SELECT employee_id FROM project_governance_members WHERE governance_role='committee' AND is_active=1 ORDER BY employee_id")->fetchAll(PDO::FETCH_COLUMN);
        if (count($members) !== 3) throw new RuntimeException('监委会人数不是 3 人，需先由财务核对成员');
        $pending = db()->prepare("SELECT COUNT(*) FROM project_governance_records WHERE record_date>=? AND record_date<? AND record_kind IN ('chair','committee') AND review_state='pending'");
        $pending->execute([$from,$until]);
        if ((int)$pending->fetchColumn() > 0) throw new RuntimeException('还有待评审的管理层事项，暂缓季度结转');
        $ideaPending = db()->prepare("SELECT COUNT(*) FROM project_governance_penalties WHERE window_end>=? AND window_end<? AND state NOT IN ('applied','waived')");
        $ideaPending->execute([$from,$until]);
        if ((int)$ideaPending->fetchColumn() > 0) throw new RuntimeException('缺报核验尚未完成');
        $daysInQuarter=(new DateTimeImmutable($from))->diff(new DateTimeImmutable($until))->days;
        $penalty=0.0; $manualPenalty=0.0; $chairEarned=0.0; $segmentDetails=[];
        $penaltyQuery = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM project_governance_penalties WHERE chair_employee_id=? AND state='applied' AND window_end>=? AND window_end<?");
        $recordQuery = db()->prepare("SELECT COALESCE(SUM(GREATEST(bonus_delta,0)),0) AS positive,COALESCE(SUM(LEAST(bonus_delta,0)),0) AS negative FROM project_governance_records WHERE owner_employee_id=? AND record_kind='chair' AND review_state='approved' AND record_date>=? AND record_date<?");
        foreach ($segments as $segment) {
            $id=$segment['chair_employee_id'];
            $segDays=(new DateTimeImmutable($segment['from']))->diff(new DateTimeImmutable($segment['until']))->days;
            $share=$segDays/$daysInQuarter;
            $penaltyQuery->execute([$id,$segment['from'],$segment['until']]);
            $segPenalty=abs((float)$penaltyQuery->fetchColumn());
            $recordQuery->execute([$id,$segment['from'],$segment['until']]);
            $records=$recordQuery->fetch();
            $segInnovation=min(((float)$policy['chair_quarter_target']-(float)$policy['chair_duty_portion'])*$share,(float)$records['positive']);
            $segManualPenalty=abs((float)$records['negative']);
            $segEarned=max(0,(float)$policy['chair_duty_portion']*$share+$segInnovation-$segPenalty-$segManualPenalty);
            $chairEarned+=$segEarned; $penalty+=$segPenalty; $manualPenalty+=$segManualPenalty;
            $segmentDetails[]=$segment+['days'=>$segDays,'earned'=>round($segEarned,2),'auto_penalty'=>$segPenalty,'reviewed_penalty'=>$segManualPenalty];
        }
        $chairEarned=min((float)$policy['chair_quarter_target'],round($chairEarned,2));
        $committeeEarned = 0.0;
        $memberDetails = [];
        $q = db()->prepare("SELECT COUNT(*) AS n,COUNT(bonus_delta) AS explicit_count,COALESCE(SUM(bonus_delta),0) AS delta FROM project_governance_records WHERE owner_employee_id=? AND record_kind='committee' AND review_state='approved' AND record_date>=? AND record_date<?");
        foreach ($members as $id) {
            $q->execute([(int)$id,$from,$until]);
            $row = $q->fetch();
            // 原表有效监督每条 ¥50；明确填写的奖惩差额另外计入，个人上限 ¥1,000。
            $earned = min((float)$policy['committee_person_target'], max(0, ((int)$row['n']-(int)$row['explicit_count']) * 50 + (float)$row['delta']));
            $committeeEarned += $earned;
            $memberDetails[(int)$id] = ['verified_records'=>(int)$row['n'],'earned'=>round($earned,2)];
        }
        $chairFunding = (float)$policy['chair_quarter_target'];
        $committeeFunding = count($members) * (float)$policy['committee_person_target'];
        $remainder = round($chairFunding + $committeeFunding - $chairEarned - $committeeEarned, 2);
        if ($remainder < 0) throw new RuntimeException('季度余额异常，请核对');
        $details = ['rotation_segments'=>$segmentDetails,'auto_penalties'=>$penalty,'reviewed_penalties'=>$manualPenalty,'committee'=>$memberDetails,'policy'=>$policy];
        db()->prepare('INSERT INTO project_welfare_closures (quarter_key,chair_funding,committee_funding,chair_earned,committee_earned,penalty_transfers,remainder_transfer,details_json,closed_at) VALUES (?,?,?,?,?,?,?,?,NOW())')
            ->execute([$quarter,$chairFunding,$committeeFunding,$chairEarned,$committeeEarned,$penalty+$manualPenalty,$remainder,json_encode($details,JSON_UNESCAPED_UNICODE)]);
        pw_ledger('quarter:' . $quarter,'quarter_remainder',$remainder,$quarter,null,'管理层季度奖金池未获得额度结转；含未豁免缺报扣减，不重复入账');
        db()->commit();
        return true;
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
}

/** 审核完成的贡献按 1/3/5 点分享最多 30% 余额；个人不超本次预算 30%。 */
function pw_award_quarter($quarter)
{
    [$from,$until] = pw_bounds($quarter);
    if ($until > date('Y-m-d')) throw new RuntimeException('季度尚未结束');
    db()->beginTransaction();
    try {
        $lock = db()->query('SELECT * FROM project_welfare_policy WHERE id=1 FOR UPDATE')->fetch();
        $exists = db()->prepare('SELECT 1 FROM project_welfare_awards WHERE award_type=\'quarterly\' AND period_key=? LIMIT 1');
        $exists->execute([$quarter]);
        if ($exists->fetchColumn()) { db()->commit(); return false; }
        $closure = db()->prepare('SELECT 1 FROM project_welfare_closures WHERE quarter_key=?');
        $closure->execute([$quarter]);
        if (!$closure->fetchColumn()) throw new RuntimeException('先完成管理层季度结转');
        $year = (int)substr($quarter,0,4);
        if ($year > (int)substr($lock['effective_quarter'],0,4)) {
            $prior = ($year-1) . '-Q4';
            if (pw_balance_as_of($prior) > 0) {
                $priorAward = db()->prepare("SELECT 1 FROM project_welfare_awards WHERE award_type='annual' AND period_key=? LIMIT 1");
                $priorAward->execute([(string)($year-1)]);
                if (!$priorAward->fetchColumn()) throw new RuntimeException('先完成上一年全员年终福利结算，避免动用上年度余额');
            }
        }
        $pending = db()->prepare("SELECT COUNT(*) FROM project_welfare_contributions WHERE occurred_on>=? AND occurred_on<? AND status='pending'");
        $pending->execute([$from,$until]);
        if ((int)$pending->fetchColumn()) throw new RuntimeException('本季度还有待核验的建议或 Bug');
        $q = db()->prepare("SELECT employee_id,SUM(points) AS points FROM project_welfare_contributions WHERE occurred_on>=? AND occurred_on<? AND status='approved' GROUP BY employee_id ORDER BY employee_id");
        $q->execute([$from,$until]);
        $people = $q->fetchAll();
        $totalPoints = array_sum(array_map(static fn($p)=>(int)$p['points'],$people));
        if ($totalPoints < 1) { db()->commit(); return false; }
        $budgetCents = (int)floor(max(0,min(pw_balance(),pw_balance_as_of($quarter))) * (float)$lock['quarterly_award_rate'] * 100 + .0001);
        $capCents = (int)floor($budgetCents * (float)$lock['quarterly_person_cap_rate'] + .0001);
        foreach ($people as $person) {
            $cents = min($capCents,(int)floor($budgetCents * (int)$person['points'] / $totalPoints));
            if ($cents < 1) continue;
            $amount = $cents / 100;
            db()->prepare("INSERT INTO project_welfare_awards (award_type,period_key,employee_id,points,amount) VALUES ('quarterly',?,?,?,?)")
                ->execute([$quarter,(int)$person['employee_id'],(int)$person['points'],$amount]);
            pw_ledger('quarterly:' . $quarter . ':' . $person['employee_id'],'quarterly_award',-$amount,$quarter,(int)$person['employee_id'],'季度建议与 Bug 共创奖励，已预留待财务发放');
        }
        db()->commit();
        return true;
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
}

function pw_award_year($year)
{
    if (!preg_match('/^20\d{2}$/',(string)$year) || (int)$year >= (int)date('Y')) throw new RuntimeException('年终奖只能在完整年度结束后结算');
    db()->beginTransaction();
    try {
        db()->query('SELECT id FROM project_welfare_policy WHERE id=1 FOR UPDATE')->fetch();
        $effective = pw_policy()['effective_quarter'];
        $required = 0;
        for ($i=1; $i<=4; $i++) if ($year . '-Q' . $i >= $effective) $required++;
        if (!$required) throw new RuntimeException('本年度尚未启用福利池规则');
        $q = db()->prepare("SELECT COUNT(*) FROM project_welfare_closures WHERE quarter_key LIKE ?");
        $q->execute([$year . '-Q%']);
        if ((int)$q->fetchColumn() !== $required) throw new RuntimeException('本年度启用后的季度尚未全部结转');
        $q = db()->prepare("SELECT COUNT(*) FROM project_welfare_awards WHERE award_type='annual' AND period_key=?");
        $q->execute([(string)$year]);
        if ((int)$q->fetchColumn()) { db()->commit(); return false; }
        $q = db()->prepare("SELECT COUNT(*) FROM project_welfare_contributions WHERE occurred_on>=? AND occurred_on<? AND status='pending'");
        $q->execute([$year . '-01-01',((int)$year+1) . '-01-01']);
        if ((int)$q->fetchColumn()) throw new RuntimeException('本年度仍有待核验的建议或 Bug');
        $q = db()->prepare("SELECT COUNT(DISTINCT CONCAT(YEAR(occurred_on),'-Q',QUARTER(occurred_on))) FROM project_welfare_contributions WHERE occurred_on>=? AND occurred_on<? AND status='approved' AND NOT EXISTS (SELECT 1 FROM project_welfare_awards a WHERE a.award_type='quarterly' AND a.period_key=CONCAT(YEAR(occurred_on),'-Q',QUARTER(occurred_on)))");
        $q->execute([$year . '-01-01',((int)$year+1) . '-01-01']);
        if ((int)$q->fetchColumn()) throw new RuntimeException('还有季度贡献奖励尚未生成');
        $q = db()->prepare('SELECT employee_id,eligible_months FROM project_welfare_year_roster WHERE year_key=? AND confirmed_at IS NOT NULL ORDER BY employee_id');
        $q->execute([(int)$year]);
        $people = $q->fetchAll();
        $weight = array_sum(array_map(static fn($r)=>(int)$r['eligible_months'],$people));
        if (!$weight) throw new RuntimeException('请先由财务确认年终福利人员及参与月份');
        $missing = db()->prepare('SELECT e.name FROM project_users u JOIN employees e ON e.id=u.employee_id LEFT JOIN project_welfare_year_roster r ON r.employee_id=e.id AND r.year_key=? AND r.confirmed_at IS NOT NULL WHERE u.is_active=1 AND r.employee_id IS NULL LIMIT 10');
        $missing->execute([(int)$year]);
        $missingNames = $missing->fetchAll(PDO::FETCH_COLUMN);
        if ($missingNames) throw new RuntimeException('活跃合作人员名单尚未核对完整：' . implode('、',$missingNames));
        $balanceCents = (int)floor(max(0,min(pw_balance(),pw_balance_as_of($year . '-Q4'))) * 100 + .0001);
        $used = 0;
        foreach ($people as $index=>$person) {
            $cents = $index === count($people)-1 ? $balanceCents-$used : (int)floor($balanceCents * (int)$person['eligible_months'] / $weight);
            $used += $cents;
            if ($cents < 1) continue;
            $amount = $cents / 100;
            db()->prepare("INSERT INTO project_welfare_awards (award_type,period_key,employee_id,points,amount) VALUES ('annual',?,?,?,?)")
                ->execute([(string)$year,(int)$person['employee_id'],(int)$person['eligible_months'],$amount]);
            pw_ledger('annual:' . $year . ':' . $person['employee_id'],'annual_award',-$amount,$year . '-Q4',(int)$person['employee_id'],'年终全员福利预留；按财务核实的参与月份分配');
        }
        db()->commit();
        return true;
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
}
