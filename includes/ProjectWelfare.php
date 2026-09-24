<?php
require_once __DIR__ . '/ProjectGovernance.php';

function pw_policy()
{
    return db()->query('SELECT * FROM project_welfare_policy WHERE id=1')->fetch();
}

function pw_quarter($date)
{
    $d = new DateTimeImmutable($date);
    $year = (int)$d->format('Y');
    $monthDay = $d->format('m-d');
    if ($monthDay >= '12-15') return ($year + 1) . '-Q1';
    if ($monthDay >= '09-15') return $year . '-Q4';
    if ($monthDay >= '06-15') return $year . '-Q3';
    if ($monthDay >= '03-15') return $year . '-Q2';
    return $year . '-Q1';
}

function pw_bounds($quarter)
{
    if (!preg_match('/^(20\d{2})-Q([1-4])$/', $quarter, $m)) throw new RuntimeException('季度格式不正确');
    $year = (int)$m[1];
    return match ((int)$m[2]) {
        1 => [($year - 1) . '-12-15', $year . '-03-15'],
        2 => [$year . '-03-15', $year . '-06-15'],
        3 => [$year . '-06-15', $year . '-09-15'],
        4 => [$year . '-09-15', $year . '-12-15'],
    };
}

function pw_previous_quarter($date)
{
    $start = pw_bounds(pw_quarter($date))[0];
    return pw_quarter((new DateTimeImmutable($start))->modify('-1 day')->format('Y-m-d'));
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

function pw_chair_earned($policy, $approvedPositive, $approvedNegative, $autoPenalty)
{
    $innovation = min((float)$policy['chair_quarter_target']-(float)$policy['chair_duty_portion'],max(0,(float)$approvedPositive));
    return round(max(0,min((float)$policy['chair_quarter_target'],(float)$policy['chair_duty_portion']+$innovation-abs((float)$approvedNegative)-abs((float)$autoPenalty))),2);
}

function pw_committee_earned($target, $approvedWithoutAmount, $explicitTotal)
{
    return round(max(0,min((float)$target,(int)$approvedWithoutAmount*50+(float)$explicitTotal)),2);
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
        $lastDay = (new DateTimeImmutable($until))->modify('-1 day')->format('Y-m-d');
        $rot = db()->prepare('SELECT id,chair_employee_id,start_date,end_date FROM project_governance_rotations WHERE start_date=? AND end_date=?');
        $rot->execute([$from,$lastDay]);
        $rotations = $rot->fetchAll();
        if (count($rotations) !== 1) throw new RuntimeException('本任期轮值日期尚未完整确认；奖金只能按整届独立结算，不能跨任期分摊');
        $rotation = $rotations[0];
        $overlap = db()->prepare('SELECT COUNT(*) FROM project_governance_rotations WHERE id<>? AND start_date<? AND (end_date IS NULL OR end_date>=?)');
        $overlap->execute([(int)$rotation['id'],$until,$from]);
        if ((int)$overlap->fetchColumn()) throw new RuntimeException('轮值排期有重叠，先核对换届日期，再独立结算本任期');
        $chairId = (int)$rotation['chair_employee_id'];
        $members = db()->query("SELECT employee_id FROM project_governance_members WHERE governance_role='committee' AND is_active=1 ORDER BY employee_id")->fetchAll(PDO::FETCH_COLUMN);
        if (count($members) !== 3) throw new RuntimeException('监委会人数不是 3 人，需先由财务核对成员');
        $pending = db()->prepare("SELECT COUNT(*) FROM project_governance_records WHERE record_date>=? AND record_date<? AND record_kind IN ('chair','committee') AND review_state='pending'");
        $pending->execute([$from,$until]);
        if ((int)$pending->fetchColumn() > 0) throw new RuntimeException('还有待评审的管理层事项，暂缓季度结转');
        $ideaPending = db()->prepare("SELECT COUNT(*) FROM project_governance_penalties WHERE window_end>=? AND window_end<? AND state NOT IN ('applied','waived')");
        $ideaPending->execute([$from,$until]);
        if ((int)$ideaPending->fetchColumn() > 0) throw new RuntimeException('缺报核验尚未完成');
        $penalty=0.0; $manualPenalty=0.0;
        $penaltyQuery = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM project_governance_penalties WHERE chair_employee_id=? AND state='applied' AND window_end>=? AND window_end<?");
        $recordQuery = db()->prepare("SELECT COALESCE(SUM(GREATEST(COALESCE(bonus_delta,CASE WHEN category='三天脑洞' THEN 100 ELSE 0 END),0)),0) AS positive,COALESCE(SUM(LEAST(COALESCE(bonus_delta,0),0)),0) AS negative FROM project_governance_records WHERE owner_employee_id=? AND record_kind='chair' AND review_state='approved' AND record_date>=? AND record_date<?");
        $penaltyQuery->execute([$chairId,$from,$until]);
        $penalty=abs((float)$penaltyQuery->fetchColumn());
        $recordQuery->execute([$chairId,$from,$until]);
        $records=$recordQuery->fetch();
        $innovation=min((float)$policy['chair_quarter_target']-(float)$policy['chair_duty_portion'],(float)$records['positive']);
        $manualPenalty=abs((float)$records['negative']);
        $chairEarned=pw_chair_earned($policy,(float)$records['positive'],(float)$records['negative'],$penalty);
        $committeeEarned = 0.0;
        $memberDetails = [];
        $q = db()->prepare("SELECT COUNT(*) AS n,COUNT(bonus_delta) AS explicit_count,COALESCE(SUM(bonus_delta),0) AS delta FROM project_governance_records WHERE owner_employee_id=? AND record_kind='committee' AND review_state='approved' AND record_date>=? AND record_date<?");
        foreach ($members as $id) {
            $q->execute([(int)$id,$from,$until]);
            $row = $q->fetch();
            // 原表有效监督每条 ¥50；明确填写的奖惩差额另外计入，个人上限 ¥1,000。
            $earned = pw_committee_earned($policy['committee_person_target'],(int)$row['n']-(int)$row['explicit_count'],(float)$row['delta']);
            $committeeEarned += $earned;
            $memberDetails[(int)$id] = ['verified_records'=>(int)$row['n'],'earned'=>round($earned,2)];
        }
        $chairFunding = (float)$policy['chair_quarter_target'];
        $committeeFunding = count($members) * (float)$policy['committee_person_target'];
        $remainder = round($chairFunding + $committeeFunding - $chairEarned - $committeeEarned, 2);
        if ($remainder < 0) throw new RuntimeException('季度余额异常，请核对');
        $details = ['rotation_id'=>(int)$rotation['id'],'chair_employee_id'=>$chairId,'term_from'=>$from,'term_through'=>$lastDay,'innovation_earned'=>$innovation,'auto_penalties'=>$penalty,'reviewed_penalties'=>$manualPenalty,'committee'=>$memberDetails,'policy'=>$policy];
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
        $yearFrom = pw_bounds($year . '-Q1')[0];
        $yearUntil = pw_bounds($year . '-Q4')[1];
        $q = db()->prepare("SELECT COUNT(*) FROM project_welfare_contributions WHERE occurred_on>=? AND occurred_on<? AND status='pending'");
        $q->execute([$yearFrom,$yearUntil]);
        if ((int)$q->fetchColumn()) throw new RuntimeException('本年度仍有待核验的建议或 Bug');
        $q = db()->prepare("SELECT COUNT(*) FROM project_welfare_contributions WHERE occurred_on>=? AND occurred_on<? AND status='approved'");
        $awardCheck = db()->prepare("SELECT 1 FROM project_welfare_awards WHERE award_type='quarterly' AND period_key=? LIMIT 1");
        for ($i=1; $i<=4; $i++) {
            $key = $year . '-Q' . $i;
            if ($key < $effective) continue;
            [$periodFrom,$periodUntil] = pw_bounds($key);
            $q->execute([$periodFrom,$periodUntil]);
            if (!(int)$q->fetchColumn()) continue;
            $awardCheck->execute([$key]);
            if (!$awardCheck->fetchColumn()) throw new RuntimeException($key . ' 贡献奖励尚未生成');
        }
        $q = db()->prepare('SELECT employee_id,eligible_months FROM project_welfare_year_roster WHERE year_key=? AND confirmed_at IS NOT NULL ORDER BY employee_id');
        $q->execute([(int)$year]);
        $people = $q->fetchAll();
        $weight = array_sum(array_map(static fn($r)=>(int)$r['eligible_months'],$people));
        if (!$weight) throw new RuntimeException('请先由财务确认年终福利人员及参与月份');
        $missing = db()->prepare('SELECT e.name FROM project_users u JOIN employees e ON e.id=u.employee_id LEFT JOIN project_welfare_year_roster r ON r.employee_id=e.id AND r.year_key=? AND r.confirmed_at IS NOT NULL WHERE u.is_active=1 AND u.created_at<? AND r.employee_id IS NULL LIMIT 10');
        $missing->execute([(int)$year,$yearUntil]);
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
