<?php
require_once __DIR__ . '/../includes/ProjectWelfare.php';
$actor = ps_require_actor();
$finance = $actor['role'] === 'finance';
$me = (int)($actor['employee_id'] ?? 0);
$policy = pw_policy();
$error = '';
$success = '';
$department = '';
$managed = [];
if ($me) {
    $q = db()->prepare('SELECT department FROM employees WHERE id=?');
    $q->execute([$me]);
    $department = (string)$q->fetchColumn();
    $q = db()->prepare('SELECT department FROM project_welfare_managers WHERE employee_id=?');
    $q->execute([$me]);
    $managed = $q->fetchAll(PDO::FETCH_COLUMN);
}
$staff = db()->query('SELECT id,name,department FROM employees ORDER BY department,name,id')->fetchAll();
$byId = [];
foreach ($staff as $person) $byId[(int)$person['id']] = $person;
$departments = array_values(array_unique(array_filter(array_column($staff,'department'))));
sort($departments);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'contribute') {
            $owner = (int)($_POST['employee_id'] ?? 0);
            if (!isset($byId[$owner])) throw new RuntimeException('请选择贡献人员');
            if (!$finance && $owner !== $me && !in_array($byId[$owner]['department'],$managed,true)) throw new RuntimeException('只能登记本人或所负责部门的贡献');
            $kind = (string)($_POST['kind'] ?? '');
            if (!in_array($kind,['suggestion','bug'],true)) throw new RuntimeException('请选择建议或 Bug');
            $title = trim((string)($_POST['title'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $evidence = trim((string)($_POST['evidence_text'] ?? ''));
            $occurred = pg_validate_date($_POST['occurred_on'] ?? '',true);
            if (mb_strlen($title)<4 || mb_strlen($title)>160 || mb_strlen($description)<10 || mb_strlen($description)>10000 || mb_strlen($evidence)>4000) throw new RuntimeException('请填写 4–160 字标题、10–10000 字说明，证据不超过 4000 字');
            if ($occurred > date('Y-m-d') || pw_quarter($occurred) < $policy['effective_quarter']) throw new RuntimeException('日期不能在未来或福利池生效前');
            $q = db()->prepare('SELECT 1 FROM project_welfare_closures WHERE quarter_key=?');
            $q->execute([pw_quarter($occurred)]);
            if ($q->fetchColumn()) throw new RuntimeException('该季度已结转，不能补录；请联系财务处理更正');
            $q = db()->prepare("SELECT 1 FROM project_welfare_contributions WHERE employee_id=? AND kind=? AND title=? AND occurred_on=? AND status<>'rejected' LIMIT 1");
            $q->execute([$owner,$kind,$title,$occurred]);
            if ($q->fetchColumn()) throw new RuntimeException('相同人员、日期、标题的记录已存在，请勿重复登记');
            db()->prepare('INSERT INTO project_welfare_contributions (employee_id,created_by_employee_id,created_by_admin_id,department,kind,title,description,evidence_text,occurred_on) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$owner,$finance?null:$me,$finance?(int)$actor['id']:null,$byId[$owner]['department'],$kind,$title,$description,$evidence ?: null,$occurred]);
            ps_audit('welfare_contribution',(int)db()->lastInsertId(),'create',$actor,['employee_id'=>$owner,'kind'=>$kind,'title'=>$title]);
            $success = '已提交，等待财务核验；审核通过后才计入季度奖励。';
        } elseif ($action === 'review') {
            if (!$finance) throw new RuntimeException('仅财务可审核');
            $id = (int)($_POST['id'] ?? 0);
            $status = (string)($_POST['status'] ?? '');
            $tier = (string)($_POST['impact_tier'] ?? 'normal');
            $note = trim((string)($_POST['review_note'] ?? ''));
            if (!in_array($status,['approved','rejected'],true) || !in_array($tier,['normal','implemented','major'],true) || mb_strlen($note)<4 || mb_strlen($note)>500) throw new RuntimeException('请选择结论、等级并写明至少 4 字核验依据');
            db()->beginTransaction();
            $q = db()->prepare('SELECT status,occurred_on,employee_id,evidence_text FROM project_welfare_contributions WHERE id=? FOR UPDATE');
            $q->execute([$id]);
            $row = $q->fetch();
            if (!$row || $row['status'] !== 'pending') throw new RuntimeException('这条记录已处理，请刷新');
            if ($status==='approved' && $tier==='major' && trim((string)$row['evidence_text'])==='') throw new RuntimeException('重大贡献需先补充可核对的结果证据');
            $points = $status === 'approved' ? ['normal'=>1,'implemented'=>3,'major'=>5][$tier] : 0;
            db()->prepare('UPDATE project_welfare_contributions SET status=?,impact_tier=?,points=?,review_note=?,reviewed_by_admin_id=?,reviewed_at=NOW() WHERE id=?')
                ->execute([$status,$tier,$points,$note,(int)$actor['id'],$id]);
            ps_audit('welfare_contribution',$id,'review',$actor,['status'=>$status,'points'=>$points,'note'=>$note]);
            db()->commit();
            $success = '审核结果已记录。';
        } elseif ($action === 'manager') {
            if (!$finance) throw new RuntimeException('仅财务可设置主管');
            $owner = (int)($_POST['employee_id'] ?? 0);
            $dept = (string)($_POST['department'] ?? '');
            if (!isset($byId[$owner]) || !in_array($dept,$departments,true) || $byId[$owner]['department'] !== $dept) throw new RuntimeException('主管须属于所负责部门');
            db()->prepare('INSERT IGNORE INTO project_welfare_managers (department,employee_id,assigned_by_admin) VALUES (?,?,?)')->execute([$dept,$owner,(int)$actor['id']]);
            ps_audit('welfare_manager',$owner,'assign',$actor,['department'=>$dept]);
            $success = '主管录入权限已开通。';
        } elseif ($action === 'unmanager') {
            if (!$finance) throw new RuntimeException('仅财务可设置主管');
            $id = (int)($_POST['id'] ?? 0);
            db()->prepare('DELETE FROM project_welfare_managers WHERE id=?')->execute([$id]);
            ps_audit('welfare_manager',$id,'revoke',$actor,[]);
            $success = '主管录入权限已撤回。';
        } elseif ($action === 'close_quarter' || $action === 'award_quarter') {
            if (!$finance) throw new RuntimeException('仅财务可确认季度结算');
            $qtr = (string)($_POST['quarter'] ?? '');
            if ($action === 'close_quarter') pw_close_quarter($qtr); else pw_award_quarter($qtr);
            ps_audit('welfare_quarter',0,$action,$actor,['quarter'=>$qtr]);
            $success = '季度核算完成；重复提交不会重复记账。';
        } elseif ($action === 'roster_seed') {
            if (!$finance) throw new RuntimeException('仅财务可确认年终名单');
            $year = (int)($_POST['year'] ?? 0);
            if ($year < 2026 || $year > (int)date('Y')) throw new RuntimeException('年度不正确');
            $q = db()->prepare("SELECT 1 FROM project_welfare_awards WHERE award_type='annual' AND period_key=? LIMIT 1");
            $q->execute([(string)$year]);
            if ($q->fetchColumn()) throw new RuntimeException('本年度已生成福利奖励，名单不可再改');
            $months = $year === 2026 ? 3 : 12;
            db()->prepare('INSERT IGNORE INTO project_welfare_year_roster (year_key,employee_id,eligible_months,confirmed_by_admin,confirmed_at) SELECT ?,u.employee_id,?,?,NOW() FROM project_users u WHERE u.is_active=1')
                ->execute([$year,$months,(int)$actor['id']]);
            ps_audit('welfare_roster',0,'seed_active_users',$actor,['year'=>$year,'default_months'=>$months]);
            $success = '已批量确认活跃合作人员；请检查名单和参与月份，必要时逐人修正。';
        } elseif ($action === 'roster') {
            if (!$finance) throw new RuntimeException('仅财务可确认年终名单');
            $year = (int)($_POST['year'] ?? 0);
            $owner = (int)($_POST['employee_id'] ?? 0);
            $months = (int)($_POST['eligible_months'] ?? 0);
            if ($year < 2026 || $year > (int)date('Y') || !isset($byId[$owner]) || $months<1 || $months>12) throw new RuntimeException('请填写正确的年度、人员和参与月份');
            $q = db()->prepare("SELECT 1 FROM project_welfare_awards WHERE award_type='annual' AND period_key=? LIMIT 1");
            $q->execute([(string)$year]);
            if ($q->fetchColumn()) throw new RuntimeException('本年度已生成福利奖励，名单不可再改');
            db()->prepare('INSERT INTO project_welfare_year_roster (year_key,employee_id,eligible_months,confirmed_by_admin,confirmed_at) VALUES (?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE eligible_months=VALUES(eligible_months),confirmed_by_admin=VALUES(confirmed_by_admin),confirmed_at=NOW()')
                ->execute([$year,$owner,$months,(int)$actor['id']]);
            ps_audit('welfare_roster',$owner,'confirm',$actor,['year'=>$year,'months'=>$months]);
            $success = '年终参与资格已确认。';
        } elseif ($action === 'award_year') {
            if (!$finance) throw new RuntimeException('仅财务可确认年终结算');
            $year = (int)($_POST['year'] ?? 0);
            pw_award_year($year);
            ps_audit('welfare_year',0,'award',$actor,['year'=>$year]);
            $success = '年终福利已按已确认人员参与月份预留。';
        } elseif ($action === 'paid') {
            if (!$finance) throw new RuntimeException('仅财务可确认发放');
            $id = (int)($_POST['id'] ?? 0);
            $update=db()->prepare("UPDATE project_welfare_awards SET status='paid',paid_at=NOW() WHERE id=? AND status='calculated'");
            $update->execute([$id]);
            if ($update->rowCount()!==1) throw new RuntimeException('奖励不存在或已标记发放，请刷新');
            ps_audit('welfare_award',$id,'paid',$actor,[]);
            $success = '已标记发放；资金台账不重复扣除。';
        } else throw new RuntimeException('操作无效');
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); $error = $e->getMessage(); }
}
$balance = pw_balance();
$closures = db()->query('SELECT * FROM project_welfare_closures ORDER BY quarter_key DESC LIMIT 8')->fetchAll();
$totalIn = (float)db()->query('SELECT COALESCE(SUM(amount),0) FROM project_welfare_ledger WHERE amount>0')->fetchColumn();
$totalOut = abs((float)db()->query('SELECT COALESCE(SUM(amount),0) FROM project_welfare_ledger WHERE amount<0')->fetchColumn());
$managers = $finance ? db()->query('SELECT m.*,e.name FROM project_welfare_managers m JOIN employees e ON e.id=m.employee_id ORDER BY m.department,e.name')->fetchAll() : [];
$where = $finance ? '' : 'WHERE c.employee_id=' . $me . ' OR c.created_by_employee_id=' . $me;
$contributions = db()->query('SELECT c.*,e.name AS person_name FROM project_welfare_contributions c JOIN employees e ON e.id=c.employee_id ' . $where . ' ORDER BY c.id DESC LIMIT 80')->fetchAll();
$awards = $finance ? db()->query('SELECT a.*,e.name FROM project_welfare_awards a JOIN employees e ON e.id=a.employee_id ORDER BY a.id DESC LIMIT 80')->fetchAll() : [];
$yearRoster = $finance ? db()->query('SELECT r.year_key,r.employee_id,r.eligible_months,e.name,e.department FROM project_welfare_year_roster r JOIN employees e ON e.id=r.employee_id ORDER BY r.year_key DESC,e.department,e.name LIMIT 250')->fetchAll() : [];
$myAwards = [];
if ($me) { $q = db()->prepare('SELECT * FROM project_welfare_awards WHERE employee_id=? ORDER BY id DESC LIMIT 20'); $q->execute([$me]); $myAwards=$q->fetchAll(); }
$currentQuarter = pw_quarter(date('Y-m-d'));
$previousQuarter = pw_quarter((new DateTimeImmutable(pg_quarter_start()))->modify('-1 day')->format('Y-m-d'));
$page_title = '全员福利池';
include __DIR__ . '/../includes/header.php';
?>
<div class="welfare-page">
  <section class="welfare-hero"><div><span>CO-CREATION / ALL-TEAM BENEFITS</span><h1>全员福利池</h1><p>把节省下来的额度，变成大家一起成长的支持。每条好建议、每个被找到并修复的问题，都值得认真看见。</p></div><div class="welfare-balance"><small>可分配余额</small><strong>¥<?php echo money($balance); ?></strong><em>季度结转自动入账 · 奖励预留实时扣减</em></div></section>
  <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>
  <div class="welfare-kpis"><div><small>累计结转</small><strong>¥<?php echo money($totalIn); ?></strong></div><div><small>已预留奖励</small><strong>¥<?php echo money($totalOut); ?></strong></div><div><small>季度贡献奖励上限</small><strong><?php echo number_format((float)$policy['quarterly_award_rate']*100,0); ?>%</strong></div><div><small>年终余额</small><strong>自动结转</strong></div></div>
  <div class="welfare-layout"><section class="welfare-card"><h2>＋ 记录建议或 Bug</h2><p>本人随时可以录入；已授权的部门主管也可以替本部门同伴代录。财务核验后才产生奖励点数。</p>
    <form method="post"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="contribute">
      <div class="form-row"><div class="form-group col-md-6"><label>贡献人</label><select class="form-control" name="employee_id" required><?php foreach ($staff as $person): if (!$finance && (int)$person['id'] !== $me && !in_array($person['department'],$managed,true)) continue; ?><option value="<?php echo (int)$person['id']; ?>" <?php echo (int)$person['id']===$me?'selected':''; ?>><?php echo e($person['name'].' · '.$person['department']); ?></option><?php endforeach; ?></select></div><div class="form-group col-md-3"><label>类型</label><select class="form-control" name="kind"><option value="suggestion">改进建议</option><option value="bug">发现 Bug</option></select></div><div class="form-group col-md-3"><label>发现日期</label><input class="form-control" type="date" name="occurred_on" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required></div></div>
      <div class="form-group"><label>一句话主题</label><input class="form-control" name="title" minlength="4" maxlength="160" placeholder="例如：订单导入时自动提醒重复流水" required></div>
      <div class="form-group"><label>具体问题与改善办法</label><textarea class="form-control" name="description" minlength="10" maxlength="10000" rows="4" required placeholder="发生了什么？影响了谁？你建议怎么改？"></textarea></div>
      <div class="form-group"><label>证据 / 链接 / 复现步骤（可选）</label><textarea class="form-control" name="evidence_text" maxlength="4000" rows="2" placeholder="不填客户隐私、密码或支付信息"></textarea></div><button class="btn btn-success" type="submit">提交，等待核验</button>
    </form></section>
    <section class="welfare-card"><h2>规则一眼看懂</h2><p>自 <?php echo e($policy['effective_quarter']); ?> 起，董事长季度目标 ¥<?php echo money($policy['chair_quarter_target']); ?>；监委会每人每季度 ¥<?php echo money($policy['committee_person_target']); ?>，三人共 ¥<?php echo money((float)$policy['committee_person_target']*3); ?>。季度结束后，未获得的额度才结转全员福利池。</p><p>经财务核实的建议或 Bug：有效 1 点、落地或修复 3 点、重大贡献 5 点。每季度最多使用池余额的 <?php echo number_format((float)$policy['quarterly_award_rate']*100,0); ?>%，每人最多占本次预算的 <?php echo number_format((float)$policy['quarterly_person_cap_rate']*100,0); ?>%。未分配部分留在池内，年终按财务确认的参与月份分配。</p><a href="<?php echo BASE_URL; ?>/project/rules.php?domain=welfare">查看完整福利池规则 →</a></section></div>
  <section class="welfare-card"><h2>季度结转</h2><?php if (!$closures): ?><p class="text-muted">尚无已结转季度。规则从 <?php echo e($policy['effective_quarter']); ?> 生效；季度结束并完成核验后自动入账。</p><?php else: ?><div class="table-responsive"><table class="table table-sm"><thead><tr><th>季度</th><th>董事长额度</th><th>监委会额度</th><th>已获得</th><th>转入福利池</th><th>完成时间</th></tr></thead><tbody><?php foreach ($closures as $c): ?><tr><td><?php echo e($c['quarter_key']); ?></td><td>¥<?php echo money($c['chair_funding']); ?></td><td>¥<?php echo money($c['committee_funding']); ?></td><td>¥<?php echo money((float)$c['chair_earned']+(float)$c['committee_earned']); ?></td><td><strong>¥<?php echo money($c['remainder_transfer']); ?></strong></td><td><?php echo e($c['closed_at']); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
  <?php if ($finance): ?><section class="welfare-card"><h2>财务核验与结算</h2><p>先核对轮值安排、监委人数与待审事项；完成季度结转后核验建议／Bug，再生成奖励。没有历史对账的季度不会自动追溯。</p><form method="post" class="welfare-inline"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="close_quarter"><label>结转季度<input class="form-control" name="quarter" value="<?php echo e($previousQuarter); ?>" pattern="20[0-9]{2}-Q[1-4]" required></label><button class="btn btn-outline-success" type="submit">核验并结转</button></form><form method="post" class="welfare-inline"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="award_quarter"><label>奖励季度<input class="form-control" name="quarter" value="<?php echo e($previousQuarter); ?>" pattern="20[0-9]{2}-Q[1-4]" required></label><button class="btn btn-success" type="submit">核验并生成季度奖励</button></form>
    <h3>待审核贡献</h3><?php $pendingRows=array_values(array_filter($contributions,static fn($c)=>$c['status']==='pending')); if (!$pendingRows): ?><p class="text-muted">暂无待审核记录。</p><?php endif; foreach ($pendingRows as $c): ?><form method="post" class="welfare-review"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="review"><input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>"><div><strong><?php echo e($c['title']); ?></strong><small><?php echo e($c['person_name'].' · '.$c['occurred_on']); ?></small><p><?php echo nl2br(e($c['description'])); ?></p><?php if ($c['evidence_text']): ?><small>证据：<?php echo nl2br(e($c['evidence_text'])); ?></small><?php endif; ?></div><select class="form-control" name="status"><option value="approved">通过</option><option value="rejected">退回</option></select><select class="form-control" name="impact_tier"><option value="normal">有效 · 1 点</option><option value="implemented">已落地 · 3 点</option><option value="major">重大 · 5 点</option></select><input class="form-control" name="review_note" minlength="4" maxlength="500" placeholder="核验依据 / 退回原因" required><button class="btn btn-sm btn-success" type="submit">保存核验</button></form><?php endforeach; ?>
    <h3>部门主管代录权限</h3><form method="post" class="welfare-inline"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="manager"><select class="form-control" name="department" required><option value="">选择部门</option><?php foreach ($departments as $dept): ?><option><?php echo e($dept); ?></option><?php endforeach; ?></select><select class="form-control" name="employee_id" required><option value="">选择该部门主管</option><?php foreach ($staff as $person): ?><option value="<?php echo (int)$person['id']; ?>"><?php echo e($person['name'].' · '.$person['department']); ?></option><?php endforeach; ?></select><button class="btn btn-outline-success" type="submit">授权代录</button></form><?php foreach ($managers as $manager): ?><form method="post" class="welfare-manager"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="unmanager"><input type="hidden" name="id" value="<?php echo (int)$manager['id']; ?>"><span><?php echo e($manager['department'].' · '.$manager['name']); ?></span><button type="submit" class="btn btn-sm btn-link">撤回</button></form><?php endforeach; ?>
    <h3>年终名单与结算</h3><p>核对活跃账户后，可一键按默认月份确认名单；再检查下方名单并修正特殊人员。完整年度结束后，系统按确认月份分配余额；缺少活跃人员时不能结算。</p><form method="post" class="welfare-inline"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="roster_seed"><input class="form-control" type="number" name="year" min="2026" max="<?php echo date('Y'); ?>" value="<?php echo date('Y'); ?>" required><button class="btn btn-outline-success" type="submit">批量确认活跃人员</button></form><form method="post" class="welfare-inline"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="roster"><input class="form-control" type="number" name="year" min="2026" max="<?php echo date('Y'); ?>" value="<?php echo date('Y'); ?>" required><select class="form-control" name="employee_id" required><option value="">合作人员</option><?php foreach ($staff as $person): ?><option value="<?php echo (int)$person['id']; ?>"><?php echo e($person['name'].' · '.$person['department']); ?></option><?php endforeach; ?></select><input class="form-control" type="number" name="eligible_months" min="1" max="12" value="<?php echo date('Y')==='2026'?'3':'12'; ?>" title="参与月份" required><button class="btn btn-outline-success" type="submit">调整并确认资格</button></form><?php if ($yearRoster): ?><details><summary>查看已确认名单（<?php echo count($yearRoster); ?> 人）</summary><div class="table-responsive"><table class="table table-sm"><thead><tr><th>年度</th><th>人员</th><th>部门</th><th>参与月份</th></tr></thead><tbody><?php foreach ($yearRoster as $r): ?><tr><td><?php echo (int)$r['year_key']; ?></td><td><?php echo e($r['name']); ?></td><td><?php echo e($r['department']); ?></td><td><?php echo (int)$r['eligible_months']; ?></td></tr><?php endforeach; ?></tbody></table></div></details><?php endif; ?><form method="post" class="welfare-inline"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="award_year"><input class="form-control" type="number" name="year" min="2026" max="<?php echo date('Y'); ?>" value="<?php echo date('Y'); ?>" required><button class="btn btn-success" type="submit">生成年终福利</button></form>
    <?php if ($awards): ?><h3>奖励发放</h3><div class="table-responsive"><table class="table table-sm"><thead><tr><th>归属</th><th>人员</th><th>金额</th><th>状态</th></tr></thead><tbody><?php foreach ($awards as $a): ?><tr><td><?php echo e($a['period_key'].' · '.($a['award_type']==='annual'?'年终':'季度')); ?></td><td><?php echo e($a['name']); ?></td><td>¥<?php echo money($a['amount']); ?></td><td><?php if ($a['status']==='paid'): ?>已发放<?php else: ?><form method="post"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="paid"><input type="hidden" name="id" value="<?php echo (int)$a['id']; ?>"><button class="btn btn-sm btn-outline-success" type="submit">确认已发放</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
  </section><?php else: ?><section class="welfare-card"><h2>我的贡献与奖励</h2><?php if (!$myAwards): ?><p class="text-muted">还没有已生成的福利奖励。一起把点子变成改变。</p><?php else: ?><?php foreach ($myAwards as $a): ?><div class="welfare-line"><span><?php echo e($a['period_key'].' · '.($a['award_type']==='annual'?'年终福利':'季度奖励')); ?></span><strong>¥<?php echo money($a['amount']); ?></strong><small><?php echo $a['status']==='paid'?'已发放':'待发放'; ?></small></div><?php endforeach; ?><?php endif; ?></section><?php endif; ?>
  <section class="welfare-card"><h2><?php echo $finance?'最近贡献记录':'我的提交记录'; ?></h2><?php if (!$contributions): ?><p class="text-muted">还没有记录，期待你的第一个好想法。</p><?php endif; foreach ($contributions as $c): ?><div class="welfare-line"><span><?php echo e($c['person_name'].' · '.$c['title']); ?></span><small><?php echo e($c['occurred_on']); ?> · <?php echo $c['status']==='approved'?'已核验 '.$c['points'].' 点':($c['status']==='rejected'?'已退回':'待核验'); ?></small></div><?php endforeach; ?></section>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
