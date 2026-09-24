<?php
require_once __DIR__ . '/../includes/ProjectGovernance.php';
[$actor,$member] = pg_require_member();
if ($member['governance_role'] !== 'committee') { http_response_code(403); exit('换届投票仅监委会成员可见'); }
pg_sync_election_notices();
$error=''; $success='';
$chairs=db()->query("SELECT m.employee_id,e.name FROM project_governance_members m JOIN employees e ON e.id=m.employee_id WHERE m.governance_role='chair' AND m.is_active=1 ORDER BY e.id")->fetchAll();
$chairIds=array_map('intval',array_column($chairs,'employee_id'));
if ($_SERVER['REQUEST_METHOD']==='POST') {
    ps_check_csrf();
    try {
        $action=(string)($_POST['action']??'');
        $id=(int)($_POST['election_id']??0);
        db()->beginTransaction();
        $q=db()->prepare('SELECT el.*,r.chair_employee_id,r.start_date,r.end_date FROM project_governance_elections el JOIN project_governance_rotations r ON r.id=el.rotation_id WHERE el.id=? FOR UPDATE');
        $q->execute([$id]);
        $election=$q->fetch();
        if (!$election) throw new RuntimeException('投票提醒不存在');
        if ($action==='open') {
            if ($election['status']!=='reminder') throw new RuntimeException('本次投票已发起');
            db()->prepare("UPDATE project_governance_elections SET status='voting',opened_by_employee_id=?,opened_at=NOW() WHERE id=?")
                ->execute([(int)$actor['employee_id'],$id]);
            ps_audit('governance_election',$id,'open_vote',$actor,['deadline'=>$election['deadline_date']]);
            $success='换届投票已发起，三位监委会成员现在可以选择候选人。';
        } elseif ($action==='vote') {
            if ($election['status']!=='voting') throw new RuntimeException('投票尚未开始或已结束');
            $candidate=(int)($_POST['candidate_employee_id']??0);
            if (!in_array($candidate,$chairIds,true)) throw new RuntimeException('请选择在任候选名单中的轮值董事长');
            db()->prepare('INSERT INTO project_governance_votes (election_id,voter_employee_id,candidate_employee_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE candidate_employee_id=VALUES(candidate_employee_id),voted_at=NOW()')
                ->execute([$id,(int)$actor['employee_id'],$candidate]);
            ps_audit('governance_election',$id,'cast_vote',$actor,['candidate_employee_id'=>$candidate]);
            $tally=db()->prepare('SELECT candidate_employee_id,COUNT(*) AS votes FROM project_governance_votes WHERE election_id=? GROUP BY candidate_employee_id ORDER BY votes DESC,candidate_employee_id');
            $tally->execute([$id]);
            $counts=$tally->fetchAll();
            if ($counts && (int)$counts[0]['votes']>=2) {
                $winner=(int)$counts[0]['candidate_employee_id'];
                $nextStart=(new DateTimeImmutable($election['deadline_date']))->modify('+1 day')->format('Y-m-d');
                $nextEnd=(new DateTimeImmutable($nextStart))->modify('+3 months -1 day')->format('Y-m-d');
                $overlap=db()->prepare('SELECT id FROM project_governance_rotations WHERE id<>? AND start_date<=? AND (end_date IS NULL OR end_date>=?) LIMIT 1 FOR UPDATE');
                $overlap->execute([(int)$election['rotation_id'],$nextEnd,$nextStart]);
                if ($overlap->fetchColumn()) throw new RuntimeException('下一任轮值日期已被占用，先联系财务核对排期');
                if ($election['end_date']===null) db()->prepare('UPDATE project_governance_rotations SET end_date=? WHERE id=?')->execute([$election['deadline_date'],(int)$election['rotation_id']]);
                db()->prepare('INSERT INTO project_governance_rotations (chair_employee_id,start_date,end_date,note,created_by_employee_id) VALUES (?,?,?,?,?)')
                    ->execute([$winner,$nextStart,$nextEnd,'监委会换届投票获得至少 2 票',(int)$actor['employee_id']]);
                db()->prepare("UPDATE project_governance_elections SET status='closed',elected_employee_id=?,closed_at=NOW() WHERE id=?")
                    ->execute([$winner,$id]);
                ps_audit('governance_election',$id,'elect',$actor,['winner_employee_id'=>$winner,'votes'=>(int)$counts[0]['votes'],'next_start'=>$nextStart,'next_end'=>$nextEnd]);
                $success='已有至少两票一致，新任轮值期已自动登记。';
            } else $success='投票已记录；达到两票一致后自动登记下一任轮值期。';
        } else throw new RuntimeException('操作无效');
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); $error=$e->getMessage(); }
}
$list=db()->query('SELECT el.*,r.start_date,r.end_date,r.chair_employee_id,e.name AS chair_name,w.name AS winner_name FROM project_governance_elections el JOIN project_governance_rotations r ON r.id=el.rotation_id JOIN employees e ON e.id=r.chair_employee_id LEFT JOIN employees w ON w.id=el.elected_employee_id ORDER BY el.id DESC LIMIT 20')->fetchAll();
$votes=[];
if ($list) {
    $ids=implode(',',array_map('intval',array_column($list,'id')));
    foreach (db()->query('SELECT election_id,voter_employee_id,candidate_employee_id FROM project_governance_votes WHERE election_id IN ('.$ids.')')->fetchAll() as $v) $votes[(int)$v['election_id']][(int)$v['voter_employee_id']]=(int)$v['candidate_employee_id'];
}
$page_title='换届投票提醒';
include __DIR__ . '/../includes/header.php';
?>
<div class="governance-page"><section class="governance-hero"><div><span class="governance-kicker">CO-CREATION / ROTATION</span><h1>换届投票</h1><p>轮值期满前 7 天，系统给监委会生成提醒。任一监委发起投票；三人一人一票，候选人获得至少两票后，自动登记下一届三个月任期。</p></div><span class="governance-role">仅监委会可见</span></section>
<nav class="governance-tabs"><a href="<?php echo BASE_URL; ?>/project/governance_ideas.php">三天脑洞</a><a class="active" href="<?php echo BASE_URL; ?>/project/governance_election.php">换届投票</a><a href="<?php echo BASE_URL; ?>/project/rules.php?domain=governance">考核规则</a></nav>
<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?><?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>
<?php if (!$list): ?><section class="governance-card"><h2>当前无需发起投票</h2><p>提醒将在本届期满前第 7 天自动出现。栾鑫本届自 2026-09-15 开始，按三个月任期到 2026-12-14；预计 2026-12-07 提醒监委会。</p></section><?php endif; ?>
<?php foreach ($list as $item): ?><section class="governance-card" style="margin-top:16px"><div class="governance-heading"><div><span class="governance-step"><?php echo e($item['status']==='closed'?'已完成':($item['status']==='voting'?'正在投票':'请发起投票')); ?></span><h2><?php echo e($item['chair_name']); ?>本届 · <?php echo e($item['start_date']); ?> — <?php echo e($item['deadline_date']); ?></h2><p class="governance-hint">提醒日期 <?php echo e($item['reminder_date']); ?>。<?php if ($item['status']==='closed'): ?>下一任：<?php echo e($item['winner_name']); ?>。<?php else: ?>当前已投 <?php echo count($votes[(int)$item['id']]??[]); ?> / 3 票。<?php endif; ?></p></div></div>
<?php if ($item['status']==='reminder'): ?><form method="post"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="open"><input type="hidden" name="election_id" value="<?php echo (int)$item['id']; ?>"><button class="btn btn-success" type="submit">发起本届换届投票</button></form><?php elseif ($item['status']==='voting'): ?><form method="post" class="form-inline"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="vote"><input type="hidden" name="election_id" value="<?php echo (int)$item['id']; ?>"><label class="mr-2">我的选择</label><select class="form-control mr-2" name="candidate_employee_id" required><option value="">选轮值董事长</option><?php foreach ($chairs as $chair): ?><option value="<?php echo (int)$chair['employee_id']; ?>" <?php echo (($votes[(int)$item['id']][(int)$actor['employee_id']]??0)===(int)$chair['employee_id'])?'selected':''; ?>><?php echo e($chair['name']); ?></option><?php endforeach; ?></select><button class="btn btn-success" type="submit">提交 / 修改我的票</button></form><?php endif; ?></section><?php endforeach; ?></div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
