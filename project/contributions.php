<?php
require_once __DIR__ . '/../includes/ProjectGovernance.php';
// 建议 / Bug / 主动做事奖励台账：对应原表“建议、bug、做事记录”。财务与监委会录入、补凭证、登记发放；
// 奖金是独立台账，不自动进入项目报酬，也不计入董事长目标额度。
[$actor, $editorName, $isFinance] = pg_require_contribution_editor();
$selfEmployeeId = (int)($actor['employee_id'] ?? 0);
$error = '';
$conclusions = ['pending' => '待评判', 'approved' => '已完成', 'rejected' => '未采纳'];

/** 表单 → 记录字段；校验失败抛出 RuntimeException。 */
$readForm = function ($input) use ($selfEmployeeId, $conclusions) {
    $owner = (int)($input['owner_employee_id'] ?? 0);
    $check = db()->prepare('SELECT 1 FROM employees WHERE id=?');
    $check->execute([$owner]);
    if ($owner < 1 || !$check->fetchColumn()) throw new RuntimeException('请选择当事人');
    $title = mb_substr(trim((string)($input['category'] ?? '')), 0, 120);
    $description = trim((string)($input['description'] ?? ''));
    if ($title === '' && $description === '') throw new RuntimeException('请填写建议 / Bug / 做事内容');
    if ($title === '') $title = mb_substr(preg_replace('/\s+/u', ' ', $description), 0, 30);
    if ($description === '') $description = $title;
    if (mb_strlen($description) > 12000) throw new RuntimeException('具体内容不超过 12000 字');
    $date = pg_validate_date($input['record_date'] ?? '', true);
    $due = pg_validate_date($input['due_date'] ?? '');
    $state = (string)($input['conclusion'] ?? 'pending');
    if (!isset($conclusions[$state])) throw new RuntimeException('请选择完成状态');
    $reviewer = (int)($input['reviewer_employee_id'] ?? 0);
    if ($reviewer > 0) { $check->execute([$reviewer]); if (!$check->fetchColumn()) throw new RuntimeException('评判人不存在'); }
    if ($reviewer > 0 && $reviewer === $owner) throw new RuntimeException('评判人不能是当事人本人');
    if ($state !== 'pending' && $selfEmployeeId > 0 && $owner === $selfEmployeeId) throw new RuntimeException('本人的事项须由其他监委或财务给出结论');
    if ($state !== 'pending' && $reviewer < 1) throw new RuntimeException('给出结论时请选择评判人');
    $amountText = str_replace([',', '¥', '￥', ' '], '', trim((string)($input['bonus_delta'] ?? '')));
    $amount = null;
    if ($amountText !== '') {
        if (!preg_match('/^-?\d{1,6}(?:\.\d{1,2})?$/', $amountText)) throw new RuntimeException('奖金格式不正确');
        $amount = round((float)$amountText, 2);
    }
    if ($amount !== null && $state !== 'approved') throw new RuntimeException('只有“已完成”的事项才能填写奖金');
    $paid = (string)($input['reward_status'] ?? '') === 'paid';
    $paidOn = $paid ? pg_validate_date($input['reward_paid_on'] ?? '') : null;
    if ($paid && ($amount === null || $amount <= 0)) throw new RuntimeException('登记“奖励已发”前请先填写奖金');
    return [
        'owner_employee_id' => $owner, 'record_date' => $date, 'category' => $title, 'description' => $description, 'due_date' => $due,
        'review_state' => $state, 'outcome_status' => $conclusions[$state], 'reviewer_employee_id' => $reviewer ?: null, 'bonus_delta' => $amount,
        'reward_status' => $amount !== null && $amount > 0 ? ($paid ? 'paid' : 'unpaid') : null, 'reward_paid_on' => $paidOn,
        'flow_note' => mb_substr(trim((string)($input['flow_note'] ?? '')), 0, 255),
    ];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    $stored = [];
    try {
        $action = (string)($_POST['action'] ?? '');
        $nested = db()->inTransaction();
        if ($nested) db()->exec('SAVEPOINT contribution_save'); else db()->beginTransaction();
        if ($action === 'create') {
            $data = $readForm($_POST);
            [$employeeId, $adminId] = pg_actor_columns($actor);
            db()->prepare("INSERT INTO project_governance_records (record_kind,owner_employee_id,record_date,category,description,due_date,outcome_status,reviewer_employee_id,bonus_delta,flow_note,reward_status,reward_paid_on,review_state,review_note,created_by_employee_id,created_by_admin_id,reviewed_at) VALUES ('contribution',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$data['owner_employee_id'], $data['record_date'], $data['category'], $data['description'], $data['due_date'], $data['outcome_status'], $data['reviewer_employee_id'], $data['bonus_delta'], $data['flow_note'], $data['reward_status'], $data['reward_paid_on'], $data['review_state'], '由 ' . $editorName . ' 录入', $employeeId, $adminId, $data['review_state'] === 'pending' ? null : date('Y-m-d H:i:s')]);
            $id = (int)db()->lastInsertId();
            foreach (pg_uploaded_files($_FILES['evidence_files'] ?? []) as $file) if ($path = pg_store_evidence($id, $actor, $file)) $stored[] = $path;
            ps_audit('governance_record', $id, 'contribution_create', $actor, $data + ['files' => count($stored)]);
            $notice = 'created';
        } elseif ($action === 'update' || $action === 'mark_paid') {
            $id = (int)($_POST['record_id'] ?? 0);
            $q = db()->prepare("SELECT * FROM project_governance_records WHERE id=? AND record_kind='contribution' FOR UPDATE");
            $q->execute([$id]);
            $before = $q->fetch();
            if (!$before) throw new RuntimeException('记录不存在');
            if ($action === 'mark_paid') {
                if ($before['review_state'] !== 'approved' || (float)$before['bonus_delta'] <= 0) throw new RuntimeException('只有已完成且有奖金的事项可以登记发放');
                db()->prepare("UPDATE project_governance_records SET reward_status='paid',reward_paid_on=CURDATE() WHERE id=?")->execute([$id]);
                ps_audit('governance_record', $id, 'contribution_paid', $actor, ['bonus_delta' => $before['bonus_delta']]);
                $notice = 'paid';
            } else {
                $data = $readForm($_POST);
                $reviewedAt = $data['review_state'] === 'pending' ? null : ($before['review_state'] === $data['review_state'] && $before['reviewed_at'] ? $before['reviewed_at'] : date('Y-m-d H:i:s'));
                db()->prepare('UPDATE project_governance_records SET owner_employee_id=?,record_date=?,category=?,description=?,due_date=?,outcome_status=?,reviewer_employee_id=?,bonus_delta=?,flow_note=?,reward_status=?,reward_paid_on=?,review_state=?,reviewed_at=? WHERE id=?')
                    ->execute([$data['owner_employee_id'], $data['record_date'], $data['category'], $data['description'], $data['due_date'], $data['outcome_status'], $data['reviewer_employee_id'], $data['bonus_delta'], $data['flow_note'], $data['reward_status'], $data['reward_paid_on'], $data['review_state'], $reviewedAt, $id]);
                foreach (pg_uploaded_files($_FILES['evidence_files'] ?? []) as $file) if ($path = pg_store_evidence($id, $actor, $file)) $stored[] = $path;
                $changes = [];
                foreach ($data as $key => $value) if ((string)$before[$key] !== (string)$value) $changes[$key] = ['from' => $before[$key], 'to' => $value];
                ps_audit('governance_record', $id, 'contribution_update', $actor, ['changes' => $changes, 'files' => count($stored)]);
                $notice = 'updated';
            }
        } else {
            throw new RuntimeException('操作无效');
        }
        if ($nested) db()->exec('RELEASE SAVEPOINT contribution_save'); else db()->commit();
        header('Location: ' . BASE_URL . '/project/contributions.php?' . $notice . '=' . $id . '#c-' . $id);
        if (PHP_SAPI === 'cli') { $savedId = $id; return; } // 冒烟测试在同一事务内连续调用
        exit;
    } catch (Throwable $e) {
        if (!empty($nested)) db()->exec('ROLLBACK TO SAVEPOINT contribution_save'); elseif (db()->inTransaction()) db()->rollBack();
        foreach ($stored as $path) @unlink($path);
        $error = $e instanceof RuntimeException ? $e->getMessage() : '保存失败：' . $e->getMessage();
    }
}

$month = trim((string)($_GET['month'] ?? ''));
if ($month !== '' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = '';
$statusFilter = (string)($_GET['status'] ?? '');
$keyword = trim((string)($_GET['q'] ?? ''));
$where = ["r.record_kind='contribution'"]; $params = [];
if ($month !== '') { $where[] = 'r.record_date>=? AND r.record_date<?'; $params[] = $month . '-01'; $params[] = (new DateTimeImmutable($month . '-01'))->modify('+1 month')->format('Y-m-d'); }
$statusSql = ['pending' => "r.review_state='pending'", 'unpaid' => "r.review_state='approved' AND r.reward_status='unpaid'", 'paid' => "r.reward_status='paid'", 'rejected' => "r.review_state='rejected'"];
if (isset($statusSql[$statusFilter])) $where[] = $statusSql[$statusFilter]; else $statusFilter = '';
if ($keyword !== '') { $where[] = '(o.name LIKE ? OR r.category LIKE ? OR r.description LIKE ?)'; $like = '%' . $keyword . '%'; array_push($params, $like, $like, $like); }
$list = db()->prepare("SELECT r.*,o.name AS owner_name,o.department AS owner_department,v.name AS reviewer_name,COALESCE(c.name,CONCAT('财务 ',a.username),'原表补录') AS creator_name FROM project_governance_records r JOIN employees o ON o.id=r.owner_employee_id LEFT JOIN employees v ON v.id=r.reviewer_employee_id LEFT JOIN employees c ON c.id=r.created_by_employee_id LEFT JOIN admins a ON a.id=r.created_by_admin_id WHERE " . implode(' AND ', $where) . ' ORDER BY r.record_date DESC,r.id DESC LIMIT 300');
$list->execute($params);
$records = $list->fetchAll();
$evidenceByRecord = [];
if ($records) {
    $proofs = db()->query('SELECT id,record_id,original_name,mime_type FROM project_governance_evidence WHERE record_id IN (' . implode(',', array_map('intval', array_column($records, 'id'))) . ') ORDER BY id')->fetchAll();
    foreach ($proofs as $proof) $evidenceByRecord[(int)$proof['record_id']][] = $proof;
}
$stats = db()->query("SELECT COUNT(*) AS total,SUM(review_state='pending') AS pending,COALESCE(SUM(CASE WHEN review_state='approved' AND reward_status='unpaid' THEN bonus_delta END),0) AS unpaid,COALESCE(SUM(CASE WHEN reward_status='paid' THEN bonus_delta END),0) AS paid FROM project_governance_records WHERE record_kind='contribution'")->fetch();
$employees = db()->query('SELECT id,name,department FROM employees ORDER BY name,id')->fetchAll();
$committee = db()->query("SELECT e.id,e.name FROM project_governance_members m JOIN employees e ON e.id=m.employee_id WHERE m.governance_role='committee' AND m.is_active=1 ORDER BY e.id")->fetchAll();
$committeeIds = array_map('intval', array_column($committee, 'id'));

/** 当事人 / 评判人下拉：监委会排在评判人最前。 */
$personOptions = function ($selected, $withBlank, $committeeFirst = false) use ($employees, $committee, $committeeIds) {
    $html = $withBlank ? '<option value="">—</option>' : '<option value="">请选择</option>';
    if ($committeeFirst) {
        $html .= '<optgroup label="监委会">';
        foreach ($committee as $person) $html .= '<option value="' . (int)$person['id'] . '"' . ((int)$selected === (int)$person['id'] ? ' selected' : '') . '>' . e($person['name']) . '</option>';
        $html .= '</optgroup><optgroup label="其他人员">';
    }
    foreach ($employees as $emp) {
        if ($committeeFirst && in_array((int)$emp['id'], $committeeIds, true)) continue;
        $html .= '<option value="' . (int)$emp['id'] . '"' . ((int)$selected === (int)$emp['id'] ? ' selected' : '') . '>' . e($emp['name'] . ' · ' . $emp['department']) . '</option>';
    }
    return $html . ($committeeFirst ? '</optgroup>' : '');
};
$form = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create' ? $_POST : [];

/** 录入 / 编辑共用字段。 */
$fields = function ($row, $prefix) use ($personOptions, $selfEmployeeId, $conclusions) {
    $state = $row['review_state'] ?? 'pending';
    $reviewer = $row['reviewer_employee_id'] ?? ($selfEmployeeId ?: '');
    ob_start(); ?>
    <div class="form-row">
      <div class="form-group col-md-4"><label>当事人</label><select class="form-control" name="owner_employee_id" required><?php echo $personOptions($row['owner_employee_id'] ?? '', false); ?></select></div>
      <div class="form-group col-md-4"><label>记录日期</label><input class="form-control" type="date" name="record_date" value="<?php echo e($row['record_date'] ?? date('Y-m-d')); ?>" required></div>
      <div class="form-group col-md-4"><label>截止时间</label><input class="form-control" type="date" name="due_date" value="<?php echo e($row['due_date'] ?? ''); ?>"></div>
    </div>
    <div class="form-group"><label>建议 / Bug / 主动做事</label><input class="form-control" name="category" maxlength="120" list="contributionKinds" value="<?php echo e($row['category'] ?? ''); ?>" placeholder="例如：平台发现bug、退订赤兔名品插件"></div>
    <div class="form-group"><label>具体内容描述</label><textarea class="form-control" name="description" rows="2" maxlength="12000" placeholder="可不填，默认同上"><?php echo e(isset($row['description']) && $row['description'] !== ($row['category'] ?? '') ? $row['description'] : ''); ?></textarea></div>
    <div class="form-row">
      <div class="form-group col-md-4"><label>（推动解决）完成状态</label><select class="form-control" name="conclusion"><?php foreach ($conclusions as $key => $label): ?><option value="<?php echo $key; ?>"<?php echo $state === $key ? ' selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></div>
      <div class="form-group col-md-4"><label>奖金变动（元）</label><input class="form-control" name="bonus_delta" inputmode="decimal" value="<?php echo isset($row['bonus_delta']) && $row['bonus_delta'] !== null ? e(rtrim(rtrim((string)$row['bonus_delta'], '0'), '.')) : ''; ?>" placeholder="已完成时填写，如 300"></div>
      <div class="form-group col-md-4"><label>评判人</label><select class="form-control" name="reviewer_employee_id"><?php echo $personOptions($reviewer, true, true); ?></select></div>
    </div>
    <div class="form-row">
      <div class="form-group col-md-4"><label>奖励发放</label><select class="form-control" name="reward_status"><option value="unpaid">未发</option><option value="paid"<?php echo ($row['reward_status'] ?? '') === 'paid' ? ' selected' : ''; ?>>奖励已发</option></select></div>
      <div class="form-group col-md-4"><label>发放日期</label><input class="form-control" type="date" name="reward_paid_on" value="<?php echo e($row['reward_paid_on'] ?? ''); ?>"></div>
      <div class="form-group col-md-4"><label>备注</label><input class="form-control" name="flow_note" maxlength="255" value="<?php echo e($row['flow_note'] ?? ''); ?>" placeholder="如：随 9 月工资发放"></div>
    </div>
    <div class="contribution-drop" data-drop>
      <input class="contribution-file" type="file" id="<?php echo e($prefix); ?>Files" name="evidence_files[]" accept=".png,.jpg,.jpeg,.webp,.pdf" multiple>
      <label for="<?php echo e($prefix); ?>Files"><i class="fas fa-paperclip"></i> 举证材料：点击选择、拖入文件，或在本区域 <kbd>Ctrl</kbd>+<kbd>V</kbd> 粘贴截图</label>
      <div class="contribution-picked" data-picked></div>
      <small>图片或 PDF，可多个，每个不超过 8 MB；保存在网站目录外，只有财务与管理层可查看。</small>
    </div>
    <?php return ob_get_clean();
};

$page_title = '建议 / Bug 奖励';
include __DIR__ . '/../includes/header.php';
?>
<div class="governance-page">
  <section class="governance-hero"><div><span class="governance-kicker">SUGGESTIONS / BUGS</span><h1>建议 / Bug 奖励</h1><p>对应原表“建议、bug、做事记录”。财务与监委会在这里登记、补举证、给结论并登记发放；奖金为独立台账，不自动进入项目报酬。</p></div><span class="governance-role"><?php echo e($editorName); ?></span></section>
  <?php if ($error): ?><div class="alert alert-danger mt-3"><?php echo e($error); ?></div><?php endif; ?>
  <?php foreach (['created' => '已登记', 'updated' => '已保存修改', 'paid' => '已登记奖励发放'] as $key => $text): if (isset($_GET[$key])): ?><div class="alert alert-success mt-3"><?php echo $text; ?>（#<?php echo (int)$_GET[$key]; ?>）。</div><?php endif; endforeach; ?>
  <div class="governance-stats"><div><small>全部记录</small><strong><?php echo (int)$stats['total']; ?></strong></div><div><small>待评判</small><strong><?php echo (int)$stats['pending']; ?></strong></div><div><small>已评未发</small><strong>¥<?php echo money($stats['unpaid']); ?></strong></div><div><small>奖励已发</small><strong>¥<?php echo money($stats['paid']); ?></strong></div></div>

  <section class="governance-card" id="new-record">
    <h2>登记一条</h2>
    <p class="governance-hint">当事人和内容必填，其余可稍后在列表里补。给出“已完成 / 未采纳”结论时需选评判人；本人的事项须由他人给结论。</p>
    <form method="post" enctype="multipart/form-data" data-contribution-form><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="create">
      <?php echo $fields($form ? ['owner_employee_id' => $form['owner_employee_id'] ?? '', 'record_date' => $form['record_date'] ?? date('Y-m-d'), 'category' => $form['category'] ?? '', 'description' => $form['description'] ?? '', 'due_date' => $form['due_date'] ?? '', 'review_state' => $form['conclusion'] ?? 'pending', 'bonus_delta' => $form['bonus_delta'] ?? null, 'reviewer_employee_id' => $form['reviewer_employee_id'] ?? '', 'reward_status' => $form['reward_status'] ?? '', 'reward_paid_on' => $form['reward_paid_on'] ?? '', 'flow_note' => $form['flow_note'] ?? ''] : [], 'new'); ?>
      <button class="btn btn-success mt-2" type="submit"><i class="fas fa-check mr-1"></i>登记</button>
    </form>
  </section>

  <section class="governance-card" id="records">
    <div class="governance-heading"><div><h2>记录台账</h2><p class="governance-hint">点“编辑 / 补凭证”可修改任一字段或追加举证；每次修改都留审计。</p></div>
      <form method="get" class="governance-filters"><input class="form-control" type="month" name="month" value="<?php echo e($month); ?>" aria-label="月份"><select class="form-control" name="status" aria-label="状态"><option value="">全部状态</option><?php foreach (['pending' => '待评判', 'unpaid' => '已评未发', 'paid' => '奖励已发', 'rejected' => '未采纳'] as $key => $label): ?><option value="<?php echo $key; ?>"<?php echo $statusFilter === $key ? ' selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select><input class="form-control" name="q" value="<?php echo e($keyword); ?>" placeholder="姓名 / 内容" aria-label="搜索"><button class="btn btn-outline-secondary" type="submit">筛选</button></form></div>
    <?php if (!$records): ?><div class="governance-empty">还没有符合条件的记录。</div><?php else: ?>
    <div class="table-responsive"><table class="table contribution-table">
      <thead><tr><th>#</th><th>记录日期</th><th>当事人</th><th>建议 / Bug / 主动做事</th><th>具体内容描述</th><th>截止时间</th><th>完成状态</th><th class="text-right">奖金变动</th><th>评判人</th><th>举证材料</th><th>备注</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($records as $row): $proofs = $evidenceByRecord[(int)$row['id']] ?? []; ?>
        <tr id="c-<?php echo (int)$row['id']; ?>">
          <td class="text-muted"><?php echo (int)$row['id']; ?></td>
          <td class="text-nowrap"><?php echo e($row['record_date']); ?></td>
          <td class="text-nowrap"><strong><?php echo e($row['owner_name']); ?></strong><small class="d-block text-muted"><?php echo e($row['owner_department']); ?></small></td>
          <td><?php echo e($row['category']); ?></td>
          <td><?php echo $row['description'] !== $row['category'] ? nl2br(e($row['description'])) : ''; ?></td>
          <td class="text-nowrap"><?php echo e($row['due_date'] ?? ''); ?></td>
          <td><span class="governance-status <?php echo e($row['review_state']); ?>"><?php echo e($row['outcome_status']); ?></span></td>
          <td class="text-right text-nowrap"><?php echo $row['bonus_delta'] !== null ? '¥' . money($row['bonus_delta']) : ''; ?></td>
          <td class="text-nowrap"><?php echo e($row['reviewer_name'] ?? ''); ?></td>
          <td><div class="contribution-proofs"><?php foreach ($proofs as $proof): $url = BASE_URL . '/project/governance_evidence.php?id=' . (int)$proof['id']; ?><a href="<?php echo e($url); ?>" target="_blank" rel="noopener noreferrer" title="<?php echo e($proof['original_name']); ?>"><?php if (strpos($proof['mime_type'], 'image/') === 0): ?><img src="<?php echo e($url); ?>" alt="<?php echo e($proof['original_name']); ?>" loading="lazy"><?php else: ?><i class="fas fa-file-pdf"></i><?php endif; ?></a><?php endforeach; ?></div></td>
          <td><?php if ($row['reward_status'] === 'paid'): ?><span class="contribution-paid">奖励已发<?php echo $row['reward_paid_on'] ? ' ' . e($row['reward_paid_on']) : ''; ?></span><?php elseif ($row['reward_status'] === 'unpaid'): ?><span class="contribution-unpaid">待发放</span><?php endif; ?><?php if ($row['flow_note']): ?><small class="d-block text-muted"><?php echo e($row['flow_note']); ?></small><?php endif; ?></td>
          <td class="text-nowrap">
            <?php if ($row['review_state'] === 'approved' && $row['reward_status'] === 'unpaid'): ?><form method="post" class="d-inline" onsubmit="return confirm('登记 <?php echo e($row['owner_name']); ?> 的奖励 ¥<?php echo money($row['bonus_delta']); ?> 已发放？');"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="mark_paid"><input type="hidden" name="record_id" value="<?php echo (int)$row['id']; ?>"><button class="btn btn-sm btn-outline-success" type="submit">登记已发</button></form><?php endif; ?>
            <button class="btn btn-sm btn-outline-primary" type="button" data-edit-toggle="<?php echo (int)$row['id']; ?>">编辑 / 补凭证</button>
          </td>
        </tr>
        <tr class="contribution-edit" id="edit-<?php echo (int)$row['id']; ?>" hidden><td colspan="12">
          <form method="post" enctype="multipart/form-data" data-contribution-form><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="update"><input type="hidden" name="record_id" value="<?php echo (int)$row['id']; ?>">
            <?php echo $fields($row, 'edit' . (int)$row['id']); ?>
            <div class="mt-2"><button class="btn btn-primary btn-sm" type="submit">保存</button> <small class="text-muted ml-2">录入：<?php echo e($row['creator_name']); ?><?php echo $row['review_note'] ? ' · ' . e($row['review_note']) : ''; ?></small></div>
          </form>
        </td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </section>
  <datalist id="contributionKinds"><option value="平台发现bug"><option value="优化建议"><option value="节省成本"><option value="主动做事"><option value="市场调研"></datalist>
</div>
<script>
(function () {
  document.querySelectorAll('[data-edit-toggle]').forEach(function (button) {
    button.addEventListener('click', function () {
      var row = document.getElementById('edit-' + button.dataset.editToggle);
      row.hidden = !row.hidden;
      if (!row.hidden) row.querySelector('select, input').focus();
    });
  });
  // 举证：多选、拖入、粘贴截图都汇入同一个文件框
  document.querySelectorAll('[data-drop]').forEach(function (zone) {
    var input = zone.querySelector('input[type=file]');
    var picked = zone.querySelector('[data-picked]');
    var files = [];
    function sync() {
      if (typeof DataTransfer !== 'undefined') {
        var dt = new DataTransfer();
        files.forEach(function (f) { dt.items.add(f); });
        input.files = dt.files;
      }
      picked.textContent = files.length ? '已选 ' + files.length + ' 个：' + files.map(function (f) { return f.name; }).join('、') : '';
    }
    function add(list) {
      Array.prototype.forEach.call(list, function (f) {
        if (!/^image\/|application\/pdf/.test(f.type)) return;
        if (!f.name || f.name === 'image.png') f = new File([f], '截图-' + new Date().toISOString().slice(0, 19).replace(/[-:T]/g, '') + '-' + (files.length + 1) + '.png', {type: f.type});
        files.push(f);
      });
      sync();
    }
    input.addEventListener('change', function () { files = []; add(input.files); });
    zone.addEventListener('dragover', function (e) { e.preventDefault(); zone.classList.add('over'); });
    zone.addEventListener('dragleave', function () { zone.classList.remove('over'); });
    zone.addEventListener('drop', function (e) { e.preventDefault(); zone.classList.remove('over'); add(e.dataTransfer.files); });
    zone.closest('form').addEventListener('paste', function (e) {
      var items = (e.clipboardData && e.clipboardData.files) || [];
      if (items.length) { e.preventDefault(); add(items); }
    });
  });
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
