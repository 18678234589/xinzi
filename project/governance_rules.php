<?php
require_once __DIR__ . '/../includes/ProjectGovernance.php';
[$actor, $member] = pg_require_member();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    try {
        if ($member['governance_role'] !== 'committee') throw new RuntimeException('仅监委会成员可维护本栏目的规则');
        $action = (string)($_POST['action'] ?? '');
        if (!in_array($action, ['save_governance_rule', 'create_governance_rule'], true)) throw new RuntimeException('操作无效');
        $id = (int)($_POST['rule_id'] ?? 0);
        $version = (int)($_POST['version'] ?? 0);
        $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 160);
        $cadence = mb_substr(trim((string)($_POST['cadence_note'] ?? '')), 0, 120);
        $text = trim((string)($_POST['rule_text'] ?? ''));
        $state = (string)($_POST['rule_state'] ?? 'draft');
        $scope = (string)($_POST['role_scope'] ?? 'all');
        if ($title === '' || mb_strlen($text) < 5 || mb_strlen($text) > 5000) throw new RuntimeException('请填写规则名称及 5–5000 字说明');
        if (!in_array($state, ['draft', 'confirmed'], true)) throw new RuntimeException('规则状态无效');
        if (!in_array($scope, ['chair', 'committee', 'all'], true)) throw new RuntimeException('适用对象无效');
        $amount = static function ($input, $label) {
            $input = trim((string)$input);
            if ($input === '') return null;
            if (!preg_match('/^\d{1,6}(?:\.\d{1,2})?$/', $input) || (float)$input > 100000) throw new RuntimeException($label . '应为不超过 100000 元的非负金额');
            return round((float)$input, 2);
        };
        $reward = $amount($_POST['reward_amount'] ?? '', '奖励参考');
        $penalty = $amount($_POST['penalty_amount'] ?? '', '扣减参考');
        db()->beginTransaction();
        if ($action === 'create_governance_rule') {
            $code = 'custom_' . bin2hex(random_bytes(12));
            $save = db()->prepare('INSERT INTO project_governance_rules (rule_code,title,role_scope,cadence_note,reward_amount,penalty_amount,rule_text,rule_state,updated_by_employee_id) VALUES (?,?,?,?,?,?,?,?,?)');
            $save->execute([$code, $title, $scope, $cadence, $reward, $penalty, $text, $state, (int)$actor['employee_id']]);
            $id = (int)db()->lastInsertId();
            ps_audit('governance_rule', $id, 'create', $actor, ['title' => $title, 'scope' => $scope, 'cadence' => $cadence, 'reward' => $reward, 'penalty' => $penalty, 'text' => $text, 'state' => $state]);
            db()->commit();
            header('Location: ' . BASE_URL . '/project/rules.php?domain=governance&saved=' . $id . '#rule-' . $id);
            exit;
        }
        $q = db()->prepare('SELECT * FROM project_governance_rules WHERE id=? FOR UPDATE');
        $q->execute([$id]);
        $old = $q->fetch();
        if (!$old || (int)$old['version'] !== $version) throw new RuntimeException('规则已被他人更新，请刷新后重试');
        if ($old['rule_code'] === 'chair_idea' && ($cadence !== $old['cadence_note'] || (float)$penalty !== (float)$old['penalty_amount'] || $state !== $old['rule_state'])) {
            throw new RuntimeException('脑洞缺报已自动记账；调整周期、金额或启停须先制定生效日期，不能直接改写历史口径');
        }
        $save = db()->prepare('UPDATE project_governance_rules SET title=?,role_scope=?,cadence_note=?,reward_amount=?,penalty_amount=?,rule_text=?,rule_state=?,version=version+1,updated_by_employee_id=? WHERE id=? AND version=?');
        $save->execute([$title, $scope, $cadence, $reward, $penalty, $text, $state, (int)$actor['employee_id'], $id, $version]);
        ps_audit('governance_rule', $id, 'update', $actor, ['before' => ['title' => $old['title'], 'scope' => $old['role_scope'], 'cadence' => $old['cadence_note'], 'reward' => $old['reward_amount'], 'penalty' => $old['penalty_amount'], 'text' => $old['rule_text'], 'state' => $old['rule_state']], 'after' => ['title' => $title, 'scope' => $scope, 'cadence' => $cadence, 'reward' => $reward, 'penalty' => $penalty, 'text' => $text, 'state' => $state]]);
        db()->commit();
        header('Location: ' . BASE_URL . '/project/rules.php?domain=governance&saved=' . $id . '#rule-' . $id);
        exit;
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $error = $e->getMessage();
    }
}
$rules = db()->query('SELECT r.*,e.name AS editor_name FROM project_governance_rules r LEFT JOIN employees e ON e.id=r.updated_by_employee_id ORDER BY r.id')->fetchAll();
$page_title = '管理层规则中心';
include __DIR__ . '/../includes/header.php';
?>
<div class="governance-page">
  <section class="governance-hero"><div><span class="governance-kicker">CO-CREATION / 规则中心</span><h1>管理层考核规则</h1><p>轮值董事长与监委会的执行口径集中在这里。已确认的六天脑洞缺报规则会自动记入董事长奖金池；其他未确认条款只供讨论与人工核验。</p></div><span class="governance-role"><?php echo $member['governance_role'] === 'chair' ? '轮值董事长' : '监委会成员'; ?></span></section>
  <nav class="governance-tabs" aria-label="管理层栏目"><a href="<?php echo BASE_URL; ?>/project/governance_ideas.php">三天脑洞</a><a href="<?php echo BASE_URL; ?>/project/governance.php">事项台账</a><a class="active" aria-current="page" href="<?php echo BASE_URL; ?>/project/rules.php?domain=governance">规则中心</a></nav>
  <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
  <?php if (isset($_GET['saved'])): ?><div class="alert alert-success">规则已更新，旧版内容保留在操作审计中。</div><?php endif; ?>
  <div class="governance-rule-intro"><strong>脑洞缺报按原文六天周期</strong><p>原规则写明每 6 天提交一次，连续 6 天未提交或被判无效扣 ¥300；仅在监委会登记的轮值期内、完整窗口结束后自动入账。监委会可以逐笔说明理由并豁免。原表其他条款与历史记录有出入，仍待核对；不是所有“已确认”规则都会自动扣款。</p></div>
  <?php if ($member['governance_role'] === 'committee'): ?><details class="governance-card governance-review governance-new-rule"><summary>＋ 新增考核规则</summary><form method="post"><input type="hidden" name="domain" value="governance"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="create_governance_rule"><div class="form-row"><div class="form-group col-md-8"><label>规则名称</label><input class="form-control" name="title" maxlength="160" required placeholder="例如：每月复盘改进"></div><div class="form-group col-md-4"><label>适用对象</label><select class="form-control" name="role_scope"><option value="chair">轮值董事长</option><option value="committee">监委会</option><option value="all">共同</option></select></div></div><div class="form-group"><label>周期 / 时效</label><input class="form-control" name="cadence_note" maxlength="120" placeholder="例如：每月一次"></div><div class="form-row"><div class="form-group col-md-6"><label>奖励参考金额（可选）</label><input class="form-control" type="number" min="0" max="100000" step="0.01" name="reward_amount"></div><div class="form-group col-md-6"><label>扣减参考金额（可选）</label><input class="form-control" type="number" min="0" max="100000" step="0.01" name="penalty_amount"></div></div><div class="form-group"><label>规则说明</label><textarea class="form-control" rows="4" maxlength="5000" name="rule_text" required placeholder="写明适用条件、举证方式与核验标准"></textarea></div><div class="form-group"><label>状态</label><select class="form-control" name="rule_state"><option value="draft">先存为待确认草案</option><option value="confirmed">已确认，供人工核验参考</option></select></div><button class="btn btn-success" type="submit">保存新规则</button></form></details><?php endif; ?>
  <div class="governance-rule-list"><?php foreach ($rules as $rule): ?><article class="governance-card governance-rule" id="rule-<?php echo (int)$rule['id']; ?>"><div class="governance-record-top"><span class="governance-tag"><?php echo $rule['role_scope'] === 'chair' ? '轮值董事长' : ($rule['role_scope'] === 'committee' ? '监委会' : '共同'); ?></span><span class="governance-status <?php echo $rule['rule_state'] === 'confirmed' ? 'approved' : 'pending'; ?>"><?php echo $rule['rule_state'] === 'confirmed' ? '已确认' : '待确认'; ?></span><small>版本 <?php echo (int)$rule['version']; ?><?php if ($rule['editor_name']): ?> · <?php echo e($rule['editor_name']); ?> 更新<?php endif; ?></small></div><h2><?php echo e($rule['title']); ?></h2><p><?php echo nl2br(e($rule['rule_text'])); ?></p><div class="governance-rule-facts"><span>周期：<?php echo e($rule['cadence_note'] ?: '按事项'); ?></span><?php if ($rule['reward_amount'] !== null): ?><span>奖励参考 ¥<?php echo money($rule['reward_amount']); ?></span><?php endif; ?><?php if ($rule['penalty_amount'] !== null): ?><span>扣减参考 ¥<?php echo money($rule['penalty_amount']); ?></span><?php endif; ?></div>
    <?php if ($member['governance_role'] === 'committee'): ?><details class="governance-review"><summary>编辑并确认规则</summary><form method="post"><input type="hidden" name="domain" value="governance"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="save_governance_rule"><input type="hidden" name="rule_id" value="<?php echo (int)$rule['id']; ?>"><input type="hidden" name="version" value="<?php echo (int)$rule['version']; ?>"><div class="form-row"><div class="form-group col-md-8"><label>规则名称</label><input class="form-control" name="title" maxlength="160" value="<?php echo e($rule['title']); ?>" required></div><div class="form-group col-md-4"><label>适用对象</label><select class="form-control" name="role_scope"><option value="chair" <?php echo $rule['role_scope'] === 'chair' ? 'selected' : ''; ?>>轮值董事长</option><option value="committee" <?php echo $rule['role_scope'] === 'committee' ? 'selected' : ''; ?>>监委会</option><option value="all" <?php echo $rule['role_scope'] === 'all' ? 'selected' : ''; ?>>共同</option></select></div></div><div class="form-group"><label>周期 / 时效</label><input class="form-control" name="cadence_note" maxlength="120" value="<?php echo e($rule['cadence_note']); ?>"></div><div class="form-row"><div class="form-group col-md-6"><label>奖励参考金额</label><input class="form-control" type="number" min="0" max="100000" step="0.01" name="reward_amount" value="<?php echo e($rule['reward_amount']); ?>"></div><div class="form-group col-md-6"><label>扣减参考金额</label><input class="form-control" type="number" min="0" max="100000" step="0.01" name="penalty_amount" value="<?php echo e($rule['penalty_amount']); ?>"></div></div><div class="form-group"><label>规则说明</label><textarea class="form-control" rows="4" maxlength="5000" name="rule_text" required><?php echo e($rule['rule_text']); ?></textarea></div><div class="form-group"><label>状态</label><select class="form-control" name="rule_state"><option value="draft" <?php echo $rule['rule_state'] === 'draft' ? 'selected' : ''; ?>>待确认草案</option><option value="confirmed" <?php echo $rule['rule_state'] === 'confirmed' ? 'selected' : ''; ?>>已确认，供人工核验参考</option></select></div><button class="btn btn-success" type="submit">保存规则</button></form></details><?php endif; ?></article><?php endforeach; ?></div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
