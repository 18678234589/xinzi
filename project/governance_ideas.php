<?php
require_once __DIR__ . '/../includes/ProjectGovernance.php';
[$actor, $member] = pg_require_member();
$isCommittee = $member['governance_role'] === 'committee';
$error = '';
$chairs = db()->query("SELECT m.employee_id,e.name FROM project_governance_members m JOIN employees e ON e.id=m.employee_id WHERE m.governance_role='chair' AND m.is_active=1 ORDER BY e.id")->fetchAll();
pg_sync_idea_penalties();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    $storedPath = null;
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'create_idea') {
            $ownerId = $isCommittee ? (int)($_POST['owner_employee_id'] ?? 0) : (int)$actor['employee_id'];
            if (!in_array($ownerId, array_map('intval', array_column($chairs, 'employee_id')), true)) throw new RuntimeException('请选择轮值董事长');
            $description = trim((string)($_POST['description'] ?? ''));
            if (mb_strlen($description) < 3 || mb_strlen($description) > 12000) throw new RuntimeException('请填写 3–12000 字的想法或行动计划');
            $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 120);
            if ($title === '') $title = mb_substr(preg_replace('/\s+/u', ' ', $description), 0, 24);
            $date = pg_validate_date($_POST['record_date'] ?? '', true);
            $due = pg_validate_date($_POST['due_date'] ?? '');
            $proof = trim((string)($_POST['evidence_text'] ?? ''));
            if (mb_strlen($proof) > 4000) throw new RuntimeException('结果说明不超过 4000 字');
            $decision = $isCommittee ? (string)($_POST['decision'] ?? 'pending') : 'pending';
            if (!in_array($decision, ['pending','approved','rejected'], true)) throw new RuntimeException('评审结果无效');
            $amount = null;
            $reviewNote = mb_substr(trim((string)($_POST['review_note'] ?? '')), 0, 500);
            $flow = mb_substr(trim((string)($_POST['flow_note'] ?? '')), 0, 255);
            if ($decision !== 'pending') {
                if ($reviewNote === '') throw new RuntimeException('补录已评审结果时，请写明评审依据');
                $rawAmount = trim((string)($_POST['bonus_delta'] ?? ''));
                if ($decision === 'approved' && $rawAmount !== '') {
                    if (!preg_match('/^-?\d{1,6}(?:\.\d{1,2})?$/', $rawAmount) || abs((float)$rawAmount) > 100000) throw new RuntimeException('金额须在 ±100000 元以内，最多两位小数');
                    $amount = round((float)$rawAmount, 2);
                    if ($amount != 0 && $flow === '') throw new RuntimeException('填写奖惩金额时，请说明奖金池或流向');
                }
            }
            db()->beginTransaction();
            $q = db()->prepare('INSERT INTO project_governance_records (record_kind,owner_employee_id,record_date,category,description,due_date,evidence_text,outcome_status,reviewer_employee_id,bonus_delta,flow_note,review_state,review_note,created_by_employee_id,reviewed_at) VALUES (\'chair\',?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $q->execute([$ownerId,$date,'三天脑洞',$title . "\n" . $description,$due,$proof ?: null,$decision === 'pending' ? '待评判' : ($decision === 'approved' ? '有效提出' : '已退回'),$decision === 'pending' ? null : (int)$actor['employee_id'],$amount,$decision === 'approved' ? $flow : '',$decision,$decision === 'pending' ? '' : '补录已评审：' . $reviewNote,(int)$actor['employee_id'],$decision === 'pending' ? null : date('Y-m-d H:i:s')]);
            $id = (int)db()->lastInsertId();
            $storedPath = pg_store_evidence($id, $actor, $_FILES['evidence_file'] ?? []);
            ps_audit('governance_record', $id, $decision === 'pending' ? 'create_idea' : 'backfill_reviewed_idea', $actor, ['owner_employee_id' => $ownerId, 'record_date' => $date, 'decision' => $decision, 'bonus_delta' => $amount]);
            db()->commit();
            header('Location: ' . BASE_URL . '/project/governance_ideas.php?saved=' . $id . '#idea-' . $id);
            exit;
        }
        if ($action === 'review_idea') {
            $id = (int)($_POST['record_id'] ?? 0);
            $decision = (string)($_POST['decision'] ?? '');
            if (!in_array($decision, ['approved','rejected'], true)) throw new RuntimeException('请选择评审结论');
            $reviewNote = mb_substr(trim((string)($_POST['review_note'] ?? '')), 0, 500);
            if ($reviewNote === '') throw new RuntimeException('请写明评审依据或退回原因');
            $rawAmount = trim((string)($_POST['bonus_delta'] ?? ''));
            $amount = null;
            if ($decision === 'approved' && $rawAmount !== '') {
                if (!preg_match('/^-?\d{1,6}(?:\.\d{1,2})?$/', $rawAmount) || abs((float)$rawAmount) > 100000) throw new RuntimeException('金额须在 ±100000 元以内，最多两位小数');
                $amount = round((float)$rawAmount, 2);
            }
            $flow = mb_substr(trim((string)($_POST['flow_note'] ?? '')), 0, 255);
            if ($amount !== null && $amount != 0 && $flow === '') throw new RuntimeException('填写奖惩金额时，请说明奖金池或流向');
            db()->beginTransaction();
            $q = db()->prepare("SELECT id,owner_employee_id,created_by_employee_id,review_state FROM project_governance_records WHERE id=? AND record_kind='chair' AND category='三天脑洞' FOR UPDATE");
            $q->execute([$id]);
            $record = $q->fetch();
            if (!$record || !pg_can_review($member, $record)) throw new RuntimeException('仅其他监委会成员可评审待审脑洞');
            db()->prepare("UPDATE project_governance_records SET review_state=?,outcome_status=?,reviewer_employee_id=?,bonus_delta=?,flow_note=?,review_note=?,reviewed_at=NOW() WHERE id=? AND review_state='pending'")
                ->execute([$decision,$decision === 'approved' ? '有效提出' : '已退回',(int)$actor['employee_id'],$decision === 'approved' ? $amount : null,$decision === 'approved' ? $flow : '',$reviewNote,$id]);
            ps_audit('governance_record', $id, 'review_idea', $actor, ['decision' => $decision, 'bonus_delta' => $amount]);
            db()->commit();
            header('Location: ' . BASE_URL . '/project/governance_ideas.php?reviewed=' . $id . '#idea-' . $id);
            exit;
        }
        if ($action === 'save_rotation') {
            if (!$isCommittee) throw new RuntimeException('仅监委会可登记轮值期');
            $chairId = (int)($_POST['chair_employee_id'] ?? 0);
            if (!in_array($chairId, array_map('intval', array_column($chairs, 'employee_id')), true)) throw new RuntimeException('请选择轮值董事长');
            $start = pg_validate_date($_POST['start_date'] ?? '', true);
            $end = pg_validate_date($_POST['end_date'] ?? '');
            if ($start < date('Y-m-d')) throw new RuntimeException('新轮值期只能从今天或未来开始，避免补扣历史缺报');
            if ($end !== null && $end < $start) throw new RuntimeException('结束日期不能早于开始日期');
            $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255);
            db()->beginTransaction();
            $overlap = db()->prepare("SELECT id FROM project_governance_rotations WHERE start_date<=? AND (end_date IS NULL OR end_date>=?) LIMIT 1 FOR UPDATE");
            $overlap->execute([$end ?: '9999-12-31',$start]);
            if ($overlap->fetchColumn()) throw new RuntimeException('该时间段已有轮值安排，请先核对');
            db()->prepare('INSERT INTO project_governance_rotations (chair_employee_id,start_date,end_date,note,created_by_employee_id) VALUES (?,?,?,?,?)')
                ->execute([$chairId,$start,$end,$note,(int)$actor['employee_id']]);
            ps_audit('governance_rotation',(int)db()->lastInsertId(),'create',$actor,['chair_employee_id'=>$chairId,'start'=>$start,'end'=>$end]);
            db()->commit();
            header('Location: ' . BASE_URL . '/project/governance_ideas.php?rotation=1#rotation');
            exit;
        }
        if ($action === 'close_rotation') {
            if (!$isCommittee) throw new RuntimeException('仅监委会可结束轮值期');
            $id = (int)($_POST['rotation_id'] ?? 0);
            db()->beginTransaction();
            $q = db()->prepare('SELECT id,start_date,end_date FROM project_governance_rotations WHERE id=? FOR UPDATE');
            $q->execute([$id]);
            $rotation = $q->fetch();
            if (!$rotation || $rotation['end_date'] !== null) throw new RuntimeException('轮值期不存在或已结束');
            $today = date('Y-m-d');
            if ($today < $rotation['start_date']) throw new RuntimeException('未来的轮值期尚未开始，请联系管理员调整');
            db()->prepare('UPDATE project_governance_rotations SET end_date=? WHERE id=?')->execute([$today,$id]);
            ps_audit('governance_rotation',$id,'close',$actor,['end_date'=>$today]);
            db()->commit();
            header('Location: ' . BASE_URL . '/project/governance_ideas.php?rotation=1#rotation');
            exit;
        }
        if ($action === 'waive_penalty') {
            if (!$isCommittee) throw new RuntimeException('仅监委会可豁免扣减');
            $id = (int)($_POST['penalty_id'] ?? 0);
            $reason = mb_substr(trim((string)($_POST['waiver_reason'] ?? '')), 0, 500);
            if (mb_strlen($reason) < 4) throw new RuntimeException('请写明至少 4 字的豁免理由');
            db()->beginTransaction();
            $q = db()->prepare('SELECT id,state,chair_employee_id,window_start,window_end FROM project_governance_penalties WHERE id=? FOR UPDATE');
            $q->execute([$id]);
            $penalty = $q->fetch();
            if (!$penalty || $penalty['state'] !== 'applied') throw new RuntimeException('该扣减已处理，请刷新页面');
            db()->prepare("UPDATE project_governance_penalties SET state='waived',waived_by_employee_id=?,waiver_reason=?,waived_at=NOW() WHERE id=?")
                ->execute([(int)$actor['employee_id'],$reason,$id]);
            ps_audit('governance_penalty',$id,'waive',$actor,['chair_employee_id'=>(int)$penalty['chair_employee_id'],'from'=>$penalty['window_start'],'to'=>$penalty['window_end'],'reason'=>$reason]);
            db()->commit();
            header('Location: ' . BASE_URL . '/project/governance_ideas.php?waived=' . $id . '#penalty-' . $id);
            exit;
        }
        if ($action === 'set_pool_opening') {
            if (!$isCommittee) throw new RuntimeException('仅监委会可登记奖金池期初');
            $quarter = pg_quarter_start();
            $raw = trim((string)($_POST['opening_amount'] ?? ''));
            $note = mb_substr(trim((string)($_POST['source_note'] ?? '')), 0, 500);
            if (!preg_match('/^\d{1,6}(?:\.\d{1,2})?$/', $raw) || (float)$raw > 1000000) throw new RuntimeException('请填写正确的奖金池期初余额');
            if (mb_strlen($note) < 4) throw new RuntimeException('请写明期初余额的核对依据');
            db()->beginTransaction();
            db()->prepare("INSERT INTO project_governance_pools (quarter_start,pool_role,opening_amount,source_note,set_by_employee_id) VALUES (?,'chair',?,?,?) ON DUPLICATE KEY UPDATE opening_amount=VALUES(opening_amount),source_note=VALUES(source_note),set_by_employee_id=VALUES(set_by_employee_id)")
                ->execute([$quarter,round((float)$raw,2),$note,(int)$actor['employee_id']]);
            ps_audit('governance_pool',(int)str_replace('-','',substr($quarter,0,7)),'set_opening',$actor,['quarter'=>$quarter,'amount'=>(float)$raw,'source'=>$note]);
            db()->commit();
            header('Location: ' . BASE_URL . '/project/governance_ideas.php?pool=1#pool');
            exit;
        }
        throw new RuntimeException('操作无效');
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        if ($storedPath && is_file($storedPath)) @unlink($storedPath);
        $error = $e->getMessage();
    }
}

$state = (string)($_GET['state'] ?? '');
if (!in_array($state, ['', 'pending','approved','rejected'], true)) $state = '';
$where = "r.record_kind='chair' AND r.category='三天脑洞'";
$params = [];
if ($state !== '') { $where .= ' AND r.review_state=?'; $params[] = $state; }
$q = db()->prepare("SELECT r.*,o.name AS owner_name,v.name AS reviewer_name,c.name AS creator_name FROM project_governance_records r JOIN employees o ON o.id=r.owner_employee_id JOIN employees c ON c.id=r.created_by_employee_id LEFT JOIN employees v ON v.id=r.reviewer_employee_id WHERE $where ORDER BY r.record_date DESC,r.id DESC LIMIT 100");
$q->execute($params);
$ideas = $q->fetchAll();
$pending = (int)db()->query("SELECT COUNT(*) FROM project_governance_records WHERE record_kind='chair' AND category='三天脑洞' AND review_state='pending'")->fetchColumn();
$rotations = db()->query("SELECT r.*,e.name AS chair_name FROM project_governance_rotations r JOIN employees e ON e.id=r.chair_employee_id ORDER BY r.start_date DESC,r.id DESC LIMIT 20")->fetchAll();
$quarterStart = pg_quarter_start();
$pool = pg_chair_pool($quarterStart);
$penalties = [];
if ($isCommittee) {
    $penaltiesQuery = db()->prepare("SELECT p.*,e.name AS chair_name,w.name AS waived_by_name FROM project_governance_penalties p JOIN employees e ON e.id=p.chair_employee_id LEFT JOIN employees w ON w.id=p.waived_by_employee_id WHERE p.window_end>=? AND p.window_end<? ORDER BY p.window_end DESC,p.id DESC LIMIT 60");
    $penaltiesQuery->execute([$quarterStart,(new DateTimeImmutable($quarterStart))->modify('+3 months')->format('Y-m-d')]);
    $penalties = $penaltiesQuery->fetchAll();
}
$proofs = [];
if ($ideas) {
    $ids = array_map('intval', array_column($ideas, 'id'));
    foreach (db()->query('SELECT id,record_id,original_name FROM project_governance_evidence WHERE record_id IN (' . implode(',', $ids) . ')')->fetchAll() as $file) $proofs[(int)$file['record_id']][] = $file;
}
$page_title = '三天脑洞';
include __DIR__ . '/../includes/header.php';
?>
<div class="governance-page governance-ideas-page">
  <section class="governance-hero"><div><span class="governance-kicker">CO-CREATION / IDEA STUDIO</span><h1>三天脑洞</h1><p>把新想法写下来，让进展和评审有据可循。无需等到攒满三天，想到就能记；周期以规则中心最终确认的口径为准。</p></div><span class="governance-role"><?php echo $isCommittee ? '监委会评审台' : '轮值董事长想法台'; ?></span></section>
  <nav class="governance-tabs" aria-label="管理层栏目"><a class="active" aria-current="page" href="<?php echo BASE_URL; ?>/project/governance_ideas.php">三天脑洞</a><a href="<?php echo BASE_URL; ?>/project/governance.php">事项与评审</a><a href="<?php echo BASE_URL; ?>/project/rules.php?domain=governance">规则中心</a></nav>
  <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
  <?php if (isset($_GET['saved'])): ?><div class="alert alert-success">脑洞已记录，之后的进展可在事项台账继续补充。</div><?php endif; ?>
  <?php if (isset($_GET['reviewed'])): ?><div class="alert alert-success">评审已保存，并留下审核记录。</div><?php endif; ?>
  <?php if (isset($_GET['rotation'])): ?><div class="alert alert-success">轮值期已登记。完整六天窗口结束后，系统会核对提交记录。</div><?php endif; ?>
  <?php if (isset($_GET['waived'])): ?><div class="alert alert-success">这笔扣减已豁免，奖金池已同步更新。</div><?php endif; ?>
  <?php if (isset($_GET['pool'])): ?><div class="alert alert-success">奖金池期初余额已保存。</div><?php endif; ?>
  <div class="governance-idea-layout"><section class="governance-card governance-idea-form"><div class="governance-heading"><div><span class="governance-step">01 · 快速记录</span><h2>有个新想法？</h2><p class="governance-hint">写一句主题和想法即可。日期默认今天，执行节点与证据按需补充。</p></div><div class="governance-idea-orb"><i class="fas fa-lightbulb"></i></div></div>
    <form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="create_idea">
      <?php if ($isCommittee): ?><div class="form-group"><label>轮值董事长</label><select class="form-control" name="owner_employee_id" required><option value="">选一位当事人</option><?php foreach ($chairs as $chair): ?><option value="<?php echo (int)$chair['employee_id']; ?>"><?php echo e($chair['name']); ?></option><?php endforeach; ?></select></div><?php endif; ?>
      <div class="form-group"><label>想法主题</label><input class="form-control" name="title" maxlength="120" placeholder="例如：让客户提交需求更轻松"></div>
      <div class="form-group"><label>想法 / 下一步行动 <span class="text-danger">*</span></label><textarea class="form-control" name="description" rows="5" maxlength="12000" required placeholder="这个想法想解决什么问题？准备先做哪一步？"></textarea></div>
      <?php if ($isCommittee): ?><div class="governance-decision"><span>评审进度</span><label><input type="radio" name="decision" value="pending" checked> 先登记，待评审</label><label><input type="radio" name="decision" value="approved"> 已评审通过</label><label><input type="radio" name="decision" value="rejected"> 已评审退回</label></div><div id="governanceIdeaReviewFields" hidden><div class="form-group"><label>评审依据 / 退回原因</label><textarea class="form-control" name="review_note" rows="2" maxlength="500" placeholder="写下为何通过，或需要补充什么"></textarea></div><div class="form-row"><div class="form-group col-md-6"><label>奖惩变动（可留空）</label><input class="form-control" type="number" name="bonus_delta" step="0.01" min="-100000" max="100000" placeholder="奖励正数，扣减负数"></div><div class="form-group col-md-6"><label>奖金池 / 流向</label><input class="form-control" name="flow_note" maxlength="255" placeholder="填写金额时必填"></div></div></div><?php endif; ?>
      <details class="governance-advanced"><summary>补充日期、行动节点与举证（可选）</summary><div class="form-row"><div class="form-group col-md-6"><label>记录日期</label><input class="form-control" type="date" name="record_date" value="<?php echo date('Y-m-d'); ?>" required></div><div class="form-group col-md-6"><label>下一步计划完成日期</label><input class="form-control" type="date" name="due_date"></div></div><div class="form-group"><label>进展 / 结果说明</label><textarea class="form-control" name="evidence_text" rows="2" maxlength="4000" placeholder="可以稍后通过跟进记录补充"></textarea></div><div class="form-group"><label>举证图片或 PDF</label><input class="form-control-file" type="file" name="evidence_file" accept=".png,.jpg,.jpeg,.webp,.pdf"></div></details>
      <button class="btn btn-success governance-submit" type="submit"><?php echo $isCommittee ? '保存这条脑洞' : '提交脑洞，等待评审'; ?></button>
    </form>
  </section><aside class="governance-card governance-idea-side"><span class="governance-step">02 · 轻松跟进</span><h2>每一步都有回声</h2><p>想法提交后，监委会在这里评审。已完成的结果可补录，但需填写评审依据；已评审记录不会被覆盖。</p><div class="governance-idea-counter"><strong><?php echo $pending; ?></strong><span>条脑洞待评审</span></div><a href="<?php echo BASE_URL; ?>/project/rules.php?domain=governance#rule-3">查看想法规则 →</a></aside></div>
  <section class="governance-card" id="pool"><div class="governance-heading"><div><span class="governance-step">季度奖金池</span><h2><?php echo e(substr($quarterStart,0,7)); ?> 起 · 董事长尽职奖金池</h2><p class="governance-hint">每个完整六天轮值窗口没有有效提交，系统自动从本季度奖金池记 −¥300；监委会可逐笔豁免。</p></div><span class="governance-status <?php echo $pool['opening'] === null ? 'pending' : 'approved'; ?>"><?php echo $pool['opening'] === null ? '期初待核对' : '站内已记账'; ?></span></div>
    <div class="governance-pool-grid"><div><small>期初余额</small><strong><?php echo $pool['opening'] === null ? '待填写' : '¥' . money($pool['opening']); ?></strong></div><div><small>已评审奖惩</small><strong><?php echo $pool['reviewed'] >= 0 ? '+' : '−'; ?>¥<?php echo money(abs($pool['reviewed'])); ?></strong></div><div><small>系统扣减</small><strong>−¥<?php echo money(abs($pool['penalties'])); ?></strong></div><div class="governance-pool-balance"><small>站内计算余额</small><strong><?php echo $pool['balance'] === null ? '待核对期初' : '¥' . money($pool['balance']); ?></strong></div></div>
    <?php if ($pool['opening'] === null): ?><p class="governance-hint">本季度早于新系统上线，历史奖金池尚未完整迁入。扣减照样逐笔留账；先核对期初余额，余额才会显示。</p><?php else: ?><p class="governance-hint">期初依据：<?php echo e($pool['source_note']); ?>。只含站内已核验奖惩和自动扣减，历史未导入记录仍需人工核对。</p><?php endif; ?>
    <?php if ($isCommittee): ?><details class="governance-review"><summary><?php echo $pool['opening'] === null ? '登记本季度期初余额' : '更正本季度期初余额'; ?></summary><form method="post"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="set_pool_opening"><div class="form-row"><div class="form-group col-md-4"><label>核对后的期初余额</label><input class="form-control" type="number" name="opening_amount" min="0" max="1000000" step="0.01" value="<?php echo $pool['opening'] === null ? '' : e($pool['opening']); ?>" required></div><div class="form-group col-md-8"><label>来源 / 核对依据</label><input class="form-control" name="source_note" maxlength="500" value="<?php echo e($pool['source_note']); ?>" required placeholder="例如：8 月结余及 9 月已发放记录核对"></div></div><button class="btn btn-success btn-sm" type="submit">保存期初</button></form></details><?php endif; ?>
  </section>
  <section class="governance-card" id="rotation"><div class="governance-heading"><div><span class="governance-step">轮值日程</span><h2>谁在当值，从哪天开始</h2><p class="governance-hint">原表只标了“当值”，未给起止日期。监委会先确认轮值期，系统才会启动自动扣减；不会追扣登记前的历史。</p></div></div>
    <?php if ($isCommittee): ?><form method="post" class="governance-rotation-form"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="save_rotation"><div class="form-row"><div class="form-group col-md-3"><label>轮值董事长</label><select class="form-control" name="chair_employee_id" required><option value="">请选择</option><?php foreach ($chairs as $chair): ?><option value="<?php echo (int)$chair['employee_id']; ?>"><?php echo e($chair['name']); ?></option><?php endforeach; ?></select></div><div class="form-group col-md-3"><label>开始日期</label><input class="form-control" type="date" name="start_date" min="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d'); ?>" required></div><div class="form-group col-md-3"><label>结束日期（可留空）</label><input class="form-control" type="date" name="end_date"></div><div class="form-group col-md-3"><label>备注</label><input class="form-control" name="note" maxlength="255" placeholder="如轮值安排来源"></div></div><button class="btn btn-success btn-sm" type="submit">确认轮值期</button></form><?php endif; ?>
    <?php if (!$rotations): ?><div class="governance-empty">尚未确认轮值日期，因此不会自动生成缺报扣减。</div><?php else: ?><div class="governance-rotation-list"><?php foreach ($rotations as $rotation): ?><div><strong><?php echo e($rotation['chair_name']); ?></strong><span><?php echo e($rotation['start_date']); ?> — <?php echo e($rotation['end_date'] ?: '持续轮值'); ?></span><?php if ($rotation['note']): ?><small><?php echo e($rotation['note']); ?></small><?php endif; ?><?php if ($isCommittee && !$rotation['end_date'] && $rotation['start_date'] <= date('Y-m-d')): ?><form method="post" onsubmit="return confirm('确认今天结束此轮值期？已生成的历史扣减不会自动删除。')"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="close_rotation"><input type="hidden" name="rotation_id" value="<?php echo (int)$rotation['id']; ?>"><button class="btn btn-outline-secondary btn-sm" type="submit">今天结束</button></form><?php endif; ?></div><?php endforeach; ?></div><?php endif; ?>
  </section>
  <?php if ($isCommittee): ?><section class="governance-card" id="penalties"><div class="governance-heading"><div><span class="governance-step">自动核对</span><h2>六天缺报与豁免</h2><p class="governance-hint">按真实提交时间检查完整六天窗口；待评审脑洞先视为已提交，若后来被判无效，再生成扣减。同一窗口只记一次。</p></div></div>
    <?php if (!$penalties): ?><div class="governance-empty">本季度暂无自动扣减。</div><?php endif; ?>
    <?php foreach ($penalties as $penalty): ?><div class="governance-penalty" id="penalty-<?php echo (int)$penalty['id']; ?>"><div><strong><?php echo e($penalty['chair_name']); ?></strong><small><?php echo e($penalty['window_start']); ?> 至 <?php echo e($penalty['window_end']); ?> · 六天未见有效提交</small></div><span class="governance-status <?php echo $penalty['state'] === 'waived' ? 'approved' : 'rejected'; ?>"><?php echo $penalty['state'] === 'waived' ? '已豁免' : '已扣 ¥' . money(abs((float)$penalty['amount'])); ?></span><?php if ($penalty['state'] === 'waived'): ?><small>由 <?php echo e($penalty['waived_by_name'] ?: '监委会'); ?> 豁免：<?php echo e($penalty['waiver_reason']); ?></small><?php else: ?><details class="governance-review"><summary>有合理情况？豁免这笔扣减</summary><form method="post"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="waive_penalty"><input type="hidden" name="penalty_id" value="<?php echo (int)$penalty['id']; ?>"><label>豁免理由</label><textarea class="form-control mb-2" name="waiver_reason" rows="2" maxlength="500" required placeholder="例如：临时请假、轮值调整，附可核对依据"></textarea><button class="btn btn-success btn-sm" type="submit">确认豁免，返还奖金池</button></form></details><?php endif; ?></div><?php endforeach; ?>
  </section><?php endif; ?>
  <section class="governance-card" id="idea-list"><div class="governance-heading"><div><span class="governance-step">想法与评审</span><h2>脑洞记录</h2></div><form method="get" class="governance-filters"><select class="form-control" name="state" aria-label="评审状态"><option value="">全部状态</option><option value="pending" <?php echo $state === 'pending' ? 'selected' : ''; ?>>待评审</option><option value="approved" <?php echo $state === 'approved' ? 'selected' : ''; ?>>已通过</option><option value="rejected" <?php echo $state === 'rejected' ? 'selected' : ''; ?>>已退回</option></select><button class="btn btn-outline-success" type="submit">筛选</button></form></div>
    <?php if (!$ideas): ?><div class="governance-empty">暂无符合条件的脑洞，从上方写下第一条吧。</div><?php endif; ?>
    <?php foreach ($ideas as $idea): $parts = explode("\n", (string)$idea['description'], 2); ?><article class="governance-record governance-idea" id="idea-<?php echo (int)$idea['id']; ?>"><div class="governance-record-top"><span class="governance-status <?php echo e($idea['review_state']); ?>"><?php echo e(pg_review_label($idea['review_state'])); ?></span><small><?php echo e($idea['owner_name']); ?> · <?php echo e($idea['record_date']); ?> · #<?php echo (int)$idea['id']; ?></small></div><h3><?php echo e($parts[0]); ?></h3><p class="governance-description"><?php echo nl2br(e($parts[1] ?? '')); ?></p><?php if ($idea['due_date']): ?><div class="governance-meta">下一步计划：<?php echo e($idea['due_date']); ?></div><?php endif; ?><?php if ($idea['evidence_text']): ?><div class="governance-evidence"><strong>进展 / 结果</strong><p><?php echo nl2br(e($idea['evidence_text'])); ?></p></div><?php endif; ?><?php foreach ($proofs[(int)$idea['id']] ?? [] as $proof): ?><a class="governance-proof" href="<?php echo BASE_URL; ?>/project/governance_evidence.php?id=<?php echo (int)$proof['id']; ?>" target="_blank" rel="noopener noreferrer"><i class="fas fa-paperclip"></i> <?php echo e($proof['original_name']); ?></a><?php endforeach; ?>
      <?php if ($idea['review_state'] !== 'pending'): ?><div class="governance-result">由 <?php echo e($idea['reviewer_name'] ?: '—'); ?> 评审<?php if ($idea['bonus_delta'] !== null): ?> · 奖惩变动 <?php echo (float)$idea['bonus_delta'] >= 0 ? '+' : '−'; ?>¥<?php echo money(abs((float)$idea['bonus_delta'])); ?><?php endif; ?><?php if ($idea['flow_note']): ?> · <?php echo e($idea['flow_note']); ?><?php endif; ?><?php if ($idea['review_note']): ?><p><?php echo e($idea['review_note']); ?></p><?php endif; ?></div><?php endif; ?>
      <?php if (pg_can_review($member, $idea)): ?><details class="governance-review"><summary>评审这条脑洞</summary><form method="post"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="review_idea"><input type="hidden" name="record_id" value="<?php echo (int)$idea['id']; ?>"><div class="governance-decision"><label><input type="radio" name="decision" value="approved" checked> 通过</label><label><input type="radio" name="decision" value="rejected"> 退回补充</label></div><div class="form-group"><label>评审依据 / 退回原因</label><input class="form-control" name="review_note" maxlength="500" required placeholder="一句话说明决定"></div><div class="form-row"><div class="form-group col-md-6"><label>奖惩变动（可留空）</label><input class="form-control" type="number" name="bonus_delta" step="0.01" min="-100000" max="100000" placeholder="例如：100"></div><div class="form-group col-md-6"><label>奖金池 / 流向</label><input class="form-control" name="flow_note" maxlength="255" placeholder="填写金额时必填"></div></div><button class="btn btn-success btn-sm" type="submit">确认评审</button></form></details><?php endif; ?></article><?php endforeach; ?>
  </section>
</div>
<script>(function(){var fields=document.getElementById('governanceIdeaReviewFields');if(!fields)return;var form=fields.closest('form');form.querySelectorAll('input[name="decision"]').forEach(function(r){r.addEventListener('change',function(){fields.hidden=form.querySelector('input[name="decision"]:checked').value==='pending';});});})();</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
