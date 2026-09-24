<?php
require_once __DIR__ . '/../includes/ProjectGovernance.php';
[$actor, $member] = pg_require_member();
pg_sync_idea_penalties();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    $storedPath = null;
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'create') {
            $kind = (string)($_POST['record_kind'] ?? '');
            if (!in_array($kind, ['chair', 'committee', 'contribution'], true)) throw new RuntimeException('请选择记录类型');
            if ($kind !== 'contribution' && $kind !== $member['governance_role']) throw new RuntimeException('只能提交本人对应角色的考核事项');
            $ownerId = $kind === 'contribution' ? (int)($_POST['owner_employee_id'] ?? 0) : (int)$actor['employee_id'];
            $ownerQuery = db()->prepare('SELECT id FROM employees WHERE id=? LIMIT 1');
            $ownerQuery->execute([$ownerId]);
            if ($ownerId < 1 || !$ownerQuery->fetchColumn()) throw new RuntimeException('请选择有效的当事人');
            $recordDate = pg_validate_date($_POST['record_date'] ?? '', true);
            $dueDate = pg_validate_date($_POST['due_date'] ?? '');
            $category = mb_substr(trim((string)($_POST['category'] ?? '')), 0, 120);
            if ($category === '') $category = $kind === 'chair' ? '轮值事项' : ($kind === 'committee' ? '监督事项' : '建议 / Bug / 主动做事');
            $description = trim((string)($_POST['description'] ?? ''));
            if (mb_strlen($description) < 3 || mb_strlen($description) > 12000) throw new RuntimeException('具体内容请填写 3–12000 字');
            $commitment = mb_substr(trim((string)($_POST['commitment_note'] ?? '')), 0, 160);
            $dueNote = mb_substr(trim((string)($_POST['due_note'] ?? '')), 0, 160);
            $evidence = trim((string)($_POST['evidence_text'] ?? ''));
            if (mb_strlen($evidence) > 4000) throw new RuntimeException('举证说明不超过 4000 字');
            $parentId = max(0, (int)($_POST['parent_record_id'] ?? 0));
            if ($parentId) {
                $parentQuery = db()->prepare('SELECT id FROM project_governance_records WHERE id=? LIMIT 1');
                $parentQuery->execute([$parentId]);
                if (!$parentQuery->fetchColumn()) throw new RuntimeException('关联的原事项不存在');
            }
            db()->beginTransaction();
            $save = db()->prepare('INSERT INTO project_governance_records (record_kind,owner_employee_id,record_date,category,description,commitment_note,due_date,due_note,evidence_text,parent_record_id,created_by_employee_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $save->execute([$kind, $ownerId, $recordDate, $category, $description, $commitment, $dueDate, $dueNote, $evidence ?: null, $parentId ?: null, (int)$actor['employee_id']]);
            $id = (int)db()->lastInsertId();
            $storedPath = pg_store_evidence($id, $actor, $_FILES['evidence_file'] ?? []);
            ps_audit('governance_record', $id, 'create', $actor, ['kind' => $kind, 'owner_employee_id' => $ownerId, 'record_date' => $recordDate, 'parent_id' => $parentId]);
            db()->commit();
            header('Location: ' . BASE_URL . '/project/governance.php?created=' . $id . '#record-' . $id);
            exit;
        }
        if ($action === 'review') {
            $id = (int)($_POST['record_id'] ?? 0);
            $decision = (string)($_POST['decision'] ?? '');
            if (!in_array($decision, ['approved', 'rejected'], true)) throw new RuntimeException('请选择核验结果');
            $status = trim((string)($_POST['outcome_status'] ?? ''));
            $allowedStatuses = ['有效提出', '进行中', '完整闭环', '推进中', '及时干预', '严重逾期', '无效方案', '有效监督', '待补证据'];
            if ($decision === 'approved' && !in_array($status, $allowedStatuses, true)) throw new RuntimeException('请选择事项状态');
            $amountText = trim((string)($_POST['bonus_delta'] ?? ''));
            $amount = null;
            if ($decision === 'approved' && $amountText !== '') {
                if (!preg_match('/^-?(?:\d{1,6})(?:\.\d{1,2})?$/', $amountText) || abs((float)$amountText) > 100000) throw new RuntimeException('奖惩金额格式不正确');
                $amount = round((float)$amountText, 2);
            }
            $flow = mb_substr(trim((string)($_POST['flow_note'] ?? '')), 0, 255);
            $note = mb_substr(trim((string)($_POST['review_note'] ?? '')), 0, 500);
            if ($decision === 'approved' && $amount !== null && $amount != 0 && $flow === '') throw new RuntimeException('有奖惩金额时请填写奖金池或流向说明');
            if ($decision === 'rejected' && $note === '') throw new RuntimeException('退回时请写明原因');
            db()->beginTransaction();
            $q = db()->prepare('SELECT id,owner_employee_id,created_by_employee_id,review_state FROM project_governance_records WHERE id=? FOR UPDATE');
            $q->execute([$id]);
            $record = $q->fetch();
            if (!$record || !pg_can_review($member, $record)) throw new RuntimeException('仅其他监委会成员可核验待审事项');
            db()->prepare('UPDATE project_governance_records SET review_state=?,outcome_status=?,reviewer_employee_id=?,bonus_delta=?,flow_note=?,review_note=?,reviewed_at=NOW() WHERE id=? AND review_state=\'pending\'')
                ->execute([$decision, $decision === 'approved' ? $status : '已退回', (int)$actor['employee_id'], $decision === 'approved' ? $amount : null, $decision === 'approved' ? $flow : '', $note, $id]);
            ps_audit('governance_record', $id, 'review', $actor, ['decision' => $decision, 'status' => $status, 'bonus_delta' => $amount]);
            db()->commit();
            header('Location: ' . BASE_URL . '/project/governance.php?reviewed=' . $id . '#record-' . $id);
            exit;
        }
        throw new RuntimeException('操作无效');
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        if ($storedPath && is_file($storedPath)) @unlink($storedPath);
        $error = $e->getMessage();
    }
}

$month = trim((string)($_GET['month'] ?? ''));
if ($month !== '' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = '';
$kindFilter = (string)($_GET['kind'] ?? '');
if (!in_array($kindFilter, ['', 'chair', 'committee', 'contribution'], true)) $kindFilter = '';
$where = ['1=1']; $params = [];
if ($month !== '') { $where[] = 'r.record_date>=? AND r.record_date<?'; $params[] = $month . '-01'; $params[] = (new DateTimeImmutable($month . '-01'))->modify('+1 month')->format('Y-m-d'); }
if ($kindFilter !== '') { $where[] = 'r.record_kind=?'; $params[] = $kindFilter; }
$list = db()->prepare('SELECT r.*,o.name AS owner_name,v.name AS reviewer_name,c.name AS creator_name FROM project_governance_records r JOIN employees o ON o.id=r.owner_employee_id LEFT JOIN employees v ON v.id=r.reviewer_employee_id JOIN employees c ON c.id=r.created_by_employee_id WHERE ' . implode(' AND ', $where) . ' ORDER BY r.record_date DESC,r.id DESC LIMIT 150');
$list->execute($params);
$records = $list->fetchAll();
$evidenceByRecord = [];
if ($records) {
    $ids = array_map('intval', array_column($records, 'id'));
    $proofs = db()->query('SELECT id,record_id,original_name FROM project_governance_evidence WHERE record_id IN (' . implode(',', $ids) . ') ORDER BY id')->fetchAll();
    foreach ($proofs as $proof) $evidenceByRecord[(int)$proof['record_id']][] = $proof;
}
$members = db()->query('SELECT m.employee_id,m.governance_role,e.name,u.id AS user_id FROM project_governance_members m JOIN employees e ON e.id=m.employee_id LEFT JOIN project_users u ON u.employee_id=e.id AND u.is_active=1 WHERE m.is_active=1 ORDER BY FIELD(m.governance_role,\'chair\',\'committee\'),e.id')->fetchAll();
$employees = db()->query('SELECT id,name,department FROM employees ORDER BY name,id')->fetchAll();
$pendingCount = (int)db()->query("SELECT COUNT(*) FROM project_governance_records WHERE review_state='pending'")->fetchColumn();
$quarterStartMonth = (int)(floor(((int)date('n') - 1) / 3) * 3 + 1);
$quarterStart = date('Y') . '-' . sprintf('%02d', $quarterStartMonth) . '-01';
$quarterEnd = (new DateTimeImmutable($quarterStart))->modify('+3 months')->format('Y-m-d');
$quarterStmt = db()->prepare("SELECT r.owner_employee_id,e.name,COUNT(*) AS record_count,COALESCE(SUM(r.bonus_delta),0) AS amount FROM project_governance_records r JOIN employees e ON e.id=r.owner_employee_id WHERE r.review_state='approved' AND r.record_date>=? AND r.record_date<? AND r.bonus_delta IS NOT NULL GROUP BY r.owner_employee_id,e.name ORDER BY e.name");
$quarterStmt->execute([$quarterStart, $quarterEnd]);
$quarterRows = $quarterStmt->fetchAll();
$penaltyStmt = db()->prepare("SELECT p.chair_employee_id AS owner_employee_id,e.name,COUNT(*) AS record_count,COALESCE(SUM(p.amount),0) AS amount FROM project_governance_penalties p JOIN employees e ON e.id=p.chair_employee_id WHERE p.state='applied' AND p.window_end>=? AND p.window_end<? GROUP BY p.chair_employee_id,e.name");
$penaltyStmt->execute([$quarterStart,$quarterEnd]);
$quarterByPerson = [];
foreach (array_merge($quarterRows,$penaltyStmt->fetchAll()) as $row) {
    $id = (int)$row['owner_employee_id'];
    if (!isset($quarterByPerson[$id])) $quarterByPerson[$id] = ['owner_employee_id'=>$id,'name'=>$row['name'],'record_count'=>0,'amount'=>0];
    $quarterByPerson[$id]['record_count'] += (int)$row['record_count'];
    $quarterByPerson[$id]['amount'] += (float)$row['amount'];
}
$quarterRows = array_values($quarterByPerson);
usort($quarterRows, static fn($a,$b) => strcmp($a['name'],$b['name']));
$myQuarter = 0.0;
foreach ($quarterRows as $row) if ((int)$row['owner_employee_id'] === (int)$actor['employee_id']) $myQuarter = (float)$row['amount'];
$page_title = '管理层激励考核';
include __DIR__ . '/../includes/header.php';
?>
<div class="governance-page">
  <section class="governance-hero"><div><span class="governance-kicker">CO-CREATION / 治理与共创</span><h1>管理层激励考核</h1><p>承诺、监督和改进都有记录。事项先提交、再由监委会核验；奖惩经确认后留在独立台账，不自动改动项目报酬。</p></div><span class="governance-role"><?php echo $member['governance_role'] === 'chair' ? '轮值董事长' : '监委会成员'; ?></span></section>
  <nav class="governance-tabs" aria-label="管理层栏目"><a href="<?php echo BASE_URL; ?>/project/governance_ideas.php">三天脑洞</a><a class="active" aria-current="page" href="<?php echo BASE_URL; ?>/project/governance.php">事项台账</a><a href="<?php echo BASE_URL; ?>/project/rules.php?domain=governance">规则中心</a></nav>
  <?php if ($error): ?><div class="alert alert-danger mt-3"><?php echo e($error); ?></div><?php endif; ?>
  <?php if (isset($_GET['created'])): ?><div class="alert alert-success mt-3">事项已提交，等待监委会核验。</div><?php endif; ?>
  <?php if (isset($_GET['reviewed'])): ?><div class="alert alert-success mt-3">核验结果已记入台账。</div><?php endif; ?>
  <div class="governance-stats"><div><small>我的身份</small><strong><?php echo $member['governance_role'] === 'chair' ? '轮值董事长' : '监督评议'; ?></strong></div><div><small>待核验事项</small><strong><?php echo $pendingCount; ?></strong></div><div><small>本季度本人奖惩变动</small><strong>¥<?php echo money($myQuarter); ?></strong></div></div>
  <div class="governance-grid">
    <section class="governance-card" id="new-record"><h2>快速登记</h2><p class="governance-hint">选类型、写内容即可提交。日期默认今天；截止时间和举证可以按需补充。跟进原事项时填父记录编号，不覆盖旧记录。</p>
      <form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="create">
        <div class="governance-quick-kind"><label><input type="radio" name="record_kind" value="<?php echo e($member['governance_role']); ?>" checked> <?php echo e(pg_kind_label($member['governance_role'])); ?></label><label><input type="radio" name="record_kind" value="contribution"> 建议 / Bug / 主动做事</label></div>
        <div class="form-group"><label>具体内容</label><textarea class="form-control" name="description" rows="4" maxlength="12000" required placeholder="写下承诺、监督结果，或发现的问题与改进建议"></textarea></div>
        <button class="btn btn-success" type="submit">提交待核验</button>
        <details class="governance-advanced"><summary>补充日期、当事人、截止节点或举证材料（可选）</summary>
          <div class="form-row"><div class="form-group col-md-6"><label>记录日期</label><input class="form-control" type="date" name="record_date" value="<?php echo date('Y-m-d'); ?>" required></div><div class="form-group col-md-6"><label>事项类别 / 标题</label><input class="form-control" name="category" maxlength="120" placeholder="例如：业务改进、日常监督、平台 Bug"></div></div>
          <div class="form-group" id="governanceOwnerField"><label>建议 / Bug 的当事人</label><select class="form-control" name="owner_employee_id"><option value="<?php echo (int)$actor['employee_id']; ?>">本人 · <?php echo e($member['name']); ?></option><?php foreach ($employees as $emp): if ((int)$emp['id'] === (int)$actor['employee_id']) continue; ?><option value="<?php echo (int)$emp['id']; ?>"><?php echo e($emp['name'] . ' · ' . $emp['department']); ?></option><?php endforeach; ?></select><small class="form-text text-muted">管理层事项自动记在本人名下，只有建议 / Bug 会使用此处的当事人。</small></div>
          <div class="form-row"><div class="form-group col-md-6"><label>承诺节点 / 发生时间</label><input class="form-control" name="commitment_note" maxlength="160" placeholder="例如：会议当日、每周复盘"></div><div class="form-group col-md-6"><label>截止日期</label><input class="form-control" type="date" name="due_date"></div></div>
          <div class="form-row"><div class="form-group col-md-6"><label>其他时效说明</label><input class="form-control" name="due_note" maxlength="160" placeholder="例如：长期实施"></div><div class="form-group col-md-6"><label>父记录编号（跟进原事项时填写）</label><input class="form-control" type="number" min="1" name="parent_record_id" placeholder="可不填"></div></div>
          <div class="form-group"><label>举证 / 执行结果</label><textarea class="form-control" name="evidence_text" rows="2" maxlength="4000" placeholder="可写结果或内部证据链接；不要填写账号密码"></textarea></div>
          <div class="form-group"><label>举证文件</label><input class="form-control-file" type="file" name="evidence_file" accept=".png,.jpg,.jpeg,.webp,.pdf"><small class="form-text text-muted">图片或 PDF，最多 8 MB；文件保存在网站目录外，只有本栏目成员能查看。</small></div>
        </details>
      </form>
    </section>
    <aside class="governance-card"><h2>成员与机制</h2><p class="governance-hint">以下名单来自原表“人员配置”；栏目权限按员工 ID 绑定，不按页面输入的姓名判断。</p><div class="governance-member-list"><?php foreach ($members as $person): ?><div><span><?php echo e($person['name']); ?><small><?php echo $person['governance_role'] === 'chair' ? '轮值董事长' : '监委会'; ?></small></span><?php if (!$person['user_id']): ?><em>尚未开通登录账号</em><?php endif; ?></div><?php endforeach; ?></div><div class="governance-note"><strong>结算边界</strong><p>原表记载董事长季度奖金池 ¥10,000、监委会季度奖金池 ¥3,000，但提交周期及历史奖额存在不同口径。本页只汇总核验后的实际变动；奖金池余额、罚款流向和发放须再核对，不在这里自动推算或扣发。</p></div></aside>
  </div>
  <section class="governance-card" id="records"><div class="governance-heading"><div><h2>考核与共创台账</h2><p class="governance-hint">最多显示最近 150 条；已核验记录不可覆盖，需要更正时新建跟进记录。</p></div><form method="get" class="governance-filters"><input class="form-control" type="month" name="month" value="<?php echo e($month); ?>" aria-label="筛选月份"><select class="form-control" name="kind" aria-label="筛选类型"><option value="">全部类型</option><?php foreach (['chair','committee','contribution'] as $kind): ?><option value="<?php echo $kind; ?>" <?php echo $kindFilter === $kind ? 'selected' : ''; ?>><?php echo e(pg_kind_label($kind)); ?></option><?php endforeach; ?></select><button class="btn btn-outline-success" type="submit">筛选</button></form></div>
    <?php if (!$records): ?><div class="governance-empty">还没有符合条件的记录。可以从上方登记第一条。</div><?php endif; ?>
    <div class="governance-records"><?php foreach ($records as $row): ?><article class="governance-record" id="record-<?php echo (int)$row['id']; ?>"><div class="governance-record-top"><span class="governance-tag"><?php echo e(pg_kind_label($row['record_kind'])); ?></span><span class="governance-status <?php echo e($row['review_state']); ?>"><?php echo e(pg_review_label($row['review_state'])); ?></span><small>#<?php echo (int)$row['id']; ?> · <?php echo e($row['record_date']); ?> · <?php echo e($row['owner_name']); ?></small></div><h3><?php echo e($row['category'] ?: '未命名事项'); ?></h3><p class="governance-description"><?php echo nl2br(e($row['description'])); ?></p><div class="governance-meta"><?php if ($row['commitment_note']): ?><span>承诺 / 发生：<?php echo e($row['commitment_note']); ?></span><?php endif; ?><?php if ($row['due_date'] || $row['due_note']): ?><span>截止 / 时效：<?php echo e($row['due_date'] ?: $row['due_note']); ?></span><?php endif; ?><?php if ($row['parent_record_id']): ?><a href="#record-<?php echo (int)$row['parent_record_id']; ?>">跟进 #<?php echo (int)$row['parent_record_id']; ?></a><?php endif; ?><button class="governance-followup" type="button" data-governance-followup="<?php echo (int)$row['id']; ?>">＋ 跟进此事</button></div>
      <?php if ($row['evidence_text']): ?><div class="governance-evidence"><strong>举证与结果</strong><p><?php echo nl2br(e($row['evidence_text'])); ?></p></div><?php endif; ?>
      <?php foreach ($evidenceByRecord[(int)$row['id']] ?? [] as $proof): ?><a class="governance-proof" href="<?php echo BASE_URL; ?>/project/governance_evidence.php?id=<?php echo (int)$proof['id']; ?>" target="_blank" rel="noopener noreferrer"><i class="fas fa-paperclip"></i> <?php echo e($proof['original_name']); ?></a><?php endforeach; ?>
      <?php if ($row['review_state'] !== 'pending'): ?><div class="governance-result">核验：<?php echo e($row['outcome_status']); ?> · <?php echo e($row['reviewer_name'] ?: '—'); ?><?php if ($row['bonus_delta'] !== null): ?> · 奖惩变动 <strong class="<?php echo (float)$row['bonus_delta'] < 0 ? 'negative' : ''; ?>"><?php echo (float)$row['bonus_delta'] >= 0 ? '+' : '−'; ?>¥<?php echo money(abs((float)$row['bonus_delta'])); ?></strong><?php endif; ?><?php if ($row['flow_note']): ?> · <?php echo e($row['flow_note']); ?><?php endif; ?><?php if ($row['review_note']): ?><div><?php echo e($row['review_note']); ?></div><?php endif; ?></div><?php endif; ?>
      <?php if (pg_can_review($member, $row)): ?><details class="governance-review"><summary>监委会核验此事项</summary><form method="post"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="review"><input type="hidden" name="record_id" value="<?php echo (int)$row['id']; ?>"><div class="form-row"><div class="form-group col-md-4"><label>核验结论</label><select class="form-control" name="decision"><option value="approved">确认记录</option><option value="rejected">退回补充</option></select></div><div class="form-group col-md-4"><label>事项状态</label><select class="form-control" name="outcome_status"><?php foreach (['有效提出','进行中','完整闭环','推进中','及时干预','严重逾期','无效方案','有效监督','待补证据'] as $status): ?><option value="<?php echo e($status); ?>"><?php echo e($status); ?></option><?php endforeach; ?></select></div><div class="form-group col-md-4"><label>奖惩变动（可留空）</label><input class="form-control" name="bonus_delta" type="number" step="0.01" placeholder="奖励填正数，处罚填负数"></div></div><div class="form-row"><div class="form-group col-md-6"><label>奖金池 / 流向</label><input class="form-control" name="flow_note" maxlength="255" placeholder="例如：董事长奖金池 / 全员福利池"></div><div class="form-group col-md-6"><label>核验说明</label><input class="form-control" name="review_note" maxlength="500" placeholder="退回时必填；也可写明判定依据"></div></div><button class="btn btn-sm btn-success" type="submit">确认核验</button></form></details><?php endif; ?></article><?php endforeach; ?></div>
  </section>
  <section class="governance-card" id="quarter"><h2>本季度奖惩变动</h2><p class="governance-hint"><?php echo e(substr($quarterStart, 0, 7)); ?> 起，汇总已核验金额与未豁免的自动扣减；不是可发放余额，也不自动进入项目报酬。奖金池期初与余额见<a href="<?php echo BASE_URL; ?>/project/governance_ideas.php#pool">三天脑洞</a>。</p><?php if (!$quarterRows): ?><div class="governance-empty">本季度暂无已核验的奖惩变动。</div><?php else: ?><div class="table-responsive"><table class="table table-sm governance-summary"><thead><tr><th>当事人</th><th>记录数</th><th>已记账变动</th></tr></thead><tbody><?php foreach ($quarterRows as $row): ?><tr><td><?php echo e($row['name']); ?></td><td><?php echo (int)$row['record_count']; ?></td><td class="<?php echo (float)$row['amount'] < 0 ? 'negative' : ''; ?>"><?php echo (float)$row['amount'] >= 0 ? '+' : '−'; ?>¥<?php echo money(abs((float)$row['amount'])); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
</div>
<script>
(function () {
  var owner = document.getElementById('governanceOwnerField');
  var choices = document.querySelectorAll('input[name="record_kind"]');
  if (!owner || !choices.length) return;
  function update() {
    var selected = document.querySelector('input[name="record_kind"]:checked');
    owner.hidden = !selected || selected.value !== 'contribution';
  }
  choices.forEach(function (input) { input.addEventListener('change', update); });
  update();
  document.querySelectorAll('[data-governance-followup]').forEach(function (button) {
    button.addEventListener('click', function () {
      var panel = document.getElementById('new-record');
      var advanced = panel.querySelector('.governance-advanced');
      advanced.open = true;
      panel.querySelector('input[name="parent_record_id"]').value = button.dataset.governanceFollowup;
      panel.scrollIntoView({behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start'});
      panel.querySelector('textarea[name="description"]').focus({preventScroll: true});
    });
  });
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
